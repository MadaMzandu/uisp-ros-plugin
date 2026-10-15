<?php
// Production Batch/queue with a synthetic device adapter: cleanup evidence survives a partial migration.
set_error_handler(function($severity,$message,$file,$line){if(error_reporting()&$severity)throw new ErrorException($message,0,$severity,$file,$line);return false;});
function retryEvidenceCheck(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
$source=realpath(__DIR__.'/../src'); $original=getcwd(); $include=get_include_path();
$directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'uisp-verification-retry-'.bin2hex(random_bytes(8));mkdir($directory,0700);
foreach(['lib','includes','data','vendor'] as $folder)mkdir($directory.'/'.$folder,0700);
foreach(glob($source.'/lib/*.php') as $file)copy($file,$directory.'/lib/'.basename($file));
foreach(glob($source.'/includes/*') as $file)if(is_file($file))copy($file,$directory.'/includes/'.basename($file));
file_put_contents($directory.'/vendor/autoload.php','<?php namespace Ubnt\\UcrmPluginSdk\\Service; class UcrmApi { public static function create(){return new self();} public function get(){throw new \\RuntimeException("Network prohibited");} }');
file_put_contents($directory.'/lib/mt.php',<<<'PHP'
<?php
class MT {
    public static bool $failReplacement=true;
    public static array $writes=[];
    public static array $observations=[];
    public static bool $oldRouterUnavailable=true;
    private $observer=null;
    private array $failures=[];
    public function withVerificationScope(callable $observer,callable $operation) {
        $this->observer=$observer; try{return $operation();}finally{$this->observer=null;}
    }
    public function do_batch($device,$commands):int {
        $this->failures=[];
        foreach($commands as $command)self::$writes[]=[$device->id,$command['action']??'set'];
        if($device->id===2&&self::$failReplacement){$this->failures=['synthetic replacement failure'];return 0;}
        $checks=[]; foreach($commands as $command)$checks[]=ApiProvisioningVerification::expectation($command,$command,$command['action']??'set');
        if($this->observer!==null)($this->observer)($checks,fn($path,$filter)=>$this->rows($device,$checks,$path,$filter));
        return count($commands);
    }
    private function rows($device,$checks,$path,$filter) {
        self::$observations[]=$device->id;
        if($device->id===1&&self::$oldRouterUnavailable)return null;
        foreach($checks as $check)if(($check['path']??null)===$path&&($check['filter']??null)===$filter)
            return $check['action']==='remove'?[]:[$check['expected']];
        return [];
    }
    public function readVerification($device,$checks,$deadline):array {
        return ApiProvisioningVerification::compare($checks,fn($path,$filter)=>$this->rows($device,$checks,$path,$filter),$deadline);
    }
    public function failed():array{return $this->failures;}
    public function success():array{return [];}
}
PHP);
chdir($directory);set_include_path($directory.'/lib'.PATH_SEPARATOR.$include);
require 'lib/api_common.php';require 'lib/api_setup.php';require 'lib/api_update.php';require 'lib/batch.php';
try{
    $apiSqlite=new ApiSqlite('data/data.db');$apiSqlite->exec(file_get_contents('includes/schema.sql'));
    (new ApiSetup())->config_load();$apiSqlite->saveConfig(['version'=>MY_VERSION]);
    $apiSqliteCache=new ApiSqlite('data/cache.db');$apiSqliteCache->exec(file_get_contents('includes/cache.sql'));
    foreach([1,2] as $id)$apiSqlite->insert(['id'=>$id,'name'=>'Fixture '.$id,'type'=>'mikrotik','ip'=>'192.0.2.'.$id,'port'=>8728,'user'=>'PRIVATE-USER','password'=>'PRIVATE-ROUTER'],'devices');
    $make=function(int $device,string $name,string $action='set') use($apiSqlite):array {
        $service=['id'=>42,'clientId'=>17,'device'=>$device,'planId'=>null,'status'=>1,'username'=>$name,'address'=>'192.0.2.42',
            '_device'=>(array)$apiSqlite->selectDeviceById($device),'_config'=>[],
            '_plan'=>['id'=>0,'name'=>'Fixture plan','uploadSpeed'=>5,'downloadSpeed'=>30]];
        $service['_commands']=[['path'=>'/ppp/secret','action'=>$action,'name'=>$name,'remote-address'=>'192.0.2.42',
            'comment'=>'17 - Fixture - 42','profile'=>'Fixture plan','password'=>'PRIVATE-ACCOUNT','_service_id'=>42,'_client_id'=>17,'batch'=>'service-42']];
        if($action==='remove'){$service['action']='delete';$service['_delete_frozen']=true;}
        return $service;
    };
    $queue=myJobQueue();$old=$make(1,'old-fixture');$oldKey=$queue->enqueue($old);$queue->runSelected([$oldKey],fn()=>true);
    $new=$make(2,'new-fixture');$new['_cleanup']=$make(1,'old-fixture','remove');
    $key=$queue->enqueue($new);$first=$queue->runSelected([$key]);
    retryEvidenceCheck(!$first[$key]['success'],'Replacement failure was not retained');
    $inspect=new SQLite3('data/jobs.db',SQLITE3_OPEN_READONLY);
    $row=$inspect->querySingle('SELECT cleanup_done,payload FROM jobs WHERE id='.(int)substr($key,1),true);$inspect->close();
    $snapshot=json_decode($row['payload'],true);
    retryEvidenceCheck((int)$row['cleanup_done']===1&&!empty($snapshot['_verification_stages']),'Acknowledged cleanup evidence was lost before retry');
    retryEvidenceCheck(strpos(json_encode($snapshot['_verification_stages']),'PRIVATE-')===false,'Cleanup journal retained credentials');
    $writes=count(MT::$writes);MT::$failReplacement=false;$result=$queue->runSelected([$key]);
    retryEvidenceCheck($result[$key]['success']&&$result[$key]['verification']['state']==='unconfirmed','New-router success erased inconclusive old-router cleanup');
    foreach(array_slice(MT::$writes,$writes) as [$device,$action])retryEvidenceCheck($device===2,'Retry replayed a consumed old-router cleanup');
    $completedWrites=count(MT::$writes);
    retryEvidenceCheck(count(array_filter(MT::$observations,fn($id)=>$id===1))>=2,'Retry did not reread consumed cleanup evidence');
    retryEvidenceCheck(!isset($queue->appliedSnapshot(42)['_verification_stages']),'Completed cleanup journal leaked into the current applied snapshot');
    MT::$oldRouterUnavailable=false;$store=new ApiProvisioningVerification(fn($device,$checks,$deadline)=>(new MT())->readVerification($device,$checks,$deadline));
    $checked=$store->recheck(42);
    retryEvidenceCheck($checked['state']==='verified'&&count(MT::$writes)===$completedWrites,'Read-only recheck did not confirm both routers, or replayed a mutation');
    echo "Provisioning verification retry regression checks passed\n";
}finally{
    myJobQueue()->close();foreach(['apiSqlite','apiSqliteCache']as $name)if(isset($$name))$$name->close();
    if(isset($apiIpClass))unset($apiIpClass);chdir($original);set_include_path($include);
    $remove=function($path)use(&$remove){foreach(scandir($path)as$file){if($file==='.'||$file==='..')continue;$entry=$path.'/'.$file;if(is_dir($entry)&&!is_link($entry))$remove($entry);else unlink($entry);}rmdir($path);};$remove($directory);restore_error_handler();
}
