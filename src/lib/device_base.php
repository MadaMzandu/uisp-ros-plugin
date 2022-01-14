<?php

class Device_Base
{

    protected $svc; // service data object
    protected $data ; // none service data object
    protected $status; // execution status and errors
    protected $result; // output
    protected $read; // temporary reads for processing
    protected $conf;

    public function __construct($data,$service=true)
    {
        $this->svc = $service ? $this->toObject($data) : null;
        $this->data = $service ? null : $this->toObject($data);
        $this->init();
    }

    private function toObject($data)
    {
        if($data && (is_object($data) || is_array($data))) {
            return is_object($data)
                ? $data
                : json_decode(json_encode($data));
        }
        return (object)[];
    }

    protected function init(): void
    {
        $this->load_config();
        $this->status = (object)[];
        $this->status->error = false;
        $this->status->message = 'ok';
    }

    protected function load_config(): void
    {
        $this->conf = $this->db()->readConfig();
        if (!(array)$this->conf) {
            $this->setErr('failed to read plugin configuration');
        }
    }

    protected function db(): ?API_SQLite
    {
        try {
            return new API_SQLite();
        } catch (Exception $e) {
            $this->setErr($e->getMessage());
            return null;
        }


    }

    protected function setErr($msg): void
    {
        $this->status->error = true;
        $this->status->message = $msg;
    }

    public function status(): stdClass
    {
        return $this->status;
    }

    public function result()
    {
        return $this->result;
    }

    protected function error(): ?string
    {
        return $this->status->message;
    }

    protected function setMess($msg): void
    {
        $this->status->error = false;
        $this->status->message = $msg;
    }

}
