<?php

class API_Mysql
{

    private $result;
    private $device; // mysql host information
    private $db; // Mysqli object

    public function __construct($device)
    {
        $this->device = $device;
        $this->connect();
    }

    private function connect()
    {
        $this->db = new mysqli($this->device->ip, $this->device->user,
            $this->device->password, $this->device->dbname);
    }

    public function insert($data, $table)
    {
        $sql = "insert into " . $table .
            $this->prep_insert($data, $table);
        return $this->db->query($sql);
    }

    public function prep_insert($data, $table)
    {
        $cols = $this->columns($table);
        $vals = [];
        $keys = [];
        foreach ($cols as $key) {
            if (in_array($key, ['created', 'modified', 'last'])) {
                $data[$key] = date('Y-m-d H:i:s');
            }
            if (!$this->null_check($key, $data)) {
                continue;
            }
            if (is_numeric($data[$key]) ||
                in_array($key, ['yoda'])) {
                $vals[] = $data[$key];
                $keys[] = $key;
                continue;
            }
            $vals[] = "'" . $data[$key] . "'";
            $keys[] = $key;
        }
        return "(" . implode(",", $keys)
            . ") values ("
            . implode(",", $vals) . ")";
    }

    public function columns($table)
    {
        $sql = "describe " . $table;
        $this->result = $this->db->query($sql);
        if (!$this->result) {
            $this->set_error($this->db->error);
        }
        $fields = [];
        while ($row = $this->result->fetch_object()) {
            $fields[] = $row->Field;
        }
        return $fields;
    }

    private function set_error($message)
    {

    }

    private function null_check($key, $data)
    {
        if (!in_array($key, array_keys($data))) {
            return false;
        }
        if (is_null($data[$key])) {
            return false;
        }
        return true;
    }

    public function update($data, $table)
    {
        $sql = "update " . $table . " set " . $this->prep_update($data, $table);
        return $this->db->query($sql);
    }

    public function prep_update(&$data, $table)
    {
        $keys = $this->columns($table);
        $fields = '';
        foreach ($keys as $key) {//prepare fields
            if (!$this->null_check($key, $data)) {
                continue;
            }
            if (in_array($key, ['created', 'id'])) {
                continue;
            }
            if (is_numeric($data[$key])) {
                $fields .= $key . "=" . $data[$key] . ",";
            } else {
                $fields .= $key . "='" . $data[$key] . "',";
            }
        }
        if (strlen($fields) < 1) {
            return false;
        }
        return substr($fields, 0, -1) .
            " where id=" . $data['id'];
    }

    public function radiusAccountExists($username, $table = 'radcheck')
    {
        $sql = "select id from " . $table . " where username='" . $username . "'";
        return $this->db->query($sql)->fetch_row()[0] ?? false;
    }

    public function deleteRadiusAccount($username)
    {
        $tables = ['radcheck', 'radreply'];
        foreach ($tables as $table) {
            $sql = "delete from " . $table . " where username='" . $username . "'";
            if (!$this->db->query($sql)) {
                return false;
            }
        }
        return true;
    }

    public function selectNasAddresses()
    {
        $sql = "select nasname from nas";
        $read = $this->db->query($sql);
        $list = [];
        while ($row = $read->fetch_array()) {
            $list[] = $row[0];
        }
        return $list;
    }

    public function selectRadId($data, $table)
    {
        $sql = "select id from " . $table . " where username='" .
            $data['username'] . "' and attribute='" . $data['attribute'] . "'";
        return $this->db->query($sql)->fetch_row()[0] ?? false;

    }

    public function error()
    {
        return $this->db->error;
    }

}
