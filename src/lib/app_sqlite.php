<?php

class CS_SQLite {

    private $path;
    private $read ;
    public $db;
    private $data;
    private $table;
    private $id;

    public function __construct($path = false) {
        if ($path) {
            $this->path = $path;
        } else {
            $this->path = 'data/data.db';
        }
        $this->db = new SQLite3($this->path);
    }
    
    public function selectAllFromTable($table = 'services') {
        $sql = 'select * from ' . $table;
        $res = $this->db->query($sql);
        $return = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            array_push($return, $row);
        }
        return $return;
    }

    public function insert($data, $table = 'services') {
        $this->data = $data;
        $this->table = $table;
        return $this->db->exec($this->prepareInsert());
    }

    public function upgrade($data, $table = 'services') {
        return $this->insert($data, $table);
    }
    
    public function suspend($data,$table='services'){
        return $this->edit($data, $table);
    }

    public function ifServiceIdExists($id) {
        $sql = "select id from services where id=" . $id;
        return $this->db->querySingle($sql);
    }

    public function ifUsernameExists($username) {
        $sql = "select id from users where username='" . $username . "'";
        return $this->db->querySingle($sql);
    }

    public function ifDeviceNameIsUsed($name) {
        $sql = "select id from devices where name='" . $name . "' collate nocase";
        return $this->db->querySingle($sql);
    }

    public function ifIpAddressIsUsed($address) {
        $sql = "select id from services where address='" . $address . "'";
        return $this->db->querySingle($sql);
    }

    public function countSuspendedServices() {
        $sql = "select count(id) from services where status!=1";
        return $this->db->querySingle($sql);
    }

    public function countServices() {
        $sql = "select count(id) from services";
        return $this->db->querySingle($sql);
    }

    public function countServicesByDeviceId($id) {
        $sql = "select count(id) from services where device=" . $id;
        return $this->db->querySingle($sql);
    }

    public function countDeviceServicesByPlanId($planId, $deviceName) {
        $sql = "select count(services.id) from services "
                . "left join devices on services.device=devices.id "
                . "where planId=" . $planId . " and devices.name='" . $deviceName
                . "' collate nocase";
        return $this->db->querySingle($sql);
    }

    public function updateColumnById($column, $value, $id, $table = 'services') {
        $sql = "update " . $table . " set " . $column . "='" . $value . "' where id=" . $id;
        return $this->db->exec($sql);
    }

    public function replaceServiceDeviceNameWithId($id, $name) {
        $sql = "update services set device=" . $id
                . " where device='" . $name . "' collate nocase";
        return $this->db->exec($sql);
    }

    public function selectIpAddressByServiceId($id) {
        $sql = "select address from services where id=" . $id;
        return $this->db->querySingle($sql);
    }

    public function selectServiceMikrotikIdByServiceId($id) {
        $sql = "select mtId from services where id=" . $id;
        return $this->db->querySingle($sql);
    }

    public function selectQueueMikrotikIdByServiceId($id) {
        $sql = "select queueId from services where id=" . $id;
        return $this->db->querySingle($sql);
    }

    public function selectUserByUsername($username) {
        $sql = "select * from users where username='" . $username . "'";
        return $this->db->querySingle($sql, true);
    }

    public function selectUserBySession($session) {
        $sql = "select * from users where session='" . $session . "'";
        return $this->db->querySingle($sql, true);
    }
    
    public function selectDeviceByDeviceName($name) {
        $sql = "select * from devices where name='" . $name . "' collate nocase";
        return (object) $this->db->querySingle($sql, true);
    }
  
    public function selectDeviceById($id) {
        $sql = "select * from devices where id=" . $id ;
        return (object) $this->db->querySingle($sql, true);
    }

    public function selectDeviceIdByDeviceName($name) {
        $sql = "select id from devices where name='" . $name . "' collate nocase";
        return $this->db->querySingle($sql);
    }

    public function selectDeviceNameByServiceId($id) {
        $sql = "select devices.name from services left join devices "
                . "on services.device=devices.id where services.id=" . $id;
        return $this->db->querySingle($sql);
    }

    public function delete($id, $table = 'services') {
        $sql = 'delete from ' . $table . " where id=" . $id;
        return $this->db->exec($sql);
    }

    public function deleteAll($table) {
        $sql = "delete from " . $table;
        return $this->db->exec($sql);
    }

    public function edit($data, $table = 'services') {
        $this->id = $data->id;
        unset($data->id);
        $this->data = $data;
        $this->table = $table;
        return $this->db->exec($this->prepareUpdate());
    }

    public function editConfig($key, $value) {
        $sql = "update config set value='" . $value . "' where key='" . $key . "'";
        return $this->db->exec($sql);
    }

    public function move($data, $table = 'services') {
        return $this->insert($data, $table);
    }

    public function readConfig() {
        $this->read = $this->selectAllFromTable('config');
        $return = [];
        foreach ($this->read as $row) {
            $return[$row['key']] = $this->fixBoolValue($row['value']);
        }
        return (object) $return ;
    }
    
    public function saveConfig($data){
        $keys = array_keys((array)$data);
        foreach($keys as $key){
            $val = $data->{$key};
            $value = is_bool($val) ? ($val ? 'true' : 'false' ): $val ;
            $sql = "update config set value='".$value
                    ."' where key='".$key."'";
            if(!$this->db->exec($sql)){
                return false;
            }
        }
        return true ;
    }
    
    private function fixBoolValue($value){
      return ($value == 'true' || $value == 'false') 
        ? ($value == 'true' ? true : false) 
        : $value ?? '';
    }

    private function prepareUpdate() {
        $sql = 'update ' . $this->table . " set ";
        $this->data->last = $this->getTime();
        $keys = array_keys((array) $this->data);
        $fields = '';
        foreach ($keys as $key) {
            $fields .= $key . "='" . $this->data->{$key} . "',";
        }
        return $sql . substr($fields, 0, -1) . " where id=" . $this->id;
    }

    private function prepareInsert() {
        $sql = 'insert into ' . $this->table . " (";
        $this->data->created = $this->getTime();
        $keys = array_keys((array) $this->data);
        $vals = array();
        foreach ($keys as $key) {
            $vals[] = "'" . $this->data->{$key} . "'";
        }
        return $sql . implode(',', $keys) . ") values (" .
                implode(',', $vals) . ")";
    }

    private function getTime() {
        return (new DateTime())->format('Y-m-d H:i:s');
    }

}
