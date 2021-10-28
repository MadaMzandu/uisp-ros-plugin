<?php

class MT_Queue extends MT {

    private $pq; //parent queue object
    
    public function __construct(&$svc) {
        parent::__construct($svc);
        $this->path = '/queue/simple/';
        $this->pq = new MT_Parent_Queue($svc);
    }

    public function insert() {
        $this->svc->count = 1;
        if($this->exists()){ // edit if matching queue exists
            return $this->edit();
        }
        if (!$this->pq->set()) {
            $this->set_error($this->pq->error());
            return false;
        }
        if ($this->write($this->data(), 'add')) {
            $this->insertId = $this->read;
            return true;
        }
        return false;
    }

    public function delete() {
        $this->svc->count = -1;
        $del['.id'] = $this->data()->{'.id'}; 
        if ($this->write((object)$del, 'remove')) {
            if (!$this->pq->set(-1)) {  // edit or delete parent
                $this->set_error($this->pq->error());
                return false;
            }
            return true;
        }
        return false;
    }

    public function edit() {
        $data = $this->data();
        return $this->write($data, 'set');
    }
    
    protected function data() {
        return (object) array(
                    'name' => $this->name(),
                    'target' => $this->svc->ip(),
                    'max-limit' => $this->svc->rate()->text,
                    'limit-at' => $this->svc->rate()->text,
                    'parent' => $this->pq->name(),
                    'comment' => $this->comment(),
                    '.id' => $this->svc->record()->queueId ?? $this->name(),
        );
    }
    
    protected function name() {
        return $this->svc->client_id() . " - "
                . $this->svc->client_name() . " - "
                . $this->svc->id();
    }
   
   
}
