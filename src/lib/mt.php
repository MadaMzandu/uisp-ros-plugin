<?php

include_once 'routeros_api.class.php';
include_once 'device.php';

class MT extends Device
{

    protected $insertId;
    protected $exists ;
    protected $path;
    protected $entity;
    protected $device ;

    public function set()
    {
        $this->path = rtrim($this->is_set('path'),'\/').'/' ;
        return $this->write($this->is_set('data'),$this->is_set('action'));
    }

    public function get(): ?array
    {
        $this->path = rtrim($this->is_set('path'),'\/').'/';
        return $this->read($this->is_set('filter'));
    }

    protected function write($data, $action = 'set')
    {
        $api = $this->connect();
        $prep = $this->prep_data($data,$action); //prepared data
        if (!($api && $prep && $action)) {
            return false;
        }
        $api->write($this->path . $action, false);
        foreach (array_keys((array)$prep) as $key) {
            $api->write('=' . $key . '=' . $prep->$key, false);
        }
        $api->write(';'); // trailing semicolon works
        $this->read = $api->read() ?? [];
        $api->disconnect();
        return $this->has_error() ? false
            : ($this->read ? $this->read : true);
    }

    private function prep_data($data,$action): ?stdClass
    {
        $prep = is_array($data) ? (object)$data : $data ;
        if(!is_object($prep)){
            $this->setErr('mikrotik post data is empty');
            return null;
        }
        if($action == 'add'){
            unset($prep->{'.id'});
        }
        return $prep ;
    }

    private function is_set($property)
    { // check and return data object property
        if(isset($this->data->$property)){
            return $this->data->$property;
        }
        return null;
    }


    private function connect(): ?RouterosAPI
    {
        if (!$this->get_device()) {
            return null;
        }
        $api = new Routerosapi();
        $api->timeout = 1;
        $api->attempts = 1;
        //$api->debug = true;
        if ($api->connect($this->device->ip,
            $this->device->user, $this->device->password)) {
            return $api;
        }
        $this->setErr('failed to connect to device');
        return null;
    }

    protected function get_device(): bool
    {
        if($this->svc){
            $this->device = $this->svc->device();
            return (bool )$this->device;
        }
        if(isset($this->data->device_id))
        {
            $this->device = $this->db()->selectDeviceById($this->data->device_id);
            return (bool)$this->device ;
        }
        if(isset($this->data->device))
        {
            $this->device = $this->db()->selectDeviceByDeviceName($this->data->device);
            return (bool)$this->device ;
        }
        $this->setErr('failed to get device information');
        return false;
    }

    private function has_error(): bool
    {
        if(isset($this->read['!trap'])) {
            $this->setErr('failed',true);
            return true ;
        }
        return false;
    }


    protected function setErr($msg, $obj = false): void
    {
        $this->status->error = true;
        if ($obj) {
            $this->status->message = $this->read['!trap'][0]['message'];
        } else {
            $this->status->message = $msg;
        }
    }

    protected function init(): void
    {
        parent::init();
        $this->insertId = null;
        $this->exists = false;
    }

    protected function comment()
    {
        return $this->svc->client->id() . " - "
            . $this->svc->client->name() . " - "
            . $this->svc->id();
    }

    protected function exists(): bool
    {
        $this->read($this->filter());
        $this->entity = $this->read[0] ?? null;
        $this->insertId = $this->read[0]['.id'] ?? null;
        return (bool)$this->insertId;
    }

    protected function filter(): ?string
    {
        return null ;
    }

    protected function read($filter = false): ?array
    {  //implements mikrotik print
        $api = $this->connect();
        if (!$api) {
            return null;
        }
        $api->write($this->path . 'print', false);
        if ($filter) {
            $api->write($filter, false);
        }
        $api->write(";");
        $this->read = $api->read() ?? [];
        $api->disconnect();
        return $this->has_error() ? [] : $this->read ;
    }

}
