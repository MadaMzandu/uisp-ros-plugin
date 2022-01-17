<?php
include_once 'admin_mt_contention.php';
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
        $keys = ['disable_contention']; // keys that have apply methods
        foreach($keys as $key){
            if($this->hasChanged($key)){
                return $this->$key();  // run apply method for key
            }
        }
        return true ;
    }

    private function hasChanged($key): bool
    {
        $conf = $this->db()->readConfig()->{$key} ?? null ;
        $val = $this->data->{$key} ?? null ;
        return is_bool($conf) &&  $val != $conf ;
    }

    private function disable_contention(): bool
    {
        $this->db()->saveConfig($this->data);
        $data = [];
        $mt = new Admin_Mt_Contention($data,false);
        $action = $this->data->disable_contention
            ? 'disable'
            : 'enable' ;
        $ret = $mt->$action();
        $this->status = $mt->status();
        return $ret ;
    }

}
