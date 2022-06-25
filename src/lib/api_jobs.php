<?php
include_once 'api_router.php';

class Api_Jobs extends Admin
{ //scheduled job queue
    private $queue;
    private $file = 'data/queue.json';



    protected function init(): void
    {
        parent::init();
        $this->queue = $this->read();
    }

    public function list()
    {
        $this->result = $this->queue ?? [];
    }

    public function clear()
    {
        file_put_contents($this->file,null);
    }

    public function delete()
    {
        $id = $this->data->id ?? 0 ;
        unset($this->queue->$id);
        $this->save();
    }

    public function run()
    {
        if (!function_exists('fastcgi_finish_request')) {
            shell_exec('php lib/shell.php jobs > /dev/null 2>&1 &');
            return;
        } else {
            $this->status->status = 'ok';
            $this->status->data = [];
            header('content-type: application/json');
            echo json_encode($this->status);
            fastcgi_finish_request();
        }
        set_time_limit(300);
        if (is_object($this->queue)) {
            $ids = array_keys((array)$this->queue);
            foreach ($ids as $id) {
                $status = $this->run_item($this->queue->$id);
                if ($status->error) {
                    $this->queue->$id->last = (new DateTime())->format('Y-m-d H:i:s');
                    $this->queue->$id->status = $status;
                } else {
                    unset($this->queue->$id);
                }
            }
            $this->save();
        }
    }

    private function run_item($item): object
    {
        $item->data->queued = true ; // in case it fails again
        $api = new API_Router($item->data);
        $api->route();
        return $api->status();
    }

    private function read(): ?object
    {
        $json = null ;
        if(file_exists($this->file)){
            $read = file_get_contents($this->file) ;
            $json = is_string($read) ? $read: null;
        }
        return json_decode($json);
    }

    private function save(): bool
    {
        $json = json_encode($this->queue) ;
        return file_put_contents($this->file,$json);
    }
}