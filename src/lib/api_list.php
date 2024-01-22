<?php
include_once 'api_update.php';
include_once 'api_ucrm.php';
class ApiList
{
    private string $mode ;
    private null|object|array $result = null ;
    private null|object|array $data;

    public function update()
    {
        $api = new ApiUpdate($this->data,$this->mode);
        $api->exec();
        $this->result = $api->result() ;
    }

    public function list(): null|array|object
    {
        $this->result = match ($this->mode){
            'plans' => $this->list_plans(),
            'devices' => $this->list_devices(),
            'services' => $this->list_services(),
            'config' => $this->list_config(),
            'jobs' => $this->read_jobs(),
            default => null
        };
        return $this->result ;
    }

    private function list_config(): null|array|object
    {
        return $this->db()->readConfig() ;
    }

    private function list_plans(): array
    {
        $str = '{"ratio":1,"uploadSpeed":0,"downloadSpeed":0,'.
            '"priorityUpload":8,"priorityDownload":8,"limitUpload":0,"limitDownload":0,'.
            '"burstUpload":0,"burstDownload":0,"threshUpload":0,"threshDownload":0,'.
            '"timeUpload":1,"timeDownload":1}';
        $this->db()->deleteAll('plans');
        $plans = $this->find_plans();
        $from_db = $this->find_db_plans();
        $defaults = json_decode($str,true);
        foreach($plans as $plan){
            $saved = $from_db[$plan['id']] ?? [] ;
            $now = date('c');
            if(!$saved) {
                $plan['created'] = $now ;
                $plan = array_replace($defaults,$plan);
            }
            $update = array_replace($saved,$plan);
            $update['last'] = $now ;
            $from_db[$plan['id']] = $update;
            $trim = array_diff_key($update,['archive' => null]);
            $this->db()->insert($trim,'plans',true);
        }
        MyLog()->Append(['list_plans','items: '.sizeof($from_db)]);
        return $from_db ;
    }

    private function list_devices(): array
    {
        $devices = $this->find_devices() ;
        $count = $this->count_device_users() ;
        $status = $this->check_status($devices) ;
        $disabled = $this->find_disabled();
        $list = [];
        foreach($devices as $device){
            $device['users'] = $count[$device['id']] ?? 0;
            $device['status'] = $status[$device['id']] ?? false;
            $device['disabled'] = $disabled[$device['id']] ?? false ;
            MyLog()->Append(["DEVICE",$device]);
            $list[] = $device ;
        }
        return $list ;
    }

    private function find_disabled(): array
    {
        $conf = $this->db()->readConfig()->disabled_routers ?? null;
        $disabled = [];
        if($conf) foreach (explode(',',$conf) as $id){
            $disabled[$id] = true; }
        return $disabled ;
    }

    private function check_status($devices): array
    {
        $ports = ['mikrotik' => 8291,'edgos' => 443,
            'cisco' => 22,'radius' => 3301];
        $status = [];
        foreach ($devices as $device) {
            $id = $device['id'];
            $type = $device['type'] ?? 'mikrotik';
            $port = $device['port'] ?? $ports[$type];
            try{
                $conn = @fsockopen($device['ip'],
                    $port,
                    $code, $err, 3);
                $state = is_resource($conn);
                $status[$id] = $state ;
                if($state){ fclose($conn); }
            }
            catch (\Exception $err){
                $status[$id] = false ;
            }
        }
        return $status ;
    }

    private function count_device_users(): array
    {
        $q = 'select device,count(id) as count from services '.
            'where status not in (2,5,8) group by device';
        $r = $this->db()->selectCustom($q) ;
        $count = [];
        foreach($r as $item){ $count[$item['device']] = $item['count']; }
        return $count ;
    }

    private function list_services(): array
    {
        $db = $this->dbx2();
        $data = ['data' => []];
        $data['count'] = $db->querySingle($this->svc_sql(true)) ?? 0;
        $f = $db->query($this->svc_sql());
        while($r = $f->fetchArray(SQLITE3_ASSOC)){
            $r['address'] ??= $r['a4'] ?? null;
            $r['address6'] ??= $r['a6'] ?? null;
            $data['data'][] = $r ;
        }
        MyLog()->Append(['list_services','items: '. sizeof($data['data'])]);
        return $data ;

    }

