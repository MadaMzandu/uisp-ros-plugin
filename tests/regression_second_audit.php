<?php
// Fresh client events exercise the actual object response contract without any network I/O.
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
function secondAuditCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$source = realpath(__DIR__ . '/../src');
$original = getcwd();
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-second-audit-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
foreach (['lib','includes','data','vendor'] as $folder) mkdir($directory . '/' . $folder, 0700);
foreach (glob($source . '/lib/*.php') as $file) copy($file, $directory . '/lib/' . basename($file));
$queueFixture=$directory.'/lib/api_job_queue.php';
$queueCode=file_get_contents($queueFixture);
$queueCode=str_replace('static $queue = null;', 'static $queue = null; if(isset($GLOBALS["auditQueueOverride"])) return $GLOBALS["auditQueueOverride"];', $queueCode, $queueReplacements);
secondAuditCheck($queueReplacements===1,'The isolated queue factory override no longer matches');
file_put_contents($queueFixture,$queueCode);
foreach (glob($source . '/includes/*') as $file) if (is_file($file)) copy($file, $directory . '/includes/' . basename($file));
file_put_contents($directory . '/vendor/autoload.php', <<<'PHP'
<?php
namespace Ubnt\UcrmPluginSdk\Service;
class UcrmApi {
    public static array $calls = [];
    public static array $services = [];
    public static function create() { return new self(); }
    public function get($path, $options = []) {
        self::$calls[] = $path;
        if ($path === 'clients/70') return ['id'=>70,'firstName'=>'Fresh','lastName'=>'Client','companyName'=>'Fixture Company','isArchived'=>false];
        if ($path === 'clients/71') return ['id'=>71,'firstName'=>'Transferred','lastName'=>'Client','isArchived'=>false];
        if (preg_match('#^clients/services/([1-9][0-9]*)$#D', $path, $match) && isset(self::$services[(int)$match[1]]))
            return self::$services[(int)$match[1]];
        throw new \RuntimeException('Unexpected network request in second audit fixture: ' . $path);
    }
}
PHP);
file_put_contents($directory . '/lib/mt.php', <<<'PHP'
<?php
class MT {
    public static bool $failDelete=false;
    public static array $writes=[];
    public function do_batch($device,$commands): int {
        foreach($commands as $command) {
            self::$writes[]=$command;
            if(self::$failDelete && trim($command['path'],'/')==='ppp/secret' && ($command['action'] ?? '')==='remove') return 0;
        }
        return count($commands);
    }
    public function failed(): array { return []; }
    public function success(): array { return []; }
}
PHP);
chdir($directory);
set_include_path($directory . '/lib' . PATH_SEPARATOR . get_include_path());
require 'lib/api_common.php';
require 'lib/api_setup.php';
require 'lib/api_action.php';
require 'lib/api_trim.php';
require 'lib/api_update.php';
function secondAuditFreshService(int $id, int $clientId, int $status): void {
    // Independent authoritative GET response; partial or stale webhook fields are not the source of truth.
    \Ubnt\UcrmPluginSdk\Service\UcrmApi::$services[$id] = (object)[
        'id'=>$id,'clientId'=>$clientId,'status'=>$status,'servicePlanId'=>1,'uploadSpeed'=>20,'downloadSpeed'=>50,
        'attributes'=>[
            (object)['key'=>'device','value'=>'Fixture Router'],
            (object)['key'=>'username','value'=>'fixture-'.$id],
            (object)['key'=>'password','value'=>'fixture'],
            (object)['key'=>'ipAddress','value'=>'192.0.2.'.$id],
        ],
    ];
}
try {
    $apiSqlite = new ApiSqlite('data/data.db');
    $apiSqlite->exec(file_get_contents('includes/schema.sql'));
    (new ApiSetup())->config_load();
    $apiSqlite->saveConfig(['version'=>MY_VERSION]);
    $apiSqliteCache = new ApiSqlite('data/cache.db');
    $apiSqliteCache->exec(file_get_contents('includes/cache.sql'));
    $apiJobQueue = new ApiJobQueue('data/jobs.db');
    $GLOBALS['auditQueueOverride']=$apiJobQueue;
    $request = (object)['entity'=>'client','entityId'=>70,'changeType'=>'edit','uuid'=>'fresh-client-event-70',
        'extraData'=>(object)['entity'=>(object)['id'=>70,'firstName'=>'Updated']]];
    $action = new ApiAction($request);
    $action->exec();
    secondAuditCheck(!$action->status()->error, 'Fresh client event failed');
    $client = $apiSqliteCache->singleQuery('SELECT * FROM clients WHERE id=70', true);
    secondAuditCheck($client['firstName']==='Updated' && $client['lastName']==='Client'
        && $client['company']==='Fixture Company', 'Fresh partial client event lost API-hydrated names');
    secondAuditCheck(\Ubnt\UcrmPluginSdk\Service\UcrmApi::$calls===['clients/70'], 'Fresh client event repeated its hydration request');
    $apiSqlite->saveConfig(['disable_contention'=>true]);
    $apiSqlite->insert(['id'=>10,'name'=>'Fixture Router','type'=>'mikrotik','ip'=>'192.0.2.10','user'=>'fixture','password'=>'fixture'],'devices');
    $apiSqlite->insert(['id'=>1,'name'=>'Fixture Plan','ratio'=>1,'uploadSpeed'=>20,'downloadSpeed'=>50],'plans');
    $apiSqliteCache->insert(['id'=>71,'firstName'=>'Transferred','lastName'=>'Client'],'clients');
    foreach([90,91,92,93,94,95] as $id) {
        $apiSqliteCache->insert(['id'=>$id,'clientId'=>70,'device'=>10,'planId'=>1,'status'=>1,'username'=>'fixture-'.$id,'password'=>'fixture',
            'uploadSpeed'=>20,'downloadSpeed'=>50],'services');
        $apiSqliteCache->insert(['id'=>$id,'address'=>'192.0.2.'.$id],'network');
        secondAuditFreshService($id,70,1);
        secondAuditCheck((new Batch())->set_accounts([$id]),'Initial fixture service was not established');
    }
    $beforeTransfer=count(MT::$writes);
    $transfer=(object)['entity'=>'service','entityId'=>90,'changeType'=>'edit','uuid'=>'client-transfer-service-90',
        'extraData'=>(object)['entity'=>(object)['id'=>90,'clientId'=>71]]];
    secondAuditFreshService(90,71,1);
    $action=new ApiAction($transfer); $action->exec();
    secondAuditCheck(!$action->status()->error && $apiJobQueue->appliedSnapshot(90)['clientId']===71,
        'Moving a service to another client lost its new ownership');
    $transferWrites=array_values(array_filter(array_slice(MT::$writes,$beforeTransfer),static function($command): bool {
        return trim($command['path'],'/')==='ppp/secret' && ($command['name'] ?? '')==='fixture-90';
    }));
    secondAuditCheck(count($transferWrites)===2 && ($transferWrites[0]['action'] ?? '')==='remove'
        && $transferWrites[0]['_client_id']===70 && $transferWrites[1]['_client_id']===71,
        'Client transfer did not clean the original owned account before claiming it for the new client');
    MT::$failDelete=true;
    foreach([91=>['end',2],92=>['delete',1],93=>['edit',2],94=>['edit',5],95=>['edit',8]] as $id=>$terminal) {
        $event=(object)['entity'=>'service','entityId'=>$id,'changeType'=>$terminal[0],'uuid'=>'terminal-event-service-'.$id,
            'extraData'=>(object)['entity'=>(object)['id'=>$id,'clientId'=>70,'status'=>$terminal[1]]]];
        // Deletion delivery may carry its prior active state; the current service confirms termination.
        secondAuditFreshService($id,70,$terminal[0]==='delete'?2:$terminal[1]);
        $action=new ApiAction($event); $action->exec();
        secondAuditCheck($action->status()->error,'Offline terminal event did not retain a failed cleanup: '.$terminal[0].'/'.$terminal[1]);
    }
    $planEdit=new ApiUpdate((object)['action'=>'edit','data'=>(object)['id'=>1,'uploadOverride'=>15]],'plans');
    $planEdit->exec();
    $configEdit=new ApiUpdate((object)['action'=>'edit','data'=>(object)['lease_time'=>45]],'config');
    $configEdit->exec();
    // Restart the worker and replace the complete cache with a stale source snapshot.
    $apiJobQueue->close(); $apiJobQueue=new ApiJobQueue('data/jobs.db');
    $GLOBALS['auditQueueOverride']=$apiJobQueue;
    MT::$failDelete=false;
    $keys=array_keys($apiJobQueue->list());
    $apiJobQueue->run($keys);
    foreach([91,92,93,94,95] as $id) secondAuditCheck($apiJobQueue->appliedSnapshot($id)===[] && $apiSqlite->exists($id,'services')===null,
        'Editing plan/config after an offline terminal event recreated customer '.$id);
    // A stale cache and later setting edit must still respect the durable terminal marker.
    foreach([91,92,93,94,95] as $id) {
        $apiSqliteCache->insert(['id'=>$id,'clientId'=>70,'device'=>10,'planId'=>1,'status'=>1,'username'=>'fixture-'.$id,'password'=>'fixture',
            'uploadSpeed'=>20,'downloadSpeed'=>50],'services',INSERT_REPLACE);
        $apiSqliteCache->insert(['id'=>$id,'address'=>'192.0.2.'.$id],'network',INSERT_REPLACE);
    }
    (new ApiUpdate((object)['action'=>'edit','data'=>(object)['lease_time'=>46]],'config'))->exec();
    $apiJobQueue->run(array_keys($apiJobQueue->list()));
    foreach([91,92,93,94,95] as $id) secondAuditCheck($apiJobQueue->appliedSnapshot($id)===[], 'A stale cache bypassed the durable terminal state');
    $activate=(object)['entity'=>'service','entityId'=>91,'changeType'=>'activate','uuid'=>'explicit-reactivation-service-91',
        'extraData'=>(object)['entity'=>(object)['id'=>91,'clientId'=>70,'status'=>1]]];
    secondAuditFreshService(91,70,1);
    $action=new ApiAction($activate); $action->exec();
    secondAuditCheck(!$action->status()->error && ($apiJobQueue->appliedSnapshot(91)['status'] ?? null)===1,
        'A later explicit UISP activation could not clear the terminal marker');
    // A delete queued by 2.4.7 has no new intent flag and left an active cached service.
    $apiSqliteCache->insert(['id'=>96,'clientId'=>70,'device'=>10,'planId'=>1,'status'=>1,'username'=>'fixture-96','password'=>'fixture',
        'uploadSpeed'=>20,'downloadSpeed'=>50],'services');
    $apiSqliteCache->insert(['id'=>96,'address'=>'192.0.2.96'],'network');
    // Automatic refresh still sees the active source snapshot. Only the explicit
    // later activation may revoke the recovered legacy terminal intent.
    secondAuditFreshService(96,70,1);
    secondAuditCheck((new Batch())->set_accounts([96]),'Legacy fixture account was not established');
    $legacy=$apiSqliteCache->singleQuery('SELECT services.*,clients.firstName,clients.lastName,clients.company FROM services
        LEFT JOIN clients ON services.clientId=clients.id WHERE services.id=96',true);
    $legacy['_event_id']='legacy-delete-service-96'; $legacy['status']=2;
    MT::$failDelete=true;
    secondAuditCheck(!(new Batch())->del_accounts([$legacy]),'Legacy delete did not remain pending');
    // 2.4.7 could already have queued this active update after the failed delete.
    $legacyUpdate=(new Batch())->queue_accounts([96]);
    secondAuditCheck(count($legacyUpdate)===1,'Legacy automatic update was not prepared behind the terminal barrier');
    foreach([97,98] as $id) {
        MT::$failDelete=false;
        $apiSqliteCache->insert(['id'=>$id,'clientId'=>70,'device'=>10,'planId'=>1,'status'=>1,'username'=>'fixture-'.$id,'password'=>'fixture',
            'uploadSpeed'=>20,'downloadSpeed'=>50],'services');
        $apiSqliteCache->insert(['id'=>$id,'address'=>'192.0.2.'.$id],'network');
        secondAuditFreshService($id,70,1);
        secondAuditCheck((new Batch())->set_accounts([$id]),'Completed legacy fixture account was not established');
        $finishedLegacy=$apiSqliteCache->singleQuery('SELECT services.*,clients.firstName,clients.lastName,clients.company FROM services
            LEFT JOIN clients ON services.clientId=clients.id WHERE services.id=?',true,[$id]);
        $finishedLegacy['_event_id']='legacy-delete-service-'.$id; $finishedLegacy['status']=2;
        MT::$failDelete=true; secondAuditCheck(!(new Batch())->del_accounts([$finishedLegacy]),'Legacy webhook did not defer its cleanup');
        $cleanupKeys=[]; foreach($apiJobQueue->list() as $key=>$job) if($job['id']===$id) $cleanupKeys[]=$key;
        MT::$failDelete=false; $apiJobQueue->run($cleanupKeys);
        secondAuditCheck($apiJobQueue->appliedSnapshot($id)===[] && $apiSqliteCache->singleQuery('SELECT status FROM services WHERE id=?',false,[$id])===1,
            'A 2.4.7 background cleanup did not leave the expected stale active cache');
    }
    $property=new ReflectionProperty(ApiJobQueue::class,'db');
    if(PHP_VERSION_ID<80100) $property->setAccessible(true);
    $queueDatabase=$property->getValue($apiJobQueue);
    $queueDatabase->exec("UPDATE jobs SET updated='1999-01-01T00:00:00+00:00' WHERE service_id=98 AND operation='delete'");
    $compact=new ReflectionMethod(ApiJobQueue::class,'compactHistory');
    if(PHP_VERSION_ID<80100) $compact->setAccessible(true);
    $compact->invoke($apiJobQueue);
    secondAuditCheck($queueDatabase->querySingle("SELECT count(*) FROM job_receipts WHERE legacy_key LIKE 'event:legacy-delete-service-98:%'")===1,
        'The old completed cleanup was not reduced to a durable UUID receipt');
    $queueDatabase->exec('DROP TABLE service_tombstones');
    $apiJobQueue->close(); $apiJobQueue=new ApiJobQueue('data/jobs.db'); $GLOBALS['auditQueueOverride']=$apiJobQueue;
    secondAuditCheck($apiJobQueue->isServiceBlocked(96),'Upgrade did not protect a pending terminal webhook from 2.4.7');
    foreach([97,98] as $id) secondAuditCheck($apiJobQueue->isServiceBlocked($id), 'Upgrade did not protect completed legacy cleanup '.$id);
    (new ApiUpdate((object)['action'=>'edit','data'=>(object)['lease_time'=>47]],'config'))->exec();
    MT::$failDelete=false; $apiJobQueue->run(array_keys($apiJobQueue->list()));
    secondAuditCheck($apiJobQueue->appliedSnapshot(96)===[],'Upgrading and saving settings recreated a legacy terminated service');
    foreach([97,98] as $id) secondAuditCheck($apiJobQueue->appliedSnapshot($id)===[], 'Upgrading and saving settings recreated completed legacy service '.$id);
    secondAuditCheck($apiJobQueue->pending($legacyUpdate),'The uncertain legacy update was discarded instead of held for a fresh UISP state');
    $activate->entityId=96; $activate->uuid='explicit-reactivation-service-96';
    $activate->extraData->entity->id=96;
    secondAuditFreshService(96,70,1);
    $action=new ApiAction($activate); $action->exec();
    secondAuditCheck(!$action->status()->error && !$apiJobQueue->isServiceBlocked(96)
        && ($apiJobQueue->appliedSnapshot(96)['status'] ?? null)===1,
        'A fresh service event could not replace the held legacy update after cleanup');
    echo "Second audit regression passed: fresh client hydration, ownership transfer, durable terminal lifecycle, plan/config changes, worker restart, stale cache, explicit reactivation and legacy queue upgrade.\n";
} finally {
    if (isset($apiJobQueue)) $apiJobQueue->close();
    if (isset($apiSqlite)) $apiSqlite->close();
    if (isset($apiSqliteCache)) $apiSqliteCache->close();
    chdir($original);
    $resolved = realpath($directory); $temporaryRoot = realpath(sys_get_temp_dir());
    if ($resolved && $temporaryRoot && strpos($resolved, $temporaryRoot . DIRECTORY_SEPARATOR . 'uisp-second-audit-')===0) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($resolved);
    }
}
