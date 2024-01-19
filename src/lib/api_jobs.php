<?php
include_once 'api_router.php';
include_once 'batch.php';

class ApiJobs extends Admin
{ //scheduled job queue
    private ?object $queue = null ;
    private string $fn = 'data/queue.json';

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
        file_put_contents($this->fn,null);
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
            if($action == 'delete'){ $delete[] = $item['id'] ;}
            else{ $set[] = $item['id'] ; }
        }
        $api = new Batch();
        if($set) { $api->set_accounts($set); }
        if($delete) { $api->del_accounts($delete); }
    }

    private function read()
    {
        $r = null ;
        if(is_file($this->fn)){
            $r = json_decode(file_get_contents($this->fn));
        }
        return is_object($r) ? $r : new stdClass();
    }

    private function save()
    {
        $json = json_encode($this->queue) ;
        file_put_contents($this->fn,$json);
    }
}