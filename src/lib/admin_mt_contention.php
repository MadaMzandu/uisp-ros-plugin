<?php
include_once 'mt.php';
class Admin_Mt_Contention extends MT
{

    public function disable(): bool
    { // disable contention ratios
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

    public function enable(): bool
    { //rebuild to enable contention ratios
        $data = [];
        (new Admin_System($data))->rebuild();
        return true ;
    }

    private function set_profiles(): void
    { //removes profile parent queues
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

    private function set_queues(): void
    { //removes dhcp queue parents
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

    private function delete_queues(): void
    { //deletes parent queues
        $this->data->path = '/queue/simple';
        $this->data->filter = '?parent=none';
        $queues = $this->get();
        foreach($queues as $q){
            $name = $q['name'] ?? null ;
            if($name && preg_match("/servicePlan-\d+-(parent || child)/",$name)){
                $data['.id'] = $q['.id'];
                $this->write((object)$data,'remove');
            }
        }
    }

    private function reset_pppoe(): void
    { //resets pppoe servers to apply queue changes
        $this->data->path = '/int/pppoe-server/server';
        $this->data->filter = null ;
        $servers = $this->get();
        foreach($servers as $s){
            $data['.id'] = $s['.id'];
            $this->write((object)$data,'disable');
            $this->write((object)$data,'enable');
        }

    }

    public function update(): bool
    { //updates contention ratio for a parent
        $devices = $this->db()->selectAllFromTable('devices');
        $ret = true ;
        $this->path = '/queue/simple/';
        foreach($devices as $dev) {
            $this->data->device_id = $dev['id'];
            if ($this->data()) $ret &= $this->write_batch();
        }
        return $ret ;
    }

    private function data(){
        $parents = $this->read_parents();
        $this->batch = [];
        foreach ($parents as $p){
            $ratio = $this->data->ratio ;
            $children = $p['children'];
            $shares = intdiv($children,$ratio);
            if (($children % $ratio) > 0) $shares++;
            $ul = $this->data->uploadSpeed * $shares;
            $dl = $this->data->downloadSpeed * $shares;
            $rate = $ul . "M/" . $dl . "M";
            $this->set_batch(
                [
                    'action' => 'set',
                    '.id' => $p['.id'],
                    'max-limit' => $rate,
                    'limit-at' => $rate,
                    ]
            );
        }
        return (bool) $this->batch ;
    }

    private function read_parents(): array
    {
        $parents = [];
        $filter = '?parent=none';
        $read = $this->read($filter);
        $id = $this->data->id ?? 0 ;
        $name = 'servicePlan-' . $id . '-parent' ;
        foreach($read as $q) {
            $re = '/' . $name . '/';
            if(preg_match($re,$q['name'])){
                $q['children'] = sizeof(explode(',',$q['target'])) ?? 0 ;
                $parents[$q['name']] = $q ;
            }
        }
        return $parents ;
    }

}
