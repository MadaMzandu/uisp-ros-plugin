<?php

class ApiSetup
{
    public function run(){
        if($this->needs_db()){
            $this->db_create();
        }
        if($this->needs_update()){
            $this->db_update();
        }
        if($this->needs_backup()){
            $this->db_backup();
        }
        if($this->needs_cleanup()){
            $this->file_cleanup();
        }
        $this->cache_setup();
    }

    private function db_update(): bool
    {
        if(!$this->db_backup()){
            $this->throwErr('setup: failed to backup - not updating');
        }
        $source = 'includes/update_schema.sql';
        $schema = file_get_contents($source);
        if($this->db()->exec($schema)){
            if($this->config_load()){
                return $this->set_version();
            }
        }
        return false;
    }

    private function db_create(): bool
    {
        $source = 'includes/schema.sql';
        $schema = file_get_contents($source);
        if($this->db()->exec($schema)){
            if($this->config_load()){
                return $this->set_version();
            }
        }
        return false ;
    }

    private function config_load(): bool
    {
        $file = file_get_contents('includes/conf_default.json');
        $default = json_decode($file);
        return $this->db()->saveConfig($default);
    }

    private  function set_version(): bool
    {
        $state = $this->state() ;
        $state->version = MY_VERSION ;
        $this->save($state);
        $sql = sprintf('UPDATE config SET "value"="%s" WHERE "key"="%s"',
            MY_VERSION,'version');
        return $this->db()->exec($sql);
    }

    private function db_backup(): bool
    {
        $state = $this->state();
        if(++$state->backups > MAX_BACKUPS){
            $state->backups = 0 ;
        }
        $file = 'data/data.db';
        $backup = sprintf('data/backup-%s',$state->backups);
        $copy = copy($file,$backup);
        $state->backup = $this->now();
        $this->save($state);
        return $copy ;
    }

    private function file_cleanup():void
    {
        $state = $this->state() ;
        $files = 'queue.json,plugin.log';
        foreach(explode(',',$files) as $file){
            file_put_contents('data/'.$file,'');
        }
        $state->cleanup = $this->now();
        $this->save($state);
    }

    private function needs_cleanup(): bool
    {
        $date = $this->state()->cleanup ?? '2020-01-01';
        $last = new DateTime($date);
        $interval = DateInterval::createFromDateString('30 day');
        $next = $last->add($interval);
        $now = new DateTime();
        return $now > $next ;
    }

    private function needs_backup(): bool
    {
        $date = $this->state()->backup ?? '2020-01-01';
        $last = new DateTime($date);
        $interval = DateInterval::createFromDateString('1 day');
        $next = $last->add($interval);
        $now = new DateTime();
        return $now > $next ;
    }

    private function needs_update(): bool
    {
        $running = $this->state()->version ?? '1.0.0';
        return $running < MY_VERSION ;
    }

    private function needs_db(): bool
    {
        $file = 'data/data.db';
        return !file_exists($file);
    }

    private function now(): string
    {
        return (new DateTime())->format('Y-m-d H:i:s');
    }

    public function cache_setup(): void
    {
        if(!$this->cache_needs_update()) return ;
        shell_exec('rm -f data/cache.db');
        $schema_file = 'includes/cache.sql';
        $schema = file_get_contents($schema_file);
        $this->dbCache()->exec($schema);
    }

    private function cache_needs_update(): bool
    {
        if($this->cache_needs_db()) return true;
        $last = $this->conf()->last_cache ?? null;
        if(empty($last)) return true;
        $cycle = DateInterval::createFromDateString('7 day');
        $sync = new DateTime($last);
        $now = new DateTime();
        return date_add($sync,$cycle) < $now ;
    }

    private function cache_needs_db(): bool
    {
        $file = 'data/cache.db';
        if(!file_exists($file)) return true;
        $version = $this->conf()->cache_version ?? '0.0.0';
        return $version != MyCacheVersion ;

    }

    private function conf() {return $this->db()->readConfig(); }

    private function dbCache(){ return new ApiSqlite('data/cache.db'); }

    private function state(): stdClass
    {
        $file = 'data/state.json';
        if(!file_exists($file)){
            $this->save($this->default_state());
        }
        return json_decode(file_get_contents($file));
    }

    private function save($state): void
    {
        $file = 'data/state.json';
        file_put_contents($file,json_encode($state,JSON_PRETTY_PRINT));
    }

    private function default_state(): stdClass
    {
        return json_decode(
            '{
            "version":"1.0.0",
            "backup": "2020-01-01",
            "backups":0,
            "cleanup": "2020-01-01"
            }'
        );
    }

    private function db(): ApiSqlite
    {
        return new ApiSqlite();
    }

    private function throwErr($msg): void
    {
        throw new Exception("setup: ". $msg);
    }
}