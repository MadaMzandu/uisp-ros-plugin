<?php
// Real Batch/ApiIP/job queue, synthetic GET-only CRM and an in-memory RouterOS transport.
set_error_handler(function ($severity,$message,$file,$line) {
    if (error_reporting() & $severity) throw new ErrorException($message,0,$severity,$file,$line);
    return false;
});
function repairBatchCheck(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
function repairBatchState(): array {
    global $apiSqlite;
    return ['network'=>$apiSqlite->selectAllFromTable('network'),'services'=>$apiSqlite->selectAllFromTable('services'),
        'plans'=>$apiSqlite->selectAllFromTable('plans'),'queue_hash'=>hash_file('sha256','data/jobs.db'),'writes'=>MT::$writes];
}
$source=realpath(__DIR__.'/../src'); $original=getcwd(); $originalInclude=get_include_path();
$directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'uisp-repair-batch-'.bin2hex(random_bytes(8)); mkdir($directory,0700);
foreach (['lib','includes','data','vendor'] as $folder) mkdir($directory.'/'.$folder,0700);
foreach (glob($source.'/lib/*.php') as $file) copy($file,$directory.'/lib/'.basename($file));
foreach (glob($source.'/includes/*') as $file) if (is_file($file)) copy($file,$directory.'/includes/'.basename($file));
file_put_contents($directory.'/vendor/autoload.php','<?php namespace Ubnt\\UcrmPluginSdk\\Service; class UcrmApi { public static function create(){return new self();} public function get(){throw new \\RuntimeException("External network prohibited");}}');
file_put_contents($directory.'/lib/mt.php', <<<'PHP'
<?php
class MT {
    public static array $writes=[];
    public function do_batch($device,$commands,?callable $beforeWrite=null): int {
        if ($beforeWrite) $beforeWrite($commands);
        foreach ($commands as $command) self::$writes[]=['device'=>(int)$device->id,'command'=>$command];
        return count($commands);
    }
    public function failed(): array{return [];} public function success(): array{return [];}
}
PHP);
chdir($directory); set_include_path($directory.'/lib'.PATH_SEPARATOR.$originalInclude);
require 'lib/api_common.php'; require 'lib/api_setup.php'; require 'lib/batch.php'; require 'lib/api_network_repair.php';
try {
    file_put_contents('data/config.json',json_encode(['debugEnable'=>false]));
    $apiSqlite=new ApiSqlite('data/data.db'); $apiSqlite->exec(file_get_contents('includes/schema.sql')); (new ApiSetup())->config_load();
    $apiSqlite->saveConfig(['version'=>MY_VERSION,'disable_contention'=>true,'disabled_profile'=>'fixture-paused',
        'disabled_rate'=>0,'auto_ppp_user'=>false,'ip_addr_attr'=>'fixture_ip','ip_addr6_attr'=>'fixture_ip6']);
    $apiSqliteCache=new ApiSqlite('data/cache.db'); $apiSqliteCache->exec(file_get_contents('includes/cache.sql')); $apiJobQueue=myJobQueue();
    $apiSqlite->insert(['id'=>21,'name'=>'Fixture Router','type'=>'mikrotik','ip'=>'192.0.2.21','user'=>'fixture','password'=>'fixture',
        'pool'=>'198.51.100.0/24','pool6'=>null,'pfxLength'=>64],'devices');
    $apiSqlite->insert(['id'=>1,'name'=>'Fixture First Plan','ratio'=>1,'uploadSpeed'=>7,'downloadSpeed'=>21],'plans');
    $apiSqlite->insert(['id'=>2,'name'=>'Fixture Current Plan','ratio'=>1,'uploadSpeed'=>11,'downloadSpeed'=>33],'plans');
    $apiSqliteCache->insert(['id'=>71,'firstName'=>'Fixture','lastName'=>'Client'],'clients');
    $apiSqliteCache->insert(['id'=>601,'clientId'=>71,'device'=>21,'planId'=>1,'status'=>1,'username'=>'fixture-601','password'=>'fixture',
        'uploadSpeed'=>7,'downloadSpeed'=>21],'services');
    $apiSqlite->insert(['id'=>601,'address'=>'192.0.2.61'],'network'); $apiSqliteCache->insert(['id'=>601,'address'=>'192.0.2.61'],'network');
    repairBatchCheck((new Batch())->set_accounts([601]),'Initial valid service was not applied');
    $appliedBefore=$apiJobQueue->appliedSnapshot(601); $plansBefore=$apiSqlite->selectAllFromTable('plans');
    foreach ([599=>'192.0.2.199',596=>'192.0.2.196'] as $id=>$address) {
        $apiSqlite->insert(['id'=>$id,'clientId'=>71,'device'=>21,'planId'=>1,'status'=>1],'services');
        $apiSqlite->insert(['id'=>$id,'address'=>'PRIVATE-MALFORMED-'.$id],'network');
        $apiSqliteCache->insert(['id'=>$id,'clientId'=>71,'device'=>21,'planId'=>1,'status'=>1,'username'=>'fixture-'.$id,'password'=>'fixture',
            'uploadSpeed'=>7,'downloadSpeed'=>21],'services');
        $apiSqliteCache->insert(['id'=>$id,'address'=>$address],'network');
    }
    $apiSqliteCache->edit(['id'=>601,'planId'=>2,'status'=>3,'uploadSpeed'=>11,'downloadSpeed'=>33],'services');
    $apiSqliteCache->edit(['id'=>601,'address'=>'192.0.2.62'],'network');
    $writeCount=count(MT::$writes);
    repairBatchCheck((new Batch())->set_accounts([601]),'Malformed peer history blocked a valid service address/plan change');
    repairBatchCheck(count(MT::$writes)>$writeCount && $apiSqlite->selectAddress(601)==='192.0.2.62'
        && $apiSqlite->selectAddress(599)==='PRIVATE-MALFORMED-599' && $apiSqlite->selectAddress(596)==='PRIVATE-MALFORMED-596'
        && $apiSqlite->selectAllFromTable('plans')===$plansBefore,
        'Unrelated provisioning changed the malformed peer rows or configured plans');
    $appliedBefore=$apiJobQueue->appliedSnapshot(601);
    (new Batch())->queue_accounts([599,596]);
    $pending=$apiJobQueue->list(); $keys=array_column($pending,'jobId');
    repairBatchCheck(count($pending)===2,'Expected two independently queued repairable updates');
    $services=[];
    foreach ([601=>['192.0.2.62',3],599=>['192.0.2.199',1],596=>['192.0.2.196',1]] as $id=>$data) {
        $services[$id]=['id'=>$id,'clientId'=>71,'status'=>$data[1],'attributes'=>[
            ['key'=>'fixture_ip','customAttributeId'=>1,'value'=>$data[0]],['key'=>'fixture_ip6','customAttributeId'=>2,'value'=>null]]];
    }
    $request=static function(string $path,array $parameters) use (&$services) {
        if($path==='custom-attributes') return [['id'=>1,'key'=>'fixture_ip','attributeType'=>'service'],['id'=>2,'key'=>'fixture_ip6','attributeType'=>'service']];
        if($path==='clients/71') return ['id'=>71,'isArchived'=>false];
        if($path==='clients/services') return array_slice(array_values($services),$parameters['offset'],$parameters['limit']);
        if(preg_match('~^clients/services/([0-9]+)$~D',$path,$match) && isset($services[(int)$match[1]])) return $services[(int)$match[1]];
        throw new RuntimeException('Unexpected synthetic CRM read');
    };
    $repair=new ApiNetworkRepair($request); $initialState=repairBatchState();
    foreach ([599,596] as $id) {
        $preview=$repair->preview([['serviceId'=>$id,'fields'=>['address']]]);
        repairBatchCheck(count($preview['candidates'])===1 && $preview['candidates'][0]['pending_jobs']===1,
            'Actual queue-generated invalid prior/identity prevented explicit repair: '.json_encode($preview));
        $result=$repair->apply($preview['previewToken']);
        repairBatchCheck($result['jobs_preserved'] && hash_file('sha256','data/jobs.db')===$initialState['queue_hash']
            && MT::$writes===$initialState['writes'] && $apiJobQueue->appliedSnapshot(601)===$appliedBefore,
            'Repair itself wrote RouterOS, rewrote pending intent or changed current service');
    }
    $retries=$apiJobQueue->runSelected($keys);
    repairBatchCheck(count($retries)===2 && array_reduce($retries,static fn($good,$row)=>$good && $row['success'],true),
        'Normal selected retry did not complete after safe repair');
    $after=$apiJobQueue->appliedSnapshot(601);
    repairBatchCheck($after['status']===3 && $after['_plan']['id']===2 && $after['_plan']['uploadSpeed']===11
        && $after['_plan']['downloadSpeed']===33 && $apiSqlite->selectAddress(601)==='192.0.2.62'
        && $apiSqlite->selectAllFromTable('plans')===$plansBefore,'Normal retry lost intended status/rates or altered configured plans');
    $paused=array_values(array_filter(array_slice(MT::$writes,$writeCount),static fn($row)=>trim($row['command']['path']??'','/')==='ppp/secret'
        && ($row['command']['name']??null)==='fixture-601'));
    repairBatchCheck(count($paused)===1 && $paused[0]['command']['profile']==='fixture-paused','Ordinary retry lost the requested suspension');
    $apiSqliteCache->edit(['id'=>601,'status'=>1],'services'); $rateStart=count(MT::$writes);
    repairBatchCheck((new Batch())->set_accounts([601]),'Ordinary reactivation after repair failed');
    $profiles=array_values(array_filter(array_slice(MT::$writes,$rateStart),static fn($row)=>trim($row['command']['path']??'','/')==='ppp/profile'));
    repairBatchCheck((bool)array_filter($profiles,static fn($row)=>str_starts_with($row['command']['rate-limit']??'','11M/33M ')),
        'Plan outcome was inferred from label instead of actual generated RouterOS rate');
    // Corrupt an already applied local record; restoration to its exact confirmed
    // current IP is allowed without changing the account or its applied snapshot.
    $apiSqlite->edit(['id'=>599,'address'=>'PRIVATE-LOCAL-CORRUPTION'],'network');
    $sameBefore=repairBatchState(); $sameApplied=$apiJobQueue->appliedSnapshot(599);
    $preview=$repair->preview([['serviceId'=>599,'fields'=>['address']]]);
    repairBatchCheck(count($preview['candidates'])===1,'Exact current applied address was unnecessarily blocked');
    $repair->apply($preview['previewToken']);
    repairBatchCheck($apiSqlite->selectAddress(599)==='192.0.2.199' && $apiJobQueue->appliedSnapshot(599)===$sameApplied
        && hash_file('sha256','data/jobs.db')===$sameBefore['queue_hash'] && MT::$writes===$sameBefore['writes'],
        'Current-IP restoration changed live account ownership');
    $apiSqlite->edit(['id'=>599,'address'=>'PRIVATE-LOCAL-CORRUPTION'],'network');
    $services[599]['attributes'][0]['value']='192.0.2.198'; $apiSqliteCache->edit(['id'=>599,'address'=>'192.0.2.198'],'network');
    $changedApplied=$repair->preview([['serviceId'=>599,'fields'=>['address']]]);
    repairBatchCheck($changedApplied['previewToken']===null && $changedApplied['blocked'][0]['reason']==='applied_ownership',
        'Changing a corrupt local row released or replaced a different actually applied IP');
    $services[599]['attributes'][0]['value']=null; $apiSqliteCache->edit(['id'=>599,'address'=>null],'network');
    $blocked=$repair->preview([['serviceId'=>599,'fields'=>['address']]]);
    repairBatchCheck($blocked['previewToken']===null && $blocked['blocked'][0]['reason']==='applied_ownership',
        'A fresh CRM absence released an actually applied current IP');
    // A failed old intent can be cancelled after its requested IP was corrected
    // in CRM. It must stay cancelled and immutable, but its obsolete requested
    // value must not strand the independently verified malformed local row.
    $apiSqlite->edit(['id'=>599,'address'=>'192.0.2.199'],'network');
    $services[599]['attributes'][0]['value']='192.0.2.199'; $apiSqliteCache->edit(['id'=>599,'address'=>'192.0.2.199'],'network');
    $cancelledKeys=[];
    foreach ([595=>'PRIVATE-OBSOLETE-REQUEST',594=>'192.0.2.94'] as $id=>$obsoleteAddress) {
        $newAddress='192.0.2.'.($id-400);
        $apiSqlite->insert(['id'=>$id,'clientId'=>null,'device'=>21,'planId'=>1,'status'=>1],'services');
        $apiSqlite->insert(['id'=>$id,'address'=>'PRIVATE-MALFORMED-'.$id,'address6'=>'2001:db8:'.$id.'::/64'],'network');
        $apiSqliteCache->insert(['id'=>$id,'clientId'=>71,'device'=>21,'planId'=>1,'status'=>1,'username'=>'fixture-'.$id,
            'uploadSpeed'=>7,'downloadSpeed'=>21],'services');
        $apiSqliteCache->insert(['id'=>$id,'address'=>$newAddress,'address6'=>'2001:db8:'.$id.'::/64'],'network');
        $cancelledKeys[]=$apiJobQueue->enqueue(['id'=>$id,'clientId'=>71,'device'=>21,'username'=>'fixture-'.$id,
            'address'=>$obsoleteAddress,'network'=>['address'=>$obsoleteAddress],
            '_prior_network'=>['address'=>'PRIVATE-MALFORMED-'.$id,'address6'=>'2001:db8:'.$id.'::/64']]);
        $services[$id]=['id'=>$id,'clientId'=>71,'status'=>1,'attributes'=>[
            ['key'=>'fixture_ip','customAttributeId'=>1,'value'=>$newAddress],
            ['key'=>'fixture_ip6','customAttributeId'=>2,'value'=>'2001:db8:'.$id.'::/64']]];
    }
    repairBatchCheck(!in_array(false,$apiJobQueue->delete($cancelledKeys),true),'Real queue did not cancel stale intents');
    $cancelledReserved=$apiJobQueue->reservedAddresses();
    repairBatchCheck(!array_intersect([594,595],array_column($cancelledReserved,'id')),
        'Cancelled unprepared desired or malformed prior unexpectedly became an allocated IPv4 reservation');
    $reserved6Before=$apiJobQueue->reservedAddresses(true);
    repairBatchCheck(count(array_intersect([594,595],array_column($reserved6Before,'id')))===2,
        'Valid other-family cancelled prior reservations were dropped');
    $apiSqliteCache->insert(['id'=>603,'clientId'=>71,'device'=>21,'planId'=>1,'status'=>1,'username'=>'fixture-603',
        'password'=>'fixture','uploadSpeed'=>7,'downloadSpeed'=>21],'services');
    $apiSqliteCache->insert(['id'=>603,'address'=>'192.0.2.203'],'network');
    repairBatchCheck((new Batch())->set_accounts([603]) && $apiSqlite->selectAddress(595)==='PRIVATE-MALFORMED-595'
        && $apiSqlite->selectAddress(594)==='PRIVATE-MALFORMED-594',
        'Malformed cancelled history blocked another service or was silently deleted');
    $cancelledWriteCount=count(MT::$writes); $cancelledBefore=repairBatchState();
    foreach ([595,594] as $id) {
        $preview=$repair->preview([['serviceId'=>$id,'fields'=>['address']]]);
        repairBatchCheck(count($preview['candidates'])===1 && $preview['candidates'][0]['pending_jobs']===0,
            'Cancelled stale desired IP blocked fresh identity-verified local repair: '.json_encode($preview));
        $repair->apply($preview['previewToken']);
        repairBatchCheck(hash_file('sha256','data/jobs.db')===$cancelledBefore['queue_hash']
            && count(MT::$writes)===$cancelledWriteCount && $apiJobQueue->reservedAddresses(true)===$reserved6Before,
            'Cancelled-intent repair modified job history, router, or a valid other-family prior');
    }
    $cancelledRun=$apiJobQueue->runSelected($cancelledKeys);
    repairBatchCheck(!array_filter($cancelledRun,static fn($row)=>$row['attempted']??false),
        'Repair made a cancelled stale intent executable');
    repairBatchCheck(!array_filter(array_slice(MT::$writes,$cancelledWriteCount),static fn($row)=>in_array(
        $row['command']['name']??null,['fixture-594','fixture-595'],true)), 'Cancelled accounts were provisioned by recovery');
    // Cancellation of a prepared operation retains both its frozen commands
    // and the already applied account. A local repair to that same account IP
    // must leave every durable snapshot and allocator reservation protected.
    $frozen=$apiJobQueue->appliedSnapshot(599);
    $frozenKey=$apiJobQueue->enqueue($frozen);
    repairBatchCheck($apiJobQueue->delete([$frozenKey])[$frozenKey]===true,'Prepared fixture did not cancel');
    $database=new SQLite3('data/jobs.db',SQLITE3_OPEN_READONLY);
    $debtCount=$database->querySingle('SELECT count(*) FROM cancellation_cleanups WHERE service_id=599'); $database->close();
    repairBatchCheck($debtCount>=1,'Prepared cancellation did not retain its actual resources');
    require_once 'lib/api_job_recovery.php';
    $recovery=new ApiJobRecovery(static fn(string $path) => $request($path, []));
    $preserve=$recovery->preview((int)substr($frozenKey,1),'preserve');
    repairBatchCheck($preserve['previewToken']!==null,'Compatible cancellation could not be explicitly retained');
    $recovery->apply($preserve['previewToken']);
    $database=new SQLite3('data/jobs.db',SQLITE3_OPEN_READONLY);
    $retainedCount=$database->querySingle('SELECT count(*) FROM retained_resources WHERE service_id=599'); $database->close();
    repairBatchCheck($retainedCount===$debtCount+1,'Retained ownership lost a source or resource snapshot');
    $apiSqlite->edit(['id'=>599,'address'=>'PRIVATE-CORRUPTION-WITH-RECOVERY'],'network');
    $apiSqliteCache->insert(['id'=>604,'clientId'=>71,'device'=>21,'planId'=>1,'status'=>1,'username'=>'fixture-604',
        'password'=>'fixture','uploadSpeed'=>7,'downloadSpeed'=>21],'services');
    $apiSqliteCache->insert(['id'=>604,'address'=>'192.0.2.204'],'network');
    $services[604]=['id'=>604,'clientId'=>71,'status'=>1,'attributes'=>[
        ['key'=>'fixture_ip','customAttributeId'=>1,'value'=>'192.0.2.204'],
        ['key'=>'fixture_ip6','customAttributeId'=>2,'value'=>null]]];
    $beforeBlocked=count(MT::$writes);
    repairBatchCheck((new Batch())->set_accounts([604]) && $apiSqlite->selectAddress(604)==='192.0.2.204'
        && $apiSqlite->selectAddress(599)==='PRIVATE-CORRUPTION-WITH-RECOVERY',
        'A malformed retained peer row blocked unrelated allocation or was silently changed');
    $frozenBefore=repairBatchState(); $reservedBefore=$apiJobQueue->reservedAddresses(); $appliedBefore=$apiJobQueue->appliedSnapshot(599);
    $preview=$repair->preview([['serviceId'=>599,'fields'=>['address']]]);
    repairBatchCheck(count($preview['candidates'])===1 && $preview['candidates'][0]['retained_recovery_records']===$retainedCount,
        'Compatible prepared recovery stranded an exact local repair: '.json_encode($preview));
    require_once 'lib/api_automatic_reconciliation.php';
    $automatic=new ApiAutomaticReconciliation($request);
    $automatic->tick();
    repairBatchCheck($apiSqlite->selectAddress(599)==='192.0.2.199' && $apiJobQueue->appliedSnapshot(599)===$appliedBefore
        && hash_file('sha256','data/jobs.db')===$frozenBefore['queue_hash'] && MT::$writes===$frozenBefore['writes']
        && $apiJobQueue->reservedAddresses()===$reservedBefore,
        'Compatible recovery repair released ownership, revived intent, or modified RouterOS');
    $afterAutomatic=repairBatchState(); $backupCount=count(glob('data/network-cleanup-*.sqlite'));
    $automatic->tick();
    repairBatchCheck(repairBatchState()===$afterAutomatic && count(glob('data/network-cleanup-*.sqlite'))===$backupCount,
        'Repeated automatic reconciliation rewrote a valid reservation or created another backup');
    $cancelledFrozen=$apiJobQueue->runSelected([$frozenKey]);
    repairBatchCheck(!($cancelledFrozen[$frozenKey]['attempted']??false),'Local correction made a cancelled prepared operation executable');
    repairBatchCheck(!array_filter(array_slice(MT::$writes,$beforeBlocked),static fn($row)=>
        ($row['command']['name']??null)==='fixture-599'
        || in_array(trim($row['command']['path']??'','/'),['ppp/active','ip/hotspot/active'],true)
            && ($row['command']['name']??$row['command']['user']??null)==='fixture-599'),
        'Repair or unrelated retry changed/reconnected the existing retained account');
    // A cancelled prepared operation can require real cleanup before an update.
    // Validate the replacement first: invalid replacement input must not
    // disconnect/remove the already working account or freeze reconnect intent.
    foreach([605=>'192.0.2.205',606=>'192.0.2.216'] as $id=>$address) {
        $apiSqliteCache->insert(['id'=>$id,'clientId'=>71,'device'=>21,'planId'=>1,'status'=>1,'username'=>'fixture-'.$id,
            'password'=>'fixture','uploadSpeed'=>7,'downloadSpeed'=>21],'services');
        $apiSqliteCache->insert(['id'=>$id,'address'=>$address],'network');
        $apiSqlite->insert(['id'=>$id,'address'=>$address],'network');
        repairBatchCheck((new Batch())->set_accounts([$id]),'Cleanup preflight fixture did not start');
    }
    $old605=$apiJobQueue->appliedSnapshot(605);$old605Key=$apiJobQueue->enqueue($old605);
    repairBatchCheck($apiJobQueue->delete([$old605Key])[$old605Key]===true,'Cleanup preflight source did not cancel');
    $apiSqlite->insert(['id'=>590,'address'=>'PRIVATE-BLOCKING-PREFLIGHT','address6'=>'PRIVATE-UNUSED-IPV6'],'network');
    $apiSqliteCache->edit(['id'=>605,'address'=>'PRIVATE-INVALID-REPLACEMENT'],'network');
    $beforePreflight=repairBatchState();
    $database=new SQLite3('data/jobs.db',SQLITE3_OPEN_READONLY);
    $cleanupBefore=$database->querySingle('SELECT group_concat(payload) FROM cancellation_cleanups WHERE service_id=605');$database->close();
    repairBatchCheck(!(new Batch())->set_accounts([605]),'Invalid replacement reservation bypassed cleanup preflight');
    $database=new SQLite3('data/jobs.db',SQLITE3_OPEN_READONLY);
    $cleanupAfter=$database->querySingle('SELECT group_concat(payload) FROM cancellation_cleanups WHERE service_id=605');
    $pending605=json_decode($database->querySingle("SELECT payload FROM jobs WHERE service_id=605 AND status='pending' ORDER BY id DESC LIMIT 1"),true);$database->close();
    repairBatchCheck($cleanupAfter===$cleanupBefore && $apiSqlite->selectAllFromTable('network')===$beforePreflight['network']
        && MT::$writes===$beforePreflight['writes'] && $apiJobQueue->appliedSnapshot(605)===$old605
        && !isset($pending605['_commands']) && !isset($pending605['_force_reconnect']),
        'Failed preflight wrote/disconnected RouterOS, changed reservations/debt/applied ownership or froze reconnect work');
    // Correcting the replacement allows ordinary cleanup/rebuild while malformed
    // historical peer rows remain visible and harmless to this service.
    $apiSqliteCache->edit(['id'=>605,'address'=>'192.0.2.205'],'network');
    repairBatchCheck((new Batch())->set_accounts([605]) && !$apiJobQueue->hasUnreconciled(605)
        && $apiSqlite->selectAddress(605)==='192.0.2.205' && $apiSqlite->selectAddress(590)==='PRIVATE-BLOCKING-PREFLIGHT',
        'Malformed peer history blocked normal cleanup after the replacement itself was corrected');
    // Ordinary known-IP suspension has no cleanup and still works while the
    // unrelated malformed rows remain. Its unused IPv6 family is not scanned.
    $apiSqliteCache->edit(['id'=>606,'status'=>3],'services');
    repairBatchCheck((new Batch())->set_accounts([606]) && $apiJobQueue->appliedSnapshot(606)['status']===3,
        'Cleanup guard spread into unrelated known-address billing work');
    // Identity migrations queue an explicit delete prerequisite. The same
    // validation must happen before that delete is even enqueued, rather than
    // only when its replacement update eventually reaches the worker.
    $apiSqlite->edit(['id'=>590,'address'=>'PRIVATE-BLOCKING-MIGRATION'],'network');
    $apiSqliteCache->edit(['id'=>606,'username'=>'fixture-606-replacement'],'services');
    $apiSqliteCache->edit(['id'=>606,'address'=>'PRIVATE-INVALID-MIGRATION'],'network');
    $migrationBefore=repairBatchState();$applied606=$apiJobQueue->appliedSnapshot(606);
    $database=new SQLite3('data/jobs.db',SQLITE3_OPEN_READONLY);
    $migrationJobsBefore=$database->querySingle('SELECT count(*) FROM jobs WHERE service_id=606');$database->close();
    repairBatchCheck(!(new Batch())->set_accounts([606]),'Migration queued a delete despite an invalid replacement context');
    $database=new SQLite3('data/jobs.db',SQLITE3_OPEN_READONLY);
    $migrationJobsAfter=$database->querySingle('SELECT count(*) FROM jobs WHERE service_id=606');$database->close();
    repairBatchCheck($migrationJobsAfter===$migrationJobsBefore && MT::$writes===$migrationBefore['writes']
        && $apiSqlite->selectAllFromTable('network')===$migrationBefore['network'] && $apiJobQueue->appliedSnapshot(606)===$applied606,
        'Migration validation left a runnable delete, changed reservations, or touched the current account');
    $explicitReplacement=$applied606;unset($explicitReplacement['_commands'],$explicitReplacement['_cleanup'],$explicitReplacement['_delete_frozen']);
    $explicitReplacement['username']='fixture-606-explicit-replacement';
    $explicitReplacement['address']='PRIVATE-INVALID-EXPLICIT-REPLACEMENT';
    try {(new Batch())->migrate($applied606,$explicitReplacement);throw new RuntimeException('Explicit migration bypassed validation');}
    catch(InvalidNetworkAddress $expected){repairBatchCheck($expected->context()['blockingServiceId']===606
        && $expected->context()['addressSource']==='input','Explicit migration lost its invalid replacement context');}
    $database=new SQLite3('data/jobs.db',SQLITE3_OPEN_READONLY);
    repairBatchCheck($database->querySingle('SELECT count(*) FROM jobs WHERE service_id=606')===$migrationJobsBefore
        && MT::$writes===$migrationBefore['writes'] && $apiJobQueue->appliedSnapshot(606)===$applied606,
        'Explicit migration queued or applied its old-account deletion before validation');$database->close();
    $apiSqliteCache->edit(['id'=>606,'address'=>'192.0.2.216'],'network');
    repairBatchCheck((new Batch())->set_accounts([606])
        && $apiJobQueue->appliedSnapshot(606)['username']==='fixture-606-replacement'
        && $apiSqlite->selectAddress(606)==='192.0.2.216',
        'Malformed peer history blocked normal ordered identity migration after valid replacement input');
    // Own malformed state remains an error when no valid desired IP replaces it.
    $apiSqliteCache->insert(['id'=>607,'clientId'=>71,'device'=>21,'planId'=>1,'status'=>1,'username'=>'fixture-607',
        'password'=>'fixture','uploadSpeed'=>7,'downloadSpeed'=>21],'services');
    $apiSqlite->insert(['id'=>607,'address'=>'PRIVATE-OWN-MALFORMED'],'network');
    $ownStart=count(MT::$writes);
    repairBatchCheck(!(new Batch())->set_accounts([607]) && count(MT::$writes)===$ownStart
        && $apiSqlite->selectAddress(607)==='PRIVATE-OWN-MALFORMED','Own invalid reservation was silently discarded or written to RouterOS');
    $ownJobs=array_values(array_filter($apiJobQueue->list(),static fn($row)=>(int)$row['id']===607));
    repairBatchCheck(count($ownJobs)===1 && $ownJobs[0]['error']==='invalid_network_address: requested_service=607 blocking_service=607 source=reserved family=IPv4',
        'Own invalid reservation lost its actionable service-local failure');
    // A real collision throws RuntimeException before migration jobs exist.
    // Its service must fail locally while the next customer still synchronizes.
    $safe606=$apiJobQueue->appliedSnapshot(606);$mixedStart=count(MT::$writes);
    $apiSqliteCache->edit(['id'=>606,'username'=>'fixture-606-conflicting-replacement'],'services');
    $apiSqliteCache->edit(['id'=>606,'address'=>'192.0.2.62'],'network');
    $apiSqliteCache->edit(['id'=>601,'status'=>3],'services');
    $mixed=new Batch();
    repairBatchCheck(!$mixed->set_accounts([606,601]) && $mixed->outcomes()['failed']===1 && $mixed->outcomes()['completed']===1
        && $mixed->outcomes()['failures'][0]['errorCode']==='network_address_conflict'
        && $apiJobQueue->appliedSnapshot(606)===$safe606 && $apiJobQueue->appliedSnapshot(601)['status']===3
        && !array_filter(array_slice(MT::$writes,$mixedStart),static fn($row)=>
            in_array($row['command']['name']??null,['fixture-606-conflicting-replacement','fixture-606-replacement'],true)),
        'One failed migration aborted the following service or changed the protected existing account');
    echo "Network repair Batch passed: malformed peer history stays reviewable without blocking unrelated sync; explicit and automatic local correction preserve queue/RouterOS ownership; own invalid state and invalid migration replacement stop before cleanup writes; valid historical reservations remain protected.\n";
} finally {
    if(isset($apiJobQueue)) $apiJobQueue->close(); if(isset($apiSqlite)) $apiSqlite->close(); if(isset($apiSqliteCache)) $apiSqliteCache->close();
    chdir($original); set_include_path($originalInclude);
    $resolved=realpath($directory); $temporary=realpath(sys_get_temp_dir());
    if($resolved && $temporary && strpos($resolved,$temporary.DIRECTORY_SEPARATOR.'uisp-repair-batch-')===0) {
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($iterator as $file) $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname()); rmdir($resolved);
    }
}
