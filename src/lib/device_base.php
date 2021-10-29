<?php

class Device_Base {

    protected $svc; // service data object
    protected $status; // execution status and errors
    protected $result; // output
    protected $read; // temporary reads for processing

    public function __construct(&$svc) {
        $this->svc = $svc;
        $this->init();
    }

    public function status() {
        return $this->status;
    }
    
    public function result() {
        return $this->result;
    }

    protected function error() {
        return $this->status->message;
    }
    
    protected function init() {
        $this->status = (object) [];
        $this->status->session = false;
        $this->status->error = false;
    }

    protected function set_message($msg) {
        $this->status->error = false;
        $this->status->message = $msg;
    }

    protected function set_error($msg) {
        $this->status->error = true;
        $this->status->message = $msg;
    }

}
