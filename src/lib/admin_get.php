<?php

class AdminGet extends Admin{

    public function get()
    {
        $path = $this->data->path ?? null;
        if (method_exists($this, $path)) {
            $this->result = $this->$path();
        }
        else {
            $this->result = $this->ucrm()->get($path,(array)$this->data->data);
        }
    }

    protected function attributes()
    {
        $ros_keys = "pppoe_user_attr,pppoe_pass_attr,device_name_attr,mac_addr_attr,ip_addr_attr,".
            "auto_ppp_user,pppoe_caller_attr,hs_attr";
        $map = [];
        foreach(explode(',',$ros_keys) as $ros_key){
            $ukey = $this->conf->$ros_key ?? null;
            if($ukey) $map[$ukey] = $ros_key;
        }
        $attributes = $this->ucrm()->get('custom-attributes') ?? [];
        $return = [];
        foreach ($attributes as $item){
            $match = $map[$item->key] ?? null ;
            if($match){
                $item->roskey = $match ;
            }
            $return[] = $item;
        }
        return $return ;
    }
}