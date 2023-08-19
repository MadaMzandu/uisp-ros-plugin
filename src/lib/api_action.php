<?php
include_once 'api_attributes.php';
include_once 'api_trim.php';
include_once 'batch.php';

const ACTION_OBSOLETE = 5 ;
const ACTION_ENDED = 2;
const ACTION_DEFERRED = 6;
const ACTION_INACTIVE = 8;
const ACTION_DOUBLE = 2 ;
const ACTION_DELETE = -1 ;
const ACTION_DELETE_OLD = -2;
const ACTION_SET = 1 ;
const ACTION_CACHE = 10;
const ACTION_AUTO = 11;
class NoActionException extends Exception{}

class ApiAction
{
    private $request ;

    public function submit()
    {
        $type = $this->request->entity ?? 'none';
        if(!in_array($type,['client','service'])){
            throw new NoActionException(
                sprintf('no action for %s data',$type));
        }
        if(function_exists('fastcgi_finish_request')){
            MyLog()->Append('releasing webhook to fork into background');
            respond('service will be updated');
            fastcgi_finish_request();
        }
        $this->execute();
    }

    private function execute()
    {
        $type = $this->request->entity ?? 'none';
        $cache = new ApiCache();
        $data = $this->trimmer()->trim($type,$this->request);
        if($type == 'client'){
            $cache->save($data['entity'],'client');
        }
        else{
            $api = new Batch();
            $select = $this->select_action($data);
            MyLog()->Append("Selected Action: ".$select);
            switch ($select)
            {
                case ACTION_DOUBLE:{
                    MyLog()->Append('action delete then set');
                    $cache->save($data['previous']);
                    $delete = $this->get('id',$data,'old');
                    $api->del_accounts([$delete]);
                    $cache->save($data['entity']);
                    $set = $this->get('id',$data);
                    $api->set_accounts([$set]);
                    break ;
                }
                case ACTION_SET:{
                    MyLog()->Append('action set');
                    $cache->save($data['entity']);
                    $set = $this->get('id',$data);
                    $api->set_accounts([$set]);
                    break;
                }
                case ACTION_DELETE:{
                    MyLog()->Append('action delete');
                    $cache->save($data['entity']);
                    $delete = $this->get('id',$data);
                    $api->del_accounts([$delete]);
                    break ;
                }
                case ACTION_DELETE_OLD:{
                    MyLog()->Append('action delete old');
                    $cache->save($data['previous']);
                    $delete = $this->get('id',$data,'old');
                    $api->del_accounts([$delete]);
                    break ;
                }
                case ACTION_DEFERRED: {
                    MyLog()->Append('No action for this request');
                    break ;
                }
                case ACTION_CACHE: {
                    MyLog()->Append('service missing relevant attributes - delete before cache');
                    $delete = $this->get('id',$data);
                    $api->del_accounts([$delete]);
                    $cache->save($data['entity']);
                    break;
                }
                case ACTION_AUTO:{
                    MyLog()->Append('generating username and password for service');
                    $serviceId = $this->get('id',$data);
                    $clientId = $this->get('clientId',$data);
                    $this->attributes()->set_username($serviceId,$clientId);
                    break ;
                }
            }
        }
    }

    private function select_action($data): int
    {
        if($this->has_device($data)){

            if($this->has_changed($data))
            {
                if(!$this->has_user($data))
                {
                    if($this->has_auto($data)){ return ACTION_AUTO; }

                    if($this->has_cleared($data)){ return ACTION_DELETE_OLD; }

                    return ACTION_DEFERRED;
                }

                if ($this->has_ended($data)) { return ACTION_DELETE; }

                if ($this->has_deferred($data)) { return ACTION_DEFERRED; }

                if (
                    $this->has_moved($data) ||
                    $this->has_renamed($data) ||
                    $this->has_migrated($data)){ return ACTION_DOUBLE; }

            }

            //if($this->has_outage()){ return ACTION_DEFERRED; } //skip network flapping

            return ACTION_DEFERRED ;

        }

        if($this->has_cleared($data)){ return ACTION_DELETE_OLD; }

        return ACTION_DEFERRED;
    }


    private function has_changed($data): bool
    {
        $entity = $data['entity'] ?? [];
        $previous = $data['previous'] ?? [];
        return $entity != $previous;
    }

    private function has_auto($data): bool
    {
        $ap = $this->conf()->auto_ppp_user ?? false ;
        $ah = $this->conf()->auto_hs_user ?? false ;
        $he = $data['entity']['hotspot'] ?? false ;
        return ($he && $ah) || $ap ;
    }

    private function has_deferred($data): bool
    {
        $status = $this->get('status',$data) ;
        return $status == ACTION_DEFERRED ;
    }

    private function has_migrated($data)
    { //has switched between dhcp/ppp/hotspot
        $new = $this->get('mac',$data)
            ?? $this->get('username',$data);
        $old = $this->get('mac',$data,'old')
            ?? $this->get('username',$data,'old');
        if($new != $old){ return true; }
        $new = $this->get('hotspot',$data) ;
        $old = $this->get('hotspot',$data,'old') ;
        return $new != $old ;
    }

    private function has_renamed($data): bool
    {
        $fields = ['mac','username','duid','iaid'];
        foreach ($fields as $field){
            $new = $this->get($field,$data);
            $old = $this->get($field,$data,'previous');
            if($old && $new && $new != $old){
                return true ;
            }
        }
        return $new != $old ;
    }

    private function has_ended($data): bool
    {
        $action = $data['action'] ?? null ;
        if(in_array($action,['end','cancel','delete'])) return true ;
        $status = $this->get('status',$data);
        return in_array($status,[ACTION_OBSOLETE,ACTION_ENDED,ACTION_INACTIVE]);
    }

    private function has_cleared($data): bool
    { //has removed attributes
        foreach (['device','username','mac'] as $key){
            $new = $this->get($key,$data);
            $old = $this->get($key,$data,'old');
            if($old && empty($new)){ return true; }
        }
        return false ;
    }

    private function has_device($data): bool
    {
        $new = $this->get('device',$data);
        if($new){ return true ; }
        $old = $this->get('device',$data);
        if($old){ return true ; }
        return false ;
    }

    private function has_outage(): bool
    {
        $new = $this->request->extraData->entity->hasOutage ?? false ;
        $old = $this->request->extraData->entityBeforeEdit->hasOutage ?? false ;
        return $new != $old ;
    }

    private function has_user($data): bool
    {//has valid mac or username
        $mac = $this->get('mac',$data);
        if(filter_var($mac,FILTER_VALIDATE_MAC)){ return true; }
        $user = $this->get('username',$data);
        return !empty($user);
    }

    private function has_moved($data): bool
    {//compare devices
        $new = $this->get('device',$data);
        $old = $this->get('device',$data,'old');
        return $new && $old && $new != $old ;
    }

    private function get($key,$data,$type = 'entity')
    {
        $entity = $type == 'entity' ? $type : 'previous';
        $value = $data[$entity][$key] ?? null ;
        return $value ? trim($value) : null ;
    }

    private function trimmer(): ApiTrim { return new ApiTrim(); }

    private function attributes(): ApiAttributes { return new ApiAttributes(); }

    private function db(): ApiSqlite
    {
        if(empty($this->_db)){
            $this->_db = new ApiSqlite();
        }
        return $this->_db ;
    }

    private function conf(): ?object
    {
        if(empty($this->_conf)){
            $this->_conf = $this->db()->readConfig();
        }
        return $this->_conf;
    }

    public function __construct($data = null){ $this->request = $data ; }

}