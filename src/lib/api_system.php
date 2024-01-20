<?php

class ApiSystem extends Admin
{

    public function rebuild(): void
    {
        $api = new ApiRebuild() ;
        $api->rebuild($this->data);
    }

    public function recache()
    {
        $api = new ApiCache();
        $this->db()->saveConfig(['last_cache' =>'2020-01-01']);
        $api->setup();
        $api->sync();
    }

    public function unqueue()
    {
        if(function_exists('fastcgi_finish_request')){
            fastcgi_finish_request();
        }
        $did = $this->data->id ?? $this->data->did ?? 0 ;
        $on = $this->data->on ?? true ;
        $select = $this->dbCache()->selectCustom(sprintf("SELECT id FROM services WHERE device = %s AND status NOT IN (2,5,8) ",$did));
        if(empty($select)){ return ;}
        $ids = [];
        foreach ($select as $item) $ids[] = $item['id'];
        $api = new Batch();
        $api->set_queues($ids,$on);
    }

    public function log_clear()
    {
        file_put_contents('data/plugin.log','');
        MyLog()->Append('log_cleared');
    }

    public function purge_orphans()
    {
        $fill = array_fill_keys(['.id','name','mac-address'],null);
        $count = 0 ;
        $date = date('c');
        $batch = [];
        foreach ($this->data as $item){
            $trim = array_intersect_key($item,$fill);
            $trim['action'] = 'remove';
            $trim['batch'] = $date . "-" . ++$count ;
            $trim['path'] = $item['path'];
            $batch[] = $trim ;
        }
    }

    public function get_orphans()
    {
        $did = $this->data->id
            ?? $this->data->did ?? $this->data->device ?? 0;
        $dev = $this->db()->selectDeviceById($did);
        if(!is_object($dev)){ return ; }
        $api = $this->device_data_api($dev->type);
        $this->result = $api->get_orphans($dev);
    }

    private function device_data_api($type): ErData|MtData
    {
        return match ($type){
            'edgeos,edgerouter,edge' => new ErData(),
            default => new MtData(),
        };
    }

    private function device_api($type): MT|ER
    {
        return match ($type){
            'edgeos,edgerouter,edge' => new ER(),
            default => new MT(),
        };
    }
}