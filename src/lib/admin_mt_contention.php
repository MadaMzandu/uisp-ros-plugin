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
        foreach($devices as $dev){
            $this->data->device_id = $dev['id'];
            if($data = $this->data()){
                $ret = $ret && $this->write($data) ;
            }
        }
        return $ret ;
    }

    private function data(): ?object
    { //calculates contention bandwidth rate
        $planId = $this->data->id ;
        if($children = $this->db()->countDeviceServicesByPlanId(
        $planId, $this->data->device_id)) {
            $ratio = $this->data->ratio;
            $shares = intdiv($children, $ratio);
            if (($children % $ratio) > 0) {
                $shares++;
            }
            $ul = $this->data->uploadSpeed * $shares;
            $dl = $this->data->downloadSpeed * $shares;
            $rate = $ul . "M/" . $dl . "M";
            return (object)[
                '.id' => 'servicePlan-' . $planId . '-parent',
                'max-limit' => $rate,
                'limit-at' => $rate,
            ];
        }
        return null;
    }

}
