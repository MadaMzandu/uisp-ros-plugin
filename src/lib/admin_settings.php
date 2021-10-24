<?php

class Settings extends Admin {

    public function edit() {
        $db = new CS_SQLite();
        if($db->saveConfig($this->data)) {
            $this->set_message('configuration has been updated');
            return true ;
        }
        $this->set_error('failed to update configuration');
        return false ;
    }
    
    
    public function get() {
        
        $this->read = (new CS_SQLite())->readConfig();
        if (!$this->read) {
            $this->set_error('failed to read settings');
            return false;
        }
        $this->result = $this->read ;
        //$this->result->attributes = $this->get_attributes();
        return true ;
    }
    
    private function get_attributes(){
        $u = new CS_UISP();
        $u->assoc = true ;
        $read = $u->request('/custom-attributes') ?? [];
        $return = [];
        foreach($read as $item){
            if($item['attributeType'] != 'service') continue ;
            $return[] = $item ;
        }
        return $return ?? [];
    }

}
