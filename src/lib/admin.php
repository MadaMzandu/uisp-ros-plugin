<?php

include_once 'admin_settings.php';
include_once 'admin_devices.php';
include_once 'admin_plans.php';
include_once 'admin_validation.php';
include_once 'admin_backup.php';
include_once 'admin_system.php';
include_once 'admin_rebuild.php';
include_once 'admin_cache.php';
include_once 'api_jobs.php';
include_once 'api_lang.php';
include_once 'admin_mt_queue.php';

class Admin
{

    protected $status;
    protected $data;
    protected $result;
    protected $user;
    protected $read;
    protected $conf;

    public function __construct($data)
    {
        $this->data = $this->toObject($data);
        $this->init();
    }

    private function toObject($data): stdClass
    {
        if($data && (is_array($data) || is_object($data))){
            return is_object($data) ? $data
                :json_decode(json_encode($data));
        }
        return (object)[];
    }

    protected function init(): void
    {
        $this->conf = $this->db()->readConfig();
        $this->status = new stdClass();
        $this->result = new stdClass();
        $this->status->error = false;
        $this->status->message = 'ok';
    }

    public function exec(): void
    {
        $target = $this->target();
        $exec = new $target($this->data->data);
        $exec->{$this->data->action}();
        $this->status = $exec->status();
        $this->result = $exec->result();
    }

    private function target(): ?string
    {
        $map = array(
            'config' => 'Settings',
            'devices' => 'Devices',
            'stats' => 'Stats',
            'plans' => 'Plans',
            'validation' => 'Validation',
            'users' => 'Users',
            'jobs' => 'Api_Jobs',
            'unms' => 'API_Unms',
            'system' => 'Admin_System',
            'backup' => 'Admin_Backup',
            'lang' => 'Api_Lang',
            'queues' => 'Admin_Queue'
        );
        $target = $this->data->target ?? 'none' ;
        $module = $map[$target] ?? null ;
        if(!$module){
            throw new Exception('Unknown target module specified');
        }
        return $module ;
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

    protected function set_message($msg): bool
    {
        $this->status->error = false;
        $this->status->message = $msg;
        return true;
    }

    protected function set_error($msg): bool
    {
        $this->status->error = true;
        $this->status->message = $msg;
        return false ;
    }

}
