<?php

class Data_Validate {

    private $data;
    private $status;
    private $read;

    public function __construct(&$data) {
        $this->data = $data;
        $this->status = (object) ['error' => false,'message' => 'ok'];
    }

    public function validate() {
        $this->get_attributes();
        return $this->check_values();
    }

    public function status() {
        return $this->status;
    }
    
    private function check_values() {
        $checks = ['device','user','mac', 'ip',];
        foreach ($checks as $type) {
            $check = 'check_' . $type;
            if (!$this->$check()) {
                return false;
            }
        }
        return true;
    }

    private function check_user() {
        global $conf;
        if (!isset($this->read[$conf->pppoe_user_attr]) &&
                !isset($this->read[$conf->mac_addr_attr])) {
            $this->set_error('ppp username or mac address not configured');
            return false;
        }
        return true ;
    }

    private function check_device() {
        global $conf;
        if (!isset($this->read[$conf->device_name_attr])) {
            $this->set_error('network device property not configured');
            return false;
        }
        return true ;
    }

    private function check_mac() {
        global $conf;
        if (isset($this->read[$conf->mac_addr_attr])) {
            if (!filter_var($this->read[$conf->mac_addr_attr],
                            FILTER_VALIDATE_MAC)) {
                $this->set_error('invalid mac address provided');
                return false;
            }
        }
        return true;
    }

    private function check_ip() {
        global $conf;
        if (isset($this->read[$conf->ip_addr_attr])) {
            if (!filter_var($this->read[$conf->ip_addr_attr],
                            FILTER_VALIDATE_IP)) {
                $this->set_error('invalid ip address provided');
                return false;
            }
        }
        return true;
    }

    private function get_attributes() {
        $this->read = [];
        $data = $this->data->extraData->entity->attributes;
        foreach ($data as $item) {
            $this->read[$item->key] = $item->value;
        }
    }

    private function set_error($msg, $obj = false) {
        $this->status->error = true;
        if ($obj) {
            $this->status->message = $this->result['!trap'][0]['message'];
        } else {
            $this->status->message = $msg;
        }
    }

}
