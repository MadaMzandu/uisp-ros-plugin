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
        if ($type == 'plan'){
            $this->db()->exec('DELETE FROM services WHERE planId =' . $id);
        }
    }

    public function rebuild($data)
    {
        $type = $data->type ?? 'all';
        $typeId = $data->id ?? null;
        $clear = $data->clear ?? false ;
        $select = [];
        if($type == 'all'){
            $select = $this->cache()->selectCustom('SELECT id FROM services');
        }
        if($type == 'service'){
            $select = $this->cache()->selectCustom('SELECT id from services WHERE planId = '.$typeId);
        }
        if($type == 'device'){
            $select = $this->cache()->selectCustom("SELECT id FROM services WHERE device = ".$typeId);
        }
        $ids = [];
        foreach ($select as $item) $ids[] = $item['id'];
        if($clear)
        {
            $this->clear($type,$typeId);
        }
        $api = $this->ucrm();
        foreach($ids as $id){
            $api->patch('clients/services/'.$id, []);
        }
    }

}

