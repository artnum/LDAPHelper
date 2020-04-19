<?PHP
const LDAPHELPER_SCHEMA_BOOL = ['STRUCTURAL', 'AUXILIARY', 'ABSTRACT', 'SINGLE-VALUE', 'OBSOLETE', 'COLLECTIVE', 'NO-USER-MODIFICATION'];
const LDAPHELPER_SYNTAXES = [
    '1.3.6.1.4.1.1466.115.121.1.4' => ['name' => 'audio', 'binary' => true],
    '1.3.6.1.4.1.1466.115.121.1.5' => ['name' => 'binary', 'binary' => true],
    '1.3.6.1.4.1.1466.115.121.1.6' => ['name' => 'bistring', 'binary' => true],
    '1.3.6.1.4.1.1466.115.121.1.7' => ['name' => 'boolean', 'binary' => true],
    '1.3.6.1.4.1.1466.115.121.1.8' => ['name' => 'cert', 'binary' => true],
    '1.3.6.1.4.1.1466.115.121.1.9' => ['name' => 'cert-list', 'binary' => true],
    '1.3.6.1.4.1.1466.115.121.1.10' => ['name' => 'cert-pair', 'binary' => true],
    '1.3.6.1.4.1.4203.666.11.10.2.1' => ['name' => 'x509-attr', 'binary' => true],
    '1.3.6.1.4.1.1466.115.121.1.12' => ['name' => 'dn', 'binary' => false],
    '1.2.36.79672281.1.5.0' => ['name' => 'rdn', 'binary' => false],
    '1.3.6.1.4.1.1466.115.121.1.14' => ['name' => 'delivery-method', 'binary' => false],
    '1.3.6.1.4.1.1466.115.121.1.15' => ['name' => 'directory-string', 'binary' => false],
    '1.3.6.1.4.1.1466.115.121.1.22' => ['name' => 'fax-number', 'binary' => false],
    '1.3.6.1.4.1.1466.115.121.1.24' => ['name' => 'generalized-time', 'binary' => false],
    '1.3.6.1.4.1.1466.115.121.1.26' => ['name' => 'ia5-string', 'binary' => false],
    '1.3.6.1.4.1.1466.115.121.1.27' => ['name' => 'integer', 'binary' => false],
    '1.3.6.1.4.1.1466.115.121.1.28' => ['name' => 'jpeg', 'binary' => true],
    '1.3.6.1.4.1.1466.115.121.1.34' => ['name' => 'name-uid', 'binary' => false],
    '1.3.6.1.4.1.1466.115.121.1.36' => ['name' => 'numeric-string', 'binary' => false],
    '1.3.6.1.4.1.1466.115.121.1.38' => ['name' => 'oid', 'binary' => false],
    '1.3.6.1.4.1.1466.115.121.1.39' => ['name' => 'other-mailbox', 'binary' => false],
    '1.3.6.1.4.1.1466.115.121.1.40' => ['name' => 'octet-string', 'binary' => false],
    '1.3.6.1.4.1.1466.115.121.1.41' => ['name' => 'postal-address', 'binary' => false],
    '1.3.6.1.4.1.1466.115.121.1.44' => ['name' => 'printable-string', 'binary' => false],
    '1.3.6.1.4.1.1466.115.121.1.11' => ['name' => 'country-string', 'binary' => false],
    '1.3.6.1.4.1.1466.115.121.1.45' => ['name' => 'subtree-desc', 'binary' => false],
    '1.3.6.1.4.1.1466.115.121.1.49' => ['name' => 'supported-algorithm', 'binary' => true],
    '1.3.6.1.4.1.1466.115.121.1.50' => ['name' => 'phone-number', 'binary' => false],
    '1.3.6.1.4.1.1466.115.121.1.52' => ['name' => 'telex-number', 'binary' => false],
    '1.3.6.1.1.1.0.0' => ['name' => 'nis-triple', 'binary' => false],
    '1.3.6.1.1.1.0.1' => ['name' => 'boot-parameter', 'binary' => false],
    '1.3.6.1.1.16.1' => ['name' => 'uuid', 'binary' => false]
];

class LDAPHelperResult {
    function __construct($result, LDAPHelperServer $server) {
        $this->Result = $result;
        $this->Server = $server;
        $this->currentEntry = false;
        $this->Conn = $server->getConnection();
    }

    function count() {
        $retval = ldap_count_entries($this->Conn, $this->Result);
        if ($retval === false) {
            return 0;
        }
        return $retval;
    }

    function firstEntry() {
        $this->currentEntry = ldap_first_entry($this->Conn, $this->Result);
        if ($this->currentEntry === false) { return false; }
        return $this->Server->getEntry($this->currentEntry);
    }

