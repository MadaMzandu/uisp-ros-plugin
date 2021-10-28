<?php
include_once 'service_plan.php';
include_once 'app_ipv4.php';
include_once '_temp.php';

class Service_Account extends Service_Plan {
    
    public $move = false ;
    public $ip ; 
    protected $client ;
    protected $rec ;
    protected $dev; //device parameters
    
    public function __construct(&$data) {
        parent::__construct($data);
        $this->load_device();
        $this->load_client();
        $this->load_record();
        $this->load_record();
    }
    
    public function record(){
        return $this->rec ;
    }
    
    public function device(){
        $this->load_device();
        return $this->dev;
    }
    
    public function device_type(){
        return $this->dev->type ;
    }
    
    protected function load_device() {
        $entity = $this->move ? 'before' : 'entity';
        if (!isset($this->$entity->{$this->conf->device_name_attr})) {
            $this->setErr('Device name is not provided');
            return (object) [];
        }
        $name = $this->$entity->{$this->conf->device_name_attr};
        $this->dev = $this->db->selectDeviceByDeviceName($name);
        (array) $this->dev ?: $this->setErr('Device specified was not found');
    }
    
    protected function load_record(){
        $id = isset($this->before->id) ? $this->before->id : $this->entity->id;
        $this->rec = $this->db->selectServiceById($id);
        $this->exists = (array)$this->rec ? true : false ;
    }
    
    public function id(){
        return $this->entity->id ;
    }
    
    public function username(){
        return $this->entity->{$this->conf->pppoe_user_attr};
    }
    
    public function password(){
        return $this->entity->{$this->conf->pppoe_pass_attr};
    }
    
    public function mac(){
        return $this->entity->{$this->conf->mac_addr_attr};
    }
    
    public function mt_account_id(){
        return isset($this->rec->mtId) 
        ? $this->rec->mtId : null;
    }
    
    public function mt_queue_id(){
        return isset($this->rec->queueId) 
        ? $this->rec->queueId : null;
    }
    
    public function save($data){
        $rec = $this->data($data);
        return $this->db->insert($rec) ? : $this->db->edit($rec);
    }
    
    public function delete(){
        $this->db->delete($this->rec->id);
    }
   
    public function client_name() {
        $name = 'Client Id:' . $this->entity->clientId;
        if ((array) $this->client) {
            $name = $this->client->firstName . ' ' . $this->client->lastName;
            if (isset($this->client->companyName)) {
                $name = $this->client->companyName;
            }
        }
        return $name;
    }
    
    public function client_id(){
        return $this->entity->clientId ;
    }
    
    public function ip() {
        if ($this->exists) {
            return $this->rec->address;
        }
        if (isset($this->entity->{$this->conf->ip_addr_attr})) {
            return $this->entity->{$this->conf->ip_addr_attr};
        }
        return $this->ip ? $this->ip : $this->assign_ip();
    }
    
    protected function data($data) {
        $rec = (object) array(
                    'id' => $this->entity->id,
                    'planId' => $this->entity->servicePlanId,
                    'clientId' => $this->entity->clientId,
                    'address' => $this->ip(),
                    'status' => $this->entity->status,
                    'device' => $this->dev->id,
        );
        foreach(array_keys((array)$data) as $key){
            $rec->$key = $data->{$key} ;
        }
        return $rec ;
    }

    protected function assign_ip() {
        $device = $this->conf->router_ppp_pool ? $this->dev : ($this->pppoe ? false : $this->dev);
        $this->ip = (new API_IPv4())->assign($device);
        return $this->ip;
    }

    protected function load_client(){
        $id = $this->entity->clientId ;
        $this->client = (new API_Unms())->request('/clients/'.$id);
    }
    
    
}
    
   