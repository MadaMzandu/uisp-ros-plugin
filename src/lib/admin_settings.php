<?php
include_once 'admin_mt_contention.php';
class Settings extends Admin
{

    public function edit(): bool
    {
        $this->trim();
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

    private function trim()
    {
        $conf = (array)$this->db()->readConfig();
        foreach(array_keys($conf) as $key){
            $old = $conf[$key] ;
            $new = $this->data->$key ?? null ;
            if($old == $new){
                unset($this->data->$key);
            }
        }
    }

    private function apply(): bool
    {
        $apps = ['disable_contention']; // keys that have apply methods
        $keys = array_keys((array)$this->data);
        $ret = true ;
        foreach($keys as $key){
            if(!in_array($key,$apps))continue;
            $ret &= $this->$key();
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
