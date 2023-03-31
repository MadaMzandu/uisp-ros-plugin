<?php

include_once 'admin_mt_contention.php';
class AdminPlans extends Admin
{

    public function get()
    {
        $this->result = $this->update();
    }

    private function merge()
    {
        $plans = $this->get_plans();
        $config = $this->get_config() ;
        $keys = ['ratio','priority','limitUpload','limitDownload','burstUpload','burstDownload',
            'threshUpload','threshDownload','timeUpload','timeDownload'];
        foreach($plans as $plan){
            $id = $plan['id'] ;
            foreach($keys as $key){
                if($key == 'priority'){
                    $plans[$id][$key] = $config[$id][$key] ?? 8 ;
                    continue;
                }
                $plans[$id][$key] = $config[$id][$key] ?? 0 ;
            }
        }
        return $plans ;
    }

    private function update(): array
    {
        $plans = $this->merge();
        $ids = array_keys($plans);
        $delete_expired = sprintf('DELETE FROM plans WHERE id NOT IN (%s)',implode(',',$ids)) ;
        $this->db()->exec($delete_expired);
        $keys = "id,ratio,priority,limitUpload,limitDownload,burstUpload,burstDownload,threshUpload,".
            "threshDownload,timeUpload,timeDownload";
        $update = sprintf("INSERT OR IGNORE INTO plans (%s) VALUES ",$keys);
        $values = [];
        foreach ($plans as $plan){
            $row = [];
            foreach(explode(',',$keys) as $key) {
                $row[] = $plan[$key] ?? 0 ;
            }
            $values[] = sprintf("(%s)",implode(',',$row));
        }
        $update .= implode(',',$values);
        $this->db()->exec($update);
        return $plans;
    }

    private function get_plans(): array
    {
        $read = $this->ucrm()->get('service-plans');
        $tmp = [];
        foreach ($read as $item) {
            $tmp[$item->id] = $item ;
        }
        return json_decode(json_encode($tmp),true) ;
    }

    private function get_config(): array
    {
        $read = $this->db()->selectAllFromTable('plans') ?? [];
        $tmp = [];
        foreach($read as $item){
            $tmp[$item['id']] = $item ;
        }
        return $tmp ;
    }

    public function list(): array
    {
        return $this->update();
    }

    public function edit()
    {
        if($this->db()->edit($this->data,'plans')){
            $this->rebuild();
        }
        return true ;
    }

    private function rebuild()
    {
       $api = new AdminRebuild();
       $plan = new stdClass();
       $plan->id = $this->data->id ;
       $plan->type = 'service';
       $api->rebuild($plan);
    }

}
