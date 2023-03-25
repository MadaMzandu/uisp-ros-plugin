<?php

class Admin_System extends Admin
{

    public function rebuild(): void
    {
        if(!function_exists('fastcgi_finish_request')){
            shell_exec('php lib/shell.php rebuild > /dev/null 2>&1 &');
            return;
        }else{
            header('content-type: application/json');
            respond('sending rebuild task to background');
            fastcgi_finish_request();
        }
        set_time_limit(7200);
        (new AdminRebuild())->rebuild($this->data);
    }
}