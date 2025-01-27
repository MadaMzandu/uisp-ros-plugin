<?php
include_once 'api_common.php';
class ApiUpdate
{
    private string $mode ;
    private $data;
    private $result;
    private bool $fast = false ;

    public function exec(): void
    {
        $allowed = 'services,config,devices,plans,jobs,backups,attributes,attrs,system';
        if(!in_array($this->mode,explode(',',$allowed))){
            fail('invalid_mode',$this->data);
        }
        $action = $this->data->action ?? null;
        switch ($action){
            case 'insert': $this->result = $this->insert(); break;
            case 'edit': $this->result = $this->edit(); break;
            case 'delete': $this->result = $this->delete(); break;
            case 'backup': $this->result = $this->backup(); break;
            case 'restore': $this->result =$this->restore(); break;
            case 'publish': $this->result =$this->publish(); break;
            case 'unpublish' : $this->result =$this->publish(true); break;
            case 'cache': $this->result =$this->cache_build(); break;
            case 'log_clear' : $this->result =$this->log_clear(); break;
            case 'run': $this->result = $this->run_jobs() ; break ;
            default: fail('invalid_action',$this->data);
        }
    }

    public function backup(): array
    {
        if(!in_array($this->mode,['backups','system'])){
            fail('invalid_mode',[$this->mode,$this->data]);
        }
        $last = $this->find_last_backup() ;
        $index = preg_replace("/\D/",'',$last);
        if(++$index > 6) $index = 0 ;
        $dir = 'data';
        $src = "$dir/data.db";
        $next = "$dir/backup-$index";
        if(is_file($src) && copy($src,$next)){
            MyLog()->Append(['backup_success',$next,date('c',filemtime($next))]);
            return [1];
        }
        fail('backup_fail',$this->data);
    }

    private function delete()
    {
        switch ($this->mode){
            case 'devices':
            case 'delete': return $this->delete_db();
            case 'services':
            case 'system': return $this->delete_services();
            case 'jobs': return $this->delete_jobs();
            default: fail('invalid_mode',[$this->mode,$this->data]);
        }
    }

    private function edit()
    {
        switch ($this->mode){
            case 'devices':
            case 'plans': return $this->edit_db();
            case 'config': return $this->edit_config();
            case 'attrs':
            case 'attributes': return $this->edit_attr();
            default: fail('invalid_mode',[$this->mode,$this->data]);
        }
    }

    private function edit_config()
    {
        $data = json_decode(json_encode($this->data->data),true);
        $conf = $this->db()->readConfig() ;
        foreach (array_keys($data) as $k){ if(!property_exists($conf,$k)){
            fail('invalid_config_key',$this->data); }}
        if($this->db()->saveConfig($data)){
            MyLog()->Append('config_edit_success');
            return [];
        }
        fail('edit_config_fail',$this->data);
    }

    private function insert()
    {
        switch ($this->mode){
            case 'devices':
            case 'plans': return $this->insert_db();
            case 'services':
            case 'system': return $this->insert_services();
            case 'attrs':
            case 'attributes': return $this->insert_attr();
            default: fail('invalid_mode',[$this->mode,$this->data]);
        }
    }

    private function log_clear(): array
    {
        $fn = 'data/plugin.log';
        if(file_put_contents($fn,date('c') . " log_reset\n")){
            MyLog()->Append('log_clear_success');
            return [];
        }
        fail('log_clear_fail');
    }

    private function publish($clear = false): array
    {
        $fn = $this->data->data->name ?? 'none';
        $src = "data/$fn";
        $dst = "public/$fn";
        if($clear){
            if(is_file($dst) && unlink($dst)){
                MyLog()->Append(['unpublish_success',$dst]);
                return [];
            }
        }
        else{
            if(is_file($src) && copy($src,$dst)){
                MyLog()->Append(['publish_success',$src,$dst]);
                return [];
            }
        }
        $un = $clear ? 'un' : null;
        fail("${un}publish_fail",$this->data);
    }

    public function restore(): array
    {
        $dir = 'data';
        $fn = "$dir/" . ($this->data->data->name ?? 'none') ;
        if(is_file($fn) && copy($fn,"$dir/data.db")){
            MyLog()->Append(['restore_success',$fn,date('c',filemtime($fn))],6);
            return [];
        }
        fail('restore_fail',$this->data);
    }

    private function run_jobs()
    {
        $fn = 'data/queue.json';
        $read = is_file($fn) ? json_decode(file_get_contents($fn),true): [];
        $data = $this->data->data ?? [];
        if(!$data || !is_array($data)){ return [1]; }
        $filter = array_fill_keys($data,'$%^%^');
        $run = array_intersect_key($read,$filter) ;
        $diff = array_diff_key($read,$filter);
        $write = $diff ? json_encode($diff,128) : '{}';
        if(!file_put_contents($fn,$write)){//remove run jobs from queue
            fail('job_save_failed',$diff);
        }
        $edit = [];
        $delete = [];
        foreach ($run as $job){
            if($job['action'] == 'delete'){ $delete[] = $job['id']; }
            else{ $edit[] = $job['id']; }
        }
        $batch = new Batch();
        if($edit){ $batch->set_accounts($edit); }
        if($delete){ $batch->del_accounts($delete); }
        return $run ;
    }

