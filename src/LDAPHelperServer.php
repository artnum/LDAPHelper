<?PHP
namespace artnum;

class LDAPHelperServer
{
    function __construct($uri, $conn)
    {
        $this->UseHelperEntry = true;
        $this->URI = $uri;
        $this->Conn = $conn;
        $this->Readonly = false;
        $this->Writer = null;
        $this->Schemas = [];
        $this->Contexts = [];
        $this->Syntaxes = [];
    }

    function getConnection() {
        return $this->Conn;
    }

    function setWriter($writer) {
        $this->Writer = $writer;
    }

    function getWriter() {
        if ($this->Writer !== null) 
        { 
            return $this->Writer; 
        }
        if ($this->Writer === null && $this->Readonly !== true) {
            return $this;
        }
        return null;
    }

    function isReadonly() {
        return $this->Readonly;
    }

    function setReadonly()
    {
        $this->Readonly = true;
    }

    function setSchema($schemas)
    {
        $this->Schemas = $schemas;
    }

    function getSchema() 
    {
        return $this->Schemas;
    }

    function setContext($contexts)
    {
        $this->Contexts = $contexts;
    }

    function getContext()
    {
        return $this->Contexts;
    }

    function isDnInSameContext($dn) {
        foreach ($this->Contexts as $ctx) {
            $parts = explode(',', $dn);
            $ctxParts = explode(',', $ctx);
            $in = false;
            while ($ctxRdn = array_pop($ctxParts)) {
                $dnRdn = array_pop($parts);
                $in = false;
                if ($dnRdn === FALSE) { break; }
                if ($dnRdn !== $ctxRdn) { break; }
                $in = true;
            }
            if ($in) { return true; }
        }
        return false;
    }

    function isServerInSameContext($server) {
        $otherContexts = $server->getContext();
        foreach ($otherContexts as $ctx1) {
            foreach ($this->Contexts as $ctx2) {
                if ($ctx1 === $ctx2) { return true; }
            }
        }
        return false;
    }

    function checkContext($base)
    {
        foreach ($this->Contexts as $ctx) {
            if (strstr($base, $ctx)) {
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

    // attribute can have options, remove the option part
    private function unoptName($name) 
    {
        return explode(';', $name)[0];
    }

    /* return objectclasses or attributetype if it exists */
    private function _exists($name, $attribute = true)
    {
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
        $name = strtolower($this->unoptName($name));
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
        $name = strtolower($this->unoptName($name));
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
    
    function getValue($entryid, $attr, &$syntax = null)
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

    private function _getEntry($entryid)
    {
        $entry = [];
        for ($attr = ldap_first_attribute($this->Conn, $entryid); $attr; $attr = ldap_next_attribute($this->Conn, $entryid)) {
            $entry[$attr] = $this->getValue($entryid, $attr);
        }
        return $entry;
    }

    /* use helper entry */
    private function _getHelperEntry($entryid)
    {
        return new LDAPHelperEntry($this, $entryid);
    }

    function getEntry($entryid) 
    {
        if ($this->UseHelperEntry) {
            return $this->_getHelperEntry($entryid);
        } else {
            return $this->_getEntry($entryid);
        }
    }
 }

 ?>