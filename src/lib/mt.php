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
        $this->path = rtrim($this->getData('path'),'\/').'/' ;
        return $this->write($this->getData('data'),$this->getData('action'));
    }

    public function get(): ?array
    {
        $this->path = rtrim($this->getData('path'),'\/').'/';
        return $this->read($this->getData('filter'));
    }

    protected function write($data, $action = 'set')
    {
        $api = $this->connect();
        if (!($api && $data && $action)) {
            return false;
        }
        if($action == 'add'){unset($data->{'.id'});}
        $api->write($this->path . $action, false);
        foreach (array_keys((array)$data) as $key) {
            $api->write('=' . $key . '=' . $data->$key, false);
        }
        $api->write(';'); // trailing semicolon works
        $this->read = $api->read() ?? [];
        $api->disconnect();
        return $this->has_error() ? false
            : ($this->read ? : true);
    }

    private function getData($property)
    { // check and return data object property
        return $this->data->$property ?? null ;
    }

    private function connect(): ?RouterosAPI
    {
        $this->getDevice() or die('failed to get mikrotik device');
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

    protected function getDevice(): bool
    {
        if($this->svc){
            $this->device = $this->svc->device();
            return (bool )$this->device;
        }
        if($id = $this->getData('device_id'))
        {
            $this->device = $this->db()->selectDeviceById($id);
            return (bool)$this->device ;
        }
        if($dev = $this->getData('device'))
        {
            $this->device = $this->db()->selectDeviceByDeviceName($dev);
            return (bool)$this->device ;
        }
        $this->setErr('failed to get device information');
        return false;
    }

    private function has_error(): bool
    {
        $error = $this->read['!trap'][0]['message'] ?? null;
        if($error) {
            $this->setErr('failed');
        }
        return (bool) $error;
    }

    protected function init(): void
    {
        parent::init();
        $this->insertId = null;
        $this->exists = false;
    }

    protected function comment(): string
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

    protected function read($filter = null): ?array
    {  //implements mikrotik print
        $api = $this->connect();
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
