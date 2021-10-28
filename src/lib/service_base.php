<?php
include_once 'app_sqlite.php';

class Service_Base {
    
    public $ready = true ;
    protected $status ;
    protected $_data;
    protected $entity;
    protected $before;
    protected $conf;
    protected $db; //database object ;
    
    public function __construct(&$data) {
        $this->data = $data ;
        $this->init();
    }
    
    public function status(){
        return $this->status ;
    }
    
    public function error(){
        return $this->status->message ;
    }
    
    protected function init() {
        $this->status = (object) [];
        $this->status->error = false;
        $this->status->message = 'ok';
        $this->load_db();
        $this->load_config();
    }
    
    protected function load_db() {
        $this->db = new API_SQLite();
        if(!$this->db){
            $this->setErr('failed to open database cache');
        }
    }

    protected function load_config() {
        $this->conf = $this->db->readConfig();
        if(!(array)$this->conf){
            $this->setErr('failed to read plugin configuration');
        }
    }
        
    protected function setErr($err) {
        $this->ready = false;
        $this->status->error = true;
        $this->status->messsage =$err;
    }
    
    protected function setMess($mess) {
        $this->status->error = false;
        $this->status->message = $mess;
    }
    
    
}