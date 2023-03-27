<?php
const MyCacheVersion = '1.0.1b';
class ApiCache{

    private $ref ;
    private $dev;

    public function update($json)
    {
        $item = json_decode($json);
        if(function_exists('fastcgi_finish_request')){
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
        if($table == 'services')  $this->populate_net($id);
        $end = microtime(true);
        $duration = ($end - $start) / 60 ; //in minutes
        if($duration > 5)
        MyLog()->Append('cache: sync completed in minutes: '.$duration,6);
    }

    public function sync()
    {
        if($this->needs_attributes()){ return ;}
        if(!$this->needs_update()){ return; }
        $this->get_devices();
        foreach(['clients','services'] as $table){
            $this->populate($table);
        }
        $this->populate_net();
        $state = ['cache_version' => MyCacheVersion,'last_cache' => $this->now()];
        $this->db()->saveConfig($state);
    }

    private function populate_net($id = null)
    {
        if($id == 0) return ;
        $fields = 'services.id,services.address,services.prefix6';
        $sql = sprintf("SELECT %s FROM services ",$fields);
        if($id) $sql .= "WHERE services.id = ". $id ;
        $data = $this->db()->selectCustom($sql);
        if(!$data) return ;
        $first = $data[0] ?? null;
        $keys = array_keys($first);
        $query = [];
        foreach($data as $item){
            $values = [];
            foreach ($keys as $key){
                $values[] = $item[$key];
            }
            $query[] = $this->toSqlValues($values);
        }
        $sql = sprintf("INSERT OR REPLACE INTO network (%s) VALUES %s ",
            implode(',',$keys),
            implode(',',$query),
        );
        $this->dbCache()->exec($sql);
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
                array_splice(
                    $values,6,5,
                    $this->fix_attributes($entity->attributes));
            }
            $query[] = $this->toSqlValues($values);
        }
        $sql .= implode(',',$query);
        $this->dbCache()->exec($sql);
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
                $keys = 'id,status,clientId,price,totalPrice,currencyCode,device,username,password,mac,hotspot';
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

    public function setup(): void
    {
        if(!$this->needs_update()) return ;
        shell_exec('rm -f data/cache.db');
        $schema_file = 'includes/cache.sql';
        $schema = file_get_contents($schema_file);
        $this->dbCache()->exec($schema);
    }

    private function fix_attributes($array): ?array
    {//sanitize attributes
        if(!$array) return null ;
        if(!$this->ref) $this->needs_attributes();
        if(!$this->dev) $this->get_devices();
        $map = [];
        $values = [];
        foreach ($array as $item){ $map[$item->key] = $item->value; }
        $roskeys = 'device_name_attr,pppoe_user_attr,pppoe_pass_attr,mac_addr_attr,hs_attr';
        foreach (explode(',',$roskeys) as $ros){
            $match = $this->ref[$ros] ?? null ;
            if($match && $ros == 'device_name_attr'){
                $values[] = $this->dev[strtolower($map[$match])];
            }
            else if($match) $values[]  = $map[$match] ?? null ;
            else $values[] = null ;
        }
        return $values ;
    }

    private function needs_attributes(): bool
    {
        $attributes = $this->get_attributes();
        $device = $attributes['device_name_attr'] ?? null;
        $mac = $attributes['mac_addr_attr'] ?? null ;
        $user = $attributes['pppoe_user_attr'] ?? null;
        $ret = !($device && ($mac || $user));
        if(!$ret){foreach (array_keys($attributes) as $key)
            $this->ref[$key] = $attributes[$key]->key; }
        return $ret ;
    }

    private function get_devices()
    {
        $devs = $this->db()->selectAllFromTable('devices');
        $map = [];
        foreach ($devs as $dev){
            $map[trim(strtolower($dev['name']))] = $dev['id'];
        }
        $this->dev = $map ;
    }

    private function get_attributes(): array
    {
        $data = ['path' => 'attributes'];
        $api = new AdminGet($data);
        $api->get();
        $res = $api->result();
        $map = [];
        foreach($res as $item){ $key = $item->roskey ?? null; if($key) $map[$key] = $item; }
        return $map;
    }

    private function needs_update(): bool
    {
        if($this->needs_db()) return true;
        $last = $this->conf()->last_cache ?? null;
        if(empty($last)) return true;
        $cycle = DateInterval::createFromDateString('30 day');
        $sync = new DateTime($last);
        $now = new DateTime();
        return date_add($sync,$cycle) < $now ;
    }

    private function needs_db(): bool
    {
        $file = 'data/cache.db';
        if(!file_exists($file)) return true;
        $version = $this->conf()->cache_version ?? '0.0.0';
        return $version != MyCacheVersion ;

    }

    private function opts(): array
    {
        $json = '{"limit":500,"offset":0}';
        return json_decode($json,true);
    }

    private function ucrm(){ return new ApiUcrm(); }

    private function conf(){ return $this->db()->readConfig(); }

    private function dbCache(){ return new ApiSqlite('data/cache.db'); }

    private function db(){ return new ApiSqlite(); }

    private function now() { return (new DateTime())->format('Y-m-d H:i:s'); }

   }
