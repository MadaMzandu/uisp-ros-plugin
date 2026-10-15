<?php
// Exact read-only RouterOS fixtures; this suite never opens a socket or changes a service.
require_once __DIR__ . '/../src/lib/api_job_recovery_observation.php';
require_once __DIR__ . '/../src/lib/api_job_recovery.php';
require_once __DIR__ . '/../src/lib/api_jobs_status.php';
set_error_handler(static function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line); return false;
});
function recoveryObservationCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function recoveryObservationCanonical(array $value): string { ksort($value); foreach ($value as $key => $entry) if (is_array($entry)) $value[$key] = json_decode(recoveryObservationCanonical($entry), true); return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
function recoveryObservationIdentity(array $snapshot): string {
    return hash('sha256', recoveryObservationCanonical(['device' => $snapshot['device'], 'ip' => $snapshot['_device']['ip'],
        'port' => $snapshot['_device']['port'] ?? null, 'username' => $snapshot['username'] ?? null,
        'mac' => $snapshot['mac'] ?? null, 'hotspot' => $snapshot['hotspot'] ?? null, 'duid' => $snapshot['duid'] ?? null, 'iaid' => $snapshot['iaid'] ?? null]));
}
$directory = sys_get_temp_dir() . '/uisp-recovery-observation-' . bin2hex(random_bytes(8)); mkdir($directory, 0700);
$data = $directory . '/data.db'; $jobs = $directory . '/jobs.db'; $legacy = $directory . '/queue.json';
$database = new SQLite3($data); $database->enableExceptions(true);
$database->exec("CREATE TABLE devices(id INTEGER PRIMARY KEY,ip TEXT,port INTEGER,user TEXT,password TEXT,ssl INTEGER,allowSelfSigned INTEGER,type TEXT);
    INSERT INTO devices VALUES(7,'192.0.2.7',8728,'PRIVATE_ROUTER_USER','PRIVATE_ROUTER_PASSWORD',0,0,'mikrotik')");
$device = ['id'=>7,'ip'=>'192.0.2.7','port'=>8728,'user'=>'PRIVATE_ROUTER_USER','password'=>'STALE_PRIVATE_ROUTER_PASSWORD','ssl'=>0,'allowSelfSigned'=>0];
$snapshot = ['id'=>701,'clientId'=>81,'device'=>7,'status'=>1,'username'=>'fixture-account',
    '_device'=>$device, '_commands'=>[['path'=>'/ppp/secret','name'=>'fixture-account','password'=>'PRIVATE_ACCOUNT_PASSWORD', 'remote-address'=>'10.77.0.11']]];
$mode = 'absent'; $calls = []; $time = 1800000000;
$reader = static function (array $router, string $path, array $filter) use (&$mode, &$calls, &$time): array {
    recoveryObservationCheck($router['password']==='PRIVATE_ROUTER_PASSWORD','Retired transport password was reused');
    recoveryObservationCheck(count($filter)===1 && !in_array('', $filter, true),'A broad query was issued');
    $calls[] = [$router['id'], $path, $filter];
    if ($mode==='offline') throw new RuntimeException('PRIVATE_ROUTER_PASSWORD unreachable');
    if ($mode==='present') return [['.id'=>'*A']+$filter];
    if ($mode==='session' && $path==='ppp/active') return [['.id'=>'*B']+$filter];
    if ($mode==='foreign') return [['.id'=>'*B','name'=>'unrelated-account']];
    if ($mode==='trap') return ['!trap'=>[['message'=>'PRIVATE_DENIED']]];
    if ($mode==='invalid_id') return [['.id'=>'invalid']+$filter];
    if ($mode==='budget') $time += 13;
    return [];
};
$clock = static function () use (&$time): int { return $time; };
$observer = new ApiJobRecoveryObservation($data, $reader, $clock);
$queue = null;
try {
    $before = hash_file('sha256', $data);
    $result = $observer->inspect([$snapshot]);
    recoveryObservationCheck($result['safe'] && $result['reason']==='observed_absent_resources'
        && array_column($result['checks'],'path')===['/ppp/secret','/ppp/active']
        && array_column($result['checks'],'state')===['absent','absent'],'Principal and session absence was not observed');
    recoveryObservationCheck(hash_file('sha256',$data)===$before,'Observation wrote the device database');
    foreach (['PRIVATE_ROUTER','PRIVATE_ACCOUNT_PASSWORD','STALE_PRIVATE','192.0.2.7'] as $secret)
        recoveryObservationCheck(strpos(json_encode($result),$secret)===false,'Public observation exposed credentials or transport');
    recoveryObservationCheck($result['checks'][0]['selector_label']==='fixture-account','Operator has no exact resource label');
    foreach (['present'=>'router_resources_present','session'=>'router_resources_present',
        'offline'=>'router_observation_unavailable','foreign'=>'router_observation_unavailable',
        'trap'=>'router_observation_unavailable','invalid_id'=>'router_observation_unavailable',
        'budget'=>'router_observation_limit'] as $mode=>$reason) {
        $time=1800000000; $result=$observer->inspect([$snapshot]);
        recoveryObservationCheck(!$result['safe'] && $result['reason']===$reason,'Unsafe router result accepted: ' . $mode);
    }
    $mode='absent'; $time=1800000000; $calls=[];
    $changed=$snapshot; $changed['_device']['ip']='192.0.2.8'; $result=$observer->inspect([$changed]);
    recoveryObservationCheck(!$result['safe'] && $result['reason']==='router_authority_changed' && !$calls,'Retired router authority was guessed');
    $changed=$snapshot; $changed['_device']['user']='changed-user'; $result=$observer->inspect([$changed]);
    recoveryObservationCheck(!$result['safe'] && $result['reason']==='router_authority_changed' && !$calls,'Changed API user was accepted');
    $changed=$snapshot; unset($changed['_commands']); $result=$observer->inspect([$changed]);
    recoveryObservationCheck(!$result['safe'] && $result['reason']==='router_snapshot_incomplete'
        && array_column($result['checks'],'state')===['absent','absent'],'Missing commands became absence or lost principal diagnostics');
    $calls=[];
    $changed=$snapshot; $changed['_commands'][0]['path']='/system/reboot'; $result=$observer->inspect([$changed]);
    recoveryObservationCheck(!$result['safe'] && $result['reason']==='router_snapshot_incomplete' && !$calls,'Unknown resource path was queried');
    $changed=$snapshot; $changed['_commands'][0]['name']='foreign'; $result=$observer->inspect([$changed]);
    recoveryObservationCheck(!$result['safe'] && !$calls,'Account identity mismatch was queried');
    $changed=$snapshot; $changed['_commands'][0]['action']='execute'; $result=$observer->inspect([$changed]);
    recoveryObservationCheck(!$result['safe'] && !$calls,'Unknown mutation semantics were accepted');
    $changed=$snapshot; $changed['_commands'][0]['name']="bad\nname"; $result=$observer->inspect([$changed]);
    recoveryObservationCheck(!$result['safe'] && !$calls,'Control characters were accepted');
    $result=$observer->inspect(array_fill(0,13,$snapshot));
    recoveryObservationCheck(!$result['safe'] && $result['reason']==='router_observation_limit','Snapshot limit was ignored');
    $changed=$snapshot; $changed['_commands']=array_fill(0,61,$snapshot['_commands'][0]); $result=$observer->inspect([$changed]);
    recoveryObservationCheck(!$result['safe'],'Command limit was ignored');

    // Managed profiles may survive a display rename under an ownership selector.
    $changed=$snapshot; $changed['_commands'][]=['path'=>'/ppp/profile','name'=>'fixture-profile','_owner'=>'uisp-ros-plugin:plan:5'];
    $profileReader=static function(array $router,string $path,array $filter) use($reader):array {
        if ($path==='ppp/profile' && isset($filter['comment'])) return [['.id'=>'*C']+$filter];
        return $reader($router,$path,$filter);
    };
    $result=(new ApiJobRecoveryObservation($data,$profileReader,$clock))->inspect([$changed]);
    recoveryObservationCheck(!$result['safe'] && $result['reason']==='router_resources_present'
        && in_array('owner',array_column($result['checks'],'selector_type'),true),'A renamed shared profile was inferred absent');
    $changed=$snapshot; $changed['username']='fixture-hotspot'; $changed['_commands']=[['path'=>'/ip/hotspot/user','name'=>'fixture-hotspot']];
    $calls=[]; $result=$observer->inspect([$changed]);
    recoveryObservationCheck($result['safe'] && $calls[1][2]===['user'=>'fixture-hotspot'],'Hotspot active used the wrong exact field');
    $changed=$snapshot; $changed['mac']='02:00:00:00:00:01'; $changed['_commands']=[['path'=>'/ip/dhcp-server/lease','mac-address'=>$changed['mac']]];
    $result=$observer->inspect([$changed]); recoveryObservationCheck($result['safe'] && $result['checks'][0]['selector_type']==='mac','DHCP identity was not exact');
    $changed=$snapshot; $changed['duid']='00010001aabbccdd';$changed['iaid']=4;
    $changed['_commands']=[['path'=>'/ipv6/dhcp-server/binding','duid'=>'0x00010001aabbccdd','iaid'=>4]];
    $result=$observer->inspect([$changed]); recoveryObservationCheck($result['safe'] && $result['checks'][0]['selector_type']==='duid','DHCPv6 identity was not exact');
    $changed['duid']='00010001aabbccee'; recoveryObservationCheck(!$observer->inspect([$changed])['safe'],'DHCPv6 command DUID did not match snapshot identity');
    $changed['duid']='00010001aabbccdd';$changed['iaid']=9; recoveryObservationCheck(!$observer->inspect([$changed])['safe'],'DHCPv6 command IAID did not match snapshot identity');

    // Serialized RouterOS words must include a valid terminal acknowledgement;
    // unknown control words, traps and malformed records never mean absence.
    $decode=new ReflectionMethod(ApiJobRecoveryObservation::class,'decodePrint');
    recoveryObservationCheck($decode->invoke($observer,['!done'],['name'=>'fixture-account'])===[],'Valid empty print failed');
    recoveryObservationCheck($decode->invoke($observer,['!empty','!done'],['name'=>'fixture-account'])===[],'Documented empty reply failed');
    foreach ([['!bogus','!done'],['!trap','=message=denied','!done'],['!fatal','!done'],[],['!empty'],
        ['!re','!done'],['!re','=.id=*A','!done'],['!done','!done'],
        ['!re','=.id=*A','=name=fixture-account','=name=foreign','!done'],
        ['!re','=.id=*A','=name=fixture-account','=password=PRIVATE','!done']] as $words) {
        try {$decode->invoke($observer,$words,['name'=>'fixture-account']); throw new RuntimeException('Malformed RouterOS receipt became absence');}
        catch(InvalidArgumentException $expected) {recoveryObservationCheck($expected->getMessage()==='router_observation_unavailable','Wrong malformed receipt result');}
    }

    // Missing applied ownership becomes actionable only after fresh UISP and all
    // retained resources are absent. Apply observes again under the real guard.
    $queue=new SQLite3($jobs); $queue->enableExceptions(true);
    $queue->exec('CREATE TABLE jobs(id INTEGER PRIMARY KEY,service_id INTEGER,status TEXT,version INTEGER,payload TEXT,updated TEXT,error TEXT);
        CREATE TABLE cancellation_cleanups(id INTEGER PRIMARY KEY,source_job_id INTEGER,service_id INTEGER,source_version INTEGER,slot TEXT,payload TEXT,identity TEXT);
        CREATE TABLE service_versions(service_id INTEGER PRIMARY KEY,version INTEGER,applied_identity TEXT,applied_payload TEXT);
        CREATE TABLE service_event_locks(service_id INTEGER PRIMARY KEY); CREATE TABLE queue_meta(key TEXT PRIMARY KEY,value TEXT);');
    $identity=recoveryObservationIdentity($snapshot);
    $insert=static function(string $table,array $values) use($queue):void {
        $statement=$queue->prepare('INSERT INTO '.$table.'('.implode(',',array_keys($values)).') VALUES('.implode(',',array_fill(0,count($values),'?')).')');
        foreach(array_values($values) as $key=>$value) $statement->bindValue($key+1,$value,is_int($value)?SQLITE3_INTEGER:($value===null?SQLITE3_NULL:SQLITE3_TEXT));
        $statement->execute()->finalize();$statement->close();
    };
    $seed=static function() use($queue,$insert,$snapshot,$identity):void {
        $queue->exec('DELETE FROM jobs;DELETE FROM cancellation_cleanups;DELETE FROM service_versions;DELETE FROM queue_meta;DELETE FROM service_event_locks');
        $insert('jobs',['id'=>17,'service_id'=>701,'status'=>'cancelled','version'=>3,'payload'=>json_encode($snapshot),'updated'=>'2026-10-14T00:00:00Z']);
        $insert('cancellation_cleanups',['id'=>31,'source_job_id'=>17,'service_id'=>701,'source_version'=>3,'slot'=>'commands','payload'=>json_encode($snapshot),'identity'=>$identity]);
        $insert('service_versions',['service_id'=>701,'version'=>3,'applied_payload'=>null,'applied_identity'=>null]);
        $insert('queue_meta',['key'=>'cancellation_cleanups_seeded_v1','value'=>'fixture']);
    };
    $service=['id'=>701,'clientId'=>81,'status'=>1]; $client=['id'=>81,'firstName'=>'Synthetic','isArchived'=>false];
    $request=static function(string $path) use(&$service,&$client) {
        if ($path==='clients/services/701') return $service;
        if ($path==='clients/81') return $client; throw new RuntimeException('Unexpected UISP read');
    };
    $api=new ApiJobRecovery($request,$jobs,$data,$legacy,$clock,null,$reader);
    $seed(); $before=hash_file('sha256',$jobs); $calls=[]; $preview=$api->preview(17);
    recoveryObservationCheck(is_string($preview['previewToken']) && $preview['candidate']['resolution']==='observed_absent_resources'
        && $preview['candidate']['crm_status']===1 && count($preview['candidate']['checks'])===2,'Missing applied ownership remained an opaque dead end');
    recoveryObservationCheck(hash_file('sha256',$jobs)===$before,'Preview changed recovery ownership');
    $callsBefore=count($calls); $result=$api->apply($preview['previewToken']);
    recoveryObservationCheck(count($calls)>$callsBefore && $result['resolution']==='observed_absent_resources'
        && $result['router_changes']===false && $result['crm_changes']===false
        && $queue->querySingle('SELECT status FROM jobs WHERE id=17')==='cleaned'
        && (int)$queue->querySingle('SELECT count(*) FROM cancellation_cleanups')===0,'Fresh absence did not safely retire exact source');
    recoveryObservationCheck($queue->querySingle('SELECT applied_payload FROM service_versions WHERE service_id=701')===null,'Applied state was altered');
    $backup=new SQLite3($directory.'/'.$result['backup_name'],SQLITE3_OPEN_READONLY);
    recoveryObservationCheck($backup->querySingle('SELECT status FROM jobs WHERE id=17')==='cancelled'
        && (int)$backup->querySingle('SELECT count(*) FROM cancellation_cleanups')===1,'Backup omitted pre-change source/debt'); $backup->close();
    recoveryObservationCheck((new ApiJobsStatus($jobs,$legacy))->read()['total']===0,'Counter remained after observed recovery');

    $seed(); $mode='present'; $result=$api->preview(17);
    recoveryObservationCheck($result['previewToken']===null && $result['blocked'][0]['reason']==='router_resources_present'
        && $result['blocked'][0]['checks'][0]['state']==='present','Present resources had no actionable protected diagnosis');
    $mode='absent'; $seed(); $preview=$api->preview(17); $mode='session';
    try { $api->apply($preview['previewToken']); throw new RuntimeException('A new live session was ignored'); }
    catch(InvalidArgumentException $expected) { recoveryObservationCheck($expected->getMessage()==='router_resources_present','Unexpected router race result'); }
    recoveryObservationCheck((int)$queue->querySingle('SELECT count(*) FROM cancellation_cleanups')===1,'Router drift discarded debt');
    $mode='absent'; $seed(); $preview=$api->preview(17); $service['clientId']=82;
    try { $api->apply($preview['previewToken']); throw new RuntimeException('CRM identity drift accepted'); }
    catch(InvalidArgumentException $expected) { recoveryObservationCheck($expected->getMessage()==='crm_identity_changed','Unexpected CRM race result'); }
    $service['clientId']=81;
    $seed(); $service['status']=2; $preview=$api->preview(17);
    recoveryObservationCheck(is_string($preview['previewToken']) && $preview['candidate']['crm_status']===2,'Ended service observation was blocked unnecessarily'); $service['status']=1;
    $seed(); $partial=$snapshot; unset($partial['_commands']);
    $statement=$queue->prepare('UPDATE cancellation_cleanups SET payload=?');$statement->bindValue(1,json_encode($partial));$statement->execute()->finalize();$statement->close();
    $result=$api->preview(17); recoveryObservationCheck($result['previewToken']===null && $result['blocked'][0]['reason']==='router_snapshot_incomplete'
        && count($result['blocked'][0]['checks'])===2,'Legacy auxiliary uncertainty was hidden or certified absent');
    $seed(); $partial=$snapshot; $partial['_prior_network']=['address'=>'10.77.0.99']; $partial['_prior_identity']=$identity;
    $statement=$queue->prepare('UPDATE jobs SET payload=? WHERE id=17');$statement->bindValue(1,json_encode($partial));$statement->execute()->finalize();$statement->close();
    $result=$api->preview(17); recoveryObservationCheck($result['previewToken']===null && $result['blocked'][0]['reason']==='router_snapshot_incomplete'
        && $result['blocked'][0]['checks']===[] && $result['blocked'][0]['diagnostics']===['stage'=>'prior_reservations',
            'issues'=>[['code'=>'uncovered_prior_address','snapshot_slot'=>'source','family'=>'IPv4']]],'Unobserved prior reservation was released or missing-history diagnostic remained opaque');
    $calls=[]; $preserve=$api->preview(17,'preserve');
    recoveryObservationCheck(is_string($preserve['previewToken']) && $preserve['candidate']['resolution']==='retained_resources'
        && !$calls,'Missing prior history had no explicit non-mutating preservation path');
    $seed(); $partial=$snapshot; $partial['_prior_network']=['address'=>'10.77.0.11']; $partial['_prior_identity']='unmatched-old-identity';
    $statement=$queue->prepare('UPDATE jobs SET payload=? WHERE id=17');$statement->bindValue(1,json_encode($partial));$statement->execute()->finalize();$statement->close();
    recoveryObservationCheck($api->preview(17)['previewToken']===null,'Unobserved prior account identity was released');
    // A different identity containing the desired address cannot cover this
    // cancelled source's older reservation.
    $seed(); $partial=$snapshot; $partial['_prior_network']=['address'=>'10.77.0.99']; $partial['_prior_identity']=$identity;
    $statement=$queue->prepare('UPDATE jobs SET payload=? WHERE id=17');$statement->bindValue(1,json_encode($partial));$statement->execute()->finalize();$statement->close();
    $foreign=$snapshot; $foreign['username']='other-fixture-account';$foreign['_commands'][0]['name']=$foreign['username'];$foreign['_commands'][0]['remote-address']='10.77.0.99';
    $insert('cancellation_cleanups',['id'=>32,'source_job_id'=>17,'service_id'=>701,'source_version'=>3,'slot'=>'applied','payload'=>json_encode($foreign),'identity'=>recoveryObservationIdentity($foreign)]);
    recoveryObservationCheck($api->preview(17)['previewToken']===null,'A foreign identity covered another prior reservation');
    $seed(); $partial=$snapshot; $partial['_prior_network']=['address'=>'10.77.0.99'];$partial['_prior_identity']=$identity;
    $statement=$queue->prepare('UPDATE cancellation_cleanups SET payload=?');$statement->bindValue(1,json_encode($partial));$statement->execute()->finalize();$statement->close();
    recoveryObservationCheck($api->preview(17)['previewToken']===null,'A retained row released an unobserved prior reservation');
    foreach (['jobs','cancellation_cleanups'] as $table) {
        $seed(); $partial=$snapshot; $partial['_previous_addresses']=['10.77.0.99','2001:db8:99::/64'];
        $statement=$queue->prepare('UPDATE '.$table.' SET payload=?');$statement->bindValue(1,json_encode($partial));$statement->execute()->finalize();$statement->close();
        $calls=[]; $heldPreview=$api->preview(17);
        recoveryObservationCheck($heldPreview['previewToken']===null && $heldPreview['blocked'][0]['reason']==='router_snapshot_incomplete'
            && $calls===[], 'An address-only previous hold was released without an actual observable resource');
    }
    $seed(); $partial=$snapshot; $partial['_previous_addresses']=['10.77.0.11'];
    $statement=$queue->prepare('UPDATE jobs SET payload=? WHERE id=17');$statement->bindValue(1,json_encode($partial));$statement->execute()->finalize();$statement->close();
    recoveryObservationCheck(is_string($api->preview(17)['previewToken']), 'An old hold covered by the exact observed absent principal could not be resolved');
    $seed(); $preview=$api->preview(17); $database->exec("UPDATE devices SET password='NEW_PRIVATE_PASSWORD' WHERE id=7");
    try { $api->apply($preview['previewToken']); throw new RuntimeException('Credential/config drift accepted'); }
    catch(InvalidArgumentException $expected) { recoveryObservationCheck(in_array($expected->getMessage(),['recovery_state_changed','router_observation_unavailable'],true),'Unexpected device race result'); }
    recoveryObservationCheck((int)$queue->querySingle('SELECT count(*) FROM cancellation_cleanups')===1,'Device drift discarded debt');
    echo "Job recovery observations regression passed\n";
} finally {
    if($queue!==null) $queue->close(); $database->close();
    $remove=static function(string $path) use(&$remove):void { if(is_dir($path)&&!is_link($path)){foreach(scandir($path) as $name)if($name!=='.'&&$name!=='..')$remove($path.'/'.$name);rmdir($path);}else unlink($path);};
    $remove($directory); restore_error_handler();
}
