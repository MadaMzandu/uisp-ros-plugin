<?php
//remember to update cols file and repeats when schema changes
const MY_VERSION = '1.0.3';
const MAX_BACKUPS = 6 ;
const REPEAT_STATEMENTS  = 6; //number of statements expected to fail during update

include_once 'api_sqlite.php';
include_once 'api_logger.php';
include_once 'api_timer.php';

class ApiSetup
{
    private $_db ;

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
            MyLog()->Append('setup: failed to backup - not updating');
            return false ;
        }
        MyLog()->Append('starting db update');
        $schema = $this->update_schema();
        shell_exec('rm -f data/tmp.db');
        $count = 0 ; $total = sizeof($schema) ;
        set_error_handler('dbUpdateHandler');
        foreach($schema as $stm){
            if($this->db()->exec($stm)){ $count++; }
        }
        $this->close();
        set_error_handler('myErrorHandler');
        if($count >= $total - REPEAT_STATEMENTS){
            MyLog()->Append(sprintf('update: %s of %s statements executed',$count,$total));
            copy('data/tmp.db','data/data.db');
            shell_exec('rm -f data/tmp.db');
            $this->set_version();
            $this->config_load();
            return true;
        }
        return false ;
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

    public function config_load(): bool
    {
        $file = file_get_contents('includes/conf_default.json');
        $default = json_decode($file,true);
        $conf = (array) $this->dbApi()->readConfig();
        $diff = array_diff_key($default,$conf);
        return $this->dbApi()->saveConfig($diff);
    }

    private function update_schema(): ?array
    {
        $source = 'includes/update_schema.sql';
        $str = file_get_contents($source);
        $arr = explode("\n",
            preg_replace('/;/',";\n",
                preg_replace('/\v+/',"",$str))); //convert to array
        return array_diff($arr,["",null]);
    }

    private  function set_version(): void
    {

        $this->dbApi()->saveConfig(['version' => MY_VERSION]);
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
        if($this->needs_columns()) return true ;
        $running = $this->dbApi()->readConfig()->version ?? '0.0.0' ;
        return trim($running) != MY_VERSION ;
    }

    private function needs_db(): bool
    {
        $file = 'data/data.db';
        if(!is_file($file)) return true ;
        return false ;
    }

    private function needs_columns(): bool
    { //check for missing columns
        $schema = [];
        $file = 'includes/cols.json';
        if(is_file($file)) $schema = json_decode(file_get_contents($file),true);
        foreach(array_keys($schema) as $table){
            $q = $this->db()->query(sprintf('PRAGMA table_info("%s")',$table));
            $cols = [];
            while($row = $q->fetchArray(SQLITE3_ASSOC)){ $cols[] = $row['name'];}
            if(array_diff($schema[$table],$cols)) { return true; }
        }
        return false ;
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

    private function close(): void
    {
        $this->db()->close();
        $this->_db = null ;
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

    private function db(): SQLite3
    {
        if(empty($this->_db)){
            $this->_db = new SQLite3('data/data.db');
            $this->_db->busyTimeout(5000);
        }
        return $this->_db ;
    }

    private function dbApi(): ApiSqlite
    {
        return new ApiSqlite();
    }
}

function run_setup(){ $setup = new ApiSetup(); $setup->run(); }