<?php
const MyCacheVersion = '1.8.10';

include_once 'api_trim.php';
include_once 'api_ucrm.php';

class ApiCache{

    private $_trim ;
    private $_cache ;
    private $_conf ;

    public function save($request,$type = 'service')
    { //update a single service
        $timer = new ApiTimer('cache update');
        $table = $type . 's';
        $batch[] = $request ;
        $this->batch($table,$batch);
        if($table == 'services')$this->batch_network($batch);
        $timer->stop();
    }

    public function sync($force = false)
    {
        if($force || $this->needs_update()){
            if(!$this->attributes()->check_config()
                || !$this->check_devices()){
                return ;
            }
            $timer = new ApiTimer('sync: ');
            MyLog()->Append('populating services and clients');
            foreach(['clients','services'] as $table){
                $this->populate($table);
                MyLog()->Append('finished populating: '.$table);
            }
            $state = ['last_cache' => $this->now()];
            $this->db()->saveConfig($state);
            $timer->stop();
        }
    }

    public function setup(): void
    {
        $timer = new ApiTimer('cache setup: ');
        if($this->needs_db()){
            shell_exec('rm -f data/cache.db');
            $schema_file = 'includes/cache.sql';
            $schema = file_get_contents($schema_file);
            if($this->dbCache()->exec($schema)){//reset cache time
                $state = ['cache_version' => MyCacheVersion,
                    'last_cache' => '2020-01-01','last_net' => '2020-01-01'];
                $this->db()->saveConfig($state);
            }
        }
        $timer->stop();
    }

    private function populate($table)
    {
        $data = ['starter'];
        $opts = $this->opts($table);
        $path = $this->path($table);
        while($data){
            $data = $this->ucrm()->get($path,$opts);
            if(empty((array)$data)) continue ;
            $request = [];
            foreach($data as $item){
                $trim = $this->trimmer()->trim($table,$item)['entity'] ?? null;
                if(!$trim){ continue ; }
                $request[] = $trim ;
            }
            $this->batch($table,$request);
            if($table == 'services')$this->batch_network($request);
            $opts['offset'] += 500 ;
        }
    }

    public function batch($table, $request)
    {
        $values = [];
        foreach ($request as $item){
            $values[] = array_diff_key($item,['network' => null]);
        }
        MyLog()->Append("sending cache data to sqlite");
        $this->dbCache()->insert($values,$table,true);
    }

    private function batch_network($request)
    {
        $deleted = [];
        $values = [];
        foreach($request as $item){
            $net = $item['network'] ?? [];
            $net['id'] = $item['id'];
            if(in_array($item['status'],[2,5,8])) { $deleted[] = $item['id']; }
            else {
                $values[] = $net ;
            }
        }
        if(!empty($deleted)){
            $sql = sprintf("delete from network where id in (%s)",
                implode(',',$deleted));
            MyLog()->Append('cache: network delete sql: '.$sql);
            $this->dbCache()->exec($sql); //clear inactive addresses
        }
        if(!empty($values)){
            MyLog()->Append('sending cache network data to sqlite');
            $this->dbCache()->insert($values,'network',true);
        }
    }

    private function path($table): ?string
    {
        switch ($table){
            case 'clients': return 'clients';
            case 'services': return 'clients/services';
        }
        return null ;
    }

    private function check_devices(): bool
    {
        $devices = $this->db()->selectAllFromTable('devices');
        if(empty($devices)) {
            MyLog()->Append('devices not configured sync delayed');
            return false ;
        }
        MyLog()->Append('cache devices found: '. json_encode($devices));
        return true ;
    }

    private function needs_update(): bool
    {
        $last = $this->conf()->last_cache ?? '2020-01-01';
        $cycle = DateInterval::createFromDateString('7 day');
        $sync = new DateTime($last);
        $now = new DateTime();
        return date_add($sync,$cycle) < $now ;
    }

    private function needs_net(): bool
    {
        $last = $this->conf()->last_net ?? '2020-01-01';
        $cycle = DateInterval::createFromDateString('30 minute');
        $sync = new DateTime($last);
        $now = new DateTime();
        return date_add($sync,$cycle) < $now ;
    }

    private function needs_db(): bool
    {
        $file = 'data/cache.db';
        if(!file_exists($file)) return true;
        if(!$this->dbCache()->has_tables(['clients','services','network'])){
            return true ;}
        $version = $this->conf()->cache_version ?? '0.0.0';
        return $version != MyCacheVersion ;
    }

    private function opts($table = 'services'): array
    {
        $opts = ['limit' => 500,'offset' => 0,'statuses' => [0,1,3,4,6,7]];
        if($table == 'services') return $opts ;
        else return array_diff_key($opts,['statuses' => null]);
    }

    private function attributes(): ApiAttributes { return new ApiAttributes(); }

    private function trimmer()
    {
        if(empty($this->_trim)){
            $this->_trim = new ApiTrim();
        }
        return $this->_trim;
    }

    private function ucrm(){ return new ApiUcrm(); }

    private function db(): ApiSqlite { return mySqlite(); }

    private function now(): string { return (new DateTime())->format('c'); }

    private function conf()
    {
        if(empty($this->_conf)){
            $this->_conf = $this->db()->readConfig();
        }
        return $this->_conf;
    }

    private function dbCache(): ApiSqlite
    {
        return myCache() ;
    }

   }

function cache_sync() { $api = new ApiCache(); $api->sync();}

function cache_setup(){ $cache = new ApiCache(); $cache->setup();}
