<?php
const API_SQLT_SINGLE = 1;

class ApiSqlite
{

    private string $path;
    private $read;

    public function insert($data,$table = 'services',$replace = false): bool
    { //insert single row or batch
        $data = $this->to_array($data);
        if(!$data) return false ;
        $fields = $this->to_fields($data);
        $values = $this->to_values($data);
        $REPLACE = null ;
        if($replace) $REPLACE = 'OR REPLACE ';
        $query = sprintf("INSERT %s INTO %s (%s) VALUES %s",   $REPLACE,$table,$fields,$values);
        MyLog()->Append("sqlite query: ".$query);
        return $this->db()->exec($query);
    }

    private function to_values($data): ?string
    {
        $values = [];
        foreach ($data as $item){
            $row = [];
            foreach($item as $value){ $row[] = $this->quote($value);}
            $values[] = sprintf("(%s)",implode(',',$row));
        }
        return implode(',',$values);
    }

    private function quote($value)
    {
        if(empty($value)) return 'null';
        if(is_numeric($value)) return $value;
        return sprintf("'%s'",$value);
    }

    private function to_fields($data): ?string
    {
        $first = array_values($data)[0] ?? [];
        $fields = [];
        foreach (array_keys($first) as $key){
            $fields[] = $this->quote($key);
        }
        return implode(',',$fields);
    }

    private function to_array($data): ?array
    {
        if(is_object($data)) return [json_decode(json_encode($data),true)];
        if(is_array($data)){
            $first = array_values($data)[0] ?? null;
            if(is_object($first)) return json_decode(json_encode($data),true);
            if(is_array($first)) return $data ;
            return [$data];
        }
        return null ;
    }

    public function edit($data,$table = 'services'): bool
    {
        $data = $this->to_array($data);
        if(!$data) return false ;
        $sql = sprintf("UPDATE %s SET ",$table);
        foreach($data as $row){
            $query = $sql . $this->to_edit($row);
            if(!$this->db()->exec($query)) return false ;
        }
        return true ;
    }

    private function to_edit($row): ?string
    {
        $id = $row['id'] ?? null ;
        if(!$id) return null ;
        $row = array_diff_key($row,['id' => null]);
        $values = [];
        foreach(array_keys($row) as $key){
            $value = $row[$key] ?? null ;
            $values[] = sprintf("%s=%s",$key,$this->quote($value));
        }
        return implode(',',$values) . " WHERE id=" . $id;
    }

    public function has_tables($tables = ['services','devices','config']): bool
    {
        foreach ($tables as $table){
            $sql = "SELECT name from sqlite_master where type='table' AND name='" . $table ."'";
            if(empty($this->db()->querySingle($sql))) return false;
        }
        return true ;
    }

    public function exists($id,$table = 'network'): ?int
    {
        return $this->db()->querySingle(
            sprintf('select id from %s where id = %s',$table,$id));
    }

    public function exec($sql): ?bool
    {
        return $this->db()->exec($sql);
    }

    public function ifDeviceNameIsUsed($name)
    {
        $sql = "select id from devices where name='" . $name . "' collate nocase";
        return $this->singleQuery($sql);
    }

    public function countServicesByDeviceId($id)
    {
        $sql = "select count(id) from services where device=" . $id;
        return $this->singleQuery($sql);
    }

    public function selectIp($id,$ip6): ?string
    {
        $field = 'address';
        if($ip6) $field = 'address6';
        $sql = sprintf("SELECT %s FROM network WHERE id=%s",$field,$id);
        return $this->db()->querySingle($sql);
    }

    public function selectDeviceByDeviceName($name)
    {
        $sql = "select * from devices where name='" . $name . "' collate nocase";
        return (object)$this->singleQuery($sql,true);
    }

    public function selectDeviceById($id)
    {
        $sql = "select * from devices where id=" . $id;
        return (object)$this->singleQuery($sql,true);
    }

