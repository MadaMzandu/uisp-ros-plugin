<?php
const MyCacheVersion = '1.8.12';

include_once 'api_trim.php';
include_once 'api_ucrm.php';

class ApiCache{

    private ?object $_conf = null;
    private ?ApiSqlite $staging = null;

    public function save($request,$type = 'service')
    { //update a single service
        $timer = new ApiTimer('cache update');
        $id = $request['id'] ?? 0 ;
        $siteId = $request['unmsClientSiteId'] ?? null ;
        if(key_exists('unmsClientSiteId',$request)){
            unset($request['unmsClientSiteId']); }
        $table = $type . 's';
        $batch[] = $request ;
        $this->batch($table,$batch);
        if($table == 'services'){
            $this->batch_network($batch);
            $this->save_site($id,$siteId);
        }
        $timer->stop();
    }

    private function save_site($id,$siteId){

        $device = $this->get_device($siteId);
        $did = null ;
        if(is_object($device)){
            $did = $device->identification->id ?? null ; }
        $post['id'] = $siteId ;
        $post['service'] = $id ;
        $post['device'] = $did;
        $this->dbCache()->insert($post,'sites',INSERT_REPLACE);
    }

    public function sync($force = false)
    {
        if(!$this->attrs()->check_config()){ return ; }
        $timer = new ApiTimer('sync: ');
        $next = date_create('+7 day');
        $types = ['clients','services'];
        $token = $this->find_unms_key();
        if(is_string($token) && strlen(trim($token)) >= 36){ //if token is set
            $types[] = 'sites';
        }
        if($force || $this->needs_update()){
            $this->refresh($types);
            $this->db()->saveConfig(['last_cache' => date('c'), 'next_cache' => $next->format('c'), 'next_sites' => $next->format('c')]);
            $timer->stop();
        }
        elseif($this->needs_sites()){
            $this->refresh(['sites']);
            $this->db()->saveConfig(['next_sites' => $next->format('c')]);
        }
    }

    public function setup(): void
    {
        $timer = new ApiTimer('cache setup: ');
        if($this->needs_db()){
            $schema_file = 'includes/cache.sql';
            $schema = file_get_contents($schema_file);
            if($this->dbCache()->exec($schema)){//reset cache time
                // CREATE TABLE IF NOT EXISTS cannot add fields to an existing
                // cache. Preserve every old row and leave its archive status
                // unknown until a real CRM value arrives.
                $this->dbCache()->transaction(function () {
                    if (!$this->has_archive_column()) $this->dbCache()->exec('ALTER TABLE "clients" ADD COLUMN "isArchived" INTEGER');
                });
                $state = ['cache_version' => MyCacheVersion,
                    'last_cache' => '2020-01-01','last_net' => '2020-01-01'];
                $this->db()->saveConfig($state);
                $this->_conf = null;
                $timer->stop();
            }
        }
    }

    public function populate($table)
    {
        $data = ['starter'];
        $limit = 50 ;
        $offset = 0 ;
        while($data){
            switch ($table){
                case 'clients': $data = $this->get_clients($offset,$limit); break;
                case 'services': $data = $this->get_services($offset,$limit); break;
                case 'sites': $data = $this->get_sites(); break ;
                default: $data = null ;
            }
            if(empty($data)) continue ;
            $request = [];
            foreach($data as $item){
                $trim = $this->trimmer()->trim($table,$item)['entity'] ?? null;
                if(!$trim){ continue ; }
                $request[] = $trim ;
            }
            $this->batch($table,$request);
            if(in_array($table,['site','sites'])){ break; }
            if(in_array($table,['service','services'])){
                $this->batch_network($request);
            }
            $offset += $limit ;
        }
    }

    public function get_sites(): array
    {
        $opts = ['type' => 'endpoint'];
        $sites = $this->get_data('sites',$opts,true);
        $devices = $this->get_data('devices',[],true);
        $site_map = [];
        foreach ($sites as $site){ $site_map[$site->id] = $site; }
        foreach($devices as $device){
            $site_id = $device->identification->site->id ?? null ;
            $name = $device->identification->name ?? null ;
            if($site_id && str_starts_with($name, "RosP_")){
                $site = $site_map[$site_id] ?? null ;
                if(is_object($site)){
                    $site->device = $device->identification->id ?? null ;
                    $site_map[$site_id] = $site ;
                }
            }
        }
        return $site_map ;
    }

    private function get_device($siteId): ?object
    {
        $devices = $this->ucrm(true)->get('devices',['siteId' => $siteId]);
        if(!is_array($devices)){ return null ;}
        foreach ($devices as $device){
            $name = $device->identification->name ?? null ;
            if(str_starts_with($name, "RosP_")){ return $device; }
        }
        return null ;
    }

    private function get_clients($offset,$limit = 500): array
    {
        $opts = ['offset' => $offset, 'limit' => $limit];
        return $this->get_data('clients',$opts) ;
    }

    private function get_services($offset,$limit = 500): array
    {
        $opts = ['offset' => $offset,'limit' => $limit,'statuses' => [0,1,3,4,6,7]];
        return $this->get_data('clients/services',$opts);
    }

    private function get_data($path,$opts,$unms = false): array
    {
        $result = $this->ucrm($unms)->get($path,$opts);
        if (!is_array($result)) throw new RuntimeException('UISP returned an invalid collection: ' . $path);
        return $result;
    }

