<?php

class ApiSqlite
{

    private string $path;
    private $read;
    private ?SQLite3 $_db = null;

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
        if(is_numeric($value)) return $value;
        if(empty($value)) return 'null';
        return sprintf('"%s"',SQLite3::escapeString($value));
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

    public function selectDeviceByDeviceName($name): object
    {
        $sql = "select * from devices where name='" . $name . "' collate nocase";
        return (object)$this->singleQuery($sql,true);
    }

    public function selectDeviceById($id): object
    {
        $sql = "select * from devices where id=" . $id;
        return (object)$this->singleQuery($sql,true);
    }

    public function selectServices(): ?array
    {
        $fields = 'services.*,devices.name as deviceName,network.address,network.address6';
        $sql = sprintf("select %s from services left join devices on services.device=devices.id ".
            "left join network on services.id=network.id",$fields);
        $res = $this->db()->query($sql);
        $return = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $return[] = $row;
        }
        return $return;
    }

    public function deleteAll($table = 'services'): bool
    {
        $sql = sprintf("delete from %s",$table);
        return $this->db()->exec($sql);
    }

    public function delete($id, $table = 'services'): bool
    {
        $sql = sprintf("delete from %s where id = %s",$table,$id);
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
        $res = $this->db()->query($sql);
        $return = [];
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

    public function readConfig(): ?object
    {
        $read = $this->selectAllFromTable('config') ?? [];
        $cfg = new stdClass();
        foreach ($read as $row) {
            $key = $row['key'] ?? 'nokey';
            $cfg->$key  = $this->unStringify($row['value']);
        }
        return $cfg ;
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
        if(empty($this->_db)){
            $this->_db = new SQLite3($this->path);
            $this->_db->busyTimeout(5000);
            $this->_db->enableExceptions(true);
        }
        return $this->_db ;
    }

    public function selectIsUsed($address, $ipv6 = false):bool
    {
        $field = $ipv6 ? 'address6' : 'address';
        $query = sprintf("select id from network where %s = '%s'",$field,$address);
        $id = $this->db()->querySingle($query) ;
        return (bool) $id ;
    }

    public function selectAddress($sid,$ipv6 = false): ?string
    {
        $field = $ipv6 ? 'address6': 'address';
        $query = "select $field from network where id='$sid'";
        return $this->db()->querySingle($query);
    }

    public function singleQuery($sql,$entireRow=false)
    {
        return $this->db()->querySingle($sql,$entireRow);
    }

    public function __construct($path = null) { $this->path = $path ?? 'data/data.db';}

}

$apiSqlite = null ;
$apiSqliteCache = null ;

function mySqlite()
{
    global $apiSqlite ;
    if(empty($apiSqlite)){
        MyLog()->Append("CREATING NEW DB INSTANCE");
        $apiSqlite = new ApiSqlite();
    }
    return $apiSqlite ;
}

function myCache()
{
    global $apiSqliteCache ;
    $fn = 'data/cache.db';
    if(empty($apiSqliteCache)){
        $apiSqliteCache = new ApiSqlite($fn);
    }
    return $apiSqliteCache ;
}