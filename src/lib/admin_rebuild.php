<?php

class AdminRebuild{

    private function db(){ return new ApiSqlite(); }

    private function ucrm() { return new ApiUcrm(); }

    private function cache(){ return new ApiSqlite('data/cache.db'); }

    private function clear($type,$id = null)
    {
        if($type == 'all'){
            $this->db()->deleteAll('services');
        }
        if ($type == 'device'){
            $this->db()->exec("DELETE FROM services WHERE device = " . $id);
        }
        if ($type == 'service'){
            $this->db()->exec('DELETE FROM services WHERE planId =' . $id);
        }
    }

    public function rebuild($data)
    {
        if(function_exists('fastcgi_finish_request')){
            fastcgi_finish_request();
        }
        set_time_limit(7200);
        $type = $data->type ?? 'all';
        $typeId = $data->id ?? $data->did ?? $data->sid ?? null;
        $clear = $data->clear ?? false ;
        $select = [];
        $timer = new ApiTimer($type . ' rebuild: '.json_encode($data));
        MyLog()->Append('selecting services to rebuild');
        if($type == 'all'){
            $select = $this->cache()->selectCustom('SELECT id FROM services WHERE status IN (1,3)');
        }
        if($type == 'service'){
            $select = $this->cache()->selectCustom(sprintf("SELECT id from services WHERE planId = %s AND status IN (1,3) ",$typeId));
        }
        if($type == 'device'){
            $select = $this->cache()->selectCustom(sprintf("SELECT id FROM services WHERE device = %s AND status IN (1,3) ",$typeId)); //test limit 15
        }
        $ids = [];
        if(empty($select)){
            throw new Exception('no items found to rebuild: '.json_encode($data));
        }
        foreach ($select as $item) $ids[] = $item['id'];
        MyLog()->Append(sprintf('found %s services to rebuild',sizeof($ids)));
        if($clear)
        {
            $this->clear($type,$typeId);
        }
        $batch = new MtBatch();
        $batch->set_ids($ids);
        $timer->stop();
    }

}

function rebuild(){ $api = new AdminRebuild(); $api->rebuild([]);}