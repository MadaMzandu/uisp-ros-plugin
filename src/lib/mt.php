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

    public function set()
    {
        $this->path = rtrim($this->getData('path'), '\/') . '/';
        return $this->write($this->getData('data'), $this->getData('action'));
    }

    public function get(): ?array
    {
        $this->path = rtrim($this->getData('path'), '\/') . '/';
        return $this->read($this->getData('filter'));
    }

    protected function write($data, $action = 'set')
    {
        $api = $this->connect();
        if ($action == 'add') {
            unset($data->{'.id'});
        }
        $api->write($this->path . $action, false);
        foreach (array_keys((array)$data) as $key) {
            $api->write('=' . $key . '=' . $data->$key, false);
        }
        $api->write(';'); // trailing semicolon works
        $this->read = $api->read() ?? [];
        $api->disconnect();
        return $this->has_error() ? false
            : ($this->read ?: true);
    }

    private function getData($property)
    { // check and return data object property
        return $this->data->$property ?? null;
    }

    private function connect(): ?RouterosAPI
    {
        if(!$this->getDevice()){
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

    protected function getDevice(): bool
    {
        $this->device = null ;
        if ($this->svc) {
            $this->device = $this->svc->device();
        }
        elseif ($id = $this->getData('device_id')) {
            $this->device = $this->db()->selectDeviceById($id);
        }
        elseif ($dev = $this->getData('device')) {
            $this->device = $this->db()->selectDeviceByDeviceName($dev);
        }
        return (bool)$this->device ;
    }

    private function has_error(): bool
    {
        $error = $this->read['!trap'][0]['message'] ?? null;
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
        $api->write($this->path . 'print', false);
        if ($filter) {
            $api->write($filter, false);
        }
        $api->write(";");
        $this->read = $api->read() ?? [];
        $api->disconnect();
        return $this->has_error() ? [] : $this->read;
    }

}
