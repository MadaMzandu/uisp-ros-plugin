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
            switch ($this->select_action($data))
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
                    //$this->attributes()->unset_attr($delete);
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
        switch ($this->has_attributes()) {
            case -1: return ACTION_DELETE;
            case 0: return ACTION_CACHE;
            case 2: return ACTION_AUTO;
        }

        if(!$this->has_changed($data)){ return ACTION_DEFERRED; }

        if ($this->has_ended($data)) { return ACTION_DELETE; }

        if ($this->has_deferred($data)) { return ACTION_DEFERRED; }

        if (
            $this->has_moved($data) ||
            $this->has_renamed($data) ||
            $this->has_upgraded($data) ||
            $this->has_flipped($data)){ return ACTION_DOUBLE; }

        return ACTION_SET;
    }

    private function has_changed($data): bool
    {
        $entity = $data['entity'] ?? [];
        $previous = $data['previous'] ?? [];
        return $entity != $previous;
    }

    private function has_attributes(): int
    {
        return $this->attributes()->check($this->request);
    }

    private function has_deferred($data): bool
    {
        $status = $this->get('status',$data) ;
        return $status == ACTION_DEFERRED ;
    }

    private function has_renamed($data): bool
    {
        $fields = ['mac','username','duid','iaid'];
        foreach ($fields as $field){
            $new = $this->get($field,$data);
            $old = $this->get($field,$data,'previous');
            if($old && $new != $old){
                return true ;
            }
        }
        return false ;
    }

    private function has_ended($data): bool
    {
        $action = $data['action'] ?? null ;
        if(in_array($action,['end','cancel','delete'])) return true ;
        $status = $this->get('status',$data);
        return in_array($status,[ACTION_OBSOLETE,ACTION_ENDED,ACTION_INACTIVE]);
    }

    private function has_flipped($data): bool
    {//compare status
        $action = $data['action'] ?? null;
        $mac = $this->get('mac',$data);
        if($mac) return false ; // skip this check for dhcp
        if(in_array($action,['suspend','unsuspend'])) return true ;
        $new = $this->get('status',$data);
        $old = $this->get('status',$data,'old');
        return $new && $old && $new != $old ;
    }

    private function has_upgraded($data): bool
    {//compare plans
        $new = $this->get('planId',$data);
        $old = $this->get('planId',$data,'old');
        return $new && $old && $new != $old ;
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

    private function trimmer(){ return new ApiTrim(); }

    private function attributes() { return new ApiAttributes(); }

    public function __construct($data = null){ $this->request = $data ; }

}