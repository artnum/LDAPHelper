<?PHP
namespace artnum;

class LDAPHelperResult {
    function __construct($result, LDAPHelperServer $server) {
        $this->Result = $result;
        $this->Server = $server;
        $this->currentEntry = false;
        $this->Conn = $server->getConnection();
    }

    function count() {
        if ($this->Result === null || $this->Result === false) { return 0; }
        $retval = ldap_count_entries($this->Conn, $this->Result);
        if ($retval === false) {
            return 0;
        }
        return $retval;
    }

    function firstEntry() {
        if ($this->Result === null || $this->Result === false) { return null; }
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

?>