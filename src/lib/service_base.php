<?php
include_once 'api_sqlite.php';

class Service_Base
{

    public $ready ;
    public $move ;
    protected $status;
    protected $data;
    protected $entity;
    protected $before;
    protected $conf;

    public function __construct(&$data)
    {
        $this->data = $data;
        $this->init();
    }

    protected function init(): void
    {
        $this->ready = true ;
        $this->status = (object)[];
        $this->status->ready = &$this->ready;
        $this->status->error = false;
        $this->status->message = 'ok';
        $this->get_config();
        $this->set_shortcuts();
    }

    public function exists(): bool
    {
       return (bool)$this->db()
            ->ifServiceIdExists($this->entity->id);
    }

    protected function set_shortcuts()
    {
        $this->entity = $this->data->extraData->entity ?? (object)[];
        $this->before = $this->data->extraData->entityBeforeEdit ?? (object)[];
    }

    protected function get_config()
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