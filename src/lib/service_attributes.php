<?php

include_once 'service_base.php';

class Service_Attributes extends Service_Base {
    
    public $valid = true;
    public $action ;
    public $pppoe = false ;
    public $fix = false ;
    public $exists = true;
    public $unsuspend = false ;
    public $disabled = false ;
    protected $rec ;
    

    

    protected function init() {
        parent::init();
        $this->set_shortcuts();
        $this->set_attributes();
        $this->set_status();
        $this->check_attributes();
        $this->load_record();
        $this->set_action();
    }
    
    public function record(){
        return $this->rec ;
    }
    
    protected function load_record(){
        $id = isset($this->before->id) ? $this->before->id : $this->entity->id;
        $this->rec = $this->db->selectServiceById($id);
        $this->exists = (array)$this->rec ? true : false ;
    }
    
    
    protected function set_status(){
        $this->disabled = $this->entity->status != 1 
                ? true :false;
    }

    protected function set_shortcuts() {
        $this->entity = isset($this->data->extraData->entity) 
                ? $this->data->extraData->entity 
                : (object) [];
        $this->before = isset($this->data->extraData->entityBeforeEdit) 
                ? $this->data->extraData->entityBeforeEdit 
                : (object) [];
    }

    protected function set_attributes() {
        $objects = ['entity', 'before'];
        foreach ($objects as $object) {
            if(!isset($this->$object->attributes)){
                continue;
            }
            foreach ($this->$object->attributes as $attribute) {
                $this->$object->{$attribute->key} = $attribute->value;
            }
        }
    }
    
    protected function check_attributes(){
        if(isset($this->entity->{$this->conf->ip_addr_attr}) 
        && !filter_var($this->entity->{$this->conf->ip_addr_attr},
                FILTER_VALIDATE_IP)){
            $this->setErr('Invalid ip address was provided for account');
            return ;
        }
        if(isset($this->entity->{$this->conf->mac_addr_attr}) 
        && filter_var($this->entity->{$this->conf->mac_addr_attr},
                FILTER_VALIDATE_MAC) ){
            return ;
        }
        if(isset($this->entity->{$this->conf->pppoe_user_attr})){
            $this->pppoe = true ;
            return ;
        }
        $this->setErr('No valid pppoe username or dhcp mac address were provided');
    }
    
    protected function set_action(){
        $change = 'set_'.$this->data->changeType;
        if(in_array($this->data->changeType,['end','insert','edit','unsuspend'])){
            $this->$change();
        }
        $this->action = $this->data->changeType ;
    }
    
    protected function set_edit() {
        if (!$this->exists) {
            $this->data->changeType = 'insert_fix';
            //$this->fix = true ;
            return ;
        }
        $lastDevice = strtolower($this->entity->{$this->conf->device_name_attr});
        $thisDevice = strtolower($this->before->{$this->conf->device_name_attr});
        if($lastDevice != $thisDevice){
            $this->data->changeType = 'move';
        }
    }
    
    protected function set_insert() {
        if (isset($this->data->extraData->entityBeforeEdit)) {
            $this->data->changeType = 'move';
        }
    }

    protected function set_end() {
        $this->data->changeType = 'delete';
    }
    
    protected function set_unsuspend(){
        $this->data->changeType = 'suspend';
        $this->unsuspend = true ;
    }

}
