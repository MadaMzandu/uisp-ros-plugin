<?php

class ApiAction
{
    private ?object $request ;

    public function submit($request = null)
    {
        if($request){ $this->request = $request; }
        $data = myTrimmer()->trim($this->type(),$request) ;
        $this->execute($data);
    }

    private function execute($data)
    { // only working with edits/inserts to avoid duplicates
        $action = $this->action() ;
        if($this->type() == 'client')
        {
            $this->save($data['entity']);
        }
        elseif($action == 'insert')
        {
            if(in_array($this->status(),[0,6]))
            { //deferred
                MyLog()->Append(sprintf("Deferred insert client: %s service: %s",
                    $this->entity()->clientId,$this->entity()->id),6);
            }
            else
            {
                $this->set($data['entity'],$action);
            }
        }
        elseif($action == 'edit')
        {
            $this->edit($data);
        }

    }

    private function edit($data)
    {
        $entity = $data['entity'] ;
        $previous = $data['previous'] ?? [];
        $diff = array_diff_assoc($entity,$previous);
        $changes = array_keys($diff);
        $upgrade = array_intersect(['device','mac','username','hotspot'],$changes);
        MyLog()->Append($changes);
        if(in_array('status',$changes) && in_array($this->status(),[0,6]))
        { //deferred changes
            MyLog()->Append(sprintf("Deferred edit for service: %s client: %s",
                $this->entity()->id,$this->entity()->clientId),6);
        }
        elseif($upgrade)
        { //changes requiring previous delete
            MyLog()->Append(["Update edit requires delete changes: ",$changes,$this->entity()->id]);
            $this->delete($data['previous']);
            $this->set($data['entity'],'upgrade');
        }
        elseif(in_array('status',$changes) && in_array($this->status(),[2,5]))
        {// obsolete status requires delete
            $this->delete($data['previous']);
        }
        else
        {//normal edit
            $this->set($data['entity'],'edit');
        }

    }

    private function client(): ?array
    {
        $id = $this->entity()->clientId ?? 0;
        $q = "select * from clients where id=$id";
        $client = myCache()->singleQuery($q,true);
        if($client){ return $client; }
        return $this->get_client($id);
    }

    private  function get_client($id): array
    {
        $client = $this->ucrm()->get("clients/$id");
        if(!$client){ return []; }
        $ret['id'] = $id;
        $ret['company'] = $client['companyName'] ?? null;
        $ret['firstName'] = $client['firstName'] ?? null;
        $ret['lastName'] = $client['lastName'] ?? null ;
        return $ret ;
    }

    private function set($entity,$action = 'set')
    {
        $this->save($entity);
        $client = $this->client() ;
        $data = array_replace($entity,$client);
        $data['action'] = $action ;
        $this->batch()->set_accounts([$data]);
    }

    private function delete($entity)
    {
        $client = $this->client() ;
        $data = array_replace($entity,$client);
        $data['action'] = 'delete' ;
        $this->batch()->del_accounts([$data]);
        $this->unsave($entity);
    }

    private function save($entity)
    {
        $table = $this->type() . 's';
        myCache()->insert($entity,$table,true);
    }

    private function unsave($entity)
    {
        $id = $entity['id'] ;
        foreach(['services','network'] as $table)
        {
            myCache()->delete($id,$table) ;
        }
    }

    private function status(): int
    {
        return $this->entity()->status ?? 0 ;
    }

    private function entity(): object
    {
        return $this->req()->extraData->entity ?? new stdClass();
    }

    private function type(): string
    {
        return $this->req()->entity ?? 'no_entity';
    }

    private function action(): string
    {
        return $this->req()->changeType ?? 'no_change' ;
    }

    private function batch(): MtBatch
    {
        return new MtBatch() ;
    }

    private function  req(): object
    {
        if(is_object($this->request)){
            return $this->request; }
        return new stdClass();
    }

    public function __construct($data = null)
    {
        $this->request = $data ;
    }
}