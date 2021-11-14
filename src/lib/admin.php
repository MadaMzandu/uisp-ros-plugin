<?php

include_once 'admin_settings.php';
include_once 'admin_devices.php';
include_once 'admin_plans.php';
include_once 'admin_validation.php';
include_once 'admin_backup.php';
include_once 'admin_system.php';

class Admin
{

    protected $status;
    protected $data;
    protected $result;
    protected $user;
    protected $read;
    protected $conf;

    public function __construct(&$data)
    {
        $this->data = $data;
        $this->init();
    }

    protected function init(): void
    {
        $this->conf = $this->db()->readConfig();
        $this->status = new stdClass();
        $this->result = new stdClass();
        $this->status->authenticated = false;
        $this->status->error = false;
        $this->status->message = 'ok';
    }

    public function exec(): void
    {
        $target = $this->target($this->data->target);
        $exec = new $target($this->data->data);
        $exec->{$this->data->action}();
        $this->status = $exec->status();
        $this->result = $exec->result();
    }

    private function target($target): ?string
    {
        $map = array(
            'config' => 'Settings',
            'devices' => 'Devices',
            'stats' => 'Stats',
            'plans' => 'Plans',
            'validation' => 'Validation',
            'users' => 'Users',
            'unms' => 'API_Unms',
            'system' => 'Admin_System',
            'backup' => 'Admin_Backup',
        );
        return $map[$target] ?? null;
    }

    public function status(): stdClass
    {
        return $this->status;
    }

    protected function db(): ?API_SQLite
    {
        try {
            return new API_SQLite();
        } catch (Exception $e) {
            return null;
        }
    }

    protected function service_blank(): stdClass
    {
        return (object)[
            'changeType' =>'none',
            'extraData' => (object)[
                'entity' => (object)[
                    'id' => 0,
                    'status' => 0,
                ],
            ]
        ];
    }

    public function result()
    {
        return $this->result;
    }

    protected function get_attrib($key,$data): ?string
    { //returns an attribute value
        if(isset($data['attributes'])) {
            foreach ($data['attributes'] as $attribute) {
                if ($key == $attribute['key']) {
                    return $attribute['value'];
                }
            }
        }
        return null;
    }

    protected function set_message($msg): void
    {
        $this->status->error = false;
        $this->status->message = $msg;
    }

    protected function set_error($msg): void
    {
        $this->status->error = true;
        $this->status->message = $msg;
    }

}
