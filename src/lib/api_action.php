<?php
const ACTION_DOUBLE = 2 ;
const ACTION_DELETE = -1 ;
const ACTION_SET = 1 ;
class ApiAction
{
    private $request ;

    public function exec()
    {
        $type = $this->request->entity ?? 'none';
        if($type != 'service'){
            throw new Exception(sprintf('no action for %s data: %s',$type,json_encode($this->request)));
        }
        $data = $this->trimmer()->trim('service',$this->request);
        $api = new MtBatch();
        switch ($this->action($data))
        {
            case ACTION_DOUBLE:{
                $delete = $this->get('id',$data,'old');
                $api->delete_ids([$delete]);
                $set = $this->get('id',$data);
                $api->set_ids([$set]);
                break ;

            }
            case ACTION_SET:{
                $set = $this->get('id',$data);
                $api->set_ids([$set]);
                break;
            }
            case ACTION_DELETE:{
                $delete = $this->get('id',$data);
                $api->set_ids([$delete]);
                break ;
            }
        }
    }

    private function action($data)
    {
        $return = ACTION_SET;
        if($this->has_moved($data)
            || $this->has_upgraded($data)) $return = ACTION_DOUBLE;
        else if($this->has_ended($data)) $return = ACTION_DELETE;
        MyLog()->Append("action code selected: ". $return);
        return $return ;
    }

    private function has_ended($data)
    {
        $action = $data['action'] ?? null ;
        if(in_array($action,['end','cancel','delete'])) return 'delete';
        $status = $this->get('status',$data);
        return in_array($status,[5]);
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