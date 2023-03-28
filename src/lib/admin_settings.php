<?php
include_once 'admin_mt_contention.php';
class Settings extends Admin
{

    public function edit(): bool
    {
        if($this->db()->saveConfig($this->data) && $this->apply()){
            return $this->set_message('configuration updated');
        }
        return $this->set_error('failed to update configuration');
    }

    public function get(): bool
    {
        $this->read = $this->db()->readConfig();
        if (!$this->read) {
            $this->set_error('failed to read settings');
            return false;
        }
        $this->result = $this->read;
        return true;
    }

    public function attributes(): bool
    {
        $ros_keys = "pppoe_user_attr,pppoe_pass_attr,device_name_attr,mac_addr_attr,ip_addr_attr,".
            "auto_ppp_user,pppoe_caller_attr,hs_attr";
        $map = [];
        foreach(explode(',',$ros_keys) as $ros_key){
            $ukey = $this->conf()->$ros_key ?? null;
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
        $this->result = $return ;
        return (bool) $return ;
    }

    private function apply(): bool
    {
        $keys = array_keys((array)$this->data);
        $ret = true ;
        foreach($keys as $key){
            if(method_exists($this,$key)){
                $ret &= $this->$key();
            }
        }
        return $ret ;
    }

    private function disable_contention(): bool
    {
        $this->db()->saveConfig($this->data);
        $data = [];
        $mt = new Admin_Mt_Contention($data);
        $action = $this->data->disable_contention
            ? 'disable'
            : 'enable' ;
        $ret = $mt->$action();
        $this->status = $mt->status();
        return $ret ;
    }

}
