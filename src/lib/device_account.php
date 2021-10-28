<?php

//include_once 'app_ipv4.php';
//include_once 'app_uisp.php';

class Device_Account extends Device_Template {

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

    protected function fix() {
        global $conf;
        $clientId = $this->data->extraData->entity->clientId;
        $id = $this->data->entityId;
        $this->trim();  // trim after aquiring data
        $u = new API_Unms();
        if ($u->request('/clients/services/' . $id . '/end', 'PATCH')) {//end service
            $u->request('/clients/services/' . $id, 'DELETE'); //delete service
            sleep($conf->unsuspend_fix_wait);
            $u->request('/clients/' . $clientId . '/services', 'POST', $this->entity); //recreate service
        }
    }

    protected function trim() {
        $vars = $this->trim_fields();
        foreach ($vars as $var) {
            unset($this->entity->$var);
        }
        $this->trim_attrbs();
    }

    protected function trim_fields() {
        global $conf;
        return ['id', 'clientId', 'status', 'servicePlanId', 'invoicingStart',
            'hasIndividualPrice', 'totalPrice', 'currencyCode', 'servicePlanName',
            'servicePlanPrice', 'servicePlanType', 'downloadSpeed', 'uploadSpeed',
            'hasOutage', 'lastInvoicedDate', 'suspensionReasonId', 'serviceChangeRequestId',
            'downloadSpeedOverride', 'uploadSpeedOverride', 'trafficShapingOverrideEnd',
            'trafficShapingOverrideEnabled', $conf->mac_addr_attr, $conf->device_name_attr,
            $conf->pppoe_user_attr, $conf->pppoe_pass_attr, 'unmsClientSiteId',
            $conf->ip_addr_attr, 'clientName'];
    }

    protected function trim_attrbs() {
        $vars = ["id", "serviceId", "name", "key", "clientZoneVisible"];
        foreach ($this->entity->attributes as $attrb) {
            foreach ($vars as $var) {
                unset($attrb->$var);
            }
        }
    }

    protected function ip_get($device = false) {
        global $conf;
        $addr = false;
        if (property_exists($this->data->extraData->entity, $conf->ip_addr_attr)) {
            if ($this->data->extraData->entity->{$conf->ip_addr_attr}) {
                $addr = $this->data->extraData->entity->{$conf->ip_addr_attr};
            } //user provided address
        }
        if (in_array($this->data->changeType, ['insert', 'move', 'upgrade'])) {
            $ip = new API_IPv4();
            $addr = $ip->assign($device);  // acquire new address
        } else {
            $db = new API_SQLite();
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
        $db = new API_SQLite();
        return $db->{$this->data->changeType}($data);
    }

    protected function clear() {
        $db = new API_SQLite();
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
        $db = new API_SQLite();
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
