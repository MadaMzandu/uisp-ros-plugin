<?php

Class Data_Sanitize {

    private $data;
    private $entity;
    private $before;

    public function __construct(&$data) {
        $this->data = $data;
        if (!in_array($data->changeType, ['admin'])) {
            $this->entity = &$this->data->extraData->entity;
            $this->before = &$this->data->extraData->entityBeforeEdit;
        }
    }

    public function sanitize() {
        $this->set_custom_attr();
        $this->set_client();
        $sanitize = 'sanitize_' . $this->data->changeType;
        $this->$sanitize();
    }

    private function set_client() {
        $id = $this->data->extraData->entity->clientId;
        $name = 'client' . $id;
        $client = (array) (new CS_UISP())->request('/clients/' . $id);
        if ($client) {
            $name = $client['firstName'] . ' ' . $client['lastName'];
            if (isset($client['companyName'])) {
                $name = $client['companyName'];
            }
        }
        $this->data->clientName = $name;
    }

    private function sanitize_insert() {
        if (isset($this->data->extraData->entityBeforeEdit)) {
            $this->data->changeType = 'move';
        }
    }

    private function sanitize_end() {
        $this->data->changeType = 'delete';
    }

    private function sanitize_edit() {
       if(!$this->has_record()){
           $this->data->changeType = 'insert_fix';
           return;
       }
       if($this->has_moved()){
           $this->data->changeType = 'move';
       }
    }

    private function sanitize_unsuspend() {
        $this->sanitize_suspend();
    }

    private function sanitize_suspend() {
        $this->data->unsuspendFlag = false;
        if ($this->data->changeType == 'unsuspend') {
            $this->data->unsuspendFlag = true;
            $this->data->changeType = 'suspend';
        }
    }

    private function has_record() {
       return (new CS_SQLite())->ifServiceIdExists($this->entity->id);
    }

    private function has_moved() {
        global $conf;
        $savedName = (new CS_SQLite())
            ->selectDeviceNameByServiceId($this->entity->id);// use saved name because of uisp bug
        $thisName = strtolower(
                $this->entity->{$conf->device_name_attr});
        if ($thisName!= strtolower($savedName) ) {
            $this->before->{$conf->device_name_attr} = $savedName; // correct before entity
            return true ;
        }
        false ;
    }

    private function set_custom_attr() {
        $this->data->actionObj = 'entity';
        foreach (['entity', 'entityBeforeEdit'] as $obj) {
            if (!isset($this->data->extraData->$obj) ||
                    !isset($this->data->extraData->$obj->attributes)) {
                continue;
            }
            foreach ($this->data->extraData->$obj->attributes as $attr) {
                $this->data->extraData->$obj->{$attr->key} = $attr->value;
            }
        }
    }

}
