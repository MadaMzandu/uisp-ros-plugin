<?php

include_once 'admin_settings.php';
include_once 'admin_devices.php';
include_once 'admin_plans.php';
include_once 'admin_validation.php';
include_once 'admin_backup.php';
include_once 'admin_system.php';
include_once 'admin_rebuild.php';
include_once 'admin_cache.php';
include_once 'admin_get.php';
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

    public function __construct($data = [])
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

    public function select()
    {
        $target = $this->data->target ?? null ;
        $request = $this->data ;
        $data = $this->data->data ;
        switch ($target){
            case 'get': return new AdminGet($request);
            case 'config': return new Settings($data);
            case 'devices': return new Devices($data);
            //case 'stats': return new Stats($data);
            case 'plans': return new AdminPlans($data);
            case 'validation': return new Validation($data);
            //case 'users': return new Users($data);
            case 'jobs': return new Api_Jobs($data);
            case 'system': return new Admin_System($data);
            case 'backup': return new Admin_Backup($data);
            case 'lang': return new Api_Lang($data);
            case 'queue': return new Admin_Queue($data);
        }
        return null ;
    }

    public function exec(): void
    {
        $api = $this->select();
        $action = $this->data->action ?? null ;
        if($api && method_exists($api,$action)){ //route found
            $api->$action();
            $this->status = $api->status();
            $this->result = $api->result();
        }
        else{ //assume its a uisp api call
            $data = $this->data->data ?? [];
            $path = $this->data->path ?? null;
            $ucrm = new ApiUcrm();
            $this->result = $ucrm->request($path,$action,(array)$data);
        }
    }

    public function status(): stdClass
    {
        return $this->status;
    }

    protected function db(): ?ApiSqlite
    {
        return new ApiSqlite();
    }

    protected function ucrm()
    {
        return new ApiUcrm();
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
