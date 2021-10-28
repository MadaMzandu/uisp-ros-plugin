<?php

include_once 'admin_settings.php';
include_once 'admin_devices.php';
include_once 'admin_stats.php';
include_once 'admin_plans.php';
include_once 'admin_validation.php';
include_once 'admin_users.php';
include_once 'admin_backup.php';
include_once 'admin_system.php';

class Admin {

    protected $status;
    protected $data;
    protected $result;
    protected $user;
    protected $read;

    public function __construct(&$data) {
        $this->data = $data;
        $this->init();
    }

    protected function init() {
        $this->status = new stdClass();
        $this->result = new stdClass();
        $this->status->authenticated = false;
        $this->status->error = false;
        $this->status->message = 'ok';
    }

    public function exec() {
        $target = $this->target($this->data->target);
        $this->setSession();
        $exec = new $target($this->data->data);
        $exec->{$this->data->action}();
        $this->status = $exec->status();
        $this->result = $exec->result();
    }

    private function setSession() {
        if (property_exists($this->data, 'session')) {
            $this->data->data->session = $this->data->session; //add session token before exec
        }
    }

    protected function doAuthentication() {
        $user = new Users($this->data);
        if (!$user->authenticate()) {
            $this->status = $user->status();
            $this->status->session = 'none';
            return false;
        }
        $this->user = $user->result();
        $this->status = $user->status();
        $this->status->session = $this->data->session;
        unset($this->data->session);
        return true;
    }

    private function target($target) {
        $map = array(
            'config' => 'Settings',
            'devices' => 'Devices',
            'stats' => 'Stats',
            'plans' => 'Plans',
            'validation' => 'Validation',
            'users' => 'Users',
            'unms' => 'API_Unms',
            'system' => 'System',
            'backup' => 'Backup',
        );
        return $map[$target];
    }

    public function result() {
        return $this->result;
    }

    public function status() {
        return $this->status;
    }

    protected function set_message($msg) {
        $this->status->error = false;
        $this->status->message = $msg;
    }

    protected function set_error($msg) {
        $this->status->error = true;
        $this->status->message = $msg;
    }

}
