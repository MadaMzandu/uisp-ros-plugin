<?php

include_once 'request.php';
include_once 'vendor/autoload.php';

use PHPUnit\Framework\TestCase;

class ApiTest extends TestCase
{
    protected $_data = null ;

    protected function reset():void
    {
        $this->data()->reset();
        $this->data()->entityId = 999;
        $this->data()->e->id = 999;
        $this->data()->b->id = 999;
        $this->data()->e->clientId = 999 ;
        $this->data()->b->clientId = 999;
        $this->data()->e->status = 1 ;
        $this->data()->b->status = 1 ;
    }

    protected function data(){
        if(empty($this->_data)){
            $this->_data = new TestRequest();
        }
        return $this->_data ;
    }
}
