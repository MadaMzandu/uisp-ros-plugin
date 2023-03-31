<?php
include_once 'api_sqlite.php';
//include_once '_web_ucrm.php'; //for devel only
include_once 'api_ucrm.php';
include_once 'api_timer.php';
include_once 'api_logger.php';
include_once 'mt_batch.php';
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
        if(function_exists('fastcgi_finish_request')){
            fastcgi_finish_request();
        }
        $timer = new ApiTimer('rebuild: '.json_encode($data));
        set_time_limit(7200);
        $type = $data->type ?? 'all';
        $typeId = $data->id ?? null;
        $clear = $data->clear ?? false ;
        $select = [];
        MyLog()->Append('selecting services to rebuild');
        if($type == 'all'){
            $select = $this->cache()->selectCustom('SELECT id FROM services WHERE status IN (1,3)');
        }
        if($type == 'service'){
            $select = $this->cache()->selectCustom(sprintf("SELECT id from services WHERE planId = %s AND status IN (1,3)",$typeId));
        }
        if($type == 'device'){
            $select = $this->cache()->selectCustom(sprintf("SELECT id FROM services WHERE device = %s AND status IN (1,3)",$typeId));
        }
        $ids = [];
        if(empty($select)){
            throw new Exception('no items found to rebuild: '.json_encode($data));
        }
        foreach ($select as $item) $ids[] = $item['id'];
        MyLog()->Append(sprintf('found %s services to rebuild',sizeof($ids)));
        $batch = new MtBatch();
        $batch->set_ids($ids);
//        if($clear)
//        {
//            $this->clear($type,$typeId);
//        }
//        $api = $this->ucrm();
//        $count = 0;
//        foreach($ids as $id){
//            try{
//                $done = $api->patch('clients/services/'.$id, []);
//                if($done){ MyLog()->Append(sprintf("rebuild service: %s success",$done->id)); $count++;}
//            }
//            catch (Exception $error){
//                MyLog()->Append('failed to rebuild service: '.$error->getMessage());
//                continue ;
//            }
//        }
//        MyLog()->Append(sprintf('rebuild %s services completed',$count));
        $timer->stop();
    }

}

function rebuild(){ $api = new AdminRebuild(); $api->rebuild([]);}