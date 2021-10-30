<?php

include_once 'mt_account.php';

class API_Routes {

    private $status;
    private $service ;
    private $module;

    public function __construct(&$service) {
        $this->service = $service;
        $this->status = (object) [
            'status' => 'ok',
            'message' => '',
            'error' => false,
        ];
        $this->exec();
    }

    private function exec() {
        $module = $this->select_device();
        if(!$module){
            $this->status->error = true ;
            $this->status->message = 'Could not find module for provided device';
            return ;
        }
        $this->module = new $module($this->service);
        $action = $this->service->action;
        $this->module->$action();
        $this->status = $this->module->status();
    }

    public function status() {
        return $this->status;
    }

    private function select_device() {
        $map = [
            'radius' => 'Radius_Account',
            'mikrotik' => 'MT_Account',
        ];
        $type = $this->service->device_type() ;
        return $type ? $map[$type] : false;
    }
  

}
