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
        if ($this->check_exists()) {
            $this->check_device_move();
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

    private function check_exists() {
        $db = new CS_SQLite();
        $this->data->utilFlag = false ;
        if (!$db->ifServiceIdExists($this->data->entityId)) {
            $this->data->changeType = 'insert';
            $this->data->utilFlag = true ;
            return false;
        }
        return true;
    }

    private function check_device_move() {
        global $conf;
        $savedName = $this->getSavedDeviceName(); // use saved name because of uisp bug
        $thisName = strtolower(
                $this->entity->{$conf->device_name_attr});
        if ($thisName != strtolower($savedName) ) {
            $this->before->{$conf->device_name_attr} = $savedName; // correct before entity
            $this->data->changeType = 'move';
        }
    }

    private function getSavedDeviceName() {
        $db = new CS_SQLite();
        $id = $this->data->entityId;
        return $db->selectDeviceNameByServiceId($id);
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
