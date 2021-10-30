<?php

class MT_Profile extends MT {

    private $pq; //parent queue object
    private $count;
    private $is_nas = false;

    public function __construct(&$data) {
        parent::__construct($data);
        $this->path = '/ppp/profile/';
        $this->pq = new MT_Parent_Queue($data);
    }

    public function set($count = 0) {
        $this->count = $count;
        if ($count > 0 && !$this->exists()) {
            return $this->insert();
        }
        if ($count < 0 && !$this->has_children()) {
            return $this->deleteProfile();
        }
        if (!$this->pq->set($count)) {
            $this->set_error($this->pq->error());
            return false;
        }
        return true;
    }

    public function set_nas($list) {
        global $conf;
        $this->is_nas = true;
        foreach ($list as $nas) {
            $this->make_device($nas);
            if (!$this->exists()) {
                $this->write($this->profile(), 'add');
            }
            if (!$this->exists($conf->disabled_profile)) {
                $this->write($this->profile($conf->disabled_profile), 'add');
            }
        }
    }

    private function make_device($nas) {
        global $conf;
        $this->device = (object) [
                    'name' => 'nas',
                    'ip' => $nas,
                    'user' => $conf->nas_user,
                    'password' => $conf->nas_password,
        ];
    }

    protected function get_device(){
        if ($this->is_nas) {
            return $this->device;
        }
            return parent::get_device();
    }

    private function deleteProfile() {
        if (!$this->pq->set(-1)) {
            $this->set_error($this->pq->error());
            return false;
        }
        $data = (object) array('.id' => $this->name());
        return $this->write($data, 'remove');
    }
    
    private function has_children(){
        $this->path = '/ppp/secret/';
        $read = $this->read('?profile='.$this->name()) ?? [];
        $this->path = '/ppp/profile/';
        $count = $read ? sizeof($read): 0;
        $count += $this->is_disabled() ? 0 : -1 ; // do not deduct if account is disabled
        return $count ? : false ;
    }

    private function insert() {
        if (!$this->pq->set(1)) {
            $this->set_error($this->pq->error());
            return false;
        }
        return $this->write($this->profile(), 'add');
    }

    private function profile() {
        return (object) [
                    'name' => $this->name(),
                    'local-address' => $this->local_addr(),
                    'rate-limit' => $this->rate(),
                    'parent-queue' => $this->is_nas ? 'none' : $this->pq->name(),
                    'address-list' => $this->fwlist(),
        ];
    }
    
    protected function fwlist() {
        global $conf ;
        return $this->is_disabled() 
                ? $conf->disabled_list : $conf->active_list;
    }
    
    protected function rate(){
        global $conf ;
        $rate = parent::rate();
        $disabled_limit = $conf->disabled_rate ?? null;
        $disabled_rate = $disabled_limit.'M/'.$disabled_limit.'M';
        return $this->is_disabled() ? $disabled_rate : $rate ;
    }

    private function local_addr() { // get one address for profile local address
        $savedPath = $this->path;
        $this->path = '/ip/address/';
        if ($this->read()) {
            foreach ($this->read as $prefix) {
                if ($prefix['dynamic'] == 'true') {
                    continue;
                }
                [$addr] = explode('/', $prefix['address']);
                $this->path = $savedPath;
                return $addr;
            }
        }
        $this->path = $savedPath;
        return false;
    }

    private function name() {
        global $conf ;
        return $this->is_disabled() 
                ? $conf->disabled_profile 
                : $this->entity->servicePlanName ;
    }

    protected function exists($profile = false) {
        $name = $profile ? $profile : $this->name();
        return !$this->read('?name=' . $name) 
                ? false : ($this->read ? true :false);
    }

}
