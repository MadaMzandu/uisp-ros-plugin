<?php
include_once 'mt.php';
class Admin_Mt_Plan extends MT
{

    public function disable_contention(): bool
    {
        $devs = $this->db()->selectAllFromTable('devices');
        foreach($devs as $dev){
            $this->data->device_id = $dev['id'];
            $this->set_profiles();
            $this->reset_pppoe();
            $this->set_queues();
            $this->delete_queues();
        }
        return true ;
    }

    public function enable_contention()
    {
        $data = [];
        (new Admin_System($data))->rebuild();
        return true ;
    }

    private function set_profiles()
    {
        $this->data->path = '/ppp/profile';
        $profiles = $this->get();
        foreach($profiles as $p){
            $parent = $p['parent-queue'] ?? null ;
            if($parent && preg_match('/servicePlan-\d+-parent/',$parent)){
                $data['.id'] = $p['.id'];
                $data['parent-queue'] = 'none';
                $this->write((object)$data);
            }
        }
    }

    private function set_queues()
    {
        $this->data->path = '/queue/simple';
        $this->data->filter = '?dynamic=false';
        $queues = $this->get();
        foreach($queues as $q){
            $parent = $q['parent'] ?? null ;
            if($parent && preg_match('/servicePlan-\d+-parent/',$parent)){
                $data['.id'] = $q['.id'];
                $data['parent'] = 'none';
                $this->write((object)$data);
            }
        }
    }

    private function delete_queues()
    {
        $this->data->path = '/queue/simple';
        $this->data->filter = '?dynamic=false';
        $queues = $this->get();
        foreach($queues as $q){
            $name = $q['name'] ?? null ;
            if($name && preg_match("/servicePlan-\d+-(parent || child)/",$name)){
                $data['.id'] = $q['.id'];
                $this->write((object)$data,'remove');
            }
        }
    }

    private function reset_pppoe()
    {
        $this->data->path = '/int/pppoe-server/server';
        $this->data->filter = null ;
        $servers = $this->get();
        foreach($servers as $s){
            $data['.id'] = $s['.id'];
            $this->write((object)$data,'disable');
            $this->write((object)$data,'enable');
        }

    }




}
