<?php
const API_SQLT_SINGLE = 1;

class ApiSqlite
{

    private string $path;
    private $read;
    private bool $mem  ;
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

    public function deleteAll($table = 'services'){
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
        $res = $this->query($sql);
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
        if(empty($this->_db)){
            $path = $this->mem ? ':memory:' : $this->path ;
            $this->_db = new SQLite3($path);
            $this->_db->busyTimeout(5000);
            $this->_db->enableExceptions(true);
            if($this->mem){ $this->load_disk(); }
        }
        return $this->_db ;
    }

    public function test (){ return $this->db(); }

    private function load_disk()
    {
        $cache = $this->path != 'data/data.db';
        $schema = $cache ? 'includes/cache.sql' : 'includes/schema.sql';
        $this->_db->exec(file_get_contents($schema));
        $this->_db->exec(sprintf('ATTACH "%s" as tmp',$this->path));
        $tables = $cache ? 'services,network,clients' : 'config,network';
        foreach (explode(',',$tables) as $table){
            $sql = sprintf('INSERT INTO "%s" SELECT * FROM tmp."%s"',$table,$table);
            $this->_db->exec($sql);
        }
        $this->_db->exec('DETACH tmp');
    }

    private function save_disk()
    {
        if($this->mem){
            $this->_db->exec(sprintf('ATTACH "%s" as tmp',$this->path));
            $cache = $this->path != 'data/data.db';
            $tables = $cache ? 'services,network,clients' : 'config,network';
            foreach (explode(',',$tables) as $table){
                $sql = sprintf('INSERT OR REPLACE INTO tmp."%s" SELECT * FROM "%s"',$table,$table);
                $this->_db->exec($sql);
            }
            $this->_db->close();
        }
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

    public function __construct($path = null,$mem = false)
    { $this->path = $path ?? 'data/data.db'; $this->mem = $mem; }

    public function __destruct()
    {
        $this->save_disk();
    }


}
