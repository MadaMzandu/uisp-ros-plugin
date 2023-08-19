<?php
class ApiDevices extends Admin
{
    public function delete(): bool
    {

        if (!$this->db()->delete($this->data->id, 'devices')) {
            $this->set_error('database error');
            return false;
        }
        if(function_exists('fastcgi_finish_request')){
            respond('device has been added');
            fastcgi_finish_request();
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
        if(function_exists('fastcgi_finish_request')){
            respond('device has been added');
            fastcgi_finish_request();
        }
        $this->recache();
        return true;
    }

    private function recache(): void
    {
        $api = new ApiSystem();
        sleep(3);
        $api->recache();
    }

    private function rebuild_qs($queues)
    {
        $api = new Batch();
        $ids = $this->find_ids();
        if($queues > 0){
            $api->set_accounts($ids);
        }
        else{
            $api->del_queues($ids);
        }
    }

    public function clear()
    {//remove accounts from device
        $ids = $this->find_ids();
        $api = new Batch();
        $api->del_accounts($ids);
    }

    public function edit(): bool
    {

        $this->trim_prefix();
        $queues = $this->needs_rebuild_qs();
        $cache = $this->needs_cache();
        if (!$this->db()->edit($this->data, 'devices')) {
            $this->set_error('database error');
            return false;
        }
        if(function_exists('fastcgi_finish_request')){
            respond('device has been updated');
            fastcgi_finish_request();
        }
        if($cache) $this->recache();
        if($queues) $this->rebuild_qs($queues);
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
        $this->set_status();
        $this->set_users();
        $this->set_disabled();
        $this->result = $this->read;
        $this->set_message('devices retrieved');
        return true;
    }

    public function services()
    {
        $this->result = $this->get_services();
    }

    private function get_services(): array
    {
        $db = new SQLite3('data/data.db');
        $db->exec("ATTACH 'data/cache.db' as cache");
        $result = $db->query($this->cache_sql()) ?? [];
        $ret = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)){
            $row['address'] ??= $row['a4'] ?? null ;
            $row['address6'] ??= $row['a6'] ?? null ;
            $ret[$row['id']] = $row ;
        }
        $db->close();
        $ret['count'] = $this->cache_count();
        return $ret ;
    }

    private function find_ids(): array
    {
        $id = $this->data->id ?? 0 ;
        $ids = [];
        $select = $this->dbCache()->selectCustom(sprintf
        ("SELECT id FROM services WHERE device = %s AND status NOT IN (2,5,8) ",$id));
        foreach ($select as $item) $ids[] = $item['id'];
        return $ids ;
    }

    private function cache_sql(): string
    {
        $fields = "services.*,services.username,services.mac,services.price,".
            "main.network.address,main.network.address6,clients.company,clients.firstName,".
            "clients.lastName,plans.name as plan,cache.network.address as a4,".
            "cache.network.address6 as a6";
        $did = $this->data->did ?? $this->data->id ?? $this->data->device ?? 0 ;
        $sql = sprintf("SELECT %s FROM main.services ".
            "LEFT JOIN cache.clients ON main.services.clientId=clients.id ".
            "LEFT JOIN main.network ON main.services.id=main.network.id ".
            "LEFT JOIN cache.network on main.services.id=cache.network.id ".
            "LEFT JOIN main.plans on main.services.planId=plans.id ".
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

    private function needs_cache(): bool
    {
        if(!property_exists($this->data,'name')){ return false; }
        $id = $this->data->id ?? 0;
        if(!$id){ return false; }
        $device = $this->db()->selectDeviceById($id);
        $name = $device->name ?? null ;
        $edit = $this->data->name ?? null ;
        return $name !== $edit ;
    }

    private function needs_rebuild_qs(): int
    {
        if(!property_exists($this->data,'qos')){ return 0; }
        $id = $this->data->id ?? 0;
        if(!$id){ return 0; }
        $device = $this->db()->selectDeviceById($id);
        $type = $device->type ?? 'mikrotik';
        if($type != 'edgeos'){ return 0; }
        $edit = $this->data->qos ?? null ;
        $prev = $device->qos ?? null ;
        if($edit == $prev){ return 0 ;}
        return $edit ? 1 : -1 ;
    }

    private function trim_prefix(): void
    {
        if(isset($this->data->pfxLength)){
            $this->data->pfxLength =
                trim(trim($this->data->pfxLength),'/');
        }
    }

    private function set_status(): void
    {
        foreach ($this->read as &$device) {
            $type = $device['type'] ?? 'mikrotik';
            $port = $device['port'] ?? $this->dp($type);
            try{
                $conn = @fsockopen($device['ip'],
                $port,
                $code, $err, 3);
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

    private function set_disabled(){
        $map = [];
        foreach ($this->read as $item) {
            $item['disabled'] = false ;
            $map[$item['id']] = $item ;
        }
        $conf = $this->db()->readConfig()->disabled_routers ?? null;
        if($conf) foreach (explode(',',$conf) as $id){$map[$id]['disabled'] = true ;}
        $this->read = array_values($map);
    }

    private function dp($type): int
    {
        $ports = array(
            'mikrotik' => 8728,
            'cisco' => 22,
            'radius' => 3301,
            'edgeos' => 443,
        );
        return $ports[$type];
    }

    private function set_users(): void
    {
        $db = mySqlite();
        foreach ($this->read as &$device) {
            $device['users'] = $db->countServicesByDeviceId($device['id']);
        }
    }

}
