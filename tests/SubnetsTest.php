<?php

include_once 'lib/admin.php';
include_once 'lib/admin_subnets.php';

use PHPUnit\Framework\TestCase;

class SubnetsTest extends TestCase
{
    private Subnets $subnets ;

    public function setUp(): void
    {
        $db = new ApiSqlite();
        $device = ['id'=>99 ,'name' =>'Device99','pool'=>'192.168.99.0/24','pool6'=>'fd99:1111:2222::/48'];
        $plan =['id'=>99,'name' => 'Plan99'];
        $db->insert($device,'devices');
        $db->insert($plan,'plans');
        $d = [];
        $this->subnets = new Subnets($d);
    }

    public function tearDown(): void
    {
        $db = new ApiSqlite();
        $db->delete(99,'devices');
        $db->delete(99,'plans');
        $db->deleteWhere('did=99','subnets');
        $db->deleteWhere('id > 10000','services');
    }

    public function testAssign(): void
    {
        $a = $this->subnets->assign(99,99);
        $this->assertSame('192.168.99.0/28',$a['pool']);
        $this->assertSame('fd99:1111:2222::/54',$a['pool6']);
    }

    public function testAssignNext(): void
    {
        $db = new ApiSqlite();
        $sn = ['planId'=>99,'did'=>99,'address'=>'192.168.99.0'];
        $db->insert($sn,'subnets');
        for($i=1;$i<16;$i++){
            $pref = "192.168.99.";
            $data = ['id' => 10000+$i, 'address' => $pref.$i];
            $db->insert($data,'services');
        }
        $a = $this->subnets->assign(99,99);
        $this->assertSame('192.168.99.16/28',$a['pool']);
    }

    public function testAssignIPOnly(): void
    {
        $db = new ApiSqlite();
        $d = ['id' => 99, 'pool6' => ""];
        $db->edit($d,'devices');
        $a = $this->subnets->assign(99,99);
        $this->assertSame('192.168.99.0/28',$a['pool']);
        $pool6 = $a['pool6'] ?? null;
        $this->assertEmpty($pool6);
    }

    public function testAssignNoPool()
    {
        $db = new ApiSqlite();
        $d = ['id' => 99,'pool' => "" ,'pool6' => ""];
        $db->edit($d,'devices');
        $a = $this->subnets->assign(99,99);
        $this->assertEmpty($a);
    }

    public function testUsed(): void
    {
        $db = new ApiSqlite();
        $addr = '192.168.99.';
        $net = 0 ;
        while($net <= 64){
            $d = ['planId'=>99,'did'=>99,'address'=> $addr . $net];
            $db->insert($d,'subnets');
            $net += 16 ;
        }
        $a = $this->subnets->used();
        $arr = preg_grep('/192.168.99./',array_keys($a));
        $str = implode(',',$arr);
        $ret = "192.168.99.0,192.168.99.16,192.168.99.32,192.168.99.48,192.168.99.64";
        $this->assertSame($ret,$str);
    }

    public function testTargets(): void
    {
        $db = new ApiSqlite();
        $addr = '192.168.99.';
        $net = 0 ;
        while($net <= 64){
            $d = ['planId'=>99,'did'=>99,'address'=> $addr . $net];
            $db->insert($d,'subnets');
            $net += 16 ;
        }
        $str = $this->subnets->targets(99,99);
        $ret = "192.168.99.0/28,192.168.99.16/28,192.168.99.32/28,192.168.99.48/28,192.168.99.64/28";
        $this->assertSame($ret,$str);
    }


}