    private function delete_db(): ?array
    {
        $data = json_decode(json_encode($this->data->data),true);
        $id = $data['id'] ?? null ;
        if($id){ $data = [$id]; }
        $pk = $this->find_pk();
        if(!$data || !$pk){ return null; }
        $ids = implode(',',$data);
        $table = $this->mode ;
        $s = "select * from $table where $pk in ($ids)";
        $before = $this->db()->selectCustom($s);
        $d = "delete from $table where $pk in ($ids)";
        if($this->db()->exec($d)){
            $this->result = $before ;
            if($this->mode == 'devices'){ $this->cache_build(); }
            return $this->result ;
        }
        fail('db_delete_fail',$this->data);
    }

    private function delete_jobs(): array
    {
        $fn = 'data/queue.json';
        $read = is_file($fn) ? json_decode(file_get_contents($fn),true): [];
        $del = $this->data->data ?? [];
        if(!$del || !is_array($del)){ return [1]; }
        $filter = array_fill_keys($del,'$%^%^');
        $diff = array_diff_key($read,$filter) ;
        $write = $diff ? json_encode($diff,128) : '{}';
        if(file_put_contents($fn,$write)){
            MyLog()->Append('delete_jobs_success');
            return $diff;
        }
        fail('delete_job_fail');
    }

    private function delete_services(): array
    {
        $this->fast_finish();
        $ids = $this->find_services() ;
        if(!$ids){ return [] ; }
        $batch = new Batch();
        if($batch->del_accounts($ids)){
            MyLog()->Append(['delete_services_success','items: '.sizeof($ids)]);
            return [];
        }
        fail('delete_services_fail',$this->data);
    }

    private function insert_attr()
    {
        $an = $this->data->data->value ?? null;
        $key = $this->data->data->key ?? 'nokey' ;
        $conf = $this->db()->readConfig();
        if(!$an || !property_exists($conf,$key)){
            fail('insert_attr_fail',$this->data);
        }
        $data = [
            'name' => $an,
            'type' => 'string',
            'clientZoneVisible' => false,
            'attributeType' => 'service',
        ];
        $done = $this->ucrm()->post('custom-attributes',$data);
        $value = $done->key ?? null ;
        if($key && $this->db()->saveConfig([$key => $value])){
            MyLog()->Append(['insert_attr_success',$key,$value]);
            return [];
        }
        fail('insert_attr_fail',$data);
    }

    private function insert_db()
    {
        $data = json_decode(json_encode($this->data->data),true);
        $cols = $this->find_columns() ;
        $qos = $this->qos_change();
        if(!$data || !$cols){ fail('insert_invalid',$this->data); }
        $fill = array_fill_keys($cols,null);
        $trim = array_intersect_key($data,$fill);
        $now = date('c');
        $trim['last'] = $now ;
        $trim['created'] = $now ;
        if($this->db()->insert($trim,$this->mode)){
            $id = $data['id'] ?? null ;
            $this->result = $this->find_last($id);
            if($this->mode == 'devices')
            {
                $this->cache_build(true) && $this->set_qos($qos);
            }
            return $this->result;
        }
        fail('db_insert_fail',$this->data);
    }

    private function insert_services($type = null): array
    {
        $this->fast_finish();
        $ids = $this->find_services($type) ;
        if(!$ids){ return [] ; }
        $this->clean_services($type);
        $batch = new Batch();
        if($batch->set_accounts($ids)){
            MyLog()->Append(['insert_services_success','items: '.sizeof($ids)]);
            return [];
        }
        fail('insert_services_fail',$this->data);
    }

    private function clean_services($type = null)
    {
        $id = $this->data->data->id ?? 0 ;
        $type = $this->data->data->type ?? $type ?? 'device' ;
        if(in_array($type,['service','services'])){ return; }
        $sql = [
            "DELETE FROM network",
            "DELETE FROM services",
        ];
        if($type == 'device'){

            $sql[0] .= " WHERE id IN (SELECT id FROM services WHERE device = $id)";
            $sql[1] .= " WHERE device = $id";
        }
        foreach($sql as $st){ $this->db()->exec($st); }
    }

    private function edit_attr()
    {
        $key = $this->data->data->key ?? 'NoKey';
        $value = $this->data->data->value ?? null ;
        $conf = $this->db()->readConfig();
        if(property_exists($conf,$key)
            && $this->db()->saveConfig([$key => $value])){
            MyLog()->Append(['edit_attr_success',$key,$value]);
            return [];
        }
        fail('edit_attr_fail',$this->data);
    }

