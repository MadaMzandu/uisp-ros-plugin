<?php

class Admin_System extends Admin
{

    public function rebuild(): void
    {
        if(!function_exists('fastcgi_finish_request')){
            shell_exec('php lib/shell.php rebuild > /dev/null 2>&1 &');
            return;
        }else{
            $this->status->status = 'ok';
            $this->status->data = [];
            header('content-type: application/json');
            echo json_encode($this->status);
            fastcgi_finish_request();
        }
        set_time_limit(6000);
        $re = new Admin_Rebuild();
        if(empty($this->data)){
            $re->send_triggers();
        }
        else{
            $re->rebuild_device($this->data);
        }
    }
}