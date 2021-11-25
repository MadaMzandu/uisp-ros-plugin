<?php

include_once 'mt.php';
include_once 'mt_queue.php';
include_once 'mt_profile.php';
include_once 'mt_parent_queue.php';

class MT_Account extends MT
{

    private $profile;
    private $q;

    public function suspend()
    {
        $name = $this->svc->client->name();
        if ($this->move()) {
            if ($this->svc->unsuspend && $this->conf->unsuspend_date_fix) {
                $this->date_fix();
            }
            $action = $this->svc->unsuspend ? 'unsuspended' : 'suspended';
            $this->setMess('service for ' . $name . ' was ' . $action);
            return true;
        }
        return false;
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
            . 'was successfully '.$message;
        $this->set_profile()
            && $this->write($this->data(), $action)
            && $this->svc->save()
            && $this->disconnect() ;
        return !$this->findErr($success);
    }

    protected function data()
    {
        return $this->svc->pppoe ? $this->pppoe_data() : $this->dhcp_data();
    }

    private function pppoe_data(): stdClass
    {
        return (object)[
            'remote-address' => $this->svc->ip(),
            'name' => $this->svc->username(),
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
            'address-lists' => $this->addr_list(),
            'comment' => $this->comment(),
            '.id' => $this->insertId,
        ];
    }

    protected function addr_list()
    {
        return $this->svc->disabled() ? $this->conf->disabled_list : $this->conf->active_list;
    }

    private function disconnect()
    {
        if ($this->svc->pppoe) {
            if ($this->read('?name=' . $this->svc->username())) {
                foreach ($this->read as $item) {
                }
            }
        }
        return true;
    }


    protected function filter():string
    {
        return $this->svc->pppoe
            ? '?name='. $this->svc->username()
            : '?mac-address=' . $this->svc->mac();
    }

    protected function findErr($success=''): bool
    {
        if($this->status->error){
            return true ;
        }
        if ($this->profile->status()->error) {
            $this->status = $this->profile->status();
            $this->true ;
        }
        if ($this->q->status()->error) {
            $this->status = $this->q->status();
            return true ;
        }
        $this->setMess($success);
        return false ;
    }

    public function move(): bool
    {
        $this->svc->move(true);
        $this->init(); // switch device
        if (!$this->delete()) {
            $this->setErr('unable to delete old service');
            return false;
        }
        $this->svc->move(false);
        $this->svc->plan->contention = $this->svc->exists() ? 0 : 1;
        $this->init(); // restore device
        if (!$this->insert()) {
            $this->setErr('unable to create service on new device');
            return false;
        }
        $this->setMess('account for '.$this->svc->client->name().' was updated');
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
