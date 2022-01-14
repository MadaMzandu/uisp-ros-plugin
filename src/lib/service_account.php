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

    protected function init(): void
    {
        parent::init();
        $this->plan = new Service_Plan($this->data);
        $this->client = new Service_Client($this->data);
        if($this->auto){
            $this->username() && $this->password();
            $this->status->message = 'Username/password trigger sent';
            $this->ready = false;
        }
    }

    public function disabled(): bool
    {
        $entity = $this->move ? 'before' : 'entity';
        return $this->$entity->status != 1 ;
    }

    public function device(): ?stdClass
    {
        return $this->get_device();
    }

    public function move(bool $move): void
    {
        $this->move = $move;
        $this->plan->move = $move;
        $this->client->move = $move;
    }

    public function callerId(): ?string
    {
        $entity = $this->move ? 'before' : 'entity';
        return $this->get_attribute_value(
            $this->conf->pppoe_caller_attr,$entity
        );

    }

    protected function get_device(): ?stdClass
    {
        $entity = $this->move ? 'before' : 'entity';
        $name = $this->get_attribute_value($this->conf->device_name_attr,$entity);
        $dev = $this->db()->selectDeviceByDeviceName($name)
            or $this->setErr('the specified device was not found');
        return $dev ;
    }

    public function username(): ?string
    {
        $entity = $this->move ? 'before' : 'entity';
        $username = $this->get_attribute_value($this->conf->pppoe_user_attr,$entity);
        if(!$username && $this->conf->auto_ppp_user){
            $username = $this->client->username();
        }
        return $username;
    }

    protected function pass_generate(): ?string
    {
        $len = 8;
        $chars[] = "abcdefghijklmnopqrstuvwxyz";
        $chars[] = strtoupper($chars[0]);
        $chars[] = "1234567890";
        $chars[] = "!@#$&*?";
        $tmp = '';
        while(strlen($tmp) < $len){
            foreach ($chars as $set){
                if(strlen($tmp) == $len){
                    continue;
                }
                $tmp .= str_split($set)[array_rand(str_split($set))];
            }
        }
        $pass = str_shuffle($tmp);
        return $this->set_attribute($this->conf->pppoe_pass_attr,$pass)
            ? $pass : null;
    }

    public function password(): string
    {
        $entity = $this->move ? 'before' : 'entity';
        $password = $this->get_attribute_value($this->conf->pppoe_pass_attr,$entity);
        if(!$password){
            $password = $this->pass_generate();
        }
        return $password;
    }

    public function mac(): ?string
    {
        $entity = $this->move ? 'before' : 'entity';
        return $this->get_attribute_value($this->conf->mac_addr_attr,$entity);
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
        $ip = $this->get_attribute_value($this->conf->ip_addr_attr);
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
        $ip = $this->get_attribute_value($this->conf->ip_addr_attr);
        $old_ip = $this->get_attribute_value($this->conf->ip_addr_attr,'before');
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
    
   