<?php
include_once 'api_router.php';
include_once 'batch.php';

class ApiJobs extends Admin
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

    public function run(): void
    {
        if(function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        set_time_limit(7200);
        $delete = [];
        $set = [];
        $this->clear();
        foreach ($this->queue as $item){
            $action = $item['action'] ??  null;
            if($action == 'update'){ $set[] = $item['id']; }
            if($action == 'delete'){ $delete[] = $item['id'] ;}
        }
        $api = new Batch();
        $api->set_accounts($set);
        $api->del_accounts($delete);
    }

    private function read()
    {
        $json = '{}' ;
        if(file_exists($this->file)){
            $json = file_get_contents('data/queue.json') ?? '{}';
        }
        return json_decode($json,true);
    }

    private function save(): bool
    {
        $json = json_encode($this->queue) ;
        return file_put_contents($this->file,$json);
    }
}