<?php

include_once 'device_base.php';

class Device_Account extends Device_Base
{

    protected  function rate():stdClass
    {
        $rate = $this->svc->plan->rate();
        $dr = max($this->conf->disabled_rate ,1); //disabled rate
        return $this->svc->disabled()
            ? (object)[
                'text' => $dr.'M/'.$dr.'M',
                'upload' => $dr,
                'download' => $dr,
            ]
            : $rate ;
    }

}
