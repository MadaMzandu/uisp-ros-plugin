<?php
include_once '../src/lib/service_base.php';
include_once '../src/lib/service_attributes.php';
include_once '../src/lib/service_plan.php';
include_once '../src/lib/service_client.php';
include_once '../src/lib/service_account.php';
include_once '../src/lib/api_sqlite.php';
include_once '../src/lib/admin.php';
include_once '../src/lib/admin_subnets.php';


use PHPUnit\Framework\TestCase;

class Service_AccountTest extends TestCase
{
    private $json = '{"changeType":"insert","entity":"service","entityId":99999,"extraData":{"entity":{"id":99999,"clientId":2,"status":1,"downloadSpeed":1,"uploadSpeed":1,"hasOutage":false,"servicePlanId":1,"servicePlanName":"Internet1Mbps","attributes":[{"key":"deviceName","value":"Device99"},{"key":"username","value":"user-Unit99"},{"key":"password","value":"y!sh"}]}}}';
    private $svc ;

    public function testIP():void
    {
        $d = json_decode($this->json);
        $db = new ApiSqlite();
        $pre = '192.168.99.';
        $i = 1 ;
        while($i < 33){
            $this->svc = new Service_Account($d);
            $ip = $this->svc->ip();
            $check = $pre . $i;
            $this->assertSame($check,$ip);
            $svc = ['id' => 10000+$i, 'address'=>$check];
            $db->insert($svc,'services');
            $i++;
        }

    }

    public function testIP6():void
    {
        $d = json_decode($this->json);
        $this->svc = new Service_Account($d);
        $ip = $this->svc->ip6();
        $this->assertSame( "fd99:1111:2222::",$ip);
    }

    public function setUp(): void
    {
        $db = new ApiSqlite();
        $device = ['id'=>99 ,'name' =>'Device99','ip'=>'172.20.2.1','pool'=>'192.168.99.0/24','pool6'=>'fd99:1111:2222::/48'];
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
        $db->deleteWhere('id > 10000');
    }

}
