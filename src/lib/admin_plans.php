<?php

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
        foreach($plans as $plan){
            $saved = $config[$plan['id']] ?? [] ;
            if(!$saved) {
                $config[$plan['id']] =
                    array_replace($this->defaults(),$plan);
            }
            else{
                $config[$plan['id']] = array_replace($saved,$plan);
            }
        }
        return $config ;
    }

    private function defaults(): array
    {
        $keys = ['id','name','ratio','uploadSpeed','downloadSpeed','priorityUpload','priorityDownload',
            'limitUpload','limitDownload', 'burstUpload','burstDownload','threshUpload',
            'threshDownload'];
        $fill = array_fill_keys($keys,0);
        $fill['timeUpload']  = 1 ;
        $fill['timeDownload'] = 1;
        $fill['priorityUpload'] = 8 ;
        $fill['priorityDownload'] = 8 ;
        return $fill ;
    }

    public function update(): array
    {
        $plans = $this->merge();
        $update = [];
        foreach ($plans as $plan){
            $update[] = array_diff_key($plan,['last' => -1,'created' => -1,'archive' => null]);
        }
        $this->db()->insert($update,'plans',true);
        return $plans;
    }

    private function get_plans(): array
    {
        $data = $this->ucrm()->get('service-plans',['servicePlanType' => 'internet']);
        $read = json_decode(json_encode($data),true);
        $tmp = [];
        $trimmer = null ;
        foreach ($read as $item) {
            $trimmer ??= array_diff_key($item,['id' => 0,'uploadSpeed' => 0,'downloadSpeed' => 0,'name' => null]);
            $trim = array_diff_key($item,$trimmer);
            $trim['archive'] = false ;
            $tmp[$item['id']] = $trim;
        }
        return $tmp ;
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

    public function edit():bool
    {
        $arr = (array) $this->data ;
        $update = array_diff_key($arr,['archive' => null]);
        if($this->db()->edit($update,'plans')){
            $this->rebuild();
        }
        return true ;
    }

    public function delete():bool
    {
        $id = $this->data->id ?? 0 ;
        if($this->db()->delete($id,'plans')){
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
