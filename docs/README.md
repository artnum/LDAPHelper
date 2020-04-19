# LDAPHelper

The php-ldap extension took too much of the un-good side of the C API and not enough of its good side. It also didn't offer the whole power of LDAP. So this helper try to solve all that.


## First and next entry/attribute/whatever

What is nice with LDAP protocol is its way of dealing with queries and results. Event based modern web programming is already there. You can start processing while still having not all data in. It doesn't mean it is implemented that way, but it could be implemented that way. So when you send a query, you receive the first object, process it and get to the second that might have been received in between.

```php
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

Very C-like. With the helper, most of the boilerplate is done :

```php
$helper = new LDAPHelper();
$helper->addServer(....);
$results = $helper->search(....);
foreach ($results as $result) {
    for ($entry = $result->firstEntry(); $result; $entry = $result->nextEntry()) {
        print_r($entry)
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


