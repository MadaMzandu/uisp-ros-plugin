<?php
// Real panel routing, durable configuration backlog and allocations; no external transport.
set_error_handler(function ($severity,$message,$file,$line) {
    if (error_reporting() & $severity) throw new ErrorException($message,0,$severity,$file,$line);
    return false;
});
function exclusionCheck(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
function exclusionRows(SQLite3 $connection,string $query): array {
    $result=$connection->query($query); $rows=[];
    while ($row=$result->fetchArray(SQLITE3_ASSOC)) $rows[]=$row;
    $result->finalize(); return $rows;
}
function exclusionQueueState(ApiJobQueue $queue): array {
    $connection=(new ReflectionProperty(ApiJobQueue::class,'db'))->getValue($queue);
    $tables=exclusionRows($connection,"SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
    $state=[];
    foreach ($tables as $table) {
        exclusionCheck((bool)preg_match('/^[a-z_]+$/D',$table['name']),'Unexpected fixture table name');
        $state[$table['name']]=exclusionRows($connection,'SELECT * FROM '.$table['name'].' ORDER BY rowid');
    }
    return $state;
}
function exclusionSave(array $data): array {
    $router=new ApiRouter(['changeType'=>'update','target'=>'config','action'=>'edit','data'=>$data]);
    $router->route();
    exclusionCheck(empty($router->status()->error),'The real panel configuration route rejected a valid save');
    return (new ReflectionProperty(ApiRouter::class,'result'))->getValue($router);
}
$source=realpath(__DIR__.'/../src'); $original=getcwd(); $originalIncludePath=get_include_path();
$directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'uisp-exclusion-save-'.bin2hex(random_bytes(8));
mkdir($directory,0700);
foreach (['lib','includes','data','vendor'] as $folder) mkdir($directory.'/'.$folder,0700);
foreach (glob($source.'/lib/*.php') as $file) copy($file,$directory.'/lib/'.basename($file));
foreach (glob($source.'/includes/*') as $file) if (is_file($file)) copy($file,$directory.'/includes/'.basename($file));
file_put_contents($directory.'/vendor/autoload.php', <<<'PHP'
<?php
namespace Ubnt\UcrmPluginSdk\Service;
class UcrmApi {
    public static function create() { return new self(); }
    public function get($path,$options=[]) {
        $GLOBALS['exclusionApiReads']++;
        if ($path==='clients/71') return ['id'=>71,'isArchived'=>false];
        if (preg_match('#^clients/services/(50[1-3])$#D',$path,$match))
            return ['id'=>(int)$match[1],'clientId'=>71,'status'=>(int)$match[1]===502?3:1];
        throw new \RuntimeException('External network prohibited');
    }
}
PHP);
file_put_contents($directory.'/lib/mt.php', <<<'PHP'
<?php
class MT {
    public static array $writes=[];
    public function do_batch($device,$commands,?callable $beforeWrite=null): int {
        if ($beforeWrite) $beforeWrite($commands);
        foreach ($commands as $command) self::$writes[]=['device'=>(int)$device->id,'command'=>$command];
        return count($commands);
    }
    public function failed(): array { return []; }
    public function success(): array { return []; }
}
PHP);
chdir($directory); set_include_path($directory.'/lib'.PATH_SEPARATOR.$originalIncludePath);
require 'lib/api_common.php'; require 'lib/api_setup.php'; require 'lib/batch.php';
class ExclusionSaveSqlite extends ApiSqlite {
    public $beforeNextTransaction=null;
    public function transaction(callable $callback) {
        if ($this->beforeNextTransaction!==null) {
            $before=$this->beforeNextTransaction; $this->beforeNextTransaction=null; $before();
        }
        return parent::transaction($callback);
    }
}
$exclusionApiReads=0;
try {
    file_put_contents('data/config.json',json_encode(['debugEnable'=>false]));
    $apiSqlite=new ExclusionSaveSqlite('data/data.db'); $apiSqlite->exec(file_get_contents('includes/schema.sql'));
    (new ApiSetup())->config_load();
    $apiSqlite->saveConfig(['version'=>MY_VERSION,'disable_contention'=>true,'disabled_profile'=>'fixture-paused',
        'disabled_rate'=>0,'auto_ppp_user'=>false,'excl_addr'=>'']);
    $apiSqliteCache=new ApiSqlite('data/cache.db'); $apiSqliteCache->exec(file_get_contents('includes/cache.sql'));
    $apiJobQueue=myJobQueue();
    $device=['id'=>21,'name'=>'Fixture router','type'=>'mikrotik','ip'=>'192.0.2.21','user'=>'fixture',
        'password'=>'fixture','pool'=>'198.51.100.0/28','pool6'=>'2001:db8:100::/60','pfxLength'=>64];
    $apiSqlite->insert($device,'devices');
    $apiSqlite->insert(['id'=>1,'name'=>'Fixture plan','ratio'=>1,'uploadSpeed'=>7,'downloadSpeed'=>21],'plans');
    $apiSqliteCache->insert(['id'=>71,'firstName'=>'Fixture','lastName'=>'Client'],'clients');
    foreach ([501=>1,502=>3,503=>1] as $id=>$status) {
        $service=['id'=>$id,'clientId'=>71,'device'=>21,'planId'=>1,'status'=>$status,'username'=>'fixture-'.$id,
            'password'=>'fixture','uploadSpeed'=>7,'downloadSpeed'=>21];
        $apiSqliteCache->insert($service,'services');
        if ($id===501) {
            $apiSqliteCache->insert(['id'=>$id,'address'=>'198.51.100.1','address6'=>'2001:db8:100::/64'],'network');
            exclusionCheck((new Batch())->set_accounts([$id]),'The initial service did not produce a real applied snapshot');
        } elseif ($id===502) {
            $allocator=new ApiIP();
            exclusionCheck($allocator->assign($id,(object)$device)==='198.51.100.2'
                && $allocator->assign($id,(object)$device,true)==='2001:db8:100:1::/64',
                'The initial automatic allocation did not retain both families');
        } else {
            $network=['id'=>$id,'address'=>'198.51.100.3','address6'=>'2001:db8:100:2::/64'];
            $apiSqlite->insert($network,'network'); $apiSqliteCache->insert($network,'network');
        }
    }
    require 'lib/api_router.php'; require_once 'lib/api_configuration_apply.php';
    // A save unrelated to network application must not consume old work left by an earlier request.
    ApiConfigurationApply::requestPlan(1);
    $durable=$apiJobQueue->enqueue(['id'=>504,'clientId'=>71,'device'=>21,'planId'=>1,'status'=>1,
        'username'=>'fixture-waiting','password'=>'fixture','_config'=>(array)$apiSqlite->readConfig()]);
    $backlog=$apiSqlite->selectCustom('SELECT * FROM configuration_apply ORDER BY service_id');
    exclusionCheck(count($backlog)===3 && isset($apiJobQueue->list()[$durable]),'The existing-work fixture is incomplete');
    $queueBefore=exclusionQueueState($apiJobQueue);
    $networkBefore=$apiSqlite->selectCustom('SELECT * FROM network ORDER BY id');
    $cacheBefore=$apiSqliteCache->selectCustom('SELECT * FROM network ORDER BY id');
    $receiptsBefore=$apiSqlite->selectCustom('SELECT * FROM pool_allocations ORDER BY service_id,family');
    $appliedBefore=$apiJobQueue->appliedSnapshot(501); $writesBefore=MT::$writes; $readsBefore=$exclusionApiReads;
    $exclusions='198.51.100.1-198.51.100.5,2001:db8:100::/64-2001:db8:100:4::/64';
    $fullConfig=(array)$apiSqlite->readConfig(); $fullConfig['excl_addr']=$exclusions;
    $saved=exclusionSave($fullConfig);
    exclusionCheck($apiSqlite->readConfig()->excl_addr===$exclusions,'The exclusions were not saved');
    exclusionCheck(($saved['scheduled']??null)===0,'Saving only exclusions reported newly scheduled work');
    exclusionCheck($apiSqlite->selectCustom('SELECT * FROM configuration_apply ORDER BY service_id')===$backlog,
        'Saving only exclusions created, replaced or drained pre-existing configuration requests');
    exclusionCheck(exclusionQueueState($apiJobQueue)===$queueBefore,'Saving exclusions changed an existing job, applied state or queue history');
    exclusionCheck($apiSqlite->selectCustom('SELECT * FROM network ORDER BY id')===$networkBefore
        && $apiSqliteCache->selectCustom('SELECT * FROM network ORDER BY id')===$cacheBefore
        && $apiSqlite->selectCustom('SELECT * FROM pool_allocations ORDER BY service_id,family')===$receiptsBefore
        && $apiJobQueue->appliedSnapshot(501)===$appliedBefore,
        'Saving exclusions changed current IPv4/IPv6 addresses, receipts or applied state');
    exclusionCheck(MT::$writes===$writesBefore && $exclusionApiReads===$readsBefore,'Saving exclusions contacted UISP or changed RouterOS');
    exclusionSave((array)$apiSqlite->readConfig());
    exclusionCheck($apiSqlite->selectCustom('SELECT * FROM configuration_apply ORDER BY service_id')===$backlog
        && exclusionQueueState($apiJobQueue)===$queueBefore,'Saving unchanged configuration drained old work');

    // Deterministically commit another writer's network setting immediately before this save gets its SQL lock.
    // An out-of-transaction baseline would incorrectly attribute that change to the exclusion edit.
    $concurrentLease=(float)$apiSqlite->readConfig()->lease_time+10;
    $apiSqlite->beforeNextTransaction=static function () use ($concurrentLease): void {
        $other=new ApiSqlite('data/data.db');
        try { $other->saveConfig(['lease_time'=>$concurrentLease]); }
        finally { $other->close(); }
    };
    $concurrent=exclusionSave(['excl_addr'=>$exclusions.',198.51.100.9']);
    exclusionCheck(($concurrent['scheduled']??null)===0 && (float)$apiSqlite->readConfig()->lease_time===$concurrentLease
        && $apiSqlite->selectCustom('SELECT * FROM configuration_apply ORDER BY service_id')===$backlog
        && exclusionQueueState($apiJobQueue)===$queueBefore,
        'An exclusion save attributed another committed network change to itself or drained unrelated work');
    exclusionCheck(MT::$writes===$writesBefore && $exclusionApiReads===$readsBefore,
        'A concurrent exclusion-only save contacted UISP or RouterOS');

    // Only future automatic acquisitions consult exclusions; incumbents and explicit manual values keep their identity.
    $allocator=new ApiIP();
    exclusionCheck($allocator->assign(502,(object)$device)==='198.51.100.2'
        && $allocator->assign(502,(object)$device,true)==='2001:db8:100:1::/64',
        'A newly excluded address displaced an existing automatic reservation');
    $allocator->set(503,'198.51.100.3'); $allocator->set(503,'2001:db8:100:2::/64',true);
    exclusionCheck($allocator->assign(510,(object)$device)==='198.51.100.6'
        && $allocator->assign(510,(object)$device,true)==='2001:db8:100:5::/64',
        'A future automatic assignment used a newly excluded IPv4 or IPv6 range');
    $allocator->set(511,'198.51.100.4'); $allocator->set(511,'2001:db8:100:3::/64',true);
    exclusionCheck($apiSqlite->selectAddress(511)==='198.51.100.4'
        && $apiSqlite->selectAddress(511,true)==='2001:db8:100:3::/64',
        'Automatic exclusions blocked an explicit manual assignment');
    exclusionCheck(exclusionQueueState($apiJobQueue)===$queueBefore && MT::$writes===$writesBefore,
        'Allocator-only checks changed queue or RouterOS state');

    // Removing the exclusion trigger must not suppress actual network setting changes in the same save.
    $nextLease=(float)$apiSqlite->readConfig()->lease_time+1;
    $mixed=exclusionSave(['excl_addr'=>$exclusions.',198.51.100.7','lease_time'=>$nextLease]);
    exclusionCheck(($mixed['scheduled']??0)===3 && ($mixed['remaining']??-1)===0,
        'A mixed exclusion/network save stopped scheduling its real network changes');
    $connection=(new ReflectionProperty(ApiJobQueue::class,'db'))->getValue($apiJobQueue);
    $payloads=exclusionRows($connection,"SELECT payload FROM jobs WHERE service_id IN (501,502,503) AND status='pending' ORDER BY service_id");
    exclusionCheck(count($payloads)===3,'Mixed configuration did not prepare each current service');
    foreach ($payloads as $row) {
        $snapshot=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
        exclusionCheck((float)$snapshot['_config']['lease_time']===$nextLease
            && $snapshot['_config']['excl_addr']===$exclusions.',198.51.100.7',
            'A mixed save froze stale network settings or exclusions');
    }
    $rate=exclusionSave(['excl_addr'=>$exclusions.',198.51.100.8','disabled_rate'=>0.5]);
    exclusionCheck(($rate['scheduled']??0)===3,'A mixed exclusion/suspension-rate save lost network application');
    exclusionCheck($apiSqlite->selectCustom('SELECT * FROM network WHERE id IN (501,502,503) ORDER BY id')===$networkBefore
        && $apiJobQueue->appliedSnapshot(501)===$appliedBefore && MT::$writes===$writesBefore,
        'Scheduling mixed settings changed incumbent addresses or executed router commands during save');
    echo "Excluded-address saves passed: real panel route, untouched backlog/durable jobs/applied IPv4+IPv6, concurrent-writer isolation, future-only automatic exclusions, manual reuse and preserved mixed network-setting scheduling.\n";
} finally {
    if (isset($apiJobQueue)) $apiJobQueue->close();
    if (isset($apiSqlite)) $apiSqlite->close();
    if (isset($apiSqliteCache)) $apiSqliteCache->close();
    chdir($original); set_include_path($originalIncludePath);
    $resolved=realpath($directory); $temporaryRoot=realpath(sys_get_temp_dir());
    if ($resolved && $temporaryRoot && strpos($resolved,$temporaryRoot.DIRECTORY_SEPARATOR.'uisp-exclusion-save-')===0) {
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
        rmdir($resolved);
    }
}
