<?php
class AdminDevices extends Admin
{
    public function delete(): bool
    {

        if (!$this->db()->delete($this->data->id, 'devices')) {
            $this->set_error('database error');
            return false;
        }
        $this->recache();
        $this->set_message('device has been deleted');
        return true;
    }

    public function insert(): bool
    {
        unset($this->data->id);
        $this->trim_prefix();
        if (!$this->db()->insert($this->data, 'devices')) {
            $this->set_error('database error');
            return false;
        }
        $this->set_message('device has been added');
        $this->recache();
        return true;
    }

    private function recache(): void
    {
        $api = new Admin_System();
        if(function_exists('fastcgi_finish_request')){
            respond('Device has been updated!');
            fastcgi_finish_request();
        }
        sleep(5);
        $api->recache();
    }

    public function clear()
    {
        $id = $this->data->id ?? 0 ;
        $ids = [];
        $select = $this->dbCache()->selectCustom(sprintf
            ("SELECT id FROM services WHERE device = %s AND status NOT IN (2,5,8) ",$id));
        foreach ($select as $item) $ids[] = $item['id'];
        $api = new MtBatch();
        $api->del_accounts($ids);
    }

    public function edit(): bool
    {

        $this->trim_prefix();
        if (!$this->db()->edit($this->data, 'devices')) {
            $this->set_error('database error');
            return false;
        }
        $this->recache();
        $this->set_message('device has been updated');
        return true;
    }

    public function getAllServices(): bool
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
        $this->setDisabled();
        $this->result = $this->read;
        $this->set_message('devices retrieved');
        return true;
    }

    public function services()
    {
        $this->result = $this->get_services();
    }

    private function get_services()
    {
        $db = new SQLite3('data/data.db');
        $db->exec("ATTACH 'data/cache.db' as cache");
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
        $fields = "services.*,services.username,services.mac,services.price,".
            "network.address,network.address6,clients.company,clients.firstName,clients.lastName";
        $did = $this->data->did ?? $this->data->id ?? $this->data->device ?? 0 ;
        $sql = sprintf("SELECT %s FROM services LEFT JOIN cache.clients ON ".
            "main.services.clientId=clients.id LEFT JOIN network ON main.services.id=network.id ".
            "LEFT JOIN cache.services ON main.services.id=cache.services.id ".
            "WHERE main.services.device = %s AND main.services.status NOT IN (2,5,8) ",$fields,$did);
        $query = $this->data->query ?? null ;
        if($query){
            if(is_numeric($query)){
                $sql .= sprintf("AND (main.services.id=%s OR main.services.clientId=%s) ",$query,$query);
            }
            else{
                $sql .= sprintf("AND (clients.firstName LIKE '%%%s%%' OR clients.lastName LIKE '%%%s%%' ".
                    "OR clients.company LIKE '%%%s%%' OR services.username LIKE '%%%s%%' OR services.mac LIKE '%%%s%%') ",
                    $query,$query,$query,$query,$query);
            }
        }
        $limit = $this->data->limit ?? 100 ;
        $offset = $this->data->offset ?? 0 ;
        $sql .= sprintf("ORDER BY main.services.id DESC LIMIT %s OFFSET %s",$limit,$offset);
        MyLog()->Append("services sql: ".$sql);
        return $sql;
    }

    private function cache_count()
    {
        $device = $this->data->did ?? $this->data->id ?? $this->data->device ?? 0 ;
        $sql = sprintf("SELECT COUNT(services.id) FROM services WHERE ".
            "services.device = %s AND services.status NOT IN (2,5,8) ",$device);
        return $this->db()->singleQuery($sql) ;
    }

    private function read(): bool
    {
        $this->read = $this->db()->selectAllFromTable('devices') ?? [];
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

    private function setDisabled(){
        $map = [];
        foreach ($this->read as $item) {
            $item['disabled'] = false ;
            $map[$item['id']] = $item ;
        };
        $conf = $this->db()->readConfig()->disabled_routers ?? null;
        if($conf) foreach (explode(',',$conf) as $id){$map[$id]['disabled'] = true ;}
        $this->read = array_values($map);
    }

    private function default_port($type): int
    {
        $ports = array(
            'mikrotik' => 8728,
            'cisco' => 22,
            'radius' => 3301,
            'edgeos' => 443,
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
