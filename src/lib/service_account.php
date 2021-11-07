<?php
include_once 'service_plan.php';
include_once 'api_ipv4.php';
include_once 'api_unms.php';

class Service_Account extends Service_Plan
{

    public $move = false;
    public $ip;
    protected $client;

    public function device()
    {
        return $this->get_device();
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
        return $this->db()->delete($id);
    }

    public function client_name()
    {
        $name = 'Client Id:' . $this->entity->clientId;
        $client = $this->get_client();
        if ((array)$client) {
            $name = $client->firstName . ' ' . $client->lastName;
            if (isset($client->companyName)) {
                $name = $client->companyName;
            }
        }
        return $name;
    }

    protected function get_client(): ?object
    {
        if (!(array)$this->client) {
            $this->client = (new API_Unms())->request('/clients/' . $this->client_id());
        }

        return $this->client ?? null;
    }

    public function client_id()
    {
        return $this->entity->clientId;
    }


}
    
   