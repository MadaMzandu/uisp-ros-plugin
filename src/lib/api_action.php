<?php
const ACTION_OBSOLETE = 5 ;
const ACTION_ENDED = 2;
const ACTION_SUSPENDED = 3;
const ACTION_DEFERRED = 6;
const ACTION_INACTIVE = 8;
const ACTION_DOUBLE = 2 ;
const ACTION_DELETE = -1 ;
const ACTION_SET = 1 ;
const ACTION_NULL = 99 ;
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
        $data = $this->trimmer()->trim('service',$this->request);
        if($type == 'client'){
            $cache->save($data['entity'],'client');
        }
        else{
            $api = new MtBatch();
            switch ($this->select_action($data))
            {
                case ACTION_DOUBLE:{
                    $cache->save($data['previous']);
                    $delete = $this->get('id',$data,'old');
                    $api->delete_ids([$delete]);
                    $cache->save($data['entity']);
                    $set = $this->get('id',$data);
                    $api->set_ids([$set]);
                    break ;
                }
                case ACTION_SET:{
                    $cache->save($data['entity']);
                    $set = $this->get('id',$data);
                    $api->set_ids([$set]);
                    break;
                }
                case ACTION_DELETE:{
                    $cache->save($data['entity']);
                    $delete = $this->get('id',$data);
                    $api->delete_ids([$delete]);
                    break ;
                }
                case ACTION_DEFERRED: {
                    MyLog()->Append('No action for deferred action');
                }
            }
        }

    }

    private function select_action($data): int
    {
        $return = ACTION_SET;
        if($this->has_deferred($data)) $return = ACTION_DEFERRED;
        else if($this->has_moved($data)
            || $this->has_flipped($data)
            || $this->has_upgraded($data)
            || $this->has_renamed($data)) $return = ACTION_DOUBLE;
        else if($this->has_ended($data)) $return = ACTION_DELETE;
        MyLog()->Append("action code selected: ". $return);
        return $return ;
    }

    private function has_deferred($data)
    {
        $status = $this->get('status',$data) ;
        return $status == ACTION_DEFERRED ;
    }

    private function has_renamed($data)
    {
        $new = $this->get('mac',$data) ?? $this->get('username',$data);
        $old = $this->get('mac',$data,'old') ?? $this->get('username',$data,'old');
        return $new && $old && $new != $old ;
    }

    private function has_ended($data)
    {
        $action = $data['action'] ?? null ;
        if(in_array($action,['end','cancel','delete'])) return 'delete';
        $status = $this->get('status',$data);
        return in_array($status,[ACTION_OBSOLETE,ACTION_ENDED,ACTION_INACTIVE]);
    }

    private function has_flipped($data)
    {
        $action = $data['action'] ?? null;
        if(in_array($action,['suspend','unsuspend'])) return true ;
        $new = $this->get('status',$data);
        $old = $this->get('status',$data,'old');
        return $new && $old && $new != $old ;
    }

    private function has_upgraded($data)
    {
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

    private function get($key,$data,$entity = 'entity')
    {
        if($entity == 'entity'
            && array_key_exists('entity',$data)){
            return $data['entity'][$key] ?? null ;
        }
        if(array_key_exists('previous',$data)){
            return $data['previous'][$key] ?? null ;
        }
        return null ;
    }

    private function trimmer(){ return new ApiTrim(); }


    public function __construct($data = null)
    {
        if(!is_object($data)){
            throw new Exception('action: wrong data format: '.json_encode($data));
        }
        $this->request = $data ;
    }

}