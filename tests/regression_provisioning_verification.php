<?php
// Synthetic RouterOS transport and private temporary SQLite databases; no production calls.
set_error_handler(function($severity,$message,$file,$line) { if(error_reporting()&$severity) throw new ErrorException($message,0,$severity,$file,$line); return false; });
$source=realpath(__DIR__.'/../src'); $original=getcwd(); $include=get_include_path();
$directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'uisp-verification-'.bin2hex(random_bytes(8)); mkdir($directory,0700); mkdir($directory.'/data',0700);
set_include_path($source.'/lib'.PATH_SEPARATOR.$include);
require_once $source.'/lib/api_provisioning_verification.php'; require_once $source.'/lib/api_job_queue.php'; require_once $source.'/lib/mt.php';
function verifyCheck(bool $condition,string $message): void { if(!$condition) throw new RuntimeException($message); }
function MyLog() { static $log; return $log??=new class { public function Append($value,$level=0):void {} }; }
final class VerificationAPI extends RouterosAPI {
    public array $rows=[]; public array $mutations=[]; public bool $failReads=false; public bool $ignoreWrites=false;
    private array $words=[];
    public function write($word,$param2=true) { $this->words[]=$word; return true; }
    public function finishSentence():void {}
    public function disconnect() { $this->connected=false; }
    public function read($parse=true) {
        $words=$this->words; $this->words=[]; $path=array_shift($words); $parts=explode('/',trim($path,'/')); $action=array_pop($parts); $resource=implode('/',$parts);
        $fields=[]; $filters=[];
        foreach($words as $word) {
            if(strpos($word,'=')===0) { [$key,$value]=explode('=',substr($word,1),2); $fields[$key]=$value; }
            elseif(strpos($word,'?')===0) { [$key,$value]=explode('=',substr($word,1),2); $filters[$key]=$value; }
        }
        if($action==='print') {
            if($this->failReads) throw new RuntimeException('PRIVATE-TRANSPORT-SECRET');
            return array_values(array_filter($this->rows[$resource]??[],static function($row) use($filters):bool {
                foreach($filters as $field=>$expected) if(($row[$field]??null)!==$expected) return false; return true;
            }));
        }
        $this->mutations[]=$path;
        if($this->ignoreWrites) return [];
        if($action==='remove') {
            $ids=explode(',',$fields['.id']); $this->rows[$resource]=array_values(array_filter($this->rows[$resource]??[],static fn($row)=>!in_array($row['.id'],$ids,true))); return [];
        }
        if($action==='add') { $fields['.id']='*F'.dechex(count($this->rows[$resource]??[])+1); $this->rows[$resource][]=$fields; return []; }
        verifyCheck($action==='set','Unexpected mutation '.$path);
        foreach($this->rows[$resource] as &$row) if($row['.id']===$fields['.id']) $row=array_replace($row,$fields); unset($row); return [];
    }
}
final class VerificationMT extends MT {
    public VerificationAPI $transport;
    public function __construct(VerificationAPI $transport) { $this->transport=$transport; parent::__construct(); }
    protected function load_config():void { $this->conf=(object)['fixture'=>true]; }
    protected function api_connect():?RouterosAPI { $this->transport->connected=true; return $this->transport; }
}
$queue=null; $database=null;
try {
    chdir($directory);
    $database=new SQLite3('data/data.db'); $database->exec(file_get_contents($source.'/includes/schema.sql'));
    $database->exec("INSERT INTO devices(id,name,ip,port,type,user,password) VALUES(1,'Fixture','192.0.2.1',8728,'mikrotik','PRIVATE-LOGIN','PRIVATE-PASSWORD')");
    $device=(object)$database->querySingle('SELECT * FROM devices WHERE id=1',true);
    $service=['id'=>42,'clientId'=>17,'device'=>1,'status'=>1,'username'=>'fixture-account','address'=>'192.0.2.42'];
    $account=['path'=>'/ppp/secret','name'=>'fixture-account','remote-address'=>'192.0.2.42','profile'=>'uisp-plan-1',
        'comment'=>'17 - Fixture - 42','disabled'=>'false','password'=>'PRIVATE-ACCOUNT-PASSWORD','_service_id'=>42,'_client_id'=>17,'batch'=>'service-42'];
    $profile=['path'=>'/ppp/profile','name'=>'uisp-plan-1','comment'=>'ignored old label','rate-limit'=>'5M/30M','only-one'=>'yes',
        '_owner'=>'uisp-ros-plugin:plan:1','batch'=>'service-42'];
    $transport=new VerificationAPI(); $mt=new VerificationMT($transport);
    $transport->rows=['ppp/secret'=>[['.id'=>'*A1','name'=>'fixture-account','comment'=>'17 - Fixture - 42','remote-address'=>'192.0.2.9','profile'=>'old','password'=>'PRIVATE-OLD-PASSWORD']],
        'ppp/profile'=>[['.id'=>'*B1','name'=>'uisp-plan-1','comment'=>'Plan #1','rate-limit'=>'1M/1M']]];
    $stage=null;
    $written=$mt->withVerificationScope(function($checks,$read) use(&$stage,$device) { $stage=ApiProvisioningVerification::stage($device,$checks,$read); },fn()=>$mt->do_batch($device,[$profile,$account]));
    verifyCheck($written===2 && array_unique(array_column($stage['results'],'state'))===['verified'],'Real MT batch read-back did not verify actual account/profile configuration');
    verifyCheck($stage['checks'][0]['expected']['comment']==='Plan #1','Evidence compared stale prepared profile comment instead of actual wire value');
    verifyCheck(strpos(json_encode($stage),'PRIVATE-')===false,'Credentials entered read-back evidence');
    $safeCheck=ApiProvisioningVerification::expectation($account,$account+['.id'=>'*A1'],'set');
    $normal=ApiProvisioningVerification::compare([ApiProvisioningVerification::expectation($profile,['name'=>'uisp-plan-1','rate-limit'=>'5M/30M','only-one'=>'yes'],'set')],
        fn()=>[['name'=>'uisp-plan-1','rate-limit'=>'5000000/30000000','only-one'=>'true']]);
    verifyCheck($normal[0]['state']==='verified','Boolean/rate canonical values differed');
    $duration=ApiProvisioningVerification::compare([ApiProvisioningVerification::expectation(['path'=>'/queue/simple','name'=>'queue'],['name'=>'queue','burst-time'=>'10/0'],'set')],
        fn()=>[['name'=>'queue','burst-time'=>'10s/0s']]);
    verifyCheck($duration[0]['state']==='verified','RouterOS duration pairs differ from numeric requested seconds');
    foreach(['remote-address'=>'192.0.2.77','profile'=>'wrong','comment'=>'18 - Other - 43','disabled'=>'true','.id'=>'*FF'] as $field=>$value) {
        $actual=array_replace($safeCheck['expected'],[$field=>$value]);
        $result=ApiProvisioningVerification::compare([$safeCheck],fn()=>[$actual]);
        verifyCheck($result[0]['state']==='mismatch','Actual mismatch not detected: '.$field);
    }
    $reads=0; $expired=ApiProvisioningVerification::compare([$safeCheck],function() use(&$reads){ $reads++;return [];},microtime(true)-1);
    verifyCheck($reads===0 && $expired[0]['reason']==='read_timeout','Expired budget still initiated a read');
    $session=['path'=>'/ppp/active','name'=>'fixture-account','action'=>'remove'];
    $removed=ApiProvisioningVerification::expectation($session,['.id'=>'*D1'],'remove');
    verifyCheck(ApiProvisioningVerification::compare([$removed],fn()=>[['.id'=>'*D2','name'=>'fixture-account']])[0]['state']==='verified','Newly reconnected session mistaken for the disconnected session');
    verifyCheck(ApiProvisioningVerification::compare([$removed],fn()=>[['.id'=>'*D1','name'=>'fixture-account']])[0]['state']==='mismatch','Old session still present was missed');
    $retained=ApiProvisioningVerification::expectation(['path'=>'/queue/simple','name'=>'queue'],[],'remove','required_resource_retained');
    verifyCheck(ApiProvisioningVerification::compare([$retained],fn()=>[])[0]['state']==='unconfirmed','Required individual queue removal was reported harmless');

    $queue=new ApiJobQueue('data/jobs.db',null); $key=$queue->enqueue($service);
    $observerCalls=0;
    $result=$queue->runSelected([$key],function($snapshot,$operation,$job,$queue) use($device,$account,$mt,$transport,&$observerCalls):bool {
        $stages=[];
        $writes=$mt->withVerificationScope(function($checks,$read) use(&$stages,$device,$transport,&$observerCalls):void {
            $observerCalls++; $transport->failReads=true; $stages[]=ApiProvisioningVerification::stage($device,$checks,$read); $transport->failReads=false;
        },fn()=>$mt->do_batch($device,[$account]));
        verifyCheck($writes===1,'Read-back failure changed acknowledged write count');
        $queue->recordVerification($snapshot,$job,$device,$stages); return true;
    });
    verifyCheck($result[$key]['success'] && $result[$key]['state']==='succeeded' && $result[$key]['verification']['state']==='unconfirmed','Verification failure retried or failed a completed job');
    $mutationCount=count($transport->mutations);
    $queue->runSelected([$key],function(){ throw new RuntimeException('Completed operation was replayed'); });
    verifyCheck(count($transport->mutations)===$mutationCount && $observerCalls===1,'Read-back failure replayed writes');
    $store=new ApiProvisioningVerification(function($currentDevice,$checks,$deadline) use($mt,$transport):array {
        verifyCheck($currentDevice->password==='PRIVATE-PASSWORD','Recheck did not load current router credentials');
        return $mt->readVerification($currentDevice,$checks,$deadline);
    });
    $checked=$store->recheck(42);
    verifyCheck($checked['state']==='verified' && count($transport->mutations)===$mutationCount,'Recheck mutated the router or failed to confirm');
    $transport->rows['ppp/secret'][0]['remote-address']='192.0.2.88';
    verifyCheck($store->recheck(42)['state']==='mismatch' && count($transport->mutations)===$mutationCount,'Read-only recheck missed drift or wrote data');
    $database->exec("UPDATE devices SET ip='192.0.2.99' WHERE id=1");
    verifyCheck($store->recheck(42)['reason']==='router_changed','Changed physical router was queried with old evidence');
    $database->exec("UPDATE devices SET ip='192.0.2.1' WHERE id=1");
    $next=$queue->enqueue(array_replace($service,['address'=>'192.0.2.44']));
    verifyCheck($store->recheck(42)['reason']==='superseded','Superseded receipt was rechecked against old intent');
    verifyCheck(!isset($queue->selectionStatus([$key])[$key]['verification']),'Old job reused current verification evidence');
    verifyCheck($store->read(999)['reason']==='no_verification','Missing receipt reported a successful verification');
    file_put_contents('data/provisioning-verification-99.json','{"schema":');
    verifyCheck($store->read(99)['state']==='unconfirmed' && $store->read(99)['reason']==='receipt_unavailable','Corrupt receipt was confused with no verification');
    $listing=$store->listing(20);
    verifyCheck($listing['available'] && count($listing['items'])===2,'Recent evidence listing dropped corrupt known service');
    $stored=file_get_contents('data/provisioning-verification-42.json');
    verifyCheck(strpos($stored,'PRIVATE-')===false && strpos(json_encode($listing),'PRIVATE-')===false,'Secrets leaked into receipt/public listing');
    $tampered=json_decode($stored,true); $tampered['stages'][0]['results'][0]['password']='EXTRA-PRIVATE';
    file_put_contents('data/provisioning-verification-42.json',json_encode($tampered));
    verifyCheck(strpos(json_encode($store->read(42)),'EXTRA-PRIVATE')===false,'Unknown stored result key escaped public projection');
    foreach(['device_id','checked_at','client_id','operation'] as $field) {
        $tampered=json_decode($stored,true); $tampered[$field]=['password'=>'TOP-PRIVATE'];
        file_put_contents('data/provisioning-verification-42.json',json_encode($tampered));
        $rejected=$store->read(42);
        verifyCheck($rejected['reason']==='receipt_unavailable'&&strpos(json_encode($rejected),'TOP-PRIVATE')===false,'Nested receipt metadata escaped validation: '.$field);
    }
    echo "Provisioning verification regression checks passed\n";
} finally {
    if($queue) $queue->close(); if($database) $database->close(); chdir($original); set_include_path($include);
    $remove=function(string $path) use(&$remove):void { foreach(scandir($path) as $file) { if($file==='.'||$file==='..')continue; $entry=$path.DIRECTORY_SEPARATOR.$file; if(is_dir($entry)&&!is_link($entry))$remove($entry);else unlink($entry); } rmdir($path); };
    $remove($directory); restore_error_handler();
}
