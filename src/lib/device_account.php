<?php

include_once 'device_base.php';
include_once 'app_ipv4.php';
include_once 'app_uisp.php';

class Device_Account extends Device_Base {

    protected function init() {
        global $conf;
        parent::init();
        if (is_object($this->data)) {
            $obj = &$this->{$this->data->actionObj};
            $attributes = [$conf->pppoe_user_attr, $conf->mac_addr_attr];
            foreach ($attributes as $attribute) {
                if (!property_exists($obj, $attribute)) { // create unused attributes with null values
                    $obj->{$attribute} = null;
                }
            }
        }
    }

    protected function ip_get($device = false) {
        global $conf;
        $addr = false;
        if (isset($this->data->extraData->entity->{$conf->ip_addr_attr})) {
            $addr = $this->data->extraData->entity->{$conf->ip_addr_attr};
            if(filter_var($addr,FILTER_VALIDATE_IP)){
                $this->data->ip = $addr ;
                return true ;
            }
        }
        if (in_array($this->data->changeType, ['insert', 'move', 'upgrade'])) {
            $ip = new CS_IPv4();
            $addr = $ip->assign($device);  // acquire new address
        } else {
            $db = new CS_SQLite();
            $addr = $db->selectIpAddressByServiceId($this->before->id); //reuse old address
        }
        if (!$addr) {
            $this->set_error('no valid ip address to assign');
            return false;
        }
        $this->data->ip = $addr;
        return true;
    }

    protected function save() {
        $data = $this->save_data();
        $db = new CS_SQLite();
        return $db->{$this->data->changeType}($data);
    }

    protected function clear() {
        $db = new CS_SQLite();
        $db->delete($this->{$this->data->actionObj}->id);
    }

    protected function save_data() {
        $data = (object) array(
                    'id' => $this->entity->id,
                    'planId' => $this->entity->servicePlanId,
                    'clientId' => $this->entity->clientId,
                    'address' => $this->data->ip,
                    'status' => $this->entity->status,
                    'device' => $this->device_id(),
        );
        return $data;
    }

    protected function device_id() {
        global $conf;
        $name = $this->entity->{$conf->device_name_attr};
        $db = new CS_SQLite();
        return $db->selectDeviceIdByDeviceName($name);
    }

    protected function device() {
        global $conf;
        $obj = $this->{$this->data->actionObj};
        return $obj->{$conf->device_name_attr};
    }

    protected function insertId() {
        return false;
    }

    protected function is_pppoe() {
        global $conf;
        $obj = &$this->{$this->data->actionObj};
        return property_exists($obj, $conf->pppoe_user_attr) && $obj->{$conf->pppoe_user_attr} ? true : false;
    }

    protected function is_disabled() {
        $obj = &$this->{$this->data->actionObj};
        return $obj->status != 1 ? true : false;
    }

}
