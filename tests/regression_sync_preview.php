<?php
set_error_handler(static function($severity,$message,$file,$line) { if(error_reporting()&$severity) throw new ErrorException($message,0,$severity,$file,$line); return false; });
$source=realpath(__DIR__.'/../src'); $original=getcwd(); $oldInclude=get_include_path();
$directory=sys_get_temp_dir().'/uisp-sync-preview-'.bin2hex(random_bytes(8)); mkdir($directory,0700);mkdir($directory.'/data',0700);mkdir($directory.'/vendor',0700);
file_put_contents($directory.'/vendor/autoload.php','<?php');
set_include_path($source.'/lib'.PATH_SEPARATOR.$oldInclude); chdir($directory);
require_once $source.'/lib/api_sync_preview.php';
function previewCheck(bool $ok,string $message):void { if(!$ok)throw new RuntimeException($message); }
function previewReject(callable $call,string $message):void { try{$call();}catch(Throwable $error){return;}throw new RuntimeException($message); }
function MyLog(){static $log;return $log??=new class{public function Append($event,$level=0):void{}};}
function previewRemove(string $path):void { foreach(new FilesystemIterator($path,FilesystemIterator::SKIP_DOTS) as $entry){if($entry->isDir()&&!$entry->isLink())previewRemove($entry->getPathname());else unlink($entry->getPathname());}rmdir($path); }
function previewHashes():array { $result=[];foreach(['data/data.db','data/cache.db','data/jobs.db']as$file)if(is_file($file))$result[$file]=hash_file('sha256',$file);return$result; }
$db=null;$cache=null;$queue=null;
try {
    $db=new ApiSqlite();$db->exec(file_get_contents($source.'/includes/schema.sql'));
    $db->saveConfig(['device_name_attr'=>'site','ip_addr_attr'=>'ipAddress','ip_addr6_attr'=>'ip6Address','pppoe_user_attr'=>'pppoeUsername','pppoe_pass_attr'=>'pppoePassword',
        'router_ppp_pool'=>true,'disable_contention'=>true,'active_list'=>'active','disabled_list'=>'suspended','disabled_profile'=>'disabled','disabled_rate'=>1,'excl_addr'=>'']);
    $device=['id'=>1,'name'=>'Fixture','ip'=>'192.0.2.1','port'=>8728,'type'=>'mikrotik','pool'=>'192.0.2.0/24','user'=>'PRIVATE-LOGIN','password'=>'PRIVATE-ROUTER'];
    $db->insert($device,'devices');$db->insert(['id'=>2,'name'=>'Other','ip'=>'198.51.100.1','port'=>8728,'pool'=>'198.51.100.0/24'],'devices');
    $db->insert(['id'=>1,'name'=>'Residential','uploadSpeed'=>5,'downloadSpeed'=>20,'ratio'=>1],'plans');
    $cache=new ApiSqlite('data/cache.db');$cache->exec(file_get_contents($source.'/includes/cache.sql'));
    $cache->insert(['id'=>7,'firstName'=>'Fixture','lastName'=>'Client','isArchived'=>0],'clients');
    $raw=(object)['id'=>42,'clientId'=>7,'status'=>1,'servicePlanId'=>1,'uploadSpeed'=>5,'downloadSpeed'=>20,'attributes'=>[
        (object)['key'=>'site','value'=>'Fixture'],(object)['key'=>'pppoeUsername','value'=>'fixture-account'],(object)['key'=>'pppoePassword','value'=>'PRIVATE-ACCOUNT'],(object)['key'=>'ipAddress','value'=>'192.0.2.42']]];
    $normalized=(new ApiTrim(new ApiAttributes($db)))->trim('service',$raw)['entity'];
    $cache->insert(array_diff_key($normalized,['network'=>true]),'services');$cache->insert(['id'=>42,'address'=>'192.0.2.42'],'network');
    $service=$normalized+['firstName'=>'Fixture','lastName'=>'Client','company'=>null,'_device'=>$device,'_config'=>(array)$db->readConfig(),'action'=>'update','batch'=>'fixture'];
    $plan=Batch::previewPlans($db)[1];
    $commands=MtData::previewCommands($service,$plan,new ApiIP(true,false,['db'=>$db,'cache'=>$cache,'reserved4'=>[],'reserved6'=>[],'blocked'=>[]]),$db,$cache);
    $router=[];$index=0;
    foreach($commands as$command){$path=trim($command['path'],'/');if(($command['action']??'set')==='remove')continue;
        $row=[];foreach($command as$key=>$value)if($key[0]!=='_'&&!in_array($key,['path','batch','action','password'],true))$row[$key]=$value;
        if($path==='ppp/profile'){$row['comment']='Plan #1';$row['local-address']='192.0.2.1';}
        $row['.id']='*'.dechex(++$index);$router[$path][]=$row;
    }
    $router['ip/address']=[['.id'=>'*F','address'=>'192.0.2.1/24','disabled'=>'false','dynamic'=>'false','invalid'=>'false']];
    $requests=[];$readCount=0;$now=time();
    $request=static function(string $path)use(&$requests,&$raw){$requests[]=$path;if(strpos($path,'clients/services?')===0)return[$raw];if($path==='clients/services/42')return$raw;if($path==='clients/7')return(object)['id'=>7,'isArchived'=>false];throw new RuntimeException('PRIVATE-NETWORK');};
    $reader=static function(object$device,string$path,array$filter,float$deadline)use(&$router,&$readCount):array{$readCount++;$rows=$router[trim($path,'/')]??[];return array_values(array_filter($rows,static function(array$row)use($filter):bool{foreach($filter as$key=>$value)if((string)($row[$key]??'')!==(string)$value)return false;return true;}));};
    $api=new ApiSyncPreview($request,$reader,null,'data/data.db','data/cache.db','data/jobs.db',static function()use(&$now){return$now;});
    $before=previewHashes();$begin=$api->begin(1);previewCheck(!$begin['done']&&!$begin['catalogComplete'],'Preview omitted live catalog stage');
    $catalog=$api->step($begin['previewToken']);previewCheck($catalog['catalogComplete']&&$catalog['total']===1,'Live catalog failed');
    $review=$api->step($begin['previewToken']);
    previewCheck($review['done']&&$review['items'][0]['state']==='unchanged','Matching account did not compare unchanged: '.json_encode($review));
    previewCheck(previewHashes()===$before&&!is_file('data/jobs.db'),'Read-only preview initialized or mutated business state');
    previewCheck(strpos(json_encode([$begin,$catalog,$review]),'PRIVATE')===false,'Preview disclosed credentials');
    $fields=$review['items'][0]['fields'];previewCheck(in_array('rate-limit',array_column($fields,'field'),true)&&in_array('remote-address',array_column($fields,'field'),true),'Preview did not compare effective rates/address');
    // A rate-only discrepancy is a real change; external/ambiguous ownership stays blocked even in forced mode.
    $router['ppp/profile'][0]['rate-limit']='1M/1M';$begin=$api->begin(1);$api->step($begin['previewToken']);$review=$api->step($begin['previewToken']);
    previewCheck($review['items'][0]['state']==='changes'&&$review['items'][0]['reconnect']===true,'Rate-only change was not proposed');
    $router['ppp/secret'][0]['comment']='Manual account';$begin=$api->begin(1);$api->step($begin['previewToken']);$review=$api->step($begin['previewToken']);
    previewCheck($review['items'][0]['state']==='blocked'&&$review['items'][0]['reason']==='sync_preview_ownership_unconfirmed','Manual account was adopted');
    $count=$readCount;$result=$api->applyStep($begin['previewToken'],'force_reconnect');previewCheck($result['state']==='blocked'&&$readCount===$count,'Forced mode bypassed uncertain ownership');
    $router['ppp/secret'][0]['comment']=$commands[count($commands)-2]['comment']??'7 - Fixture Client - 42';
    // Exact row ambiguity cannot be approved by forcing.
    $router['ppp/secret'][0]['comment']='7 - Fixture Client - 42';$router['ppp/secret'][]=$router['ppp/secret'][0];
    $begin=$api->begin(1);$api->step($begin['previewToken']);$review=$api->step($begin['previewToken']);previewCheck($review['items'][0]['reason']==='sync_preview_identity_ambiguous','Ambiguous account was accepted');array_pop($router['ppp/secret']);
    // New uncached services are discovered; missing account proposes a pool IP without creating reservations.
    $raw->attributes=array_values(array_filter($raw->attributes,static fn($a)=>$a->key!=='ipAddress'));$router['ppp/secret']=[];
    $begin=$api->begin(1);$api->step($begin['previewToken']);$review=$api->step($begin['previewToken']);previewCheck($review['items'][0]['state']==='changes'&&filter_var($review['items'][0]['newAddress'],FILTER_VALIDATE_IP),'Automatic allocation did not produce a read-only IP proposal: '.json_encode($review));
    previewCheck(previewHashes()===$before&&!is_file('data/jobs.db'),'Automatic proposal reserved an address');
    // Bounded token expiry, mode validation, configuration drift and transport failures fail closed.
    previewReject(fn()=>$api->applyStep($begin['previewToken'],'overwrite'),'Unknown apply mode accepted');
    $db->saveConfig(['active_list'=>'changed']);previewReject(fn()=>$api->applyStep($begin['previewToken'],'differences'),'Stale configuration accepted');$db->saveConfig(['active_list'=>'active']);
    $now+=601;previewReject(fn()=>$api->step($begin['previewToken']),'Expired preview accepted');$now-=601;
    previewReject(fn()=>$api->step('../jobs.db'),'Unsafe token accepted');
    $failing=new ApiSyncPreview($request,static function(){throw new RuntimeException('PRIVATE-ERROR');},null,'data/data.db','data/cache.db','data/jobs.db',static function()use(&$now){return$now;});
    $begin=$failing->begin(1);$failing->step($begin['previewToken']);$failed=$failing->step($begin['previewToken']);previewCheck($failed['items'][0]['state']==='blocked'&&strpos(json_encode($failed),'PRIVATE')===false,'Failed observation was treated as applicable or leaked error');
    $readOnly=new ApiSqlite('data/data.db',true);previewReject(fn()=>$readOnly->execute("UPDATE config SET value='modified'"),'Read-only adapter wrote data');$readOnly->close();
    $missing='data/missing.db';$readOnly=new ApiSqlite($missing,true);previewReject(fn()=>$readOnly->selectCustom('SELECT 1'),'Read-only adapter created missing database');previewCheck(!is_file($missing),'Missing read-only DB was created');
    // Apply goes through the real fresh-state action and normal queue, freezing exactly the reviewed proposal.
    require_once $source.'/lib/api_action.php'; $apiSqlite=$db;$apiSqliteCache=$cache;$apiIpClass=new ApiIP();
    // Omitted live overrides differ from stale cache values; fresh intent and effective builder state have separate fingerprints.
    unset($raw->uploadSpeed,$raw->downloadSpeed);
    $queue=myJobQueue();$applyCount=0;
    $applier=static function(int$sid,bool$force,callable$guard,callable$sink)use($request,&$applyCount):array{$applyCount++;return(new ApiAction(null,new ApiWebhookFreshness($request)))->reconcileService($sid,true,$force,$guard,$sink);};
    $api=new ApiSyncPreview($request,$reader,$applier,'data/data.db','data/cache.db','data/jobs.db',static function()use(&$now){return$now;});
    $stale=$api->begin(1);$api->step($stale['previewToken']);$api->step($stale['previewToken']);
    foreach($raw->attributes as$attribute)if($attribute->key==='pppoePassword')$attribute->value='PRIVATE-NEW';
    $blocked=$api->applyStep($stale['previewToken'],'differences');previewCheck($blocked['state']==='blocked'&&$queue->list()===[]&&$db->selectAddress(42)===null,'Fresh intent drift wrote stale work');
    foreach($raw->attributes as$attribute)if($attribute->key==='pppoePassword')$attribute->value='PRIVATE-ACCOUNT';
    $after=$api->applyStep($stale['previewToken'],'differences');previewCheck($after['state']==='complete','Rejected consumed item was replayed');
    $stale=$api->begin(1);$api->step($stale['previewToken']);$proposal=$api->step($stale['previewToken']);
    $db->insert(['id'=>99,'address'=>$proposal['items'][0]['newAddress']],'network');
    $blocked=$api->applyStep($stale['previewToken'],'differences');previewCheck($blocked['state']==='blocked'&&$queue->list()===[]&&$db->selectAddress(42)===null,'Changed pool availability queued an unreviewed IP');$db->execute('DELETE FROM network WHERE id=99');
    $stale=$api->begin(1);$api->step($stale['previewToken']);$api->step($stale['previewToken']);
    $router['ppp/secret']=[['.id'=>'*A','name'=>'fixture-account','comment'=>'Manual account']];
    $blocked=$api->applyStep($stale['previewToken'],'force_reconnect');previewCheck($blocked['state']==='blocked'&&$queue->list()===[],'Forced apply bypassed newly conflicting router ownership');$router['ppp/secret']=[];
    // A failed frozen-command verifier rolls back both reservation and allocation proof.
    $rejected=$normalized;$rejected['network']=['address'=>null,'address6'=>null];$rejected+=['company'=>null,'firstName'=>'Fixture','lastName'=>'Client'];
    previewReject(fn()=>(new Batch())->queueReviewed($rejected,static fn()=>false),'Mismatched prepared commands were enqueued');
    previewCheck($db->selectAddress(42)===null&&$queue->list()===[],'Rejected frozen commands left a reservation or job');
    $begin=$api->begin(1);$api->step($begin['previewToken']);$review=$api->step($begin['previewToken']);$proposed=$review['items'][0]['newAddress'];
    $applied=$api->applyStep($begin['previewToken'],'differences');
    previewCheck($applied['state']==='queued'&&count($applied['jobKeys'])===1,'Real apply did not enqueue the reviewed plan: '.json_encode($applied));
    $queue=myJobQueue();$jobs=$queue->list();$job=$jobs[$applied['jobKeys'][0]];
    previewCheck($db->selectAddress(42)===$proposed,'Queued allocation differs from reviewed IP');
    $jobsDb=new ApiSqlite('data/jobs.db',true);$payload=json_decode($jobsDb->singleQuery('SELECT payload FROM jobs WHERE service_id=42'),true);$jobsDb->close();
    previewCheck(isset($payload['_commands'])&&count($payload['_commands'])>1,'Reviewed enqueue deferred allocation until future execution');
    $account=array_values(array_filter($payload['_commands'],static fn($c)=>trim($c['path'],'/')==='ppp/secret'))[0];
    previewCheck($account['remote-address']===$proposed&&$account['password']==='PRIVATE-ACCOUNT','Frozen queued commands lost the reviewed allocation or normal credentials');
    previewCheck($cache->singleQuery('SELECT uploadSpeed IS NULL AND downloadSpeed IS NULL FROM services WHERE id=42')===1,'Preview apply revived omitted cached speed overrides');
    $count=$applyCount;$again=$api->applyStep($begin['previewToken'],'differences');previewCheck($again['done']&&$again['state']==='complete'&&$applyCount===$count,'Transport retry enqueued an item twice');
    previewCheck(strpos(json_encode([$applied,$again]),'PRIVATE')===false,'Apply result disclosed credentials');
    $pending=$api->begin(1);$api->step($pending['previewToken']);$review=$api->step($pending['previewToken']);previewCheck($review['items'][0]['reason']==='sync_preview_pending_work','Existing executable work was overwritten by a preview');
    $queue->runSelected($applied['jobKeys'],static fn()=>true);
    $snapshot=$queue->appliedSnapshot(42);$snapshot['address']=$proposed;
    $jobsDb=new ApiSqlite('data/jobs.db');$jobsDb->execute('UPDATE service_versions SET applied_payload=? WHERE service_id=42',[json_encode($snapshot)]);$jobsDb->close();
    $automatic=$api->begin(1);$api->step($automatic['previewToken']);$review=$api->step($automatic['previewToken']);
    previewCheck($review['items'][0]['newAddress']===$proposed,'Explicit applied network null became a new allocation on every review');
    // A migration also observes the old account; uncertain source ownership cannot retire a different account.
    $prior=$router;$prior['ppp/secret']=[$account+['.id'=>'*B']];unset($prior['ppp/secret'][0]['password']);
    $target=['ip/address'=>[['.id'=>'*F','address'=>'198.51.100.1/24','disabled'=>'false','dynamic'=>'false','invalid'=>'false']]];
    $migrationReader=static function(object$device,string$path,array$filter,float$deadline)use(&$prior,&$target):array{$rows=((int)$device->id===1?$prior:$target)[trim($path,'/')]??[];return array_values(array_filter($rows,static function($row)use($filter):bool{foreach($filter as$key=>$value)if((string)($row[$key]??'')!==(string)$value)return false;return true;}));};
    foreach($raw->attributes as$attribute)if($attribute->key==='site')$attribute->value='Other';$raw->attributes[]=(object)['key'=>'ipAddress','value'=>'198.51.100.42'];
    $migrationApi=new ApiSyncPreview($request,$migrationReader,$applier,'data/data.db','data/cache.db','data/jobs.db',static function()use(&$now){return$now;});
    $prior['ppp/secret'][0]['comment']='Different customer';$begin=$migrationApi->begin(2);$migrationApi->step($begin['previewToken']);$review=$migrationApi->step($begin['previewToken']);
    previewCheck($review['items'][0]['reason']==='sync_preview_ownership_unconfirmed','Migration ignored ownership of the old account');
    $prior['ppp/secret'][0]['comment']='7 - Fixture Client - 42';$begin=$migrationApi->begin(2);$migrationApi->step($begin['previewToken']);$review=$migrationApi->step($begin['previewToken']);
    previewCheck($review['items'][0]['migration']===true&&$review['items'][0]['sourceDeviceId']===1&&$review['items'][0]['oldAddress']===$proposed&&$review['items'][0]['newAddress']==='198.51.100.42','Migration omitted actual source cleanup or concrete destination');
    $applied=$migrationApi->applyStep($begin['previewToken'],'differences');previewCheck($applied['state']==='queued'&&count($applied['jobKeys'])===2,'Reviewed migration did not preserve ordered cleanup/replacement: '.json_encode($applied));
    foreach(glob('data/sync-previews/*.json')as$file)previewCheck(strpos(file_get_contents($file),'PRIVATE')===false,'Private preview session retained raw credentials');
    echo "PASS regression_sync_preview\n";
}finally{if($queue)$queue->close();if($db)$db->close();if($cache)$cache->close();chdir($original);set_include_path($oldInclude);previewRemove($directory);}
