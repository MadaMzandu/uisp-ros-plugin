<?php
include_once 'api_router.php';
include_once 'batch.php';

class ApiJobs
{ //scheduled job queue
    private ?array $queue = null ;
    private string $fn = 'data/queue.json';

    protected function init(): void
    {
        $this->queue = $this->read();
    }

    public function list(): array
    {
        return array_values($this->queue);
    }

    public function clear()
    {
        file_put_contents($this->fn,null);
    }

    public function delete()
    {
        $index = $this->data->id ?? 0 ;
        if(is_int($index)){ $index = 'Q' . $index; }
        if(key_exists($index,$this->queue)){
            unset($this->queue[$index]);
            $this->save();
        }
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
            if(in_array($action,['delete','remove'])){ $delete[] = $item['id'] ;}
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
            $r = json_decode(file_get_contents($this->fn),true);
        }
        return is_array($r) ? $r : [];
    }

    private function save()
    {
        $json = json_encode($this->queue) ;
        file_put_contents($this->fn,$json);
    }
}