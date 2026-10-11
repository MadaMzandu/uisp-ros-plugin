<?php
// Real webhook normalization/cache/queue. All SDK responses and RouterOS writes are local fixtures.
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
function flowCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$source = realpath(__DIR__ . '/../src');
$original = getcwd();
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-webhook-flow-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
foreach (['lib','includes','data','vendor'] as $folder) mkdir($directory . '/' . $folder, 0700);
foreach (glob($source . '/lib/*.php') as $file) copy($file, $directory . '/lib/' . basename($file));
foreach (glob($source . '/includes/*') as $file) if (is_file($file)) copy($file, $directory . '/includes/' . basename($file));
file_put_contents($directory . '/vendor/autoload.php', <<<'PHP'
<?php
namespace Ubnt\UcrmPluginSdk\Service;
class UcrmApi {
    public static array $services = [];
    public static array $patches = [];
    public static function create() { return new self(); }
    public function get($path, $options = []) {
        if ($path === 'custom-attributes') return [['key'=>'pppoeUsername','id'=>228],['key'=>'pppoePassword','id'=>229]];
        if (preg_match('#^clients/services/([1-9][0-9]*)$#D', $path, $match) && isset(self::$services[(int)$match[1]])) return self::$services[(int)$match[1]];
        throw new \RuntimeException('Fixture network prohibited');
    }
    public function patch($path, $data = []) {
        self::$patches[] = [$path,$data];
        if (preg_match('#^clients/services/([1-9][0-9]*)$#D', $path, $match)) return ['id'=>(int)$match[1]];
        throw new \RuntimeException('Fixture network prohibited');
    }
}
PHP);
file_put_contents($directory . '/lib/mt.php', <<<'PHP'
<?php
class MT {
    public static bool $fail = false;
    public static array $writes = [];
    public function do_batch($device, $commands, $beforeWrite = null): int {
        if ($beforeWrite !== null) $beforeWrite($commands);
        foreach ($commands as $command) {
            self::$writes[] = $command;
            if (self::$fail) return 0;
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
try {
    $apiSqlite = new ApiSqlite('data/data.db');
    $apiSqlite->exec(file_get_contents('includes/schema.sql'));
    (new ApiSetup())->config_load();
    $apiSqlite->saveConfig(['version'=>MY_VERSION,'disable_contention'=>true,'auto_ppp_user'=>false,
        'device_name_attr'=>'pppoeSite','pppoe_user_attr'=>'pppoeUsername','pppoe_pass_attr'=>'pppoePassword',
        'disabled_profile'=>'fixture-suspended']);
    file_put_contents('data/config.json', json_encode(['debugEnable'=>false]));
    MyLog()->Append('DEFAULT-DEBUG-SHOULD-STAY-HIDDEN');
    MyLog()->Append(['event'=>'fixture_info_without_debug'], LOG_INFO);
    $apiSqliteCache = new ApiSqlite('data/cache.db');
    $apiSqliteCache->exec(file_get_contents('includes/cache.sql'));
    $queue = myJobQueue();
    $apiSqlite->insert(['id'=>10,'name'=>'Berwind','type'=>'mikrotik','ip'=>'192.0.2.10','user'=>'ROUTER-USER-PRIVATE',
        'password'=>'ROUTER-PASSWORD-PRIVATE','pool'=>'10.42.0.0/24'], 'devices');
    $apiSqlite->insert(['id'=>35,'name'=>'Fixture 25/5','ratio'=>1,'uploadSpeed'=>5,'downloadSpeed'=>25], 'plans');
    $apiSqliteCache->insert(['id'=>70,'firstName'=>'Fixture','lastName'=>'Client'], 'clients');
    $attrs = static fn(?string $site, ?string $user) => [
        (object)['key'=>'pppoeSite','customAttributeId'=>235,'value'=>$site],
        (object)['key'=>'pppoeUsername','customAttributeId'=>228,'value'=>$user],
        (object)['key'=>'pppoePassword','customAttributeId'=>229,'value'=>'CLIENT-PASSWORD-PRIVATE']];
    $sequence = 0;
    $request = static function (int $id, string $change, int $status, ?int $before = null,
        ?string $site = 'Berwind', ?string $user = 'CLIENT-USER-PRIVATE') use ($attrs, &$sequence): object {
        $entity = (object)['id'=>$id,'clientId'=>70,'status'=>$status,'servicePlanId'=>35,
            'uploadSpeed'=>5,'downloadSpeed'=>25,'attributes'=>$attrs($site,$user)];
        $extra = (object)['entity'=>$entity];
        if ($before !== null) { $extra->entityBeforeEdit = clone $entity; $extra->entityBeforeEdit->status = $before; }
        return (object)['entity'=>'service','entityId'=>(string)$id,'changeType'=>$change,
            'uuid'=>'FLOW-PRIVATE-UUID-' . ++$sequence, 'extraData'=>$extra,
            'url'=>'https://fixture.invalid/?webhookToken=TOKEN-PRIVATE'];
    };
    $execute = static function (object $request): ApiAction {
        $action = new ApiAction($request); $action->exec(); return $action;
    };
    $secret = static function (array $commands): array {
        foreach (array_reverse($commands) as $command) if (trim($command['path'],'/') === 'ppp/secret' && ($command['action']??'') !== 'remove') return $command;
        throw new RuntimeException('No PPP secret was prepared');
    };
    $assertApplied = static function (ApiAction $action, int $status) use ($queue): void {
        $result = $action->result();
        flowCheck(!$action->status()->error && $result['provisioning'] === 'applied', 'Completed Batch was not reported applied');
        flowCheck($result['serviceId']===1420 && $result['clientId']===70 && $result['desiredStatus']===$status
            && $result['appliedStatus']===$status && $result['resolvedDeviceId']===10, 'Applied routing/billing diagnostic is wrong');
        flowCheck($result['routing']===['configuredSiteAttribute'=>'pppoeSite','siteAttributePresent'=>true], 'Site key/presence diagnostic is wrong');
        flowCheck($result['jobCounts']['known']>0 && $result['jobCounts']['completed']===$result['jobCounts']['known']
            && $result['jobCounts']['pending']===0 && $queue->completed($result['jobKeys']), 'Applied diagnostic did not verify its jobs');
    };
    // Generic service.edit payload uses billing status, while explicit suspend/unsuspend names override it.
    $suspend = $execute($request(1420,'edit',3,1)); $assertApplied($suspend,3);
    flowCheck($secret(MT::$writes)['profile']==='fixture-suspended', 'service.edit status3 did not suspend the PPP profile');
    $resume = $execute($request(1420,'edit',1,3)); $assertApplied($resume,1);
    flowCheck($secret(MT::$writes)['profile']!=='fixture-suspended', 'service.edit status1 did not reactivate the PPP profile');
    $suspend = $execute($request(1420,'suspend',1)); $assertApplied($suspend,3);
    $writes=count(MT::$writes);
    $pairedSuspend=$execute($request(1420,'edit',3,1));
    flowCheck($pairedSuspend->result()['provisioning']==='already_applied' && count(MT::$writes)===$writes
        && $pairedSuspend->result()['jobCounts']['known']===0, 'Paired suspend edit issued a duplicate write');
    $resume = $execute($request(1420,'unsuspend',3)); $assertApplied($resume,1);
    $writes=count(MT::$writes);
    $pairedResume=$execute($request(1420,'edit',1,3));
    flowCheck($pairedResume->result()['provisioning']==='already_applied' && count(MT::$writes)===$writes,
        'Paired reactivation edit issued a duplicate write');
    // Status-only event pairs cannot suppress effective plan/configuration changes between events.
    $apiSqlite->edit(['id'=>35,'uploadOverride'=>0],'plans');
    $planChanged=$execute($request(1420,'edit',1,3)); $assertApplied($planChanged,1);
    flowCheck($queue->appliedSnapshot(1420)['_plan']['_sourceUploadOverride']===0.0
        || $queue->appliedSnapshot(1420)['_plan']['_sourceUploadOverride']===0,
        'NULL-to-zero speed override was incorrectly treated as unchanged');
    $apiSqlite->edit(['id'=>35,'uploadOverride'=>null],'plans');
    $execute($request(1420,'unsuspend',1));
    $apiSqlite->saveConfig(['disabled_profile'=>'fixture-suspended-next']);
    $configChanged=$execute($request(1420,'edit',1,3)); $assertApplied($configChanged,1);
    $additional=$request(1420,'edit',1,3);
    $additional->extraData->entity->attributes[2]=(object)['key'=>'pppoePassword','customAttributeId'=>229,'value'=>'CLIENT-UPDATED-PASSWORD-PRIVATE'];
    $extraChanged=$execute($additional); $assertApplied($extraChanged,1);
    flowCheck($queue->appliedSnapshot(1420)['password']==='CLIENT-UPDATED-PASSWORD-PRIVATE', 'An additional effective edit was suppressed');
    $execute($request(1420,'unsuspend',1));
    // Another service's shared-profile repair invalidates this session even when billing state already matches.
    $baseline=$queue->appliedSnapshot(1420);
    $profileIdentity=ApiSharedResources::endpointIdentity((object)$baseline['_device']) . ':profile:ppp/profile:uisp-ros-plugin:plan:35';
    $queue->repairProfileRevision($profileIdentity);
    $revisionChanged=$execute($request(1420,'edit',1,3)); $assertApplied($revisionChanged,1);
    $writes=count(MT::$writes);
    $revisionAcked=$execute($request(1420,'edit',1,3));
    flowCheck($revisionAcked->result()['provisioning']==='already_applied' && count(MT::$writes)===$writes,
        'Acknowledged shared-profile debt kept repeating the paired edit');
    $noop = $execute($request(1420,'edit',1,1));
    flowCheck($noop->result()['provisioning']==='already_applied' && $noop->result()['jobCounts']['known']===0,
        'An unchanged edit was incorrectly described as a new router application');

    MT::$fail = true;
    $pendingRequest = $request(1420,'edit',3,1);
    $pending = $execute($pendingRequest); $result = $pending->result();
    flowCheck($pending->status()->error && $result['provisioning']==='pending' && $result['desiredStatus']===3
        && $result['appliedStatus']===1 && $result['jobCounts']['pending']>0, 'Offline work was falsely reported applied');
    $again = $execute($pendingRequest);
    flowCheck($again->result()['provisioning']==='pending', 'Duplicate pending work was falsely reported complete');
    MT::$fail = false;
    $property = new ReflectionProperty(ApiJobQueue::class,'db');
    if (PHP_VERSION_ID < 80100) $property->setAccessible(true);
    $queueDb = $property->getValue($queue);
    $queueDb->exec('UPDATE jobs SET next_attempt=0');
    $queue->run($pending->result()['jobKeys']);
    $writes = count(MT::$writes);
    $duplicate = $execute($pendingRequest);
    flowCheck($duplicate->result()['provisioning']==='duplicate_completed' && $duplicate->result()['appliedStatus']===3
        && count(MT::$writes)===$writes, 'Completed duplicate mutated the router or lost its completion diagnostic');

    $writes = count(MT::$writes);
    $doc = $execute($request(1451,'edit',1,3,null));
    flowCheck($doc->result()['provisioning']==='ignored_unmanaged' && $doc->result()['resolvedDeviceId']===null
        && $doc->result()['routing']['siteAttributePresent'] && count(MT::$writes)===$writes,
        'Documentary Site-less service was not distinguished from provisioning');
    $apiSqlite->saveConfig(['device_name_attr'=>'device']); $apiAttributes=null;
    $unmappedKey = $execute($request(1452,'edit',1,3));
    flowCheck($unmappedKey->result()['provisioning']==='ignored_unmanaged'
        && $unmappedKey->result()['routing']===['configuredSiteAttribute'=>'device','siteAttributePresent'=>false],
        'Incorrect configured Site key was invisible in the response');
    $apiSqlite->saveConfig(['device_name_attr'=>'pppoeSite']); $apiAttributes=null;
    $missing = new ApiAction($request(1453,'edit',1,null,'Berwind',null));
    try { $missing->exec(); throw new RuntimeException('Missing credentials were acknowledged silently'); }
    catch (InvalidArgumentException $error) { flowCheck($error->getMessage()==='service_credentials_missing', 'Missing identity raised the wrong error'); }
    flowCheck($missing->result()['serviceId']===1453 && $missing->result()['resolvedDeviceId']===10
        && $missing->result()['provisioning']==='failed' && count(MT::$writes)===$writes, 'Missing identity was applied or lost its diagnostic');
    $deferred = $execute($request(1454,'insert',0));
    flowCheck($deferred->result()['provisioning']==='deferred' && $deferred->result()['jobCounts']['known']===0,
        'Deferred billing status was described as applied');
    $apiSqlite->saveConfig(['auto_ppp_user'=>true]); $apiAttributes=null;
    $credentialsRequest = $request(1455,'insert',1,null,'Berwind',null);
    \Ubnt\UcrmPluginSdk\Service\UcrmApi::$services[1455] = (array)$credentialsRequest->extraData->entity;
    $credentials = $execute($credentialsRequest);
    flowCheck($credentials->result()['provisioning']==='credentials_requested'
        && count(\Ubnt\UcrmPluginSdk\Service\UcrmApi::$patches)===1 && $credentials->result()['jobCounts']['known']===0,
        'Credential generation was confused with router application');
    $apiSqlite->saveConfig(['auto_ppp_user'=>false]); $apiAttributes=null;
    $withoutUuid = $request(1456,'insert',1); unset($withoutUuid->uuid);
    $noUuid = $execute($withoutUuid);
    flowCheck($noUuid->result()['provisioning']==='applied' && $noUuid->result()['appliedStatus']===1
        && $noUuid->result()['jobCounts']['known']===0, 'An event without UUID invented job identities or lost actual completion');
    // Removing identity intentionally retains existing cleanup semantics and reports clearing distinctly.
    $clearedRequest = $request(1456,'edit',1,null,'Berwind',null);
    $clearedRequest->extraData->entityBeforeEdit = clone $withoutUuid->extraData->entity;
    $cleared = $execute($clearedRequest);
    flowCheck($cleared->result()['provisioning']==='cleared' && $cleared->result()['appliedStatus']===null,
        'Intentional identity removal was blocked or described as normal application');

    $client = $execute((object)['entity'=>'client','entityId'=>'70','changeType'=>'edit','uuid'=>'FLOW-PRIVATE-UUID-CLIENT',
        'extraData'=>(object)['entity'=>(object)['id'=>70,'firstName'=>'Fixture','lastName'=>'Client']]]);
    flowCheck($client->result()['provisioning']==='already_applied' && $client->result()['clientId']===70
        && !isset($client->result()['serviceId']), 'Client no-op was falsely reported as router application');
    $unknown = new ApiAction($request(1420,'edit',1,3,'Unknown fixture Site'));
    try { $unknown->exec(); throw new RuntimeException('Unknown Site was accepted'); }
    catch (InvalidArgumentException $error) { flowCheck($error->getMessage()==='service_site_not_configured', 'Unknown Site error was not specific'); }
    flowCheck($unknown->result()['serviceId']===1420 && $unknown->result()['appliedStatus']===3
        && $unknown->result()['routing']['siteAttributePresent'] && $unknown->result()['provisioning']==='failed',
        'An extraction error before normalization lost service/routing/applied context');
    $apiAttributes = new class extends ApiAttributes {
        public function extract($attributes): array { throw new RuntimeException('ARBITRARY-SENSITIVE-EXCEPTION-VALUE'); }
    };
    $unsafe = new ApiAction($request(1457,'edit',1,3));
    try { $unsafe->exec(); throw new RuntimeException('Fixture exception was lost'); }
    catch (RuntimeException $error) { flowCheck($error->getMessage()==='ARBITRARY-SENSITIVE-EXCEPTION-VALUE', 'Original processing exception was obscured'); }
    $apiAttributes=null;
    // ApiRouter must return the diagnostic in the actual HTTP JSON data field.
    require 'lib/api_router.php';
    $router = new ApiRouter($request(1420,'edit',1,3)); $router->route();
    ob_start(); $router->http_response(); $http = json_decode(ob_get_clean(),true);
    flowCheck($http['status']==='ok' && $http['data']['serviceId']===1420 && $http['data']['provisioning']==='applied'
        && $http['data']['desiredStatus']===1 && $http['data']['appliedStatus']===1, 'HTTP response omitted the routing outcome');
    $logs = file_get_contents('data/plugin.log');
    flowCheck(strpos($logs,'DEFAULT-DEBUG-SHOULD-STAY-HIDDEN')===false
        && strpos($logs,'fixture_info_without_debug')!==false && ApiLogger::DEBUG>LOG_INFO,
        'Debug filtering hid INFO or exposed ordinary debug output on this PHP platform');
    foreach (['CLIENT-USER-PRIVATE','CLIENT-PASSWORD-PRIVATE','CLIENT-UPDATED-PASSWORD-PRIVATE','ROUTER-USER-PRIVATE','ROUTER-PASSWORD-PRIVATE',
        'FLOW-PRIVATE-UUID','TOKEN-PRIVATE','fixture.invalid','ARBITRARY-SENSITIVE-EXCEPTION-VALUE','Unknown fixture Site'] as $private) {
        flowCheck(strpos($logs,$private)===false, 'Webhook diagnostics disclosed private request/exception data');
    }
    $events=[];
    foreach (explode("\n",$logs) as $line) {
        $offset=strpos($line,'{'); if ($offset===false) continue;
        $entry=json_decode(substr($line,$offset),true);
        if (is_array($entry) && isset($entry['event'])) $events[]=$entry;
    }
    $outcomeCount=count(array_filter($events,static fn($entry)=>$entry['event']==='webhook_result'));
    flowCheck($outcomeCount>=12, 'Webhook outcome logs disappeared with debug disabled: ' . $outcomeCount);
    flowCheck(count(array_filter($events,static fn($entry)=>$entry['event']==='webhook_processing_failed'
        && $entry['errorCode']==='service_credentials_missing' && $entry['serviceId']===1453))===1,
        'Missing identity did not log safe, useful error context');
    flowCheck(count(array_filter($events,static fn($entry)=>$entry['event']==='webhook_processing_failed'
        && $entry['errorCode']==='webhook_processing_failed' && $entry['serviceId']===1457))===1,
        'Arbitrary exception text was not replaced by a safe diagnostic code');
    echo "Webhook flow checks passed: edit/explicit billing transitions, verified completion, pending/duplicate outcomes, Site mapping, missing identity, cleanup, credential requests, client no-op, HTTP result and safe logs without debug.\n";
} finally {
    if (isset($queue)) $queue->close();
    if (isset($apiSqlite)) $apiSqlite->close();
    if (isset($apiSqliteCache)) $apiSqliteCache->close();
    chdir($original);
    $resolved = realpath($directory); $temporaryRoot = realpath(sys_get_temp_dir());
    if ($resolved && $temporaryRoot && strpos($resolved,$temporaryRoot . DIRECTORY_SEPARATOR . 'uisp-webhook-flow-')===0) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($resolved);
    }
}
