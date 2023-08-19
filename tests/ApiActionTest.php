<?php

include_once 'vendor/autoload.php';
include_once 'lib/api_action.php';

use PHPUnit\Framework\TestCase;

class ApiActionTest extends TestCase
{
    private ?ApiAction $unit = null ;

    public function testUnset()
    {
        $d = $this->obj();
        $d->action = 'insert';
        $d->entity = $this->fill();
        $d->previous = $this->fill();
        $d->previous->device =  1;
        $d->previous->username = 'username';
        $int = $this->unit()->test($d);
        $this->assertSame(ACTION_DELETE,$int);
    }

    public function testCache()
    {
        $d = $this->obj();
        $d->action = 'insert';
        $d->entity = $this->fill() ;
        $int = $this->unit()->test($d);
        $this->assertSame(ACTION_CACHE,$int);
    }


    private function fill(): object
    {
       $k = ['id','status','device','username','mac'];
        return (object) array_fill_keys($k,null);
    }

    private function obj(){ return new stdClass(); }

    private function unit(){
        if(empty($this->unit)){
            $this->unit = new ApiAction();
        }
        return $this->unit ;
    }
}
