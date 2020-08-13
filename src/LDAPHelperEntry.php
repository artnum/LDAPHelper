<?PHP
namespace artnum;

class LDAPHelperEntry {
    private $Entry;
    private $Ldap;
    private $lastError;
    private $Server;
    private $Conn;

    function __construct($server, $entryid = null) 
    {
        $this->Entry = [
            'new' => false,
            'dn' => null,
            'current' => [],
            'syntax' => [],
            'unopted' => [],
            'mods' => [],
            'moveTo' => null,
            'renameTo' => null
        ];
        /* new entry have an LDAPHelper, when dn is set we choose server */
        if ($server instanceof LDAPHelper) {
            $this->Entry['new'] = true;
            $this->Ldap = $server;
            $this->Server = null;
            $this->Conn = null;
        } else {
            $this->Ldap = null;
            $this->Server = $server;
            $this->Conn = $server->getConnection();
        }

        if ($entryid !== null) {
            $syntax = null;
            $this->Entry['dn'] = ldap_get_dn($this->Conn, $entryid);
            for ($attr = strtolower(ldap_first_attribute($this->Conn, $entryid)); $attr; $attr = strtolower(ldap_next_attribute($this->Conn, $entryid))) {
                $this->Entry['current'][$attr] = $this->Server->getValue($entryid, $attr, $syntax);
                $this->Entry['syntax'][$attr] = $syntax;
                $unopted = $this->unoptName($attr);
                if (!isset($this->Entry['unopted'][$unopted])) {
                    $this->Entry['unopted'][$unopted] = [$attr];
                } else {
                    if (!in_array($attr, $this->Entry['unopted'][$unopted])) {
                        $this->Entry['unopted'][$unopted][] = $attr;
                    }
                }
            }
        }
    }

    private function unoptName($name) 
    {
        return strtolower(explode(';', $name)[0]);
    }

    private function cli_dump() 
    {
        foreach ($this->Entry['current'] as $attr => $values) {
            echo $attr . '(' . $this->unoptName($attr) . ') {' . PHP_EOL;
            if ($this->Entry['syntax'][$attr]['binary']) {
                foreach ($values as $value) {
                    echo "\t" . base64_encode($value) . PHP_EOL;
                }
            } else {
                foreach ($values as $value) {
                    echo "\t" . $value . PHP_EOL;
                }
            }
            echo '}' . PHP_EOL;
        }
    }

    private function web_dump() 
    {
        foreach ($this->Entry['current'] as $attr => $values) {
            echo '<dl><dt>' . $attr . ' (' . $this->unoptName($attr) . ')</dt>' . PHP_EOL;
            if ($this->Entry['syntax'][$attr]['binary']) {
                foreach ($values as $value) {
                    echo '<dd>'. base64_encode($value) . '</dd>' . PHP_EOL;
                }
            } else {
                foreach ($values as $value) {
                    echo '<dd>'. $value . '</dd>' . PHP_EOL;
                }
            }
            echo '</dl>' . PHP_EOL;
        }
    }

    function dump() {
        if (php_sapi_name() === 'cli') {
            $this->cli_dump();
        } else {
            $this->web_dump();
        }
    }

    private function _reset() {
        $this->Entry['mods'] = [];
        $this->Entry['renameTo'] = null;
        $this->Entry['moveTo'] = null;
        $this->lastError = 0;
    }

    function rollback() 
    {
        $this->_reset();
        return true;
    }

    private function _moveTo ($conn) {
        $rdn = explode(',', $this->Entry['dn'])[0];
        return @ldap_rename($conn, $this->Entry['dn'], $rdn, $this->Entry['moveTo'], true);
    }

