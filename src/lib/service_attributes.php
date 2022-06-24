<?php

include_once 'service_base.php';

class Service_Attributes extends Service_Base
{

    public $action = 'edit';
    public $pppoe;
    public $unsuspend = false;
    public $move = false;
    public $accountType = 1;  // 0 - dhcp , 1 - ppp , 2 - hotspot
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
        if (!$this->check_username()) {
            $this->setErr('no valid username or mac address provided for service');
            return false;
        }
        return $this->check_ip();
    }

    protected function check_username(): bool
    {
        if($this->check_mac()) return true ;
        $username = $this->get_value($this->conf->pppoe_user_attr ?? null);
        $password = $this->get_value($this->conf->pppoe_pass_attr ?? null);
        $hs = $this->get_value($this->conf->hs_attr ?? 'hotspot');
        $hsauto = $this->conf->auto_hs_user ?? false;
        if($hs && ($hsauto || $username)){
            $this->accountType = 2;
            $this->auto = !($username && $password) ;
            return true ;
        }
        $pauto = $this->conf->auto_ppp_user ?? false;
        if ($pauto || $username) {
            $this->accountType = 1;
            $this->auto = !($username && $password) ;
            return true ;
        }
        return false ;
    }

    protected function check_username_change(): void
    {
        $mac = $this->get_value($this->conf->mac_addr_attr);
        $old_mac = $this->get_value($this->conf->mac_addr_attr, 'before');
        if ($old_mac && $mac != $old_mac) {
            $this->data->changeType = 'rename';
            return;
        }
        $user = $this->get_value($this->conf->pppoe_user_attr);
        $old_user = $this->get_value($this->conf->pppoe_user_attr, 'before');
        if ($old_user && $user != $old_user) {
            $this->data->changeType = 'rename';
        }
    }

    protected function check_ip(): bool
    {
        $ip = $this->get_value($this->conf->ip_addr_attr);
        if ($ip && !filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->setErr('Invalid ip address was provided for the account');
            return false;
        }
        return true;
    }

    protected function check_mac(): bool
    {
        $mac = $this->get_value($this->conf->mac_addr_attr);
        if ($mac && filter_var($mac, FILTER_VALIDATE_MAC)) {
            $this->accountType = 0 ;
            return true;
        }
        return false;
    }


    protected function check_device(): void
    {
        $name = $this->get_value($this->conf->device_name_attr);
        $device = $this->db()->selectDeviceByDeviceName($name) ?? null ;
        if (!($name && $device)) {
            $this->setErr('no device or unknown device name specified for service');
        }
    }

    protected function set_action(): void
    {
        $change = $this->data->changeType ?? null ;
        if (in_array($change, ['end','edit', 'unsuspend'])) {
            $this->{'set_'.$change}();
        }
        $this->action = $this->data->changeType ??  null;
    }

    protected function set_edit(): void
    {
        $device = $this->get_value($this->conf->device_name_attr);
        $old_device = $this->get_value($this->conf->device_name_attr, 'before');
        $status = $this->entity->status ?? -1 ;
        if ($old_device && strtolower($device) != strtolower($old_device)) {
            $this->data->changeType = 'move';
        } elseif (in_array($status,[2,5])){
            $this->data->changeType = 'delete';
        }
        else {
            $this->check_username_change();
        }
    }

    protected function set_insert(): void
    {
        $newId = $this->data->extraData->entity->id ?? null ;
        $oldId = $this->data->extraData->entityBeforeEdit->id ?? null ;
        if ($oldId && $newId != $oldId ) {
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