    public function batch($table, $request)
    {
        $values = [];
        foreach ($request as $item){
            $values[] = array_diff_key($item,['network' => 1,'unmsClientSiteId' => 1]);
        }
        if($values){
            // Client webhooks may omit lifecycle facts. Update supplied fields
            // without clearing an archive flag learned from an earlier event.
            if ($table === 'clients') $this->dbCache()->edit($values, $table);
            else $this->dbCache()->insert($values,$table,INSERT_REPLACE);
        }
    }

    private function batch_network($request)
    {
        $deleted = [];
        $values = [];
        foreach($request as $item){
            $net = $item['network'] ?? null;
            if(!is_array($net) || !$net){ continue; }
            // A supplied NULL is an authoritative clear, while an omitted
            // family (for example an unconfigured IPv6 attribute) stays intact.
            $existing = $this->dbCache()->singleQuery('SELECT address,address6 FROM network WHERE id=?',true,[$item['id']]) ?: [];
            $net = array_replace($existing,array_intersect_key($net,array_fill_keys(['address','address6'],true)));
            $net['id'] = $item['id'];
            if(in_array((int)($item['status'] ?? 1),[2,5,8],true)) { $deleted[] = $item['id']; }
            else {
                $values[] = $net ;
            }
        }
        if(!empty($deleted)){
            $sql = sprintf("delete from network where id in (%s)",
                implode(',',$deleted));
            $this->dbCache()->exec($sql); //clear inactive addresses
        }
        if(!empty($values)){
            $this->dbCache()->insert($values,'network',INSERT_REPLACE);
        }
    }

    private function check_devices(): bool
    {
        $devices = $this->db()->selectAllFromTable('devices');
        if(empty($devices)) {
            MyLog()->Append('cache_devices_unset');
            return false ;
        }
        return true ;
    }


    private function needs_update(): bool
    {
        $next = $this->conf()->next_cache ?? '2020-01-01';
        $now = date('c');
        return $now > $next;
    }

    private function needs_sites(): bool
    {
        $next = $this->conf()->next_sites ?? '2023-01-01';
        $now = date('c');
        $token = $this->find_unms_key() ;
        return $now > $next && is_string($token) && strlen(trim($token)) >= 36;
    }

    private function find_unms_key()
    {
        if(!is_file('data/config.json')){ return null; }
        $read = json_decode(file_get_contents('data/config.json'));
        if(!is_object($read)){ return null; }
        return $read->unmsToken ?? null ;
    }

    private function clean()
    {
        $tables = ['services','sites','clients','network'];
        foreach($tables as $table){
            $this->dbCache()->exec("delete from $table");
        }
    }

    private function refresh(array $tables): void
    {
        $cache = myCache();
        $generation = $cache->singleQuery('PRAGMA data_version');
        $localChanges = $cache->singleQuery('SELECT total_changes()');
        // Download into a separate database; failed pages never replace the last valid cache.
        $staging = new ApiSqlite(':memory:');
        $staging->exec(file_get_contents('includes/cache.sql'));
        $this->staging = $staging;
        try {
            foreach ($tables as $table) $this->populate($table);
            $publish = $tables;
            if (in_array('services', $tables, true)) $publish[] = 'network';
            $cache->transaction(function () use ($publish, $staging, $cache, $generation, $localChanges) {
                if ($cache->singleQuery('PRAGMA data_version') !== $generation || $cache->singleQuery('SELECT total_changes()') !== $localChanges) {
                    throw new RuntimeException('cache_changed_during_refresh_retry_later');
                }
                foreach ($publish as $table) {
                    $cache->deleteAll($table);
                    $rows = $staging->selectAllFromTable($table);
                    if ($rows) $cache->insert($rows, $table, INSERT_REPLACE);
                }
            });
        } finally {
            $this->staging = null;
            $staging->close();
        }
    }

    private function needs_db(): bool
    {
        $file = 'data/cache.db';
        if(!is_file($file)) return true;
        if(!$this->dbCache()->has_tables(['clients','services','network','sites'])){
            return true ;}
        if (!$this->has_archive_column()) return true;
        $version = $this->conf()->cache_version ?? '0.0.0';
        return $version != MyCacheVersion ;
    }

    private function has_archive_column(): bool
    {
        foreach ($this->dbCache()->selectCustom('PRAGMA table_info("clients")') ?? [] as $column) {
            if (($column['name'] ?? null) === 'isArchived') return true;
        }
        return false;
    }

    private function attrs(): ApiAttributes { return myAttr(); }

    private function trimmer(): ApiTrim
    {
        return myTrimmer() ;
    }

    private function ucrm($unms = false): ApiUcrm { return new ApiUcrm(null,false,$unms); }

    private function db(): ApiSqlite { return mySqlite(); }

    private function conf(): ?object
    {
        if(empty($this->_conf)){
            $this->_conf = $this->db()->readConfig();
        }
        return $this->_conf;
    }

    private function dbCache(): ApiSqlite
    {
        return $this->staging ?? myCache();
    }

   }

function cache_sync() { return myJobQueue()->withAdministrativeLock(function () { $api = new ApiCache(); $api->sync(); }); }

function cache_setup(){ return myJobQueue()->withAdministrativeLock(function () { $cache = new ApiCache(); $cache->setup(); }); }
