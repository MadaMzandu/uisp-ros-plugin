<?php

class MT_Parent_Queue extends MT {

    private $plan ;
    private $count = 0; // +/- one child
    private $devices ;

    public function __construct(&$data) {
        parent::__construct($data);
        $this->path = '/queue/simple/';
    }

    public function set($count = 0) {
        $this->count = $count;
        if ($count >0 && !$this->exists()) {
            return $this->create();
        }
        if ($count < 0 && !$this->has_children()) {
            return $this->deleteQueue();
        }
        return $this->editQueue();
    }
    
    public function update($id){
        global $conf ;
        if(!$this->read_devices()){
            $this->set_error('parent queue module failed to read devices');
            return;
        }
        $this->data = (object)[];
        $this->data->actionObj = 'entity';
        $this->entity = (object)[];
        $this->entity->servicePlanId = $id;
        foreach($this->devices as $device){
            $this->entity->{$conf->device_name_attr} = $device['name'];
            if(!$this->has_children()){
                continue;
            }
            $this->editQueue();
        }
        
    }
    
    private function read_devices(){
        $this->devices = (new CS_SQLite())
                ->selectAllFromTable('devices') ?? [];
        return $this->devices ? true : false ;
    }

    private function read_plan() {
        $planId = $this->entity->servicePlanId ;
        $this->plan = (new Plans($planId))->list()[$planId];
        return $this->plan ? true : false ;
    }

    public function name() {
        return $this->prefix() . "-parent";
    }

    private function prefix() {
        return "servicePlan-" . $this->entity->servicePlanId;
    }

    private function editQueue() {
        $rate = $this->rate();
        if (!$rate || !$this->exists()) {
            return false ;
        }
        $data = (object) array(
                    'max-limit' => $rate,
                    'limit-at' => $rate,
                    'name' => $this->name(),
                    '.id' => $this->name(),
        );
        return $this->write($data);
    }

    protected function rate() {
        if (!$this->read_plan()) {
            $this->set_error('not able to read service plans');
            return false;
        }
        $shares = $this->shares();
        $upload = $this->plan['uploadSpeed'] * $shares;
        $download = $this->plan['downloadSpeed'] * $shares;
        return $upload.'M/'.$download.'M';
    }
    
    private function shares(){ // calculates the number of contention shares
        $ratio = $this->plan['ratio'];
        $children = $this->has_children();
        $shares = intdiv($children,$ratio);
        return ($children % $ratio) > 0 ? ++$shares : $shares ; // go figure :-)
    }

    private function deleteQueue() {
        $data = (object) array('.id' => $this->name());
        if ($this->child_delete()) {
            return $this->write($data, 'remove');
        }
    }

    private function has_children() {
        global $conf ;
        $planId = $this->entity->servicePlanId;
        $deviceName = strtolower($this->entity->{$conf->device_name_attr});
        $db = new CS_SQLite();
        $children = $db->countDeviceServicesByPlanId($planId, $deviceName) ;
        $children += $this->count;
        return $children > 0 ? $children : false ;
    }

    private function create() {
        $data = (object) array(
                    'name' => $this->name(),
                    'max-limit' => $this->rate(),
                    'limit-at' => $this->rate(),
                    'queue' => 'pcq-upload-default/'
                    . 'pcq-download-default',
                    'comment' => $this->comment(),
        );
        if ($this->write($data, 'add')) {
            return $this->child_create();
        }
        return !$this->write($data,'add') ? : $this->child_create();
    }

    private function child_create() {
        $data = (object) array(
                    'name' => $this->prefix() . '-child',
                    'packet-marks' => '1stchild',
                    'parent' => $this->name(),
                    'max-limit' => '1M/1M',
                    'limit-at' => '1M/1M',
                    'comment' => $this->comment(),
        );
        return $this->write($data, 'add') ;
    }

    private function child_delete() {
        $data = (object) array(
                    '.id' => $this->prefix() . '-child',
        );
        return $this->write($data, 'remove');
    }

    protected function comment() {
        return 'do not delete';
    }

    protected function exists() {
        $this->read('?name=' . $this->name());
        if ($this->read) {
            return true;
        }
        return false;
    }
   
}
    