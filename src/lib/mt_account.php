<?php

include_once 'mt.php';

class MT_Account extends MT {

    private $profile;
    private $q;

    public function __construct(Service &$svc) {
        parent::__construct($svc);
        $this->path = $this->path();
        $this->profile = new MT_Profile($svc);
        $this->q = new MT_Queue($svc);
    }

    public function edit() {
        $data = (object)$this->data();
        if ($this->write($data, 'set')) {
            $this->disconnect();
            if (!$this->save()) {
                return false;
            }
            $this->set_message('service for '
                    .$this->svc->client_name().' was updated');
            return true;
        }
        return false;
    }

    public function insert() {

        $this->svc->count = 1;
        if (!$this->set_profile()) {
            return false;
        }
        if (!$this->write($this->data(), 'add')) {
            return false;
        }
        $this->save['mtId'] = $this->read ;
        if (!$this->save()) {
            return false;
        }
        $this->set_message('service for '
                .$this->svc->client_name().' has been added');
        return true;
    }

    public function insert_fix() { // attempt to rebuild orphans
        if (!$this->exists()) {
            return $this->insert(); //normal insert if not fount
        }
        $account = $this->search[0];
        $q = $this->fix_queue_id();

        if ($this->fix_save($account, $q)) {
            $this->set_message('account for '
                    .$this->svc->client_name().' was successfully repaired');
            return true;
        }
        $this->set_error('failed to repair account for '
                .$this->svc->client_name());
        return false;
    }

    private function fix_queue_id() {
        if ($this->svc->pppoe) { //if dhcp
            return null;
        }
        $q = $this->q->findId();
        $this->svc->count = 1;
        return $q ? $q : ($this->set_profile() ? $this->q->insertId() : false);
    }

    private function fix_save($account, $q) {
        $this->save['address'] = isset($account['address']) 
                ? $account['address'] : $account['remote-address'];
        $this->save['mtId'] = $account['.id'] ?? null;
        $this->save['queueId'] = $q ?? null;
        return $this->save();
    }

    public function suspend() {
        global $conf;
        $name = $this->svc->client_name();
        if ($this->svc->pppoe) {
            $this->svc->count = 1;
            if(!$this->set_ppp_profile()){
                return false;
            }
        }
        if ($this->edit()) {
            if ($this->svc->unsuspend && $conf->unsuspend_date_fix) {
                $this->date_fix();
            }
            $action = $this->svc->unsuspend ? 'unsuspended' : 'suspended';
            $this->set_message('service for ' . $name . ' was ' . $action);
            return true;
        }
        return false;
    }

    public function move() {
        $this->svc->move = true;
        if (!$this->delete()) {
            $this->set_error('unable to delete old service');
            return false;
        }
        $this->svc->move = false;
        if (!$this->insert()) {
            $this->set_error('unable to create service on new device');
            return false;
        }
        return true;
    }

    public function delete() {
        if (!$this->set_profile(-1)) {
            return false;
        }
        $data = (object) ['.id' => $this->svc->record()->mtId];
        if ($this->write($data, 'remove')) {
            $this->disconnect();
            $this->set_message('service was deleted');
            if (in_array($this->svc->action, ['delete', 'move', 'upgrade'])) {
                $this->svc->delete();
            }
            return true;
        }
        return false;
    }

    protected function addr_list() {
        global $conf;
        return $this->svc->disabled ? $conf->disabled_list : $conf->active_list;
    }
    
    protected function path() {
        return $this->svc->pppoe ? '/ppp/secret/' : '/ip/dhcp-server/lease/';
    }

    protected function data() {
        return $this->svc->pppoe ? $this->pppoe_data() : $this->dhcp_data();
    }

    private function dhcp_data() {
        return [
                    'address' => $this->svc->ip(),
                    'mac-address' => $this->svc->mac(),
                    'insert-queue-before' => 'bottom',
                    'address-lists' => $this->addr_list(),
                    'comment' => $this->comment(),
                    '.id' => $this->svc->mt_account_id(),
        ];
    }

    private function pppoe_data() {
        global $conf;
        $profile = $this->svc->disabled 
                ? $conf->disabled_profile : $this->svc->plan_name();
        return [
                    'remote-address' => $this->svc->ip(),
                    'name' => $this->svc->username(),
                    'password' => $this->svc->password(),
                    'profile' => $profile,
                    'comment' => $this->comment(),
                    '.id' => $this->svc->mt_account_id(),
        ];
    }
    
    private function set_profile() {
        return $this->svc->pppoe ? $this->set_ppp_profile() : $this->set_dhcp_queue();
    }

    private function set_ppp_profile() {
        if (!$this->profile->set()) { //or set profile
            $this->set_error($this->profile->error());
            return false;
        }
        return true;
    }

    private function set_dhcp_queue() {
        if ($this->svc->count > 0) {
            if (!$this->q->insert()) {
                $this->set_error($this->q->error());
                return false;
            }
            $this->save['queueId'] = $this->q->insertId();
        } else {
            if (!$this->q->delete()) {
                $this->set_error($this->q->error());
                return false;
            }
        }
        return true;
    }

    private function disconnect() {
        if (!$this->svc->pppoe) {
            return;
        }
        if ($this->read('?comment')) {
            $this->findByComment();
            foreach ($this->search as $item) {
                
            }
        }
    }

}
