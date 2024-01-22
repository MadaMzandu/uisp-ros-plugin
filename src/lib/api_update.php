<?php
include_once 'api_common.php';
class ApiUpdate
{
    private string $mode ;
    private null|array|object $data = null;
    private null|array|object $result = null;
    private bool $fast = false ;

    public function exec()
    {
        if(!in_array($this->mode,['services','devices','plans'])){
            return ;
        }
        $action = $this->data->action ?? null;
        match ($action){
            'insert' => $this->insert(),
            'edit' => $this->edit(),
            'delete' => $this->delete(),
            'service_build' => $this->service_build(),
            'service_clear' => $this->service_build(true),
            default => null,
        };
    }

    private function insert()
    {
        $data = json_decode(json_encode($this->data->data),true);
        MyLog()->Append(['DATA: ',$data]);
        $cols = $this->find_columns() ;
        $qos = $this->qos_change();
        MyLog()->Append(['COLS,QOS: ',$cols,$qos]);
        if(!$data || !$cols){ fail('insert_invalid',$data); }
        $fill = array_fill_keys($cols,null);
        $trim = array_intersect_key($data,$fill);
        $now = date('c');
        $trim['last'] = $now ;
        $trim['created'] = $now ;
        MyLog()->Append(['TRIM',$trim]);
        if($this->db()->insert($trim,$this->mode)){
            $id = $data['id'] ?? null ;
            $this->result = $this->find_last($id);
            MyLog()->Append(["RESULT: ",$this->result]);
            match ($this->mode){
                'devices' => $this->cache_build(true) && $this->qos_build($qos),
                 default => null,
            };
            return $this->result;
        }
        return null ;
    }

    private function edit(): ?array
    {
        $data = json_decode(json_encode($this->data->data),true);
        MyLog()->Append(["DATA: ",$data]);
        $pk = $this->find_pk();
        $id = $data[$pk] ?? null ;
        $qos = $this->qos_change() ;
        $cols = array_diff($this->find_columns(),['created']) ;
        MyLog()->Append(["PK,ID,QOS,COLS",$pk,$id,$qos,$cols]);
        if(!$data || !$pk || !$id || !$cols){
            fail('edit_invalid',$data);  }
        $fill = array_fill_keys($cols,null);
        $trim = array_intersect_key($data,$fill);
        $trim['last'] = date('c') ;
        MyLog()->Append(['TRIM: ',$trim]);
        if($this->db()->edit($trim,$this->mode)){
            $this->result = $this->find_last($trim[$pk]);
            MyLog()->Append(['RESULT',$this->result]);
            match ($this->mode){
                'devices' => $this->cache_build(true) && $this->qos_build($qos),
                'plans' => $this->service_build(),
                 default => null ,
            };
            return $this->result ;
        }
        return null ;
    }

    private function delete(): ?array
    {
        $data = json_decode(json_encode($this->data->data),true);
        $id = $data['id'] ?? null ;
        if($id){ $data = [$id]; }
        MyLog()->Append(["DATA: ",$data]);
        $pk = $this->find_pk();
        if(!$data || !$pk){ return null; }
        $ids = implode(',',$data);
        $table = $this->mode ;
        $s = "select * from $table where $pk in ($ids)";
        $before = $this->db()->selectCustom($s);
        MyLog()->Append(["BEFORE: ",$before]);
        $d = "delete from $table where $pk in ($ids)";
        if($this->db()->exec($d)){
            $this->result = $before ;
            MyLog()->Append(["RESULT: ",$this->result]);
            match ($this->mode){
                'devices' => $this->cache_build(),
                 default => null ,
            };
            return $this->result ;
        }
        return null ;
    }

    private function find_last($id = null)
    {
        $table = $this->mode ;
        $pk = $this->find_pk() ;
        if(!$id){
            $id = $this->db()->singleQuery("select max($pk) from services");
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

    private function find_services(): array
    {
        $id = $this->data->data->id ?? 0 ;
        $field = $this->mode == 'devices' ? 'device' : 'planId';
        $r = $this->cachedb()->selectCustom("select id from services where $field=$id") ?? [];
        $ids = [];
        foreach($r as $i){ $ids[] = $i['id']; }
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

    private function service_build($clear = false): bool
    {
        $this->fast_finish();
        $id = $this->data->data->id ?? $this->result->id ?? null ;
        if(!$id){ return false ; }
        $ids = $this->find_services() ;
        if(!$ids){ return false ; }
        $batch = new Batch();
        if($clear){
            $done = $batch->del_accounts($ids);
        }
        else{
            $done = $batch->set_accounts($ids);
        }
        if($done){
            $n = $clear ? "clear" : 'build';
            MyLog()->Append(["service_${n}_success","items: ".sizeof($ids)],6);
        }
        return $done ;
    }

    private function qos_build($type = 0): bool
    {
        $this->fast_finish();
        MyLog()->Append("QOS TYPE: ". $type);
        if(!$type){ return false; }
        if($type > 0){
           $done = $this->service_build();
        }
        else{
            $batch = new Batch();
            $ids = $this->find_services() ;
            $done = $batch->del_queues($ids);
            if($done){MyLog()->Append(['qos_remove_success',"items: ".sizeof($ids)]);}
        }
        return $done ;
    }

    private function cache_build($edit = false): bool
    {
        $this->fast_finish();
        if($edit && !$this->name_change()){ return false; }
        $api = new ApiCache() ;
        $api->sync(true);
        MyLog()->Append("device_cache_success");
        return true ;
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

    private function db(): ApiSqlite { return mySqlite(); }
    private function cachedb(): ApiSqlite { return myCache(); }
    private function status(): object { return new stdClass(); }
    private function result(): null|array|object { return $this->result; }

    public function __construct($data = null,$mode = 'services')
    {
        $this->set_mode($mode);
        if(!$data){$data = new stdClass(); }
        $this->data = $data ;
    }
}