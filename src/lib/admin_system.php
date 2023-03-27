<?php

class Admin_System extends Admin
{

    public function rebuild(): void
    {
        $api = new AdminRebuild() ;
        $api->rebuild($this->data);
    }

    public function recache()
    {
        $api = new ApiCache();
        $this->db()->saveConfig((object)['last_cache' =>'2020-01-01']);
        $api->setup();
        $api->sync();
    }
}