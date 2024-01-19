<?php
const MY_VERSION = '2.0.2';
const MAX_BACKUPS = 6 ;
const REPEAT_STATEMENTS  = 6; //number of statements expected to fail during update

include_once 'api_sqlite.php';
include_once 'api_logger.php';
include_once 'api_timer.php';

class ApiSetup
{
    private ?object $_db = null ;
    private ?object $_tmp = null ;

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

    private function db_update(): void
    {
        if(!$this->db_backup()){
            MyLog()->Append('setup: failed to backup - not updating');
            return ;
        }
        MyLog()->Append('starting db update');
        $schema = $this->update_schema();
        shell_exec('rm -f data/tmp.db');
        $failed = 0 ; $total = sizeof($schema) ;
        set_error_handler('dbUpdateHandler');
        foreach($schema as $stm){
            if(!$this->db()->exec($stm)){ $failed++; }
        }
        $this->close();
        set_error_handler('myErrorHandler');
        MyLog()->Append(sprintf('update: %s of %s statements rejected',$failed,$total));
        if($failed <= REPEAT_STATEMENTS){
            copy('data/tmp.db','data/data.db');
            shell_exec('rm -f data/tmp.db');
            $this->set_version();
            $this->config_load();
        }
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

    private function get_cols($t,$tmp = true): array
    {
        $q = "select name,type from pragma_table_info('$t')";
        $db = $tmp ? $this->tmp() : $this->db() ;
        $f = $db->query($q);
        $m = [];
        while($r = $f->fetchArray(SQLITE3_ASSOC)){
            $m[$r['name']] = $r['type'] ;
        }
        return $m ;
    }

    private function tmp(): SQLite3
    {//memory db for col check
        if(empty($this->_tmp))
        {
            $this->_tmp = new SQLite3(':memory:');
            $fn = 'includes/schema.sql';
            if(is_file($fn)) {
                $this->_tmp->exec(file_get_contents($fn));
            }
        }
        return $this->_tmp ;
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
        $state->backup = date('c');
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
        $state->cleanup = date('c');
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
        if($this->needs_cols()) return true ;
        $running = $this->dbApi()->readConfig()->version ?? '0.0.0' ;
        return trim($running) != MY_VERSION ;
    }

    private function needs_db(): bool
    {
        return !is_file('data/data.db');
    }

    public function needs_cols(): bool
    { //check for missing columns
        $tables = 'services,plans,network,devices,config';
        foreach(explode(',',$tables) as $table){
            $new = $this->get_cols($table);
            $current = $this->get_cols($table,false);
            if(array_diff_assoc($new,$current)){
                return true ;
            }
        }
        return false ;
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
        return mySqlite();
    }
}

function run_setup(){ $setup = new ApiSetup(); $setup->run(); }