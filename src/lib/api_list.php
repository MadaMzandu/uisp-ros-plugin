<?php
include_once 'api_update.php';
include_once 'api_ucrm.php';
class ApiList
{
    private string $mode ;
    private null|object|array $result = null ;
    private null|object|array $data;

    public function list(): null|array
    {
        return match ($this->mode){
            'plans' => $this->list_plans(),
            default => null
        };
    }

    private function list_plans(): array
    {
        $str = '{"ratio":1,"uploadSpeed":0,"downloadSpeed":0,'.
            '"priorityUpload":8,"priorityDownload":8,"limitUpload":0,"limitDownload":0,'.
            '"burstUpload":0,"burstDownload":0,"threshUpload":0,"threshDownload":0,'.
            '"timeUpload":1,"timeDownload":1}';
        $this->db()->deleteAll('plans');
        $plans = $this->find_plans();
        $from_db = $this->find_db_plans();
        $defaults = json_decode($str,true);
        foreach($plans as $plan){
            $saved = $from_db[$plan['id']] ?? [] ;
            $now = date('c');
            if(!$saved) {
                $plan['created'] = $now ;
                $plan = array_replace($defaults,$plan);
            }
            $update = array_replace($saved,$plan);
            $update['last'] = $now ;
            $from_db[$plan['id']] = $update;
            $trim = array_diff_key($update,['archive' => null]);
            $this->db()->insert($trim,'plans',true);
        }
        MyLog()->Append(['list_plans','items: '.sizeof($from_db)]);
        return $from_db ;
    }

    private function find_db_plans(): array
    {
        $read = $this->db()->selectAllFromTable('plans');
        $tmp = [];
        foreach($read as $item){ $tmp[$item['id']] = $item; }
        return $tmp ;
    }

    public function find_plans(): array
    {
        $data = $this->ucrm()->get('service-plans',['servicePlanType' => 'internet']);
        $tmp = [];
        $trimmer = array_fill_keys(['id','uploadSpeed','downloadSpeed','name'],'$#@&');
        foreach ($data as $item) {
            $trim = array_intersect_key($item,$trimmer);
            $trim['archive'] = false ;
            $tmp[$item['id']] = $trim;
        }
        return $tmp ;
    }


    private function set_mode($mode)
    {
        if(preg_match("#(service|device|plan)#",$mode)){ //append ending "s"
            $mode = preg_replace("#s\s*$#",'',$mode) . 's';
        }
        $this->mode = $mode ;
    }

    private function db(): ApiSqlite { return mySqlite(); }
    private function cachedb(): ApiSqlite { return myCache(); }
    private function status(): object { return new stdClass(); }
    private function result(): null|array|object { return $this->result; }
    private function ucrm($assoc = true): ApiUcrm { return new ApiUcrm(null,$assoc); }

    public function __construct($data = null,$mode = 'services')
    {
        $this->set_mode($mode);
        if(!$data){$data = new stdClass(); }
        $this->data = $data ;
    }
}
