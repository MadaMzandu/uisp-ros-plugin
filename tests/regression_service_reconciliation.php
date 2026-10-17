<?php
// Synthetic database snapshots and read transports. The sink captures queue submissions, never router writes.
set_error_handler(function($severity,$message,$file,$line) { if (error_reporting()&$severity) throw new ErrorException($message,0,$severity,$file,$line); return false; });
function reconciliationCheck(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
final class ReconciliationQueueSink {
    public array $locks=[];
    public function withServiceLock(int $sid,callable $operation): array { $this->locks[]=$sid; return $operation(); }
}
final class Batch {
    public static array $submissions=[];
    public static bool $collision=false;
    public static int $preflights=0;
    public function validateSiteAddressReplacement(array $entity,bool $releasesReservation=true): void { reconciliationCheck($releasesReservation===false,'Drift review discarded an existing reservation'); self::$preflights++; if (self::$collision) throw new RuntimeException('PRIVATE-COLLISION-DETAIL'); }
    public function queue_accounts(array $entities): array { self::$submissions[]=$entities; return ['Q901']; }
}
$reconciliationQueue=new ReconciliationQueueSink();
function myJobQueue(): ReconciliationQueueSink { global $reconciliationQueue; return $reconciliationQueue; }
$root=realpath(__DIR__.'/../src'); require_once $root.'/lib/api_service_reconciliation.php';
$directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'uisp-service-reconciliation-'.bin2hex(random_bytes(8)); mkdir($directory,0700);
$mainPath=$directory.'/data.db'; $cachePath=$directory.'/cache.db'; $jobsPath=$directory.'/jobs.db'; $statePath=$directory.'/service-reconciliation.json';
$main=new SQLite3($mainPath); $main->enableExceptions(true); $main->exec(file_get_contents($root.'/includes/schema.sql'));
$main->exec("INSERT INTO config(key,value) VALUES('device_name_attr','site'),('pppoe_user_attr','account'),('pppoe_pass_attr','pass'),('mac_addr_attr','dhcpMac'),('ip_addr_attr','manualIp'),('hs_attr',''),('service_reconciliation_enabled','true'),('service_reconciliation_auto_apply','true')");
$main->exec("INSERT INTO devices(id,name,ip,port,user,password,ssl,pool) VALUES(1,'Fixture East','192.0.2.1',8728,'ROUTER-USER-PRIVATE','ROUTER-PASSWORD-PRIVATE',0,'192.0.2.0/24'),(2,'Fixture West','198.51.100.1',8728,'OTHER-USER','OTHER-PASSWORD',0,'198.51.100.0/24'); INSERT INTO plans(id,name,uploadSpeed,downloadSpeed) VALUES(9,'Fixture Plan',5,20)");
$device=$main->querySingle('SELECT * FROM devices WHERE id=1',true);
$cache=new SQLite3($cachePath); $cache->enableExceptions(true); $cache->exec(file_get_contents($root.'/includes/cache.sql'));
$jobs=new SQLite3($jobsPath); $jobs->enableExceptions(true);
$jobs->exec('CREATE TABLE jobs(id INTEGER PRIMARY KEY,service_id INTEGER,status TEXT); CREATE TABLE service_versions(service_id INTEGER PRIMARY KEY,version INTEGER,applied_payload TEXT); CREATE TABLE service_event_locks(service_id INTEGER PRIMARY KEY); CREATE TABLE queue_meta(key TEXT PRIMARY KEY); CREATE TABLE webhook_fresh_reviews(service_id INTEGER PRIMARY KEY); CREATE TABLE service_tombstones(service_id INTEGER PRIMARY KEY)');
$snapshot=static fn(int $sid):array=>['id'=>$sid,'clientId'=>17,'device'=>1,'username'=>'fixture-'.$sid,'status'=>1,'planId'=>9,
    'password'=>'SNAPSHOT-PASSWORD-PRIVATE','_device'=>$device,'network'=>['address'=>'192.0.2.42'],
    '_commands'=>[['path'=>'/ppp/secret','name'=>'fixture-'.$sid,'profile'=>'Fixture Profile','comment'=>'17 - Fixture - '.$sid]]];
$add=static function(int $sid) use($main,$cache,$jobs,$snapshot):void {
    $main->exec('INSERT OR REPLACE INTO services(id,clientId,device,status,planId) VALUES('.$sid.',17,1,1,9)');
    $cache->exec('INSERT OR REPLACE INTO services(id,clientId,device,status,username) VALUES('.$sid.',17,1,1,\'fixture-'.$sid.'\')');
    $statement=$jobs->prepare('INSERT OR REPLACE INTO service_versions VALUES(?,1,?)'); $statement->bindValue(1,$sid,SQLITE3_INTEGER); $statement->bindValue(2,json_encode($snapshot($sid)),SQLITE3_TEXT); $statement->execute()->finalize(); $statement->close();
};
$add(42); $add(43);
$report=static function(int $sid):array {
    $values=['username'=>'fixture-'.$sid,'site'=>'Fixture East','address'=>'192.0.2.42','address6'=>null,'profile'=>'Fixture Profile','plan_id'=>9,'status'=>1,'ownership'=>'17:'.$sid,
        'base_speed'=>'20/5','speed_override'=>'disabled','speed'=>'20/5','rate_limit'=>'5M/20M 0/0 0/0 1/1 8 0/0'];
    $uisp=$values; $uisp['rate_limit']=null; $router=$values; $router['base_speed']=null; $router['speed_override']=null;
    $comparisons=[]; foreach ($values as $field=>$value) $comparisons[]=['field'=>$field,'uisp'=>$value,'expected'=>$value,'router'=>$value,
        'matches_uisp'=>in_array($field,['profile','address6','speed','rate_limit'],true)?null:true,'matches_router'=>in_array($field,['plan_id','status','address6','base_speed','speed_override'],true)?null:true];
    foreach ($comparisons as &$comparison) { $comparison['uisp']=$uisp[$comparison['field']]; $comparison['router']=$router[$comparison['field']]; } unset($comparison);
    return ['service_id'=>$sid,'uisp'=>['available'=>true,'values'=>$uisp], 'expected'=>['source'=>'applied','values'=>$values],
        'router'=>['available'=>true,'present'=>true,'reason'=>null,'values'=>$router],'comparisons'=>$comparisons];
};
$now=1792070000; $calls=[]; $queued=[]; $hook=null; $mutate=null;
$inspector=static function(int $sid,float $deadline) use(&$calls,&$hook,&$mutate,$report):array {
    $calls[]=$sid; reconciliationCheck($deadline>microtime(true) && $deadline<=microtime(true)+8.1,'Inspection lost its total budget');
    $answer=$report($sid); if ($hook!==null) $answer=$hook($sid,$answer); if ($mutate!==null) $mutate($sid); return $answer;
};
$sink=static function(int $sid,array $baseline,array $diagnostic,float $deadline) use(&$queued):array {
    reconciliationCheck($baseline['service_id']===$sid && $diagnostic['service_id']===$sid,'Enqueue targeted a different service');
    $queued[]=$sid; return ['Q900'];
};
$clock=static function() use(&$now):float { return $now; };
$make=static fn($enqueue=null)=>new ApiServiceReconciliation($inspector,$enqueue??$sink,$mainPath,$cachePath,$jobsPath,$statePath,$clock);
$reset=static function() use($statePath,&$calls,&$queued,&$hook,&$mutate,&$now,$jobs):void {
    if (is_file($statePath)) unlink($statePath); $calls=[]; $queued=[]; $hook=null; $mutate=null; $now+=61;
    foreach (['jobs','service_event_locks','queue_meta','webhook_fresh_reviews','service_tombstones'] as $table) $jobs->exec('DELETE FROM '.$table);
};
try {
    $initial=array_map(static fn($path)=>hash_file('sha256',$path),[$mainPath,$cachePath,$jobsPath]);
    $read=$make()->read(); reconciliationCheck($read['state']==='never_observed' && !is_file($statePath) && !$calls,'Read initialized or executed work');
    $tick=$make()->tick(); reconciliationCheck($tick['checked']===1 && !$queued && $calls===[42],'Aligned account caused corrective work');
    reconciliationCheck($initial===array_map(static fn($path)=>hash_file('sha256',$path),[$mainPath,$cachePath,$jobsPath]),'Inspection changed current service data');
    $make()->tick(); reconciliationCheck($calls===[42],'Cooldown did not bound scheduler work');
    $now+=61; $make()->tick(); reconciliationCheck($calls===[42,43],'Cursor did not advance fairly');
    $now+=61; $make()->tick(); reconciliationCheck($calls===[42,43,42],'Cursor did not wrap');
    $reset(); $hook=static function($sid,$answer) { $answer['comparisons'][4]['matches_router']=false; return $answer; };
    $tick=$make()->tick(); $read=$make()->read();
    reconciliationCheck($tick['queued']===1 && $queued===[42] && $read['items'][0]['job_keys']===['Q900'],'Proven owned drift was not queued');
    reconciliationCheck($read['items'][0]['fields']===['profile'],'Incident recorded arbitrary diagnostic data');
    foreach (['base_speed','speed_override','speed','rate_limit'] as $field) {
        $reset(); $hook=static function($sid,$answer) use($field) {
            foreach ($answer['comparisons'] as &$comparison) if ($comparison['field']===$field) {
                $comparison[$field==='rate_limit'?'router':'uisp']=$field==='rate_limit'?'1M/1M 0/0 0/0 1/1 8 0/0':'35/10';
                $comparison[$field==='rate_limit'?'matches_router':'matches_uisp']=false;
            } unset($comparison); return $answer;
        };
        $tick=$make()->tick(); reconciliationCheck($tick['queued']===1 && $queued===[42]
            && $make()->read()['items'][0]['fields']===[$field],'Known QoS difference was not eligible for safe correction: '.$field);
    }
    $reset(); $hook=static function($sid,$answer) {
        foreach ($answer['comparisons'] as &$comparison) if ($comparison['field']==='rate_limit') {
            $comparison['router']=null; $comparison['matches_router']=null;
        } unset($comparison); return $answer;
    };
    $tick=$make()->tick(); reconciliationCheck(!$queued && $tick['unknown']===1 && $make()->read()['items'][0]['reason']==='inspection_unavailable',
        'Unknown current QoS silently cleared the incident');
    $reset(); $tick=$make()->tick(); reconciliationCheck(!$queued && $tick['checked']===1 && !$make()->read()['items'],
        'Already aligned QoS caused repeated correction');
    $public=json_encode($read); foreach (['PRIVATE','password','username','snapshot','applied_hash','configuration_hash'] as $private)
        reconciliationCheck(strpos($public,$private)===false,'A monitoring report exposed private state');
    $reset(); $main->exec("UPDATE config SET value='false' WHERE key='service_reconciliation_auto_apply'");
    $hook=static function($sid,$answer) { $answer['comparisons'][2]['matches_router']=false; return $answer; };
    $tick=$make()->tick(); reconciliationCheck(!$queued && $tick['differences']===1 && $make()->read()['items'][0]['reason']==='automatic_correction_disabled','Disabled automatic correction was ignored');
    $main->exec("UPDATE config SET value='true' WHERE key='service_reconciliation_auto_apply'");
    $reset(); $main->exec("UPDATE config SET value='false' WHERE key='service_reconciliation_enabled'");
    $make()->tick(); reconciliationCheck(!$calls && !is_file($statePath),'Disabled monitor still scanned'); $main->exec("UPDATE config SET value='true' WHERE key='service_reconciliation_enabled'");
    foreach (['pending','running'] as $status) {
        $reset(); $jobs->exec("INSERT INTO jobs VALUES(1,42,'$status')"); $tick=$make()->tick();
        reconciliationCheck($tick['deferred']===1 && !$calls && !$queued,'Active job interfered with observation');
        $jobs->exec('DELETE FROM jobs'); $now+=61; $make()->tick(); reconciliationCheck($calls===[43],'Blocked first service starved a later service');
    }
    $reset(); $jobs->exec('INSERT INTO webhook_fresh_reviews VALUES(42)'); $tick=$make()->tick();
    reconciliationCheck($tick['deferred']===1 && !$calls,'Fresh webhook review duplicated by monitor');
    foreach (['queue_meta','service_event_locks','service_tombstones'] as $table) {
        $reset(); $jobs->exec($table==='queue_meta' ? "INSERT INTO queue_meta VALUES('maintenance')" : 'INSERT INTO '.$table.' VALUES(42)');
        $make()->tick(); reconciliationCheck(!$calls && !$queued,'Monitor crossed a lifecycle/maintenance gate');
    }
    $reset(); $mutate=static function() use($jobs):void { $jobs->exec('UPDATE service_versions SET version=version+1 WHERE service_id=42'); };
    $hook=static function($sid,$answer) { $answer['comparisons'][4]['matches_router']=false; return $answer; };
    $make()->tick(); reconciliationCheck(!$queued && $make()->read()['items'][0]['reason']==='state_changed','Concurrent event did not invalidate evidence');
    $reset(); $mutate=static function() use($main):void { $main->exec("UPDATE devices SET ip='192.0.2.9' WHERE id=1"); };
    $make()->tick(); reconciliationCheck(!$queued,'Changed router configuration was used'); $main->exec("UPDATE devices SET ip='192.0.2.1' WHERE id=1");
    $cases=[
        ['identity_unverified',static function($sid,$a) { $a['router']['values']['ownership']='99:999'; return $a; }],
        ['identity_changed',static function($sid,$a) { $a['uisp']['values']['username']='another-account'; return $a; }],
        ['account_absent',static function($sid,$a) { $a['router']['present']=false; return $a; }],
        ['terminal_requires_review',static function($sid,$a) { $a['uisp']['values']['status']=2; return $a; }],
        ['inspection_unavailable',static function($sid,$a) { $a['uisp']['available']=false; return $a; }],
        ['inspection_unavailable',static function($sid,$a) { $a['router']['reason']='ipv6_unavailable'; return $a; }],
    ];
    foreach ($cases as [$reason,$change]) { $reset(); $hook=$change; $make()->tick(); reconciliationCheck(!$queued && $make()->read()['items'][0]['reason']===$reason,'Ambiguous evidence queued: '.$reason); }
    $reset(); $hook=static function():array { throw new RuntimeException('PRIVATE-REMOTE-PASSWORD'); }; $make()->tick();
    reconciliationCheck(strpos(file_get_contents($statePath),'PRIVATE-REMOTE-PASSWORD')===false,'Raw remote failure persisted');
    $reset(); file_put_contents($directory.'/queue.json','[{"service":42}]'); $make()->tick();
    reconciliationCheck(!$calls && !$queued,'Hidden legacy work ignored'); unlink($directory.'/queue.json');
    $reset(); $handle=fopen($statePath.'.lock','c+b'); flock($handle,LOCK_EX|LOCK_NB);
    $tick=$make()->tick(); reconciliationCheck($tick['busy'] && !$calls,'Concurrent monitor was allowed'); flock($handle,LOCK_UN); fclose($handle);
    // Exercise the default fresh hydration/guard path, retaining only the normal queue submission as a sink.
    $previousDirectory=getcwd(); $previousInclude=get_include_path(); chdir($root); set_include_path($root.'/lib'.PATH_SEPARATOR.$previousInclude);
    require_once $root.'/lib/api_sqlite.php'; $apiSqlite=new ApiSqlite($mainPath);
    $catalog=[]; foreach (['site','account','manualIp','pass'] as $index=>$key) $catalog[]=['id'=>$index+1,'key'=>$key,'attributeType'=>'service'];
    $fresh=['id'=>42,'clientId'=>17,'status'=>1,'servicePlanId'=>9,'uploadSpeed'=>5,'downloadSpeed'=>20,'trafficShapingOverrideEnabled'=>false,'attributes'=>[
        ['key'=>'site','customAttributeId'=>1,'value'=>'Fixture East'],['key'=>'account','customAttributeId'=>2,'value'=>'fixture-42'],
        ['key'=>'manualIp','customAttributeId'=>3,'value'=>'192.0.2.42'],['key'=>'pass','customAttributeId'=>4,'value'=>'FRESH-PRIVATE-PASSWORD']]];
    $client=['id'=>17,'firstName'=>'Fresh','lastName'=>'Customer','isArchived'=>false]; $serviceReads=0; $requestHook=null;
    $request=static function($path,$method,$parameters) use(&$fresh,&$catalog,&$client,&$serviceReads,&$requestHook):array {
        reconciliationCheck($method==='get' && $parameters===[],'Monitor hydration attempted a CRM mutation');
        if ($requestHook!==null) $requestHook($path);
        if ($path==='clients/services/42') { $serviceReads++; return $fresh; }
        if ($path==='custom-attributes') return $catalog;
        if ($path==='clients/17') return $client;
        throw new RuntimeException('Unexpected read');
    };
    $production=static fn()=>new ApiServiceReconciliation($inspector,null,$mainPath,$cachePath,$jobsPath,$statePath,$clock,$request);
    $reset(); $hook=static function($sid,$a) { $a['comparisons'][4]['matches_router']=false; return $a; };
    $tick=$production()->tick();
    reconciliationCheck($tick['queued']===1 && $serviceReads===2 && count(Batch::$submissions)===1 && $reconciliationQueue->locks===[42],
        'Default correction bypassed guarded fresh hydration');
    reconciliationCheck(Batch::$preflights===1,'Corrective enqueue bypassed the normal address preflight');
    $entity=Batch::$submissions[0][0]; reconciliationCheck($entity['password']==='FRESH-PRIVATE-PASSWORD' && $entity['username']==='fixture-42'
        && $entity['firstName']==='Fresh' && $entity['planId']===9,'Default correction replayed cached identity or credentials');
    reconciliationCheck(strpos(file_get_contents($statePath),'FRESH-PRIVATE-PASSWORD')===false,'Fresh credentials persisted in monitor evidence');
    $reset(); $hook=static function($sid,$a) { $a['comparisons'][4]['matches_router']=false; return $a; }; $serviceReads=0;
    $requestHook=static function($path) use(&$fresh,&$serviceReads):void { if ($path==='clients/services/42' && $serviceReads===1) $fresh['status']=3; };
    $tick=$production()->tick(); reconciliationCheck(!$tick['queued'] && count(Batch::$submissions)===1,'Changing fresh billing state was enqueued');
    $fresh['status']=1; $requestHook=null;
    $reset(); $hook=static function($sid,$a) { $a['comparisons'][4]['matches_router']=false; return $a; }; $client['isArchived']=true;
    $production()->tick(); reconciliationCheck(count(Batch::$submissions)===1,'Archived client caused an automatic update'); $client['isArchived']=false;
    $reset(); $fresh['attributes'][2]['value']='198.51.100.42';
    $hook=static function($sid,$a) { $a['uisp']['values']['address']='198.51.100.42'; $a['comparisons'][2]['matches_uisp']=false; return $a; };
    $production()->tick(); reconciliationCheck(count(Batch::$submissions)===1 && $production()->read()['items'][0]['reason']==='foreign_site_address',
        'Monitor invoked foreign-Site clearing or migration policy'); $fresh['attributes'][2]['value']='192.0.2.42';
    $reset(); $hook=static function($sid,$a) { $a['comparisons'][4]['matches_router']=false; return $a; }; Batch::$collision=true;
    $production()->tick(); reconciliationCheck(count(Batch::$submissions)===1 && $production()->read()['items'][0]['reason']==='correction_unavailable',
        'Known address collision was queued or its private details exposed'); Batch::$collision=false;
    $reset(); $fresh['attributes'][2]['value']='192.0.2.42/32';
    $hook=static function($sid,$a) { $a['uisp']['values']['address']='192.0.2.42/32'; $a['comparisons'][2]['matches_uisp']=false; return $a; };
    $production()->tick(); reconciliationCheck(count(Batch::$submissions)===1 && $production()->read()['items'][0]['reason']==='identity_unverified',
        'Malformed IPv4 CIDR was queued by automatic correction'); $fresh['attributes'][2]['value']='192.0.2.42';
    // A missed override webhook must enqueue freshly hydrated QoS, without reusing displayed rates.
    $overrideReport=static function($sid,$answer) {
        $answer['uisp']['values']['speed_override']='35/10'; $answer['uisp']['values']['speed']='35/10';
        foreach ($answer['comparisons'] as &$comparison) if (in_array($comparison['field'],['speed_override','speed'],true)) {
            $comparison['uisp']='35/10'; $comparison['matches_uisp']=false;
        } unset($comparison); return $answer;
    };
    $reset(); $hook=$overrideReport; $fresh['trafficShapingOverrideEnabled']=true;
    $fresh['downloadSpeedOverride']=35; $fresh['uploadSpeedOverride']=10; $fresh['trafficShapingOverrideEnd']='2099-01-01T00:00:00+0000';
    $tick=$production()->tick(); reconciliationCheck($tick['queued']===1 && count(Batch::$submissions)===2
        && Batch::$submissions[1][0]['downloadSpeedOverride']===35 && Batch::$submissions[1][0]['uploadSpeedOverride']===10
        && Batch::$submissions[1][0]['trafficShapingOverrideEnabled']===true,'Missed override was not queued from fresh UISP fields');
    $reset(); $hook=$overrideReport; $fresh['uploadSpeedOverride']=11;
    $tick=$production()->tick(); reconciliationCheck(!$tick['queued'] && count(Batch::$submissions)===2
        && $production()->read()['items'][0]['reason']==='state_changed','QoS changed after diagnostics was accepted'); $fresh['uploadSpeedOverride']=10;
    $reset(); $hook=$overrideReport; $serviceReads=0;
    $requestHook=static function($path) use(&$fresh,&$serviceReads):void {
        if ($path==='clients/services/42' && $serviceReads===1) $fresh['trafficShapingOverrideEnd']='2099-02-01T00:00:00+0000';
    };
    $tick=$production()->tick(); reconciliationCheck(!$tick['queued'] && count(Batch::$submissions)===2,
        'Override expiry changed during fresh hydration without invalidating its fingerprint'); $requestHook=null;
    // UISP's disabled flag is authoritative even when expired override numbers remain on the service.
    $reset(); $fresh['trafficShapingOverrideEnabled']=false; $fresh['trafficShapingOverrideEnd']='2000-01-01T00:00:00+0000';
    $hook=static function($sid,$answer) {
        $answer['expected']['values']['speed_override']='35/10'; $answer['expected']['values']['speed']='35/10';
        $answer['router']['values']['speed']='35/10';
        foreach ($answer['comparisons'] as &$comparison) {
            if ($comparison['field']==='speed_override') { $comparison['expected']='35/10'; $comparison['matches_uisp']=false; }
            if ($comparison['field']==='speed') { $comparison['expected']='35/10'; $comparison['router']='35/10'; }
        } unset($comparison); return $answer;
    };
    $tick=$production()->tick(); reconciliationCheck($tick['queued']===1 && count(Batch::$submissions)===3
        && Batch::$submissions[2][0]['trafficShapingOverrideEnabled']===false,'Missed override expiry failed to queue a return to the plan');
    $reset(); $jobs->exec("INSERT INTO jobs VALUES(901,42,'pending')"); $tick=$production()->tick();
    reconciliationCheck($tick['deferred']===1 && count(Batch::$submissions)===3 && !$calls,'Pending QoS correction was duplicated');
    $apiSqlite->close(); $apiSqlite=null; chdir($previousDirectory); set_include_path($previousInclude);
    $reset(); $make()->tick(); $value=json_decode(file_get_contents($statePath),true); $value['items']=['42'=>['service_id'=>42,'device_id'=>'PRIVATE','state'=>'unknown','reason'=>'inspection_unavailable','fields'=>[],'checked_at'=>gmdate('Y-m-d\TH:i:s\Z',$now),'job_keys'=>[]]]; file_put_contents($statePath,json_encode($value));
    reconciliationCheck(!$make()->read()['available'],'Corrupt incident identity was trusted');
    echo "Service reconciliation regression passed\n";
} finally {
    $jobs->close(); $cache->close(); $main->close();
    foreach (glob($directory.'/*')?:[] as $path) if (is_file($path)) unlink($path); rmdir($directory);
}
