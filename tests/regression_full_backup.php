<?php
// Full snapshots and restoration use disposable local files; no CRM/router transport.
set_error_handler(static function($severity,$message,$file,$line) {
    if (error_reporting()&$severity) throw new ErrorException($message,0,$severity,$file,$line); return false;
});
require_once __DIR__ . '/../src/lib/api_full_backup.php';
function fullCheck(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
function fullReject(callable $operation,string $message): void { try { $operation(); } catch (Throwable $error) { return; } throw new RuntimeException($message); }
function fullLogical(string $path): array {
    $db=new SQLite3($path,SQLITE3_OPEN_READONLY);$db->enableExceptions(true);$result=[];
    try {
        $schema=$db->query("SELECT name,sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        while($table=$schema->fetchArray(SQLITE3_ASSOC)) {
            $rows=$db->query('SELECT * FROM '.ApiSqlite::identifier($table['name']).' ORDER BY rowid');$values=[];
            while($row=$rows->fetchArray(SQLITE3_ASSOC))$values[]=$row;$rows->finalize();$result[$table['name']]=[$table['sql'],$values];
        }
        $schema->finalize();return$result;
    } finally{$db->close();}
}
function fullRemove(string $path): void {
    if (!is_dir($path)||is_link($path)) return;
    foreach(new FilesystemIterator($path,FilesystemIterator::SKIP_DOTS) as $entry) {
        if($entry->isDir()&&!$entry->isLink())fullRemove($entry->getPathname());else unlink($entry->getPathname());
    }
    rmdir($path);
}
$root=sys_get_temp_dir().'/uisp-full-backup-'.bin2hex(random_bytes(8));mkdir($root,0700);$data=$root.'/data';mkdir($data,0700);
$queue=null;$db=null;$now=time();
try {
    $db=new ApiSqlite($data.'/data.db');$db->exec(file_get_contents(__DIR__.'/../src/includes/schema.sql'));
    $db->saveConfig(['version'=>MY_VERSION,'device_name_attr'=>'site']);
    $db->insert(['id'=>1,'name'=>'Fixture Site','ip'=>'192.0.2.1','port'=>8728,'user'=>'PRIVATE-ROUTER-USER','password'=>'PRIVATE-ROUTER-PASSWORD'],'devices');
    $db->insert(['id'=>42,'clientId'=>17,'device'=>1,'planId'=>1,'status'=>1],'services');
    $db->insert(['id'=>42,'address'=>'192.0.2.42'],'network');
    $cache=new SQLite3($data.'/cache.db');$cache->exec(file_get_contents(__DIR__.'/../src/includes/cache.sql'));
    $cache->exec("INSERT INTO clients(id,firstName,lastName,isArchived) VALUES(17,'Fixture','Client',0)");$cache->close();
    $queue=new ApiJobQueue($data.'/jobs.db',null);
    $queue->deferFreshReview(46,'webhook_state_unconfirmed');
    $snapshot=['id'=>42,'clientId'=>17,'device'=>1,'status'=>1,'username'=>'fixture-account','address'=>'192.0.2.42',
        'password'=>'PRIVATE-ACCOUNT-PASSWORD','_device'=>['id'=>1,'ip'=>'192.0.2.1','port'=>8728,'user'=>'PRIVATE-ROUTER-USER','password'=>'PRIVATE-ROUTER-PASSWORD'],
        '_commands'=>[['path'=>'/ppp/secret','name'=>'fixture-account','comment'=>'17 - Fixture - 42','remote-address'=>'192.0.2.42',
            'password'=>'PRIVATE-ACCOUNT-PASSWORD','_service_id'=>42,'_client_id'=>17]]];
    $applied=$queue->enqueue($snapshot);$queue->runSelected([$applied],static fn()=>true);
    $pending=$queue->enqueue(array_replace($snapshot,['id'=>43,'address'=>'192.0.2.43']));
    $cancelled=$queue->enqueue(array_replace($snapshot,['id'=>44,'address'=>'192.0.2.44']));$queue->delete([$cancelled]);
    file_put_contents($data.'/config.json',json_encode(['webhookSecret'=>'PRIVATE-WEBHOOK-SECRET','debugEnable'=>false]));
    file_put_contents($data.'/state.json',json_encode(['backup'=>gmdate('c'),'cleanup'=>gmdate('c')]));
    file_put_contents($data.'/provisioning-verification-42.json',json_encode(['fixture'=>'saved evidence']));
    file_put_contents($data.'/crm-network-history-42-address.json',json_encode(['fixture'=>'historical exclusion']));
    $resolutionBackup='router-resolution-20261016T130000Z-0123456789abcdef.json';
    file_put_contents($data.'/'.$resolutionBackup,json_encode(['rows'=>[['password'=>'PRIVATE-REMOVED-ACCOUNT']]]));
    require_once __DIR__.'/../src/lib/api_site_address_policy.php';
    $oldDevice=$db->singleQuery('SELECT * FROM devices WHERE id=1',true);
    $oldDevice['pool']='198.51.100.0/24';
    ApiSiteAddressPolicy::recordPoolChange($oldDevice,array_replace($oldDevice,['pool'=>'198.18.0.0/24']),$db);
    file_put_contents($data.'/plugin.log','PRIVATE-LOG');file_put_contents($root.'/ucrm.json','PRIVATE-HOST-SDK');
    $beforeData=fullLogical($data.'/data.db');$beforeJobs=fullLogical($data.'/jobs.db');$beforeCache=fullLogical($data.'/cache.db');
    $api=new ApiFullBackup($data,static function()use(&$now){return(float)$now;});
    $created=$api->create();fullCheck(ApiFullBackup::backupName($created['name'])&&$created['summary']['pendingJobs']===1,'Complete snapshot omitted queue counts');
    $zip=new ZipArchive();fullCheck($zip->open($data.'/full-backups/'.$created['name'])===true,'Snapshot ZIP unavailable');
    $manifest=json_decode($zip->getFromName('manifest.json'),true);
    fullCheck(isset($manifest['files']['data/jobs.db'],$manifest['files']['data/data.db'],$manifest['files']['data/cache.db'],
        $manifest['files']['data/config.json'],$manifest['files']['data/provisioning-verification-42.json'],$manifest['files']['data/crm-network-history-42-address.json'],
        $manifest['files']['data/'.$resolutionBackup])
        && $zip->locateName('data/plugin.log')===false&&$zip->locateName('ucrm.json')===false,'Snapshot omitted ownership/evidence or included logs/host integration');$zip->close();
    fullCheck(!str_contains(json_encode([$created,$api->listing()]),'PRIVATE'),'Public backup metadata exposed credentials');
    $db->execute("UPDATE devices SET name='Changed' WHERE id=1");$db->saveConfig(['device_name_attr'=>'changedSite']);
    $extra=$queue->enqueue(array_replace($snapshot,['id'=>45,'address'=>'192.0.2.45']));
    file_put_contents($data.'/config.json',json_encode(['webhookSecret'=>'CHANGED-SECRET']));
    file_put_contents($data.'/provisioning-verification-999.json',json_encode(['fixture'=>'newer evidence']));
    $preview=$api->preview($created['name']);
    fullCheck(is_string($preview['previewToken'])&&$preview['candidate']['pendingJobs']===1&&$preview['current']['pendingJobs']===2
        &&$preview['preservedHostIntegration']&&!str_contains(json_encode($preview),'PRIVATE'),'Restore preview omitted differences or disclosed private fields');
    $restored=$api->apply($preview['previewToken']);
    fullCheck($restored['restored']&&$restored['jobsMayResumeOnNextScheduledRun']&&!$restored['routerChanges']&&!$restored['crmChanges'], 'Restore failed to report no external operations');
    fullCheck(fullLogical($data.'/data.db')===$beforeData&&fullLogical($data.'/jobs.db')===$beforeJobs&&fullLogical($data.'/cache.db')===$beforeCache,
        'Complete restore lost local settings, applied ownership, jobs or cache');
    fullCheck(!is_file($data.'/provisioning-verification-999.json')&&file_get_contents($root.'/ucrm.json')==='PRIVATE-HOST-SDK'
        &&file_get_contents($data.'/plugin.log')==='PRIVATE-LOG','Restore retained mismatched evidence or replaced host/log files');
    fullCheck(is_file($data.'/full-backups/'.$restored['backupName'])&&!is_file($data.'/full-backup-restore.json'),'Before-restore backup or committed journal cleanup missing');
    fullReject(static fn()=>$api->apply($preview['previewToken']),'Restore token was reusable');

    // Fresh previews bind both the archive bytes and every current local record.
    $preview=$api->preview($created['name']);$db->saveConfig(['fixture_changed'=>'true']);
    fullReject(static fn()=>$api->apply($preview['previewToken']),'Changed live state did not invalidate preview');
    $preview=$api->preview($created['name']);$now+=601;
    fullReject(static fn()=>$api->apply($preview['previewToken']),'Expired preview was accepted');$now-=601;

    // Synchronous failures after a first replacement roll back every store.
    $db->saveConfig(['rollback_fixture'=>'must survive']);$rollbackData=fullLogical($data.'/data.db');$rollbackJobs=fullLogical($data.'/jobs.db');
    $failing=new ApiFullBackup($data,null,static function($file,$index){if($index===2)throw new RuntimeException('synthetic failure');});
    $preview=$failing->preview($created['name']);
    fullReject(static fn()=>$failing->apply($preview['previewToken']),'Injected mid-restore failure did not abort');
    fullCheck(fullLogical($data.'/data.db')===$rollbackData&&fullLogical($data.'/jobs.db')===$rollbackJobs&&!is_file($data.'/full-backup-restore.json'),
        'Failed restore did not roll back coherent original state');

    // An abrupt process exit leaves the private journal. The next entry point
    // must restore every previous file before setup or a queue can run.
    file_put_contents($data.'/config.json',json_encode(['webhookSecret'=>'PRIVATE-CRASH-BEFORE']));
    $beforeCrashConfig=file_get_contents($data.'/config.json');
    $preview=$api->preview($created['name']);
    $script=$root.'/crash-restore.php';
    file_put_contents($script,'<?php require '.var_export(realpath(__DIR__.'/../src/lib/api_full_backup.php'),true).';'
        .'$backup=new ApiFullBackup('.var_export($data,true).',null,static function($file,$index){if($index===2)exit(23);});'
        .'$backup->apply('.var_export($preview['previewToken'],true).');exit(9);');
    $process=proc_open([PHP_BINARY,'-n','-d','extension_dir='.ini_get('extension_dir'),'-d','extension=sqlite3','-d','extension=gmp','-d','extension=zip',$script],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fullCheck(is_resource($process),'Could not start interrupted restore fixture');fclose($pipes[0]);
    $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    fullCheck($exit===23&&$stdout===''&&$stderr===''&&is_file($data.'/full-backup-restore.json'),'Interrupted restore did not preserve its recovery journal');
    fullCheck(ApiFullBackup::recoverPending($data)===true&&!is_file($data.'/full-backup-restore.json')
        &&file_get_contents($data.'/config.json')===$beforeCrashConfig&&fullLogical($data.'/data.db')===$rollbackData
        &&fullLogical($data.'/jobs.db')===$rollbackJobs,'Startup recovery did not roll back all stores after a process exit');

    // Same file-lock gates as workers and IP attribute export block snapshots.
    $identity=realpath($data.'/jobs.db');if(PHP_OS_FAMILY==='Windows')$identity=strtolower($identity);
    $locks=dirname($identity).'/.uisp-queue-locks-'.hash('sha256',$identity);
    foreach([$locks.'/maintenance.lock',$data.'/ip-sync.lock',$data.'/setup.lock',$data.'/backup.lock']as$path){
        $guard=fopen($path,'c+b');flock($guard,LOCK_EX|LOCK_NB);
        fullReject(static fn()=>$api->create(),'Snapshot bypassed exclusive operational gate');flock($guard,LOCK_UN);fclose($guard);
    }
    $raw=new SQLite3($data.'/jobs.db');$raw->exec("UPDATE jobs SET status='running' WHERE status='pending'");$raw->close();
    fullReject(static fn()=>$api->create(),'Snapshot accepted active job ownership');
    $raw=new SQLite3($data.'/jobs.db');$raw->exec("UPDATE jobs SET status='pending' WHERE status='running'");$raw->close();

    // Uploaded or edited ZIPs never provide extraction paths or executable schemas.
    foreach(['../escape','data\\escape','data/plugin.log']as$entry){
        $bad='full-backup-20261015T000000Z-'.bin2hex(random_bytes(16)).'.zip';
        copy($data.'/full-backups/'.$created['name'],$data.'/full-backups/'.$bad);$zip=new ZipArchive();$zip->open($data.'/full-backups/'.$bad);$zip->addFromString($entry,'unsafe');$zip->close();
        fullReject(static fn()=>$api->preview($bad),'Unsafe archive path was accepted');
    }
    $bad='full-backup-20261015T000000Z-'.bin2hex(random_bytes(16)).'.zip';copy($data.'/full-backups/'.$created['name'],$data.'/full-backups/'.$bad);
    $zip=new ZipArchive();$zip->open($data.'/full-backups/'.$bad);$zip->addFromString('data/config.json','{}');$zip->close();
    fullReject(static fn()=>$api->preview($bad),'Mismatched file digest was accepted');
    $bad='full-backup-20261015T000000Z-'.bin2hex(random_bytes(16)).'.zip';copy($data.'/full-backups/'.$created['name'],$data.'/full-backups/'.$bad);
    $zip=new ZipArchive();$zip->open($data.'/full-backups/'.$bad);$future=$manifest;$future['pluginVersion']='999.0.0';
    $zip->addFromString('manifest.json',json_encode($future));$zip->close();
    fullReject(static fn()=>$api->preview($bad),'Future plugin version was accepted');
    foreach(['trigger','identity','missing_column','fresh_review_schema','fresh_review_payload']as$mutation){
        $bad='full-backup-20261015T000000Z-'.bin2hex(random_bytes(16)).'.zip';copy($data.'/full-backups/'.$created['name'],$data.'/full-backups/'.$bad);
        $zip=new ZipArchive();$zip->open($data.'/full-backups/'.$bad);$altered=$root.'/altered-jobs.db';file_put_contents($altered,$zip->getFromName('data/jobs.db'));
        $sql=new SQLite3($altered);
        if($mutation==='trigger')$sql->exec("CREATE TRIGGER unsafe AFTER INSERT ON jobs BEGIN DELETE FROM jobs; END");
        elseif($mutation==='missing_column')$sql->exec('ALTER TABLE jobs RENAME COLUMN fingerprint TO missing_fingerprint');
        elseif($mutation==='fresh_review_schema')$sql->exec('ALTER TABLE webhook_fresh_reviews RENAME COLUMN next_attempt TO missing_next_attempt');
        elseif($mutation==='fresh_review_payload')$sql->exec("UPDATE webhook_fresh_reviews SET last_error='PRIVATE-PAYLOAD'");
        else$sql->exec("UPDATE jobs SET payload='{\"id\":999}' WHERE id=1");$sql->close();
        $changed=$manifest;$changed['files']['data/jobs.db']=['size'=>filesize($altered),'sha256'=>hash_file('sha256',$altered)];
        $zip->addFile($altered,'data/jobs.db');$zip->addFromString('manifest.json',json_encode($changed));$zip->close();unlink($altered);
        fullReject(static fn()=>$api->preview($bad),'Unsafe database schema or conflicting service identity was accepted');
    }
    $bad='full-backup-20261015T000000Z-'.bin2hex(random_bytes(16)).'.zip';copy($data.'/full-backups/'.$created['name'],$data.'/full-backups/'.$bad);
    $zip=new ZipArchive();$zip->open($data.'/full-backups/'.$bad);$zip->setExternalAttributesName('data/config.json',ZipArchive::OPSYS_UNIX,0120777<<16);$zip->close();
    fullReject(static fn()=>$api->preview($bad),'Symlink archive entry was accepted');
    fullReject(static fn()=>$api->preview('../'.$created['name']),'Unsafe saved backup name was accepted');
    fullReject(static fn()=>$api->previewUpload(['error'=>UPLOAD_ERR_OK,'tmp_name'=>$data.'/full-backups/'.$created['name']]),'Arbitrary local path bypassed uploaded-file verification');

    // Invalid startup recovery stays fail-closed before any setup/provisioning.
    file_put_contents($data.'/full-backup-restore.json',json_encode(['schema'=>1,'work'=>'../../outside','previous'=>[],'files'=>[]]));
    fullReject(static fn()=>ApiFullBackup::recoverPending($data),'Unsafe recovery journal was accepted');
    fullCheck(is_file($data.'/full-backup-restore.json')&&fullLogical($data.'/data.db')===$rollbackData,'Invalid journal was erased or changed live settings');
    unlink($data.'/full-backup-restore.json');
    fullCheck(ApiFullBackup::recoverPending($data)===false,'Empty startup recovery initialized state');
    echo "Full backup passed: complete private snapshots, preview binding, no external writes, exact restore, rollback, operational gates, ZIP/schema/hash validation and fail-closed recovery.\n";
} finally { if($queue)$queue->close();if($db)$db->close();fullRemove($root); }
