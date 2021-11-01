<?php

include_once 'mt.php';
include_once 'app_ipv4.php';
include_once 'app_uisp.php';

class MT_Account extends MT {

    private $profile;
    private $q;

    public function __construct(&$data) {
        parent::__construct($data);
        $this->path = $this->path();
        $this->profile = new MT_Profile($data);
        $this->q = new MT_Queue($data);
    }

    public function edit(): bool {
        if (!$this->ip_get($this->pool_device())) {
            return false;
        }
        $id = $this->entity->id;
        $data = $this->data();
        $data->{'.id'} = $this->savedId();
        if ($this->write($data)) {
            $this->disconnect();
            if (!$this->save()) {
                return false;
            }
            $this->set_message('service id:' . $id . ' was updated');
            return true;
        }
        return false;
    }

    public function insert(): bool {
        if (!$this->ip_get($this->pool_device())) {
            return false;
        }
        if (!$this->set_profile(1)) {
            return false;
        }
        if (!$this->write($this->data(), 'add')) {
            return false;
        }
        $this->insertId = $this->read;
        if (!$this->insertId) {
            return false;
        }
        if (!$this->save() && !$this->data->utilFlag) {
            return false;
        }
        $this->set_message('service has been added');
        return true;
    }

    public function insert_fix(): bool {
        if (!$this->exists()) {
            $this->data->changeType = 'insert';
            return $this->insert(); // normal insert
        }
        $account = $this->search[0];
        $this->data->ip = $account['address'] ?? $account['remote-address'];
        $q = $this->fix_queue();
        $this->fix_save($account, $q);
        $this->set_message('Account was found on router.Cache has been updated');
        return true;
    }

    private function fix_queue() {
        $this->set_profile(1);
        if ($this->is_pppoe()) {
            return null;
        }
        return $this->q->insertId();
    }

    private function fix_save($account, $q) {
        $data = (object) array(
                    'id' => $this->entity->id,
                    'planId' => $this->entity->servicePlanId,
                    'clientId' => $this->entity->clientId,
                    'mtId' => $account['.id'],
                    'address' => $this->data->ip,
                    'status' => $this->entity->status,
                    'device' => $this->device_id(),
                    'queueId' => $q,
        );
        return (new CS_SQLite())->insert($data);
    }

    public function suspend() {
        global $conf;
        $id = $this->entity->id;
        $count = $this->data->unsuspendFlag ? 0 : 1;
        if ($this->is_pppoe()) {
            $this->set_ppp_profile($count);
        }
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

    public function move(): bool {
        $this->data->actionObj = 'before';
        if (!$this->delete()) {
            $this->set_error('unable to delete old service');
            return false;
        }
        $this->data->actionObj = 'entity';
        if (!$this->insert()) {
            $this->set_error('unable to create service on new device');
            return false;
        }
        return true;
    }

    public function delete(): bool {
        if (!$this->set_profile(-1)) {
            return false;
        }
        $id = $this->{$this->data->actionObj}->id;
        $data = (object) ['.id' => $this->savedId()];
        if ($this->write($data, 'remove')) {
            $this->disconnect();
            $this->set_message('service id:' . $id . ' was deleted');
            if (in_array($this->data->changeType, ['delete', 'move', 'upgrade'])) {
                $this->clear();
            }
            return true;
        }
        return false;
    }

    protected function addr_list() {
        global $conf;
        return $this->is_disabled() ? $conf->disabled_list : $conf->active_list;
    }

    protected function savedId() {
        $id = $this->{$this->data->actionObj}->id;
        $db = new CS_SQLite();
        $savedId = $db->selectServiceMikrotikIdByServiceId($id);
        if ($savedId) {
            return $savedId;
        }
        if ($this->exists()) { // get id from router
            $saveId = $this->search[0]['.id'];
            $db->updateColumnById('mtId', $saveId, $id);
            return $saveId;
        }
        return false;
    }

    protected function save_data() {
        $data = parent::save_data();
        if ($this->insertId()) {
            $data->mtId = $this->insertId();
        }
        if ($this->q->insertId()) {
            $data->queueId = $this->q->insertId();
        }
        return $data;
    }

    private function path() {
        return $this->is_pppoe() ? '/ppp/secret/' : '/ip/dhcp-server/lease/';
    }

    private function data() {
        return $this->is_pppoe() ? $this->pppoe_data() : $this->dhcp_data();
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
        global $conf;
        return (object) array(
                    'address' => $this->data->ip,
                    'mac-address' => $this->entity->{$conf->mac_addr_attr},
                    'insert-queue-before' => 'bottom',
                    'address-lists' => $this->addr_list(),
                    'comment' => $this->comment(),
        );
    }

    private function pppoe_data() {
        global $conf;
        $profile = $this->entity->status == 1 ? $this->entity->servicePlanName : $conf->disabled_profile;
        return (object) array(
                    'remote-address' => $this->data->ip,
                    'name' => $this->entity->{$conf->pppoe_user_attr},
                    'password' => $this->entity->{$conf->pppoe_pass_attr},
                    'profile' => $profile,
                    'comment' => $this->comment(),
        );
    }

    private function set_profile($int) {
        return $this->is_pppoe() ? $this->set_ppp_profile($int) : $this->set_dhcp_queue($int);
    }

    private function set_ppp_profile($int) {
        if (!$this->profile->set($int)) { //or set profile
            $this->set_error($this->profile->error());
            return false;
        }
        return true;
    }

    private function set_dhcp_queue($int) {
        if ($int > 0) {
            if (!$this->q->insert()) {
                $this->set_error($this->q->error());
                return false;
            }
            $this->data->queueId = $this->q->insertId();
        } else {
            if (!$this->q->delete()) {
                $this->set_error($this->q->error());
                return false;
            }
        }
        return true;
    }

    private function disconnect() {
        if (!$this->is_pppoe()) {
            return;
        }
        $this->path = '/ppp/active/';
        if ($this->read('?comment')) {
            $this->findByComment();
            foreach ($this->search as $item) {
                
            }
        }
        $this->path = $this->path();
    }

}
