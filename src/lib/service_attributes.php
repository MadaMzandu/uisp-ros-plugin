<?php

include_once 'service_base.php';

class Service_Attributes extends Service_Base
{

    public $action = 'edit';
    public $pppoe = true;
    public $exists = false;
    public $unsuspend = false;
    public $disabled = false;
    public $move = false;
    public $staticIPClear = false;


    public function change_type($value)
    {
        $this->data->changeType = $value;
    }

    protected function init()
    {
        parent::init();
        $this->set_attributes();
        $this->set_status();
        $this->check_attributes();
        $this->check_ip_clear();
        $this->check_exists();
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
        if (isset($this->entity->{$this->conf->ip_addr_attr})
            && !filter_var($this->entity->{$this->conf->ip_addr_attr},
                FILTER_VALIDATE_IP)) {
            $this->setErr('Invalid ip address was provided for the account');
            return;
        }
        if (isset($this->entity->{$this->conf->mac_addr_attr})
            && filter_var($this->entity->{$this->conf->mac_addr_attr},
                FILTER_VALIDATE_MAC)) {
            $this->pppoe = false;
            return;
        }
        if (isset($this->entity->{$this->conf->pppoe_user_attr})) {
            return;
        }
        $this->setErr('No valid pppoe username or dhcp mac address were provided');
    }

    protected function check_ip_clear()
    {
        if (isset($this->before->{$this->conf->ip_addr_attr})
            && !isset($this->entity->{$this->conf->ip_addr_attr})) {
            $this->staticIPClear = true;
        }
    }

    protected function check_exists()
    {
        $this->exists = (bool)$this->db()
            ->ifServiceIdExists($this->entity->id);
    }

    protected function set_action()
    {
        $change = 'set_' . $this->data->changeType;
        if (in_array($this->data->changeType, ['end', 'insert', 'edit', 'unsuspend'])) {
            $this->$change();
        }
        $this->action = $this->data->changeType;
    }

    protected function set_edit()
    {
        $lastDevice = strtolower($this->entity->{$this->conf->device_name_attr});
        $thisDevice = strtolower($this->before->{$this->conf->device_name_attr});
        if ($lastDevice != $thisDevice) {
            $this->data->changeType = 'move';
        }
    }

    protected function set_insert()
    {
        if (isset($this->data->extraData->entityBeforeEdit)) {
            $this->data->changeType = 'move';
        }
    }

    protected function set_end()
    {
        $this->data->changeType = 'delete';
    }

    protected function set_unsuspend()
    {
        $this->data->changeType = 'suspend';
        $this->unsuspend = true;
    }

}
