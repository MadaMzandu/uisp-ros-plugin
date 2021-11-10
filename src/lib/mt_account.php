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
        if ($this->set_profile() && $this->edit()) {
            if ($this->svc->unsuspend && $this->conf->unsuspend_date_fix) {
                $this->date_fix();
            }
            $action = $this->svc->unsuspend ? 'unsuspended' : 'suspended';
            $this->setMess('service for ' . $name . ' was ' . $action);
            return true;
        }
        return false;
    }

    private function set_profile()
    {
        return $this->svc->pppoe ? $this->set_ppp_profile() : $this->set_dhcp_queue();
    }

    private function set_ppp_profile()
    {
        if (!$this->profile->set()) { //or set profile
            $this->setErr($this->profile->error());
            return false;
        }
        return true;
    }

    private function set_dhcp_queue()
    {
        return $this->q->set();
    }

    public function edit()
    {
        $this->svc->contention = $this->svc->exists ? 0 : 1;
        $action = $this->exists ? 'set' : 'add';
        $message = $this->exists ? 'updated' : 'added';
        if ($this->set_profile()
            && $this->write($this->data(), $action)
            && $this->save()
            && $this->disconnect()) {
            $this->setMess('service for '
                . $this->svc->client->name() . ' was ' . $message);
            return true;
        }
        $this->findErr();
        return false;
    }

    protected function data()
    {
        return $this->svc->pppoe ? $this->pppoe_data() : $this->dhcp_data();
    }

    private function pppoe_data(): object
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
        return $this->svc->disabled
            ? $this->conf->disabled_profile : $this->svc->plan->name();
    }

    private function dhcp_data(): object
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
        return $this->svc->disabled ? $this->conf->disabled_list : $this->conf->active_list;
    }

    private function disconnect()
    {
        if (!$this->svc->pppoe) {
            return true;
        }
        if ($this->read('?name=' . $this->svc->username())) {
            $this->findByComment();
            foreach ($this->search as $item) {
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

    protected function findErr()
    {
        if ($this->profile->status()->error) {
            $this->status = $this->profile->status();
        }
        if ($this->q->status()->error) {
            $this->status = $this->q->status();
        }
    }

    public function move()
    {
        $this->svc->move = true;
        $this->init(); // switch device
        if (!$this->delete()) {
            $this->setErr('unable to delete old service');
            return false;
        }
        $this->svc->move = false;
        $this->init(); // restore device
        if (!$this->insert()) {
            $this->setErr('unable to create service on new device');
            return false;
        }
        return true;
    }

    protected function init(): void
    {
        parent::init();
        $this->path = $this->path();
        $this->exists = $this->exists();
        $this->profile = new MT_Profile($this->svc);
        $this->q = new MT_Queue($this->svc);
    }

    protected function path()
    {
        return $this->svc->pppoe ? '/ppp/secret/' : '/ip/dhcp-server/lease/';
    }

    public function delete()
    {
        if (!$this->exists) {
            $this->setErr('Account was not found on specified device');
            return false;
        }
        $this->svc->contention = -1;
        if (!$this->set_profile()) {
            return false;
        }
        $data = (object)['.id' => $this->insertId];
        if ($this->write($data, 'remove')
            && $this->svc->delete() && $this->disconnect()) {
            $this->setMess('service for '
                . $this->svc->client->name() . ' was deleted');
            return true;
        }
        $this->findErr();
        return false;
    }

    public function insert()
    {
        if ($this->exists) {
            return $this->edit();
        }
        $this->svc->contention = +1;
        if ($this->set_profile()
            && $this->write($this->data(), 'add')
            && $this->save()) {
            $this->setMess('service for '
                . $this->svc->client->name() . ' has been added');
            return true;
        }
        $this->findErr();
        return false;
    }

}
