<?php
const MY_VERSION = '1.8.8.11';
const MAX_BACKUPS = 6 ;

include_once 'api_sqlite.php';
include_once 'api_logger.php';
include_once 'api_timer.php';

class ApiSetup
{
    public function run(){
        $timer = new ApiTimer('db setup: ');
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
        $timer->stop();
    }

    private function db_update(): bool
    {
        if(!$this->db_backup()){
            $this->throwErr('setup: failed to backup - not updating');
        }
        $source = 'includes/update_schema.sql';
        $schema = file_get_contents($source);
        shell_exec('rm -f data/tmp.db');
        if($this->db()->exec($schema)){
            copy('data/tmp.db','data/data.db');
            shell_exec('rm -f data/tmp.db');
            $this->set_version();
        }
        return false;
    }

    private function db_create(): void
    {
        $source = 'includes/schema.sql';
        $schema = file_get_contents($source);
        shell_exec('rm -f data/data.db');
        if($this->db()->exec($schema)){
            if($this->config_load()){
                $this->set_version();
            }
        }
    }

    private function config_load(): bool
    {
        $file = file_get_contents('includes/conf_default.json');
        $default = json_decode($file,true);
        $done = $this->db()->saveConfig($default);
        return $done ;
    }

    private  function set_version(): void
    {
        $this->db()->saveConfig(['version' => MY_VERSION]);
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
        $running = $this->db()->readConfig()->version ?? '0.0.0' ;
        return $running < MY_VERSION ;
    }

    private function needs_db(): bool
    {
        $file = 'data/data.db';
        if(!file_exists($file)) return true ;
        return !$this->db()->has_tables();
    }

    private function now(): string
    {
        return (new DateTime())->format('Y-m-d H:i:s');
    }

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

function run_setup(){ $setup = new ApiSetup(); $setup->run(); }