    private function _renameTo ($conn) {
        $dnParts = explode('=', $this->Entry['renameTo'], 2);
        if (count ($dnParts) !== 2) {
            return false;
        }
        $addAttr = false;
        if (!isset($this->Entry['current'][$dnParts[0]])) {
            $addAttr = true;
        } else {
            $addAttr = true;
            foreach($this->Entry['current'][$dnParts[0]] as $v) {
                if (strcmp($dnParts[1], $v) === 0) {
                    $addAttr = false;
                break;
                }
            }
        }
        if ($addAttr) {
            $result = @ldap_mod_add($conn, $this->Entry['dn'], [$dnParts[0] => [$dnParts[1]]]);
            if (!$result) {
                return false; 
            }
            if (!isset($this->Entry['current'][$dnParts[0]])) {
                $this->Entry['current'][$dnParts[0]] = [$dnParts[1]];
            } else {
                $this->Entry['current'][$dnParts[0]][] = $dnParts[1];
            }

            /* purge upcoming mods from modification regarding our new rdn */
            $mods = [];
            foreach ($this->Entry['mods'] as $modk => $mod) {
                if ($mod['attrib'] === $dnParts[0]) {
                    $keys = [];
                    foreach ($mod['values'] as $k => $v) {
                        if (strcmp($v, $dnParts[1]) === 0) {
                            $keys[] = $k;
                        break;
                        }
                    }
                    foreach ($keys as $k) {
                        unset($mod['values'][$k]);
                    }
                    if (empty($mod['values'])) {
                        $mods[] = $modk;
                    }
                }
            }
            foreach ($mods as $k) {
                unset($this->Entry['mods'][$k]);
            }
        }

        $result = @ldap_rename($conn, $this->Entry['dn'], $this->Entry['renameTo'], NULL, false);
        if ($result) {
            $this->Entry['dn'] = $this->Entry['renameTo'] . ',' . explode(',', $this->Entry['dn'], 2)[1];
        }
        return $result;
    }

    function lastError() {
        return [$this->lastError, ldap_err2str($this->lastError)];
    }

    function commit() 
    {
        $conn = null;

        $writer = $this->Server->getWriter();
        if ($writer === null) {
            return false;
        }
        $conn = $writer->getConnection();
        if ($conn === null) {
            return false;
        }

        $result = true;

        if ($this->Entry['renameTo'] !== null) {
            $result = $this->_renameTo($conn);
        }
        
        if ($result) {
            if (!$this->Entry['new']) {
                if (count($this->Entry['mods']) > 0) {
                    $result = @ldap_modify_batch($conn, $this->Entry['dn'], $this->Entry['mods']);
                    if ($result) {
                        /* reflect modification on current entry */
                        foreach ($this->Entry['mods'] as $mod) {
                            switch ($mod['modtype']) {
                                case LDAP_MODIFY_BATCH_ADD:
                                    if (!isset($this->Entry['current'][$mod['attrib']])) {
                                        $this->Entry['current'][$mod['attrib']] = $mod['values'];
                                    } else {
                                        $this->Entry['current'][$mod['attrib']] = array_merge($this->Entry['current'][$mod['attrib']], $mod['values']);
                                    }
                                break;
                                case LDAP_MODIFY_BATCH_REPLACE:
                                    $this->Entry['current'][$mod['attrib']] = $mod['values'];
                                break;
                                case LDAP_MODIFY_BATCH_REMOVE_ALL:
                                    unset($this->Entry['current'][$mod['attrib']]);
                                break;
                                case LDAP_MODIFY_BATCH_REMOVE:
                                    $keys = [];
                                    foreach ($this->Entry['current'][$mod['attrib']] as $k => $v1) {
                                        foreach ($mod['values'] as $v2) {
                                            if (strcmp($v1, $v2) === 0 && !in_array($k, $keys)) {
                                                $keys[] = $k;
                                            }
                                        }
                                    }
                                    foreach($keys as $k) {
                                        unset($this->Entry['current'][$mod['attrib']][$k]);
                                    }
                                    /* empty attribute are removed */
                                    if (empty($this->Entry['current'][$mod['attrib']])) {
                                        unset($this->Entry['current'][$mod['attrib']]);
                                    }
                                break;
                            }
                        }
                    }
                } else {
                    $result = true;
                }
            } else {
                if (empty($this->Entry['dn'])) {
                    return false;
                }
                /* build entry for addition */
                foreach ($this->Entry['mods'] as $mod) {
                    switch ($mod['modtype']) {
                        case LDAP_MODIFY_BATCH_REPLACE:
                        case LDAP_MODIFY_BATCH_ADD:
                            $this->Entry['current'][$mod['attrib']] = $mod['values'];
                        break;
                    }
                }
                $rdn = explode('=', explode(',', $this->Entry['dn'])[0], 2);
                if (empty($this->Entry[$rdn[0]])) {
                    $this->Entry['current'][$rdn[0]] = [$rdn[1]];
                } else {
                    if (!in_array($rdn[1], $this->Entry['current'][$rdn[0]])) {
                        $this->Entry['current'][$rdn[0]][] = $rdn[1];
                    }
                }
                $result = @ldap_add($conn, $this->Entry['dn'], $this->Entry['current']);
            }
        }

        if ($result && $this->Entry['moveTo'] !== null) {
            $result = $this->_moveTo($conn);
        }

        $this->lastError = ldap_errno($conn);
        if ($result) {
            $this->Entry['new'] = false;
            $this->_reset();
        }

        return $result;
    }

