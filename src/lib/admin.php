<?php
include_once 'api_logger.php';
include_once 'api_timer.php';
include_once 'api_ucrm.php';
include_once 'api_trim.php';
include_once 'api_lang.php';
include_once 'api_sqlite.php';
include_once 'admin_settings.php';
include_once 'admin_devices.php';
include_once 'admin_plans.php';
include_once 'admin_validation.php';
include_once 'admin_backup.php';
include_once 'admin_system.php';
include_once 'admin_rebuild.php';
include_once 'api_jobs.php';
include_once 'api_cache.php' ;
//include_once '_web_ucrm.php';
include_once 'api_action.php';
include_once 'mt_batch.php';//for devel only
class Admin
{

    protected $status;
    protected $data;
    protected $result;
    protected $user;
    protected $read;

    public function __construct($data = [])
    {
        $this->data = $this->toObject($data);
        $this->init();
    }

    protected function toObject($data): ?stdClass
    {
        if(empty($data)) return null ;
        if(is_object($data)){return $data; }
        if(is_array($data)){ json_decode(json_encode($data)); }
        return null;
    }

    protected function init(): void
    {
        $this->status = json_decode('{"error":false,"message":"ok"}');
    }

    public function select()
    {
        $target = $this->data->target ?? null ;
        $request = $this->data ;
        $data = $this->data->data ?? null;
        switch ($target){
            case 'config': return new Settings($data);
            case 'devices': return new AdminDevices($data);
            case 'plans': return new AdminPlans($data);
            case 'validation': return new Validation($data);
            case 'jobs': return new Api_Jobs($data);
            case 'system': return new Admin_System($data);
            case 'backup': return new Admin_Backup($data);
            case 'lang': return new Api_Lang($data);
        }
        return null ;
    }

    public function exec(): void
    {
        if(empty($this->data)) {
            throw new Exception('admin: unable to route invalid request'); }
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
            $this->result = $this->ucrm()->$action($path,(array)$data);
        }
    }

    public function status(): stdClass
    {
        return $this->status;
    }

    protected function db()
    {
        return new ApiSqlite();
    }

    protected function dbCache()
    {
        return new ApiSqlite('data/cache.db');
    }


    protected function ucrm()
    {
        return new ApiUcrm();
    }

    protected function conf()
    {
        return $this->db()->readConfig();
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
