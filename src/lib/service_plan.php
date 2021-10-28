<?php

include_once 'service_attributes.php';

class Service_Plan extends Service_Attributes {
    
    protected $plan ;
    public $count ;


    public function __construct(&$data) {
        parent::__construct($data);
        $this->load_plan();
    }
    
    public function rate() {
        $d = $this->entity->downloadSpeed;
        $u = $this->entity->uploadSpeed;
        return (object) [
                    'text' => $u . 'M/' . $d . "M",
                    'download' => $d,
                    'upload' => $u,
        ];
    }
    
    public function plan_name(){
        return $this->entity->servicePlanName ;
    }
    
    public function plan_id(){
        return $this->entity->servicePlanId ;
    }
    
    public function plan_children() {
        $planId = $this->entity->servicePlanId;
        $deviceId = $this->dev->id;
        $db = new API_SQLite();
        $children = $db->countDeviceServicesByPlanId($planId, $deviceId);
        $children += $this->count;
        return $children > 0 ? $children: false;
    }
    
    public function plan_rate() {
        $shares = $this->plan_shares();
        $u = $this->rate()->upload * $shares;
        $d = $this->rate()->download * $shares;
        return (object)[
            'text' => $u.'M/'.$d.'M',
            'upload' => $u,
            'download' => $d,
        ];
    }
    
    protected function plan_shares() { // calculates the number of contention shares
        $ratio = $this->plan['ratio'];
        $children = $this->plan_children();
        $shares = intdiv($children, $ratio);
        return ($children % $ratio) > 0 ? ++$shares : $shares; // go figure :-)
    }

    protected function load_plan(){
        $planId = $this->entity->servicePlanId ;
        $this->plan = (new Plans($planId))->list()[$planId];
        return $this->plan ? true : false ;
    }

}
