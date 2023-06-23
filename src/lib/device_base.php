<?php

class Device_Base
{
    protected $data ; // none service data object
    protected $status; // execution status and errors
    protected $result; // output
    protected $read; // temporary reads for processing
    protected $conf;

    public function __construct($data = null)
    {
        $this->data = $this->toObject($data);
        $this->init();
    }

    private function toObject($data): ?stdClass
    {
        if($data && (is_object($data) || is_array($data))) {
            return is_object($data)
                ? $data
                : json_decode(json_encode($data));
        }
        return null;
    }

    protected function init(): void
    {
        $this->load_config();
        $this->status = json_decode('{"error":false,"message":"ok"}');
    }

    protected function load_config(): void
    {
        $this->conf = $this->db()->readConfig();
        if (!(array)$this->conf) {
            $this->setErr('failed to read plugin configuration');
        }
    }

    protected function db(): ?ApiSqlite
    {
        return new ApiSqlite();
    }

    protected function dbCache(): ?ApiSqlite
    {
        return new ApiSqlite('data/cache.db');
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

}
