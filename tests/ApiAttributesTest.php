<?php


include_once 'ApiTest.php';
include_once 'lib/api_attributes.php';

class ApiAttributesTest extends ApiTest
{
    private ?ApiAttributes $_unit = null ;

    public function testCheckAttr()
    {
        $this->reset();
        $this->data()->b = [];
        $this->data()->e->at->macAddress->value = null ;
        $this->data()->e->at->username->value = null ;
        $int = $this->unit()->check($this->data()->toPost());
        $this->assertEquals(0,$int,"Missing mac and username");
        $this->data()->e->at->macAddress->value = '60:46:89:6d:cc:78' ;
        $int = $this->unit()->check($this->data()->toPost());
        $this->assertEquals(1,$int,"Valid Mac");
        $this->data()->e->at->macAddress->value = '60:46:89:6d - invalid' ;
        $int = $this->unit()->check($this->data()->toPost());
        $this->assertEquals(0,$int,"InValid Mac");
        $this->data()->e->at->macAddress->value = null ;
        $this->data()->e->at->username->value = 'bb@example.com';
        $int = $this->unit()->check($this->data()->toPost());
        $this->assertEquals(1,$int,"Valid username");
        $this->data()->e->at->username->value = ' ';
        $int = $this->unit()->check($this->data()->toPost());
        $this->assertEquals(0,$int,"Invalid username");
        $this->data()->e->at->device->value = null ;
        $int = $this->unit()->check($this->data()->toPost());
        $this->assertEquals(0,$int,"Missing device");
    }

    private function unit()
    {
        if(empty($this->_unit)){
            $this->_unit = new ApiAttributes();
        }
        return $this->_unit ;
    }
}
