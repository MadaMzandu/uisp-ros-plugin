<?php
include_once 'service_plan.php';
include_once 'service_attributes.php';
include_once 'service_client.php';
include_once 'api_ipv4.php';
include_once 'api_unms.php';

class Service_Account extends Service_Attributes
{

    public $plan ;
    public $client ;
    public $ip; //ip address assignment

    public $device_index = 0; // for iterating devices

    protected function init(): void
    {
        parent::init();
        $this->plan = new Service_Plan($this->data);
        $this->client = new Service_Client($this->data);
    }

    public function disabled(): bool
    {
        $entity = $this->move ? 'before' : 'entity';
        return $this->$entity->status != 1 ;
    }

    public function device(): ?stdClass
    {
        return $this->ready
            ? $this->get_device()
            : $this->get_next_device();
    }

    public function move($bool): void
    {
        $this->move = $bool;
        $this->plan->move = $bool;
    }

    protected function get_device(): ?stdClass
    {
        $entity = $this->move ? 'before' : 'entity';
        $name = $this->attribute($this->conf->device_name_attr,$entity);
        $dev = $this->db()->selectDeviceByDeviceName($name);
        if (!(array)$dev) {
            $this->setErr('the specified device was not found');
            return null;
        }
        return $dev;
    }

    protected function get_next_device(): ?stdClass
    {
        $devices = $this->db()->selectAllFromTable('devices') ?? [];
        $length = sizeof($devices);
        $device = null;
        if($this->device_index < $length){
            $device = (object) $devices[$this->device_index];
        }
        if(++$this->device_index >= $length){
            $this->device_index = -1;
        }
        return $device;
    }

    public function username(): ?string
    {
        $entity = $this->move ? 'before' : 'entity';
        return $this->attribute($this->conf->pppoe_user_attr,$entity);
    }

    public function password(): ?string
    {
        $entity = $this->move ? 'before' : 'entity';
        return $this->attribute($this->conf->pppoe_pass_attr,$entity);
    }

    public function mac(): ?string
    {
        $entity = $this->move ? 'before' : 'entity';
        return $this->attribute($this->conf->mac_addr_attr,$entity);
    }

    public function save(): bool
    {
        return $this->exists()
            ? $this->db()->edit($this->data())
            : $this->db()->insert($this->data());
    }

    protected function data(): array
    {
        return [
            'id' => $this->entity->id,
            'planId' => $this->entity->servicePlanId,
            'clientId' => $this->entity->clientId,
            'address' => $this->ip(),
            'status' => $this->entity->status,
            'device' => $this->get_device()->id
        ];
    }

    public function ip(): ?string
    {
        $ip = $this->attribute($this->conf->ip_addr_attr);
        if ($ip) {
            return $ip;
        }
        $rec = $this->db()->selectServiceById($this->id());
        if ((array)$rec && !$this->ip_removed()) {
            return $rec->address;
        }
        return $this->ip ?? $this->assign_ip();
    }

    protected function ip_removed(): bool
    {
        $ip = $this->attribute($this->conf->ip_addr_attr);
        $old_ip = $this->attribute($this->conf->ip_addr_attr,'before');
        return $old_ip && !$ip  ;
    }

    public function id(): int
    {
        $entity = $this->move ? 'before' : 'entity';
        return $this->$entity->id;
    }

    protected function assign_ip(): ?string
    {
        $device = false;
        if ($this->conf->router_ppp_pool || !$this->pppoe) {
            $device = $this->get_device();
        }
        $this->ip = (new API_IPv4())->assign($device);
        return $this->ip;
    }

    public function delete(): bool
    {
        $id = $this->move ? $this->before->id : $this->entity->id;
        $this->db()->delete($id);
        return true ;
    }

}
    
   