    function nextEntry() {
        $this->currentEntry = ldap_next_entry($this->Conn, $this->currentEntry);
        if ($this->currentEntry === false) { return false; }
        return $this->Server->getEntry($this->currentEntry);
    }
}

class LDAPHelperServer
{
    function __construct($uri, $conn)
    {
        $this->URI = $uri;
        $this->Conn = $conn;
        $this->Readonly = false;
        $this->Schemas = [];
        $this->Contexts = [];
        $this->Syntaxes = [];
    }

    function getConnection() {
        return $this->Conn;
    }

    function setReadonly()
    {
        $this->Readonly = true;
    }

    function setSchema($schemas)
    {
        $this->Schemas = $schemas;
    }

    function setContext($contexts)
    {
        $this->Contexts = $contexts;
    }

    function checkContext($base)
    {
        foreach ($this->Contexts as $ctx) {
            if ($base === $ctx) {
                return true;
            }
        }
        return false;
    }

    private function resolveReference ($type, $name) {
        $entry = $this->Schemas[$type][$name];
        while (!empty($entry['_ref'])) {
            $name = $entry['_ref'];
            $entry = $this->Schemas[$type][$entry['_ref']];
        }
        return $name;
    }

    private function mergeParent($type, $name)
    {
        $entry = $this->Schemas[$type][$this->resolveReference($type, $name)];
        if (empty($entry['SUP'])) {
            return $entry;
        }
        $ref = strtolower($entry['SUP']);
        while ($ref) {
            $ref = $this->resolveReference($type, $ref);
            if (empty($this->Schemas[$type][$ref])) {
                break;
            }
            $parent = $this->Schemas[$type][$ref];
            if (empty($parent)) {
                break;
            }
            unset($entry['SUP']); // remove 'SUP' if it appears again we continu
            foreach ($parent as $k => $v) {
                $entry[$k] = $v;
            }
            $ref = isset($entry['SUP']) ? strtolower($entry['SUP']) : null;
        }
        return $entry;
    }

    /* return objectclasses or attributetype if it exists */
    private function _exists($name, $attribute = true)
    {
        $name = strtolower($name);
        $found = false;
        $type = 'attributetypes';
        if (!$attribute) {
            $type = 'objectclasses';
        }

        if (empty($this->Schemas[$type])) {
            return false;
        }
        if (!empty($this->Schemas[$type][$name])) {
            return [$type, $name];
        }
        
        /* search by oid */
        foreach ($this->Schemas[$type] as $name => $value) {
            if (empty($value['oid'])) {
                continue;
            } // should not exist
            if ($value['oid'] !== $name) {
                continue;
            }
            return [$type, $name];
        }
        return false;
    }

    function syntax ($name) {
        if (empty($this->Syntaxes[$name])) {
            $description = $this->describe($name);

            $this->Syntaxes[$name] = $description['_syntax'];
        }

        if (!empty(LDAPHELPER_SYNTAXES[$this->Syntaxes[$name]])) {
            return LDAPHELPER_SYNTAXES[$this->Syntaxes[$name]];
        }
        /* when we don't know we answer octet string */
        return LDAPHELPER_SYNTAXES['1.3.6.1.4.1.1466.115.121.1.40'];
    }

    function describe($name, $attribute = true)
    {
        $entry = $this->_exists($name, $attribute);
        if ($entry === false) {
            return false;
        }
        return $this->mergeParent($entry[0], $entry[1]);
    }
    function exists($name, $attribute = true)
    {
        return $this->_exists($name, $attribute) === false ? false : true;
    }
    
    function getValue($entryid, $attr)
    {
        $binary = false;

        $val = [];
        $syntax = $this->syntax($attr);
        if ($syntax['binary']) {
            $val = ldap_get_values_len($this->Conn, $entryid, $attr);
        } else {
            $val = ldap_get_values($this->Conn, $entryid, $attr);
        }
        /* so can be used with foreach */
        unset($val['count']);
        return $val;
    }

    function getEntry($entryid)
    {
        $entry = [];
        for ($attr = ldap_first_attribute($this->Conn, $entryid); $attr; $attr = ldap_next_attribute($this->Conn, $entryid)) {
            $entry[$attr] = $this->getValue($entryid, $attr);
        }
        return $entry;
    }
}

class LDAPHelper
{
    function __construct()
    {
        $this->Servers = [];
    }

    public function empty($entry)
    {
        if (!$entry) {
            return true;
        }
        if (empty($entry)) {
            return true;
        }
        if (!isset($entry['count'])) {
            return true;
        }
        if ($entry['count'] <= 0) {
            return true;
        }
        return false;
    }