    public function selectServices(): ?array
    {
        $fields = 'services.*,devices.name as deviceName,network.address,network.address6';
        $sql = sprintf("select %s from services left join devices on services.device=devices.id ".
            "left join network on services.id=network.id",$fields);
        $res = $this->query($sql);
        $return = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $return[] = $row;
        }
        return $return;
    }

    public function delete($id, $table = 'services'): bool
    {
        $sql = 'delete from ' . $table . " where id=" . $id;
        return $this->db()->exec($sql);
    }

    public function selectCustom($sql) : ?array
    {
        $res = $this->db()->query($sql);
        $return = null;
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $return[] = $row;
        }
        return $return;
    }

    public function selectAllFromTable($table = 'services'): ?array
    {
        $sql = 'select * from ' . $table;
        $res = $this->query($sql);
        $return = null;
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $return[] = $row;
        }
        return $return;
    }

    private function unStringify($value)
    {
        if(in_array($value,['true','false'])) return $value == 'true' ;
        if(is_numeric($value)) return (double) $value;
        if(empty($value)) return null;
        return $value ;
    }

    private function stringify($value)
    {
        if(is_bool($value)) return $value ? 'true' : 'false';
        if(is_numeric($value)) return (string) $value;
        if(empty($value)) return null ;
        return $value ;
    }

    public function readConfig(): ?stdClass
    {
        $this->read = $this->selectAllFromTable('config') ?? [];
        $return = null;
        foreach ($this->read as $row) {
            $return[$row['key']] = $this->unStringify($row['value']);
        }
        return (object)$return;
    }

    public function saveConfig($fields): bool
    {
        $data = (array) $fields ;
        foreach(array_keys($data) as $key){
            $now = (new DateTime())->format('Y-m-d H:i:s');
            $value = $this->stringify($data[$key] ?? null);
            $sql = sprintf("INSERT OR REPLACE INTO config ('key','value','last','created')".
                " VALUES ('%s','%s','%s','%s') ",$key,$value,$now,'2020-01-01 00:00:00');
            if(!$this->db()->exec($sql)) return false;
        }
        return true;
    }

    private function db(): SQLite3
    {
        $db = new SQLite3($this->path);
        $db->busyTimeout(100);
        $db->enableExceptions(true);
        return $db ;
    }
    
    public function execQuery($sql)
    {
        return $this->db()->exec($sql);
    }

    public function singleQuery($sql,$entireRow=false)
    {
        return $this->db()->querySingle($sql,$entireRow);
    }

    private function query($sql,$mode=2,$entireRow=null)
    {
        $db = $this->db();
        $db->enableExceptions(true);
        $modes = ['exec','querySingle','query'];
        try {
            $action = $modes[$mode] ?? 'query' ;
            return $entireRow 
                ? $db->$action($sql,$entireRow)
                : $db->$action($sql);
        } catch (Exception $err) {
            die($this->error($err->getMessage()));
        }
    }

    private function error($msg = 'failed')
    {
        $status = [
            'status' => 'failed',
            'error' => true,
            'message' => "Sqlite3 error: " . $msg,
        ];
        return json_encode($status);
    }

    public function __construct($path = null){ $this->path = $path ?? 'data/data.db'; }

//    public function ifServiceIdExists($id)
//    {
//        $sql = "select id from services where id=" . $id;
//        return $this->singleQuery($sql);
//    }
//    public function countDeviceServicesByPlanId($planId, $deviceId)
//    {
//        $sql = "select count(services.id) from services "
//            . "where planId=" . $planId . " and device=" . $deviceId;
//        return $this->singleQuery($sql);
//    }
//    public function ifIpAddressIsUsed($address)
//    {
//        $sql = "select id from services where address='"
//            . $address . "' or prefix6='" . $address . "'";
//        return $this->singleQuery($sql);
//    }
//    public function selectServicesOnDevice($device_id,$limit=null,$offset=null)
//    {
//        if($limit) $limit = ' LIMIT ' . $limit ;
//        if($offset) $offset = ' OFFSET ' . $offset ;
//        $sql = "select * from services where device=" . $device_id . $limit . $offset;
//        $res = $this->query($sql);
//        $return = null;
//        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
//            $return[] = $row;
//        }
//        return $return;
//    }
//    public function selectServiceById($id): ?stdClass
//    {
//        $sql = "select services.*,devices.name as deviceName from services left join devices "
//            . "on services.device=devices.id where services.id=" . $id;
//        return (object)$this->singleQuery($sql,true) ?? null;
//    }
//    public function selectTargets($id, $devId)
//    {
//        $sql = "select address from services where planId=" . $id . " and device=" . $devId;
//        $res = $this->query($sql);
//        $return = null;
//        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
//            $return[$row['address']] = $row['address'];
//        }
//        return $return;
//    }
//    public function setVersion($version)
//    {
//        $sql = "update config set value='" . $version . "' where key='version'";
//        return $this->execQuery($sql);
//    }
//    public function deleteAll($table)
//    {
//        $sql = "delete from " . $table;
//        return $this->execQuery($sql);
//    }
//    private function getTime()
//    {
//        return (new DateTime())->format('c');
//    }
    //    public function edit($data, $table = 'services')
//    {
//        if (!(is_array($data) || is_object($data))) {
//            return false;
//        }
//        $this->data = is_array($data) ? $data : (array)$data;
//        $this->id = $this->data['id'];
//        unset($this->data['id']);
//        $this->table = $table;
//        return $this->execQuery($this->prepareUpdate());
//    }

//    private function prepareUpdate()
//    {
//        $sql = 'update ' . $this->table . " set ";
//        $this->data['last'] = $this->getTime();
//        $keys = array_keys($this->data);
//        $fields = '';
//        foreach ($keys as $key) {
//            if (is_null($this->data[$key])) {
//                continue;
//            }
//            $fields .= $key . "='" . $this->data[$key] . "',";
//        }
//        return $sql . substr($fields, 0, -1) . " where id=" . $this->id;
//    }

//    public function insert($data, $table = 'services')
//    {
//        if (!(is_array($data) || is_object($data))) {
//            return false;
//        }
//        $this->data = is_array($data) ? $data : (array)$data;
//        $this->table = $table;
//        return $this->execQuery($this->prepareInsert());
//    }

//    private function prepareInsert()
//    {
//        $sql = 'insert into ' . $this->table . " (";
//        $this->data['created'] = $this->getTime();
//        $keys = array_keys($this->data);
//        $valid_keys = [];
//        $values = [];
//        foreach ($keys as $key) {
//            if (is_null($this->data[$key])) {
//                continue;
//            }
//            $valid_keys[] = $key ;
//            $values[] = "'" . $this->data[$key] . "'";
//        }
//        return $sql . implode(',', $valid_keys) . ") values (" .
//            implode(',', $values) . ")";
//    }


}
