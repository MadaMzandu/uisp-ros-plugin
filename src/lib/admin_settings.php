<?php
include_once 'admin_mt_plan.php';
class Settings extends Admin
{

    public function edit(): bool
    {
        return (
            $this->apply()
            && $this->db()->saveConfig($this->data)
            && $this->set_message('configuration has been updated'))
            or $this->set_error('failed to update configuration');
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
        $keys = ['disable_contention'];
        foreach($keys as $key){
            if($this->hasChanged($key)){
                return $this->$key();
            }
        }
        return true ;
    }

    private function hasChanged($key): bool
    {
        $conf = $this->db()->readConfig();
        return $this->data->{$key} != $conf->{$key};
    }

    private function disable_contention(): bool
    {
        $this->db()->saveConfig($this->data);
        $data = [];
        $mt = new Admin_Mt_Plan($data,false);
        $action = $this->data->disable_contention
            ? 'disable_contention'
            : 'enable_contention' ;
        $ret = $mt->$action();
        $this->status = $mt->status();
        return $ret ;
    }

}
