<?php
include_once 'api_update.php';
include_once 'api_ucrm.php';
class ApiList
{
    private string $mode ;
    private $result = null ;
    private $data;

    public function exec(): void
    {
        $this->result = $this->list();
    }

    public function list()
    {
        switch ($this->mode){
            case 'plans':  return $this->list_plans();
            case 'devices': return $this->list_devices();
            case 'services': return $this->list_services();
            case 'config': return $this->list_config();
            case 'jobs': return $this->list_jobs();
            case 'attrs':
            case 'attributes': return $this->list_attrs();
            case 'backups': return $this->list_backups();
            case 'orphans': return $this->list_orphans();
            case 'messages':
            case 'lang': return $this->list_msg();
            default: return null;
        }
    }

    private function list_config()
    {
        return $this->db()->readConfig() ;
    }

    private function list_plans(): array
    {
        $str = '{"ratio":1,"uploadSpeed":0,"downloadSpeed":0,'.
            '"priorityUpload":8,"priorityDownload":8,"limitUpload":0,"limitDownload":0,'.
            '"burstUpload":0,"burstDownload":0,"threshUpload":0,"threshDownload":0,'.
            '"timeUpload":1,"timeDownload":1}';
        $plans = $this->find_plans();
        $from_db = $this->find_db_plans();
        $defaults = json_decode($str,true);
        $updates = [];
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
            $updates[] = $trim ;
        }
        $this->db()->insert($updates,'plans',INSERT_REPLACE);
        MyLog()->Append(['list_plans','items: '.sizeof($from_db)]);
        return array_values($from_db) ;
    }

    public function list_msg(): array
    {
        $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ; //add trailing comma
        $split = preg_split("/[;,]+/",$accept) ;
        $grep = preg_grep("/[a-zA-Z]+$/",$split);
        $first = array_shift($grep) ?? 'en-USA' ;
        $lang = preg_replace("/-.*/",'',$first);
        $data = [];
        foreach (['messages','fields'] as $name){
            $dir = 'includes/l10n/';
            $path = $dir . $name . '_' . strtolower($lang) . ".json";
            if(!is_file($path)) $path = $dir . $name . "_en.json";
            $data[] = json_decode(file_get_contents($path));
        }
        MyLog()->Append(['lang_read','lang: '.$lang,'items: '. sizeof($data)]);
        return $data ;
    }

    private function list_orphans(): array
    {
        $id = $this->data->data->id ?? 0 ;
        $device = $this->db()->selectDeviceById($id);
        $type = $device->type ?? null ;
        $api = $type == 'mikrotik' ? new MtData() : null ;
        if($api) fail('orphan_list_fail',[$device,$this->data]);
        return $api->get_orphans($device);
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
            $list[] = $device ;
        }
        MyLog()->Append(['device_list_success','items: '.sizeof($list)]);
        return $list ;
    }

    private function list_attrs(): array
    {
        $conf = $this->db()->readConfig() ;
        $conf = json_decode(json_encode($conf),true);
        $attributes = $this->ucrm()->get('custom-attributes') ?? [];
        $return = [];
        foreach ($attributes as $item){
            $ros_key = $item['key'] ?? '$%^^&';
            $native_key = array_search($ros_key,$conf,true);
            if($native_key){
                $item['roskey'] = $ros_key;
                $item['native_key'] = $native_key ;
            }
            $return[] = $item;

        }
        return $return ;
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
            $r['username'] = $r['username'] ?? $r['mac'];
            $r['client'] = $r['company'] ?? $r['firstName'] . ' ' . $r['lastName'];
            $r['pricef'] = sprintf('%01.2f',$r['price']);
            $data['data'][] = $r ;
        }
        $db->close();
        MyLog()->Append(['list_services','items: '. sizeof($data['data'])]);
        return $data ;

    }

    private function list_jobs(): array
    {
        $r = null ;
        $fn = 'data/queue.json';
        if(is_file($fn)){
            $r = json_decode(file_get_contents($fn),true);
        }
        return is_array($r) ? $r : [];
    }

    private function list_backups(): array
    {
        $files = [];
        $dir = 'data';
        if(is_dir($dir)){
            $re = '#^backup-\d+#';
            $list = preg_grep($re,scandir($dir));
            foreach ($list as $file){
                $r = [];
                $r['id'] = preg_replace("#\D+#",'',$file);
                $r['name'] = $file ;
                $r['date'] = date('c',filemtime("$dir/$file"));
                $files[] = $r ;
            }

        }
        return $files;
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
        $ports = ['mikrotik' => 8728,'edgeos' => 443,
            'cisco' => 22,'radius' => 3301];
        $status = [];
        foreach ($devices as $device) {
            $id = $device['id'];
            $type = $device['type'] ?? 'mikrotik';
            $port = $device['port'] ?? $ports[$type];
            try{
                $conn = @fsockopen($device['ip'],
                    $port,
                    $code, $err, 8);
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
        $r = $this->db()->selectCustom($q) ?? [];
        $count = [];
        foreach($r as $item){ $count[$item['device']] = $item['count']; }
        return $count ;
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
        if(preg_match("#(serv|dev|plan|job|back|attr)#",$mode)){ //append ending "s"
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

    private function dbx2(): SQLite3
    {
        $fn = 'data/data.db';
        $fn2 = 'data/cache.db';
        $db = new SQLite3($fn);
        $db->exec("ATTACH '$fn2' as cache");
        return $db ;
    }

    private function db(): ApiSqlite { return mySqlite(); }

    public function status(): object { return new stdClass(); }

    public function result() { return $this->result; }

    private function ucrm(): ApiUcrm {
        return new ApiUcrm(null, true); }

    public function __construct($data = null,$mode = 'services')
    {
        $this->set_mode($mode);
        if(!$data){$data = new stdClass(); }
        $this->data = $data ;
    }
}
