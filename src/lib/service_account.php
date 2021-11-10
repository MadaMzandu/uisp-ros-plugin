<?php
include_once 'service_plan.php';
include_once 'service_attributes.php';
include_once 'service_client.php';
include_once 'api_ipv4.php';
include_once '_temp.php';

class Service_Account extends Service_Attributes
{

    public $plan ;
    public $client ;
    public $ip;

    public $device_index = 0; // for iterating devices

    protected function init(): void
    {
        parent::init();
        $this->plan = new Service_Plan($this->data);
        $this->client = new Service_Client($this->data);
    }

    public function device()
    {
        return $this->ready
            ? $this->get_device()
            : $this->get_next_device();
    }

    protected function get_device()
    {
        $entity = $this->move ? 'before' : 'entity';
        $name = $this->$entity->{$this->conf->device_name_attr};
        $dev = $this->db()->selectDeviceByDeviceName($name);
        if (!(array)$dev) {
            $this->setErr('the specified device was not found');
            return false;
        }
        return $dev;
    }

    protected function get_next_device()
    {
        $devices = $this->db()->selectAllFromTable('devices') ?? [];
        $length = sizeof($devices);
        if($this->device_index < $length){
            $device = $devices[$this->device_index++];
            if($this->device_index >= $length){
                $this->device_index = -1;
            }
            return (object) $device;
        }
    }

    public function username(): ?string
    {
        $entity = $this->move ? 'before' : 'entity';
        return $this->pppoe
            ? $this->$entity->{$this->conf->pppoe_user_attr}
            : null;
    }

    public function password(): ?string
    {
        $entity = $this->move ? 'before' : 'entity';
        return $this->pppoe
            ? $this->$entity->{$this->conf->pppoe_pass_attr}
            : null;
    }

    public function mac(): ?string
    {
        $entity = $this->move ? 'before' : 'entity';
        return !$this->pppoe
            ? $this->$entity->{$this->conf->mac_addr_attr}
            : null;
    }

    public function mt_account_id()
    {
        $id = $this->move ? $this->before->id : $this->entity->id;
        return $this->db()->selectServiceMikrotikIdByServiceId($id);
    }

    public function mt_queue_id()
    {
        $id = $this->move ? $this->before->id : $this->entity->id;
        return $this->db()->selectQueueMikrotikIdByServiceId($id);
    }

    public function save($data)
    {
        $save = $this->data($data);
        $done = $this->exists
            ? $this->db()->edit((object)$save)
            : $this->db()->insert((object)$save);
        if (!$done) {
            $this->setErr('failed to write changes to cache');
        }
        return $done;
    }

    protected function data($data)
    {
        $save = [
            'id' => $this->entity->id,
            'planId' => $this->entity->servicePlanId,
            'clientId' => $this->entity->clientId,
            'address' => $this->ip(),
            'status' => $this->entity->status,
            'device' => $this->get_device()->id
        ];
        foreach (array_keys($data) as $key) {
            $save[$key] = $data[$key] ?? null;
        }
        return $save;
    }

    public function ip()
    {
        if (isset($this->entity->{$this->conf->ip_addr_attr})) {
            return $this->entity->{$this->conf->ip_addr_attr};
        }
        $rec = $this->db()->selectServiceById($this->id());
        if ((array)$rec && !$this->staticIPClear) {
            return $rec->address;
        }
        return $this->ip ? $this->ip : $this->assign_ip();
    }

    public function id()
    {
        $entity = $this->move ? 'before' : 'entity';
        return $this->$entity->id;
    }

    protected function assign_ip()
    {
        $device = false;
        if ($this->conf->router_ppp_pool || $this->pppoe) {
            $device = $this->get_device();
        }
        $this->ip = (new API_IPv4())->assign($device);
        return $this->ip;
    }

    public function delete()
    {
        $id = $this->move ? $this->before->id : $this->entity->id;
        $this->db()->delete($id);
        return true;
    }







}
    
   