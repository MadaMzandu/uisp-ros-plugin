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
    public static array $requests = [];
    public static array $reads = [];
    public static array $patches = [];
    public static function create() { return new self(); }
    public function get($path, $options = []) {
        self::$reads[]=$path;
        if ($path === 'custom-attributes') return [['key'=>'pppoeUsername','id'=>228],['key'=>'pppoePassword','id'=>229]];
        if (preg_match('#^clients/services/([1-9][0-9]*)$#D', $path, $match)) {
            $request=self::$requests[(int)$match[1]]??null;
            if ($request!==null) {
                $entity=clone $request->extraData->entity;
                $entity->status=['suspend'=>3,'unsuspend'=>1,'suspend_cancel'=>1,'activate'=>1,'end'=>2,'delete'=>2][$request->changeType]??$entity->status;
                return $entity;
            }
            if (isset(self::$services[(int)$match[1]])) return self::$services[(int)$match[1]];
        }
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
    $apiSqlite->insert(['id'=>11,'name'=>'Other router','type'=>'mikrotik','ip'=>'192.0.2.11','user'=>'fixture','password'=>'fixture','pool'=>'10.43.0.0/24'],'devices');
    $sdk=\Ubnt\UcrmPluginSdk\Service\UcrmApi::class;
    $service=static fn(int $sid,int $status,string $user='CURRENT-USER') => (object)['id'=>$sid,'clientId'=>70,'status'=>$status,'servicePlanId'=>35,
        'uploadSpeed'=>5,'downloadSpeed'=>25,'attributes'=>$attrs('Berwind',$user)];
    $event=static function(int $sid,string $action,int $status,string $uuid,string $user='OLD-USER') use($service): object {
        return (object)['entity'=>'service','entityId'=>$sid,'changeType'=>$action,'uuid'=>$uuid,
            'extraData'=>(object)['entity'=>$service($sid,$status,$user)]];
    };
    $run=static function(object $event,?ApiWebhookFreshness $fresh=null): ApiAction {
        $action=new ApiAction($event,$fresh); $action->exec(); return $action;
    };
    $due=static function() use($queue): void {
        $property=new ReflectionProperty(ApiJobQueue::class,'db'); if(PHP_VERSION_ID<80100)$property->setAccessible(true);
        $property->getValue($queue)->exec('UPDATE webhook_fresh_reviews SET next_attempt=0; UPDATE jobs SET next_attempt=0');
    };
    $sdk::$services[1420]=$service(1420,1);
    $initial=$run($event(1420,'insert',1,'fresh-seed'));
    flowCheck(!$initial->status()->error && $queue->appliedSnapshot(1420)['username']==='CURRENT-USER','Fresh GET did not replace old delivery attributes');
    $writes=count(MT::$writes); $jobs=$queue->list();
    $stale=$run($event(1420,'suspend',3,'old-suspension'));
    flowCheck($stale->status()->error && $stale->result()['provisioning']==='awaiting_fresh_state'
        && $stale->result()['reason']==='webhook_state_unconfirmed' && $queue->hasFreshReview(1420)
        && count(MT::$writes)===$writes && $queue->list()===$jobs
        && (int)$apiSqliteCache->singleQuery('SELECT status FROM services WHERE id=1420')===1,
        'Old suspension overwrote a confirmed active service or disappeared silently');
    // The same contradiction can also be eventual consistency: revisit only SID, never replay suspend.
    $sdk::$services[1420]=$service(1420,3); $due();
    $review=(new ApiWebhookFreshness())->drain($queue);
    flowCheck($review['queued']>0 && !$queue->hasFreshReview(1420) && count(MT::$writes)===$writes,
        'Fresh review wrote to a router or failed to enqueue the now-confirmed state');
    $queue->run();
    flowCheck($queue->appliedSnapshot(1420)['status']===3,'Fresh queued suspension was not applied');
    $writes=count(MT::$writes);
    $oldResume=$run($event(1420,'unsuspend',1,'old-reactivation'));
    flowCheck($oldResume->result()['reason']==='webhook_state_unconfirmed' && count(MT::$writes)===$writes,'Old reactivation restored active state');
    $sdk::$services[1420]=$service(1420,1); $due();
    (new ApiWebhookFreshness())->drain($queue); $queue->run();
    flowCheck($queue->appliedSnapshot(1420)['status']===1,'Current reactivation did not survive a delayed delivery');
    $writes=count(MT::$writes);
    // A distinct old generic edit has stale credentials and stale before-edit routing. Both are ignored.
    $oldEdit=$event(1420,'edit',3,'old-attributes');
    $oldEdit->extraData->entityBeforeEdit=$service(1420,3,'OTHER-PRIVATE-USER');
    $oldEdit->extraData->entityBeforeEdit->attributes[0]->value='Other router';
    $edited=$run($oldEdit);
    flowCheck(!$edited->status()->error && $queue->appliedSnapshot(1420)['username']==='CURRENT-USER'
        && $queue->appliedSnapshot(1420)['device']===10 && $queue->appliedSnapshot(1420)['status']===1
        && count(MT::$writes)===$writes,'Old generic edit or its previous identity caused a destructive migration');
    $cache=$apiSqliteCache->singleQuery('SELECT * FROM services WHERE id=1420',true); $jobs=$queue->list();
    $failed=$run($event(1420,'edit',3,'offline-old'),new ApiWebhookFreshness(static function(){throw new RuntimeException('PRIVATE-TOKEN');}));
    flowCheck($failed->result()['reason']==='webhook_fresh_state_unavailable' && $queue->list()===$jobs
        && $cache===$apiSqliteCache->singleQuery('SELECT * FROM services WHERE id=1420',true) && count(MT::$writes)===$writes,
        'Unavailable authoritative state mutated cached or queued state');
    $due(); $unavailable=(new ApiWebhookFreshness(static function(){throw new RuntimeException('PRIVATE-TOKEN');}))->drain($queue);
    flowCheck($unavailable['pending']===1 && $queue->nextFreshReview()===null,'Failed review did not back off');
    $invalid=$service(1420,1); $invalid->attributes[]=$invalid->attributes[0];
    flowCheck((new ApiWebhookFreshness(static fn()=>$invalid))->current(1420,'edit')['reason']==='webhook_fresh_state_invalid','Duplicate attributes were trusted');
    $invalid=$service(1420,1); $invalid->status=true;
    flowCheck((new ApiWebhookFreshness(static fn()=>$invalid))->current(1420,'edit')['reason']==='webhook_fresh_state_invalid','Boolean status was coerced to active');
    $invalid=$service(999,1);
    flowCheck((new ApiWebhookFreshness(static fn()=>$invalid))->current(1420,'edit')['reason']==='webhook_fresh_state_invalid','Mismatched fresh service ID was trusted');
    // Exact authenticated HTTP 404 can prove absence; arbitrary error text cannot.
    eval('namespace GuzzleHttp\\Exception; class RequestException extends \\RuntimeException {
        private int $status; public function __construct(int $status) { parent::__construct("fixture private transport"); $this->status=$status; }
        public function hasResponse(): bool { return true; }
        public function getResponse(): object { return new class($this->status) {
            private int $status; public function __construct(int $status) {$this->status=$status;} public function getStatusCode(): int {return $this->status;}
        }; }
    }');
    $absent=new ApiWebhookFreshness(static function(){throw new \GuzzleHttp\Exception\RequestException(404);});
    flowCheck($absent->current(1420,'edit')['reason']==='webhook_service_absent'
        && $absent->current(1420,'delete')['missing']===true && $absent->current(1420,'review')['reason']===null,
        'Confirmed 404 was confused with an editable service');
    flowCheck((new ApiWebhookFreshness(static function(){throw new \GuzzleHttp\Exception\RequestException(500);}))->current(1420,'delete')['reason']==='webhook_fresh_state_unavailable'
        && (new ApiWebhookFreshness(static function(){throw new RuntimeException('404 not found');}))->current(1420,'delete')['missing']===false,
        'A failed read was treated as evidence authorizing deletion');
    $removed=$run($event(1420,'delete',2,'stale-deletion'));
    flowCheck($removed->result()['reason']==='webhook_state_unconfirmed' && count(MT::$writes)===$writes,
        'Old deletion removed a current active service');
    $absentAction=new ApiAction(null,$absent); $absentAction->reconcileService(1420,true);
    flowCheck($absentAction->result()['provisioning']==='queued' && count(MT::$writes)===$writes,
        'Confirmed absent service did not queue normal guarded cleanup without router I/O');
    $queue->run();
    flowCheck($queue->appliedSnapshot(1420)===[],'Confirmed absence did not complete normal cleanup');
    // A pending UUID must not write its stale snapshot, including after a partially acknowledged batch.
    $sdk::$services[1421]=$service(1421,3,'PENDING-USER'); MT::$fail=true;
    $pendingEvent=$event(1421,'suspend',1,'partial-original'); $pending=$run($pendingEvent);
    flowCheck($pending->status()->error && $pending->result()['jobCounts']['pending']>0,'Fixture did not create partial work');
    MT::$fail=false; $writes=count(MT::$writes); $sdk::$services[1421]=$service(1421,1,'PENDING-USER');
    $duplicate=$run($pendingEvent);
    flowCheck($duplicate->result()['reason']==='webhook_state_unconfirmed' && count(MT::$writes)===$writes
        && $queue->hasFreshReview(1421),'Pending old UUID replayed old status');
    $keys=$queue->eventKeys('partial-original');
    $action=new ApiAction(); $action->reconcileService(1421,true);
    flowCheck($queue->pending($keys) && $queue->list(),'Fresh review dropped a partially prepared prerequisite');
    $due(); $queue->run();
    flowCheck($queue->appliedSnapshot(1421)['status']===1,'Fresh replacement did not follow the preserved partial prerequisite');
    // A rejected preview guard does not alter cache, enqueue, or create an inbox item.
    $cache=$apiSqliteCache->singleQuery('SELECT * FROM services WHERE id=1421',true); $jobs=$queue->list();
    $guarded=new ApiAction(); $result=$guarded->reconcileService(1421,true,false,static fn()=>false);
    flowCheck($result['reason']==='webhook_fresh_review_blocked' && $jobs===$queue->list()
        && $cache===$apiSqliteCache->singleQuery('SELECT * FROM services WHERE id=1421',true),'Rejected freshness guard mutated state');
    $called=false;
    $expired=(new ApiWebhookFreshness(static function()use(&$called){$called=true;},microtime(true)-1))->current(1421,'review');
    flowCheck(!$called && $expired['reason']==='webhook_fresh_state_unavailable','Expired read budget still contacted UISP');
    // Automatic review never performs unrelated CRM reads, writes, or metadata hydration.
    $apiSqlite->saveConfig(['ip_addr_attr'=>'fixtureIp']); $apiAttributes=null;
    $sdk::$services[1430]=$service(1430,1,'FOREIGN-IP-USER');
    $sdk::$services[1430]->attributes[]=(object)['key'=>'fixtureIp','value'=>'10.43.0.10'];
    $reads=count($sdk::$reads); $patches=count($sdk::$patches); $writes=count(MT::$writes);
    $foreign=new ApiAction(); $foreign->reconcileService(1430,true);
    flowCheck($foreign->result()['reason']==='webhook_fresh_review_blocked' && count($sdk::$patches)===$patches
        && array_slice($sdk::$reads,$reads)===['clients/services/1430'] && count(MT::$writes)===$writes,
        'Background foreign-Site review patched CRM or issued unbounded follow-up reads');
    $sdk::$services[1431]=$service(1431,1,'MISSING-PLAN-USER'); $sdk::$services[1431]->servicePlanId=999;
    $reads=count($sdk::$reads); $unknownPlan=new ApiAction(); $unknownPlan->reconcileService(1431,true);
    flowCheck($unknownPlan->result()['reason']==='webhook_fresh_review_blocked'
        && array_slice($sdk::$reads,$reads)===['clients/services/1431'],'Background review performed unbounded plan hydration');
    $property=new ReflectionProperty(ApiJobQueue::class,'db'); if(PHP_VERSION_ID<80100)$property->setAccessible(true);
    $rows=$property->getValue($queue)->query('SELECT * FROM webhook_fresh_reviews'); $stored=[];
    while($row=$rows->fetchArray(SQLITE3_ASSOC))$stored[]=$row;
    $encoded=json_encode([$stored,$failed->result(),$unavailable]);
    foreach(['PRIVATE-TOKEN','OLD-USER','CLIENT-PASSWORD-PRIVATE','partial-original','old-suspension'] as $secret)
        flowCheck(!str_contains($encoded,$secret),'Fresh review inbox or diagnostic stored a payload/credential/UUID');
    echo "Webhook freshness checks passed: inverted lifecycle events, fresh attributes, SID-only eventual review, bounded backoff, unavailable/malformed reads, duplicate pending guard and partial prerequisite order.\n";
} finally {
    foreach ([isset($queue)?$queue:null,isset($apiSqlite)?$apiSqlite:null,isset($apiSqliteCache)?$apiSqliteCache:null] as $store) if ($store) $store->close();
    chdir($original);
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file) $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
    rmdir($directory);
}
