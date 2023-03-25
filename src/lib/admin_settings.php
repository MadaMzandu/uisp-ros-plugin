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
