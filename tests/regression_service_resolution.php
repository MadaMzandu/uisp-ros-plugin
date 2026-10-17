<?php
// Fresh UISP evidence, real durable queue/verification receipts and an isolated mutable RouterOS fixture.
set_error_handler(static function($severity,$message,$file,$line){if(error_reporting()&$severity)throw new ErrorException($message,0,$severity,$file,$line);return false;});
$source=realpath(__DIR__.'/../src');$original=getcwd();$include=get_include_path();
$directory=sys_get_temp_dir().'/uisp-service-resolution-'.bin2hex(random_bytes(8));mkdir($directory,0700);
foreach(['data','vendor','lib','includes']as$folder)mkdir($directory.'/'.$folder,0700);
foreach(glob($source.'/lib/*.php')as$file)copy($file,$directory.'/lib/'.basename($file));
foreach(glob($source.'/includes/*')as$file)if(is_file($file))copy($file,$directory.'/includes/'.basename($file));
$batch=str_replace('new MT()','new ResolutionMT()',file_get_contents($directory.'/lib/batch.php'),$replaced);
if($replaced!==1)throw new RuntimeException('The RAM adapter constructor hook was not precise');file_put_contents($directory.'/lib/batch.php',$batch);
file_put_contents($directory.'/vendor/autoload.php', <<<'PHP'
<?php
namespace Ubnt\UcrmPluginSdk\Service;
class UcrmApi {
    public static function create(){return new self();}
    public function get($path,$parameters=[]){return($GLOBALS['resolutionHttp'])($path,$parameters);}
    public function patch($path,$parameters=[]){throw new \RuntimeException('Private external mutation prohibited');}
}
PHP);
chdir($directory);set_include_path($directory.'/lib'.PATH_SEPARATOR.$include);
require_once $directory.'/lib/api_setup.php';require_once $directory.'/lib/api_service_resolution.php';
function resolutionCheck(bool$ok,string$message):void{if(!$ok)throw new RuntimeException($message);}
function resolutionReject(callable$call,string$message):void{try{$call();}catch(Throwable$error){return;}throw new RuntimeException($message);}
function resolutionRemove(string$path):void{foreach(new FilesystemIterator($path,FilesystemIterator::SKIP_DOTS)as$entry){if($entry->isDir()&&!$entry->isLink())resolutionRemove($entry->getPathname());else unlink($entry->getPathname());}rmdir($path);}
$db=null;$cache=null;$queue=null;
require_once $directory.'/lib/routeros_api.class.php';
class ResolutionRouterAPI extends RouterosAPI
{
    private array $pending=[];private array $response=[];
    public function write($word,$param2=true){$this->pending[]=$word;return true;}
    public function finishSentence():void{
        $words=$this->pending;$this->pending=[];$full=trim(array_shift($words),'/');$separator=strrpos($full,'/');$path=substr($full,0,$separator);$action=substr($full,$separator+1);$values=[];
        foreach($words as$word)if(strpos($word,'=')===0){[$key,$value]=explode('=',substr($word,1),2);$values[$key]=$value;}
        $GLOBALS['resolutionNativeWrites'][]=[$path,$action,$values];$rows=&$GLOBALS['resolutionRouter'][$path];if(!is_array($rows))$rows=[];
        if($action==='set'){foreach($rows as&$row)if(($row['.id']??null)===($values['.id']??null))$row=array_replace($row,$values);unset($row);}
        elseif($action==='add'){$id='*'.dechex(++$GLOBALS['resolutionRouterSequence']);$rows[]=['.id'=>$id]+$values;}
        elseif($action==='remove'){$ids=explode(',',$values['.id']??'');$rows=array_values(array_filter($rows,static fn($row)=>!in_array($row['.id']??null,$ids,true)));}
        $this->response=[];
    }
    public function read($parse=true){$result=$this->response;$this->response=[];return$result;}
    public function disconnect(){$this->connected=false;}
}
require_once $directory.'/lib/mt.php';
class ResolutionMT extends MT
{
    private ResolutionRouterAPI $recording;
    public function __construct(){parent::__construct();$this->recording=new ResolutionRouterAPI();}
    protected function api_connect():?RouterosAPI{$this->recording->connected=true;return$this->recording;}
    protected function read($path,$filter=null){
        if(is_string($filter)){$parsed=[];foreach(explode(',',$filter)as$item){[$key,$value]=explode('=',ltrim($item,'?'),2);$parsed[$key]=$value;}$filter=$parsed;}
        return array_values(array_filter($GLOBALS['resolutionRouter'][trim($path,'/')]??[],static function($row)use($filter){foreach((array)$filter as$key=>$value)if((string)($row[$key]??'')!==(string)$value)return false;return true;}));
    }
}
try{
    $db=new ApiSqlite('data/data.db');$db->exec(file_get_contents($source.'/includes/schema.sql'));
    $db->saveConfig(json_decode(file_get_contents($source.'/includes/conf_default.json'),true));
    $db->saveConfig(['device_name_attr'=>'site','pppoe_user_attr'=>'account','pppoe_pass_attr'=>'pass','ip_addr_attr'=>'manualIp',
        'disable_contention'=>true,'router_ppp_pool'=>true]);
    $device=['id'=>1,'name'=>'Fixture Site','ip'=>'192.0.2.1','port'=>8728,'user'=>'PRIVATE-ROUTER-USER','password'=>'PRIVATE-ROUTER-PASSWORD','type'=>'mikrotik','pool'=>'192.0.2.0/24'];
    $db->insert($device,'devices');$db->insert(['id'=>9,'name'=>'Fixture Plan','uploadSpeed'=>5,'downloadSpeed'=>20,'ratio'=>1],'plans');
    $cache=new ApiSqlite('data/cache.db');$cache->exec(file_get_contents($source.'/includes/cache.sql'));
    $raw=['id'=>42,'clientId'=>17,'status'=>1,'servicePlanId'=>9,'attributes'=>[
        ['key'=>'site','value'=>'Fixture Site'],['key'=>'account','value'=>'fixture-account'],['key'=>'pass','value'=>'PRIVATE-UISP-SECRET'],['key'=>'manualIp','value'=>'192.0.2.42']]];
    $client=['id'=>17,'isArchived'=>false,'firstName'=>'Fixture','lastName'=>'Customer'];
    $catalog=[];foreach(['site','account','pass','manualIp']as$i=>$key)$catalog[]=['id'=>$i+1,'key'=>$key,'attributeType'=>'service'];
    $peers=[];$otherClients=[];$calls=[];$writes=[];$applies=0;$writeFailure=false;$verify=true;$now=time();$readHook=null;
    $base=['.id'=>'*A1','name'=>'fixture-account','comment'=>'17 - Fixture Customer - 42','profile'=>'Fixture Profile','remote-address'=>'192.0.2.42','password'=>'PRIVATE-ACCOUNT-PASSWORD','disabled'=>'false'];
    $router=['ppp/secret'=>[$base],'queue/simple'=>[]];
    $GLOBALS['resolutionRouter']=&$router;$GLOBALS['resolutionNativeWrites']=[];$GLOBALS['resolutionRouterSequence']=256;
    $request=static function(string$path,array$parameters=[])use(&$raw,&$client,&$catalog,&$peers,&$otherClients,&$calls){
        $calls[]=$path;
        if($path==='custom-attributes')return$catalog;
        if($path==='clients/services/42')return$raw;
        if($path==='clients/17')return$client;
        if(preg_match('#^clients/([1-9][0-9]*)$#D',$path,$m)&&isset($otherClients[(int)$m[1]]))return$otherClients[(int)$m[1]];
        if($path==='clients/services')return array_slice(array_merge([$raw],array_values($peers)),(int)($parameters['offset']??0),(int)($parameters['limit']??500));
        if(preg_match('#^clients/services/([1-9][0-9]*)$#D',$path,$m)&&isset($peers[(int)$m[1]]))return$peers[(int)$m[1]];
        throw new RuntimeException('PRIVATE-HTTP-ERROR');
    };
    $GLOBALS['resolutionHttp']=$request;
    $reader=static function(array$device,string$path,array$selector,array$fields)use(&$router,&$readHook):array{
        if($readHook!==null)$readHook($path,$selector);
        return array_values(array_map(static fn(array$row)=>array_intersect_key($row,array_fill_keys($fields,true)),array_filter($router[$path]??[],static function(array$row)use($selector):bool{
            foreach($selector as$key=>$value)if((string)($row[$key]??'')!==(string)$value)return false;return true;
        })));
    };
    $writer=static function(array$device,string$path,string$action,string$id,array$values,array$expected)use(&$router,&$writes,&$writeFailure):void{
        $writes[]=[$path,$action,$id,$values];
        foreach($router[$path]??[]as$index=>$row)if($row['.id']===$id){
            if($action==='set')$router[$path][$index]=array_replace($row,$values);
            elseif($action==='remove'){unset($router[$path][$index]);$router[$path]=array_values($router[$path]);}
        }
        if($writeFailure)throw new RuntimeException('PRIVATE-TRANSPORT-ERROR');
    };
    $applier=static function(int$sid,callable$guard,callable$namesReader,ApiJobQueue$jobs)use(&$raw,&$router,&$applies,&$verify,$db,$cache,$device):array{
        $applies++;$service=(new ApiTrim(new ApiAttributes($db)))->trim('service',json_decode(json_encode($raw)))['entity'];
        resolutionCheck($guard($service,$jobs->appliedSnapshot($sid)),'Fresh reconciliation guard failed');
        $service=array_replace($service,$namesReader(17));$service['_device']=$device;$service['action']='update';
        $key=$jobs->enqueue($service);$jobs->runSelected([$key],static function(array$snapshot,string$operation,array$job,ApiJobQueue$queue)use(&$router,&$verify,$service,$device):bool{
            $comment='17 - '.$service['firstName'].' '.$service['lastName'].' - 42';
            foreach($router['ppp/secret']as&$row){$row['comment']=$comment;$row['profile']='Fixture Profile';}unset($row);
            $checks=[['kind'=>'resource','path'=>'/ppp/secret','action'=>'set','filter'=>['name'=>'fixture-account'],'expected'=>['comment'=>$verify?$comment:'different expected comment']]];
            $stage=ApiProvisioningVerification::stage((object)$device,$checks,static fn()=>array_map(static fn($row)=>array_diff_key($row,['password'=>true]),$router['ppp/secret']));
            $queue->recordVerification($service,$job,(object)$device,[$stage]);return true;
        });
        return['provisioning'=>'applied','jobKeys'=>[$key]];
    };
    $make=static function()use($request,$reader,$writer,$applier,&$now){return new ApiServiceResolution($request,$reader,$writer,$applier,'data/data.db','data/cache.db','data/jobs.db',static function()use(&$now){return$now;});};
    $hashes=[hash_file('sha256','data/data.db'),hash_file('sha256','data/cache.db')];
    $preview=$make()->preview(42);
    resolutionCheck($preview['eligible']&&$preview['mode']==='reconcile'&&$preview['autoApply']&&preg_match('/^[a-f0-9]{64}$/D',$preview['previewToken'])===1,'Owned account did not offer fresh reconciliation: '.json_encode($preview));
    resolutionCheck(!is_file('data/jobs.db')&&$writes===[]&&$hashes===[hash_file('sha256','data/data.db'),hash_file('sha256','data/cache.db')],'Preview initialized queue or mutated business data');
    foreach(['PRIVATE','password','fingerprint']as$secret)resolutionCheck(strpos(json_encode($preview),$secret)===false,'Preview disclosed private evidence');
    foreach(glob('data/service-resolution-previews/*.json')as$file)resolutionCheck(strpos(file_get_contents($file),'PRIVATE')===false,'Private preview retained raw credentials');
    // A complete catalog scan protects ownership; unrelated metadata must not
    // invalidate a reviewed account while customers are being edited elsewhere.
    $unrelated=$raw;$unrelated['id']=43;$unrelated['clientId']=18;$unrelated['price']=10;
    foreach($unrelated['attributes']as&$attribute)if($attribute['key']==='account')$attribute['value']='unrelated-account';unset($attribute);
    $peers[43]=$unrelated;$preview=$make()->preview(42);$peers[43]['price']=11;
    $result=$make()->apply($preview['previewToken']);$peers=[];
    resolutionCheck($result['state']==='resolved'&&$result['verificationState']==='verified'&&count($result['jobKeys'])===1&&$applies===1,'Confirmed normal reconciliation was not resolved: '.json_encode($result));
    resolutionCheck(ApiServiceResolution::backupName($result['backupName'])&&is_file('data/'.$result['backupName']),'Repair omitted its private backup');
    $count=$applies;resolutionReject(fn()=>$make()->apply($preview['previewToken']),'Consumed token was replayed');resolutionCheck($applies===$count,'Token replay reached reconciler');
    $queue=myJobQueue();
    // A newly appearing current claimant must still invalidate the review
    // before a backup, queue cancellation, account edit or reconnection.
    $preview=$make()->preview(42);$competing=$raw;$competing['id']=43;$peers[43]=$competing;
    $writeCount=count($writes);$applyCount=$applies;$backupCount=count(glob('data/router-resolution-*.json'));
    $competition=$make()->apply($preview['previewToken']);$peers=[];
    resolutionCheck($competition['state']==='blocked'&&$competition['reason']==='resolution_changed'
        &&count($writes)===$writeCount&&$applies===$applyCount&&count(glob('data/router-resolution-*.json'))===$backupCount,
        'A newly added current competing service was ignored or allowed repair writes');
    // Manual principals require selection; a row id is not a candidate token.
    $router['ppp/secret'][0]['comment']='<img src=x onerror=alert(1)>';
    $preview=$make()->preview(42);resolutionCheck($preview['mode']==='select_account'&&!$preview['autoApply']&&$preview['candidates'][0]['selectable'],'Manual account was silently adopted or not reviewable');
    $blocked=$make()->apply($preview['previewToken']);resolutionCheck($blocked['state']==='blocked'&&$blocked['reason']==='resolution_selection_required'&&$writes===[],'Manual account did not require explicit selection');
    $preview=$make()->preview(42);$chosen=$preview['candidates'][0]['candidateId'];resolutionCheck($chosen!==$preview['candidates'][0]['rowId'],'Candidate selector was not opaque');
    $result=$make()->apply($preview['previewToken'],[$chosen]);
    resolutionCheck($result['state']==='resolved'&&$router['ppp/secret'][0]['comment']==='17 - Fixture Customer - 42'&&count($writes)===1,'Explicit manual adoption did not use exact-row repair and verified reconciliation');
    $backup=json_decode(file_get_contents('data/'.$result['backupName']),true);resolutionCheck($backup['rows'][0]['comment']==='<img src=x onerror=alert(1)>'&&$backup['rows'][0]['password']==='PRIVATE-ACCOUNT-PASSWORD','Manual repair did not preserve its complete prior account privately');
    // A fresh change between preview and apply consumes the old review without touching the router.
    $preview=$make()->preview(42);$raw['attributes'][3]['value']='192.0.2.43';$count=count($writes);$appliedCount=$applies;
    $result=$make()->apply($preview['previewToken']);resolutionCheck($result['state']==='blocked'&&count($writes)===$count&&$applies===$appliedCount,'Changed UISP intent applied stale evidence');$raw['attributes'][3]['value']='192.0.2.42';
    // Duplicate principals can remove only the explicitly proved owned extras, after a full restorable backup.
    $router['ppp/secret'][]=array_replace($base,['.id'=>'*A2','password'=>'PRIVATE-SECOND-PASSWORD']);
    $preview=$make()->preview(42);$chosen=$preview['candidates'][0]['candidateId'];$result=$make()->apply($preview['previewToken'],[$chosen]);
    resolutionCheck($result['state']==='resolved'&&count($router['ppp/secret'])===1&&$router['ppp/secret'][0]['.id']==='*A1','Reviewed duplicate removal did not keep the selected row');
    $backup=json_decode(file_get_contents('data/'.$result['backupName']),true);resolutionCheck(count($backup['rows'])===2&&$backup['rows'][1]['password']==='PRIVATE-SECOND-PASSWORD','Duplicate removal did not preserve a restorable secret');
    $router['ppp/secret'][]=array_diff_key(array_replace($base,['.id'=>'*A2']),['password'=>true]);
    $preview=$make()->preview(42);$count=count($writes);$result=$make()->apply($preview['previewToken'],[$preview['candidates'][0]['candidateId']]);
    resolutionCheck($result['state']==='blocked'&&$result['reason']==='resolution_backup_unavailable'&&count($writes)===$count&&count($router['ppp/secret'])===2,'An unreadable account secret was deleted');array_pop($router['ppp/secret']);
    // An uncertain mutation never auto-replays or claims success.
    $router['ppp/secret'][0]['comment']='manual';$preview=$make()->preview(42);$writeFailure=true;
    $result=$make()->apply($preview['previewToken'],[$preview['candidates'][0]['candidateId']]);$writeFailure=false;$count=count($writes);
    resolutionCheck($result['state']==='unconfirmed'&&$result['backupName']!==null&&strpos(json_encode($result),'PRIVATE')===false,'A partial write was falsely completed or exposed transport errors');
    resolutionReject(fn()=>$make()->apply($preview['previewToken'],[$preview['candidates'][0]['candidateId']]),'Uncertain token was replayable');resolutionCheck(count($writes)===$count,'Uncertain replay wrote again');
    // Derived duplicate queues retain the complete chosen row and shared dependency guards.
    $cap=['.id'=>'*B1','name'=>'uisp-service-42-suspension','comment'=>'17 - Fixture Customer - 42','target'=>'192.0.2.42/32','max-limit'=>'5M/20M','disabled'=>'false'];
    $router['queue/simple']=[$cap,array_replace($cap,['.id'=>'*B2']),['.id'=>'*B3','name'=>'dependent-queue','parent'=>$cap['name']]];
    $preview=$make()->preview(42);$count=count($writes);$result=$make()->apply($preview['previewToken']);
    resolutionCheck($result['state']==='blocked'&&$result['reason']==='resolution_derived_in_use'&&count($writes)===$count&&count($router['queue/simple'])===3,'Derived queue deletion bypassed a live child dependency');
    array_pop($router['queue/simple']);$preview=$make()->preview(42);$result=$make()->apply($preview['previewToken']);
    resolutionCheck($result['state']==='resolved'&&count($router['queue/simple'])===1&&$router['queue/simple'][0]['.id']==='*B1','Owned derived duplicates remained after resolution');
    $backup=json_decode(file_get_contents('data/'.$result['backupName']),true);resolutionCheck(count($backup['derivedRepairs'][0]['rows'])===2&&$backup['derivedRepairs'][0]['rows'][1]['max-limit']==='5M/20M','Derived duplicate backup could not restore its queue configuration');
    $router['queue/simple']=[];
    $verify=false;$preview=$make()->preview(42);$result=$make()->apply($preview['previewToken']);$verify=true;
    resolutionCheck($result['state']==='unconfirmed'&&$result['verificationState']==='mismatch','Completed execution without matching read-back was reported resolved');
    // Freshly archived clients and an actual competing current account remain protected.
    $client['isArchived']=true;$preview=$make()->preview(42);resolutionCheck(!$preview['eligible']&&$preview['previewToken']===null,'Archived client offered account adoption');$client['isArchived']=false;
    $peer=$raw;$peer['id']=43;$peers[43]=$peer;$preview=$make()->preview(42);resolutionCheck(!$preview['eligible'],'A second live UISP claim was ignored');$peers=[];
    // Pending stale updates are cancelled locally; running and cleanup work cannot be forced through.
    $snapshot=(new ApiTrim(new ApiAttributes($db)))->trim('service',json_decode(json_encode($raw)))['entity'];$snapshot['_device']=$device;
    $old=$queue->enqueue($snapshot);$preview=$make()->preview(42);$result=$make()->apply($preview['previewToken']);
    $oldState=$queue->selectionStatus([$old])[$old];
    resolutionCheck(in_array($result['state'],['resolved','unconfirmed'],true)&&in_array($oldState['state'],['cancelled','cleaned'],true)&&$oldState['attempts']===0,'Reviewed repair replayed its old pending update: '.json_encode([$result,$oldState]));
    // Exercise the default ApiAction -> Batch -> MtData -> MT applier after a narrowly reviewed ownership repair.
    $apiSqlite=$db;$apiSqliteCache=$cache;$apiIpClass=new ApiIP();
    $peers[41]=['id'=>41,'clientId'=>33,'status'=>2,'servicePlanId'=>9,'attributes'=>[
        ['key'=>'site','value'=>'Fixture Site'],['key'=>'account','value'=>'former-account']]];
    $router['ppp/secret']=[$base];
    $router['ip/address']=[['.id'=>'*F1','address'=>'192.0.2.1/24','disabled'=>'false','dynamic'=>'false','invalid'=>'false']];
    $router['ppp/active']=[['.id'=>'*F2','name'=>'fixture-account']];$router['ppp/profile']=[];
    require_once $directory.'/lib/batch.php';
    $baseline=$snapshot+['company'=>null,'firstName'=>'Fixture','lastName'=>'Customer'];
    resolutionCheck((new Batch())->set_accounts([$baseline]),'Real repair baseline could not be applied');
    $router['ppp/secret'][0]['comment']='33 - Former Customer - 41';$router['ppp/secret'][0]['remote-address']='192.0.2.21';
    $router['ppp/active']=[['.id'=>'*F2','name'=>'fixture-account']];
    $stale=$snapshot;$stale['network']=['address'=>'192.0.2.21','address6'=>null];$stale+=['company'=>null,'firstName'=>'Fixture','lastName'=>'Customer'];
    $oldKeys=(new Batch())->queueReviewed($stale,static fn()=>true,false);
    $real=new ApiServiceResolution($request,$reader,$writer,null);$preview=$real->preview(42);$nativeStart=count($GLOBALS['resolutionNativeWrites']);
    resolutionCheck($preview['eligible']&&$preview['autoApply'],'Retired former-owner fixture was not safely reviewable: '.json_encode($preview));
    $result=$real->apply($preview['previewToken']);
    resolutionCheck($result['state']==='resolved'&&$result['verificationState']==='verified'&&count($router['ppp/secret'])===1
        &&$router['ppp/secret'][0]['comment']==='17 - Fixture Customer - 42'&&$router['ppp/secret'][0]['remote-address']==='192.0.2.42'
        &&$router['ppp/active']===[],'Default fresh repair failed to apply and verify the account: '.json_encode([$result,$queue->selectionStatus($result['jobKeys'])]));
    foreach($queue->selectionStatus($oldKeys)as$old)resolutionCheck(in_array($old['state'],['cancelled','cleaned'],true)&&$old['attempts']===0,'Real repair executed a stale prepared update');
    $native=array_slice($GLOBALS['resolutionNativeWrites'],$nativeStart);resolutionCheck((bool)array_filter($native,static fn($row)=>$row[0]==='ppp/secret'&&in_array($row[1],['set','add'],true)), 'Default repair never reached real MT account writes');
    resolutionCheck($queue->completed($result['jobKeys']),'Default repair left its normal queue job incomplete');
    // An exact-principal manual duplicate must not force the administrator to
    // discard the account that already has the correct current owner.
    $owned=$router['ppp/secret'][0];$manual=array_replace($owned,['.id'=>'*A3','comment'=>'','profile'=>'Manual Fixture Profile','password'=>'PRIVATE-MANUAL-PASSWORD']);
    $router['ppp/secret'][]=$manual;$preview=$real->preview(42);
    $ownedCandidate=array_values(array_filter($preview['candidates'],static fn($candidate)=>$candidate['rowId']===$owned['.id']))[0];
    resolutionCheck($preview['eligible']&&$preview['mode']==='select_account'&&!$preview['autoApply']&&$ownedCandidate['selectable']
        &&$ownedCandidate['removeRowIds']===['*A3']&&$ownedCandidate['manualDuplicateRowIds']===['*A3'],
        'A manual duplicate prevented selecting the correctly owned account: '.json_encode($preview));
    $beforeRows=$router['ppp/secret'];$nativeStart=count($GLOBALS['resolutionNativeWrites']);$repairStart=count($writes);$backupStart=count(glob('data/router-resolution-*.json'));
    $result=$real->apply($preview['previewToken']);
    resolutionCheck($result['state']==='blocked'&&$result['reason']==='resolution_selection_required'&&$router['ppp/secret']===$beforeRows
        &&count($GLOBALS['resolutionNativeWrites'])===$nativeStart&&count($writes)===$repairStart&&count(glob('data/router-resolution-*.json'))===$backupStart,
        'Manual duplicate removal ran without an explicit account choice');
    $preview=$real->preview(42);$ownedCandidate=array_values(array_filter($preview['candidates'],static fn($candidate)=>$candidate['rowId']===$owned['.id']))[0];
    $router['ppp/secret'][1]['profile']='Changed After Review';$changedRows=$router['ppp/secret'];
    $result=$real->apply($preview['previewToken'],[$ownedCandidate['candidateId']]);
    resolutionCheck($result['state']==='blocked'&&$result['reason']==='resolution_changed'&&$router['ppp/secret']===$changedRows
        &&count($GLOBALS['resolutionNativeWrites'])===$nativeStart&&count($writes)===$repairStart&&count(glob('data/router-resolution-*.json'))===$backupStart,
        'A stale review deleted or changed a subsequently edited manual duplicate');
    $router['ppp/secret'][1]=$manual;unset($router['ppp/secret'][1]['password']);
    $preview=$real->preview(42);$ownedCandidate=array_values(array_filter($preview['candidates'],static fn($candidate)=>$candidate['rowId']===$owned['.id']))[0];
    $result=$real->apply($preview['previewToken'],[$ownedCandidate['candidateId']]);
    resolutionCheck($result['state']==='blocked'&&$result['reason']==='resolution_backup_unavailable'&&count($router['ppp/secret'])===2
        &&count($GLOBALS['resolutionNativeWrites'])===$nativeStart&&count($writes)===$repairStart&&count(glob('data/router-resolution-*.json'))===$backupStart,
        'An unreadable manual duplicate secret bypassed the restorable-backup requirement');
    $router['ppp/secret'][1]=$manual;$preview=$real->preview(42);
    $ownedCandidate=array_values(array_filter($preview['candidates'],static fn($candidate)=>$candidate['rowId']===$owned['.id']))[0];
    $result=$real->apply($preview['previewToken'],[$ownedCandidate['candidateId']]);
    resolutionCheck($result['state']==='resolved'&&$result['verificationState']==='verified'&&$queue->completed($result['jobKeys'])
        &&count($router['ppp/secret'])===1&&$router['ppp/secret'][0]['.id']===$owned['.id']
        &&$router['ppp/secret'][0]['comment']==='17 - Fixture Customer - 42'&&$router['ppp/secret'][0]['password']===$owned['password'],
        'Explicit manual-duplicate removal did not retain and verify the correctly owned account: '.json_encode($result));
    resolutionCheck(array_slice($writes,$repairStart)===[['ppp/secret','remove','*A3',[]]],
        'Manual duplicate resolution edited the retained account or removed an unexpected row');
    $backup=json_decode(file_get_contents('data/'.$result['backupName']),true);
    resolutionCheck($backup['selectedRowId']===$owned['.id']&&$backup['removedRowIds']===['*A3']&&count($backup['rows'])===2
        &&$backup['rows'][0]['password']===$owned['password']&&$backup['rows'][1]['password']==='PRIVATE-MANUAL-PASSWORD'
        &&$backup['rows'][1]['comment']===''&&$backup['rows'][1]['profile']==='Manual Fixture Profile',
        'Manual duplicate removal did not preserve both complete prior account configurations privately');
    resolutionCheck(strpos(json_encode($preview),'PRIVATE')===false&&strpos(json_encode($result),'PRIVATE')===false,
        'Manual duplicate review or outcome disclosed private credentials');
    // Clearing a mistaken connection in a still-current service must release
    // its historical identity claim without applying or deleting that service.
    // The absent router account is recreated through the ordinary action,
    // durable queue, MT writer and current provisioning receipt.
    $otherClients[33]=['id'=>33,'isArchived'=>false,'firstName'=>'Former','lastName'=>'Fixture'];
    $peers[41]=$raw;$peers[41]['id']=41;$peers[41]['clientId']=33;
    $former=$snapshot;$former['id']=41;$former['clientId']=33;$former['network']=['address'=>'192.0.2.21','address6'=>null];
    $former['firstName']='Former';$former['lastName']='Fixture';$formerKey=$queue->enqueue($former);
    $queue->runSelected([$formerKey],static fn()=>true);
    resolutionCheck($queue->completed([$formerKey])&&$queue->appliedSnapshot(41)['username']==='fixture-account','Historical former-owner state was not recorded');
    $formerApplied=$queue->appliedSnapshot(41);$formerStatus=$queue->selectionStatus([$formerKey]);
    $formerDelete=$queue->enqueue($former,'delete');$formerDeleteStatus=$queue->selectionStatus([$formerDelete]);
    $router['ppp/secret']=[];$router['ppp/active']=[];
    $blockedStart=count($GLOBALS['resolutionNativeWrites']);$blockedRepairs=count($writes);
    $preview=$real->preview(42);
    resolutionCheck(!$preview['eligible']&&$preview['previewToken']===null&&$preview['reason']==='resolution_owner_active'
        &&count($GLOBALS['resolutionNativeWrites'])===$blockedStart&&count($writes)===$blockedRepairs,
        'Missing account let an actual current duplicate bypass ownership protection: '.json_encode($preview));
    $peers[41]['attributes']=[];
    $preview=$real->preview(42);$nativeStart=count($GLOBALS['resolutionNativeWrites']);$repairStart=count($writes);
    resolutionCheck($preview['eligible']&&$preview['autoApply']&&$preview['mode']==='reconcile'&&$preview['candidates']===[],
        'Cleared duplicate history prevented missing-account reconciliation: '.json_encode($preview));
    $result=$real->apply($preview['previewToken']);
    resolutionCheck($result['state']==='resolved'&&$result['verificationState']==='verified'&&$queue->completed($result['jobKeys'])
        &&count($router['ppp/secret'])===1&&$router['ppp/secret'][0]['name']==='fixture-account'
        &&$router['ppp/secret'][0]['comment']==='17 - Fixture Customer - 42'&&$router['ppp/secret'][0]['remote-address']==='192.0.2.42',
        'Fresh missing-account action did not create and verify its target: '.json_encode([$result,$queue->selectionStatus($result['jobKeys'])]));
    $receipt=(new ApiProvisioningVerification())->read(42);
    resolutionCheck(($receipt['current']??false)===true&&($receipt['state']??null)==='verified'
        &&in_array($receipt['job_id']??null,$result['jobKeys'],true),'Missing-account result reused an earlier verification receipt');
    $created=array_slice($GLOBALS['resolutionNativeWrites'],$nativeStart);
    resolutionCheck((bool)array_filter($created,static fn($write)=>$write[0]==='ppp/secret'&&$write[1]==='add')
        &&count($writes)===$repairStart&&$queue->appliedSnapshot(41)===$formerApplied&&$queue->selectionStatus([$formerKey])===$formerStatus
        &&$queue->selectionStatus([$formerDelete])===$formerDeleteStatus,
        'Missing-account reconciliation altered former-owner history or used a direct repair write instead of normal account creation');
    $newAccount=$router['ppp/secret'];$nativeStart=count($GLOBALS['resolutionNativeWrites']);
    $queue->runSelected([$formerDelete]);
    resolutionCheck(!$queue->completed([$formerDelete])&&$queue->selectionStatus([$formerDelete])[$formerDelete]['state']==='pending'
        &&count($GLOBALS['resolutionNativeWrites'])===$nativeStart&&$router['ppp/secret']===$newAccount,
        'A deferred former-owner cleanup erased or changed the newly assigned current account');
    $delete=$queue->enqueue($snapshot,'delete');$preview=$make()->preview(42);$count=count($writes);$result=$make()->apply($preview['previewToken']);
    resolutionCheck($result['state']==='blocked'&&$result['reason']==='resolution_pending_cleanup'&&count($writes)===$count,'A cleanup barrier was cancelled or bypassed');$queue->delete([$delete]);
    $preview=$make()->preview(42);$now+=601;resolutionReject(fn()=>$make()->apply($preview['previewToken']),'Expired repair accepted');$now-=601;
    resolutionCheck(!ApiServiceResolution::backupName('../config.json'),'Unsafe backup filename accepted');
    echo "Service resolution regression passed\n";
}finally{if($queue)$queue->close();if($db)$db->close();if($cache)$cache->close();chdir($original);set_include_path($include);resolutionRemove($directory);}
