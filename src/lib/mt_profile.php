<?php

class MT_Profile extends MT
{

    private $pq; //parent queue object

    protected function init():void
    {
        parent::init();
        $this->path = '/ppp/profile/';
        $this->findId();
        $this->exists = (bool)$this->insertId;
        $this->pq = new MT_Parent_Queue($this->svc);
    }

    public function set():bool
    {
        if ($this->svc->contention < 0 && !$this->has_children()) {
            return $this->deleteProfile();
        }
        if (!$this->exists()) {
            return $this->insert();
        }
        return $this->edit();
    }

    protected function findErr(){
        if($this->pq->status()->error){
            $this->status = $this->pq->status();
        }
    }

    protected function exists():bool
    {
        $this->read('?name=' . $this->name());
        $this->entity = $this->read[0] ?? null;
        $this->insertId = $this->read[0]['.id'] ?? null ;
        return (bool)$this->insertId;
    }

    protected function name()
    {
        return $this->svc->disabled
            ? $this->conf->disabled_profile
            : $this->svc->plan_name();
    }

    private function orphaned():string
    {
        if(!$this->exists()){
            return false;
        }
        $profile = $this->entity;
        return substr($profile['parent-queue'],0,1) == '*'
            ? $profile['parent-queue'] : false ;
    }

    private function insert():bool
    {
        $this->svc->contention = +1;
        if($this->pq->set() && $this->write($this->data(), 'add')){
            return true ;
        }
        $this->findErr();
        return false ;
    }

    private function edit():bool
    {
        $orphanId = $this->orphaned();
        return $orphanId
            ? ($this->pq->reset($orphanId) && $this->write($this->data()))
            : ($this->pq->set()  && $this->write($this->data()));
    }

    protected function data():object
    {
        return (object)[
            'name' => $this->name(),
            'local-address' => $this->local_addr(),
            'rate-limit' => $this->rate()->text,
            'parent-queue' => $this->pq->name(),
            'address-list' => $this->conf->active_list,
            '.id' => $this->name(),
        ];
    }

    private function local_addr()
    { // get one address for profile local address
        $savedPath = $this->path;
        $this->path = '/ip/address/';
        if ($this->read()) {
            foreach ($this->read as $prefix) {
                if ($prefix['dynamic'] == 'true') {
                    continue;
                }
                [$addr] = explode('/', $prefix['address']);
                $this->path = $savedPath;
                return $addr;
            }
        }
        $this->path = $savedPath;
        return false;
    }

    protected function rate():object
    {
        $rate = parent::rate();
        $r = $this->conf->disabled_rate;
        $disabled = (object)[
            'text' => $r . 'M/' . $r . 'M',
            'upload' => $r,
            'download' => $r,
        ];
        return $this->svc->disabled ? $disabled : $rate;
    }

    private function has_children()
    {
        $this->path = '/ppp/secret/';
        $read = $this->read('?profile=' . $this->name()) ?? [];
        $this->path = '/ppp/profile/';
        $count = $read ? sizeof($read) : 0;
        $count += $this->svc->disabled ? 0 : -1; // do not deduct if account is disabled
        return $count ?: false;
    }

    private function deleteProfile():bool
    {
        $this->svc->contention = -1;
        $data['.id'] = $this->data()->name;
        if($this->pq->set()
            && $this->write((object)$data, 'remove')){
            return true ;
        }
        $this->findErr();
        return false ;
    }

}
