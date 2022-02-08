<?php

include_once 'service_base.php';

class Service_Attributes extends Service_Base
{

    public $action = 'edit';
    public $pppoe;
    public $unsuspend = false;
    public $move = false;
    protected $auto;

    protected function init(): void
    {
        parent::init();
        $this->check_device();
        $this->check_attributes();
        $this->check_changes();
        $this->check_status();
        $this->set_action();
    }

    protected function check_status()
    {
        $status = $this->entity->status ?? -1;
        if (in_array($status, [0, 4, 6, 7])) {
            $this->setErr("Deferred update - service status: " . $status);
            $this->status->error = false;
        }
    }

    protected function check_changes()
    {
        if ($this->data->changeType != 'edit') {
            return;
        }
        $valid = $this->changes();
        $invalid = $this->changes(1);
        if ($invalid && !$valid) {
            $this->setErr("No changes to apply");
            $this->status->error = false;
        }
    }

    protected function changes($type = 0)
    {
        $keys = [
            ['id', 'status', 'servicePlanId', 'attributes'], //valid
            ['hasOutage'] //invalid
        ];
        $changes = false;
        foreach ($keys[$type] as $key) {
            $new = $this->entity->{$key} ?? null;
            $old = $this->before->{$key} ?? null;
            $changes |= $new != $old;
        }
        return $changes;
    }

    protected function check_attributes(): bool
    {
        if (!($this->check_mac() || $this->check_username())) {
            $this->setErr('no valid username or mac address provided for service');
            return false;
        }
        return $this->check_ip();
    }

    protected function check_username(): bool
    {
        $username = $this->get_attribute_value($this->conf->pppoe_user_attr);
        $password = $this->get_attribute_value($this->conf->pppoe_pass_attr);
        $auto = $this->conf->auto_ppp_user ?? false;
        if ($auto || $username) {
            $this->pppoe = true;
            $this->auto = !($username && $password);
        }
        return $auto || $username;
    }

    protected function check_username_change(): void
    {
        $mac = $this->get_attribute_value($this->conf->mac_addr_attr);
        $old_mac = $this->get_attribute_value($this->conf->mac_addr_attr, 'before');
        if ($old_mac && $mac != $old_mac) {
            $this->data->changeType = 'rename';
            return;
        }
        $user = $this->get_attribute_value($this->conf->pppoe_user_attr);
        $old_user = $this->get_attribute_value($this->conf->pppoe_user_attr, 'before');
        if ($old_user && $user != $old_user) {
            $this->data->changeType = 'rename';
        }
    }

    protected function check_ip(): bool
    {
        $ip = $this->get_attribute_value($this->conf->ip_addr_attr);
        if ($ip && !filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->setErr('Invalid ip address was provided for the account');
            return false;
        }
        return true;
    }

    protected function check_mac(): bool
    {
        $mac = $this->get_attribute_value($this->conf->mac_addr_attr);
        if ($mac && filter_var($mac, FILTER_VALIDATE_MAC)) {
            $this->pppoe = false;
            return true;
        }
        return false;
    }


    protected function check_device(): void
    {
        $device = $this->get_attribute_value($this->conf->device_name_attr);
        if (!$device || !$this->db()->selectDeviceIdByDeviceName($device)) {
            $this->setErr('no device or unknown device name specified for service');
        }
    }

    protected function set_action(): void
    {
        $change = $this->data->changeType ?? null ;
        if (in_array($change, ['end', 'insert', 'edit', 'unsuspend'])) {
            $this->{'set_'.$change}();
        }
        $this->action = $this->data->changeType ??  null;
    }

    protected function set_edit(): void
    {
        $device = $this->get_attribute_value($this->conf->device_name_attr);
        $old_device = $this->get_attribute_value($this->conf->device_name_attr, 'before');
        if ($old_device && strtolower($device) != strtolower($old_device)) {
            $this->data->changeType = 'move';
        } else {
            $this->check_username_change();
        }
    }

    protected function set_insert(): void
    {
        if (isset($this->data->extraData->entityBeforeEdit)) {
            $this->data->changeType = 'move';
        }
    }

    protected function set_end(): void
    {
        $this->data->changeType = 'delete';
    }

    protected function set_unsuspend(): void
    {
        $this->data->changeType = 'suspend';
        $this->unsuspend = true;
    }

}
