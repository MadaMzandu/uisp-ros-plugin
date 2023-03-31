<?php
const MyCacheVersion = '1.0.1a';

include_once 'api_sqlite.php';
include_once 'api_ucrm.php';
//include_once '_web_ucrm.php'; //for devel only
include_once 'api_logger.php';
include_once 'api_timer.php';

class ApiCache{

    private $ref ;
    private $dev;

    public function update($json)
    {
        $item = json_decode($json);
        if(function_exists('fastcgi_finish_request')){
            MyLog()->Append('ending tcp here to go background');
            fastcgi_finish_request();
        }
        set_time_limit(7200);
        $table = ($item->entity ?? '') . 's';
        $id = $item->entityId ?? 0 ;
        $start = microtime(true);
        $data[] = $item;
        $this->sync();
        if(!in_array($table,['clients','services'])) return ;
        $this->batch($table,$data);
        if($table == 'services')  $this->net_update($id);
        $end = microtime(true);
        $duration = ($end - $start) / 60 ; //in seconds
        if($duration > 5)
        MyLog()->Append('cache: sync completed in seconds: '.$duration,6);
    }

    public function sync()
    {
        if($this->needs_update()){
            $this->set_attributes();
            $timer = new ApiTimer('sync: ');
            $this->get_devices();
            MyLog()->Append('populating services and clients');
            foreach(['clients','services'] as $table){
                $this->populate($table);
                MyLog()->Append('finished populating: '.$table);
            }
            MyLog()->Append('populating network');
            $this->net_update();
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

    public function net_update()
    {
       if($this->needs_net()){
           $db = new SQLite3('data/data.db');
           $db->enableExceptions(true);
           $db->exec(sprintf("ATTACH '%s/data/cache.db'",__DIR__));
           $sql = "INSERT OR REPLACE INTO cache.network (id,address,prefix6) ".
               "SELECT id,address,prefix6 from services ";
           if($db->exec($sql)){
               $this->db()->saveConfig(['last_net' => $this->now()]);
           }
       }
    }

    private function populate($table)
    {
        $data = ['starter'];
        $opts = $this->opts();
        $path = $this->path($table);
        while($data){
            $data = $this->ucrm()->get($path,$opts);
            if(empty($data)) continue ;
            $this->batch($table,$data);
            $opts['offset'] += 500 ;
        }
    }

    public function batch($table,$data)
    {
        MyLog()->Append('starting batch prepare');
        $fields = $this->fields($table);
        $keys = array_values($fields);
        $sql = sprintf('INSERT OR REPLACE INTO %s (%s) VALUES ',$table,implode(',',$keys));
        $query = [];
        foreach ($data as $item){
            $entity = $item->extraData->entity ?? $item ;
            $values = [];
            foreach(array_keys($fields) as $key){
                $values[] = $entity->$key ?? null ;
            }
            if($table == 'services'){
                $attributes = $entity->attributes ?? [];
                $extract = $this->extract_attributes($attributes);
                $attr_size = sizeof($extract);
                $offset = sizeof($keys) - $attr_size - 1;
                array_splice($values,$offset,$attr_size,$extract);
            }
            if(sizeof($values) != sizeof($keys)){
                continue;
            } ;
            $query[] = $this->toSqlValues($values);
        }
        $sql .= implode(',',$query);
        $this->dbCache()->exec($sql);
        MyLog()->Append('batch exectued '.$sql);
    }

    private function extract_attributes($array): ?array
    {//sanitize attributes
        $roskeys = [
            'device_name_attr',
            'pppoe_user_attr',
            'pppoe_pass_attr',
            'mac_addr_attr',
            'hs_attr',
            'pppoe_caller_attr',
        ];
        if(empty($array)) return array_fill(0,sizeof($roskeys),null);
        if(!$this->ref) $this->set_attributes();
        if(!$this->dev) $this->get_devices();
        $map = [];
        $values = [];
        foreach ($array as $item){ $map[$item->key] = $item->value; }
        foreach ($roskeys as $ros){
            $match = $this->ref[$ros] ?? null ; //attribute is configured
            if($match && $ros == 'device_name_attr'){ //device name to device id
                $values[] = $this->dev[strtolower($map[$match])] ?? null;
            }
            else if($match) $values[]  = $map[$match] ?? null ;
            else $values[] = null ;
        }
        return $values ;
    }

    private function fields($table): array
    {
        switch ($table){
            case 'clients':{
                $keys = 'id,company,firstName,lastName';
                $map = [];
                foreach (explode(',',$keys) as $key) $map[$key] = $key ;
                return $map ;
            }
            case 'services':{
                $map = [];
                $keys = 'id,status,clientId,price,totalPrice,currencyCode,device,username,password,mac,hotspot,callerId';
                foreach (explode(',',$keys) as $key) $map[$key] = $key ;
                $map['servicePlanId'] = 'planId';
                return $map ;
            }
        }
        return [];
    }

    private function path($table): ?string
    {
        switch ($table){
            case 'clients': return 'clients';
            case 'services': return 'clients/services';
        }
        return null ;
    }

    private function toSqlValues($array): string
    {
        $values = [];
        foreach ($array as $item){
            if(empty($item)) $values[] = 'null';
            elseif (is_array($item) || is_object($item)) $values[] = sprintf("'%s'",json_encode($item));
            elseif(is_numeric($item)) $values[] = $item ;
            else $values[] = sprintf("'%s'",$item);
        }
        return sprintf("(%s)",implode(',',$values));
    }

    private function make_nulls(int $qty): array
    {
        $nulls = [];
        for($i = 0; $i < $qty; $i++) $nulls[] = null ;
        return $nulls;
    }

    private function set_attributes(): void
    {
        $attributes = $this->map_attributes();
        MyLog()->Append('checking for attributes');
        $device = $attributes['device_name_attr'] ?? null;
        $mac = $attributes['mac_addr_attr'] ?? null ;
        $user = $attributes['pppoe_user_attr'] ?? null;
        MyLog()->Append('attributes found: '. json_encode([$device,$mac,$user]));
        $missing = !($device && ($mac || $user));
        if($missing) {
            $this->throwErr('cache: attributes not configured yet will sync later');
        }
        MyLog()->Append('attributes found: '. json_encode([$device,$mac,$user]));
        foreach (array_keys($attributes) as $key){
            $this->ref[$key] = $attributes[$key]->key ?? 'notset' ;
        }
    }

    private function get_devices()
    {
        $devs = $this->db()->selectAllFromTable('devices');
        if(empty($devs)) {
            $this->throwErr('cache: devices not configured sync delayed');
        }
        $map = [];
        foreach ($devs as $dev){
            $map[trim(strtolower($dev['name']))] = $dev['id'];
        }
        $this->dev = $map ;
    }

    private function map_attributes(): array
    {
        $attrs = $this->ucrm()->get('custom-attributes');
        $keymap = [];
        foreach ($attrs as $attr){ $keymap[$attr->key] = $attr; }
        $conf = $this->conf();
        $roskeys = 'device_name_attr,pppoe_user_attr,pppoe_pass_attr,mac_addr_attr,hs_attr,pppoe_caller_attr';
        $rosmap = [];
        foreach(explode(',',$roskeys) as $roskey){
            $match = $conf->$roskey ;
            if($match){
                $rosmap[$roskey] = $keymap[$match] ?? null;
            }
        }
        return $rosmap;
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

    private function opts(): array
    {
        $json = '{"limit":500,"offset":0}';
        return json_decode($json,true);
    }

    private function ucrm(){ return new ApiUcrm(); }

    private function db(){ return new ApiSqlite(); }

    private function now() { return (new DateTime())->format('Y-m-d H:i:s'); }

    private function conf() {return $this->db()->readConfig(); }

    private function dbCache(){ return new ApiSqlite('data/cache.db'); }

    private function throwErr($exception){ throw new Exception($exception); }

   }

function cache_sync($json) { $api = new ApiCache(); $api->update($json);}

function cache_setup(){ $cache = new ApiCache(); $cache->setup();}

function net_update(){ $cache = new ApiCache(); $cache->net_update();}
