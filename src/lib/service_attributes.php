<?php

include_once 'service_base.php';

class Service_Attributes extends Service_Base
{

    public $action = 'edit';
    public $pppoe = true;
    public $unsuspend = false;
    public $disabled = false;
    public $move = false;
    public $staticIPClear = false;

    protected function init(): void
    {
        parent::init();
        $this->set_attributes();
        $this->check_device();
        $this->set_status();
        $this->check_attributes();
        $this->check_ip_clear();
        $this->set_action();
    }

    protected function set_attributes()
    {
        $objects = ['entity', 'before'];
        foreach ($objects as $object) {
            if (!isset($this->$object->attributes)) {
                continue;
            }
            foreach ($this->$object->attributes as $attribute) {
                $this->$object->{$attribute->key} = $attribute->value;
            }
        }
    }

    protected function set_status()
    {
        $id = $this->move ? $this->before->id : $this->entity->id;
        $this->disabled = $this->entity->status != 1;
        $this->exists = (bool)$this->db()->ifServiceIdExists($id);
    }

    protected function check_attributes()
    {
        $ip = $this->attribute($this->conf->ip_addr_attr);
        $mac = $this->attribute($this->conf->mac_addr_attr);
        $username = $this->attribute($this->conf->pppoe_user_attr);
        if(!($username || $mac)){
            $this->setErr('no valid username or mac address provided for service');
            return;
        }
        if ($ip && !filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->setErr('Invalid ip address was provided for the account');
            return;
        }
        if ($mac && filter_var($mac, FILTER_VALIDATE_MAC)) {
            $this->pppoe = false;
        }
    }

    protected function check_ip_clear(): void
    {
        $ip = $this->attribute($this->conf->ip_addr_attr);
        $old_ip = $this->attribute($this->conf->ip_addr_attr,'before');
        if ($old_ip && !$ip) {
            $this->staticIPClear = true;
        }
    }

    protected function check_device(): void
    {
        $device = $this->attribute($this->conf->device_name_attr);
        if(!$device || !$this->db()->selectDeviceIdByDeviceName($device)){
            $this->setErr('no device or unknown device name specified for service');
        }
    }

    protected function check_username_change(): void
    {
        $mac = $this->attribute($this->conf->mac_addr_attr);
        $old_mac = $this->attribute($this->conf->mac_addr_attr,'before');
        if($mac && $old_mac && $mac != $old_mac){
            $this->data->changeType= 'move';
            return ;
        }
        $user = $this->attribute($this->conf->pppoe_user_attr);
        $old_user = $this->attribute($this->conf->pppoe_user_attr,'before');
        if($user && $old_user && $user != $old_user){
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
        $lastDevice = strtolower($this->entity->{$this->conf->device_name_attr});
        $thisDevice = strtolower($this->before->{$this->conf->device_name_attr});
        if ($lastDevice != $thisDevice) {
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

    protected function attribute($key,$entity='entity'): ?string
    { //returns an attribute value
        foreach($this->$entity->attributes as $attribute){
            if($key == $attribute->key){
                return $attribute->value;
            }
        }
        return null;
    }

}