    private function loadRootDSE($conn)
    {
        if (!$conn) {
            return false;
        }

        $res = ldap_read($conn, '', '(objectclass=*)', ['+']);
        if (!$res) {
            return false;
        }

        $dse = ldap_get_entries($conn, $res);
        if ($this->empty($dse)) {
            return false;
        }
        $dse = $dse[0]; // should have only one

        if ($this->empty($dse['supportedldapversion'])) {
            return false;
        }
        if (!ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, $dse['supportedldapversion'][0])) {
            return false;
        }

        $Contexts = [];
        if ($this->empty($dse['namingcontexts'])) {
            return false;
        }
        for ($i = 0; $i < $dse['namingcontexts']['count']; $i++) {
            $Contexts[] = $dse['namingcontexts'][$i];
        }

        /* load all objectclasses and attributetypes available on the server */
        $Schemas = [];
        if (!$this->empty($dse['subschemasubentry'])) {
            for ($i = 0; $i < $dse['subschemasubentry']['count']; $i++) {
                $result = ldap_read($conn, $dse['subschemasubentry'][$i], '(objectclass=*)', ['+']);
                if (!$result) {
                    continue;
                }
                $schemas = ldap_get_entries($conn, $result);
                if (!$this->empty($schemas)) {
                    for ($j = 0; $j < $schemas['count']; $j++) {
                        foreach (['objectclasses', 'attributetypes'] as $type) {
                            if ($this->empty($schemas[$j][$type])) {
                                break;
                            }
                            $Schemas[$type] = [];
                            for ($k = 0; $k < $schemas[$j][$type]['count']; $k++) {
                                $s = $this->parseSchemaEntry($schemas[$j][$type][$k], $type);
                                foreach ($s as $_s) {
                                    if ($_s[0] !== null) {
                                        $Schemas[$type][$_s[0]] = $_s[1];
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        return ['schemas' => $Schemas, 'contexts' => $Contexts];
    }

    private function connectServer($uri, $bindtype = 'simple', $bindopts = ['dn' => null, 'password' => null])
    {
        $conn = ldap_connect($uri);
        if (!$conn) {
            return false;
        }
        $s = $this->loadRootDSE($conn);
        if (!$s) {
            ldap_close($conn);
            return false;
        }

        if (!is_array($bindopts)) { $bindopts = []; }
        switch ($bindtype) {
            default:
            case 'simple':
                if (!isset($bindopts['dn'])) {
                    $bindopts['dn'] = null;
                }
                if (!isset($bindopts['password'])) {
                    $bindopts['password'] = null;
                }
                if (!ldap_bind($conn, $bindopts['dn'], $bindopts['password'])) {
                    ldap_close($conn);
                    return false;
                }
        }

        $server = new LDAPHelperServer($uri, $conn);
        $server->setSchema($s['schemas']);
        $server->setContext($s['contexts']);
        return $server;
    }

    /* might not be most efficient but good for now */
    private function parseSchemaEntry($se, $type)
    {
        /* 0 => not yet, 1 => next, 2 => now */
        $state = [
            'started' => 0,
            'oid' => 0,
            'attr' => 0,
            'value' => 0,
            'inlist' => 0,
            'instring' => 0
        ];
        $entry = [];
        $currentAttr = '';
        $tks = explode(' ', $se);
        foreach ($tks as $tk) {
            if ($tk === '(' && $state['started'] === 2 && $state['value'] === 1) {
                $state['inlist'] = 1;
                $state['value'] = 2;
                continue;
            }
            if ($tk === '(' && $state['started'] === 0) {
                $state['oid'] = 1;
                $state['started'] = 2;
                continue;
            }
            if ($tk === ')' && $state['inlist'] > 0) {
                $state['inlist'] = 0;
                $state['attr'] = 1;
                continue;
            }
            if ($tk === ')' && $state['inlist'] === 0) {
                break;
            }

            /* oid */
            if ($state['oid'] === 1) {
                $state['attr'] = 1;
                $state['oid'] = 0;
                $entry['oid'] = $tk;
                continue;
            }
            /* attribute */
            if ($state['attr'] === 1) {
                if (in_array($tk, LDAPHELPER_SCHEMA_BOOL)) {
                    $entry[$tk] = true;
                    $state['attr'] = 1;
                    $state['value'] = 0;
                } else {
                    $state['attr'] = 0;
                    $state['value'] = 1;
                    $currentAttr = $tk;
                }
                continue;
            }
            /* value */
            if ($state['value'] === 2 && $state['inlist'] === 1) {
                $state['inlist'] = 2;
                $entry[$currentAttr] = [str_replace('\'', '', $tk)];
                continue;
            }
            if ($state['value'] === 2 && $state['inlist'] === 2) {
                if ($tk === '$') {
                    continue;
                }
                $entry[$currentAttr][] = str_replace('\'', '', $tk);
                continue;
            }
            if ($state['value'] === 2 && $state['instring'] === 2) {
                $entry[$currentAttr] .= ' ' . $tk;
                if ($tk[strlen($tk) - 1] === '\'') {
                    $entry[$currentAttr] = str_replace('\'', '', $entry[$currentAttr]);
                    $state['instring'] = 0;
                    $state['value'] = 0;
                    $state['attr'] = 1;
                }
                continue;
            }
            if ($state['value'] === 1) {
                if ($tk[0] === '\'' && $tk[strlen($tk) - 1] !== '\'') {
                    $entry[$currentAttr] = $tk; // removing ' is left at end of string
                    $state['instring'] = 2;
                    $state['value'] = 2;
                } else {
                    $entry[$currentAttr] = str_replace('\'', '', $tk);
                    $state['attr'] = 1;
                    $state['value'] = 0;
                }
                continue;
            }
        }

        if (empty($entry)) {
            return [null, []];
        }
        if (empty($entry['NAME'])) {
            return [null, []];
        }

        $entry['_type'] = $type === 'objectclasses' ? 'class' : 'attribute';
        if (!empty($entry['SYNTAX'])) {
            if (preg_match('/([0-9\.]+)(?:\{([0-9]+)\})?/m', $entry['SYNTAX'], $m)) {
                $entry['_syntax'] = $m[1];
                $entry['_length'] = !empty($m[2]) ? $m[2] : -1;
            } else {
                $entry['_syntax'] = $entry['SYNTAX'];
                $entry['_length'] = -1; // unlimited
            }
        }

        if (is_array($entry['NAME'])) {
            $s = [[strtolower($entry['NAME'][0]), $entry]];
            for ($i = 1; $i < count($entry['NAME']); $i++) {
                $s[] = [strtolower($entry['NAME'][$i]), ['_ref' => strtolower($entry['NAME'][0])]];
            }
            return $s;
        } else {
            return [[strtolower($entry['NAME']), $entry]];
        }
    }

    function addServer($uri, $bindtype = 'simple', $bindopts = ['dn' => null, 'password' => null], $readonly = false)
    {
        $server = $this->connectServer($uri, $bindopts, $bindtype);
        if ($server) {
            if ($readonly) {
                $server->setReadonly();
            }
            $this->Servers[] = $server;
        }
    }

    private function searchSingle($base, $filter, $attrs, $scope): array
    {
        $conn = $this->Servers[0]->getConnection();
        switch ($scope) {
            case 'base':
            case 'Base':
            case 'BAse':
            case 'BASe':
            case 'BASE':
                return [new LDAPHelperResult(ldap_read($conn, $base, $filter, $attrs), $this->Servers[0])];
            case 'one':
            case 'One':
            case 'ONe':
            case 'ONE':
                return [new LDAPHelperResult(ldap_list($conn, $base, $filter, $attrs), $this->Servers[0])];
            case 'sub':
            case 'Sub':
            case 'SUb':
            case 'SUB':
                return [new LDAPHelperResult(ldap_search($conn, $base, $filter, $attrs), $this->Servers[0])];
        }
        return [];
    }

    private function searchMultiple($base, $filter, $attrs, $scope): array
    {
        $servers = [];
        $conns = [];
        $ctxs = [];
        $i = 0;
        foreach ($this->Servers as $server) {
            if ($server->checkContext($base)) {
                /* if base already in list, we prefere readonly server */
                if (isset($ctxs[$base])) {
                    $j = $ctxs[$base];
                    if ($servers[$j]->isReadonly()) {
                        continue;
                    }
                    if ($server->isReadonly()) {
                        $servers[$j] = $server;
                        $conns[$j] = $server->getConnection();
                    }
                    continue;
                }
                $ctxs[$base] = $i;
                $servers[$i] = $server;
                $conns[$i] = $server->getConnection();
                $i++;
            }
        }

        $results = [];
        switch ($scope) {
            case 'base':
            case 'Base':
            case 'BAse':
            case 'BASe':
            case 'BASE':
                $results = ldap_read($conns, $base, $filter, $attrs);
            case 'one':
            case 'One':
            case 'ONe':
            case 'ONE':
                $results = ldap_list($conns, $base, $filter, $attrs);
            case 'sub':
            case 'Sub':
            case 'SUb':
            case 'SUB':
                $results = ldap_search($conns, $base, $filter, $attrs);
        }

        $retval = [];
        foreach ($results as $k => $value) {
            $retval[] = new LDAPHelperResult($value, $servers[$k]);            
        }

        return $retval;
    }

    function search($base, $filter, $attr, $scope):array
    {
        $result = [];
        if (count($this->Servers) === 1) {
            $result = $this->searchSingle($base, $filter, $attr, $scope);
        } else {
            $result = $this->searchMultiple($base, $filter, $attr, $scope);
        }
        return $result;
    }
}
?>