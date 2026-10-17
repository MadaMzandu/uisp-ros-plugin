<?php
// Synthetic snapshots, HTTP answers and RouterOS observations only. No production connection or mutation.
set_error_handler(function ($severity,$message,$file,$line) {
    if (error_reporting() & $severity) throw new ErrorException($message,0,$severity,$file,$line); return false;
});
function diagnosticCheck(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
$root = realpath(__DIR__.'/../src'); require_once $root.'/lib/api_service_diagnostics.php';
$directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'uisp-diagnostic-'.bin2hex(random_bytes(8)); mkdir($directory,0700);
$mainPath = $directory.'/data.db'; $cachePath = $directory.'/cache.db'; $jobsPath = $directory.'/jobs.db';
$main = new SQLite3($mainPath); $main->enableExceptions(true); $main->exec(file_get_contents($root.'/includes/schema.sql'));
$main->exec("INSERT INTO config(key,value) VALUES ('device_name_attr','site'),('pppoe_user_attr','account'),('mac_addr_attr','dhcpMac'),('hs_attr','hotspot'),('ip_addr_attr','manualIp'),('ip_addr6_attr','v6')");
$main->exec("INSERT INTO devices(id,name,ip,port,user,password,ssl) VALUES (1,'Fixture East','192.0.2.1',8728,'ROUTER-USER-SECRET','ROUTER-SECRET',0)");
$device = $main->querySingle('SELECT * FROM devices WHERE id=1',true);
$cache = new SQLite3($cachePath); $cache->enableExceptions(true);
$cache->exec('CREATE TABLE services(id INTEGER PRIMARY KEY,clientId INTEGER,device INTEGER,username TEXT,mac TEXT,hotspot TEXT,status INTEGER,planId INTEGER,last TEXT,password TEXT); CREATE TABLE clients(id INTEGER PRIMARY KEY,firstName TEXT,lastName TEXT,company TEXT); CREATE TABLE network(id INTEGER PRIMARY KEY,address TEXT,address6 TEXT)');
$cache->exec("INSERT INTO services VALUES (42,17,1,'fixture-user',NULL,NULL,1,9,'2026-10-15T12:00:00Z','CACHE-SECRET'); INSERT INTO clients VALUES(17,'Fixture','Customer',NULL); INSERT INTO network VALUES(42,'192.0.2.42',NULL)");
$jobs = new SQLite3($jobsPath); $jobs->enableExceptions(true);
$jobs->exec('CREATE TABLE jobs(id INTEGER PRIMARY KEY,service_id INTEGER,status TEXT,payload TEXT,operation TEXT,attempts INTEGER,created TEXT,updated TEXT,next_attempt INTEGER,error TEXT); CREATE TABLE service_versions(service_id INTEGER PRIMARY KEY,applied_payload TEXT); CREATE TABLE cancellation_cleanups(service_id INTEGER); CREATE TABLE retained_resources(service_id INTEGER)');
$snapshot = ['id'=>42,'clientId'=>17,'device'=>1,'username'=>'fixture-user','status'=>1,'planId'=>9,'password'=>'PAYLOAD-SECRET',
    'uploadSpeed'=>5,'downloadSpeed'=>20,'trafficShapingOverrideEnabled'=>false,
    '_device'=>$device,'network'=>['address'=>'192.0.2.42'],'_commands'=>[
        ['path'=>'/ppp/profile','name'=>'Fixture Profile','rate-limit'=>'5M/20M 6M/24M 4M/16M 10/10 8 4M/16M'],
        ['path'=>'/ppp/secret','name'=>'fixture-user','profile'=>'Fixture Profile','remote-address'=>'192.0.2.42','password'=>'COMMAND-SECRET']]];
$putJob = static function(int $id,array $payload,string $state='pending',string $error='') use ($jobs) {
    $statement=$jobs->prepare('INSERT OR REPLACE INTO jobs VALUES (?,?,?,? ,?,?,?,?,?,?)');
    foreach ([$id,42,$state,json_encode($payload),'update',2,'2026-10-15T12:00:00Z','2026-10-15T12:00:05Z',1792065600,$error] as $i=>$value)
        $statement->bindValue($i+1,$value,is_int($value)?SQLITE3_INTEGER:SQLITE3_TEXT);
    $statement->execute()->finalize(); $statement->close();
};
$putJob(100,$snapshot,'pending','router_ownership_conflict: current_service=42 previous_service=41 reason=mapping_unverified HTTP-SECRET');
$jobs->exec('INSERT INTO cancellation_cleanups VALUES(42); INSERT INTO retained_resources VALUES(42); INSERT INTO retained_resources VALUES(42)');
$catalog=[];
foreach (['site','account','manualIp','v6','dhcpMac'] as $i=>$key) $catalog[]=['id'=>$i+1,'key'=>$key,'attributeType'=>'service'];
$fresh = ['id'=>42,'clientId'=>17,'status'=>1,'servicePlanId'=>9,'servicePlanName'=>'Fixture UISP Plan',
    'uploadSpeed'=>5,'downloadSpeed'=>20,'trafficShapingOverrideEnabled'=>false,'attributes'=>[
    ['key'=>'site','customAttributeId'=>1,'value'=>'Fixture East'],['key'=>'account','customAttributeId'=>2,'value'=>'fixture-user'],
    ['key'=>'manualIp','customAttributeId'=>3,'value'=>'192.0.2.42'],['key'=>'password','value'=>'HTTP-SECRET']]];
$client = ['id'=>17,'firstName'=>'Fixture','lastName'=>'Customer','password'=>'CLIENT-SECRET'];
$routerRows = [['.id'=>'*A1','name'=>'fixture-user','profile'=>'Fixture Profile','remote-address'=>'192.0.2.42','disabled'=>'false',
    'comment'=>'17 - Fixture Customer - 41','password'=>'ROUTER-ACCOUNT-SECRET']];
$profileRows = [['.id'=>'*B1','name'=>'Fixture Profile','rate-limit'=>'5000k/20000k 6000k/24000k 4000k/16000k 10/10 8 4000k/16000k','password'=>'PROFILE-SECRET']];
$calls=[]; $routerCalls=[]; $hook=null; $routerHook=null;
$request=static function($path,$method,$parameters) use (&$calls,&$fresh,&$catalog,&$client,&$hook) {
    diagnosticCheck($method==='get' && $parameters===[],'Diagnostic attempted a remote mutation'); $calls[]=$path;
    if ($hook!==null) $hook($path);
    if ($path==='clients/services/42') return $fresh;
    if ($path==='custom-attributes') return $catalog;
    if ($path==='clients/17') return $client;
    throw new RuntimeException('Unexpected request with HTTP-SECRET');
};
$reader=static function($device,$path,$filter,$fields) use (&$routerCalls,&$routerRows,&$routerHook,&$profileRows) {
    diagnosticCheck(in_array($path,['ppp/secret','ip/hotspot/user','ip/dhcp-server/lease','ipv6/dhcp-server/binding','ppp/profile','ip/hotspot/user/profile','queue/simple'],true),'Unexpected router observation');
    diagnosticCheck(!in_array('password',$fields,true) && !in_array('user',$fields,true),'Sensitive router projection');
    $routerCalls[]=[$path,$filter];
    if (in_array($path,['ppp/profile','ip/hotspot/user/profile'],true)) return $profileRows;
    return $routerHook===null ? $routerRows : $routerHook($path,$filter);
};
$make=static fn()=>new ApiServiceDiagnostics($request,$reader,$mainPath,$cachePath,$jobsPath,static fn()=>1792065600);
try {
    $hashes=array_map(static fn($path)=>hash_file('sha256',$path),[$mainPath,$cachePath,$jobsPath]);
    $search=$make()->search('Fixture');
    diagnosticCheck($search['available'] && count($search['items'])===1 && $search['items'][0]['service_id']===42,'Client search lost service');
    diagnosticCheck($calls===[] && $routerCalls===[],'Search contacted external systems');
    diagnosticCheck($make()->search('192.0.2.42')['items'][0]['service_id']===42,'IP search failed');
    diagnosticCheck($make()->search('fixture-user')['items'][0]['client_name']==='Fixture Customer','User search failed');
    diagnosticCheck($make()->search("%' OR 1=1 --")['items']===[],'Search interpolates SQL or wildcards');
    $result=$make()->inspect(42);
    diagnosticCheck($result['uisp']['available'] && $result['expected']['source']==='queued' && $result['router']['available'],'Three sources were not explicit');
    diagnosticCheck($result['expected']['checked_at']===gmdate('Y-m-d\TH:i:s\Z',1792065600)
        && $result['expected']['stored_at']===null,'Current local read confused with an unknown stored timestamp');
    diagnosticCheck($result['router']['values']['ownership']==='17:41','Contradictory marker not observable');
    $comparison=array_column($result['comparisons'],null,'field');
    diagnosticCheck($comparison['ownership']['matches_router']===false && $comparison['address']['matches_router']===true,'Mismatch comparison wrong');
    diagnosticCheck($comparison['profile']['matches_uisp']===null && $comparison['profile']['matches_router']===true,'UISP plan label compared to router profile');
    diagnosticCheck($comparison['plan_id']['matches_uisp']===true && $comparison['plan_id']['matches_router']===null,'Plan ID not compared independently');
    diagnosticCheck($result['jobs']['items'][0]['error_code']==='router_ownership_conflict','Error code lost or remote detail leaked');
    diagnosticCheck($result['history']['recovery_count']===1 && $result['history']['retained_count']===2,'History missing');
    $json=json_encode($result);
    foreach (['ROUTER-SECRET','ROUTER-USER-SECRET','ROUTER-ACCOUNT-SECRET','PROFILE-SECRET','HTTP-SECRET','CLIENT-SECRET','CACHE-SECRET','PAYLOAD-SECRET','COMMAND-SECRET','password','fingerprint'] as $secret)
        diagnosticCheck(strpos($json,$secret)===false,'Diagnostics exposed private material');
    diagnosticCheck(count($calls)===3 && count($routerCalls)===2 && $routerCalls[0][1]===['name'=>'fixture-user']
        && $routerCalls[1]===['ppp/profile',['name'=>'Fixture Profile']],'Observation was not bounded/exact');
    diagnosticCheck($hashes===array_map(static fn($path)=>hash_file('sha256',$path),[$mainPath,$cachePath,$jobsPath]),'Diagnostics mutated a database');
    diagnosticCheck($comparison['base_speed']['uisp']==='20/5' && $comparison['speed_override']['uisp']==='disabled'
        && $comparison['speed']['router']==='20/5' && $comparison['speed']['matches_router']===true
        && $comparison['rate_limit']['matches_router']===true,'Base/override/speed/burst observations lost or units compared literally');
    $fresh['trafficShapingOverrideEnabled']=true; $fresh['downloadSpeedOverride']=35; $fresh['uploadSpeedOverride']=10;
    $overrideSnapshot=array_replace($snapshot,['trafficShapingOverrideEnabled'=>true,'downloadSpeedOverride'=>35,'uploadSpeedOverride'=>10]);
    $overrideSnapshot['_commands'][0]['rate-limit']='10M/35M 12M/42M 8M/28M 10/10 8 8M/28M'; $putJob(100,$overrideSnapshot);
    $overrideResult=$make()->inspect(42); $overrideComparison=array_column($overrideResult['comparisons'],null,'field');
    diagnosticCheck($overrideComparison['base_speed']['uisp']==='20/5' && $overrideComparison['speed_override']['uisp']==='35/10'
        && $overrideComparison['speed']['expected']==='35/10' && $overrideComparison['speed']['matches_router']===false
        && $overrideComparison['rate_limit']['matches_router']===false,'Per-service override or actual old router limits hidden');
    $fresh['status']=3; $suspendedSnapshot=$overrideSnapshot; $suspendedSnapshot['status']=3;
    $suspendedSnapshot['_commands'][0]['rate-limit']='128k/128k'; $putJob(100,$suspendedSnapshot);
    $normalProfiles=$profileRows; $profileRows[0]['rate-limit']='128k/128k';
    $suspended=$make()->inspect(42); $suspendedComparison=array_column($suspended['comparisons'],null,'field');
    diagnosticCheck($suspendedComparison['speed']['uisp']==='35/10' && $suspendedComparison['speed']['expected']==='0.128/0.128'
        && $suspendedComparison['speed']['matches_uisp']===null && $suspendedComparison['speed']['matches_router']===true,
        'Suspension speed cap was treated as drift from an active service override');
    $fresh['status']=1; $profileRows=$normalProfiles; $putJob(100,$overrideSnapshot);
    $fresh['downloadSpeedOverride']=null; $fresh['uploadSpeedOverride']=null;
    $unlimited=$make()->inspect(42); diagnosticCheck($unlimited['uisp']['values']['speed_override']==='0/0','Explicit null enabled override must mean unlimited');
    unset($fresh['downloadSpeedOverride']); diagnosticCheck($make()->inspect(42)['uisp']['values']['speed_override']===null,'Omitted override speed guessed as unlimited');
    $fresh['trafficShapingOverrideEnabled']=false; unset($fresh['uploadSpeedOverride']); $putJob(100,$snapshot);
    $savedProfiles=$profileRows; $profileRows=[]; $partial=$make()->inspect(42);
    diagnosticCheck($partial['router']['available'] && $partial['router']['values']['ownership']==='17:41'
        && $partial['router']['reason']==='shaping_unavailable' && $partial['router']['values']['rate_limit']===null,'Missing profile erased the account observation');
    $profileRows=[['.id'=>'*B1','name'=>'Other Profile','rate-limit'=>'1M/1M']];
    diagnosticCheck($make()->inspect(42)['router']['values']['speed']===null,'Different profile accepted for actual speed');
    $profileRows=$savedProfiles;
    $expiredRead=new ReflectionMethod(ApiServiceDiagnostics::class,'routerRead'); if (PHP_VERSION_ID<80100) $expiredRead->setAccessible(true);
    $beforeDeadlineCalls=count($routerCalls);
    try { $expiredRead->invoke($make(),$device,'ppp/secret',['name'=>'fixture-user'],['.id','name']); throw new LogicException('Expired observation budget accepted'); }
    catch (RuntimeException $error) { diagnosticCheck($error->getMessage()==='router_observation_limit','Expired deadline exposed a transport error'); }
    diagnosticCheck(count($routerCalls)===$beforeDeadlineCalls,'Expired observation contacted router transport');
    $deadlineProperty=new ReflectionProperty(ApiServiceDiagnostics::class,'deadline'); if (PHP_VERSION_ID<80100) $deadlineProperty->setAccessible(true);
    $lateProbe=null;
    $lateProbe=new ApiServiceDiagnostics($request,static function() use (&$lateProbe,$deadlineProperty) {
        $deadlineProperty->setValue($lateProbe,microtime(true)-1);
        return [['.id'=>'*1','name'=>'fixture-user','remote-address'=>'192.0.2.42']];
    },$mainPath,$cachePath,$jobsPath);
    $lateResult=$lateProbe->inspect(42);
    diagnosticCheck(!$lateResult['router']['available'] && $lateResult['router']['reason']==='router_observation_limit',
        'An observation returned after the absolute budget was reported as current');
    $parser=new ReflectionMethod(ApiServiceDiagnostics::class,'decodePrint'); if (PHP_VERSION_ID<80100) $parser->setAccessible(true);
    $decoded=$parser->invoke($make(),['!re','=.id=*1','=name=fixture-user','=password=EXTRA-ROUTER-SECRET','=unknown=future-property','!done'],['.id','name']);
    diagnosticCheck($decoded===[['.id'=>'*1','name'=>'fixture-user']],'Extra RouterOS properties retained or rejected');
    foreach ([['!trap','=message=PRIVATE-ERROR','!done'],['!re','=.id=*1','=name=fixture-user'],['!re','=.id=*1','=.id=*2','!done']] as $invalid) {
        try { $parser->invoke($make(),$invalid,['.id','name']); throw new LogicException('Invalid print accepted'); }
        catch (RuntimeException $error) { diagnosticCheck($error->getMessage()==='router_unavailable','Raw RouterOS error exposed'); }
    }
    $routerRows=[]; $result=$make()->inspect(42);
    diagnosticCheck($result['router']['available'] && $result['router']['present']===false && $result['router']['reason']==='account_absent','Absence is not explicit');
    $routerRows=[['.id'=>'*1','name'=>'Fixture-user']]; $result=$make()->inspect(42);
    diagnosticCheck(!$result['router']['available'],'Case-changed PPP account accepted');
    $routerRows=[['.id'=>'*1','name'=>'fixture-user'],['.id'=>'*2','name'=>'fixture-user']]; $result=$make()->inspect(42);
    diagnosticCheck(!$result['router']['available'] && $result['router']['reason']==='router_ambiguous','Duplicate account presented as one');
    $routerRows=[['.id'=>'*1','name'=>'fixture-user','disabled'=>'false']];
    $catalog[]=['id'=>99,'key'=>'account','attributeType'=>'service']; $result=$make()->inspect(42);
    diagnosticCheck($result['uisp']['values']['username']===null,'Ambiguous mapping guessed'); array_pop($catalog);
    $fresh['id']=43; $result=$make()->inspect(42);
    diagnosticCheck(!$result['uisp']['available'] && $result['uisp']['values']['username']===null,'Different UISP service accepted'); $fresh['id']=42;
    $routerCalls=[]; $hook=static function($path) use ($main) { if ($path==='clients/17') $main->exec("UPDATE devices SET ip='192.0.2.2' WHERE id=1"); };
    $result=$make()->inspect(42);
    diagnosticCheck(!$result['router']['available'] && $result['router']['reason']==='router_authority_changed' && !$routerCalls,'Changed router endpoint contacted');
    $hook=null; $main->exec("UPDATE devices SET ip='192.0.2.1' WHERE id=1");
    $jobs->exec("UPDATE jobs SET status='cancelled'");
    $appliedSnapshot=$snapshot; $appliedSnapshot['last']='2026-10-01T10:00:00Z';
    $statement=$jobs->prepare('INSERT INTO service_versions VALUES(42,?)'); $statement->bindValue(1,json_encode($appliedSnapshot),SQLITE3_TEXT); $statement->execute()->finalize(); $statement->close();
    $appliedDetail=$make()->inspect(42);
    diagnosticCheck($appliedDetail['expected']['source']==='applied','Cancelled job treated as executable expected state');
    diagnosticCheck($appliedDetail['expected']['checked_at']===gmdate('Y-m-d\TH:i:s\Z',1792065600)
        && $appliedDetail['expected']['stored_at']==='2026-10-01T10:00:00Z','Stored date replaced the current read time');
    $v6=$snapshot; unset($v6['username'],$v6['_commands']); $v6['duid']='00:03:00:01:aa:bb:cc:dd:ee:ff'; $v6['iaid']='0x2';
    $v6['network']=['address6'=>'2001:db8:42::/64']; $putJob(100,$v6);
    $routerRows=[['.id'=>'*6','duid'=>'0x00030001aabbccddeeff','iaid'=>'2','address'=>'2001:db8:42::/64','comment'=>'17 - Fixture Customer - 42']];
    $routerCalls=[]; $result=$make()->inspect(42);
    diagnosticCheck($result['router']['available'] && $result['router']['values']['address6']==='2001:db8:42::/64'
        && $result['router']['values']['address']===null,'DHCPv6-only service not observed');
    diagnosticCheck($routerCalls[0]===['ipv6/dhcp-server/binding',['duid'=>'0x00030001aabbccddeeff','iaid'=>'2']],'DUID/IAID selector broadened');
    $routerRows[0]['iaid']='3'; diagnosticCheck(!$make()->inspect(42)['router']['available'],'Different IAID accepted');
    $v6['iaid']='-1'; $putJob(100,$v6); diagnosticCheck(!$make()->inspect(42)['router']['available'],'Invalid IAID guessed');
    $dual=$v6; $dual['iaid']=2; $dual['mac']='AA:BB:CC:DD:EE:FF'; $dual['network']['address']='192.0.2.42'; $putJob(100,$dual);
    $routerHook=static function($path,$filter) {
        return $path==='ip/dhcp-server/lease'
            ? [['.id'=>'*4','mac-address'=>'AA:BB:CC:DD:EE:FF','address'=>'192.0.2.42','comment'=>'17 - Fixture Customer - 42']]
            : [['.id'=>'*6','duid'=>'00030001aabbccddeeff','iaid'=>'2','address'=>'2001:db8:42::/64']];
    };
    $routerCalls=[]; $result=$make()->inspect(42);
    diagnosticCheck(count($routerCalls)===2 && $result['router']['ipv6_present']===true
        && $result['router']['values']['address']==='192.0.2.42' && $result['router']['values']['address6']==='2001:db8:42::/64','Dual-stack diagnostic omitted one resource');
    $ownQueue=['path'=>'/queue/simple','name'=>'Fixture Customer - 42','comment'=>'17 - Fixture Customer - 42',
        'max-limit'=>'10M/35M','burst-limit'=>'12M/42M','burst-threshold'=>'8M/28M','burst-time'=>'10/10','priority'=>'8/8','limit-at'=>'8M/28M'];
    $dual['_commands']=[$ownQueue]; $putJob(100,$dual);
    $routerHook=static function($path,$filter) use($ownQueue) {
        if ($path==='queue/simple') return [['.id'=>'*AA']+$ownQueue];
        return $path==='ip/dhcp-server/lease'
            ? [['.id'=>'*4','mac-address'=>'AA:BB:CC:DD:EE:FF','address'=>'192.0.2.42','comment'=>'17 - Fixture Customer - 42']]
            : [['.id'=>'*6','duid'=>'00030001aabbccddeeff','iaid'=>'2','address'=>'2001:db8:42::/64']];
    };
    $routerCalls=[]; $result=$make()->inspect(42); $queueComparison=array_column($result['comparisons'],null,'field');
    diagnosticCheck(count($routerCalls)===3 && $routerCalls[1]===['queue/simple',['name'=>'Fixture Customer - 42']]
        && $result['router']['values']['speed']==='35/10' && $queueComparison['rate_limit']['matches_router']===true,
        'DHCP override queue/burst was not read exactly or its rate direction was reversed');
    $routerHook=static function($path,$filter) use($ownQueue) {
        if ($path==='queue/simple') return [['.id'=>'*AA']+array_replace($ownQueue,['comment'=>'18 - Other Customer - 43'])];
        return $path==='ip/dhcp-server/lease'
            ? [['.id'=>'*4','mac-address'=>'AA:BB:CC:DD:EE:FF','address'=>'192.0.2.42']]
            : [['.id'=>'*6','duid'=>'00030001aabbccddeeff','iaid'=>'2','address'=>'2001:db8:42::/64']];
    };
    $result=$make()->inspect(42); diagnosticCheck($result['router']['values']['rate_limit']===null
        && $result['router']['reason']==='shaping_unavailable','Foreign DHCP queue was attributed to the service');
    unset($dual['_commands']); $putJob(100,$dual);
    $routerHook=static function($path,$filter) {
        if ($path==='ipv6/dhcp-server/binding') throw new RuntimeException('DHCPv6-SECRET');
        return [['.id'=>'*4','mac-address'=>'AA:BB:CC:DD:EE:FF','address'=>'192.0.2.42']];
    };
    $result=$make()->inspect(42); diagnosticCheck($result['router']['available'] && $result['router']['reason']==='ipv6_unavailable'
        && $result['router']['values']['address6']===null && strpos(json_encode($result),'DHCPv6-SECRET')===false,'IPv6 failure mistaken for successful read');
    $routerHook=null; $putJob(100,$snapshot,'cancelled');
    for ($id=1000;$id<1033;$id++) $cache->exec("INSERT INTO services(id,clientId,username) VALUES($id,17,'common-user')");
    $search=$make()->search('common-user'); diagnosticCheck(count($search['items'])===30 && $search['has_more'],'Truncated search not disclosed');
    for ($id=101;$id<134;$id++) $putJob($id,$snapshot,'cancelled','arbitrary HTTP-SECRET');
    $result=$make()->inspect(42); diagnosticCheck(count($result['jobs']['items'])===30 && $result['jobs']['has_more'],'Truncated jobs not disclosed');
    diagnosticCheck($result['jobs']['items'][0]['error_code']==='operation_failed','Arbitrary errors exposed');
    $missing=$directory.'/missing.db'; $absent=new ApiServiceDiagnostics($request,$reader,$missing,$missing,$missing);
    $result=$absent->inspect(42); diagnosticCheck(!file_exists($missing) && !$result['expected']['available'] && !$result['router']['available'],'Read created missing state');
    diagnosticCheck($absent->search('42')['items'][0]['service_id']===42 && !file_exists($missing),'Explicit unknown service cannot be inspected');
    $cache->exec('CREATE VIEW harmless AS SELECT 1 AS id'); $search=$make()->search('fixture-user');
    diagnosticCheck(!$search['available'],'Unexpected schema silently trusted');
    echo "Service diagnostics regression passed\n";
} finally {
    $jobs->close(); $cache->close(); $main->close();
    foreach (glob($directory.'/*') ?: [] as $path) if (is_file($path)) unlink($path); rmdir($directory);
}
