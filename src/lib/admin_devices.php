<?php
class AdminDevices extends Admin
{
    public function delete(): bool
    {

        $db = $this->connect();
        if (!$db->delete($this->data->id, 'devices')) {
            $this->set_error('database error');
            return false;
        }
        $this->set_message('device has been deleted');
        return true;
    }

    public function insert(): bool
    {

        $db = $this->connect();
        unset($this->data->id);
        $this->trim_prefix();
        if (!$db->insert($this->data, 'devices')) {
            $this->set_error('database error');
            return false;
        }
        $this->set_message('device has been added');
        return true;
    }

    public function edit(): bool
    {

        $db = $this->connect();
        $this->trim_prefix();
        if (!$db->edit($this->data, 'devices')) {
            $this->set_error('database error');
            return false;
        }
        $this->set_message('device has been updated');
        return true;
    }

    public function getAllServices()
    {
        $this->result =
            $this->db()->selectServices();
        return (bool) $this->result;
    }

    public function get(): bool
    {
        if(!$this->read()){ //it's not an error if no devices
            $this->result = [];
            return true ;
        }
        $this->setStatus();
        $this->setUsers();
        $this->result = $this->read;
        $this->set_message('devices retrieved');
        return true;
    }

    private function reset_pppoe($id)
    {
        $data = (object)[
            'device_id' => $id,
            'path' => '/interface/pppoe-server/server'
        ];
        $servers = (new MT($data))->get();
        foreach ($servers as $server)
        {
            $edit = (object)[
                'device_id'=> $id,
                'path' => '/interface/pppoe-server/server',
                'action' => 'disable',
                'data' => (object) ['.id' => $server['.id'],],];
            (new MT($edit))->set();
            $edit->action = 'enable';
            (new MT($edit))->set();
        }

    }

    private function save_router($id,$enable=false)
    {
        $list = json_decode($this->conf()->disabled_routers,true) ?? [];
        if($enable){
            unset($list[$id]);
        }else{
            $list[$id] = 1;
        }
        $data['disabled_routers'] = json_encode($list) ?? [];
        return $this->db()->saveConfig($data);
    }

    private function set_profile_limit($id,$profile,$plan,$enable=false)
    {
        $rate = $enable
            ? $plan['uploadSpeed']. 'M/'.$plan['downloadSpeed'] .'M'
            : null ;
        $parent = $enable
            ? 'servicePlan-'.$plan['id'].'-parent'
            : 'none';
        $data = (object)[
            'device_id' => $id,
            'action' => 'set',
            'path' => '/ppp/profile',
            'data' => (object)[
                '.id' => $profile['.id'],
                'rate-limit' => $rate,
                'parent-queue' => $parent,
            ],
        ];
        return (new MT($data))->set();
    }

    private function connect(): ApiSqlite
    {
        return new ApiSqlite();
    }

    public function services()
    {
        $this->result = $this->get_services();
    }

    private function get_services()
    {
        $db = new SQLite3('data/cache.db');
        $db->exec("ATTACH 'data/data.db' as svc");
        $result = $db->query($this->cache_sql()) ?? [];
        $cached = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)){
            $cached[] = $row ;
        }
        $db->close();
        $plans = $this->ucrm()->get('service-plans') ?? [];
        $plans = json_decode(json_encode($plans),true);
        $addressMap = [];
        $planMap = [];
        foreach ($plans as $plan)$planMap[$plan['id']] = $plan ;
        foreach ($cached as $item) $addressMap[$item['id']] = $item ;
        $ret = [];
        foreach ($cached as $item) {
            $item['plan'] = $planMap[$item['planId']]['name'] ?? null ;
            $ret[$item['id']] = $item ;
        }
        $ret['count'] = $this->cache_count();
        return $ret ;
    }

    private function cache_sql(){
        $fields = "services.*,network.address,network.address6,clients.company,".
            "clients.firstName,clients.lastName";
        $did = $this->data->did ?? $this->data->id ?? $this->data->device ?? 0 ;
        $sql = sprintf("SELECT %s FROM services LEFT JOIN clients ON ".
            "services.clientId=clients.id LEFT JOIN svc.network ON services.id=network.id ".
            "WHERE services.device = %s AND services.status NOT IN (2,5,8) ",$fields,$did);
        $query = $this->data->query ?? null ;
        if($query){
            if(is_numeric($query)){
                $sql .= sprintf("AND (services.id=%s OR services.clientId=%s) ",$query,$query);
            }
            else{
                $sql .= sprintf("AND (clients.firstName LIKE '%%%s%%' OR clients.lastName LIKE '%%%s%%' ".
                    "OR clients.company LIKE '%%%s%%' OR services.username LIKE '%%%s%%' OR services.mac LIKE '%%%s%%') ",
                    $query,$query,$query,$query,$query);
            }
        }
        $limit = $this->data->limit ?? 100 ;
        $offset = $this->data->offset ?? 0 ;
        $sql .= sprintf("ORDER BY services.id DESC LIMIT %s OFFSET %s",$limit,$offset);
        MyLog()->Append("services sql: ".$sql);
        return $sql;
    }

    private function cache_count()
    {
        $device = $this->data->did ?? $this->data->id ?? $this->data->device ?? 0 ;
        $sql = sprintf("SELECT COUNT(services.id) FROM services LEFT JOIN clients ON ".
            "services.clientId=clients.id WHERE services.device = %s AND services.status ".
            "NOT IN (2,5,8) ",$device);
        return $this->dbCache()->singleQuery($sql) ;
    }

    private function read(): bool
    {
        $this->read = $this->db()->selectAllFromTable('devices');
        return !empty($this->read) ;
    }

    private function trim_prefix(): void
    {
        $pfx = $this->data->pfxLength ?? null ;
        if($pfx) $this->data->pfxLength = trim($pfx,"/");
    }

    private function setStatus(): void
    {
        foreach ($this->read as &$device) {
            try{
                $conn = @fsockopen($device['ip'],
                $this->default_port($device['type']),
                $code, $err, 0.3);
                if (!is_resource($conn)) {
                    $device['status'] = false;
                    continue;
                }
                $device['status'] = true;
                fclose($conn);
            }
            catch (Exception $err){
                $device['status'] = false ;
            }
        }
    }

    private function default_port($type): int
    {
        $ports = array(
            'mikrotik' => 8728,
            'cisco' => 22,
            'radius' => 3301,
        );
        return $ports[$type];
    }

    private function setUsers(): void
    {
        $db = new ApiSqlite();
        foreach ($this->read as &$device) {
            $device['users'] = $db->countServicesByDeviceId($device['id']);
        }
    }

}
