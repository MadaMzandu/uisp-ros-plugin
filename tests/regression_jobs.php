<?php
// Isolated queue regressions: no plugin bootstrap, credentials, UISP, or RouterOS connections.
require_once __DIR__ . '/../src/lib/api_job_queue.php';
require_once __DIR__ . '/../src/lib/api_jobs.php';
set_error_handler(function ($severity, $message, $file, $line): void {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function checkJobs(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-jobs-test-' . bin2hex(random_bytes(8));
mkdir($directory);
$path = $directory . '/jobs.db';
$legacy = $directory . '/queue.json';
$fixtureLoaded = false;
try {
    $queue = new ApiJobQueue($path, null);
    $old = ['id' => 1, 'device' => 10, 'username' => 'old-user', 'password' => 'private',
        '_device' => ['id' => 10, 'ip' => '192.0.2.10', 'user' => 'router-admin', 'password' => 'router-secret']];
    $new = ['id' => 1, 'device' => 20, 'username' => 'new-user', 'status' => 1];
    $keys = $queue->enqueueBatch([
        ['snapshot' => $old, 'operation' => 'delete'],
        ['snapshot' => $new, 'operation' => 'update']
    ]);
    $calls = [];
    $queue->run($keys, function (array $snapshot, string $operation) use (&$calls): bool {
        $calls[] = [$operation, $snapshot['username'], $snapshot['device']];
        return false;
    });
    checkJobs($calls === [['delete', 'old-user', 10]], 'Replacement ran after failed old-account deletion');
    checkJobs(count($queue->list()) === 2, 'Failure discarded a queued operation');
    checkJobs(!$queue->completed($keys), 'Accepted jobs were reported as applied');
    $queue->run($keys, function (array $snapshot, string $operation) use (&$calls): bool {
        $calls[] = [$operation, $snapshot['username'], $snapshot['device']];
        return true;
    });
    checkJobs(array_slice($calls, 1) === [['delete', 'old-user', 10], ['update', 'new-user', 20]], 'Retry used the replacement identity or wrong order');
    checkJobs($queue->completed($keys) && $queue->list() === [], 'Successful jobs did not complete');

    // A duplicate delivered UUID must not repeat a successful disconnect or overwrite later desired state.
    $event = ['id' => 2, 'device' => 10, 'username' => 'event-user', '_event_id' => 'event-one'];
    $eventKey = $queue->enqueue($event);
    $queue->run([$eventKey], fn(): bool => true);
    checkJobs($queue->enqueue($event) === $eventKey, 'A completed webhook UUID was replayed');
    checkJobs($queue->eventKeys('event-one') === [$eventKey], 'Webhook event lookup failed');

    // Latest desired state replaces an unstarted update, while an in-flight operation remains serialized.
    $stale = $queue->enqueue(['id' => 3, 'username' => 'same', 'status' => 1]);
    $latest = $queue->enqueue(['id' => 3, 'username' => 'same', 'status' => 3]);
    checkJobs(!isset($queue->list()[$stale]) && isset($queue->list()[$latest]), 'Old desired state was retained ahead of the suspension');
    $second = new ApiJobQueue($path, null);
    $queue->run([$latest], function (array $snapshot) use ($second, $queue, $latest): bool {
        $blocked = $second->enqueue(['id' => 3, 'username' => 'same', 'status' => 1]);
        $attempted = $second->run([$blocked], function (): bool { throw new RuntimeException('Concurrent service write'); });
        checkJobs($attempted === [], 'A second worker wrote the same service concurrently');
        checkJobs($second->delete([$latest])[$latest] === false, 'An active write was cancelled');
        checkJobs($queue->hasRunning(), 'Running job was not observable');
        return true;
    });
    $second->run(null, fn(): bool => true);

    // Prepared commands, including automatically assigned addresses, survive a failed attempt unchanged.
    $frozen = $queue->enqueue(['id' => 4, 'username' => 'freeze']);
    $queue->run([$frozen], function (array $snapshot, string $operation, array $job, ApiJobQueue $store): bool {
        $snapshot['_commands'] = [['path' => '/ppp/secret', 'remote-address' => '192.0.2.44']];
        $store->freezePrepared($job, $snapshot);
        return false;
    });
    $queue->run([$frozen], function (array $snapshot): bool {
        checkJobs($snapshot['_commands'][0]['remote-address'] === '192.0.2.44', 'Retry regenerated prepared addresses');
        return true;
    });

    // A failed replacement may already have confirmed its old-account cleanup.
    // A new desired state can replace it only before replacement commands exist.
    $previousCleanup=['id'=>7,'clientId'=>7,'device'=>10,'username'=>'previous-cleanup'];
    $previousCleanupKey=$queue->enqueue($previousCleanup); $queue->run([$previousCleanupKey],fn():bool=>true);
    $staleReplacement=['id'=>7,'clientId'=>7,'device'=>20,'username'=>'stale-replacement'];
    $staleReplacementKey=$queue->enqueue($staleReplacement); $cleanupExecutions=0;
    $queue->run([$staleReplacementKey],function(array $snapshot,string $operation,array $job,ApiJobQueue $store) use($previousCleanup,&$cleanupExecutions):bool {
        $snapshot['_cleanup']=$previousCleanup;
        $store->freezePrepared($job,$snapshot);
        $cleanupExecutions++;
        $store->confirmCleanup($job,$previousCleanup);
        throw new RuntimeException('Fixture address validation failed after confirmed cleanup');
    });
    checkJobs($queue->appliedSnapshot(7)===[],'Confirmed cleanup did not release the old applied identity');
    $currentReplacementKey=$queue->enqueue(array_replace($staleReplacement,['username'=>'current-replacement']));
    $replacementStates=$queue->selectionStatus([$staleReplacementKey,$currentReplacementKey]);
    checkJobs($replacementStates[$staleReplacementKey]['state']==='superseded'
        && $replacementStates[$currentReplacementKey]['blockedBy']===null,'Completed cleanup with no replacement commands blocked newer desired state');
    $replacementExecutions=[];
    $queue->runSelected([$currentReplacementKey],function(array $snapshot) use(&$replacementExecutions):bool {
        checkJobs(!isset($snapshot['_cleanup']),'A superseded completed cleanup was replayed by the fresh intent');
        $replacementExecutions[]=$snapshot['username']; return true;
    });
    checkJobs($cleanupExecutions===1 && $replacementExecutions===['current-replacement']
        && $queue->appliedSnapshot(7)['username']==='current-replacement','Fresh replacement retried the obsolete intent or its completed cleanup');

    // An unfinished cleanup is still an execution barrier.
    $unfinished=['id'=>8,'clientId'=>8,'device'=>20,'username'=>'unfinished-replacement'];
    $unfinishedKey=$queue->enqueue($unfinished);
    $queue->run([$unfinishedKey],function(array $snapshot,string $operation,array $job,ApiJobQueue $store):bool {
        $snapshot['_cleanup']=['id'=>8,'clientId'=>8,'device'=>10,'username'=>'unfinished-old-account'];
        $store->freezePrepared($job,$snapshot); return false;
    });
    $afterUnfinished=$queue->enqueue(array_replace($unfinished,['username'=>'after-unfinished']));
    $unfinishedStates=$queue->selectionStatus([$unfinishedKey,$afterUnfinished]);
    checkJobs($unfinishedStates[$unfinishedKey]['state']==='pending' && $unfinishedStates[$afterUnfinished]['blockedBy']===$unfinishedKey,
        'A newer intent bypassed old-account cleanup without confirmation');
    $unfinishedExecutions=[];
    $queue->runSelected([$afterUnfinished],function(array $snapshot) use(&$unfinishedExecutions):bool {
        $unfinishedExecutions[]=$snapshot['username']; return true;
    });
    checkJobs($unfinishedExecutions===['unfinished-replacement','after-unfinished'],'Unfinished cleanup lost prerequisite ordering');

    // Frozen replacement commands remain a barrier even after cleanup is done.
    $preparedReplacement=['id'=>9,'clientId'=>9,'device'=>20,'username'=>'prepared-replacement'];
    $preparedReplacementKey=$queue->enqueue($preparedReplacement);
    $queue->run([$preparedReplacementKey],function(array $snapshot,string $operation,array $job,ApiJobQueue $store):bool {
        $cleanup=['id'=>9,'clientId'=>9,'device'=>10,'username'=>'prepared-old-account'];
        $snapshot['_cleanup']=$cleanup; $store->freezePrepared($job,$snapshot); $store->confirmCleanup($job,$cleanup);
        $snapshot['_commands']=[['path'=>'/ppp/secret','name'=>'prepared-replacement','remote-address'=>'192.0.2.49']];
        $store->freezePrepared($job,$snapshot); return false;
    });
    $afterPrepared=$queue->enqueue(array_replace($preparedReplacement,['username'=>'after-prepared']));
    checkJobs($queue->selectionStatus([$afterPrepared])[$afterPrepared]['blockedBy']===$preparedReplacementKey,
        'Confirmed cleanup allowed a newer intent to bypass possibly written replacement commands');
    $preparedExecutions=[];
    $queue->runSelected([$afterPrepared],function(array $snapshot,string $operation,array $job,ApiJobQueue $store) use(&$preparedExecutions):bool {
        if ($snapshot['username']==='prepared-replacement') checkJobs($store->cleanupCompleted($job)
            && $snapshot['_commands'][0]['remote-address']==='192.0.2.49','Retry lost frozen replacement commands or cleanup confirmation');
        $preparedExecutions[]=$snapshot['username']; return true;
    });
    checkJobs($preparedExecutions===['prepared-replacement','after-prepared'],'Prepared replacement barrier lost its ordering');

    // A newer B intent prevents an older prepared A from absorbing a later request for A.
    $firstA=['id'=>5,'username'=>'intent-cycle','status'=>1,'password'=>'cycle-secret'];
    $firstAKey=$queue->enqueue($firstA);
    $queue->run([$firstAKey], function (array $snapshot, string $operation, array $job, ApiJobQueue $store): bool {
        $snapshot['_commands']=[['path'=>'/ppp/secret','name'=>'intent-cycle','password'=>'cycle-secret']];
        $store->freezePrepared($job,$snapshot);
        return false;
    });
    $middleBKey=$queue->enqueue(array_replace($firstA,['status'=>3]));
    $lastAKey=$queue->enqueue($firstA);
    checkJobs($lastAKey!==$firstAKey && !isset($queue->list()[$middleBKey]), 'New A reused an older intent or retained obsolete B');
    $cycleStates=[];
    $queue->run([$firstAKey,$middleBKey,$lastAKey], function (array $snapshot) use (&$cycleStates): bool {
        $cycleStates[]=$snapshot['status']; return true;
    });
    checkJobs($cycleStates===[1,1] && $queue->appliedSnapshot(5)['status']===1,
        'A/B/A ended with the obsolete suspension');
    $sameAKey=$queue->enqueue(['id'=>6,'username'=>'same-current-intent','status'=>1]);
    checkJobs($queue->enqueue(['id'=>6,'username'=>'same-current-intent','status'=>1])===$sameAKey,
        'Consecutive identical current intents were not deduplicated');
    $queue->run([$sameAKey],fn():bool=>true);

    // Import preserves every entry and never re-imports successful legacy jobs on a new request.
    file_put_contents($legacy, json_encode([
        'Q50' => ['id' => 50, 'username' => 'legacy-old', 'action' => 'delete', 'password' => 'secret'],
        'Qdifferent' => ['id' => 50, 'username' => 'legacy-new', 'action' => 'update'],
        'Q51' => ['id' => 51, 'username' => 'legacy-other', 'action' => 'update']
    ], JSON_THROW_ON_ERROR));
    $imported = new ApiJobQueue($path, $legacy);
    checkJobs(count($imported->list()) === 3, 'Legacy import lost or collided with an entry');
    checkJobs(!is_file($legacy), 'Successfully imported legacy credential source remained on disk');
    $listed = json_encode($imported->list(), JSON_THROW_ON_ERROR);
    checkJobs(strpos($listed, 'secret') === false && strpos($listed, 'router-admin') === false, 'Public job list exposed credentials');
    $imported->run(null, fn(): bool => true);
    $reopened = new ApiJobQueue($path, $legacy);
    checkJobs($reopened->list() === [], 'Completed legacy jobs were imported again');
    $invalidLegacy=$directory.'/queue-invalid.json';
    file_put_contents($invalidLegacy,json_encode([
        ['id'=>800,'username'=>'must-roll-back','password'=>'unimported-secret'],['id'=>0,'username'=>'invalid']
    ],JSON_THROW_ON_ERROR));
    $invalidImportRejected=false;
    try { new ApiJobQueue($path,$invalidLegacy); }
    catch(InvalidArgumentException $error) { $invalidImportRejected=true; }
    checkJobs($invalidImportRejected && is_file($invalidLegacy) && $queue->list()===[],
        'A failed legacy import removed its source or partially committed a valid preceding entry');
    unlink($invalidLegacy);

    // Maintenance prevents workers from starting, and releases its guard after exceptions.
    $maintenanceKey = $queue->enqueue(['id' => 60, 'username' => 'maintenance']);
    $queue->withMaintenance(function () use ($second, $maintenanceKey): void {
        checkJobs($second->run([$maintenanceKey], fn(): bool => true) === [], 'Worker started during restoration');
    });
    $queue->run([$maintenanceKey], fn(): bool => true);
    checkJobs($queue->completed([$maintenanceKey]), 'Maintenance guard was not released');

    $gateBlocked = false;
    $maintenanceBlocked = false;
    try {
        $queue->withServiceLock(77, function () use ($second, &$gateBlocked, &$maintenanceBlocked): void {
            try { $second->withServiceLock(77, fn(): bool => true); }
            catch (RuntimeException $error) { $gateBlocked = true; }
            try { $second->withMaintenance(fn(): bool => true); }
            catch (RuntimeException $error) { $maintenanceBlocked = true; }
            throw new LogicException('test lock release');
        });
    } catch (LogicException $error) {}
    checkJobs($gateBlocked && $maintenanceBlocked, 'Desired-state service guard allowed competing events or restoration');
    $queue->withServiceLock(77, fn(): bool => true);
    $queue->withServiceLocks([93,92,93],function() use($queue,$second):void {
        checkJobs($queue->ownsServiceLock(92) && $queue->ownsServiceLock(93), 'Multi-service guard did not own every unique service');
        $competing=false;
        try { $second->withServiceLock(92,fn():bool=>true); } catch(RuntimeException $error) { $competing=true; }
        checkJobs($competing,'Client mutation did not reserve its complete service set');
    });
    checkJobs(!$queue->ownsServiceLock(92) && !$queue->ownsServiceLock(93),'Multi-service guard retained ownership after completion');
    try { $queue->withServiceLocks([92,93],function():void { throw new LogicException('multi-service release'); }); }
    catch(LogicException $error) {}
    $second->withServiceLocks([93,92],fn():bool=>true);
    $queue->withClientLock(94,function() use($second):void {
        $competing=false;
        try { $second->withClientLock(94,fn():bool=>true); } catch(RuntimeException $error) { $competing=true; }
        checkJobs($competing,'Concurrent client edits bypassed their stable request lock');
        $second->withClientLock(95,fn():bool=>true);
        $restore=false;
        try { $second->withMaintenance(fn():bool=>true); } catch(RuntimeException $error) { $restore=true; }
        checkJobs($restore,'Restore overlapped a client mutation');
    });
    $second->withClientLock(94,fn():bool=>true);

    // Administrative writes and restore use opposite sides of a real request-scoped barrier.
    $adminBlocked=false;
    $queue->withMaintenance(function () use ($second,&$adminBlocked): void {
        try { $second->withAdministrativeLock(fn():bool=>true); }
        catch(RuntimeException $error) { $adminBlocked=true; }
    });
    checkJobs($adminBlocked,'Administrative mutation entered a restore barrier');
    $restoreBlocked=false;
    $queue->withAdministrativeLock(function () use ($second,&$restoreBlocked): void {
        try { $second->withMaintenance(fn():bool=>true); }
        catch(RuntimeException $error) { $restoreBlocked=true; }
    });
    checkJobs($restoreBlocked,'Restore entered an administrative mutation');
    try { $queue->withAdministrativeLock(function (): void { throw new LogicException('release admin'); }); }
    catch(LogicException $error) {}
    $second->withMaintenance(fn():bool=>true);

    // An expired lease with a live owner is still protected by its open request lock.
    $liveKey=$queue->enqueue(['id'=>79,'username'=>'live-request']);
    $queue->run([$liveKey],function(array $snapshot,string $operation,array $job) use($path,$second,$liveKey):bool {
        $inspect=new SQLite3($path);
        $inspect->exec('UPDATE jobs SET lease_until=0 WHERE id='.(int)$job['id']); $inspect->close();
        checkJobs($second->run([$liveKey],fn():bool=>true)===[], 'An expired active request was overlapped');
        return true;
    });
    // Simulate a finished/crashed FPM request: PID survives, but its unique request lock is gone.
    $crashedKey=$queue->enqueue(['id'=>83,'username'=>'surviving-fpm-pid']);
    $inspect=new SQLite3($path);
    $crashedOwner=str_repeat('a',32);
    $inspect->exec("UPDATE jobs SET status='running',owner='$crashedOwner',owner_lock='$crashedOwner',owner_pid="
        .getmypid().',lease_until='.(time()+900).' WHERE id='.(int)substr($crashedKey,1));
    $inspect->exec("INSERT INTO service_event_locks(service_id,owner,owner_pid,lease_until,owner_lock)
        VALUES(84,'$crashedOwner',".getmypid().','. (time()+900).",'$crashedOwner')");
    $inspect->close();
    $second->run([$crashedKey],fn():bool=>true);
    checkJobs($second->completed([$crashedKey]),'A dead request was retained by the still-live FPM PID');
    $second->withServiceLock(84,fn():bool=>true);
    // Sharing a PID is not authorization to run work through another request's service gate.
    $gatedKey=$queue->enqueue(['id'=>85,'username'=>'gated-request']);
    $queue->withServiceLock(85,function() use($queue,$second,$gatedKey):void {
        checkJobs($second->run([$gatedKey],fn():bool=>true)===[], 'A different queue instance bypassed an event gate using the same PID');
        $queue->run([$gatedKey],fn():bool=>true);
    });
    checkJobs($queue->completed([$gatedKey]),'The owning event request could not process its own service');

    // Successful historical jobs contain no retry secrets; old UUIDs survive as minimal receipts.
    $retained=['id'=>86,'clientId'=>86,'username'=>'historical-user','password'=>'history-secret','_event_id'=>'old-receipt',
        '_device'=>['id'=>30,'password'=>'history-router-secret']];
    $retainedKey=$queue->enqueue($retained);
    $queue->run([$retainedKey],fn():bool=>true);
    $inspect=new SQLite3($path);
    $historicalPayload=$inspect->querySingle('SELECT payload FROM jobs WHERE id='.(int)substr($retainedKey,1));
    checkJobs(strpos($historicalPayload,'history-secret')===false && strpos($historicalPayload,'_device')===false,
        'Completed historical payload still retained credentials');
    checkJobs($queue->appliedSnapshot(86)['password']==='history-secret' && $queue->hasDeviceReferences([30]),
        'Compaction discarded the current applied cleanup snapshot or its device reference');
    $inspect->exec("UPDATE jobs SET updated='2000-01-01T00:00:00+00:00' WHERE id=".(int)substr($retainedKey,1));
    $inspect->close();
    $historyReopened=new ApiJobQueue($path,null);
    $inspect=new SQLite3($path);
    checkJobs((int)$inspect->querySingle('SELECT COUNT(*) FROM jobs WHERE id='.(int)substr($retainedKey,1))===0,
        'Expired completed history was not purged');
    $inspect->close();
    checkJobs($historyReopened->enqueue($retained)===$retainedKey && $historyReopened->completed([$retainedKey])
        && $historyReopened->eventKeys('old-receipt')===[$retainedKey]
        && $historyReopened->eventSnapshot('old-receipt')['clientId']===86,
        'Purged webhook receipt lost deduplication or its non-sensitive result context');
    checkJobs($historyReopened->run([$retainedKey],function (): bool { throw new RuntimeException('replayed historical event'); })===[],
        'An old completed webhook was replayed after history compaction');
    $historyReopened->close();
    // Upgrade removes obsolete cleanup credentials from an already-confirmed legacy applied snapshot.
    $legacyApplied=$queue->appliedSnapshot(86);
    $legacyApplied['_cleanup']=['id'=>86,'device'=>40,'_device'=>['id'=>40,'password'=>'obsolete-router-secret']];
    $legacyApplied['_prior_network']=['address'=>'192.0.2.86']; $legacyApplied['_prior_identity']='obsolete';
    $inspect=new SQLite3($path);
    $statement=$inspect->prepare('UPDATE service_versions SET applied_payload=:payload WHERE service_id=86');
    $statement->bindValue(':payload',json_encode($legacyApplied,JSON_THROW_ON_ERROR),SQLITE3_TEXT);
    $statement->execute()->finalize(); $statement->close();
    $inspect->exec("DELETE FROM queue_meta WHERE key='applied_cleanup_compacted_v1'"); $inspect->close();
    $migrationReopened=new ApiJobQueue($path,null);
    checkJobs(!isset($migrationReopened->appliedSnapshot(86)['_cleanup'])
        && !$migrationReopened->hasDeviceReferences([40]) && $migrationReopened->hasDeviceReferences([30]),
        'Upgrade retained an already-cleaned old router or discarded the active router reference');
    $migrationReopened->close();
    $cancelDeviceKey=$queue->enqueue(['id'=>87,'device'=>87,'_device'=>['id'=>87,'password'=>'cancelled-secret']]);
    checkJobs($queue->hasDeviceReferences([87]),'Pending router reference was not protected');
    checkJobs($queue->delete([$cancelDeviceKey])[$cancelDeviceKey] && !$queue->hasDeviceReferences([87]),
        'An unprepared cancelled row kept an obsolete device reference');
    $oldIdentity=['id'=>88,'device'=>88,'username'=>'old-router-account','address'=>'192.0.2.88',
        '_device'=>['id'=>88,'ip'=>'192.0.2.88','password'=>'current-secret']];
    $oldIdentityKey=$queue->enqueue($oldIdentity);
    $queue->run([$oldIdentityKey],fn():bool=>true);
    $abandonedTarget=array_replace($oldIdentity,['device'=>89,'username'=>'never-provisioned',
        '_device'=>['id'=>89,'ip'=>'192.0.2.89','password'=>'unused-secret'],
        '_prior_network'=>['address'=>'192.0.2.88']]);
    $abandonedKey=$queue->enqueue($abandonedTarget);
    checkJobs($queue->delete([$abandonedKey])[$abandonedKey] && $queue->hasDeviceReferences([89]),
        'Cancellation lost an unresolved prior reservation');
    $clearOldKey=$queue->enqueue($oldIdentity,'delete');
    $queue->run([$clearOldKey],static function ($snapshot,$operation,$job,ApiJobQueue $store): bool {
        // The local executor models confirmed physical cleanup, including the borrowed applied identity.
        foreach ($store->cancellationCleanups($job) as $debt) $store->confirmCancellationCleanup($job,$debt['id']);
        return true;
    });
    checkJobs(!$queue->hasDeviceReferences([88,89]),
        'Confirmed cleanup kept a never-provisioned cancelled target router blocked');
    $backoffKey = $queue->enqueue(['id' => 78, 'username' => 'backoff']);
    $queue->run([$backoffKey], fn(): bool => false);
    checkJobs($queue->run(null, fn(): bool => true) === [], 'Scheduled retry ignored failure backoff');
    $queue->run([$backoffKey], fn(): bool => true);
    checkJobs($queue->completed([$backoffKey]), 'Explicit retry could not bypass backoff');

    // The original cron class now initializes and delegates without clearing pending work.
    $pending = $queue->enqueue(['id' => 70, 'username' => 'cron']);
    $jobs = new ApiJobs(null, $queue);
    checkJobs(count($jobs->list()) === 1 && isset($queue->list()[$pending]), 'ApiJobs initialization cleared the queue');

    // Exercise the production Batch with a real SQLite state store and fake device transports.
    // The stubs intentionally report a success and a failure for the same service on partial writes.
    $source = realpath(__DIR__ . '/../src');
    $originalDirectory = getcwd();
    $originalIncludePath = get_include_path();
    mkdir($directory . '/stubs');
    mkdir($directory . '/data');
    file_put_contents($directory . '/stubs/api_sqlite.php', '<?php require_once ' . var_export($source . '/lib/api_sqlite.php', true) . ';');
    file_put_contents($directory . '/stubs/api_ip.php', <<<'PHP'
<?php
// Numeric snapshot validation has already loaded the production ApiIP helper.
// Install a compatible fixture instance instead of redeclaring its global function.
class JobsIPStub extends ApiIP {
    public static $beforeFlush=null;
    public function flush(): void { if(self::$beforeFlush!==null) { (self::$beforeFlush)(); } }
    public function __destruct() {}
}
$apiIpClass = new JobsIPStub();
PHP);
    file_put_contents($directory . '/stubs/api_sites.php', <<<'PHP'
<?php
class ApiSites { public function set($ids): void {} public function delete($ids): void {} }
PHP);
    file_put_contents($directory . '/stubs/mt_data.php', <<<'PHP'
<?php
class MtData {
    private array $service = [];
    public function poolAllocations(): array { return []; }
    public function validateBeforeCleanup(array $service,array $plan,bool $releasesReservation): void {}
    public static function safePreparedCommands(array $service,array $commands,string $operation): array { return $commands; }
    public function set_data($service,$plan): void { $this->service=$service; }
    public function queue(): array { return ['path'=>'/queue/simple','name'=>'queue-'.$this->service['id']]; }
    public function get_data($service,$plan): array {
        return [
            'profiles'=>['path'=>'/ppp/profile','name'=>$plan['name']],
            'queues'=>['path'=>'/queue/simple','name'=>$service['queueName']??'queue-'.$service['id'],
                'target'=>$service['address']??'192.0.2.1'],
            'accounts'=>['path'=>'/ppp/secret','name'=>$service['username'],
                'remote-address'=>$service['address']??'192.0.2.1'],
            'dhcp6'=>['path'=>'/ipv6/dhcp-server/binding','duid'=>'test-duid'],
            'disconn'=>['path'=>'/ppp/active','name'=>$service['username'],'action'=>'remove']
        ];
    }
}
PHP);
    file_put_contents($directory . '/stubs/mt.php', <<<'PHP'
<?php
class ApiTimer { public function __construct($name) {} public function stop(): void {} }
class ApiList { public function __construct($data,$type) {} public function list(): void {} }
function MyLog() { static $log; return $log ??= new class { public function Append($message,$level=0): void {} }; }
class MT {
    public static array $writes=[];
    public static ?string $failPath=null;
    public static bool $failOnlyRemove=false;
    public static bool $failOnlySet=false;
    public static $beforeWrite=null;
    private array $failed=[];
    private array $success=[];
    public function do_batch($device,$commands): int {
        $this->failed=[]; $this->success=[]; $written=0;
        foreach($commands as $command) {
            if(self::$beforeWrite!==null) { (self::$beforeWrite)($device,$command); }
            self::$writes[]=[$device->id,$command,$device->ip];
            if($command['path']===self::$failPath && (!self::$failOnlyRemove || ($command['action']??null)==='remove')
                && (!self::$failOnlySet || ($command['action']??null)!=='remove')) {
                $this->failed[$command['batch']]='simulated partial failure'; break;
            }
            $this->success[$command['batch']]=$command['batch']; $written++;
        }
        return $written;
    }
    public function failed(): array { return $this->failed; }
    public function success(): array { return $this->success; }
}
PHP);
    chdir($directory);
    set_include_path($directory . '/stubs' . PATH_SEPARATOR . $originalIncludePath);
    require_once $source . '/lib/batch.php';
    $fixtureLoaded = true;
    checkJobs(myIPClass() instanceof JobsIPStub, 'The typed production IP factory did not use the isolated fixture');
    mySqlite()->exec(file_get_contents($source . '/includes/schema.sql'));
    myCache()->exec(file_get_contents($source . '/includes/cache.sql'));
    foreach ([10,20] as $deviceId) {
        mySqlite()->insert(['id'=>$deviceId,'name'=>'router-'.$deviceId,'ip'=>'192.0.2.'.$deviceId,
            'port'=>8728,'type'=>'mikrotik','user'=>'test-user','password'=>'test-secret'], 'devices');
    }
    $service = ['id'=>80,'device'=>10,'clientId'=>80,'planId'=>null,'status'=>1,'username'=>'before',
        'password'=>'password','company'=>null,'firstName'=>'Test','lastName'=>'Client'];
    myCache()->insert(array_diff_key($service, array_flip(['company','firstName','lastName'])), 'services');
    MT::$failPath='/ipv6/dhcp-server/binding';
    checkJobs((new Batch())->set_accounts([$service])===false, 'Partial dual-stack provisioning was reported successful');
    checkJobs(!mySqlite()->singleQuery('SELECT id FROM services WHERE id=80'), 'Partial provisioning saved applied service state');
    checkJobs(count(myJobQueue()->list())===1, 'Partial provisioning was not retained for retry');
    $beforeRetry=count(MT::$writes);
    myCache()->edit(['id'=>80,'username'=>'changed-cache'], 'services');
    MT::$failPath=null;
    myJobQueue()->run(array_keys(myJobQueue()->list()));
    $retryCommands=array_column(array_slice(MT::$writes,$beforeRetry),1);
    $retryAccounts=array_values(array_filter($retryCommands,fn($c)=>$c['path']==='/ppp/secret'));
    checkJobs($retryAccounts[0]['name']==='before', 'Retry reread changed cached credentials');
    checkJobs((int)mySqlite()->singleQuery('SELECT device FROM services WHERE id=80')===10, 'Successful retry did not save applied state');

    $replacement=array_replace($service,['device'=>20,'username'=>'after']);
    MT::$failPath='/ppp/secret'; MT::$failOnlyRemove=true;
    $beforeMigration=count(MT::$writes);
    checkJobs((new Batch())->migrate($service,$replacement)===false, 'Failed migration cleanup reported success');
    foreach(array_slice(MT::$writes,$beforeMigration) as [$did,$command]) {
        checkJobs($did===10, 'Replacement router was provisioned before cleanup succeeded');
    }
    MT::$failPath=null; MT::$failOnlyRemove=false;
    myJobQueue()->run(array_keys(myJobQueue()->list()));
    checkJobs((int)mySqlite()->singleQuery('SELECT device FROM services WHERE id=80')===20, 'Migration did not apply replacement after cleanup');
    checkJobs(!isset(myJobQueue()->appliedSnapshot(80)['_cleanup']), 'Applied migration retained obsolete cleanup credentials');
    checkJobs((new Batch())->del_accounts([$service]), 'Idempotent old-identity deletion failed');
    checkJobs((int)mySqlite()->singleQuery('SELECT device FROM services WHERE id=80')===20, 'Late old-account cleanup erased replacement applied state');

    $unmapped=array_replace($service,['id'=>81,'device'=>null]);
    $valid=array_replace($service,['id'=>82,'username'=>'valid']);
    checkJobs((new Batch())->set_accounts([$unmapped,$valid])===false, 'Unmapped service was falsely confirmed');
    checkJobs((int)mySqlite()->singleQuery('SELECT device FROM services WHERE id=82')===10, 'An unmapped service aborted other valid jobs');
    mySqlite()->edit(['id'=>10,'ip'=>'192.0.2.99'], 'devices');
    $beforeDestinationChange=count(MT::$writes);
    checkJobs((new Batch())->set_accounts([$valid]), 'Destination change did not reconcile the applied account');
    $destinationCommands=array_slice(MT::$writes,$beforeDestinationChange);
    checkJobs($destinationCommands[0][2]==='192.0.2.10' && end($destinationCommands)[2]==='192.0.2.99',
        'A changed device IP redirected old-account cleanup to the new router');
    mySqlite()->saveConfig(['disabled_routers'=>'10']);
    checkJobs((new Batch())->set_queues([$replacement],false), 'Queue-only operation failed');
    checkJobs(mySqlite()->readConfig()->disabled_routers==='10,20', 'Disabling a router forgot previously disabled routers');

    // Inspect the durable payload, rather than the deliberately redacted public queue listing.
    $readJob = static function (string $key): array {
        $db = new SQLite3('data/jobs.db', SQLITE3_OPEN_READONLY);
        $row = $db->querySingle('SELECT * FROM jobs WHERE id=' . (int) substr($key, 1), true);
        $db->close();
        $row['snapshot'] = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        return $row;
    };
    $snapshotMethod = new ReflectionMethod(Batch::class, 'snapshot');
    if(PHP_VERSION_ID<80100) { $snapshotMethod->setAccessible(true); }
    $makeSnapshot = static function (array $value, string $operation = 'update') use ($snapshotMethod): array {
        return $snapshotMethod->invoke(new Batch(), $value, $operation);
    };
    $base = array_replace($service, ['id'=>100,'clientId'=>100,'device'=>20,'username'=>'A',
        'queueName'=>'queue-A','address'=>'192.0.2.100']);
    checkJobs((new Batch())->set_accounts([$base]), 'Could not establish A for prepared migration regression');
    $middle = array_replace($base, ['username'=>'B','queueName'=>'queue-B','address'=>'192.0.2.101']);
    $middleKey = myJobQueue()->enqueue($makeSnapshot($middle));
    MT::$failPath='/ipv6/dhcp-server/binding'; MT::$failOnlySet=true;
    myJobQueue()->run([$middleKey]);
    $middleJob = $readJob($middleKey);
    checkJobs($middleJob['status']==='pending' && isset($middleJob['snapshot']['_commands']),
        'B did not retain its prepared partial write');
    $last = array_replace($base, ['username'=>'C','queueName'=>'queue-C','address'=>'192.0.2.102']);
    $lastKey = myJobQueue()->enqueue($makeSnapshot($last));
    checkJobs(isset(myJobQueue()->list()[$middleKey]), 'C superseded partially applied B');
    checkJobs(myJobQueue()->run([$lastKey])===[], 'C ran before the prepared B barrier completed');
    MT::$failPath=null; MT::$failOnlySet=false;
    myJobQueue()->run([$middleKey]);
    checkJobs(myJobQueue()->completed([$middleKey]) && myJobQueue()->appliedSnapshot(100)['username']==='B',
        'B was not confirmed before C reconciliation');
    $beforeLast=count(MT::$writes);
    myJobQueue()->run([$lastKey]);
    $lastCommands=array_column(array_slice(MT::$writes,$beforeLast),1);
    $removedB=null; $createdC=null;
    foreach($lastCommands as $index=>$command) {
        if($command['path']!=='/ppp/secret') { continue; }
        checkJobs($command['name']!=='A', 'C reconciled obsolete A instead of the applied B identity');
        if($command['name']==='B' && ($command['action']??null)==='remove') { $removedB=$index; }
        if($command['name']==='C' && ($command['action']??null)!=='remove') { $createdC=$index; }
    }
    checkJobs($removedB!==null && $createdC!==null && $removedB<$createdC && myJobQueue()->completed([$lastKey]),
        'C did not clean the actual B account before provisioning its replacement');

    // A deletion queued before an earlier same-account update must refresh its resource snapshot once.
    $resourceA=array_replace($base,['id'=>101,'clientId'=>101,'username'=>'resources','queueName'=>'resource-A',
        'address'=>'192.0.2.110']);
    checkJobs((new Batch())->set_accounts([$resourceA]), 'Could not establish resource A');
    $resourceB=array_replace($resourceA,['queueName'=>'resource-B','address'=>'192.0.2.111']);
    $resourceBKey=myJobQueue()->enqueue($makeSnapshot($resourceB));
    $deleteKey=myJobQueue()->enqueue($makeSnapshot($resourceA,'delete'),'delete');
    checkJobs(empty($readJob($deleteKey)['snapshot']['_delete_frozen']), 'A queued deletion was frozen before its first write');
    myJobQueue()->run([$resourceBKey]);
    myJobQueue()->run([$deleteKey], static function (): bool { throw new RuntimeException('simulated crash after claim before preparation'); });
    checkJobs((int)$readJob($deleteKey)['attempts']===1 && empty($readJob($deleteKey)['snapshot']['_delete_frozen']),
        'A claim failure incorrectly froze deletion resources');
    $observedFrozen=false;
    MT::$beforeWrite=static function ($device,$command) use ($readJob,$deleteKey,&$observedFrozen): void {
        if($observedFrozen) { return; }
        $snapshot=$readJob($deleteKey)['snapshot'];
        checkJobs(!empty($snapshot['_delete_frozen']), 'Deletion wrote before durably freezing its refreshed resources');
        $queues=array_values(array_filter($snapshot['_commands'],fn($value)=>$value['path']==='/queue/simple'));
        $accounts=array_values(array_filter($snapshot['_commands'],fn($value)=>$value['path']==='/ppp/secret'));
        checkJobs($queues[0]['name']==='resource-B' && $accounts[0]['remote-address']==='192.0.2.111',
            'A claimed-before-freeze retry did not refresh the applied B queue and IP resources');
        $observedFrozen=true;
    };
    MT::$failPath='/ipv6/dhcp-server/binding'; MT::$failOnlyRemove=true;
    myJobQueue()->run([$deleteKey]);
    MT::$beforeWrite=null; MT::$failPath=null; MT::$failOnlyRemove=false;
    checkJobs($observedFrozen && $readJob($deleteKey)['status']==='pending', 'Failed frozen deletion did not remain retryable');
    $frozenDeletion=$readJob($deleteKey)['snapshot'];
    // Simulate a changed external applied-state record; a retry must keep its already frozen commands.
    $mutatedApplied=myJobQueue()->appliedSnapshot(101);
    foreach($mutatedApplied['_commands'] as &$command) {
        if($command['path']==='/queue/simple') { $command['name']='resource-after-freeze'; }
        if($command['path']==='/ppp/secret') { $command['remote-address']='192.0.2.112'; }
    }
    unset($command);
    $inspectionDb=new SQLite3('data/jobs.db');
    $statement=$inspectionDb->prepare('UPDATE service_versions SET applied_payload=:payload WHERE service_id=101');
    $statement->bindValue(':payload',json_encode($mutatedApplied,JSON_THROW_ON_ERROR),SQLITE3_TEXT);
    $statement->execute()->finalize(); $statement->close(); $inspectionDb->close();
    $beforeFrozenRetry=count(MT::$writes);
    myJobQueue()->run([$deleteKey]);
    $frozenRetryCommands=array_column(array_slice(MT::$writes,$beforeFrozenRetry),1);
    checkJobs($frozenRetryCommands===$frozenDeletion['_commands'] && myJobQueue()->completed([$deleteKey]),
        'Frozen deletion retry did not reuse exactly the original command sequence');
    checkJobs(!isset($readJob($deleteKey)['snapshot']['_commands']), 'Successful deletion retained its credential-bearing command history');

    // Before command freezing, flushing a replacement reservation cannot release the old live address.
    $priorService=array_replace($base,['id'=>102,'clientId'=>102,'username'=>'prior-hold','address'=>'192.0.2.121',
        'address6'=>'2001:db8:12:1::/64']);
    mySqlite()->insert(['id'=>102,'address'=>'192.0.2.120','address6'=>'2001:db8:12::/64'],'network');
    $priorKey=myJobQueue()->enqueue($makeSnapshot($priorService));
    $flushChecked=false;
    JobsIPStub::$beforeFlush=static function () use ($readJob,$priorKey,&$flushChecked): void {
        if($flushChecked) { return; }
        mySqlite()->edit(['id'=>102,'address'=>'192.0.2.121','address6'=>'2001:db8:12:1::/64'],'network');
        checkJobs(!isset($readJob($priorKey)['snapshot']['_commands']), 'Reservation test did not exercise the pre-freeze interval');
        $held4=array_values(array_filter(myJobQueue()->reservedAddresses(false),fn($row)=>$row['id']===102));
        $held6=array_values(array_filter(myJobQueue()->reservedAddresses(true),fn($row)=>$row['id']===102));
        checkJobs(in_array('192.0.2.120',array_column($held4,'address'),true)
            && in_array('2001:db8:12::/64',array_column($held6,'address6'),true),
            'Pending _prior_network without commands released an old IPv4 or IPv6 reservation');
        $flushChecked=true;
        throw new RuntimeException('simulated crash after reservation flush before command freeze');
    };
    myJobQueue()->run([$priorKey]);
    JobsIPStub::$beforeFlush=null;
    checkJobs($flushChecked && $readJob($priorKey)['status']==='pending'
        && !isset($readJob($priorKey)['snapshot']['_commands']), 'Flush-before-freeze failure discarded prior reservations');
    myJobQueue()->run([$priorKey]);
    checkJobs(myJobQueue()->completed([$priorKey]), 'The pre-freeze reservation failure could not be retried');

    // Lifecycle webhooks often contain only an ID and state; preserve cached identity and deduplicate UUIDs.
    if (!function_exists('myTrimmer')) {
        function myTrimmer() {
            static $trimmer;
            return $trimmer ??= new class {
                public function trim($type,$request): array {
                    $entity=(array)($request->extraData->entity??new stdClass());
                    unset($entity['attributes']);
                    $data=['entity'=>$entity];
                    if(isset($request->extraData->entityBeforeEdit)) {
                        $data['previous']=(array)$request->extraData->entityBeforeEdit;
                        unset($data['previous']['attributes']);
                    }
                    return $data;
                }
            };
        }
    }
    require_once $source . '/lib/api_action.php';
    myCache()->insert(['id'=>90,'company'=>null,'firstName'=>'Webhook','lastName'=>'Client'], 'clients');
    myCache()->insert(['id'=>90,'clientId'=>90,'device'=>20,'username'=>'lifecycle','password'=>'private','status'=>1], 'services');
    myCache()->insert(['id'=>90,'address'=>'192.0.2.90','address6'=>null], 'network');
    $lifecycle = function (string $action, string $uuid): object {
        return (object)['entity'=>'service','entityId'=>90,'changeType'=>$action,'uuid'=>$uuid,
            'extraData'=>(object)['entity'=>(object)['id'=>90,'clientId'=>90]]];
    };
    $suspendRequest=$lifecycle('suspend','alias-suspend');
    $suspendAction=new ApiAction($suspendRequest); $suspendAction->exec();
    checkJobs(!$suspendAction->status()->error && (int)mySqlite()->singleQuery('SELECT status FROM services WHERE id=90')===3,
        'Explicit suspension did not preserve credentials and apply the suspended state');
    $resumeAction=new ApiAction($lifecycle('unsuspend','alias-resume')); $resumeAction->exec();
    checkJobs(!$resumeAction->status()->error && (int)mySqlite()->singleQuery('SELECT status FROM services WHERE id=90')===1,
        'Explicit reactivation failed');
    $beforeReplay=count(MT::$writes);
    (new ApiAction($suspendRequest))->exec();
    checkJobs(count(MT::$writes)===$beforeReplay && (int)myCache()->singleQuery('SELECT status FROM services WHERE id=90')===1,
        'A duplicate old UUID repeated router writes or overwrote newer desired state');
    $invalidRequest=$lifecycle('delete','invalid-id'); $invalidRequest->extraData->entity->id='90 OR 1=1';
    $invalidRejected=false;
    try { (new ApiAction($invalidRequest))->exec(); } catch(InvalidArgumentException $error) { $invalidRejected=true; }
    checkJobs($invalidRejected && count(MT::$writes)===$beforeReplay, 'An invalid webhook ID was coerced to a real service');
    $deleteAction=new ApiAction($lifecycle('delete','alias-delete')); $deleteAction->exec();
    checkJobs(!$deleteAction->status()->error && !mySqlite()->singleQuery('SELECT id FROM services WHERE id=90')
        && !myCache()->singleQuery('SELECT id FROM services WHERE id=90') && !myCache()->singleQuery('SELECT id FROM network WHERE id=90'),
        'Explicit deletion did not clear both cached service and network state');
    myCache()->insert(['id'=>91,'company'=>null,'firstName'=>'Thin','lastName'=>'Client'], 'clients');
    myCache()->insert(['id'=>91,'clientId'=>91,'device'=>20,'username'=>'thin','password'=>'private','status'=>1], 'services');
    myCache()->insert(['id'=>91,'address'=>'192.0.2.91','address6'=>null], 'network');
    $thinRequest=(object)['entity'=>'service','entityId'=>'91','changeType'=>'delete','uuid'=>'thin-delete'];
    $thinAction=new ApiAction($thinRequest); $thinAction->exec();
    checkJobs(!$thinAction->status()->error && strpos($thinAction->status()->message,'(91)')!==false
        && !myCache()->singleQuery('SELECT id FROM services WHERE id=91') && !myCache()->singleQuery('SELECT id FROM network WHERE id=91'),
        'A thin deletion webhook did not recover the cached service identity and client ID');
    myJobQueue()->close(); mySqlite()->close(); myCache()->close();
    chdir($originalDirectory); set_include_path($originalIncludePath);
    echo "OK: queue ordering, retry snapshots, UUID deduplication, concurrency, legacy import, secrets, maintenance, cron initialization\n";
    echo "OK: partial-write confirmation, immutable Batch retries, migration barriers, applied identities, unmapped services, empty plans, router configuration\n";
    echo "OK: explicit suspension/reactivation/deletion, ID validation, duplicate webhook desired-state protection\n";
    echo "OK: prepared A/B/C ordering, applied-resource deletion refresh, pre-freeze failures, immutable deletion retries, prior IPv4/IPv6 reservation holds\n";
    echo "OK: latest A/B/A intent, request-owned locks, administrative/restore exclusion, FPM recovery, secret history compaction and durable UUID receipts\n";
} finally {
    if ($fixtureLoaded) {
        myJobQueue()->close(); mySqlite()->close(); myCache()->close();
        chdir($originalDirectory); set_include_path($originalIncludePath);
    }
    foreach ([$migrationReopened ?? null, $historyReopened ?? null, $reopened ?? null, $imported ?? null, $second ?? null, $queue ?? null] as $store) {
        if ($store instanceof ApiJobQueue) { $store->close(); }
    }
    unset($jobs, $reopened, $imported, $second, $queue);
    foreach (['jobs.db', 'jobs.db-journal', 'jobs.db-wal', 'jobs.db-shm', 'queue.json'] as $file) {
        $target = $directory . '/' . $file;
        if (is_file($target)) { unlink($target); }
    }
    foreach (['jobs.db','jobs.db-journal','data.db','data.db-journal','cache.db','cache.db-journal'] as $file) {
        $target=$directory.'/data/'.$file;
        if(is_file($target)) { unlink($target); }
    }
    foreach(['mt.php','mt_data.php','api_ip.php','api_sqlite.php','api_sites.php'] as $file) {
        $target=$directory.'/stubs/'.$file;
        if(is_file($target)) { unlink($target); }
    }
    // Request lock files are inside this disposable fixture, alongside each SQLite queue.
    foreach ([$directory,$directory.'/data'] as $lockParent) {
        foreach (glob($lockParent.'/.uisp-queue-locks-*',GLOB_ONLYDIR) ?: [] as $locks) {
            foreach (glob($locks.'/*.lock') ?: [] as $lockFile) { unlink($lockFile); }
            rmdir($locks);
        }
    }
    if(is_dir($directory.'/data')) { rmdir($directory.'/data'); }
    if(is_dir($directory.'/stubs')) { rmdir($directory.'/stubs'); }
    rmdir($directory);
}
