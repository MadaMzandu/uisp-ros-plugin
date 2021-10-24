<?php

class Admin {
    protected $data ;
    protected $status ;
    protected $result ;
    
    public function __construct(&$data) {
        $this->data = $data;
        $this->status = new stdClass();
        $this->status->error = false ;
        $this->status->message = 'ok';
        $this->status->data = [];
    }
    
    public function exec(){
        echo "in exec\n";
        $target = $this->data->target ;
        $action = $this->data->action ;
        $service = new $target($this->data->data);
        $service->$action();
        $this->status = $service->status();
        $this->result = $service->result();
    }
    
    public function status(){
        return $this->status ;
    }
    
    public function result(){
        return $this->result ;
    }
    
}

class services extends Admin{
    public function get(){
        $db = new CS_SQLite();
        $this->result = $db->get_all();
    }
}