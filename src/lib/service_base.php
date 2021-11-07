<?php
include_once 'api_sqlite.php';

class Service_Base
{

    public $ready = true;
    protected $status;
    protected $data;
    protected $entity;
    protected $before;
    protected $conf;
    //protected $db; //database object ;

    public function __construct(&$data)
    {
        $this->data = $data;
        $this->init();
    }

    protected function init()
    {
        $this->status = (object)[];
        $this->status->error = false;
        $this->status->message = 'service:ok';
        $this->load_config();
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
            $this->ready = false;
            $this->setErr($e->getMessage());
            return null;
        }


    }

    protected function setErr($err)
    {
        $this->ready = false;
        $this->status->error = true;
        $this->status->messsage = $err;
    }

    public function status()
    {
        return $this->status;
    }

    public function error()
    {
        return $this->status->message;
    }

    public function entity()
    {
        return $this->data->extraData->entity;
    }

}