<?php

class MT_Profile extends MT {

    private $pq; //parent queue object
    private $count;
    private $is_nas = false;

    public function __construct(Service &$svc) {
        parent::__construct($svc);
        $this->path = '/ppp/profile/';
        $this->pq = new MT_Parent_Queue($svc);
    }

    public function set() {
        if ($this->svc->count > 0 && !$this->exists()) {
            return $this->insert();
        }
        if ($this->svc->count < 0 && !$this->has_children()) {
            return $this->deleteProfile();
        }
        if (!$this->pq->set($this->svc->count)) {
            $this->set_error($this->pq->error());
            return false;
        }
        return true;
    }

    private function deleteProfile() {
        $data['.id'] = $this->data()->name;
        return !$this->pq->set(-1) 
                ? : $this->write((object)$data, 'remove');
    }
    
    private function has_children(){
        $this->path = '/ppp/secret/';
        $read = $this->read('?profile='.$this->name()) ?? [];
        $this->path = '/ppp/profile/';
        $count = $read ? sizeof($read): 0;
        $count += $this->svc->disabled ? 0 : -1 ; // do not deduct if account is disabled
        return $count ? : false ;
    }

    private function insert() {
        return !$this->pq->set(1) 
                ? : $this->write($this->data(), 'add');
    }
    
    protected function data() {
        return (object) [
                    'name' => $this->name(),
                    'local-address' => $this->local_addr(),
                    'rate-limit' => $this->rate()->text ,
                    'parent-queue' => $this->pq->name(),
                    'address-list' => $this->addr_list(),
        ];
    }
    
    protected function addr_list(){
        global $conf ;
        return $this->svc->disabled 
                ? $conf->active_list 
                : $conf->disabled_list ;
    }


    protected function name(){
        global $conf ;
        return $this->svc->disabled 
                ? $conf->disabled_profile
                : $this->svc->plan_name();
    }
    
    protected function rate() {
        $rate = parent::rate();
        global $conf ;
        $r = $conf->disabled_rate;
        $disabled = (object)[
            'text' => $r.'M/'.$r.'M',
            'upload' => $r,
            'download' => $r,
        ];
        return $this->svc->disabled ? $disabled: $rate ;
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

    protected function exists($profile = false) {
        $name = $profile ? $profile : $this->name();
        return !$this->read('?name=' . $name) 
                ? false : ($this->read ? true :false);
    }

}
