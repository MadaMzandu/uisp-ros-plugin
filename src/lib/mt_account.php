<?php

include_once 'mt.php';
include_once 'mt_queue.php';
include_once 'mt_profile.php';
include_once 'mt_parent_queue.php';

class MT_Account extends MT
{

    private $profile;
    private $q;

    public function suspend(): bool
    {
        if ($this->set_account()) {
            if ($this->svc->unsuspend && $this->conf->unsuspend_date_fix) {
                $this->date_fix();
            }
        }
        $action = $this->svc->unsuspend ? 'unsuspended' : 'suspended';
        return !$this->findErr('service for '
            . $this->svc->client->name() . ' was ' . $action);
    }

    private function set_profile(): bool
    {
        return $this->svc->pppoe
            ? $this->profile->set_profile()
            : $this->q->set_queue();
    }

    private function set_account(): bool // add/edit account
    {
        $action = $this->exists ? 'set' : 'add';
        $message = $this->exists ? 'updated' : 'added';
        $success = 'account for '.$this->svc->client->name()
            . ' was successfully '.$message;
        $this->set_profile()
            && $this->write($this->data(), $action)
            && $this->svc->save()
            && $this->disconnect() ;
        return !$this->findErr($success);
    }

    protected function data(): stdClass
    {
        return $this->svc->pppoe ? $this->pppoe_data() : $this->dhcp_data();
    }

    private function pppoe_data(): stdClass
    {
        return (object)[
            'remote-address' => $this->svc->ip(),
            'name' => $this->svc->username(),
            'caller-id' => $this->svc->callerId(),
            'password' => $this->svc->password(),
            'profile' => $this->profile(),
            'comment' => $this->comment(),
            '.id' => $this->insertId,
        ];
    }

    private function profile(): string
    {
        return $this->svc->disabled()
            ? $this->conf->disabled_profile : $this->svc->plan->name();
    }

    private function dhcp_data(): stdClass
    {
        return (object)[
            'address' => $this->svc->ip(),
            'mac-address' => $this->svc->mac(),
            'insert-queue-before' => 'bottom',
            'address-lists' => $this->address_list(),
            'comment' => $this->comment(),
            '.id' => $this->insertId,
        ];
    }

    protected function address_list(): string
    {
        return $this->svc->disabled() ? $this->conf->disabled_list : $this->conf->active_list;
    }

    private function disconnect(): bool
    {
        if (!$this->svc->pppoe) {
            return true;
        }
        $this->path = '/ppp/active/';
        $read = $this->read('?name=' . $this->svc->username());
        foreach ($read as $active)
        {
            $data['.id'] = $active['.id'];
            $this->write((object)$data,'remove');
        }
        $this->path = $this->path();
        return true;
    }


    protected function filter():string
    {
        return $this->svc->pppoe
            ? '?name='. $this->svc->username()
            : '?mac-address=' . $this->svc->mac();
    }

    public function findErr($success='ok'): bool
    {
        $calls = [&$this,&$this->profile,&$this->q];
        foreach ($calls as $call){
            if(!$call->status()->error){continue;}
            $this->status = $call->status();
            $this->svc->queue_job($this->status());
            return true;
        }
        $this->setMess($success);
        return false;
    }

    public function move(): bool
    {
        $this->svc->move(true);
        $this->init(); // switch device
        if (!$this->delete()) {
            return false;
        }
        $this->svc->move(false);
        $this->svc->plan->contention = $this->svc->exists() ? 0 : 1; // next contention after -1 delete
        $this->init(); // restore device
        if (!$this->insert()) {
            return false;
        }
        return true;
    }

    protected function init(): void
    {
        parent::init();
        $this->path = $this->path();
        if($this->svc) {
            $this->exists = $this->exists();
            $this->profile = new MT_Profile($this->svc);
            $this->q = new MT_Queue($this->svc);
        }
    }

    protected function path(): string
    {
        if(!$this->svc){ //default to pppoe
            return '/ppp/secret';
        }
        return $this->svc->pppoe ? '/ppp/secret/' : '/ip/dhcp-server/lease/';
    }

    public function delete(): bool
    {
        $this->svc->plan->contention = -1;
        $success = 'account for '. $this->svc->client->name()
            . ' has been deleted';
        if($this->exists) {
            $data['.id'] = $this->insertId;
            $this->set_profile()
             && $this->write((object)$data, 'remove')
             && $this->svc->delete()
             && $this->disconnect();
        }
        return !$this->findErr($success);
    }

    public function insert(): bool
    {
        return $this->set_account();
    }

    public function edit(): bool
    {
        return $this->set_account();
    }

}
