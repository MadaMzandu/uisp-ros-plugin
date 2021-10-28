<?php

include_once 'app_mysql.php';

class Radius_Account extends Device {

    private $db; // radius mysql backend

    public function __construct(&$data) {
        parent::__construct($data);
        $this->getDevice();
        $this->db = new API_Mysql($this->device);
    }
  
    public function suspend() {
        $this->edit(); 
    }
    
    public function upgrade(){
        $this->data->actionObj = 'before';
        if(!$this->delete()){
            return false ;
        }
        $this->data->actionObj = 'entity';
        if($this->insert()){
            $this->set_message('radius account:'.$this->username()." has been updated");
            return true;
        }
        return false ;
    }
    
    public function move(){
        $this->upgrade();
    }

    public function edit() {
        if (!$this->update($this->radcheck_data(), 'radcheck')) {
            $this->set_error($this->db->error());
            return false;
        }
        foreach ($this->radreply_data() as $data) {
            if (!$this->update($data, 'radreply')) {
                $this->set_error($this->db->error());
                return false;
            }
        }
        if (!$this->save()) {
            $this->set_error('failed to update rpa database');
            return false;
        }
        $this->set_message("radius service:".$this->username()." has been updated");
        return true;
    }


    private function update($data, $table) {
        $data['id'] = $this->db->selectRadId($data, $table);
        $this->db->update($data, $table);
    }

    public function delete() {
        if (!$this->db->deleteRadiusAccount($this->username())) {
            $this->set_error($this->db->error());
            return false;
        }
        if (in_array($this->data->changeType, ['delete', 'move', 'upgrade'])) {
            $this->clear();
        }
        $this->set_message("radius account:".$this->username()." has been deleted");
        return true;
    }

    public function insert() {
        if($this->db->radiusAccountExists($this->username())){
            $this->set_error('radius account:'.$this->username()." already exists");
            return false ;
        }
        if(!$this->db->insert($this->radcheck_data(), 'radcheck')){
            $this->set_error($this->db->error());
            return false ;
        }
        foreach ($this->radreply_data() as $data) {
            if(!$this->db->insert($data, 'radreply')){
                $this->set_error($this->db->error());
                return false ;
            }
        }
        if(!$this->save()){
            $this->set_error('failed to update rpa database');
            return false ;
        }
        $this->set_message("radius account:".$this->username()." has been added");
        return true ;
    }

    private function radcheck_data() {
        global $conf;
        $obj = &$this->{$this->data->actionObj};
        return [
            'username' => $this->username(),
            'attribute' => $obj->{$conf->mac_addr_attr} ? 'Auth-Type' : 'Cleartext-Password',
            'op' => ':=',
            'value' => $this->Cleartext_Password(),
        ];
    }

    private function radreply_data() {
        $data = [];
        foreach ($this->replies() as $key) {
            $data[] = [
                'username' => $this->username(),
                'attribute' => str_replace('_', '-', $key),
                'op' => ':=',
                'value' => $this->$key(),
            ];
        }
        return $data;
    }

    private function username() {
        global $conf;
        $obj = &$this->{$this->data->actionObj};
        return $obj->{$conf->mac_addr_attr} ?? $obj->{$conf->pppoe_user_attr};
    }

    private function replies() {
        return $this->is_pppoe() 
                ?  $this->ppp_replies() 
                : $this->dhcp_replies() ;
    }
    
    private function ppp_replies(){
        return [
            'Framed_IP_Address', 
            'Mikrotik_Rate_Limit', 
            'Mikrotik_Address_List',
            'Mikrotik-Group',
            ];
    }
    
    private function dhcp_replies(){
        return [
            'Framed_IP_Address', 
            'Mikrotik_Rate_Limit', 
            'Mikrotik_Address_List',
            ];
    }

    private function Mikrotik_Address_List() {
        global $conf;
        return $this->is_disabled() ? $conf->disabled_list : $conf->active_list;
    }

    private function Cleartext_Password() {
        global $conf;
        $obj = &$this->{$this->data->actionObj};
        return $this->is_pppoe() ? $obj->{$conf->pppoe_pass_attr} : 'Accept';
    }

    private function Framed_IP_Address() {
        $this->ip_get();
        return $this->data->ip;
    }

    private function Mikrotik_Group() {
        global $conf;
        $obj = &$this->{$this->data->actionObj};
        return $this->is_disabled() ? $conf->disabled_profile : $obj->servicePlanName;
    }

    private function Mikrotik_Rate_Limit() {
        $obj = &$this->{$this->data->actionObj};
        return $obj->uploadSpeed . "M/" . $obj->downloadSpeed . "M";
    }
    
    private function set_profile(){
        if(!$this->is_pppoe()){
            return true ;
        }
        $list = $this->db->selectNasAddresses();
        (new MT_Profile($this->data))->set_nas($list);
        return true ;
    }

}
