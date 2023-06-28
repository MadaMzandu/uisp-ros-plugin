<?php

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
        $native_keys = [
            'pppoe_user_attr',
            'pppoe_pass_attr',
            'device_name_attr',
            'mac_addr_attr',
            'dhcp6_duid_attr',
            'dhcp6_iaid_attr',
            'ip_addr_attr',
            'auto_ppp_user',
            'pppoe_caller_attr',
            'hs_attr',
        ];
        $native_map = [];
        foreach($native_keys as $native_key){
            $defined = $this->conf()->$native_key ?? null;
            if($defined) $native_map[$defined] = $native_key;
        }
        $attributes = $this->ucrm()->get('custom-attributes') ?? [];
        $return = [];
        foreach ($attributes as $item){
            $native_key = $native_map[$item->key] ?? null ;
            if($native_key){
                $item->roskey = $native_key ;
                $item->native_key = $native_key ;
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
            $method = 'apply_' . $key ;
            if(method_exists($this,$method)){
                $ret &= $this->$method();
            }
        }
        return $ret ;
    }

    private function apply_disable_contention(): bool
    {
        $enable = !$this->data->disable_contention ?? false ;
        $sys = new AdminRebuild();
        if($enable){
            $sys->rebuild(['type' => 'all']);
        }
        else{
            $sys->rebuild(['type' => 'all']);
            $batch = new MtBatch();
            $batch->del_parents();
        }
        return true;
    }

}