    private function read_jobs(): array
    {
        $r = null ;
        if(is_file($this->fn)){
            $r = json_decode(file_get_contents($this->fn),true);
        }
        return is_array($r) ? $r : [];
    }

    private function find_devices(): array
    {
        $r = $this->db()->selectAllFromTable('devices');
        $tmp = [];
        foreach ($r as $item) { $tmp[$item['id']] = $item ; }
        return $tmp;
    }

    private function find_db_plans(): array
    {
        $read = $this->db()->selectAllFromTable('plans');
        $tmp = [];
        foreach($read as $item){ $tmp[$item['id']] = $item; }
        return $tmp ;
    }

    private function find_plans(): array
    {
        $data = $this->ucrm()->get('service-plans',['servicePlanType' => 'internet']);
        $tmp = [];
        $trimmer = array_fill_keys(['id','uploadSpeed','downloadSpeed','name'],'$#@&');
        foreach ($data as $item) {
            $trim = array_intersect_key($item,$trimmer);
            $trim['archive'] = false ;
            $tmp[$item['id']] = $trim;
        }
        return $tmp ;
    }

    private function set_mode($mode)
    {
        if(preg_match("#(service|device|plan)#",$mode)){ //append ending "s"
            $mode = preg_replace("#s\s*$#",'',$mode) . 's';
        }
        $this->mode = $mode ;
    }

    private function svc_sql($count = false): string
    {
        $fields = "services.*,services.username,services.mac,services.price,".
            "main.network.address,main.network.address6,clients.company,clients.firstName,".
            "clients.lastName,plans.name as plan,cache.network.address as a4,".
            "cache.network.address6 as a6";
        if($count){ $fields = "count(main.services.id)"; }
        $did = $this->data->data->did ?? $this->data->data->id ?? $this->data->data->device ?? 0;
        $sql = "SELECT $fields FROM main.services ".
            "LEFT JOIN cache.clients ON main.services.clientId=clients.id ".
            "LEFT JOIN main.network ON main.services.id=main.network.id ".
            "LEFT JOIN cache.network on main.services.id=cache.network.id ".
            "LEFT JOIN main.plans on main.services.planId=plans.id ".
            "LEFT JOIN cache.services ON main.services.id=cache.services.id ".
            "WHERE main.services.device = $did AND main.services.status NOT IN (2,5,8) ";
        $query = $this->data->data->query ?? null ;
        if($query){
            if(is_numeric($query)){
                $sql .= "AND (main.services.id=$query OR main.services.clientId=$query) ";
            }
            else{
                $sql .= "AND (clients.firstName LIKE '%$query%' OR clients.lastName LIKE '%$query%' ".
                    "OR clients.company LIKE '%$query%' OR services.username LIKE '%$query%' OR ".
                    "services.mac LIKE '%$query%') ";
            }
        }
        $limit = $this->data->limit ?? 100 ;
        $offset = $this->data->offset ?? 0 ;
        if(!$count) { $sql .=  "ORDER BY main.services.id LIMIT $limit OFFSET $offset "; }
        return $sql;
    }

    private function dbx2()
    {
        $fn = 'data/data.db';
        $fn2 = 'data/cache.db';
        $db = new SQLite3($fn);
        $db->exec("ATTACH '$fn2' as cache");
        return $db ;
    }

    private function db(): ApiSqlite { return mySqlite(); }

    private function cachedb(): ApiSqlite { return myCache(); }

    public function status(): object { return new stdClass(); }

    public function result(): null|array|object { return $this->result; }

    private function ucrm($assoc = true): ApiUcrm { return new ApiUcrm(null,$assoc); }

    public function __construct($data = null,$mode = 'services')
    {
        $this->set_mode($mode);
        if(!$data){$data = new stdClass(); }
        $this->data = $data ;
    }
}
