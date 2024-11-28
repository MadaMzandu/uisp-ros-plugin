<?php
include_once 'batch.php';

class ApiAction
{
    private ?object $request;
    private ?object $status ;

    public function exec($request = null)
    {
        if($request){ $this->request = $request; }
        $data = myTrimmer()->trim($this->type(),$this->request) ;
        $done  = $this->route($data);
        $action = $this->action();
        $name = $this->name();
        $message = "${action}_success $name" ;
        if(!$done){
            $message = "${action}_failed $name";
            $this->status->error = true ;
            $this->status->status = 'failed';
        }
        $this->status->message = $message;
    }

    private function route($data): bool
    { // only working with edits/inserts to avoid duplicates
        $action = $this->action() ;
        if($this->type() == 'client')
        {
            return $this->set_client($data['entity']);
        }
        elseif ($check = $this->is_unset($data))
        {//unset attributes
            if($check < 0)
            { //had prior settings
                return $this->delete($data['previous']);
            }
            if($this->is_auto($data['entity']))
            {
               myAttr()->set_user($this->entity()->id,$this->entity()->clientId);
               return true ;
            }

        }
        elseif(in_array($action,['insert','suspend','unsuspend']))
        {
            if(in_array($this->state(),[0,6]))
            { //deferred
                MyLog()->Append(sprintf("Deferred insert client: %s service: %s",
                    $this->entity()->clientId,$this->entity()->id),6);
                return true ;
            }
            else
            {
               return $this->set($data['entity'],$action);
            }
        }
        elseif($action == 'edit')
        {
            return $this->edit($data);
        }
        return true ;
    }

    private function edit($data): bool
    {
        $entity = $data['entity'] ;
        $previous = $data['previous'] ?? [];
        $diff = $this->changes($entity,$previous);
        $changes = array_keys($diff);
        $upgrade = array_intersect(['device','mac','username','hotspot'],$changes);
        if(in_array('status',$changes) && in_array($this->state(),[0,6]))
        { //deferred changes
            MyLog()->Append(sprintf("Deferred edit for service: %s client: %s",
                $this->entity()->id,$this->entity()->clientId),6);
            return true ;
        }
        elseif($upgrade)
        { //changes requiring previous delete
            MyLog()->Append(["Update edit requires delete changes: ",$changes,$this->entity()->id]);
            $dev = $entity['device'] ?? null ;
            $user = $entity['username'] ?? $entity['mac'] ?? null ;
            $this->delete($data['previous']) ;
            if($dev && $user) {
                return $this->set($entity, 'upgrade');
            }
            return true ;
        }
        elseif(in_array('status',$changes) && in_array($this->state(),[2,5]))
        {// obsolete status requires delete
            return $this->delete($data['previous']);
        }
        else
        {//normal edit
            if($changes)
            {//ignore network flapping
                return $this->set($data['entity'],'edit');
            }
        }
        return  true ;
    }

    private function name(): string
    {
        $client = $this->client() ;
        $id = $this->cid() ;
        $fn = $client['firstName'] ?? 'Client';
        $ln = $client['lastName'] ?? null ;
        $cn = $client['company'] ?? null ;
        return $cn ? "$cn ($id)" : "$fn $ln ($id)" ;
    }

    private function cid(): int
    {//client id
        return $this->entity()->clientId ?? 0 ;
    }

    private function client($id = null): ?array
    {
        if(!$id){ $id = $this->entity()->clientId ?? $this->entity()->id ?? 0; }
        $q = "select company,firstName,lastName from clients where id=$id";
        $client = myCache()->singleQuery($q,true);
        if($client){ return $client; }
        return $this->get_client($id);
    }

    private  function get_client($id): array
    {
        $client = $this->ucrm()->get("clients/$id");
        if(!$client){ return []; }
        $ret['company'] = $client['companyName'] ?? null;
        $ret['firstName'] = $client['firstName'] ?? null;
        $ret['lastName'] = $client['lastName'] ?? null ;
        return $ret ;
    }

    private function  set_client($entity): bool
    {
        $id = $entity['id'];
        $client = $this->client($id);
        $changes = array_diff_assoc($entity,$client);
        if(sizeof($changes) < 2){ return true ;} //no changes
        $services = mySqlite()->selectCustom("select id from services where clientId=$id");
        if(!$services){ return $this->save($entity); } //has no services - just save
        $ids = [];
        foreach($services as $service){ $ids[] = $service['id']; }
        $this->batch()->del_queues($ids); //del queues before saving
        $this->save($entity);
        return $this->batch()->set_accounts($ids);
    }

    private function set($entity,$action = 'set'): bool
    {
        $save = $entity ;
        $this->save($save);
        $client = $this->client() ;
        $data = array_replace($entity,$client);
        $data['action'] = $action ;
        return $this->batch()->set_accounts([$data]);
    }

    private function delete($entity): bool
    {
        $client = $this->client() ;
        $data = array_replace($entity,$client);
        $data['action'] = 'delete' ;
        if($this->batch()->del_accounts([$data])){
            return $this->unsave($entity);
        }
        return false ;
    }

    private function save($entity): bool
    {
        $table = $this->type() . 's';
        $net = $entity['network'] ?? [];
        if($net){
            $net['id'] = $entity['id'];
            myCache()->insert($net,'network',INSERT_REPLACE);
            unset($entity['network']);
        }

        return myCache()->insert($entity,$table,INSERT_REPLACE);
    }

    private function unsave($entity): bool
    {
        $id = $entity['id'] ;
        foreach(['services','network'] as $table)
        {
           return  myCache()->delete($id,$table) ;
        }
    }

    private function is_unset($data): int
    {
        $entity = $data['entity'];
        $previous = $data['previous'] ?? [];
        $dev = $entity['device'] ?? null;
        $user = $entity['mac'] ?? $entity['username'] ?? null ;
        if($dev && $user){ return 0 ; }
        if(!$previous){ return 1; }
        $changes = $this->changes($entity,$previous);
        if(array_intersect(['device','mac','username'],array_keys($changes)))
        { //has prior settings - delete
            return -1;
        }
        return 1;
    }

    private function changes($entity,$previous)
    {
        $net0 = $entity['network'] ?? []; $net1 = $previous['network'] ?? [];
        $net = false;
        if($net0 || $net1){
            if(array_diff_assoc($net0,$net1)){ $net = true; }
            $entity['network'] =  null ; $previous['network'] = null ;
        }
        $diff = array_diff_assoc($entity,$previous);
        if($net){ $diff[] = 'network'; }
        return $diff;
    }

    private function is_auto($entity): bool
    {
        $auto = $this->conf()->auto_ppp_user ?? false;
        $hs = $entity['hotspot'] ?? 0 ;
        if($hs){ $auto = $this->conf()->auto_hs_user ?? false; }
        return $auto ;
    }

    private function state(): int
    {//service status
        return $this->entity()->status ?? 0 ;
    }

    public function status(): object
    {
        return $this->status ;
    }

    public function result(): array
    {
        return [];
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

    private function conf(): object
    {
        return mySqlite()->readConfig() ;
    }

    private function batch(): Batch
    {
        return new Batch() ;
    }

    private function  req(): object
    {
        if(is_object($this->request)){
            return $this->request; }
        return new stdClass();
    }

    private function ucrm(): ApiUcrm { return new ApiUcrm(null,true); }

    public function __construct($data = null)
    {
        $this->request = $data ;
        $this->status = new stdClass();
    }
}