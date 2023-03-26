<?php
const MyCacheVersion = '1.0.0';
class ApiCache{

    public function update($json)
    {
        $item = json_decode($json);
        if(function_exists('fastcgi_finish_request')){
            fastcgi_finish_request();
        }
        set_time_limit(7200);
        $table = ($item->entity ?? '') . 's';
        $id = $item->entityId ?? 0 ;
        if(!in_array($table,['clients','services'])) return ;
        $start = microtime(true);
        $data[] = $item;
        $this->sync();
        $this->batch($table,$data);
        if($table == 'services')  $this->populate_net($id);
        $end = microtime(true);
        $duration = ($end - $start) / 60 ; //in minutes
        if($duration > 5)
        MyLog()->Append('cache: sync completed in minutes: '.$duration,6);
    }

    public function sync()
    {
        if(!$this->needs_update()) return ;
        $this->create();
        foreach(['clients','services'] as $table){
            $this->populate($table);
        }
        $this->populate_net();
    }

    private function populate_net($id = null)
    {
        if($id == 0) return ;
        $fields = 'services.id,services.address,services.prefix6,devices.id as deviceId,devices.name as device';
        $sql = sprintf("SELECT %s FROM services LEFT JOIN devices ON services.device=devices.id ",$fields);
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
        $sql = sprintf("INSERT OR REPLACE INTO net (%s) VALUES %s ",
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

    private function batch($table,$data)
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
                $keys = 'id,status,clientId,price,totalPrice,currencyCode,attributes';
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

    private function create(): void
    {
        $update = $this->needs_db();
        if(!$update) return ;
        shell_exec('rm -f data/cache.db');
        $schema_file = 'includes/cache.sql';
        $schema = file_get_contents($schema_file);
        $this->dbCache()->exec($schema);
        $state = ['cache_version' => MyCacheVersion,'last_cache' => $this->now()];
        $this->db()->saveConfig($state);
    }

    function needs_update(): bool
    {
        if($this->needs_db()) return true;
        $last = $this->conf()->last_cache ?? null;
        if(empty($last)) return true;
        $cycle = DateInterval::createFromDateString('30 day');
        $sync = new DateTime($last);
        $now = new DateTime();
        return date_add($sync,$cycle) < $now ;
    }

    private function needs_db(): int
    {
        $file = 'data/cache.db';
        if(!file_exists($file)) return -1;
        $version = $this->conf()->cache_version ?? '0.0.0';
        if($version != MyCacheVersion) return 1 ;
        return 0 ;

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
