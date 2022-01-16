<?php

include_once 'service_base.php';

class Service_Attributes extends Service_Base
{

    public $action = 'edit';
    public $pppoe ;
    public $unsuspend = false;
    public $move = false;
    protected $auto ;

    protected function init(): void
    {
        parent::init();
        $this->check_device();
        $this->check_attributes();
        $this->set_action();
    }

    protected function check_attributes(): bool
    {
        if(!($this->check_mac() || $this->check_username())){
            $this->setErr('no valid username or mac address provided for service');
            return false ;
        }
        return $this->check_ip();
    }

    protected function check_username(): bool
    {
        $username = $this->get_attribute_value($this->conf->pppoe_user_attr);
        $password = $this->get_attribute_value($this->conf->pppoe_pass_attr);
        $auto = $this->conf->auto_ppp_user ?? false ;
        if($auto || $username){
            $this->pppoe = true ;
            $this->auto = !($username && $password);
        }
        return $auto || $username;
    }

    protected function check_ip(): bool
    {
        $ip = $this->get_attribute_value($this->conf->ip_addr_attr);
        if ($ip && !filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->setErr('Invalid ip address was provided for the account');
            return false ;
        }
        return true;
    }

    protected function check_mac(): bool
    {
        $mac = $this->get_attribute_value($this->conf->mac_addr_attr);
        if ($mac && filter_var($mac, FILTER_VALIDATE_MAC)) {
            $this->pppoe = false;
            return true ;
        }
        return false ;
    }


    protected function check_device(): void
    {
        $device = $this->get_attribute_value($this->conf->device_name_attr);
        if(!$device || !$this->db()->selectDeviceIdByDeviceName($device)){
            $this->setErr('no device or unknown device name specified for service');
        }
    }

    protected function check_username_change(): void
    {
        $mac = $this->get_attribute_value($this->conf->mac_addr_attr);
        $old_mac = $this->get_attribute_value($this->conf->mac_addr_attr,'before');
        if($old_mac && $mac != $old_mac){
            $this->data->changeType= 'move';
            return ;
        }
        $user = $this->get_attribute_value($this->conf->pppoe_user_attr);
        $old_user = $this->get_attribute_value($this->conf->pppoe_user_attr,'before');
        if($old_user &&  $user != $old_user){
            $this->data->changeType = 'move';
        }
    }

    protected function set_action(): void
    {
        $change = 'set_' . $this->data->changeType;
        if (in_array($this->data->changeType, ['end', 'insert', 'edit', 'unsuspend'])) {
            $this->$change();
        }
        $this->action = $this->data->changeType;
    }

    protected function set_edit(): void
    {
        $device = $this->get_attribute_value($this->conf->device_name_attr);
        $old_device = $this->get_attribute_value($this->conf->device_name_attr,'before');
        if ($old_device && strtolower($device) != strtolower($old_device)) {
            $this->data->changeType = 'move';
            return ;
        }
        $this->check_username_change();
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
