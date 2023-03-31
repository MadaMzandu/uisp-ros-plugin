<?php

include_once 'routeros_api.class.php';
include_once 'device.php';

class MT extends Device
{

    protected $insertId;
    protected $path;
    protected $entity;
    protected $device;
    protected $exists;
    protected $batch ;
    protected $batch_device ;

    public function set()
    {
        $this->path = rtrim($this->get_data('path'), '\/') . '/';
        return $this->write($this->get_data('data'), $this->get_data('action'));
    }

    public function get(): ?array
    {
        $this->path = rtrim($this->get_data('path'), '\/') . '/';
        return $this->read($this->get_data('filter'));
    }

    protected function write($data=null, $action = 'set')
    {
        if(is_object($data)){
            $data->action = $action ;
            $this->batch[] = (array)$data;
        }
        return $this->write_batch();
    }

    protected function write_batch(): bool
    {
       $api = $this->connect();
        foreach($this->batch as $data){
            $timer = new ApiTimer('single write');
            //clean up the data
            $this->path = $data['path'] ?? $this->path ;
            $data = $this->prep_data($data);
            $id = $this->find_id($data);
            $action = $id ? 'set' : 'add';
            if($action == 'set') $data['.id'] = $id ;
            //write it
            $api->write(sprintf("/%s/%s",
                trim($this->path,'/'),$action),false);
            foreach (array_keys($data) as $key ){
                $api->write('=' . $key . '=' . $data[$key],false);
            }
            $api->write(';');
            $this->read = $api->read();
            $timer->stop();
            if($this->has_error()){
                MyLog()->Append('mt write error: '.json_encode([$data,$this->read]));
            }
        }
        $this->batch = [] ;
        $api->disconnect();
        return true ;
    }

    protected function find_id($data): ?string
    {
        $name = $data['name'] ?? null ;
        $mac = $data['mac-address'] ?? null ;
        $filter = null ;
        if($name) $filter = '?name=' . $name ;
        if($mac) $filter = '?mac-address=' . $mac ;
        if($filter){
            $read = $this->read($filter);
            $item  = $read[0] ?? [];
            $id = $item['.id'] ?? null ;
            if($id && is_string($id)) return $id;
        }
        return null ;
    }

    protected function find_local(): ?string
    {
        $tmp = $this->path;
        $this->path = '/ip/address/';
        $filter = '?disabled=false,?dynamic=false,?invalid=false';
        $list = $this->read($filter);
        $prefix = $list[0]['address'] ?? null ;
        $this->path = $tmp;
        $address = explode('/',$prefix)[0] ?? null ;
        return $address ?? (new ApiIP())->local();
    }

    protected function prep_data($data): array
    {
        $clean = [];
        foreach (array_keys($data) as $key){
            if(in_array($key,['.id','path','action']))continue;
            $clean[$key] = $data[$key];
        }
        if(key_exists('local-address',$data))
            $clean['local-address'] = $this->find_local();
        return $clean ;
    }

    protected function set_batch($data)
    {
        $this->batch[] = (array)$data ;
    }

    private function get_data($property)
    { // check and return data object property
        return $this->data->$property ?? null;
    }

    private function connect(): ?RouterosAPI
    {
        if(!$this->get_device()){
            throw new Exception('failed to get device information');
        };
        $api = new Routerosapi();
        $api->timeout = 1;
        $api->attempts = 1;
        //$api->debug = true;
        if (!$api->connect($this->device->ip,
            $this->device->user, $this->device->password)) {
            $this->queueMe('device connect failed');
            throw new Exception('device connect failed: job has been queued');
        }
        return $api;
    }

    protected function get_device(): bool
    {
        $this->device = null ;
        if($this->batch_device){
            $this->device = $this->batch_device ;
        }
        elseif ($this->svc) {
            $this->device = $this->svc->device();
        }
        elseif ($id = $this->get_data('device_id')) {
            $this->device = $this->db()->selectDeviceById($id);
        }
        elseif ($dev = $this->get_data('device')) {
            $this->device = $this->db()->selectDeviceByDeviceName($dev);
        }
        return (bool)$this->device ;
    }

    private function has_error(): bool
    {
        $error = null ;
        if(is_array($this->read)){
            $error = $this->read['!trap'][0]['message'] ?? null;
        }
        if ($error) {
            $this->setErr($error);
        }
        return (bool)$error;
    }

    protected function init(): void
    {
        parent::init();
        $this->entity = null;
        $this->insertId = null;
        $this->batch = [];
    }

    protected function comment(): string
    {
        return $this->svc->client->id() . " - "
            . $this->svc->client->name() . " - "
            . $this->svc->id();
    }

    protected function exists(): bool
    {
        $check_modes = ['delete' => 1, 'rename' => 1, 'move' => 1];
        $action_modes = ['delete' => 1, 'move' => 1];
        $action = $this->svc->action;
        $check_mode = $check_modes[$action] ?? 0;
        $action_mode = $action_modes[$action] ?? 0;
        $this->svc->mode($check_mode); // set check mode
        $this->entity = $this->read($this->filter())[0] ?? null;
        $this->svc->mode($action_mode); // set action mode
        $this->insertId = $this->entity['.id'] ?? null;
        return (bool)$this->insertId;
    }

    protected function filter(): ?string
    {
        return null;
    }

    protected function read($filter = null)
    {  //implements mikrotik print
        $api = $this->connect();
        $api->write(sprintf('/%s/print',trim($this->path,'/')), false);
        if ($filter) {
            foreach($this->format_filter($filter) as $item){
                $api->write($item,false);
            }
        }
        $api->write(";");
        $this->read = $api->read() ?? [];
        $api->disconnect();
        return $this->has_error() ? [] : $this->read;
    }

    protected function format_filter($filter): array
    {
        $return = [];
        if(is_string($filter)){
            foreach(explode(',',$filter . ',') as $item){
                if(!empty($item)) $return[] = $item ;
            }
        }
        if(is_array($filter)){
            foreach(array_keys($filter) as $key){
                $return[] = '?' . $key . '=' . $filter[$key] ?? null;
            }
        }
        return $return ;
    }

    protected function to_pair($array, $mbps = true): ?string
    {
        $str = [];
        if(!is_array($array)){
            $double = [$array,$array];
            $array = $double ;
        }
        foreach($array as $value){
            $unit = $mbps ? 'M' : null;
            if(!$value){ $value = 0; $unit = null; }
            $str[] = $value . $unit ;
        }
        return implode('/',$str);
    }

    protected function to_int($value)
    {
        if(!$value || !is_numeric($value)) return 0 ;
        return $value;
    }

}
