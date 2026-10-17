<?php
// Synthetic UISP, real catalog normalization/private cursors, and injected normal provisioning boundaries.
set_error_handler(static function($severity,$message,$file,$line){if(error_reporting()&$severity)throw new ErrorException($message,0,$severity,$file,$line);return false;});
$source=realpath(__DIR__.'/../src');$original=getcwd();$oldInclude=get_include_path();
$directory=sys_get_temp_dir().'/uisp-device-sync-'.bin2hex(random_bytes(8));mkdir($directory,0700);mkdir($directory.'/data',0700);mkdir($directory.'/vendor',0700);
file_put_contents($directory.'/vendor/autoload.php','<?php');
require_once $source.'/vendor/autoload.php';
set_include_path($source.'/lib'.PATH_SEPARATOR.$oldInclude);chdir($directory);
require_once $source.'/lib/api_device_sync.php';
function syncCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function syncReject(callable $call,string $code):void{try{$call();}catch(Throwable $error){syncCheck($error->getMessage()===$code,'Unexpected rejection: '.$error->getMessage());return;}throw new RuntimeException('Expected rejection: '.$code);}
function syncRemove(string $path):void{foreach(new FilesystemIterator($path,FilesystemIterator::SKIP_DOTS)as$entry){if($entry->isDir()&&!$entry->isLink())syncRemove($entry->getPathname());else unlink($entry->getPathname());}rmdir($path);}
function sync404():Throwable{return new \GuzzleHttp\Exception\RequestException('not found',new \GuzzleHttp\Psr7\Request('GET','https://example.invalid/'),new \GuzzleHttp\Psr7\Response(404));}
$db=null;$cache=null;
try{
    $db=new ApiSqlite('data/data.db');$db->exec(file_get_contents($source.'/includes/schema.sql'));
    $db->saveConfig(['device_name_attr'=>'site','pppoe_user_attr'=>'account','pppoe_pass_attr'=>'password','ip_addr_attr'=>'ipAddress',
        'router_ppp_pool'=>true,'disable_contention'=>true,'last_cache'=>'2020-01-01']);
    foreach([1=>'Selected',2=>'Other']as$id=>$name)$db->insert(['id'=>$id,'name'=>$name,'ip'=>'192.0.2.'.$id,'port'=>8728,'type'=>'mikrotik',
        'user'=>'PRIVATE-ROUTER-USER','password'=>'PRIVATE-ROUTER-PASSWORD','pool'=>$id===1?'192.0.2.0/24':'198.51.100.0/24'],'devices');
    $cache=new ApiSqlite('data/cache.db');$cache->exec(file_get_contents($source.'/includes/cache.sql'));
    $make=static function(int$sid,string$site='Selected',int$status=1):object{return(object)['id'=>$sid,'clientId'=>$sid+1000,'status'=>$status,'servicePlanId'=>1,'attributes'=>[
        (object)['key'=>'site','value'=>$site],(object)['key'=>'account','value'=>'fixture-'.$sid],(object)['key'=>'password','value'=>'PRIVATE-ACCOUNT-PASSWORD'],
        (object)['key'=>'ipAddress','value'=>$site==='Selected'?'192.0.2.42':'198.51.100.42']]];};
    $services=[];foreach(range(101,110)as$sid)$services[$sid]=$make($sid);
    $services[103]=$make(103,'Other');$services[104]=$make(104,'Unknown terminal site',2);
    $services[105]=$make(105,'Unknown archived site');$services[106]=sync404();
    foreach([101,103,104,105,106]as$sid)$cache->insert(['id'=>$sid,'clientId'=>$sid+1000,'device'=>1,'status'=>1,'username'=>'OLD-ACCOUNT-'.$sid],'services');
    // Applied ownership remains discoverable when the desired cache has already disappeared.
    $jobs=new SQLite3('data/jobs.db');$jobs->exec('CREATE TABLE service_versions(service_id INTEGER PRIMARY KEY,applied_payload TEXT)');
    $insert=$jobs->prepare('INSERT INTO service_versions VALUES(?,?)');$insert->bindValue(1,110,SQLITE3_INTEGER);
    $insert->bindValue(2,json_encode(['id'=>110,'clientId'=>1110,'device'=>1,'username'=>'PRIVATE-OLD-ACCOUNT']));$insert->execute()->finalize();$insert->close();$jobs->close();
    $services[110]=sync404();
    $catalog=array_values(array_filter($services,static fn($raw)=>is_object($raw)&&!$raw instanceof Throwable));
    // Do not require a cancelled client to remain readable to retire its ended service.
    $clients=[];foreach(range(101,110)as$sid)$clients[$sid+1000]=['id'=>$sid+1000,'isArchived'=>false];
    $clients[1104]=new RuntimeException('PRIVATE-CLIENT-REMOVED');$clients[1105]=['id'=>1105,'isArchived'=>true];
    $clients[1107]=['id'=>999999,'isArchived'=>false];$clients[1109]=['id'=>1109,'isArchived'=>false];
    $clients[1101]+=['companyName'=>'PRIVATE-FRESH-COMPANY','firstName'=>'PRIVATE-FRESH-FIRST','lastName'=>'PRIVATE-FRESH-LAST'];
    $cache->insert(['id'=>1101,'company'=>'STALE-COMPANY','firstName'=>'STALE-FIRST','lastName'=>'STALE-LAST'],'clients');
    $calls=[];$applied=[];$retired=[];$freshNames=[];$now=time();$fail109=true;$race108=true;
    $request=static function(string$path,array$params=[],float$deadline=0)use(&$services,&$clients,&$catalog,&$calls){
        $calls[]=[$path,$params];syncCheck($deadline>microtime(true),'GET had no current bounded deadline');
        if($path==='clients/services')return array_slice($catalog,$params['offset'],$params['limit']);
        if(preg_match('~^clients/services/([1-9][0-9]*)$~D',$path,$match)){$value=$services[(int)$match[1]]??sync404();if($value instanceof Throwable)throw$value;return$value;}
        if(preg_match('~^clients/([1-9][0-9]*)$~D',$path,$match)){$value=$clients[(int)$match[1]]??null;if($value instanceof Throwable)throw$value;return$value;}
        throw new RuntimeException('PRIVATE-UNSUPPORTED-URL');
    };
    $normalize=static function(object$raw)use($db):array{return(new ApiTrim(new ApiAttributes($db)))->trim('service',$raw)['entity'];};
    $applier=static function(int$sid,bool$force,callable$guard,callable$namesReader)use(&$services,&$clients,&$applied,&$freshNames,&$fail109,&$race108,$normalize,$cache):array{
        syncCheck($force,'Immediate router synchronization lost explicit reconnect');
        if($sid===108&&$race108){$services[$sid]=clone$services[$sid];$services[$sid]->attributes[0]=(object)['key'=>'site','value'=>'Other'];}
        if($sid===102)$clients[1102]['firstName']='PRIVATE-CHANGED-BEFORE-GUARD';
        $fresh=$normalize($services[$sid]);
        if(!$guard($fresh,[]))return['provisioning'=>'awaiting_fresh_state','reason'=>'webhook_fresh_review_blocked','jobKeys'=>[],'jobCounts'=>['pending'=>0]];
        $freshNames[$sid]=$namesReader($sid+1000);syncCheck($namesReader(999999)===null,'Fresh names were supplied for another client');
        if($sid===101)syncCheck($cache->singleQuery('SELECT username FROM services WHERE id=101')==='OLD-ACCOUNT-101','Catalog overwrote legacy migration identity');
        $applied[]=$sid;
        if($sid===109&&$fail109)throw new RuntimeException('PRIVATE-UNCERTAIN-AFTER-WRITE');
        return['provisioning'=>'applied','jobKeys'=>[],'jobCounts'=>['pending'=>0]];
    };
    $retirer=static function(int$sid,float$deadline)use(&$retired):array{$retired[]=$sid;return['retired'=>true,'released'=>true,'jobKeys'=>[]];};
    $api=new ApiDeviceSync($request,$applier,$retirer,'data/data.db','data/cache.db','data/jobs.db',static function()use(&$now){return$now;});
    $before=[hash_file('sha256','data/data.db'),hash_file('sha256','data/cache.db'),hash_file('sha256','data/jobs.db')];
    $begin=$api->begin(1);syncCheck($begin['phase']==='catalog'&&!$begin['done']&&$calls===[],'Begin performed remote work or applied before caller could observe progress');
    $step=$api->step($begin['syncToken']);syncCheck($step['phase']==='applying'&&$step['catalogScanned']===8&&$step['total']===10&&$step['processed']===0,'Fresh catalog missed new services or historical/applied owners');
    syncCheck($calls[0]===['clients/services',['limit'=>500,'offset'=>0,'statuses'=>[0,1,2,3,4,5,6,7,8]]],'Catalog did not include ended lifecycle states');
    $responses=[$begin,$step];
    while(!$step['done']){$previous=$step['processed'];$step=$api->step($begin['syncToken']);syncCheck($step['processed']===$previous+1,'Step did not consume exactly one service');$responses[]=$step;}
    syncCheck($applied===[101,102,109]&&$retired===[104,105,106,110],'Scope, cancellation, archive or missing-service dispatch was wrong');
    syncCheck($freshNames[101]===['company'=>'PRIVATE-FRESH-COMPANY','firstName'=>'PRIVATE-FRESH-FIRST','lastName'=>'PRIVATE-FRESH-LAST']
        &&$freshNames[102]===['company'=>null,'firstName'=>'PRIVATE-CHANGED-BEFORE-GUARD','lastName'=>null],
        'Immediate normal pipeline did not receive freshly checked name fields or leaked lifecycle/identity properties into names');
    syncCheck($step['completed']===6&&$step['failed']===3&&$step['requested']===9&&$step['skipped']===1,'Final aggregate falsely completed uncertain or skipped services');
    $failures=array_column($step['failures'],'errorCode','serviceId');
    syncCheck($failures===[107=>'device_sync_client_unconfirmed',108=>'webhook_fresh_review_blocked',109=>'device_sync_unconfirmed'],'Service failures were missing or exposed unsafe exception details');
    $callCount=count($calls);$appliedBefore=$applied;$api->step($begin['syncToken']);
    syncCheck(count($calls)===$callCount&&$applied===$appliedBefore,'Completed cursor replayed a successful or uncertain reconnect');
    syncCheck($before===[hash_file('sha256','data/data.db'),hash_file('sha256','data/cache.db'),hash_file('sha256','data/jobs.db')],'Catalog or injected guards changed business/cache data');
    $serialized=json_encode($responses);foreach(glob('data/device-syncs/*.json')as$file)$serialized.=file_get_contents($file);
    foreach(['PRIVATE','OLD-ACCOUNT','password','fixture-101','198.51.100.42']as$secret)syncCheck(!str_contains($serialized,$secret),'Private cursor or response stored service/router secrets: '.$secret);
    // Concurrent/reentrant steps cannot race the cursor or announce completion while an account write is in flight.
    $services=[301=>$make(301)];$catalog=array_values($services);$clients[1301]=['id'=>1301,'isArchived'=>false];
    $busyApi=null;$busyToken=null;$busyCalls=0;
    $busyApplier=static function(int$sid,bool$force,callable$guard)use(&$busyApi,&$busyToken,&$busyCalls,$services,$normalize):array{
        $busyCalls++;syncReject(fn()=>$busyApi->step($busyToken),'device_sync_busy');
        $cursor=json_decode(file_get_contents('data/device-syncs/'.hash('sha256',$busyToken).'.json'),true);
        syncCheck($cursor['cursor']>0&&$cursor['failures'][(string)$sid]['errorCode']==='device_sync_unconfirmed','Side effect preceded durable consumed cursor');
        syncCheck($guard($normalize($services[$sid]),[]),'Healthy exact service/client guard failed');
        return['provisioning'=>'applied','jobCounts'=>['pending'=>0],'jobKeys'=>[]];
    };
    $busyApi=new ApiDeviceSync($request,$busyApplier,$retirer);$busy=$busyApi->begin(1);$busyToken=$busy['syncToken'];$busyApi->step($busyToken);
    do{$busyStep=$busyApi->step($busyToken);}while(!$busyStep['done']);
    syncCheck($busyCalls===1&&$busyStep['completed']===7,'Reentrant token advanced another service or duplicated the account write');
    // A client archived between observation and the normal pipeline guard cannot be activated.
    $services=[201=>$make(201)];$catalog=array_values($services);$clients[1201]=['id'=>1201,'isArchived'=>false];
    $raceApplier=static function(int$sid,bool$force,callable$guard)use(&$clients,$services,$normalize):array{
        $clients[1201]['isArchived']=true;syncCheck(!$guard($normalize($services[$sid]),[]),'Fresh guard accepted a newly archived client');
        return['provisioning'=>'awaiting_fresh_state','reason'=>'webhook_fresh_review_blocked'];
    };
    $raceApi=new ApiDeviceSync($request,$raceApplier,$retirer);$race=$raceApi->begin(1);$raceApi->step($race['syncToken']);
    // Historical ids sort first; complete this scope to exercise the new service too.
    do{$raceStep=$raceApi->step($race['syncToken']);}while(!$raceStep['done']);
    syncCheck(in_array(['serviceId'=>201,'errorCode'=>'webhook_fresh_review_blocked'],$raceStep['failures'],true),'Archive race did not remain service-local');
    // Metadata-only cache timestamps are not routing changes; endpoint/pool changes invalidate before a write.
    $services=[202=>$make(202)];$catalog=array_values($services);$clients[1202]=['id'=>1202,'isArchived'=>false];
    $session=$api->begin(1);$db->saveConfig(['last_cache'=>date('c')]);$api->step($session['syncToken']);
    $db->execute('UPDATE devices SET port=8729 WHERE id=1');
    syncReject(fn()=>$api->step($session['syncToken']),'device_sync_configuration_changed');$db->execute('UPDATE devices SET port=8728 WHERE id=1');
    $expired=$api->begin(1);$now+=3601;syncReject(fn()=>$api->step($expired['syncToken']),'device_sync_expired');$now-=3601;
    syncReject(fn()=>$api->step('../invalid'),'device_sync_invalid_token');
    // More than one collection page cannot silently miss newly moved/uncached services.
    $catalog=[];for($sid=2000;$sid<2503;$sid++)$catalog[]=$make($sid,'Other');$catalog[]=$make(2600);
    $pages=$api->begin(1);$first=$api->step($pages['syncToken']);$second=$api->step($pages['syncToken']);
    syncCheck($first['phase']==='catalog'&&$first['catalogScanned']===500&&$second['phase']==='applying'&&$second['catalogScanned']===504
        &&$second['total']===7,'Paginated discovery did not report monotonic progress or include late-page service');
    // Collection failure never falls back to applying stale cached desired attributes.
    $badApi=new ApiDeviceSync(static function(){throw new RuntimeException('PRIVATE-API-DOWN');},$applier,$retirer);
    $bad=$badApi->begin(1);$beforeApplied=$applied;syncReject(fn()=>$badApi->step($bad['syncToken']),'device_sync_catalog_unavailable');
    syncCheck($applied===$beforeApplied,'Failed fresh catalog applied stale cached service');
    // Expired sessions and their guards are removed; bounded live sessions cannot fill storage indefinitely.
    $now=max(time(),$now)+3601;$pruned=$api->begin(1);
    syncCheck(count(glob('data/device-syncs/*.json'))===1,'Expired private sync sessions were not pruned');
    syncCheck(count(glob('data/device-syncs/*.json.lock'))===0&&count(glob('data/device-syncs/*.json.run.lock'))===0,'Expired sync guard files accumulated');
    for($index=1;$index<64;$index++)$api->begin(1);
    syncReject(fn()=>$api->begin(1),'device_sync_session_limit');
    echo "Device sync passed: fresh paginated discovery, one service per step, exact scope/client guard, terminal/archive/404 cleanup, preserved migration identity, consumed uncertain writes, aggregate outcomes and private expiring configuration-bound cursors.\n";
}finally{
    if($cache instanceof ApiSqlite)$cache->close();if($db instanceof ApiSqlite)$db->close();chdir($original);set_include_path($oldInclude);syncRemove($directory);
}
