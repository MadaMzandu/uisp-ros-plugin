<?php

include_once 'routeros_api.class.php';

class MT {

    protected $svc ;
    protected $path ;
    protected $insertId ;
    protected $result ;
    protected $status ;
    protected $read ;
    protected $search;


    public function __construct(Service &$svc) {
        $this->svc = $svc;
        $this->init();
    }
    
    public function status() {
        return $this->status;
    }
    
    public function result() {
        return $this->result;
    }
    
    protected function init(){
        $this->status = (object) [];
        $this->status->session = false;
        $this->status->error = false;
    }
    
     protected function rate(){
         return $this->svc->rate();
     }

    protected function read($filter = false) {  //implements mikrotik print
        $api = $this->connect();
        if (!$api) {
            return false;
        }
        try {
            $api->write($this->path . 'print', false);
            if ($filter) {
                $api->write($filter, false);
            }
            $api->write(";");
            $this->read = $api->read();
            $api->disconnect();
            return $this->read ;
        } catch (Exception $e) {
            $api->disconnect();
            $this->set_error($e);
            return false;
        }
    }
    
    protected function insertId() {
        return $this->insertId;
    }

    protected function write($data, $action = 'set') {
        if($action == 'add'){
            unset($data->{'.id'});
        }
        $api = $this->connect();
        if (!$api) {
            return false;
        }
        try {
            $api->write($this->path . $action, false);
            foreach (array_keys((array) $data) as $key) {
                $api->write('=' . $key . '=' . $data->$key, false);
            }
            $api->write(';'); // trailing semi-colon works
            $this->read = $api->read();
            $api->disconnect();            
            if (!$this->read || is_string($this->read)) { //don't care what's inside the string?
                $this->set_message('rosapi write:ok');
                return is_string($this->read) ? $this->read : true ;
            }
            $this->set_error('rosapi write:failed', true);
            return false;
        } catch (Exception $e) {
            $api->disconnect;
            $this->set_error($e);
            return false;
        }
    }

    private function connect() {
        $d = $this->svc->device();
        try {
            $api = new Routerosapi();
            $api->timeout = 3;
            $api->attempts = 1;
            // $api->debug = true;
            if ($api->connect($d->ip, $d->user, $d->password)) {
                return $api;
            }
            $this->set_error('rosapi:connect failed');
            return false;
        } catch (Exception $e) {
            $this->set_error($e);
            return false;
        }
    }
    
        protected function exists() {
        $this->read('?comment');
        if ($this->read) {
            $this->findByComment();
            if ($this->search) {
                return true ;
            }
        }
        return false;
    }

    protected function findByComment() {
        $e = sizeof($this->read);
        $this->search = [];
        for ($i = 0; $i < $e; $i++) {
            $item = $this->read[$i];
            [$id] = explode(',', $item['comment']);
            if ($id == $this->svc->id()) {
                $this->insertId = $item['.id'];
                $this->search[] = $item;
            }
        }
    }
    
    protected function findId(){
        if($this->exists()){
            return $this->insertId;
        }
        return false ;
    }




    protected function comment() {
        return $this->svc->id() . ", "
                . $this->svc->client_id() . " - "
                . $this->svc->client_name();
    }
    
    
    protected function error(){
        return $this->status->message ;
    }


    protected function set_message($msg) {
        $this->status->error = false;
        $this->status->message = $msg;
    }

    protected function set_error($msg, $obj = false) {
        $this->status->error = true;
        if ($obj) {
            $this->status->message = $this->read['!trap'][0]['message'];
        } else {
            $this->status->message = $msg;
        }
    }
   

}
