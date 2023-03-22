<?php
const MAX_BACKUPS = 6;
class ApiSetup
{
    private string $my_version = '1.8.9';

    public function test(){
        var_dump($this->config_sql());
    }

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
    }

    private function db_update(): bool
    {
        if(!$this->db_backup()){
            $this->throwErr('setup: failed to backup - not updating');
        }
        $source = 'includes/tables.sql';
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
        return $this->db()->exec($this->config_sql());
    }

    private  function set_version(): bool
    {
        $state = $this->state() ;
        $state->version = $this->my_version ;
        $this->save($state);
        $sql = sprintf('UPDATE config SET "value"="%s" WHERE "key"="%s"',
            $this->my_version,'version');
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
        return $running < $this->my_version ;
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

    private function config_sql()
    {
        $file = 'includes/conf_default.json';
        $default = json_decode(file_get_contents($file),true);
        $sql = 'INSERT OR IGNORE INTO config ("key","value","created") VALUES ';
        $created = '2020-01-01 00:00:00';
        $str = [];
        foreach(array_keys($default) as $key){
            $str[] = $this->to_sql_vals([$key,$default[$key],$created]);
        }
        return $sql . implode(',',$str);
    }

    private function config_json(): void
    { //output a default config
        echo json_encode($this->default_config(),JSON_PRETTY_PRINT) . PHP_EOL;
    }

    private function default_config() :array
    {
        $keystr = 'ppp_pool,router_ppp_pool,excl_addr,active_list,disabled_list,disabled_profile,'.
            'unsuspend_date_fix,unsuspend_fix_wait,pppoe_user_attr,pppoe_pass_attr,device_name_attr,'.
            'mac_addr_attr,ip_addr_attr,version,disabled_rate,auto_ppp_user,pppoe_caller_attr,'.
            'disable_contention,hs_attr,hs_enable,auto_hs_user';
        $valstr = '10.99.0.0/16,true,null,null,disabled,disabled,false,5,pppoeUsername,pppoePassword,'.
            'deviceName,macAddress,ipAddress,1.8.5,1,false,callerId,true,hotspot,false,false';
        $keys = explode(',',$keystr);
        $vals = explode(',',$valstr);
        $ret = [];
        $len = sizeof($keys);
        for($i = 0;$i < $len;$i++){
            $ret[$keys[$i]] = $vals[$i];
        }
        return $ret ;

    }

    private function to_sql_vals($array): string
    {
        $ret = [];
        foreach($array as $val){
            if($val == 'null' || empty($val)) $ret[] = 'null';
            elseif(is_numeric($val)) $ret[] = $val ;
            else $ret[] = sprintf("'%s'",$val);
        }
        return sprintf("(%s)",implode(',',$ret));
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

    private function db()
    {
        return new ApiSqlite();
    }

    private function throwErr($msg): void
    {
        throw new Exception("setup: ". $msg);
    }
}