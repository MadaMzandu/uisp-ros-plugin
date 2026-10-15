<?php
// Synthetic local ownership records and read-only UISP fixture; no RouterOS or external network.
require_once __DIR__ . '/../src/lib/api_job_recovery.php';
require_once __DIR__ . '/../src/lib/api_jobs_status.php';
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line); return false;
});
function jobRecoveryCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function jobRecoveryCanonical(array $value): string {
    ksort($value); foreach ($value as $key => $entry) if (is_array($entry)) $value[$key] = json_decode(jobRecoveryCanonical($entry), true);
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
function jobRecoveryIdentity(array $snapshot): string {
    $device = $snapshot['_device'];
    return hash('sha256', jobRecoveryCanonical(['device'=>$snapshot['device'],'ip'=>$device['ip'],'port'=>$device['port']??null,
        'username'=>$snapshot['username']??null,'mac'=>$snapshot['mac']??null,'hotspot'=>$snapshot['hotspot']??null,
        'duid'=>$snapshot['duid']??null,'iaid'=>$snapshot['iaid']??null]));
}
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-job-recovery-' . bin2hex(random_bytes(8)); mkdir($directory,0700);
$jobs = $directory . '/jobs.db'; $data = $directory . '/data.db'; $legacy = $directory . '/queue.json';
$database = null; $now = 1800000000; $calls = []; $mode = null;
$service = ['id'=>701,'clientId'=>81,'status'=>1,'servicePlanId'=>5,'attributes'=>[]];
$client = ['id'=>81,'firstName'=>'Synthetic','lastName'=>'Customer','isArchived'=>false];
$request = function (string $path) use (&$calls,&$mode,&$service,&$client) {
    $calls[]=$path; if ($mode==='offline') throw new RuntimeException('NO_NETWORK_PRIVATE_CREDENTIAL');
    if ($path==='clients/services/701') return $service;
    if ($path==='clients/81') return $client;
    throw new RuntimeException('Unexpected fixture request: ' . $path);
};
$api = new ApiJobRecovery($request,$jobs,$data,$legacy,static function () use (&$now) { return $now; });
$snapshot = ['id'=>701,'clientId'=>81,'device'=>7,'status'=>1,'planId'=>5,'username'=>'SYNTHETIC_PRIVATE_ACCOUNT',
    'password'=>'PRIVATE_ACCOUNT_PASSWORD','address'=>'10.77.0.11','firstName'=>'Synthetic','lastName'=>'Customer',
    '_device'=>['id'=>7,'ip'=>'192.0.2.7','port'=>8728,'user'=>'SYNTHETIC_PRIVATE_ROUTER_USER','password'=>'PRIVATE_ROUTER_PASSWORD','ssl'=>0],
    '_config'=>['disable_contention'=>true], '_plan'=>['id'=>5,'name'=>'Configured rate','uploadSpeed'=>11,'downloadSpeed'=>33],
    '_commands'=>[['path'=>'/ppp/profile','name'=>'fixture-profile','rate-limit'=>'11M/33M','comment'=>'managed fixture'],
        ['path'=>'/ppp/secret','name'=>'SYNTHETIC_PRIVATE_ACCOUNT','password'=>'PRIVATE_ACCOUNT_PASSWORD',
            'remote-address'=>'10.77.0.11','profile'=>'fixture-profile','comment'=>'fixture service 701']]];
$safeSnapshot=$snapshot; unset($safeSnapshot['password'],$safeSnapshot['_commands'][1]['password']);
$identity=jobRecoveryIdentity($snapshot);
$insert = function (string $table,array $values) use (&$database) {
    $statement=$database->prepare('INSERT INTO ' . $table . '(' . implode(',',array_keys($values)) . ') VALUES(' . implode(',',array_fill(0,count($values),'?')) . ')');
    foreach(array_values($values) as $index=>$value) $statement->bindValue($index+1,$value,is_int($value)?SQLITE3_INTEGER:($value===null?SQLITE3_NULL:SQLITE3_TEXT));
    $result=$statement->execute(); $result->finalize(); $statement->close();
};
$seed = function () use (&$database,$insert,$snapshot,$safeSnapshot,$identity) {
    $database->exec('DELETE FROM jobs; DELETE FROM cancellation_cleanups; DELETE FROM service_versions; DELETE FROM queue_meta; DELETE FROM service_event_locks');
    if ($database->querySingle("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='retained_resources'")) $database->exec('DELETE FROM retained_resources');
    $insert('jobs',['id'=>17,'service_id'=>701,'status'=>'cancelled','version'=>3,'payload'=>json_encode($snapshot),'updated'=>'2026-10-14T00:00:00Z','error'=>null]);
    $insert('jobs',['id'=>18,'service_id'=>702,'status'=>'pending','version'=>1,'payload'=>'{"id":702,"password":"OTHER_PRIVATE"}','updated'=>'2026-10-14T00:00:00Z','error'=>'fixture failure']);
    $insert('service_versions',['service_id'=>701,'version'=>3,'applied_identity'=>$identity,'applied_payload'=>json_encode($snapshot)]);
    $insert('cancellation_cleanups',['id'=>31,'source_job_id'=>17,'service_id'=>701,'source_version'=>3,'slot'=>'applied',
        'payload'=>json_encode($safeSnapshot),'identity'=>$identity]);
    $insert('queue_meta',['key'=>'cancellation_cleanups_seeded_v1','value'=>'fixture initialized']);
};
$table = function(string $name) use (&$database):array {
    $result=$database->query('SELECT * FROM ' . $name . ' ORDER BY 1'); $rows=[];
    while($row=$result->fetchArray(SQLITE3_ASSOC)) $rows[]=$row; $result->finalize(); return $rows;
};
$blocked = function(string $reason) use ($api):void {
    $result=$api->preview(17);
    jobRecoveryCheck($result['previewToken']===null && $result['candidate']===null
        && ($result['blocked'][0]['reason']??null)===$reason,'Expected blocker ' . $reason . ': ' . json_encode($result));
};
try {
    $result=$api->listing(); jobRecoveryCheck($result['available'] && $result['total']===0 && !file_exists($jobs) && $calls===[],'Empty read initialized queue or contacted UISP');
    $database=new SQLite3($jobs); $database->enableExceptions(true);
    $database->exec('CREATE TABLE jobs(id INTEGER PRIMARY KEY,service_id INTEGER,status TEXT,version INTEGER,payload TEXT,updated TEXT,error TEXT);
        CREATE TABLE cancellation_cleanups(id INTEGER PRIMARY KEY,source_job_id INTEGER,service_id INTEGER,source_version INTEGER,slot TEXT,payload TEXT,identity TEXT);
        CREATE TABLE service_versions(service_id INTEGER PRIMARY KEY,version INTEGER,applied_identity TEXT,applied_payload TEXT);
        CREATE TABLE service_event_locks(service_id INTEGER PRIMARY KEY);
        CREATE TABLE queue_meta(key TEXT PRIMARY KEY,value TEXT);');
    $seed(); $before=hash_file('sha256',$jobs); $result=$api->listing();
    jobRecoveryCheck($result['available'] && $result['total']===1 && $result['groups'][0]['service_id']===701
        && $result['groups'][0]['client_id']===81 && $result['groups'][0]['job_id']==='Q17'
        && $result['groups'][0]['device_ids']===[7] && $result['groups'][0]['snapshot_count']===1,'Read-only list lost source metadata');
    jobRecoveryCheck(hash_file('sha256',$jobs)===$before && $calls===[],'Listing changed queue or contacted UISP');
    $text=json_encode($result); foreach(['PRIVATE','_commands','username','password','rate-limit'] as $secret) jobRecoveryCheck(strpos($text,$secret)===false,'List leaked private snapshot');
    $preview=$api->preview(17); jobRecoveryCheck(is_string($preview['previewToken']) && $preview['candidate']['crm_status']===1
        && $preview['candidate']['client_archived']===false && $calls===['clients/services/701','clients/81'],'Equivalent resource debt was not reviewed using fresh UISP');
    $text=json_encode($preview); foreach(['PRIVATE','_commands','username','password','rate-limit'] as $secret) jobRecoveryCheck(strpos($text,$secret)===false,'Preview leaked private snapshot');
    jobRecoveryCheck(hash_file('sha256',$jobs)===$before,'Preview mutated queue database');
    $unchanged=[]; foreach(['jobs','service_versions','queue_meta','service_event_locks'] as $name) $unchanged[$name]=$table($name);
    $applied=$api->apply($preview['previewToken']);
    jobRecoveryCheck($applied['resolved']===[['source_job_id'=>17,'service_id'=>701,'snapshot_count'=>1]]
        && $applied['router_changes']===false && $applied['crm_changes']===false && $table('cancellation_cleanups')===[],'Apply failed to consolidate duplicate recovery');
    foreach($unchanged as $name=>$rows) jobRecoveryCheck($table($name)===$rows,'Apply changed protected ' . $name);
    $summary=(new ApiJobsStatus($jobs,$legacy))->read(); jobRecoveryCheck($summary['recovery']===0 && $summary['pending']===1,'Resolved recovery count did not refresh independently');
    jobRecoveryCheck(ApiJobRecovery::backupName($applied['backup_name']) && !ApiJobRecovery::backupName('../'.$applied['backup_name'])
        && count(ApiJobRecovery::listBackups($directory))===1,'Recovery backup naming/listing failed');
    $backupDatabase=new SQLite3($directory . '/' . $applied['backup_name'],SQLITE3_OPEN_READONLY);
    jobRecoveryCheck((int)$backupDatabase->querySingle('SELECT count(*) FROM cancellation_cleanups')===1,'Backup omitted pre-change ownership'); $backupDatabase->close();
    try {$api->apply($preview['previewToken']); throw new RuntimeException('Consumed token reused');}
    catch(InvalidArgumentException $expected) {jobRecoveryCheck($expected->getMessage()==='invalid_recovery_token','Unexpected replay result');}

    $seed(); $database->exec("UPDATE cancellation_cleanups SET payload=replace(payload,'11M','99M')"); $blocked('different_resources');
    $seed(); $database->exec("UPDATE cancellation_cleanups SET payload=replace(payload,'10.77.0.11','10.77.0.12')"); $blocked('different_resources');
    $seed(); $database->exec("UPDATE cancellation_cleanups SET payload=replace(payload,'192.0.2.7','192.0.2.8')"); $blocked('different_resources');
    $seed(); $database->exec('UPDATE service_versions SET applied_payload=NULL'); $blocked('current_ownership_unknown');
    $seed(); $database->exec("DELETE FROM queue_meta WHERE key='cancellation_cleanups_seeded_v1'"); $blocked('source_state_changed');
    $seed(); $database->exec("UPDATE jobs SET status='running' WHERE id=18"); $blocked('queue_busy');
    $seed(); $insert('service_event_locks',['service_id'=>999]); $blocked('queue_busy');
    $seed(); $insert('queue_meta',['key'=>'maintenance','value'=>'active fixture']); $blocked('queue_busy');
    $seed(); $insert('jobs',['id'=>19,'service_id'=>701,'status'=>'pending','version'=>4,'payload'=>'{}']); $blocked('queue_busy');
    $seed(); $database->exec("UPDATE jobs SET status='cleaned' WHERE id=17"); $blocked('source_state_changed');
    $seed(); $service['status']=3; $blocked('crm_status_changed'); $service['status']=1;
    $seed(); $service['clientId']=82; $blocked('crm_identity_changed'); $service['clientId']=81;
    $seed(); $mode='offline'; $blocked('crm_unavailable'); $mode=null;
    $seed(); file_put_contents($legacy,'[{"id":701,"password":"LEGACY_PRIVATE"}]'); $blocked('legacy_queue_present'); unlink($legacy);
    $seed(); $partial=$safeSnapshot; $partial['_prior_network']=['address'=>'10.77.0.90'];
    $statement=$database->prepare('UPDATE cancellation_cleanups SET payload=?'); $statement->bindValue(1,json_encode($partial)); $statement->execute()->finalize(); $statement->close(); $blocked('different_resources');
    // Even identical applied/debt copies cannot release an old prior address:
    // ordinary applied state reserves current addresses, not prior-network data.
    $seed();$partial=$snapshot;$partial['_prior_network']=['address'=>'10.77.0.90'];
    foreach(['UPDATE service_versions SET applied_payload=?','UPDATE jobs SET payload=? WHERE id=17','UPDATE cancellation_cleanups SET payload=?'] as $sql) {
        $statement=$database->prepare($sql);$statement->bindValue(1,json_encode($partial));$statement->execute()->finalize();$statement->close();
    }
    $blocked('different_resources');
    // A held previous address is not proven current merely by repeating it in applied/debt/source metadata.
    foreach (['source','debt','all'] as $location) {
        $seed(); $partial=$snapshot; $partial['_previous_addresses']=['10.77.0.90','2001:db8:90::/64'];
        $statements=$location==='source'?['UPDATE jobs SET payload=? WHERE id=17']:
            ($location==='debt'?['UPDATE cancellation_cleanups SET payload=?']:
                ['UPDATE service_versions SET applied_payload=?','UPDATE jobs SET payload=? WHERE id=17','UPDATE cancellation_cleanups SET payload=?']);
        foreach($statements as $sql) {
            $statement=$database->prepare($sql); $statement->bindValue(1,json_encode($partial)); $statement->execute()->finalize(); $statement->close();
        }
        $blocked('different_resources');
    }
    $seed(); $partial=$snapshot; $partial['_previous_addresses']=['10.77.0.11'];
    foreach(['UPDATE service_versions SET applied_payload=?','UPDATE jobs SET payload=? WHERE id=17','UPDATE cancellation_cleanups SET payload=?'] as $sql) {
        $statement=$database->prepare($sql); $statement->bindValue(1,json_encode($partial)); $statement->execute()->finalize(); $statement->close();
    }
    jobRecoveryCheck(is_string($api->preview(17)['previewToken']), 'A previous hold covered by the actual current account prevented duplicate consolidation');
    $seed(); $partial=$safeSnapshot; $partial['_commands']=[];
    $statement=$database->prepare('UPDATE cancellation_cleanups SET payload=?'); $statement->bindValue(1,json_encode($partial)); $statement->execute()->finalize(); $statement->close(); $blocked('current_ownership_unknown');
    $seed(); $partial=$snapshot; $partial['_prior_network']=['address'=>'10.77.0.90'];
    $statement=$database->prepare('UPDATE jobs SET payload=? WHERE id=17'); $statement->bindValue(1,json_encode($partial)); $statement->execute()->finalize(); $statement->close(); $blocked('different_resources');
    $seed(); $partial=$safeSnapshot; $partial['_delete_frozen']=true;
    $statement=$database->prepare('UPDATE cancellation_cleanups SET payload=?'); $statement->bindValue(1,json_encode($partial)); $statement->execute()->finalize(); $statement->close(); $blocked('current_ownership_unknown');
    $seed(); $partial=$safeSnapshot; $partial['_commands'][1]['action']='unrecognized';
    $statement=$database->prepare('UPDATE cancellation_cleanups SET payload=?'); $statement->bindValue(1,json_encode($partial)); $statement->execute()->finalize(); $statement->close(); $blocked('current_ownership_unknown');
    $seed(); $partial=$safeSnapshot; $partial['_commands'][1]['path']='/system/reboot';
    $statement=$database->prepare('UPDATE cancellation_cleanups SET payload=?'); $statement->bindValue(1,json_encode($partial)); $statement->execute()->finalize(); $statement->close(); $blocked('current_ownership_unknown');

    // A valid active snapshot includes one-time owned suspension-queue removals.
    $seed(); $housekeeping=$snapshot; $housekeeping['_commands'][]=['path'=>'/queue/simple','name'=>'fixture-suspension','action'=>'remove','_owner'=>'fixture owner'];
    $safeHousekeeping=$housekeeping; unset($safeHousekeeping['password'],$safeHousekeeping['_commands'][1]['password']);
    foreach(['UPDATE service_versions SET applied_payload=?','UPDATE jobs SET payload=? WHERE id=17'] as $sql) {
        $statement=$database->prepare($sql);$statement->bindValue(1,json_encode($housekeeping));$statement->execute()->finalize();$statement->close();
    }
    $statement=$database->prepare('UPDATE cancellation_cleanups SET payload=?');$statement->bindValue(1,json_encode($safeHousekeeping));$statement->execute()->finalize();$statement->close();
    jobRecoveryCheck(is_string($api->preview(17)['previewToken']),'Exact current housekeeping copies could not be consolidated');

    // An equal identity is insufficient if another slot could hold a partially applied resource.
    $seed(); $partial=$safeSnapshot; $partial['_commands'][1]['profile']='other-profile';
    $insert('cancellation_cleanups',['id'=>32,'source_job_id'=>17,'service_id'=>701,'source_version'=>3,'slot'=>'commands','payload'=>json_encode($partial),'identity'=>$identity]);
    jobRecoveryCheck($api->listing()['groups'][0]['snapshot_count']===2,'Grouped debt slots were not shown together'); $blocked('different_resources');

    // Exact preview proof must survive all local and fresh CRM rechecks before any removal.
    $seed(); $review=$api->preview(17); $before=$table('cancellation_cleanups');
    $database->exec("UPDATE jobs SET updated='changed after review' WHERE id=17");
    try {$api->apply($review['previewToken']); throw new RuntimeException('Local race accepted');}
    catch(InvalidArgumentException $expected) {jobRecoveryCheck($expected->getMessage()==='recovery_state_changed','Wrong local race error');}
    jobRecoveryCheck($table('cancellation_cleanups')===$before,'Local race discarded debt');
    $seed(); $review=$api->preview(17); $client['firstName']='Changed after review';
    try {$api->apply($review['previewToken']); throw new RuntimeException('CRM race accepted');}
    catch(InvalidArgumentException $expected) {jobRecoveryCheck($expected->getMessage()==='recovery_state_changed','Wrong CRM race error');} $client['firstName']='Synthetic';
    jobRecoveryCheck(count($table('cancellation_cleanups'))===1,'CRM race discarded debt');
    $seed(); $review=$api->preview(17); $now+=601;
    try {$api->apply($review['previewToken']); throw new RuntimeException('Expired token accepted');}
    catch(InvalidArgumentException $expected) {jobRecoveryCheck($expected->getMessage()==='recovery_preview_expired','Wrong expiry result');} $now-=601;
    $seed(); $failureApi=new ApiJobRecovery($request,$jobs,$data,$legacy,static function() use (&$now){return $now;},static fn()=>false);
    $review=$failureApi->preview(17);
    try {$failureApi->apply($review['previewToken']); throw new RuntimeException('Missing backup allowed apply');}
    catch(InvalidArgumentException $expected) {jobRecoveryCheck($expected->getMessage()==='recovery_backup_failed','Wrong backup failure result');}
    jobRecoveryCheck(count($table('cancellation_cleanups'))===1,'Failed backup discarded debt');

    // Lock out a worker with an active maintenance shared guard; no router connection is attempted.
    $seed(); $review=$api->preview(17); $queueIdentity=realpath($jobs); if(PHP_OS_FAMILY==='Windows') $queueIdentity=strtolower($queueIdentity);
    $guardDirectory=dirname($queueIdentity) . DIRECTORY_SEPARATOR . '.uisp-queue-locks-' . hash('sha256',$queueIdentity);
    $guard=fopen($guardDirectory . '/maintenance.lock','c+b'); flock($guard,LOCK_SH);
    try {$api->apply($review['previewToken']); throw new RuntimeException('Worker guard ignored');}
    catch(InvalidArgumentException $expected) {jobRecoveryCheck($expected->getMessage()==='queue_busy','Wrong worker guard result');}
    finally {flock($guard,LOCK_UN);fclose($guard);}
    jobRecoveryCheck(count($table('cancellation_cleanups'))===1,'Worker contention discarded debt');

    // The real queue must retain exactly the same occupied addresses and current
    // ownership after consolidation and must not reseed debt on its next request.
    set_include_path(realpath(__DIR__ . '/../src/lib') . PATH_SEPARATOR . get_include_path());
    require_once __DIR__ . '/../src/lib/api_job_queue.php';
    $actualPath=$directory . '/actual-jobs.db'; $actualQueue=new ApiJobQueue($actualPath,null); $actualQueue->close();
    $actualDatabase=new SQLite3($actualPath); $actualDatabase->enableExceptions(true);
    $actualInsert=static function(string $name,array $values) use(&$actualDatabase):void {
        $statement=$actualDatabase->prepare('INSERT INTO '.$name.'('.implode(',',array_keys($values)).') VALUES('.implode(',',array_fill(0,count($values),'?')).')');
        foreach(array_values($values) as $index=>$value)$statement->bindValue($index+1,$value,is_int($value)?SQLITE3_INTEGER:($value===null?SQLITE3_NULL:SQLITE3_TEXT));
        $statement->execute()->finalize();$statement->close();
    };
    $actualSnapshot=$snapshot; $actualSnapshot['action']='update'; $actualSafe=$safeSnapshot; $actualSafe['action']='update';
    $actualInsert('jobs',['id'=>17,'service_id'=>701,'operation'=>'update','identity'=>$identity,'version'=>3,
        'payload'=>json_encode($actualSnapshot),'fingerprint'=>'fixture hash','status'=>'cancelled','attempts'=>1,
        'created'=>date('c'),'updated'=>date('c'),'history_scrubbed'=>1]);
    $actualInsert('service_versions',['service_id'=>701,'version'=>3,'applied_identity'=>$identity,'applied_payload'=>json_encode($actualSnapshot)]);
    $actualInsert('cancellation_cleanups',['id'=>31,'source_job_id'=>17,'service_id'=>701,'source_version'=>3,'slot'=>'applied',
        'payload'=>json_encode($actualSafe),'identity'=>$identity]);
    $actualDatabase->close();
    $actualQueue=new ApiJobQueue($actualPath,null); $occupied=$actualQueue->reservedAddresses(); $appliedOwnership=$actualQueue->appliedSnapshot(701); $actualQueue->close();
    $actualApi=new ApiJobRecovery($request,$actualPath,$data,$legacy,static function() use (&$now){return $now;});
    $review=$actualApi->preview(17); jobRecoveryCheck(is_string($review['previewToken']),'Real queue debt could not be reviewed');
    $actualApi->apply($review['previewToken']);
    $actualQueue=new ApiJobQueue($actualPath,null);
    jobRecoveryCheck(!$actualQueue->hasUnreconciled(701) && $actualQueue->reservedAddresses()===$occupied
        && $actualQueue->appliedSnapshot(701)===$appliedOwnership,'Real queue resurrected debt or lost occupied current ownership'); $actualQueue->close();
    // The actual preserve API leaves every occupied address in the actual queue
    // while making the cancelled source unavailable to Batch cleanup forever.
    $actualDatabase=new SQLite3($actualPath);$actualDatabase->enableExceptions(true);
    $cancelledDelete=$actualSnapshot;unset($cancelledDelete['_commands']);$cancelledDelete['action']='delete';
    $cancelledDelete['_prior_network']=['address'=>'10.77.0.99'];$cancelledDelete['_prior_identity']='older incomplete identity';
    // Legacy compaction can retain a historical job identity hash after some
    // source selectors disappeared. A protected hold must not pretend this is
    // current/applied proof, nor require reconstructing vanished selectors.
    $oldSourceIdentity=hash('sha256','fixture historical source before compaction');
    $actualInsert('jobs',['id'=>19,'service_id'=>701,'operation'=>'delete','identity'=>$oldSourceIdentity,'version'=>4,
        'payload'=>json_encode($cancelledDelete),'fingerprint'=>'fixture delete hash','status'=>'cancelled','attempts'=>1,
        'created'=>date('c'),'updated'=>date('c'),'history_scrubbed'=>1]);
    $actualInsert('cancellation_cleanups',['id'=>32,'source_job_id'=>19,'service_id'=>701,'source_version'=>4,'slot'=>'applied',
        'payload'=>json_encode($actualSafe),'identity'=>$identity]);$actualDatabase->close();
    $actualQueue=new ApiJobQueue($actualPath,null);$occupied=$actualQueue->reservedAddresses();$appliedOwnership=$actualQueue->appliedSnapshot(701);$actualQueue->close();
    $review=$actualApi->preview(19,'preserve');jobRecoveryCheck(is_string($review['previewToken']),'Actual cancelled delete cannot preserve incomplete history');
    $actualApi->apply($review['previewToken']);$actualQueue=new ApiJobQueue($actualPath,null);
    jobRecoveryCheck(!$actualQueue->hasUnreconciled(701) && $actualQueue->list()===[] && $actualQueue->reservedAddresses()===$occupied
        && $actualQueue->appliedSnapshot(701)===$appliedOwnership,'Actual preserve API changed ownership, occupancy or kept a hidden executable job');$actualQueue->close();
    $actualDatabase=new SQLite3($actualPath,SQLITE3_OPEN_READONLY);
    $sourceHold=$actualDatabase->querySingle("SELECT * FROM retained_resources WHERE source_job_id=19 AND slot='source'",true);
    jobRecoveryCheck($sourceHold['payload']===json_encode($cancelledDelete) && $sourceHold['identity']===jobRecoveryIdentity($cancelledDelete)
        && $sourceHold['identity']!==$oldSourceIdentity,'Incomplete retained source was silently promoted to its old identity');$actualDatabase->close();

    // Exact pre-command snapshots are still tracked by confirmed applied state.
    // Their redundant copy can close without inventing any frozen resource list.
    $seed(); $legacyApplied=$snapshot; unset($legacyApplied['_commands']);
    $legacyDebt=$safeSnapshot; unset($legacyDebt['_commands']);
    foreach(['UPDATE service_versions SET applied_payload=?','UPDATE jobs SET payload=? WHERE id=17'] as $sql) {
        $statement=$database->prepare($sql);$statement->bindValue(1,json_encode($legacyApplied));$statement->execute()->finalize();$statement->close();
    }
    $statement=$database->prepare('UPDATE cancellation_cleanups SET payload=?');$statement->bindValue(1,json_encode($legacyDebt));$statement->execute()->finalize();$statement->close();
    $review=$api->preview(17);
    jobRecoveryCheck(is_string($review['previewToken']) && ($review['candidate']['evidence']??null)==='exact_legacy_applied_copy','Exact legacy copies remain stuck on missing frozen commands');
    $legacyOwnership=$table('service_versions'); $api->apply($review['previewToken']);
    jobRecoveryCheck($table('service_versions')===$legacyOwnership && !$table('cancellation_cleanups'),'Legacy consolidation changed confirmed applied ownership');

    // Preserve is a separate, explicitly reviewed action. It can close an
    // incomplete history without claiming the router is empty or deleting it.
    $seed(); $partial=$snapshot; unset($partial['_commands']);
    $partial['_prior_network']=['address'=>'10.77.0.99'];$partial['_prior_identity']='unconfirmed old identity';
    $statement=$database->prepare('UPDATE jobs SET payload=? WHERE id=17');$statement->bindValue(1,json_encode($partial));$statement->execute()->finalize();$statement->close();
    $database->exec('UPDATE service_versions SET applied_payload=NULL,applied_identity=NULL');
    $protectedJobs=$table('jobs'); $protectedDebt=$table('cancellation_cleanups'); $protectedVersions=$table('service_versions');
    $review=$api->preview(17,'preserve');
    jobRecoveryCheck(is_string($review['previewToken']) && $review['candidate']['resolution']==='retained_resources'
        && $table('cancellation_cleanups')===$protectedDebt,'Preservation preview mutated debt or remained blocked by missing history');
    $result=$api->apply($review['previewToken']);
    jobRecoveryCheck($result['resolved']===[] && $result['retained']===[['source_job_id'=>17,'service_id'=>701,'snapshot_count'=>1]]
        && $result['router_changes']===false && $result['crm_changes']===false,'Preservation claimed physical resolution');
    $held=$table('retained_resources'); $heldSlots=array_column($held,null,'slot');
    jobRecoveryCheck(count($held)===2 && $heldSlots['applied']['payload']===$protectedDebt[0]['payload']
        && $heldSlots['applied']['identity']===$protectedDebt[0]['identity'] && $heldSlots['source']['payload']===$protectedJobs[0]['payload']
        && $table('service_versions')===$protectedVersions && !$table('cancellation_cleanups'),'Preservation discarded or altered protected ownership');
    $jobsAfter=$table('jobs');$jobBefore=$protectedJobs[0];$jobAfter=$jobsAfter[0];unset($jobBefore['status'],$jobBefore['updated'],$jobAfter['status'],$jobAfter['updated']);
    jobRecoveryCheck($jobsAfter[0]['status']==='cleaned' && $jobBefore===$jobAfter && $jobsAfter[1]===$protectedJobs[1],'Preservation altered unrelated or source resource state');
    $listing=$api->listing(); $summary=(new ApiJobsStatus($jobs,$legacy))->read();
    jobRecoveryCheck($listing['total']===0 && $listing['reviewed_total']===1 && count($listing['reviewed_groups'])===1
        && $listing['reviewed_groups'][0]['reason']==='retained_resources' && $summary['recovery']===0,'Preserved ownership stayed pending or disappeared from reviewed list');
    $text=json_encode($listing);foreach(['PRIVATE','_commands','username','password','rate-limit','10.77.0.99'] as $secret)jobRecoveryCheck(strpos($text,$secret)===false,'Retained list leaked private ownership');
    $database->exec("UPDATE retained_resources SET identity='corrupted' WHERE slot='source'");
    jobRecoveryCheck($api->listing()['available']===false,'Corrupt retained resource identity was labeled reviewed');
    $statement=$database->prepare("UPDATE retained_resources SET identity=? WHERE slot='source'");$statement->bindValue(1,jobRecoveryIdentity($partial));$statement->execute()->finalize();$statement->close();
    $holdBackup=new SQLite3($directory.'/'.$result['backup_name'],SQLITE3_OPEN_READONLY);
    jobRecoveryCheck((int)$holdBackup->querySingle('SELECT count(*) FROM cancellation_cleanups')===1,'Preservation backup omitted exact pre-change debt');$holdBackup->close();
    try {$api->apply($review['previewToken']);throw new RuntimeException('Preserve token reused');}
    catch(InvalidArgumentException $expected){jobRecoveryCheck($expected->getMessage()==='invalid_recovery_token','Wrong preserve replay result');}

    // Fresh identity and queue ownership are mandatory even when retaining.
    $seed(); $service['clientId']=82; $review=$api->preview(17,'preserve');
    jobRecoveryCheck($review['previewToken']===null && $review['blocked'][0]['reason']==='crm_identity_changed','Preserve accepted a different current customer');$service['clientId']=81;
    $seed(); $review=$api->preview(17,'preserve');$database->exec("UPDATE jobs SET updated='changed' WHERE id=17");
    try {$api->apply($review['previewToken']);throw new RuntimeException('Preserve accepted local drift');}
    catch(InvalidArgumentException $expected){jobRecoveryCheck($expected->getMessage()==='recovery_state_changed','Wrong preserve local drift result');}
    jobRecoveryCheck(count($table('cancellation_cleanups'))===1 && !$table('retained_resources'),'Stale preserve review moved ownership');
    $seed(); $review=$api->preview(17,'preserve');$service['status']=3;
    try {$api->apply($review['previewToken']);throw new RuntimeException('Preserve accepted CRM drift');}
    catch(InvalidArgumentException $expected){jobRecoveryCheck($expected->getMessage()==='recovery_state_changed','Wrong preserve CRM drift result');}$service['status']=1;
    $seed();$insert('jobs',['id'=>19,'service_id'=>701,'status'=>'pending','version'=>4,'payload'=>'{}']);
    $review=$api->preview(17,'preserve');jobRecoveryCheck($review['previewToken']===null && $review['blocked'][0]['reason']==='queue_busy','Preserve ignored an executable job for same service');

    // Final discard is an explicitly authorized local action. UISP may be
    // offline; current applied resources and executable work are preserved.
    $seed(); $mode='offline'; $localData=new SQLite3($data);
    $localData->exec("CREATE TABLE network(id INTEGER PRIMARY KEY,address TEXT); INSERT INTO network VALUES(701,'10.77.0.11');
        CREATE TABLE services(id INTEGER PRIMARY KEY,status INTEGER); INSERT INTO services VALUES(701,1)");$localData->close();
    file_put_contents($directory.'/cache.db','SYNTHETIC PRIVATE CACHE UNCHANGED');
    $dataHash=hash_file('sha256',$data);$cacheHash=hash_file('sha256',$directory.'/cache.db');
    $insert('jobs',['id'=>19,'service_id'=>701,'status'=>'pending','version'=>4,'payload'=>'{"id":701,"clientId":81,"action":"update"}']);
    $insert('jobs',['id'=>20,'service_id'=>703,'status'=>'cancelled','version'=>1,'payload'=>'{"id":703,"clientId":83}']);
    $insert('jobs',['id'=>21,'service_id'=>701,'status'=>'cancelled','version'=>2,'payload'=>'{"id":701,"clientId":81}']);
    $peer=$safeSnapshot;$peer['id']=703;$peer['clientId']=83;
    $insert('cancellation_cleanups',['id'=>32,'source_job_id'=>20,'service_id'=>703,'source_version'=>1,'slot'=>'applied','payload'=>json_encode($peer),'identity'=>jobRecoveryIdentity($peer)]);
    $insert('cancellation_cleanups',['id'=>33,'source_job_id'=>21,'service_id'=>701,'source_version'=>2,'slot'=>'applied','payload'=>json_encode($safeSnapshot),'identity'=>$identity]);
    $jobsBefore=$table('jobs');$debtBefore=$table('cancellation_cleanups');$versionsBefore=$table('service_versions');$callsBefore=$calls;
    $review=$api->preview(17,'discard');
    jobRecoveryCheck(is_string($review['previewToken']) && $review['candidate']['resolution']==='discarded_resources'
        && $review['candidate']['identity_origin']==='local' && $review['candidate']['crm_status']===null
        && $review['candidate']['client_archived']===null && $review['candidate']['diagnostics']['pending_jobs']===1
        && $calls===$callsBefore && $table('jobs')===$jobsBefore && $table('cancellation_cleanups')===$debtBefore,
        'Final discard requested external proof, claimed fresh status, blocked valid pending work, or mutated preview');
    $result=$api->apply($review['previewToken']);$expected=[['source_job_id'=>17,'service_id'=>701,'snapshot_count'=>1]];
    jobRecoveryCheck($result['discarded']===$expected && $result['resolved']===$expected && $result['resolution']==='discarded_resources'
        && $result['router_changes']===false && $result['crm_changes']===false && $calls===$callsBefore,
        'Final discard changed external state or lost its explicit outcome');
    $expectedJobs=$jobsBefore;$expectedJobs[0]['status']='cleaned';$expectedJobs[0]['updated']=gmdate('Y-m-d\TH:i:s\Z',$now);
    jobRecoveryCheck($table('jobs')===$expectedJobs && $table('cancellation_cleanups')===array_slice($debtBefore,1)
        && $table('service_versions')===$versionsBefore && hash_file('sha256',$data)===$dataHash
        && hash_file('sha256',$directory.'/cache.db')===$cacheHash,'Final discard affected another source, current ownership, jobs, network or cache');
    $discardBackup=new SQLite3($directory.'/'.$result['backup_name'],SQLITE3_OPEN_READONLY);
    jobRecoveryCheck((int)$discardBackup->querySingle('SELECT count(*) FROM cancellation_cleanups')===3
        && $discardBackup->querySingle('SELECT status FROM jobs WHERE id=17')==='cancelled'
        && $discardBackup->querySingle('SELECT applied_payload FROM service_versions WHERE service_id=701')===$versionsBefore[0]['applied_payload'],
        'Final discard backup omitted exact recoverable pre-change state');$discardBackup->close();
    $public=json_encode([$review,$result]);foreach(['PRIVATE','_commands','username','password','10.77.0.11'] as $secret)
        jobRecoveryCheck(strpos($public,$secret)===false,'Final discard leaked private history');
    try{$api->apply($review['previewToken']);throw new RuntimeException('Discard token replayed');}
    catch(InvalidArgumentException $expected){jobRecoveryCheck($expected->getMessage()==='invalid_recovery_token','Wrong discard replay result');}

    foreach(['source','pending','applied','debt'] as $race) {
        $seed();$insert('jobs',['id'=>19,'service_id'=>701,'status'=>'pending','version'=>4,'payload'=>'{"id":701,"clientId":81}']);
        $review=$api->preview(17,'discard');$debtBefore=$table('cancellation_cleanups');
        if($race==='source')$database->exec("UPDATE jobs SET updated='changed' WHERE id=17");
        elseif($race==='pending')$database->exec("UPDATE jobs SET updated='changed' WHERE id=19");
        elseif($race==='applied')$database->exec('UPDATE service_versions SET version=999');
        else $database->exec("UPDATE cancellation_cleanups SET slot='commands' WHERE id=31");
        try{$api->apply($review['previewToken']);throw new RuntimeException('Discard accepted changed '.$race);}
        catch(InvalidArgumentException $expected){jobRecoveryCheck($expected->getMessage()==='recovery_state_changed','Wrong discard race result: '.$expected->getMessage());}
        jobRecoveryCheck(count($table('cancellation_cleanups'))===1,'Stale discard removed historical ownership');
    }
    $seed();$database->exec("UPDATE jobs SET status='running' WHERE id=18");$review=$api->preview(17,'discard');
    jobRecoveryCheck($review['previewToken']===null && $review['blocked'][0]['reason']==='queue_busy','Discard ignored an active router operation');
    $seed();$insert('jobs',['id'=>19,'service_id'=>701,'status'=>'pending','version'=>4,'payload'=>'{}']);$review=$api->preview(17,'discard');
    jobRecoveryCheck($review['previewToken']===null && $review['blocked'][0]['reason']==='source_state_changed','Discard accepted malformed pending identity');
    $seed();$review=$failureApi->preview(17,'discard');
    try{$failureApi->apply($review['previewToken']);throw new RuntimeException('Discard proceeded without backup');}
    catch(InvalidArgumentException $expected){jobRecoveryCheck($expected->getMessage()==='recovery_backup_failed','Wrong final discard backup failure');}
    jobRecoveryCheck(count($table('cancellation_cleanups'))===1 && $table('jobs')[0]['status']==='cancelled','Backup failure partially discarded history');
    $seed();$review=$api->preview(17,'discard');$now+=601;
    try{$api->apply($review['previewToken']);throw new RuntimeException('Expired discard accepted');}
    catch(InvalidArgumentException $expected){jobRecoveryCheck($expected->getMessage()==='recovery_preview_expired','Wrong discard expiry result');}$now-=601;

    // Protected history can also be explicitly discarded after review. Every
    // selected slot, including the preserved source, belongs to the backup.
    foreach([false,true] as $sourceCompacted) {
        $seed();$mode=null;$review=$api->preview(17,'preserve');$api->apply($review['previewToken']);
        if($sourceCompacted)$database->exec('DELETE FROM jobs WHERE id=17');
        $mode='offline';$heldBefore=$table('retained_resources');$versionsBefore=$table('service_versions');$callsBefore=$calls;
        $review=$api->preview(17,'discard');
        jobRecoveryCheck(is_string($review['previewToken']) && $review['candidate']['reason']==='retained_resources'
            && isset($review['candidate']['reviewed_at']) && $review['candidate']['snapshot_count']===1
            && $review['candidate']['diagnostics']['local_record_count']===2 && $calls===$callsBefore,
            'Protected history remained permanently undiscardable or claimed fresh external evidence');
        $result=$api->apply($review['previewToken']);
        jobRecoveryCheck(!$table('retained_resources') && $table('service_versions')===$versionsBefore
            && $result['discarded']===[['source_job_id'=>17,'service_id'=>701,'snapshot_count'=>1]]
            && $calls===$callsBefore && $api->listing()['reviewed_total']===0,'Final protected-history discard lost current applied ownership or stale indicator');
        $discardBackup=new SQLite3($directory.'/'.$result['backup_name'],SQLITE3_OPEN_READONLY);
        jobRecoveryCheck((int)$discardBackup->querySingle('SELECT count(*) FROM retained_resources')===count($heldBefore),
            'Discard backup omitted the protected source or its resource slots');$discardBackup->close();
    }
    $seed();$mode=null;$review=$api->preview(17,'discard');
    $tokenPath=$directory.'/jobs-recovery-preview-'.$review['previewToken'].'.json';$state=json_decode(file_get_contents($tokenPath),true);
    $state['mode']='preserve';file_put_contents($tokenPath,json_encode($state));
    try{$api->apply($review['previewToken']);throw new RuntimeException('Discard token changed modes');}
    catch(InvalidArgumentException $expected){jobRecoveryCheck($expected->getMessage()==='recovery_state_changed','Mode change bypassed fingerprint binding');}

    $seed(); $database->exec("CREATE TRIGGER recovery_bad_trigger AFTER DELETE ON cancellation_cleanups BEGIN DELETE FROM jobs; END");
    jobRecoveryCheck($api->listing()['available']===false,'Unexpected triggers were accepted');
    $database->exec('DROP TRIGGER recovery_bad_trigger; ALTER TABLE service_versions RENAME TO old_versions;
        CREATE TABLE service_versions(service_id INTEGER,version INTEGER,applied_identity TEXT,applied_payload TEXT);
        INSERT INTO service_versions SELECT * FROM old_versions;');
    jobRecoveryCheck($api->listing()['available']===false,'Ambiguous ownership primary key was accepted');
    echo "Job recovery regression passed\n";
} finally {
    if($database!==null) $database->close();
    $remove=function(string $path) use (&$remove):void {if(is_dir($path)&&!is_link($path)){foreach(scandir($path) as $name)if($name!=='.'&&$name!=='..')$remove($path.DIRECTORY_SEPARATOR.$name);rmdir($path);}elseif(file_exists($path)||is_link($path))unlink($path);};
    $remove($directory); $remove=null;
}
