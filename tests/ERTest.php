<?php

include_once 'lib/api_router.php';
include_once 'lib/admin.php';

use PHPUnit\Framework\TestCase;

class ERTest extends TestCase
{
    private ?ER $_unit = null ;

    public function testDHCP()
    {
        $s = ['id' => 'test-unit','ip-address' => '10.2.0.100',
            'mac-address' => '6d:45:32:44:cc:87','path' => 'dhcp'];
        $d = $this->device();
        $int = $this->unit()->do_batch($d,[$s]);
        $this->assertEquals(1,$int,"Insert lease");
        $s['ip-address'] = '10.2.0.101';
        $int = $this->unit()->do_batch($d,[$s]);
        $this->assertEquals(1,$int,"Modify lease");
        $s['disabled'] = true ;
        $int = $this->unit()->do_batch($d,[$s]);
        $this->assertEquals(1,$int,"Disable lease");
        $s['action'] = 'remove';
        $int = $this->unit()->do_batch($d,[$s]);
        $this->assertEquals(1,$int,"Remove lease");
        $a = array_replace($s,[]);
        $a['ip-address'] = '192.168.1.1';
        $int = $this->unit()->do_batch($d,[$a]);
        $this->assertEquals(0,$int,"Unconfigured pool");
        $b = $this->device();
        $b->ip = '127.0.0.1';
        $int = $this->unit()->do_batch($b,[$s]);
        $this->assertEquals(0,$int,"Offline device");

    }

    public function testQos()
    {
        $s = ['ip'=>'10.2.0.100','rate' => [4,4],'burst' => [6,6]];
        $d = $this->device();
        $q = new ErQueue($s['ip'],$s,);
        $p = $q->toArray() ;
        $int = $this->unit()->do_batch($d,[$p]);
        $this->assertEquals(1,$int,"Insert queue");
        $s['rate'] = [3,3];
        $q->reset($s['ip'],$s,false,0);
        $p = $q->toArray();
        $int = $this->unit()->do_batch($d,[$p]);
        $this->assertEquals(1,$int,"Modify queue");
        $q->reset($s['ip'],$s,true,2);
        $p = $q->toArray();
        $int = $this->unit()->do_batch($d,[$p]);
        $this->assertEquals(1,$int,"Disable rate");
        $p['action'] = 'remove';
        $int = $this->unit()->do_batch($d,[$p]);
        $this->assertEquals(1,$int,"Remove queue");
    }

    private function device(){
        $d = '{"ip":"207.134.100.182","port":9443,"user":"ucrm_plugin","password":"Eniac180!"}';
        return json_decode($d);
    }

    private function  unit(){
        if(empty($this->_unit)){
            $this->_unit = new ER();
        }
        return $this->_unit ;
    }
}
