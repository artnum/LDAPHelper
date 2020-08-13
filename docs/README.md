# LDAPHelper

The php-ldap extension took too much of the un-good side of the C API and not enough of its good side. It also didn't offer the whole power of LDAP. So this helper try to solve all that.

## First and next entry/attribute/whatever

What is nice with LDAP protocol is its way of dealing with queries and results. Event based modern web programming is already there. You can start processing while still having not all data in. It doesn't mean it is implemented that way, but it could be implemented that way. So when you send a query, you receive the first object, process it and get to the second that might have been received in between.

```php
$conn = ldap_connect(....);
ldap_set_option(....);
ldap_bind(....);
$result = ldap_search($conn, ....);
for ($entryid = ldap_first_entry($conn, $result); $entryid; $entryid = ldap_next_entry($conn, $entryid)) {
    $entry = []
    for ($attr = ldap_first_attribute($conn, $entryid); $attr; ldap_next_attribute($conn, $entryid)) {
        $attributeValue = ldap_get_values($conn, $entryid, $attr);
        // or, in case of binary value
        $attributeValue = ldap_get_values_len($conn, $entryid, $attr);

        $entry[$attr] = $attributeValue;
    }
}
```

Very C-like. With the helper, most of the boilerplate is done. By default it returns an entry object (LDAPHelperEntry) :

```php
$helper = new LDAPHelper();
$helper->addServer(....);
$results = $helper->search(....);
foreach ($results as $result) {
    for ($entry = $result->firstEntry(); $result; $entry = $result->nextEntry()) {
        $entry->dump()
    }
}
```
The upper loop (foreach) is there because you can add multiple servers. Doing so allows to search easily through delegated servers. Also you can have readonly servers and readwrite servers.
The inner loop (for) allows to get each entry without having to care about attribute and binary/non-binary attribute. The magic lies in the root DSE.

## Root DSE parsing

When adding a server, LDAPHelper::addServer(....), the server configuration is read directly from the server. Its naming context, or the "base", the protocol version or the attribute types and object classes available. It means that you only need to supply an URI and authentication parameters to start using any LDAP server.

```php
$helper = new LDAPHelper();
$helper->addServer('ldapi:///', 'simple', ['dn' => 'cn=admin,dc=example,dc=com', 'password' => 'secret'], true); // readonly server
$helper->addServer('ldaps://write.example.com', 'simple', ['dn' => 'cn=admin,dc=example,dc=com', 'password' => 'secret']); // readwrite server
$helper->addServer('ldaps://delegated.example.com', 'simple', ['dn' => 'cn=admin,dc=delegated,dc=example,dc=com', 'password' => 'secret']); // readwrite server

$results = $helper->search('dc=example,dc=com', '(objectclass=inetorgperson)', ['*'], 'sub');
```
A subtree search on two servers. The 'ldapi:///' and 'ldaps://write.example.com' serve 'dc=example,dc=com' so the readonly is prefered (we are doing a readonly operation). And the delegated is also queried as the search happen at an upper level.

## Usage

### Add server

