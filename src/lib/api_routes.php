<?php

include_once 'mt.php';
include_once 'mt_account.php';
include_once 'mt_queue.php';
include_once 'mt_profile.php';
include_once 'mt_parent_queue.php';

class API_Routes {

    private $status;
    private $service ;
    private $module;
    private $entity ;
    private $before ;

    public function __construct(&$service) {
        $this->service = $service;
        $this->status = (object) [
            'status' => 'ok',
            'message' => '',
            'error' => false,
        ];
        $module = $this->device_selector();
        
        if(!$module){
            $this->status->error = true ;
            $this->status->message = 'Could not find module for provided device';
            return ;
        }
        
        $this->module = new $module($this->service);
        $this->exec();
    }

    private function exec() {
        $action = $this->service->action;
        $this->module->$action();
        $this->status = $this->module->status();
    }

    public function status() {
        return $this->status;
    }

    private function device_selector() {
        $map = [
            'radius' => 'Radius_Account',
            'mikrotik' => 'MT_Account',
        ];
        $type = $this->service->device_type() ;
        return $type ? $map[$type] : false;
    }
  

}
