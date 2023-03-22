<?php
const API_SQLT_EXEC = 0;
const API_SQLT_SINGLE = 1;

class ApiSqlite
{

    private $path;
    private $read;
    private $data;
    private $table;
    private $id;

    public function __construct($path = null)
    {
        $this->path = $path ?: 'data/data.db';
    }

    public function insert($data, $table = 'services')
    {
        if (!(is_array($data) || is_object($data))) {
            return false;
        }
        $this->data = is_array($data) ? $data : (array)$data;
        $this->table = $table;
        return $this->execQuery($this->prepareInsert());
    }

    private function prepareInsert()
    {
        $sql = 'insert into ' . $this->table . " (";
        $this->data['created'] = $this->getTime();
        $keys = array_keys($this->data);
        $valid_keys = [];
        $values = [];
        foreach ($keys as $key) {
            if (is_null($this->data[$key])) {
                continue;
            }
            $valid_keys[] = $key ;
            $values[] = "'" . $this->data[$key] . "'";
        }
        return $sql . implode(',', $valid_keys) . ") values (" .
            implode(',', $values) . ")";
    }

    private function getTime()
    {
        return (new DateTime())->format('Y-m-d H:i:s');
    }

    public function edit($data, $table = 'services')
    {
        if (!(is_array($data) || is_object($data))) {
            return false;
        }
        $this->data = is_array($data) ? $data : (array)$data;
        $this->id = $this->data['id'];
        unset($this->data['id']);
        $this->table = $table;
        return $this->execQuery($this->prepareUpdate());
    }

    public function exec($sql): ?bool
    {
        return $this->db()->exec($sql);
    }

    private function prepareUpdate()
    {
        $sql = 'update ' . $this->table . " set ";
        $this->data['last'] = $this->getTime();
        $keys = array_keys($this->data);
        $fields = '';
        foreach ($keys as $key) {
            if (is_null($this->data[$key])) {
                continue;
            }
            $fields .= $key . "='" . $this->data[$key] . "',";
        }
        return $sql . substr($fields, 0, -1) . " where id=" . $this->id;
    }

    public function ifServiceIdExists($id)
    {
        $sql = "select id from services where id=" . $id;
        return $this->singleQuery($sql);
    }

    public function ifDeviceNameIsUsed($name)
    {
        $sql = "select id from devices where name='" . $name . "' collate nocase";
        return $this->singleQuery($sql);
    }

    public function ifIpAddressIsUsed($address)
    {
        $sql = "select id from services where address='"
            . $address . "' or prefix6='" . $address . "'";
        return $this->singleQuery($sql);
    }

    public function countServicesByDeviceId($id)
    {
        $sql = "select count(id) from services where device=" . $id;
        return $this->singleQuery($sql);
    }

    public function countDeviceServicesByPlanId($planId, $deviceId)
    {
        $sql = "select count(services.id) from services "
            . "where planId=" . $planId . " and device=" . $deviceId;
        return $this->singleQuery($sql);
    }

    public function selectServicesOnDevice($device_id,$limit=null,$offset=null)
    {
        if($limit) $limit = ' LIMIT ' . $limit ;
        if($offset) $offset = ' OFFSET ' . $offset ;
        $sql = "select * from services where device=" . $device_id . $limit . $offset;
        $res = $this->query($sql);
        $return = null;
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $return[] = $row;
        }
        return $return;
    }

    public function selectServiceById($id): ?stdClass
    {
        $sql = "select services.*,devices.name as deviceName from services left join devices "
            . "on services.device=devices.id where services.id=" . $id;
        return (object)$this->singleQuery($sql,true) ?? null;
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
        $sql = "select services.*,devices.name as deviceName from services left join devices "
            . "on services.device=devices.id";
        $res = $this->query($sql);
        $return = null;
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $return[] = $row;
        }
        return $return;
    }

    public function selectTargets($id, $devId)
    {
        $sql = "select address from services where planId=" . $id . " and device=" . $devId;
        $res = $this->query($sql);
        $return = null;
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $return[$row['address']] = $row['address'];
        }
        return $return;
    }

    public function setVersion($version)
    {
        $sql = "update config set value='" . $version . "' where key='version'";
        return $this->execQuery($sql);
    }

    public function delete($id, $table = 'services')
    {
        $sql = 'delete from ' . $table . " where id=" . $id;
        return $this->execQuery($sql);
    }

    public function deleteAll($table)
    {
        $sql = "delete from " . $table;
        return $this->execQuery($sql);
    }

    public function readConfig()
    {
        $this->read = $this->selectAllFromTable('config');
        $return = null;
        foreach ($this->read as $row) {
            $return[$row['key']] = $this->fixBoolValue($row['value']);
        }
        return (object)$return;
    }

    public function selectAllFromTable($table = 'services')
    {
        $sql = 'select * from ' . $table;
        $res = $this->query($sql);
        $return = null;
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $return[] = $row;
        }
        return $return;
    }

    private function fixBoolValue($value)
    {
        return ($value == 'true' || $value == 'false') ? ($value == 'true' ? true : false) : $value ?? '';
    }

    public function saveConfig($data)
    {
        $keys = array_keys((array)$data);
        foreach ($keys as $key) {
            $val = $data->{$key};
            $value = is_bool($val) ? ($val ? 'true' : 'false') : $val;
            $sql = "update config set value='" . $value
                . "' where key='" . $key . "'";
            if (!$this->execQuery($sql)) {
                return false;
            }
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
    
    private function execQuery($sql)
    {
        return $this->db()->exec($sql);
    }

    private function singleQuery($sql,$entireRow=false)
    {
        return $this->query($sql,API_SQLT_SINGLE,$entireRow);
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

}