Suppose you have an OpenLDAP running on Linux. Let's say the web server process runs with user id 33(www-data) and group id 33(www-data). Let's say you run your server on unix socket (ldapi://) and on ldap://localhost. Let's say you have the following acl access on you OpenLDAP configuration :

```
olcAccess: {0}to * by dn.exact=gidNumber=0+uidNumber=0,cn=peercred,cn=external,cn=auth manage by * break
olcAccess: {1}to * by dn.exact=gidNumber=33+uidNumber=33,cn=peercred,cn=external,cn=auth write by * break
olcAccess: {2}to * by * read
```

Which means that root user can manage the server, www-data user can write and anyone can read it (it is not how you want to configure you production server). You would add two servers :

```php
$helper = new LDAPHelper();
$helper->addServer('ldapi:///', 'sasl', ['mech' => 'EXTERNAL']);
$helper->addServer('ldap://localhost', 'simple', [], true);
```

Writes would go the ldapi:/// and read to ldap://localhost.

### Searching an entry

When searching an entry you end up with an array of LDAPHelperResult. The reason is that if you have more that one context or one server, you will have several request running with several result set. So has to keep coherence on usage, search always return an array. Even reading one specific DN might have more than one result as it can be on more than one server.
Search is doing all the work, you choose the scope (searching the subtree, listing the subtree or reading an object) with the parameter $scope.

```php
$helper = new LDAPHelper();
$helper->addServer(....);
$helper->addServer(....);
$helper->addServer(....);

$base = '....';
$filter = '(objectclass=*)';
$attrs = ['*'];

$results = $helper->search($base, $filter, $attrs, 'sub'); // subtree search
// or
$results = $helper->search($base, $filter, $attrs, 'one'); // list search (one level)
// or
$results = $helper->search($base, $filter, $attrs, 'base'); // read object

foreach ($results as $rset) {
    for($entry = $rset->firstEntry(); $entry; $entry = $rset->nextEntry()) {
        // process entry
    }
}
```

### Adding entry
To add entry, create a LDAPHelper, add some server and create a LDAPHelperEntry. Set the DN, add attributes and you are done.

```php
$helper = new LDAPHelper();
$helper->addServer(....);
$helper->addServer(....);
$helper->addServer(....);$helper = new LDAPHelper();
$helper->addServer(....);
$helper->addServer(....);
$helper->addServer(....);
$newEntry = new LDAPHelperEntry($helper);
// if you have a server with naming context dc=example,dc=com it will be choosen
$newEntry->dn('cn=test,dc=example,dc=com');
$newEntry->add('objectclass', ['person']);
$newEntry->add('sn', ['test']); // person must have sn attribute
$newEntry->commit()
```

### Modifying entry
When you have an LDAPHelperEntry, you can modify quite easily

```php
$helper = new LDAPHelper();
$helper->addServer(....);
$helper->addServer(....);
$helper->addServer(....);

$results = $helper->search(....);
$entry = $results->firstEntry();
$entry->replace('sn', ['test']); // replace attribute sn
$entry->delete('l'); // delete attribute l
$entry->add('postalcode', ['1234567890']); // add attribute postalcode
$entry->commit();
```

You can replace, add or delete any attribute. If you want to cancel all modification, you have rollback instead of commit.

When a commit is done, the values of the object reflect what is on the LDAP server without reading it back, a copy is kept locally and changes are applied to it.

### Moving/renaming entry

You can move an entry. The operation is done inline with modification and/or renaming. You can add, replace, delete, move and rename in one commit. The operation is done sequentially : renaming is done, add/replace/delete is done if it succeed and then moving is done. So it would be like :

```php
$entry->delete('sn', ['test']);
$entry->move('ou=newparent,dc=example,dc=com');
$entry->rename('sn=test2');
$entry->commit();
```

Rename takes care of adding the attribute needed if it has not been added. It won't remove old one, you have to do it.

### Deleting an entry

When you have the DN you juste call delete from the helper :

```php
$helper = new LDAPHelper();
$helper->addServer(....);
$helper->addServer(....);
$helper->addServer(....);

$dn = '....';
$helper->delete($dn);
```

### Get all naming contexts (since 1.0.1)

You can get all naming contexts found on each server (either read server or write server) with getNamingContexts
```php
$helper = new LDAPHelper();
$helper->addServer(....);
$helper->addServer(....);
$helper->addServer(....);

$helper->getNamingContexts(); // writers naming contexts
$helper->getNamingContexts(true); // readers naming contexts
```

### Iterating over attributes (since 1.0.2)

Once you have an entry, you might want to iterate over each attribute. Use the generator eachAttribute :
$helper-

```php
$helper = new LDAPHelper();
$helper->addServer(....);
$results = $helper->search(....);
$entry = $results->firstEntry();

foreach($entry->eachAttribute as $name => $value) {
    // do what you want with the attribute and its value
}
```

### Finding all objectclass supported on every server (since 1.0.2)

If you need to know wich objectclass are present on every server, just use getClasses :

```php
$helper = new LDAPHelper();
$helper->addServer(....);
$helper->addServer(....);
$helper->addServer(....);

$helper->getClasses(); // an array of all known classes
```

### Rollback

When changes are made they are done locally. You call commit to apply them on the directory. So it's not a true "rollback". For example, if you rename and modify an entry and the modify fails during the commit, the renaming will still have been applied.

## More examples

To see more example on how to use it, look at project SAddr (Simple Address) which is a simple address book made years ago and being reworked with this helper. It is available at https://github.com/artnum/saddr, features are added as they are needed for that project.