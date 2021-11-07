<?php

class Device_Base
{

    protected $svc; // service data object
    protected $status; // execution status and errors
    protected $result; // output
    protected $read; // temporary reads for processing
    protected $conf;

    public function __construct(&$svc)
    {
        $this->svc = $svc;
        $this->init();
    }

    protected function init(): void
    {
        $this->load_config();
        $this->status = (object)[];
        $this->status->error = false;
        $this->status->message = 'ok';
    }

    protected function load_config()
    {
        $this->conf = $this->db()->readConfig();
        if (!(array)$this->conf) {
            $this->setErr('failed to read plugin configuration');
        }
    }

    protected function db()
    {
        try {
            return new API_SQLite();
        } catch (Exception $e) {
            $this->setErr($e->getMessage());
            return null;
        }


    }

    protected function setErr($msg)
    {
        $this->status->error = true;
        $this->status->message = $msg;
    }

    public function status()
    {
        return $this->status;
    }

    public function result()
    {
        return $this->result;
    }

    protected function error()
    {
        return $this->status->message;
    }

    protected function setMess($msg)
    {
        $this->status->error = false;
        $this->status->message = $msg;
    }

}
