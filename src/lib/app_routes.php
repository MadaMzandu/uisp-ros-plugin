<?php

include_once 'device_base.php';
include_once 'device_account.php';
include_once 'device.php';
include_once 'mt.php';
include_once 'mt_account.php';
include_once 'mt_queue.php';
include_once 'mt_profile.php';
include_once 'mt_parent_queue.php';
include_once 'mt_firewall.php';
include_once 'radius_account.php';
//this class is responsible for selecting and executing
// the provisioning module

class Routes {

    private $status;
    private $data;
    private $module;
    private $entity ;
    private $before ;

    public function __construct(&$data) {
        $this->data = $data;
        $this->status = (object) [
            'status' => 'ok',
            'message' => '',
            'error' => false,
        ];
        if (!in_array($data->changeType, ['admin'])) {
            $this->entity = &$this->data->extraData->entity;
            $this->before = &$this->data->extraData->entityBeforeEdit;
        }
        $module = $this->module_selector();
        if(!$module){
            $this->status->error = true ;
            $this->status->message = 'Failed to retrieve device';
            return ;
        }
        $this->module = new $module($data);
        $this->exec();
    }

    private function exec() {
        $action = $this->data->changeType;
        $this->module->$action();
        $this->status = $this->module->status();
    }

    public function status() {
        return $this->status;
    }

    private function module_selector() {
        $map = [
            'radius' => 'Radius_Account',
            'mikrotik' => 'MT_Account',
        ];
        $type = $this->device_type();
        return $type ? $map[$type] : false;
    }

    private function device_type() {
        global $conf;
        $device = (new CS_SQLite())->selectDeviceByDeviceName
                ($this->data->extraData->entity->{$conf->device_name_attr});
                
        return (array)$device ? $device->type : false;
    }

}
