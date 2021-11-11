<?php

include_once 'device_base.php';

class Device_Account extends Device_Base
{

    protected $save; //data for save

    protected function init(): void
    {
        parent::init();
        $this->save = [];
    }

    protected function rate(): object
    {
        return $this->svc->plan->rate();
    }

}