    private function edit_db(): ?array
    {
        $data = json_decode(json_encode($this->data->data),true);
        $pk = $this->find_pk();
        $id = $data[$pk] ?? null ;
        $qos = $this->qos_change() ;
        $cols = array_diff($this->find_columns(),['created']) ;
        if(!$data || !$pk || !$id || !$cols){
            fail('edit_invalid',$data);  }
        $fill = array_fill_keys($cols,null);
        $trim = array_intersect_key($data,$fill);
        $trim['last'] = date('c') ;
        if($this->db()->edit($trim,$this->mode)){
            $this->result = $this->find_last($trim[$pk]);
            switch ($this->mode){
                case 'devices': $this->cache_build(true) && $this->set_qos($qos); break;
                case 'plans': $this->insert_services('plans'); break ;
            }
            MyLog()->Append(['edit_db_success','mode: '. $this->mode,'id: '. $id]);
            return $this->result ;
        }
        fail('db_edit_fail',$this->data);
    }

    private function find_last($id = null)
    {
        $table = $this->mode ;
        $pk = $this->find_pk() ;
        if(!$id){
            $id = $this->db()->singleQuery("select max($pk) from $table");
        }
        $q = "select * from $table where $pk=$id";
        $last = $this->db()->singleQuery($q,true);
        return array_diff_key($last,['password' => '$%^&*']);
    }

    private function find_columns(): array
    {
        $table = $this->mode ;
        $q = "select * from pragma_table_info('$table')";
        $r = $this->db()->selectCustom($q) ;
        $cols = [];
        foreach($r as $i){
            $cols[] = $i['name'] ;
        }
        return $cols ;
    }

    private function find_pk(): string
    {
        $table = $this->mode ;
        $q = "select name from pragma_table_info('$table') where pk=1";
        return $this->db()->singleQuery($q);
    }

    private function find_last_backup()
    {
        $d = 'data';
        $files = preg_grep("#^backup-\d+#",scandir($d));
        $h = '0';
        $fn = 'backup-0';
        foreach ($files as $f) {
            $t = filemtime("$d/$f");
            if($t > $h){
                $h = $t ;
                $fn = $f ;
            }
        }
        return $fn;
    }

    private function find_services($type = null): array
    {
        $id = $this->data->data->id ?? 0 ;
        $type = $this->data->data->type ?? $type ?? 'device' ;
        if($type != 'all' && !$id){ return []; }
        $field = $type == 'device' ? 'device' : 'planId';
        $where = "where $field=$id and" ;
        if($type == 'all') $where = 'where' ;
        $q = "select id from services $where status not in (0,2,5,8)";
        $r = $this->cachedb()->selectCustom($q) ?? [];
        $ids = [];
        foreach($r as $i){ $ids[] = $i['id']; }
        MyLog()->Append(['find_services','items: '. sizeof($ids)]);
        return $ids ;
    }

    private function fast_finish()
    {
        if($this->fast){ return; }
        respond('ok',false,$this->result ?? []);
        $this->fast = true ;
        if(function_exists('fastcgi_finish_request')){
            fastcgi_finish_request();
        }
    }

    private function set_qos($type = 0): array
    {
        $this->fast_finish();
        MyLog()->Append("set_qos type: ". $type);
        $ids = $this->find_services() ;
        if(!$ids || !$type){ return []; }
        $batch = new Batch();
        if($type > 0){
           $done = $batch->set_accounts($ids);
        }
        else{
            $done = $batch->del_queues($ids);
        }
        if($done){MyLog()->Append(['set_qos_success',"items: ".sizeof($ids)]);}
        return [] ;
    }

    private function cache_build($edit = false): array
    {
        $this->fast_finish();
        if($edit && !$this->name_change()){ return [1]; }
        $api = new ApiCache() ;
        $api->sync(true);
        MyLog()->Append("device_cache_success");
        return [1] ;
    }

    private function qos_change(): int
    {
        if($this->mode != 'devices'){ return 0; }
        $edit = $this->data->data->qos ?? null ;
        if(!is_int($edit)){ return 0 ; }
        $id = $this->data->data->id ?? 0 ;
        $saved = $this->db()->singleQuery("select qos from devices where id=$id");
        if($edit === $saved){ return 0 ;}
        return $edit ? 1 : -1;
    }

    private function name_change(): bool
    {//if device name has changed
        $name = $this->data->data->name ?? null ;
        return $this->mode == 'devices' && $name ;
    }

    private function set_mode($mode)
    {
        if(preg_match("#(service|device|plan)#",$mode)){ //append ending "s"
            $mode = preg_replace("#s\s*$#",'',$mode) . 's';
        }
        $this->mode = $mode ;
    }

    private function ucrm(): ApiUcrm { return new ApiUcrm(); }

    private function db(): ApiSqlite { return mySqlite(); }

    private function cachedb(): ApiSqlite { return myCache(); }

    public function status(): object { return new stdClass(); }

    public function result() { return $this->result; }

    public function __construct($data = null,$mode = 'services')
    {
        $this->set_mode($mode);
        if(is_array($data)){ $data = json_decode(json_encode($data));}
        if(!$data){$data = new stdClass(); }
        $this->data = $data ;
    }
}