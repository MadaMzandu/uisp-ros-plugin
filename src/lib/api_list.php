<?php
include_once 'api_update.php';
include_once 'api_ucrm.php';
require_once __DIR__ . '/api_plan_catalog.php';
class ApiList
{
    private string $mode ;
    private $result = null ;
    private $data;
    private array $serviceParams = [];

    public function exec(): void
    {
        myJobQueue()->withAdministrativeLock(function (): void { $this->dispatch(); });
    }

    private function dispatch(): void
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
            case 'sys': return $this->list_sys();
            default: return null;
        }
    }

    private function list_config()
    {
        return $this->db()->readConfig() ;
    }

    private function list_plans(): array
    {
        $plans = null;
        try { $plans = $this->find_plans(); }
        catch (Throwable $error) { MyLog()->Append('Plan refresh failed; retained previous plans', LOG_WARNING); }
        if($plans){ ApiPlanCatalog::save($plans); }
        $from_db = $this->find_db_plans();
        $res = [];
        foreach($from_db as $v){
            $id = $v['id'];
            $v['archive'] = is_array($plans) && (!isset($plans[$id]) || !empty($plans[$id]['archive']));
            $res[] = $v;
        }

        MyLog()->Append(['list_plans','items: '.sizeof($res)]);
        return $res;
    }

    public function list_msg(): array
    {
        $config = $this->db()->readConfig();
        $override_lang = $config->plugin_language ?? 'system';
        // A browser preference changes only this read; it never updates shared settings.
        $requested = $this->data->data->language ?? null;
        $allowed = ['es', 'en', 'pt', 'fr', 'de', 'it', 'af', 'sw'];
        if (is_string($requested) && in_array($requested, $allowed, true)) {
            $override_lang = $requested;
        }
        
        if ($override_lang !== 'system' && !empty($override_lang)) {
            $lang = $override_lang;
        } else {
            $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en';
            $lang = 'en';
            $bestQuality = 0.0;
            foreach (explode(',', is_string($accept) ? $accept : '') as $candidate) {
                if (!preg_match('/^([a-z]{2})(?:-[a-z0-9]{1,8})*(?:\\s*;\\s*q=(0(?:\\.\\d{1,3})?|1(?:\\.0{1,3})?))?$/iD', trim($candidate), $match)) continue;
                $primary = strtolower($match[1]);
                $quality = isset($match[2]) ? (float) $match[2] : 1.0;
                if (in_array($primary, $allowed, true) && $quality > $bestQuality) {
                    $lang = $primary;
                    $bestQuality = $quality;
                }
            }
        }
        $lang = is_string($lang) ? strtolower($lang) : 'en';
        if (!in_array($lang, $allowed, true)) $lang = 'en';
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
        if(!$api) fail('orphan_list_fail',[$device,$this->data]);
        $raw = $api->get_orphans($device);
        $mapped = [];
        foreach ($raw as $item) {
            $mapped[] = [
                'name' => $item['name'] ?? $item['comment'] ?? null,
                'mac' => $item['mac-address'] ?? null,
                'address' => $item['address'] ?? $item['target'] ?? $item['remote-address'] ?? null,
                'path' => $item['path'] ?? null
            ];
        }
        return $mapped;
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
            unset($list[count($list) - 1]['password']);
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
        $data['count'] = $db->singleQuery($this->svc_sql(true), false, $this->serviceParams) ?? 0;
        $rows = $db->selectCustom($this->svc_sql(), $this->serviceParams);
        foreach ($rows as $r) {
            $r['address'] ??= $r['a4'] ?? null;
            $r['address6'] ??= $r['a6'] ?? null;
            $r['username'] = $r['username'] ?? $r['mac'];
            $r['client'] = $r['company'] ?? $r['firstName'] . ' ' . $r['lastName'];
            $r['pricef'] = sprintf('%01.2f',$r['price']);
            $data['data'][] = $r ;
            unset($data['data'][count($data['data']) - 1]['password']);
        }
        $db->close();
        MyLog()->Append(['list_services','items: '. sizeof($data['data'])]);
        return $data ;

    }

    private function list_jobs(): array
    {
        require_once __DIR__ . '/api_job_queue.php';
        return myJobQueue()->list();
    }

    private function list_backups(): array
    {
        $files = [];
        $dir = 'data';
        if(is_dir($dir)){
            $re = '#^backup-[0-6]$#D';
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
                    $code, $err, 2);
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
        $r = $this->db()->selectAllFromTable('devices') ?? [];
        $tmp = [];
        foreach ($r as $item) { $tmp[$item['id']] = $item ; }
        return $tmp;
    }



    private function find_db_plans(): array
    {
        require_once __DIR__ . '/data.php';
        $rows = $this->db()->selectCustom('SELECT id,name,last,created,ratio,uploadSpeed,downloadSpeed,' .
            'uploadOverride,downloadOverride,priorityUpload,priorityDownload,limitUpload,limitDownload,' .
            'burstUpload,burstDownload,threshUpload,threshDownload,timeUpload,timeDownload FROM plans') ?? [];
        $defaults = ['ratio'=>1, 'priorityUpload'=>8, 'priorityDownload'=>8, 'limitUpload'=>0,
            'limitDownload'=>0, 'burstUpload'=>0, 'burstDownload'=>0, 'threshUpload'=>0,
            'threshDownload'=>0, 'timeUpload'=>1, 'timeDownload'=>1];
        foreach ($rows as &$row) {
            $row = Data::normalizePlanNumbers($row);
            $row['numericIssues'] = Data::planNumericIssues($row);
            $invalid = array_fill_keys(array_column($row['numericIssues'], 'field'), true);
            // Diagnose the original value before JSON sanitization can disguise infinity as zero.
            // These replacements affect the response only; reviewing a plan never repairs storage.
            foreach ($invalid as $field => $ignored) if (array_key_exists($field, $row)) $row[$field] = null;
            foreach ($defaults as $field => $value) {
                if (!isset($invalid[$field]) && $row[$field] === null) $row[$field] = $value;
            }
            foreach (['Upload', 'Download'] as $direction) {
                $prefix = lcfirst($direction);
                $source = $prefix . 'Override';
                if (!isset($invalid[$source]) && $row[$source] === null) $source = $prefix . 'Speed';
                $row['effective' . $direction . 'Speed'] = isset($invalid[$source]) ? null : $row[$source];
            }
        }
        unset($row);
        return $rows;
    }

    private function find_plans(): array
    {
        $data = $this->ucrm()->get('service-plans',['servicePlanType' => 'internet']);
        if (!is_array($data)) throw new RuntimeException('invalid_service_plan_collection');
        $tmp = [];
        foreach ($data as $item) {
            $plan = ApiPlanCatalog::normalize($item);
            $plan['archive'] = ($item['archived'] ?? $item['isArchived'] ?? false) === true;
            $tmp[$plan['id']] = $plan;
        }
        // The standard collection can omit archived plans still attached to services.
        // Fetch their exact IDs; do not guess a server-specific archive filter.
        $needed = [];
        foreach ([$this->db(), myCache()] as $database) {
            foreach ($database->selectCustom('SELECT DISTINCT planId FROM services WHERE planId>0') ?? [] as $row)
                $needed[(int) $row['planId']] = true;
        }
        foreach ($this->db()->selectAllFromTable('plans') ?? [] as $row)
            if ((int) $row['id'] > 0 && ApiPlanCatalog::needsHydration($row)) $needed[(int) $row['id']] = true;
        foreach (array_keys($needed) as $id) {
            if (isset($tmp[$id])) continue;
            $tmp[$id] = ApiPlanCatalog::fetch($id) + ['archive' => true];
        }
        // Publication happens only after every required response was validated.
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
        $fields = "cs.*,s.id,s.device,s.planId,s.status,cs.status AS desiredStatus,".
            "n.address,n.address6,c.company,c.firstName,c.lastName,p.name AS plan,".
            "cn.address AS a4,cn.address6 AS a6";
        if($count){ $fields = "count(s.id)"; }
        $did = $this->data->data->did ?? $this->data->data->id ?? $this->data->data->device ?? 0;
        $did = filter_var($did, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($did === false) throw new InvalidArgumentException('invalid_device_id');
        $this->serviceParams = [$did];
        $sql = "SELECT $fields FROM main.services s ".
            "LEFT JOIN cache.clients c ON s.clientId=c.id ".
            "LEFT JOIN main.network n ON s.id=n.id ".
            "LEFT JOIN cache.network cn ON s.id=cn.id ".
            "LEFT JOIN main.plans p ON s.planId=p.id ".
            "LEFT JOIN cache.services cs ON s.id=cs.id ".
            "WHERE s.device = ? AND s.status NOT IN (2,5,8) ";
        $query = $this->data->data->query ?? null ;
        if($query){
            if (!is_string($query) && !is_int($query)) throw new InvalidArgumentException('invalid_search');
            if(ctype_digit((string) $query)){
                $sql .= "AND (s.id=? OR s.clientId=?) ";
                $this->serviceParams[] = (string) $query;
                $this->serviceParams[] = (string) $query;
            }
            else{
                $sql .= "AND (c.firstName LIKE ? OR c.lastName LIKE ? OR c.company LIKE ? OR cs.username LIKE ? OR cs.mac LIKE ?) ";
                foreach (range(1,5) as $ignored) $this->serviceParams[] = '%' . $query . '%';
            }
        }
        $limit = $this->data->limit ?? 100 ;
        $offset = $this->data->offset ?? 0 ;
        if (!$count) {
            $limit = filter_var($limit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
            $offset = filter_var($offset, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($limit === false || $offset === false) throw new InvalidArgumentException('invalid_pagination');
            $sql .= 'ORDER BY s.id LIMIT ? OFFSET ?';
            $this->serviceParams[] = $limit;
            $this->serviceParams[] = $offset;
        }
        return $sql;
    }

    private function dbx2(): ApiSqlite
    {
        $fn = 'data/data.db';
        $fn2 = 'data/cache.db';
        $db = new ApiSqlite($fn);
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

    private function list_sys(): array
    {
        $config = $this->db()->readConfig();
        $version = defined('MY_VERSION') ? MY_VERSION : 'unknown';
        if ($version === 'unknown' && defined('MyCacheVersion')) {
            $version = MyCacheVersion;
        }
        return [
            'app_version' => $version,
            'php_version' => phpversion(),
            'last_cache' => $config->last_cache ?? 'never',
            'next_cache' => $config->next_cache ?? 'never',
        ];
    }
}
