<?php

include_once 'mt.php';

class MT_Account extends MT {

    private $profile;
    private $q;

    public function __construct(&$svc) {
        parent::__construct($svc);
        $this->path = $this->path();
        $this->profile = new MT_Profile($svc);
        $this->q = new MT_Queue($svc);
    }

    public function edit() {
        
        $data = $this->svc->data();
        if ($this->write($data, 'set')) {
            $this->disconnect();
            if (!$this->save()) {
                return false;
            }
            $this->set_message('service was updated');
            return true;
        }
        return false;
    }

    public function insert() {
        //if($this->svc->fix){
            //return $this->util_exec();
        //}
        
        $this->svc->count = 1 ;
        if (!$this->set_profile()) {
            return false;
        }
        if (!$this->write($this->data(), 'add')) {
            return false;
        }
        $this->insertId = $this->read;
        if (!$this->insertId) {
            return false;
        }
        if (!$this->save()) {
            return false;
        }
        $this->set_message('service has been added');
        return true;
    }
    
    public function insert_fix(){ // attempt to rebuild orphans
        if(!$this->exists() && !$this->q->exists()){
            $this->svc->fix = false ; //normal insert if not fount
            return $this->insert() ;
        }
        $this->insertId = $this->search[0]['.id'];
        $this->queueId = $this->q->read()[0]['.id'];
        
        var_dump($this->q->insertId());
        exit();
        $q = $this->fix_queue_check($account);
        $this->fix_save($account);
        $this->set_message('Account was found on router.Cache has been updated');
        return true ;
    }
    
    private function fix_queue_check($account){
        $id = false ; //q id
        if(isset($account['mac-address'])){ //if dhcp
            $id = $this->q->exists() ;
            $this->queueId = $id  ? $this->q->search[0]['.id']: null;
        }
        return $id ? : $this->set_profile();
    }
    
    
    private function fix_save($account){
        $data['address'] = isset($account['address']) 
                           ? $account['address'] : $account['remote-address'];
        $data['mtId'] = $this->insertId;
        $data['queueId'] = $this->queueId;
        return $this->svc->save((object)$data);
    }

    public function suspend() {
        global $conf;
        $id = $this->entity->id;
        if ($this->edit()) {
            if ($this->data->unsuspendFlag && $conf->unsuspend_date_fix) {
                $this->fix();
            }
            $action = $this->data->unsuspendFlag ? 'unsuspended' : 'suspended';
            $this->set_message('service id:' . $id . ' was ' . $action);
            return true;
        }
        return false;
    }

    public function move() {
        $this->svc->move = true ;
        if (!$this->delete()) {
            $this->set_error('unable to delete old service');
            return false;
        }
        $this->svc->move = false ;
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
        return $this->svc->disabled 
                ? $conf->disabled_list 
                : $conf->active_list;
    }

    protected function savedId() {
        $savedId = $this->svc->mt_account_id();
        if ($savedId) {
            return $savedId;
        }
        if ($this->exists()) { // for old installations
            $saveId = $this->search[0]['.id'];
            return $saveId;
        }
    }
    
    protected function save() {
        $data = (object)[];
        if ($this->insertId()) {
            $data->mtId = $this->insertId();
        }
        if ($this->q->insertId()) {
            $data->queueId = $this->q->insertId();
        }
        return $this->svc->save($data);
    }

    protected function path() {
        return $this->svc->pppoe ? '/ppp/secret/' : '/ip/dhcp-server/lease/';
    }

    protected function data() {
        return $this->svc->pppoe ? $this->pppoe_data() : $this->dhcp_data();
    }

    private function pool_device() {
        global $conf;
        $obj = $this->{$this->data->actionObj};
        if ($conf->router_ppp_pool || $obj->{$conf->mac_addr_attr}) {
            return $this->{$this->data->actionObj}->{$conf->device_name_attr};
        }
        return false;
    }

    private function dhcp_data() {
        $data = (object) array(
                    'address' => $this->svc->ip(),
                    'mac-address' => $this->svc->mac(),
                    'insert-queue-before' => 'bottom',
                    'address-lists' => $this->addr_list(),
                    'comment' => $this->comment(),
        );
        return $data;
    }

    private function pppoe_data() {
        global $conf;
        $profile = $this->svc->disabled 
                ? $conf->disabled_profile
                : $this->svc->plan_name();
        return (object) array(
                    'remote-address' => $this->svc->ip(),
                    'name' => $this->svc->username(),
                    'password' => $this->svc->password(),
                    'profile' => $profile,
                    'comment' => $this->comment(),
        );
    }

    private function set_profile() {
        return $this->svc->pppoe 
                ? $this->set_ppp_profile() 
                : $this->set_dhcp_queue();
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
            $this->queueId = $this->q->insertId();
        } else {
            if (!$this->q->delete()) {
                $this->set_error($this->q->error());
                return false;
            }
        }
        return true;
    }

    private function disconnect() {
        if(!$this->svc->pppoe){
            return ;
        }
        if ($this->read('?comment')) {
            $this->findByComment();
            foreach ($this->search as $item) {
                
            }
        }
    }

}
