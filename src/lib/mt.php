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
        $this->path = rtrim($this->data->path,'\/').'/';
        return $this->write($this->data->data,$this->data->action);
    }

    public function get()
    {
        $this->path = rtrim($this->data->path,'\/').'/';
        $filter = $this->data->filter ?? null;
        return $this->read($filter);
    }

    protected function write($data, $action = 'set')
    {
        if ($action == 'add') {
            unset($data->{'.id'});
        }
        $api = $this->connect();
        if (!$api) {
            return false;
        }
        try {
            $api->write($this->path . $action, false);
            foreach (array_keys((array)$data) as $key) {
                $api->write('=' . $key . '=' . $data->$key, false);
            }
            $api->write(';'); // trailing semi-colon works
            $this->read = $api->read();
            $api->disconnect();
            if (!$this->read || is_string($this->read)) { //don't care what's inside the string?
                return is_string($this->read)
                    ? $this->read
                    :!(bool) $this->read;
            }
            $this->setErr('rosapi write:failed', true);
            return false;
        } catch (Exception $e) {
            $api->disconnect;
            $this->setErr($e->getMessage());
            return false;
        }
    }

    private function connect()
    {
       if(!$this->get_device()){
           return false;
       }
        try {
            $api = new Routerosapi();
            $api->timeout = 3;
            $api->attempts = 1;
            // $api->debug = true;
            if ($api->connect($this->device->ip,
                $this->device->user, $this->device->password)) {
                return $api;
            }
            $this->setErr('failed to connect to device');
            return false;
        } catch (Exception $e) {
            $this->setErr($e->getMessage());
            return false;
        }
    }

    protected function get_device(): bool
    {
        if($this->svc){
            $this->device = $this->svc->device();
            return true;
        }
        if(isset($this->data->device_id))
        {
            $this->device = $this->db()->selectDeviceById($this->data->device_id);
            return true ;
        }
        if(isset($this->data->device))
        {
            $this->device = $this->db()->selectDeviceByDeviceName($this->data->device);
            return true ;
        }
        $this->setErr('device is not specified in the request');
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

    protected function filter(): string
    {
        return '?comment='.$this->comment();
    }

    protected function read($filter = false): ?array
    {  //implements mikrotik print
        $api = $this->connect();
        if (!$api) {
            return null;
        }
        try {
            $api->write($this->path . 'print', false);
            if ($filter) {
                $api->write($filter, false);
            }
            $api->write(";");
            $this->read = $api->read() ?? [];
            $api->disconnect();
            return $this->read;
        } catch (Exception $e) {
            $api->disconnect();
            $this->setErr($e->getMessage());
            return null;
        }
    }

}