    /* get all attribute by removing option */
    function getAll($attr) {
        $unopted = $this->unoptName($attr);
        $retval = [];
        foreach ($this->Entry['unopted'][$unopted] as $name) {
            $retval[] = ['name' => $name, 'value' => $this->Entry['current'][$name]];
        }
        return $retval;
    }

    function get($attr) {
        if (isset($this->Entry['current'][$attr])) {
            return $this->Entry['current'][$attr];
        }
        return null;
    }

    function rename($newRdn) {
        /* no dn -> no rename */
        if ($this->Entry['dn'] === null) {
            return false;
        }
        $this->Entry['renameTo'] = $newRdn;
        return true;
    }

    function move($newRdn) {
        /* no known server if no DN set */
        if ($this->Entry['dn'] === null) {
            return false;
        }
        /* no support for moving between servers ... but can be implemented in some way */
        if (!$this->Server->isDnInSameContext($newRdn)) {
            return false;
        }
        $this->Entry['moveTo'] = $newRdn;
        return true;
    }

    function dn($dn = null) {
        if ($dn === null) {
            return $this->Entry['dn'];
        } else {
            // rename or create entry
            if ($this->Entry['dn'] === null) {
                $this->Server = $this->Ldap->findServerForDn($dn);
                if (!$this->Server) { return false; } // no server for DN
                $this->Conn = $this->Server->getConnection();        
                $this->Entry['dn'] = $dn;
            } else {

            }
        }
    }

    function replace($attr, $values) 
    {
        if (!isset($this->Entry['current'][$attr])) {
            return $this->add($attr, $values);
        } else {
            $this->Entry['mods'][] = [
                'modtype' => LDAP_MODIFY_BATCH_REPLACE,
                'attrib' => $attr,
                'values' => $values
            ];
        }
        return true;
    }

    function add($attr, $values)
    {
        $this->Entry['mods'][] = [
            'modtype' => LDAP_MODIFY_BATCH_ADD,
            'attrib' => $attr,
            'values' => $values
        ];
        return true;
    }

    function delete($attr, $values = null) 
    {
        if (!isset($this->Entry['current'][$attr])) {
            return false;
        }
        if ($values === null) {
            $this->Entry['mods'][] = [
                'modtype' => LDAP_MODIFY_BATCH_REMOVE_ALL,
                'attrib' => $attr
            ];
        } else {
            $this->Entry['mods'][] = [
                'modtype' => LDAP_MODIFY_BATCH_REMOVE,
                'attrib' => $attr,
                'values' => $values
            ];
        }
        return true;
    }
}
?>