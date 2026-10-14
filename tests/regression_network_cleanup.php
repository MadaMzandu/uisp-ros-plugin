<?php
// Private synthetic databases and transport fixtures; no UISP/RouterOS requests.
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
function checkCleanup(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function rejectCleanup(callable $operation, string $message): void {
    try { $operation(); }
    catch (InvalidArgumentException $error) {
        checkCleanup($error->getMessage()===$message, 'Unexpected cleanup rejection: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Cleanup accepted unsafe work: ' . $message);
}
function cleanupHttp(int $status, ?Throwable $previous = null): Throwable {
    return new GuzzleHttp\Exception\RequestException('PRIVATE TRANSPORT FIXTURE',
        new GuzzleHttp\Psr7\Request('GET', 'https://cleanup-fixture.invalid/api'),
        new GuzzleHttp\Psr7\Response($status), $previous);
}
function cleanupFixture(string $root, string $name, bool $constraint = false): array {
    $directory = $root . '/' . $name;
    mkdir($directory, 0700);
    $dbPath = $directory . '/data.db';
    $db = new SQLite3($dbPath);
    $db->enableExceptions(true);
    $db->exec('CREATE TABLE services(id INTEGER PRIMARY KEY,clientId INTEGER,status INTEGER,device INTEGER,planId INTEGER,
        address TEXT,prefix6 TEXT' . ($constraint ? ' CHECK(prefix6 IS NOT NULL)' : '') . ',privateFixture TEXT);
        CREATE TABLE network(id INTEGER PRIMARY KEY,address TEXT,address6 TEXT,last TEXT);
        CREATE TABLE config(key TEXT PRIMARY KEY,value TEXT);
        CREATE TABLE devices(id INTEGER PRIMARY KEY,name TEXT,password TEXT);
        CREATE TABLE plans(id INTEGER PRIMARY KEY,name TEXT);
        INSERT INTO config VALUES(\'version\',\'2.3.1\'),(\'backup_cursor\',\'4\');
        INSERT INTO devices VALUES(1,\'Synthetic Router\',\'PRIVATE-ROUTER-FIXTURE\');
        INSERT INTO plans VALUES(1,\'Synthetic Plan\');
        INSERT INTO services VALUES
          (1,10,1,1,1,\'192.0.2.99\',\'2001:db8::/64\',\'PRIVATE-SERVICE-FIXTURE\'),
          (2,10,1,1,1,\'192.0.2.99\',\'2001:db8::/64\',\'PRIVATE-CURRENT-FIXTURE\'),
          (4,40,1,1,1,\'192.0.2.100\',\'2001:db8:4::/64\',\'PRIVATE-CURRENT-FIXTURE\'),
          (5,50,2,1,1,\'192.0.2.101\',\'2001:db8:5::/64\',\'PRIVATE-ENDED-FIXTURE\'),
          (6,60,8,1,1,\'192.0.2.101\',\'2001:db8:6::/64\',\'PRIVATE-INACTIVE-FIXTURE\'),
          (7,70,1,1,1,\'192.0.2.102\',\'2001:db8:7::/64\',\'PRIVATE-LEGACY-FIXTURE\'),
          (8,80,2,1,1,\'192.0.2.102\',\'2001:db8:7::/64\',\'PRIVATE-CURRENT-FIXTURE\');
        INSERT INTO network VALUES
          (1,\'192.0.2.99\',\'2001:db8::/64\',\'legacy-marker\'),
          (2,\'192.0.2.99\',\'2001:db8::/64\',\'current-marker\'),
          (3,\'192.0.2.100\',\'2001:db8:3::/64\',\'unknown-owner-marker\'),
          (4,\'192.0.2.100\',\'2001:db8:4::/64\',\'current-marker\'),
          (5,\'192.0.2.101\',\'2001:db8:5::/64\',\'ended-marker\'),
          (6,\'192.0.2.101\',\'2001:db8:6::/64\',\'inactive-marker\')');
    $db->close();
    file_put_contents($directory . '/queue.json', '{}');
    file_put_contents($directory . '/cache.db', 'PRIVATE-CACHE-FIXTURE');
    file_put_contents($directory . '/state.json', '{"test":"private-state"}');
    file_put_contents($directory . '/backup-4', 'PRIVATE-ORIGINAL-BACKUP');
    return ['directory'=>$directory,'db'=>$dbPath,'jobs'=>$directory . '/jobs.db','legacy'=>$directory . '/queue.json'];
}
function cleanupTables(string $path): array {
    $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
    $db->enableExceptions(true);
    try {
        $data = [];
        $schema = $db->query("SELECT type,name,sql FROM sqlite_master ORDER BY type,name");
        while ($entry = $schema->fetchArray(SQLITE3_ASSOC)) {
            if ($entry['type']==='table') {
                $rows = $db->query('SELECT * FROM "' . $entry['name'] . '" ORDER BY rowid');
                $items = [];
                while ($row=$rows->fetchArray(SQLITE3_ASSOC)) $items[] = $row;
                $rows->finalize();
                $data[$entry['name']] = $items;
            }
        }
        $schema->finalize();
        return $data;
    } finally { $db->close(); }
}
function cleanupOperational(array $fixture): array {
    $data = cleanupTables($fixture['db']);
    $files = [];
    foreach (['cache.db','state.json','queue.json','backup-4','jobs.db'] as $name) {
        $path = $fixture['directory'] . '/' . $name;
        $files[$name] = is_file($path) ? hash_file('sha256',$path) : null;
    }
    return ['data'=>$data,'files'=>$files];
}
function cleanupSql(array $fixture, string $sql): void {
    $db = new SQLite3($fixture['db']);
    $db->enableExceptions(true);
    try { $db->exec($sql); } finally { $db->close(); }
}
function cleanupQueueSql(array $fixture, string $sql): void {
    $db = new SQLite3($fixture['jobs']);
    $db->enableExceptions(true);
    try { $db->exec($sql); } finally { $db->close(); }
}

$source = realpath(__DIR__ . '/../src');
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-network-cleanup-' . bin2hex(random_bytes(8));
mkdir($root,0700);
$time = 1800000000.0;
$responses = [];
$calls = [];
$request = static function (string $path) use (&$responses,&$calls) {
    $calls[] = $path;
    checkCleanup($path==='custom-attributes' || preg_match('~^clients/services/[1-9][0-9]*$~D',$path)===1, 'Cleanup attempted an unapproved CRM endpoint');
    if (!array_key_exists($path,$responses)) throw new RuntimeException('Unexpected synthetic service lookup');
    if ($responses[$path] instanceof Throwable) throw $responses[$path];
    return $responses[$path];
};
$clock = static function () use (&$time): float { return $time; };
$apiFor = static function (array $fixture, ?callable $backup=null) use ($request,$clock): ApiNetworkCleanup {
    return new ApiNetworkCleanup($request,$fixture['db'],$fixture['jobs'],$fixture['legacy'],$clock,$backup);
};
try {
    require $source . '/vendor/autoload.php';
    require $source . '/lib/api_network_cleanup.php';
    $responses = ['clients/services/1'=>new RuntimeException('SDK wrapper',0,cleanupHttp(404)),
        'clients/services/2'=>['id'=>2,'clientId'=>10,'status'=>1],
        'clients/services/3'=>cleanupHttp(404),'clients/services/4'=>['id'=>4,'clientId'=>40,'status'=>1],
        'clients/services/5'=>['id'=>5,'clientId'=>50,'status'=>2,'isArchived'=>true],
        'clients/services/6'=>['id'=>6,'clientId'=>60,'status'=>8],
        'clients/services/7'=>cleanupHttp(404),'clients/services/8'=>['id'=>8,'clientId'=>80,'status'=>2]];
    $fixture = cleanupFixture($root,'baseline');
    $absence = cleanupFixture($root, 'absence-status');
    cleanupSql($absence, "UPDATE network SET address=NULL,address6='' WHERE id=1;
        UPDATE services SET address='',prefix6=NULL WHERE id=1");
    $absenceApi = new ApiNetworkCleanup($request, $absence['db'], $absence['jobs'], $absence['legacy'], $clock);
    $absenceBefore = cleanupOperational($absence); $calls = [];
    $emptyPreview = $absenceApi->preview([1], true);
    $emptyState = ['address' => ['known' => true, 'present' => false], 'address6' => ['known' => true, 'present' => false]];
    checkCleanup($emptyPreview['previewToken'] === null && $emptyPreview['candidates'] === []
        && $emptyPreview['blocked'][0]['reason'] === 'no_local_assignment'
        && $emptyPreview['blocked'][0]['local_assignment_state'] === $emptyState
        && is_string($emptyPreview['blocked'][0]['checked_at']) && $emptyPreview['api_requests'] === 0 && $calls === []
        && ApiNetworkCleanup::localAssignmentState(1, $absence['db']) === $emptyState
        && ApiNetworkCleanup::localAssignmentState(999, $absence['db']) === $emptyState
        && cleanupOperational($absence) === $absenceBefore,
        'Already empty CRM cleanup offered another action, contacted CRM, or failed to expose read-only absence');
    $oldEmpty = $absenceApi->preview([1]);
    checkCleanup($oldEmpty['blocked'] === [['service_id' => 1, 'reason' => 'not_in_conflict']],
        'Empty CRM display status changed saved-conflict cleanup behavior');
    cleanupSql($absence, "UPDATE services SET address='0' WHERE id=1");
    $legacyState = ApiNetworkCleanup::localAssignmentState(1, $absence['db']);
    $legacyPreview = $absenceApi->preview([1], true);
    checkCleanup($legacyState['address'] === ['known' => true, 'present' => true]
        && $legacyState['address6'] === ['known' => true, 'present' => false]
        && $legacyPreview['blocked'][0]['reason'] === 'not_in_conflict'
        && $legacyPreview['blocked'][0]['local_assignment_state'] === $legacyState,
        'Null network reservation hid malformed but present legacy ownership');
    cleanupSql($absence, "UPDATE services SET address=NULL WHERE id=1; UPDATE network SET address6='2001:db8:1::/64' WHERE id=1");
    checkCleanup(ApiNetworkCleanup::localAssignmentState(1, $absence['db']) === [
        'address' => ['known' => true, 'present' => false], 'address6' => ['known' => true, 'present' => true]],
        'Per-family absence discarded an independently retained IPv6 reservation');
    checkCleanup(ApiNetworkCleanup::localAssignmentState(1, $absence['directory'] . '/missing.db') === [
        'address' => ['known' => false, 'present' => null], 'address6' => ['known' => false, 'present' => null]]
        && !file_exists($absence['directory'] . '/missing.db'), 'Missing database was created or reported as known clean');
    $api = $apiFor($fixture);
    $before = cleanupOperational($fixture);
    $preview = $api->preview([1,5,3]);
    checkCleanup(array_column($preview['candidates'],'service_id')===[1,3]
        && $preview['blocked']===[['service_id'=>5,'reason'=>'address_unknown']], 'Preview removed a current ended service or lost an unknown local owner');
    checkCleanup($preview['candidates'][0]['witness_service_ids']===[2]
        && $preview['candidates'][0]['address']==='192.0.2.99' && $preview['candidates'][0]['address6']==='2001:db8::/64'
        && $preview['candidates'][1]['client_id']===null, 'Preview lost complete assignments or invented an unknown client');
    checkCleanup($preview['api_requests']===5 && preg_match('/^[a-f0-9]{64}$/D',$preview['previewToken'])===1
        && $preview['expiresAt']===gmdate('Y-m-d\TH:i:s\Z',(int)$time+600), 'Preview token or bounded request accounting changed');
    checkCleanup(cleanupOperational($fixture)===$before && !is_file($fixture['jobs'])
        && !class_exists('ApiJobQueue',false), 'Preview initialized/modified queues, CRM cache or operational state');
    checkCleanup(strpos(json_encode($preview),'PRIVATE-')===false, 'Preview exposed private transport or database fields');
    rejectCleanup(fn()=>$api->preview([]),'cleanup_service_limit');
    rejectCleanup(fn()=>$api->preview([1,2,3,4]),'cleanup_service_limit');
    rejectCleanup(fn()=>$api->preview([true]),'invalid_cleanup_service_ids');
    $calls=[];
    rejectCleanup(fn()=>$api->apply($preview['previewToken'],[2]),'cleanup_selection_invalid');
    checkCleanup($calls===[] && cleanupOperational($fixture)===$before, 'An unapproved selection performed work');
    rejectCleanup(fn()=>$api->apply('../token',[1]),'invalid_cleanup_token');
    rejectCleanup(fn()=>$api->apply(str_repeat('a',64),[1]),'invalid_cleanup_token');

    // Every live service state, including ended/inactive and an archived owner,
    // must remain protected. Status is never an absence signal.
    foreach (range(0,8) as $status) {
        $responses['clients/services/1']=['id'=>1,'clientId'=>10,'status'=>$status,'isArchived'=>true];
        $result=$api->preview([1]);
        checkCleanup($result['candidates']===[] && $result['blocked']===[['service_id'=>1,'reason'=>'address_unknown']]
            && $result['previewToken']===null, 'Current service state became a cleanup candidate: ' . $status);
    }
    $unavailable=[cleanupHttp(401),cleanupHttp(403),cleanupHttp(429),cleanupHttp(500),null,false,
        ['id'=>99,'clientId'=>10],['id'=>1],new RuntimeException('HTTP 404 text is not evidence'),
        cleanupHttp(403,cleanupHttp(404)),cleanupHttp(500,cleanupHttp(404)),
        new GuzzleHttp\Exception\RequestException('No response',new GuzzleHttp\Psr7\Request('GET','https://cleanup-fixture.invalid'),null,cleanupHttp(404))];
    foreach ($unavailable as $value) {
        $responses['clients/services/1']=$value;
        $result=$api->preview([1]);
        checkCleanup($result['candidates']===[] && $result['blocked']===[['service_id'=>1,'reason'=>'crm_unavailable']],
            'Cleanup treated authorization, malformed data, connection failure or a nested old 404 as absence');
    }
    $responses['clients/services/1']=cleanupHttp(404);
    $responses['clients/services/2']=cleanupHttp(404);
    checkCleanup($api->preview([1])['blocked']===[['service_id'=>1,'reason'=>'no_live_peer']], 'Cleanup allowed a reservation without a confirmed current peer');
    $responses['clients/services/2']=['id'=>2,'clientId'=>10,'status'=>2,'isArchived'=>true];
    $preview=$api->preview([1]);
    checkCleanup(count($preview['candidates'])===1, 'A current ended peer was ignored as preservation evidence');
    $time+=600;
    rejectCleanup(fn()=>$api->apply($preview['previewToken'],[1]),'cleanup_preview_expired');
    $time-=600;

    // Any locally changed candidate or peer invalidates the reviewed snapshot.
    $changed=cleanupFixture($root,'changed'); $changedApi=$apiFor($changed);
    $plan=$changedApi->preview([1]);
    cleanupSql($changed,"UPDATE network SET address='192.0.2.200' WHERE id=1");
    $changedBefore=cleanupOperational($changed);
    rejectCleanup(fn()=>$changedApi->apply($plan['previewToken'],[1]),'cleanup_local_changed');
    checkCleanup(cleanupOperational($changed)===$changedBefore, 'Changed local assignment was overwritten');
    cleanupSql($changed,"UPDATE network SET address='192.0.2.99' WHERE id=1");
    $plan=$changedApi->preview([1]);
    cleanupSql($changed,'UPDATE services SET clientId=11 WHERE id=1');
    rejectCleanup(fn()=>$changedApi->apply($plan['previewToken'],[1]),'cleanup_local_changed');
    cleanupSql($changed,'UPDATE services SET clientId=10 WHERE id=1');
    $plan=$changedApi->preview([1]);
    cleanupSql($changed,'UPDATE services SET clientId=12 WHERE id=2');
    rejectCleanup(fn()=>$changedApi->apply($plan['previewToken'],[1]),'cleanup_local_changed');

    // Fresh CRM checks occur on apply, not just preview.
    $live=cleanupFixture($root,'reappeared'); $liveApi=$apiFor($live);
    $plan=$liveApi->preview([1]); $liveBefore=cleanupOperational($live);
    $responses['clients/services/1']=['id'=>1,'clientId'=>10,'status'=>1];
    rejectCleanup(fn()=>$liveApi->apply($plan['previewToken'],[1]),'cleanup_verification_failed');
    $responses['clients/services/1']=cleanupHttp(404);
    $responses['clients/services/2']=cleanupHttp(404);
    rejectCleanup(fn()=>$liveApi->apply($plan['previewToken'],[1]),'cleanup_verification_failed');
    checkCleanup(cleanupOperational($live)===$liveBefore && !(glob($live['directory'].'/network-cleanup-*.sqlite')?:[]),
        'Fresh CRM uncertainty changed data or created a snapshot');
    $responses['clients/services/2']=['id'=>2,'clientId'=>10,'status'=>1];

    // The exact maintenance file shared with jobs, events and setup prevents
    // overlap without ever constructing the mutating queue/import machinery.
    $queued=cleanupFixture($root,'queued'); $queuedApi=$apiFor($queued);
    $plan=$queuedApi->preview([1]);
    cleanupQueueSql($queued,"CREATE TABLE jobs(id INTEGER PRIMARY KEY,service_id INTEGER,status TEXT);
        INSERT INTO jobs VALUES(1,1,'pending')");
    rejectCleanup(fn()=>$queuedApi->apply($plan['previewToken'],[1]),'cleanup_queue_busy');
    checkCleanup($queuedApi->preview([1])['blocked']===[['service_id'=>1,'reason'=>'queue_busy']], 'Pending candidate was eligible');
    cleanupQueueSql($queued,"UPDATE jobs SET service_id=2");
    rejectCleanup(fn()=>$queuedApi->apply($plan['previewToken'],[1]),'cleanup_queue_busy');
    cleanupQueueSql($queued,"UPDATE jobs SET service_id=999,status='running'");
    rejectCleanup(fn()=>$queuedApi->apply($plan['previewToken'],[1]),'cleanup_queue_busy');
    cleanupQueueSql($queued,"DELETE FROM jobs; CREATE TABLE service_event_locks(service_id INTEGER PRIMARY KEY);
        INSERT INTO service_event_locks VALUES(999)");
    rejectCleanup(fn()=>$queuedApi->apply($plan['previewToken'],[1]),'cleanup_queue_busy');
    cleanupQueueSql($queued,"DELETE FROM service_event_locks; CREATE TABLE queue_meta(key TEXT PRIMARY KEY,value TEXT);
        INSERT INTO queue_meta VALUES('maintenance','stale-or-live')");
    rejectCleanup(fn()=>$queuedApi->apply($plan['previewToken'],[1]),'cleanup_queue_busy');
    cleanupQueueSql($queued,"DELETE FROM queue_meta; CREATE TABLE cancellation_cleanups(service_id INTEGER);
        INSERT INTO cancellation_cleanups VALUES(1)");
    rejectCleanup(fn()=>$queuedApi->apply($plan['previewToken'],[1]),'cleanup_queue_busy');
    cleanupQueueSql($queued,"DELETE FROM cancellation_cleanups; CREATE TABLE service_versions(service_id INTEGER PRIMARY KEY,applied_payload TEXT);
        INSERT INTO service_versions VALUES(1,'{\"private\":\"router ownership\"}')");
    rejectCleanup(fn()=>$queuedApi->apply($plan['previewToken'],[1]),'cleanup_queue_busy');
    cleanupQueueSql($queued,"ALTER TABLE service_versions ADD COLUMN applied_identity TEXT; UPDATE service_versions SET applied_payload=NULL,applied_identity='retained-owner'");
    rejectCleanup(fn()=>$queuedApi->apply($plan['previewToken'],[1]),'cleanup_queue_busy');
    cleanupQueueSql($queued,"DROP TABLE service_versions; CREATE TABLE service_versions(service_id INTEGER PRIMARY KEY); INSERT INTO service_versions VALUES(1)");
    rejectCleanup(fn()=>$queuedApi->apply($plan['previewToken'],[1]),'cleanup_queue_busy');
    cleanupQueueSql($queued,'DELETE FROM service_versions');
    file_put_contents($queued['legacy'],'{"fixture":{"id":1,"action":"update"}}');
    rejectCleanup(fn()=>$queuedApi->apply($plan['previewToken'],[1]),'cleanup_queue_busy');
    file_put_contents($queued['legacy'],'{}');
    $identity=realpath($queued['jobs']);
    if (PHP_OS_FAMILY==='Windows') $identity=strtolower($identity);
    $lockDirectory=dirname($identity).DIRECTORY_SEPARATOR.'.uisp-queue-locks-'.hash('sha256',$identity);
    $lock=fopen($lockDirectory.'/maintenance.lock','c');
    checkCleanup(flock($lock,LOCK_SH|LOCK_NB),'Fixture could not hold its maintenance guard');
    try { rejectCleanup(fn()=>$queuedApi->apply($plan['previewToken'],[1]),'cleanup_queue_busy'); }
    finally { flock($lock,LOCK_UN); fclose($lock); }
    cleanupQueueSql($queued,"INSERT INTO jobs VALUES(2,1,'cleaned')");
    rejectCleanup(fn()=>$queuedApi->apply($plan['previewToken'],[1]),'cleanup_queue_busy');
    cleanupQueueSql($queued,"UPDATE jobs SET service_id=999 WHERE id=2; INSERT INTO jobs VALUES(3,999,'succeeded')");
    $queueBefore=hash_file('sha256',$queued['jobs']);
    $queuedApi->apply($plan['previewToken'],[1]);
    checkCleanup(hash_file('sha256',$queued['jobs'])===$queueBefore, 'Applying cleanup changed/imported/recovered the job queue');
    $unrelatedQueue=cleanupFixture($root,'unrelated-queue'); $unrelatedQueueApi=$apiFor($unrelatedQueue);
    $plan=$unrelatedQueueApi->preview([1]);
    cleanupQueueSql($unrelatedQueue,'CREATE TABLE unrelated(id INTEGER PRIMARY KEY)');
    rejectCleanup(fn()=>$unrelatedQueueApi->apply($plan['previewToken'],[1]),'cleanup_queue_busy');

    // Backup failure aborts before the first local change. A subsequent SQL
    // constraint failure rolls back all deletes and retains the private backup.
    $failed=cleanupFixture($root,'backup-failed');
    $failedApi=$apiFor($failed,static function (): bool { return false; });
    $plan=$failedApi->preview([1]); $failedBefore=cleanupOperational($failed);
    rejectCleanup(fn()=>$failedApi->apply($plan['previewToken'],[1]),'cleanup_backup_failed');
    checkCleanup(cleanupOperational($failed)===$failedBefore && !(glob($failed['directory'].'/network-cleanup-*.sqlite')?:[]),
        'Backup failure changed data or published an incomplete backup');
    $rollback=cleanupFixture($root,'rollback',true); $rollbackApi=$apiFor($rollback);
    $plan=$rollbackApi->preview([1]); $rollbackBefore=cleanupOperational($rollback);
    rejectCleanup(fn()=>$rollbackApi->apply($plan['previewToken'],[1]),'cleanup_failed');
    checkCleanup(cleanupOperational($rollback)===$rollbackBefore && count(ApiNetworkCleanup::listBackups($rollback['directory']))===1,
        'SQL failure retained a partial removal or discarded its recovery snapshot');
    $backupRows=ApiNetworkCleanup::listBackups($rollback['directory']);
    checkCleanup(cleanupTables($rollback['directory'].'/'.$backupRows[0]['name'])===$rollbackBefore['data'],
        'Rollback recovery snapshot differs from the complete pre-cleanup database');

    // Apply a bounded subset; preserve current services, all metadata, other
    // assignment families and unrelated duplicate groups. Unknown owners work
    // only with the same exact 404 plus a separately verified current peer.
    $successful=cleanupFixture($root,'successful'); $successfulApi=$apiFor($successful);
    $successBefore=cleanupOperational($successful); $plan=$successfulApi->preview([1,3,7]);
    $calls=[];
    $applied=$successfulApi->apply($plan['previewToken'],[1,3]);
    checkCleanup($applied['removed_service_ids']===[1,3] && $applied['requires_reload']===true
        && ApiNetworkCleanup::backupName($applied['backup_name']) && $applied['duplicate_groups']===3,
        'Apply result lost removed selections, remaining unrelated conflicts or the reload barrier');
    checkCleanup($calls===['clients/services/1','clients/services/2','clients/services/3','clients/services/4'],
        'Apply did not freshly recheck candidates and exact independently confirmed peers');
    $after=cleanupOperational($successful);
    $expected=$successBefore['data'];
    $expected['network']=array_values(array_filter($expected['network'],static function(array $row):bool {return !in_array($row['id'],[1,3],true);}));
    $expected['services'][0]['address']=null; $expected['services'][0]['prefix6']=null;
    checkCleanup($after['data']===$expected && $after['files']===$successBefore['files'],
        'Cleanup affected a current/unselected service, router/config/plan table, cache, queue or original rotated backup');
    checkCleanup(cleanupTables($successful['directory'].'/'.$applied['backup_name'])===$successBefore['data'],
        'Published snapshot did not retain all pre-cleanup tables and private metadata');
    rejectCleanup(fn()=>$successfulApi->apply($plan['previewToken'],[7]),'invalid_cleanup_token');
    $legacyPlan=$successfulApi->preview([7]);
    $successfulApi->apply($legacyPlan['previewToken'],[7]);
    $afterLegacy=cleanupTables($successful['db']);
    checkCleanup($afterLegacy['services'][5]['id']===7 && $afterLegacy['services'][5]['address']===null
        && $afterLegacy['services'][5]['prefix6']===null && $afterLegacy['services'][6]['id']===8
        && $afterLegacy['services'][6]['address']==='192.0.2.102', 'Legacy-only cleanup removed a service row or preserved its reimportable assignment');
    // The projection used by migration cannot bring cleared legacy data back.
    cleanupSql($successful,"INSERT OR IGNORE INTO network(id,address,address6) SELECT id,address,prefix6 FROM services");
    $projected=cleanupTables($successful['db']);
    foreach ($projected['network'] as $row) {
        if (in_array($row['id'],[1,7],true)) checkCleanup($row['address']===null && $row['address6']===null, 'Migration reimported a removed legacy address');
    }

    // A full CRM response may prove that one configured address was removed
    // or changed while the service itself still exists. Reconcile only that
    // family, only with a current peer owning the old address, and leave all
    // service data, other families and applied router ownership intact.
    $oldResponses=$responses;
    $catalog=[['id'=>100,'key'=>'fixtureAddress','attributeType'=>'service'],
        ['id'=>101,'key'=>'fixturePrefix','attributeType'=>'service']];
    $serviceWith=static function (int $id,int $clientId,?string $address,?string $address6): array {
        return ['id'=>$id,'clientId'=>$clientId,'status'=>1,'attributes'=>[
            ['key'=>'fixtureAddress','customAttributeId'=>100,'value'=>$address],
            ['key'=>'fixturePrefix','customAttributeId'=>101,'value'=>$address6]]];
    };
    $configured=static function (string $name) use ($root): array {
        $fixture=cleanupFixture($root,$name);
        cleanupSql($fixture,"INSERT INTO config VALUES('ip_addr_attr','fixtureAddress'),('ip_addr6_attr','fixturePrefix')");
        return $fixture;
    };
    try {
        $responses['custom-attributes']=$catalog;
        $responses['clients/services/1']=$serviceWith(1,10,'','2001:db8::/64');
        $responses['clients/services/2']=$serviceWith(2,10,'192.0.2.99/32','2001:0DB8:0000::/64');
        $removed=$configured('crm-removed-ipv4'); $removedApi=$apiFor($removed);
        $removedBefore=cleanupOperational($removed); $calls=[];
        $plan=$removedApi->preview([1]);
        checkCleanup($plan['blocked']===[] && count($plan['candidates'])===1 && $plan['api_requests']===3
            && $calls===['clients/services/1','custom-attributes','clients/services/2'],
            'Present-service preview did not bound and separately validate attribute definitions and current peer');
        checkCleanup($plan['candidates'][0]['reason']==='stale_address' && $plan['candidates'][0]['fields']===['address']
            && $plan['candidates'][0]['evidence']===[['field'=>'address','saved_address'=>'192.0.2.99','current_address'=>null,'state'=>'removed']]
            && $plan['candidates'][0]['witness_service_ids']===[2] && cleanupOperational($removed)===$removedBefore,
            'IPv4 reconciliation preview lost exact evidence or modified operational state');
        $calls=[]; $applied=$removedApi->apply($plan['previewToken'],[1]);
        $removedExpected=$removedBefore['data'];
        $removedExpected['network'][0]['address']=null; $removedExpected['services'][0]['address']=null;
        checkCleanup(cleanupOperational($removed)===['data'=>$removedExpected,'files'=>$removedBefore['files']]
            && $calls===['clients/services/1','custom-attributes','clients/services/2']
            && $applied['cleared_assignments']===[['service_id'=>1,'fields'=>['address'],'reason'=>'stale_address']]
            && $applied['duplicate_groups']===5,
            'IPv4 reconciliation deleted the row, changed IPv6/metadata/queue/cache, or skipped fresh proof');
        checkCleanup(cleanupTables($removed['directory'].'/'.$applied['backup_name'])===$removedBefore['data'],
            'Reconciliation backup did not capture complete original service and network data');

        $changedFamily=$configured('crm-changed-ipv6'); $changedFamilyApi=$apiFor($changedFamily);
        $responses['clients/services/1']=$serviceWith(1,10,'192.0.2.99','2001:db8:9:1234::1/64');
        $plan=$changedFamilyApi->preview([1]); $changedFamilyBefore=cleanupOperational($changedFamily);
        checkCleanup($plan['candidates'][0]['fields']===['address6'] && $plan['candidates'][0]['evidence'][0]
            ===['field'=>'address6','saved_address'=>'2001:db8::/64','current_address'=>'2001:db8:9:1234::/64','state'=>'changed'],
            'IPv6 change did not retain canonical new prefix as reviewed evidence');
        $changedFamilyApi->apply($plan['previewToken'],[1]);
        $changedFamilyExpected=$changedFamilyBefore['data'];
        $changedFamilyExpected['network'][0]['address6']=null; $changedFamilyExpected['services'][0]['prefix6']=null;
        checkCleanup(cleanupOperational($changedFamily)===['data'=>$changedFamilyExpected,'files'=>$changedFamilyBefore['files']],
            'IPv6 reconciliation changed IPv4 or assigned an unprovisioned new CRM prefix');

        $legacyStale=$configured('crm-removed-legacy'); $legacyStaleApi=$apiFor($legacyStale);
        $responses['clients/services/7']=$serviceWith(7,70,null,'2001:db8:7::/64');
        $responses['clients/services/8']=$serviceWith(8,80,'192.0.2.102','2001:db8:7::/64');
        $plan=$legacyStaleApi->preview([7]); $legacyBefore=cleanupOperational($legacyStale);
        $legacyStaleApi->apply($plan['previewToken'],[7]);
        $legacyExpected=$legacyBefore['data']; $legacyExpected['services'][5]['address']=null;
        checkCleanup(cleanupOperational($legacyStale)===['data'=>$legacyExpected,'files'=>$legacyBefore['files']],
            'Legacy reconciliation created a network row or cleared a preserved IPv6/service field');

        $proof=$configured('crm-proof'); $proofApi=$apiFor($proof);
        $responses['clients/services/1']=$serviceWith(1,10,null,'2001:db8::/64');
        $responses['clients/services/2']=$serviceWith(2,10,'192.0.2.99','2001:db8::/64');
        $proofBefore=cleanupOperational($proof);
        $unknownCatalog=[null,[],cleanupHttp(403),[['id'=>100,'key'=>'fixtureAddress','attributeType'=>'client']],
            [$catalog[0],$catalog[0],$catalog[1]],
            [$catalog[0],['id'=>100,'key'=>'fixturePrefix','attributeType'=>'service']],
            [['id'=>100,'key'=>'renamedAddress','attributeType'=>'service'],$catalog[1]]];
        foreach ($unknownCatalog as $value) {
            $responses['custom-attributes']=$value;
            $result=$proofApi->preview([1]);
            checkCleanup($result['candidates']===[] && $result['blocked']===[['service_id'=>1,'reason'=>'address_unknown']]
                && $result['previewToken']===null && cleanupOperational($proof)===$proofBefore,
                'Missing, renamed, duplicate, wrong-type or unauthorized catalog defined an empty CRM address');
        }
        $responses['custom-attributes']=$catalog;
        $malformedAttributes=[null,false,(object)[],['not-a-list'=>['key'=>'fixtureAddress','value'=>null]],
            [['key'=>'fixtureAddress']], [['key'=>'fixtureAddress','value'=>[]]],
            [['key'=>'fixtureAddress','customAttributeId'=>999,'value'=>null]],
            [['key'=>'fixtureAddress','value'=>'not-an-IP']],
            [['key'=>'fixtureAddress','value'=>null],['key'=>'fixtureAddress','value'=>null]],
            [['key'=>'fixtureAddress','value'=>null],false]];
        foreach ($malformedAttributes as $attributes) {
            // Keep the independent IPv6 family unchanged when only the IPv4
            // attribute is malformed; a genuinely empty IPv6 key is valid
            // evidence for that family in a complete response.
            if (is_array($attributes) && (!$attributes || array_keys($attributes)===range(0,count($attributes)-1))) {
                $attributes[]=['key'=>'fixturePrefix','customAttributeId'=>101,'value'=>'2001:db8::/64'];
            }
            $responses['clients/services/1']=['id'=>1,'clientId'=>10,'attributes'=>$attributes];
            $result=$proofApi->preview([1]);
            checkCleanup($result['candidates']===[] && $result['blocked']===[['service_id'=>1,'reason'=>'address_unknown']],
                'Malformed/ambiguous CRM attributes were treated as authoritative address removal');
        }
        $responses['clients/services/1']=['id'=>1,'clientId'=>10];
        checkCleanup($proofApi->preview([1])['blocked']===[['service_id'=>1,'reason'=>'address_unknown']],
            'Omitted attributes in a partial response were treated as removal');
        $responses['clients/services/1']=$serviceWith(1,10,'192.0.2.99','2001:db8::/64');
        checkCleanup($proofApi->preview([1])['blocked']===[['service_id'=>1,'reason'=>'service_exists']],
            'An unchanged live IP was offered for reconciliation');
        $responses['clients/services/1']=$serviceWith(1,11,null,'2001:db8::/64');
        checkCleanup($proofApi->preview([1])['blocked']===[['service_id'=>1,'reason'=>'identity_changed']],
            'A reassigned CRM service was reconciled under its former local client identity');
        $responses['clients/services/1']=$serviceWith(1,10,null,'2001:db8::/64');
        foreach ([$serviceWith(2,10,null,'2001:db8::/64'),$serviceWith(2,10,'192.0.2.200','2001:db8::/64'),
            ['id'=>2,'clientId'=>10],$serviceWith(2,11,'192.0.2.99','2001:db8::/64')] as $peer) {
            $responses['clients/services/2']=$peer;
            checkCleanup($proofApi->preview([1])['blocked']===[['service_id'=>1,'reason'=>'no_live_peer']],
                'A merely existing or reassigned peer was accepted without current ownership of the old address');
        }
        $responses['clients/services/2']=$serviceWith(2,10,'192.0.2.99','2001:db8::/64');
        $plan=$proofApi->preview([1]);
        $responses['clients/services/1']=$serviceWith(1,10,'192.0.2.201','2001:db8::/64');
        rejectCleanup(fn()=>$proofApi->apply($plan['previewToken'],[1]),'cleanup_verification_failed');
        $responses['clients/services/1']=$serviceWith(1,10,null,'2001:db8::/64');
        $responses['clients/services/2']=$serviceWith(2,10,null,'2001:db8::/64');
        rejectCleanup(fn()=>$proofApi->apply($plan['previewToken'],[1]),'cleanup_verification_failed');
        $responses['clients/services/2']=$serviceWith(2,10,'192.0.2.99','2001:db8::/64');
        $responses['custom-attributes']=[['id'=>102,'key'=>'fixtureAddress','attributeType'=>'service'],$catalog[1]];
        rejectCleanup(fn()=>$proofApi->apply($plan['previewToken'],[1]),'cleanup_verification_failed');
        $responses['custom-attributes']=$catalog;
        $responses['clients/services/1']=cleanupHttp(404);
        rejectCleanup(fn()=>$proofApi->apply($plan['previewToken'],[1]),'cleanup_verification_failed');
        $deletedPlan=$proofApi->preview([1]);
        $responses['clients/services/1']=$serviceWith(1,10,null,'2001:db8::/64');
        rejectCleanup(fn()=>$proofApi->apply($deletedPlan['previewToken'],[1]),'cleanup_verification_failed');
        checkCleanup(cleanupOperational($proof)===$proofBefore && !(glob($proof['directory'].'/network-cleanup-*.sqlite')?:[]),
            'Changed CRM evidence/catalog/witness or repair mode wrote data or a misleading backup');
        $plan=$proofApi->preview([1]);
        cleanupSql($proof,"UPDATE config SET value='renamedAddress' WHERE key='ip_addr_attr'");
        $calls=[];
        rejectCleanup(fn()=>$proofApi->apply($plan['previewToken'],[1]),'cleanup_local_changed');
        checkCleanup($calls===[], 'Changed configured address mapping was not rejected before CRM work');

        $owned=$configured('crm-applied-owner'); $ownedApi=$apiFor($owned); $plan=$ownedApi->preview([1]);
        cleanupQueueSql($owned,"CREATE TABLE jobs(id INTEGER PRIMARY KEY,service_id INTEGER,status TEXT);
            CREATE TABLE service_versions(service_id INTEGER PRIMARY KEY,applied_payload TEXT,applied_identity TEXT);
            INSERT INTO service_versions VALUES(1,'{\"network\":{\"address\":\"192.0.2.99\"}}','fixture-applied-owner')");
        $ownedBefore=cleanupOperational($owned);
        rejectCleanup(fn()=>$ownedApi->apply($plan['previewToken'],[1]),'cleanup_queue_busy');
        checkCleanup($ownedApi->preview([1])['blocked']===[['service_id'=>1,'reason'=>'queue_busy']]
            && cleanupOperational($owned)===$ownedBefore,
            'CRM IP removal released a reservation still recorded as applied router ownership');

        $shared=$configured('crm-shared-catalog'); $sharedApi=$apiFor($shared);
        $responses['clients/services/5']=$serviceWith(5,50,null,'2001:db8:5::/64');
        $responses['clients/services/6']=$serviceWith(6,60,'192.0.2.101','2001:db8:6::/64');
        $calls=[]; $result=$sharedApi->preview([1,5]);
        checkCleanup(count($result['candidates'])===2 && $result['api_requests']===5
            && count(array_filter($calls,static fn(string $path):bool=>$path==='custom-attributes'))===1,
            'A bounded selection repeated its attribute-catalog lookup per service');
        $catalogTime=$time; $catalogCalls=[];
        $slowCatalog=new ApiNetworkCleanup(static function(string $path) use (&$catalogTime,&$catalogCalls,$responses) {
            $catalogCalls[]=$path;
            if ($path==='custom-attributes') $catalogTime+=13;
            return $responses[$path];
        },$shared['db'],$shared['jobs'],$shared['legacy'],static function() use (&$catalogTime):float {return $catalogTime;});
        checkCleanup($slowCatalog->preview([1])['blocked']===[['service_id'=>1,'reason'=>'deadline']]
            && $catalogCalls===['clients/services/1','custom-attributes'],
            'Cleanup exceeded its deadline to confirm a stale-address witness after a slow catalog read');
    } finally { $responses=$oldResponses; }

    // WAL pages are included while an existing connection keeps them separate
    // from data.db; a whole-file copy would lose these committed records.
    $wal=cleanupFixture($root,'wal'); $walApi=$apiFor($wal);
    $walWriter=new SQLite3($wal['db']); $walWriter->enableExceptions(true);
    $walWriter->exec('PRAGMA journal_mode=WAL; PRAGMA wal_autocheckpoint=0');
    $walWriter->exec("UPDATE config SET value='wal-fixture-value' WHERE key='backup_cursor'");
    try {
        $walBefore=cleanupTables($wal['db']); $plan=$walApi->preview([1]); $result=$walApi->apply($plan['previewToken'],[1]);
        checkCleanup(cleanupTables($wal['directory'].'/'.$result['backup_name'])===$walBefore, 'Recovery backup omitted committed WAL pages');
    } finally { $walWriter->close(); }

    // Bounded request budgets fail closed rather than guessing a peer.
    $budget=cleanupFixture($root,'budget');
    $budgetTime=1800000000.0; $budgetCalls=0;
    $slow=new ApiNetworkCleanup(static function () use (&$budgetTime,&$budgetCalls) {
        $budgetTime+=13; $budgetCalls++; throw cleanupHttp(404);
    },$budget['db'],$budget['jobs'],$budget['legacy'],static function () use (&$budgetTime) {return $budgetTime;});
    checkCleanup($slow->preview([1])['blocked']===[['service_id'=>1,'reason'=>'deadline']] && $budgetCalls===1,
        'Cleanup launched another request after its deadline');
    cleanupSql($budget,"DELETE FROM network; DELETE FROM services; INSERT INTO network VALUES(1,'192.0.2.9',NULL,NULL)");
    for ($id=2;$id<=14;$id++) cleanupSql($budget,"INSERT INTO network VALUES($id,'192.0.2.9',NULL,NULL)");
    $manyCalls=0;
    $bounded=new ApiNetworkCleanup(static function () use (&$manyCalls) {$manyCalls++; throw cleanupHttp(404);},
        $budget['db'],$budget['jobs'],$budget['legacy'],$clock);
    checkCleanup($bounded->preview([1])['blocked']===[['service_id'=>1,'reason'=>'deadline']] && $manyCalls===9,
        'Cleanup exceeded its maximum CRM request count');

    $unsupported=cleanupFixture($root,'unsupported');
    cleanupSql($unsupported,'CREATE TRIGGER fixture_trigger AFTER DELETE ON network BEGIN DELETE FROM services WHERE id=2; END');
    rejectCleanup(fn()=>$apiFor($unsupported)->preview([1]),'cleanup_schema_unsupported');
    checkCleanup(!ApiNetworkCleanup::backupName('../network-cleanup-20270115T080000Z-'.str_repeat('a',32).'.sqlite')
        && !ApiNetworkCleanup::backupName('backup-4'), 'Cleanup snapshot accepted an unrelated or traversing filename');
    $invalid=$root.'/network-cleanup-20270115T080000Z-'.str_repeat('a',32).'.sqlite';
    $db=new SQLite3($invalid); $db->exec('CREATE TABLE unrelated(id INTEGER PRIMARY KEY)'); $db->close();
    rejectCleanup(fn()=>ApiNetworkCleanup::download(basename($invalid),$root),'backup_schema_invalid');
    checkCleanup(ApiNetworkCleanup::listBackups($root)===[], 'Unrelated SQLite file appeared as a recovery snapshot');

    echo "Network cleanup checks passed: exact typed HTTP 404 deletion, explicit per-family stale CRM-address reconciliation, validated service attribute catalog and identity, current canonical-address witnesses, fail-closed/fresh evidence and mapping checks, preserved applied router ownership, private expiring reviews, maintenance/queue barriers, complete WAL backups, rollback failures and legacy reimport prevention.\n";
} finally {
    $resolved=realpath($root); $temporaryRoot=realpath(sys_get_temp_dir());
    if ($resolved && $temporaryRoot && strpos($resolved,$temporaryRoot.DIRECTORY_SEPARATOR.'uisp-network-cleanup-')===0) {
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) $entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());
        rmdir($resolved);
    }
}
