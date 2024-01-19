<?php
include_once 'api_logger.php';
include_once 'api_timer.php';
include_once 'api_ucrm.php';
include_once 'api_trim.php';
include_once 'api_lang.php';
include_once 'api_sqlite.php';
include_once 'api_settings.php';
include_once 'api_devices.php';
include_once 'api_plans.php';
include_once 'api_backup.php';
include_once 'api_system.php';
include_once 'api_rebuild.php';
include_once 'api_jobs.php';
include_once 'api_cache.php' ;
include_once 'api_action.php';
include_once 'batch.php';
class Admin
{

    protected ?object $status = null ;
    protected ?object $data = null ;
    protected mixed $result = null ;
    protected mixed $read = null ;

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
        $status = '{"status":"ok","error":false,"message":"ok","session":false}';
        $this->status = json_decode($status);
    }

    public function select(): ?object
    {
        $target = $this->data->target ?? null ;
        $data = $this->data->data ?? null;
        return match ($target) {
            'config' => new ApiSettings($data),
            'devices' => new ApiDevices($data),
            'plans' => new ApiPlans($data),
            'jobs' => new ApiJobs($data),
            'system' => new ApiSystem($data),
            'backup' => new ApiBackup($data),
            'lang' => new ApiLang($data),
            default => null,
        };
    }

    public function exec(): void
    {
        if(empty($this->data)) { fail('request_invalid'); }
        $api = $this->select();
        $action = $this->data->action ?? null ;
        if($api && method_exists($api,$action)){ //route found
            $api->$action();
            $this->status = $api->status();
            $this->result = $api->result();
        }
        else{ //assume its a uisp api call
            fail('request_invalid');
        }
    }

    public function status(): object
    {
        return $this->status;
    }

    protected function db(): ApiSqlite
    {
        return mySqlite();
    }

    protected function dbCache(): ApiSqlite
    {
        return myCache() ;
    }

    protected function ucrm(): ApiUcrm
    {
        return new ApiUcrm();
    }

    protected function conf(): object
    {
        return $this->db()->readConfig();
    }

    public function result(): mixed
    {
        return $this->result;
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
