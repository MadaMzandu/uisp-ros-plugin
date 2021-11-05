<?php

class MT_Queue extends MT
{

    private $pq; //parent queue object
    private $id ;

    protected function init(): void
    {
        parent::init();
        $this->path = '/queue/simple/';
        $this->findId();
        $this->exists = (bool) $this->insertId ;
        $this->pq = new MT_Parent_Queue($this->svc);
    }

    public function set(){
        $tmp = $this->svc->contention < 0;
        if($this->svc->contention < 0 ){
            return $this->delete();
        }
        if($this->svc->contention > 0 && !$this->exists){
            return $this->insert();
        }
        return $this->edit();
    }

    public function insert()
    {
        if (!$this->pq->set()) {
            $this->setErr($this->pq->error());
            return false;
        }
        $this->insertId = $this->write($this->data(), 'add');
        return (bool) $this->insertId;
    }

    public function edit()
    {
        $orphanId = $this->orphaned();
        $action = $this->exists ? 'set' : 'add';
        $p =  $orphanId
            ?($this->pq->reset($orphanId))
            :($this->pq->set()) ;
        $ret = $this->write($this->data(), $action);
        $this->insertId = is_string($ret) ? $ret : $this->queue_id();
        return $p && (bool) $ret;
    }

    private function orphaned():string
    {
        if(!$this->exists()){
            return false;
        }
        $queue = $this->search[0];
        return substr($queue['parent'],0,1) == '*'
            ? $queue['parent'] : false ;
    }

    protected function data()
    {
        return (object)array(
            'name' => $this->name(),
            'target' => $this->svc->ip(),
            'max-limit' => $this->svc->rate()->text,
            'limit-at' => $this->svc->rate()->text,
            'parent' => $this->pq->name(),
            'comment' => $this->comment(),
            '.id' => $this->queue_id(),
        );
    }

    protected function name()
    {
        return $this->svc->client_id() . "-"
            . $this->svc->client_name() . "-"
            . $this->svc->id();
    }

    private function queue_id()
    {
        return $this->insertId
            ?? $this->svc->mt_queue_id();
    }

    public function delete()
    {
        $del['.id'] = $this->data()->{'.id'};
        if ($this->exists &&
            !$this->write((object)$del, 'remove')) {
            return false;
        }
        if (!$this->pq->set()) {  // edit or delete parent
            $this->setErr($this->pq->error());
            return false;
        }
        return true;
    }


}
