<?php


include_once 'lib/api_action.php';
include_once 'lib/api_sqlite.php';
include_once 'ApiTest.php';

class ApiActionTest extends ApiTest
{
    private ?ApiAction $_unit = null ;

    public function testClear()
    {
        $this->reset();
        $this->data()->changeType = 'edit';
        $this->data()->e->at->username->value = null ;
        $int = $this->unit()->test($this->data()->toPost());
        $this->assertEquals(ACTION_DELETE_OLD,$int,"User cleared");
        $this->data()->e->at->username->value = 'testuser' ;
        $this->data()->e->at->device->value = null ;
        $int = $this->unit()->test($this->data()->toPost());
        $this->assertEquals(ACTION_DELETE_OLD,$int,"Device cleared");
    }

    public function testAuto()
    {
        $this->reset();
        $db = new ApiSqlite();
        $c = $db->readConfig();
        $db->saveConfig(['auto_ppp_user' => true]);
        $this->reset();
        $this->data()->e->at->username->value = null ;
        $int = $this->unit()->test($this->data()->toPost());
        $this->assertEquals(ACTION_AUTO,$int,'Auto ppp user');
        $db->saveConfig(['auto_ppp_user' => false]);
        $int = $this->unit()->test($this->data()->toPost());
        $this->assertEquals(ACTION_DELETE_OLD,$int,'Auto ppp disabled');
        $db->saveConfig(['auto_hs_user' => true]) ;
        $this->data()->e->at->hotspot->value = true ;
        $int = $this->unit()->test($this->data()->toPost());
        $this->assertEquals(ACTION_AUTO,$int,'Auto hotspot user');
        $this->data()->e->at->hotspot->value = false ;
        $int = $this->unit()->test($this->data()->toPost());
        $this->assertEquals(ACTION_DELETE_OLD,$int,'Auto no hotspot enabled');
        $db->saveConfig($c);
    }

    public function testFlip()
    {
        $this->reset();
        $this->data()->changeType = 'edit';
        $this->data()->e->at->username->value = 'madalitso';
        $int = $this->unit()->test($this->data()->toPost());
        $this->assertEquals(ACTION_DOUBLE,$int,"User change");
        $this->reset();
        $this->data()->changeType = 'edit';
        $this->data()->e->at->device->value = 'Test3';
        $int = $this->unit()->test($this->data()->toPost());
        $this->assertEquals(ACTION_DOUBLE,$int,"Device change");        $this->reset();
        $this->data()->changeType = 'edit';
        $this->data()->e->at->username->value = 'madalitso';
        $int = $this->unit()->test($this->data()->toPost());
        $this->assertEquals(ACTION_DOUBLE,$int,"User change");
        $this->reset();
        $this->data()->changeType = 'edit';
        $this->data()->e->at->device->value = 'Test3';
        $int = $this->unit()->test($this->data()->toPost());
        $this->assertEquals(ACTION_DOUBLE,$int,"Device change");
        $this->reset();
        $this->data()->changeType = 'edit';
        $this->data()->e->at->username->value = null ;
        $this->data()->b->at->username->value = null ;
        $this->data()->e->at->macAddress->value = '06:32:34:88:90:12';
        $this->data()->b->at->macAddress->value = '06:32:34:88:90:13';
        $int = $this->unit()->test($this->data()->toPost());
        $this->assertEquals(ACTION_DOUBLE,$int,"MAC change");
        $this->reset();
        $this->data()->changeType = 'edit';
        $int = $this->unit()->test($this->data()->toPost());
        $this->assertEquals(ACTION_DEFERRED,$int,"No Change");
    }

    public function testNoAttrs()
    {
        $this->reset();
        $this->data()->e->attributes->device->value = null;
        $this->data()->b = [];
        $int = $this->unit()->test($this->data()->toPost());
        $this->assertEquals(ACTION_DEFERRED,$int,"No device");
        $this->data()->e->attributes->device->value = 'Test1';
        $this->data()->e->attributes->username->value = null;
        $int = $this->unit()->test($this->data()->toPost());
        $this->assertEquals(ACTION_DEFERRED,$int,"No username");
        $this->data()->e->attributes = [];
        $int = $this->unit()->test($this->data()->toPost());
        $this->assertEquals(ACTION_DEFERRED,$int,"No attributes");
    }

    public function testEdit()
    {
        $this->reset();
        $this->data()->changeType = 'edit';
        $this->data()->e->at->password->value = '12345';
        $int = $this->unit()->test($this->data()->toPost());
        $this->assertEquals(ACTION_SET,$int,"Clean edit");
    }

    public function testSuspend()
    {
        $this->reset();
        $this->data()->changeType = 'suspend';
        $this->data()->entity->status = 3;
        $this->data()->b = [];
        $int = $this->unit()->test($this->data()->toPost());
        $this->assertEquals(ACTION_DOUBLE,$int,"Clean suspend");
        $this->data()->changeType = 'unsuspend';
        $this->data()->entity->status = 1;
        $int = $this->unit()->test($this->data()->toPost());
        $this->assertEquals(ACTION_DOUBLE,$int,"Clean unsuspend");
    }

    public function testDelete()
    {
        $this->reset();
        $this->data()->changeType = 'end';
        $this->data()->entity->status = 5 ;
        $this->data()->b = [];
        $int = $this->unit()->test($this->data()->toPost());
        $this->assertEquals(ACTION_DELETE,$int,"Clean delete");
    }

    public function testInsert()
    {
        $this->reset();
        $this->data()->changeType = 'insert';
        $this->data()->b = [];
        $int = $this->unit()->test($this->data()->toPost());
        $this->assertEquals(ACTION_SET,$int,"Clean insert");
    }

    private function unit(): ApiAction
    {
        if(empty($this->unit)){
            $this->_unit = new ApiAction();
        }
        return $this->_unit ;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $db = new ApiSqlite();
        $db->insert(['id' => 999,'name' => 'Test3'],'devices',true);
    }
    protected function tearDown(): void
    {
        parent::setUp();
        $db = new ApiSqlite();
        $db->delete(999,'devices');
    }
}
