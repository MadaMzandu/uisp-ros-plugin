<?php
// Real queue/Batch/MtData/MT against RAM routers. No external network is permitted.
set_error_handler(function ($severity,$message,$file,$line) {
    if (error_reporting() & $severity) throw new ErrorException($message,0,$severity,$file,$line);
    return false;
});
function recoveryCheck(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
$source=realpath(__DIR__ . '/../src'); $original=getcwd();
$directory=sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-cancelled-recovery-' . bin2hex(random_bytes(8));
mkdir($directory,0700);
foreach (['lib','includes','data','vendor'] as $folder) mkdir($directory . '/' . $folder,0700);
foreach (glob($source . '/lib/*.php') as $file) copy($file,$directory . '/lib/' . basename($file));
foreach (glob($source . '/includes/*') as $file) if (is_file($file)) copy($file,$directory . '/includes/' . basename($file));
file_put_contents($directory . '/vendor/autoload.php', <<<'PHP'
<?php
namespace Ubnt\UcrmPluginSdk\Service;
class UcrmApi {
    public static function create() { return new self(); }
    public function get($path,$options=[]) { throw new \RuntimeException('Fixture network prohibited'); }
    public function patch($path,$data=[]) { throw new \RuntimeException('Fixture network prohibited'); }
}
PHP);
$batchFile=$directory . '/lib/batch.php';
$code=str_replace('new MT()', 'new RecoveryMT()', file_get_contents($batchFile),$changes);
recoveryCheck($changes===1,'Fixture constructor replacement was not precise'); file_put_contents($batchFile,$code);
$queueFile=$directory . '/lib/api_job_queue.php';
$code=str_replace('static $queue = null;',
    'static $queue = null; if (isset($GLOBALS["recoveryQueueOverride"])) return $GLOBALS["recoveryQueueOverride"];',
    file_get_contents($queueFile),$changes);
recoveryCheck($changes===1,'Fixture queue restart replacement was not precise'); file_put_contents($queueFile,$code);
chdir($directory); set_include_path($directory . '/lib' . PATH_SEPARATOR . get_include_path());
require 'lib/api_common.php'; require 'lib/api_setup.php'; require 'lib/batch.php'; require 'lib/api_action.php'; require 'lib/api_trim.php';
class RecoveryRouterAPI extends RouterosAPI {
    private array $pending=[]; private array $response=[]; private $apply;
    public function __construct(callable $apply) { $this->apply=$apply; }
    public function write($word,$param2=true) { $this->pending[]=$word; return true; }
    public function finishSentence(): void { $this->response=($this->apply)($this->pending); $this->pending=[]; }
    public function read($parse=true) { $result=$this->response; $this->response=[]; return $result; }
    public function disconnect() { $this->connected=false; }
}
class RecoveryMT extends MT {
    private RecoveryRouterAPI $recording;
    public function __construct() {
        parent::__construct();
        $this->recording=new RecoveryRouterAPI(function(array $words): array {
            $did=(int)$this->batch_device->id; $GLOBALS['recoveryWrites'][]=[$did,$words];
            $path=trim(array_shift($words),'/'); $separator=strrpos($path,'/');
            $action=substr($path,$separator+1); $path=substr($path,0,$separator);
            $fault=$did . ':' . $path . '/' . $action;
            if (!empty($GLOBALS['recoveryBeforeFailure'][$fault])) return ['!trap'=>[['message'=>'Fixture mutation denied']]];
            $values=[];
            foreach ($words as $word) if (strpos($word,'=')===0) {
                [$key,$value]=explode('=',substr($word,1),2); $values[$key]=$value;
            }
            $catalogue =& $GLOBALS['recoveryRouters'][$did];
            if ($action==='add') {
                if (!empty($GLOBALS['recoveryCorruptProfile']) && $path==='ppp/profile') $values['address-list']='incorrect-list';
                $catalogue[$path][]=['.id'=>'*recovery-' . ++$GLOBALS['recoveryRouterId']] + $values;
            } elseif ($action==='set') {
                foreach ($catalogue[$path]??[] as $index=>$row)
                    if (($row['.id']??null)===($values['.id']??null)) $catalogue[$path][$index]=array_replace($row,$values);
            } elseif ($action==='remove') {
                $ids=explode(',',$values['.id']??'');
                $catalogue[$path]=array_values(array_filter($catalogue[$path]??[],static fn($row)=>!in_array($row['.id']??null,$ids,true)));
            }
            if (!empty($GLOBALS['recoveryAfterFailure'][$fault])) {
                unset($GLOBALS['recoveryAfterFailure'][$fault]);
                return ['!trap'=>[['message'=>'Fixture lost acknowledgement after mutation']]];
            }
            return [];
        });
    }
    protected function api_connect(): ?RouterosAPI { $this->recording->connected=true; return $this->recording; }
    protected function read($path,$filter=null) {
        $did=(int)$this->batch_device->id; $path=trim($path,'/');
        if (!empty($GLOBALS['recoveryReadFailure'][$did . ':' . $path])) return null;
        if (is_string($filter)) {
            $items=[]; foreach (explode(',',$filter) as $item) { [$key,$value]=explode('=',ltrim($item,'?'),2); $items[$key]=$value; }
            $filter=$items;
        }
        return array_values(array_filter($GLOBALS['recoveryRouters'][$did][$path]??[],static function($row) use($filter): bool {
            foreach ((array)$filter as $key=>$value) if ((string)($row[$key]??'')!==(string)$value) return false;
            return true;
        }));
    }
}
function recoverySnapshot(int $sid,int $did=10,int $plan=1,array $extra=[]): array {
    return array_replace(['id'=>$sid,'clientId'=>70,'device'=>$did,'planId'=>$plan,'status'=>1,
        'username'=>'recovery-' . $sid,'password'=>'PRIVATE-ACCOUNT-PASSWORD','firstName'=>'Fixture','lastName'=>'Client','company'=>'',
        'address'=>'10.' . $did . '.0.' . ($sid%200+1),'batch'=>'service-' . $sid,
        '_device'=>(array)mySqlite()->selectDeviceById($did),'_config'=>(array)mySqlite()->readConfig(),
        '_plan'=>mySqlite()->singleQuery('SELECT * FROM plans WHERE id=?',true,[$plan])],$extra);
}
function recoveryRun(array $snapshot,string $operation='update'): string {
    $queue=myJobQueue(); $key=$queue->enqueue($snapshot,$operation); $queue->runSelected([$key]); return $key;
}
function recoveryRows(int $did,string $path,string $field,string $value): array {
    return array_values(array_filter($GLOBALS['recoveryRouters'][$did][$path]??[],static fn($row)=>(string)($row[$field]??'')===$value));
}
function recoveryRestart(): void {
    myJobQueue()->close(); $GLOBALS['recoveryQueueOverride']=new ApiJobQueue();
}
try {
    $apiSqlite=new ApiSqlite('data/data.db'); $apiSqlite->exec(file_get_contents('includes/schema.sql'));
    (new ApiSetup())->config_load();
    $apiSqlite->saveConfig(['version'=>MY_VERSION,'disable_contention'=>true,'auto_ppp_user'=>false,
        'device_name_attr'=>'site','pppoe_user_attr'=>'user','pppoe_pass_attr'=>'pass',
        'disabled_profile'=>'paused-recovery','disabled_list'=>'paused-list','disabled_rate'=>0]);
    file_put_contents('data/config.json',json_encode(['debugEnable'=>false]));
    $apiSqliteCache=new ApiSqlite('data/cache.db'); $apiSqliteCache->exec(file_get_contents('includes/cache.sql'));
    $apiSqliteCache->insert(['id'=>70,'firstName'=>'Fixture','lastName'=>'Client'],'clients');
    $GLOBALS['recoveryWrites']=[]; $GLOBALS['recoveryRouterId']=0;
    foreach ([10,20,30] as $did) {
        $apiSqlite->insert(['id'=>$did,'name'=>'Recovery router ' . $did,'type'=>'mikrotik','ip'=>'192.0.2.' . $did,
            'user'=>'fixture','password'=>'PRIVATE-ROUTER-PASSWORD','pool'=>'10.' . $did . '.0.0/24','pool6'=>'fd' . $did . '::/48','pfxLength'=>64],'devices');
        $GLOBALS['recoveryRouters'][$did]=['ip/address'=>[['.id'=>'*lan','address'=>'192.0.2.1/24','dynamic'=>'false','disabled'=>'false','invalid'=>'false']]];
    }
    foreach ([1,2,3,4] as $pid) $apiSqlite->insert(['id'=>$pid,'name'=>'Recovery plan ' . $pid,'ratio'=>1,'uploadSpeed'=>5*$pid,'downloadSpeed'=>25*$pid],'plans');
    $queue=myJobQueue();

    // Partial A was never applied; a newer prepared B already exists when A is cancelled.
    $a=recoverySnapshot(201); $GLOBALS['recoveryAfterFailure']['10:ppp/secret/add']=true;
    $aKey=recoveryRun($a); recoveryCheck(!$queue->completed([$aKey]) && recoveryRows(10,'ppp/secret','name',$a['username'])!==[], 'Fixture did not partially create A');
    $b=recoverySnapshot(201,20,2); $b['_commands']=array_values((new MtData())->get_data($b,$b['_plan']));
    $bKey=$queue->enqueue($b); $count=count($GLOBALS['recoveryWrites']);
    recoveryCheck(($queue->delete([$aKey])[$aKey]??false) && count($GLOBALS['recoveryWrites'])===$count
        && $queue->hasUnreconciled(201) && $queue->pending([$bKey]), 'Cancellation contacted a router, lost debt or cancelled a later update');
    recoveryCheck(in_array($a['address'],array_column($queue->reservedAddresses(),'address'),true),'Cancelled partial IP was released before cleanup');
    $GLOBALS['recoveryBeforeFailure']['10:ppp/secret/remove']=true;
    $start=count($GLOBALS['recoveryWrites']); $queue->runSelected([$bKey]);
    recoveryCheck(!$queue->completed([$bKey]) && $queue->hasUnreconciled(201)
        && recoveryRows(20,'ppp/secret','name',$b['username'])===[],'Failed cancellation cleanup applied B or retired unconfirmed debt');
    recoveryRestart(); $queue=myJobQueue(); unset($GLOBALS['recoveryBeforeFailure']['10:ppp/secret/remove']);
    $start=count($GLOBALS['recoveryWrites']); $queue->runSelected([$bKey]);
    recoveryCheck($queue->completed([$bKey]) && !$queue->hasUnreconciled(201)
        && recoveryRows(10,'ppp/secret','name',$a['username'])===[] && count(recoveryRows(20,'ppp/secret','name',$b['username']))===1,
        'Restarted reconciliation left A orphaned or failed to apply prepared B');
    $sequence=array_slice($GLOBALS['recoveryWrites'],$start); $oldDelete=$newWrite=null;
    foreach ($sequence as $index=>[$did,$words]) {
        if ($did===10 && $words[0]==='/ppp/secret/remove') $oldDelete=$index;
        if ($did===20 && $words[0]==='/ppp/secret/add') $newWrite=$index;
    }
    recoveryCheck($oldDelete!==null && $newWrite!==null && $oldDelete<$newWrite
        && !in_array($a['address'],array_column($queue->reservedAddresses(),'address'),true), 'A cleanup was reordered after B or kept its completed reservation');

    // Same identity does not justify discarding different auxiliary profiles or the applied baseline.
    $base=recoverySnapshot(202); $baseKey=recoveryRun($base); recoveryCheck($queue->completed([$baseKey]),'Baseline failed');
    $partial=recoverySnapshot(202,10,2,['address'=>'10.10.1.2']); $GLOBALS['recoveryAfterFailure']['10:ppp/secret/set']=true;
    $partialKey=recoveryRun($partial); recoveryCheck(!$queue->completed([$partialKey]),'Partial update unexpectedly completed');
    $count=count($GLOBALS['recoveryWrites']); recoveryCheck($queue->delete([$partialKey])[$partialKey] && count($GLOBALS['recoveryWrites'])===$count,'Cancelling prepared same-identity update wrote to router');
    $current=recoverySnapshot(202,10,3,['address'=>'10.10.1.3']); $currentKey=recoveryRun($current);
    recoveryCheck($queue->completed([$currentKey]) && !$queue->hasUnreconciled(202)
        && recoveryRows(10,'ppp/profile','name','Recovery plan 2 [UISP#2]')===[]
        && (recoveryRows(10,'ppp/secret','name',$current['username'])[0]['profile']??null)==='Recovery plan 3 [UISP#3]',
        'Late binding lost the cancelled profile or replayed old settings over current intent');

    // Cancel a native prepared delete without reversing its dependency-safe removal order.
    $base=recoverySnapshot(203); $baseKey=recoveryRun($base);
    $applied=$queue->appliedSnapshot(203); $remove=[]; $active=[];
    foreach ($applied['_commands'] as $command) { $command['action']='remove';
        if (trim($command['path'],'/')==='ppp/active') $active[]=$command; else $remove[]=$command; }
    $deletion=$applied; $deletion['action']='delete'; $deletion['_delete_frozen']=true;
    $deletion['_commands']=array_merge(array_reverse($remove),$active);
    $GLOBALS['recoveryBeforeFailure']['10:ppp/secret/remove']=true;
    $deleteKey=recoveryRun($deletion,'delete'); unset($GLOBALS['recoveryBeforeFailure']['10:ppp/secret/remove']);
    recoveryCheck(!$queue->completed([$deleteKey]) && $queue->delete([$deleteKey])[$deleteKey],'Prepared deletion could not be cancelled');
    $current=recoverySnapshot(203,20,3); $currentKey=recoveryRun($current);
    recoveryCheck($queue->completed([$currentKey]) && recoveryRows(10,'ppp/secret','name',$base['username'])===[], 'Cancelled deletion was replayed or left its principal');

    // A nested cleanup can already be in deletion order although its outer action is update.
    $base=recoverySnapshot(207,10,4); $key=recoveryRun($base); $applied=$queue->appliedSnapshot(207);
    $remove=[]; $active=[];
    foreach ($applied['_commands'] as $command) { $command['action']='remove';
        if (trim($command['path'],'/')==='ppp/active') $active[]=$command; else $remove[]=$command; }
    $nested=$applied; $nested['_commands']=array_merge(array_reverse($remove),$active);
    $stage=recoverySnapshot(207,20,3); $stage['_cleanup']=$nested; $stageKey=$queue->enqueue($stage);
    $queue->runSelected([$stageKey],function($snapshot,$operation,$job,$queue): bool {
        $queue->freezePrepared($job,$snapshot); throw new RuntimeException('Fixture interrupted cleanup stage');
    });
    recoveryCheck($queue->delete([$stageKey])[$stageKey],'Nested cleanup stage could not be cancelled');
    $start=count($GLOBALS['recoveryWrites']); $currentKey=recoveryRun(recoverySnapshot(207,20,3));
    $principal=$profile=null;
    foreach (array_slice($GLOBALS['recoveryWrites'],$start) as $index=>[$did,$words]) {
        if ($did===10 && $words[0]==='/ppp/secret/remove' && $principal===null) $principal=$index;
        if ($did===10 && $words[0]==='/ppp/profile/remove' && $profile===null) $profile=$index;
    }
    recoveryCheck($queue->completed([$currentKey]) && $principal!==null && $profile!==null && $principal<$profile
        && recoveryRows(10,'ppp/profile','name','Recovery plan 4 [UISP#4]')===[], 'Nested update-labelled cleanup was reversed and left a shared profile orphan');

    // Frozen DHCPv6 auxiliary resources survive cancellation until exact retirement.
    $dhcp=recoverySnapshot(210,10,4,['username'=>null,'password'=>null,'mac'=>'02:00:00:00:02:10',
        'duid'=>'0x00030001020000000210','iaid'=>21,'address6'=>'fd10:0:1::/64']);
    $GLOBALS['recoveryAfterFailure']['10:ipv6/dhcp-server/binding/add']=true;
    $dhcpKey=recoveryRun($dhcp); recoveryCheck(!$queue->completed([$dhcpKey]),'DHCPv6 partial fixture failed');
    $count=count($GLOBALS['recoveryWrites']); recoveryCheck($queue->delete([$dhcpKey])[$dhcpKey] && count($GLOBALS['recoveryWrites'])===$count,'DHCP cancellation wrote to router');
    recoveryCheck(in_array($dhcp['address6'],array_column($queue->reservedAddresses(true),'address6'),true),'Cancelled IPv6 prefix was released');
    $next=array_replace($dhcp,['address'=>'10.10.2.11','address6'=>'fd11:0:1::/64']); $next['_device']['pool6']='fd11::/48';
    $nextKey=recoveryRun($next);
    recoveryCheck($queue->completed([$nextKey]) && !$queue->hasUnreconciled(210)
        && recoveryRows(10,'ipv6/pool','prefix','fd10::/48')===[]
        && recoveryRows(10,'ipv6/dhcp-server/binding','address',$dhcp['address6'])===[]
        && count(recoveryRows(10,'ipv6/dhcp-server/binding','address',$next['address6']))===1,
        'Same-identity DHCP recovery orphaned the old binding/pool or lost the new prefix: ' . json_encode([
            'completed'=>$queue->completed([$nextKey]),'error'=>$queue->list()[$nextKey]['error']??null,
            'pools'=>array_column($GLOBALS['recoveryRouters'][10]['ipv6/pool']??[],'prefix'),
            'bindings'=>array_column($GLOBALS['recoveryRouters'][10]['ipv6/dhcp-server/binding']??[],'address')]));

    // A queue-only request cannot delete a principal while consuming cancellation debt.
    $partial=recoverySnapshot(206); $GLOBALS['recoveryAfterFailure']['10:ppp/secret/add']=true;
    $key=recoveryRun($partial); recoveryCheck($queue->delete([$key])[$key],'Queue-only debt fixture failed');
    $key=recoveryRun(recoverySnapshot(206,20),'queues_set');
    recoveryCheck($queue->completed([$key]) && $queue->hasUnreconciled(206)
        && count(recoveryRows(10,'ppp/secret','name',$partial['username']))===1,
        'Queue-only operation consumed full-account debt or removed its principal');

    // Legacy applied shape containing only a verifiable identity needs no guessed auxiliaries.
    $legacy=recoverySnapshot(208); unset($legacy['_plan'],$legacy['planId']);
    $legacyKey=$queue->enqueue($legacy); $queue->runSelected([$legacyKey],static fn()=>true);
    $GLOBALS['recoveryRouters'][10]['ppp/secret'][]=['.id'=>'*legacy-identity','name'=>$legacy['username'],
        'profile'=>'administrator-profile','comment'=>'70 - Legacy Client - 208'];
    $partial=recoverySnapshot(208,10,2); $GLOBALS['recoveryAfterFailure']['10:ppp/secret/set']=true;
    $key=recoveryRun($partial); recoveryCheck($queue->delete([$key])[$key],'Legacy applied debt fixture failed');
    $key=recoveryRun(recoverySnapshot(208,20,3));
    recoveryCheck($queue->completed([$key]) && !$queue->hasUnreconciled(208)
        && recoveryRows(10,'ppp/secret','name',$legacy['username'])===[], 'Legacy exact-identity cleanup did not retire its principal safely');
    $legacy=recoverySnapshot(211); unset($legacy['_plan']);
    $key=$queue->enqueue($legacy); $queue->runSelected([$key],static fn()=>true);
    $GLOBALS['recoveryRouters'][10]['ppp/secret'][]=['.id'=>'*legacy-unknown','name'=>$legacy['username'],
        'profile'=>'unknown-profile','comment'=>'70 - Legacy Client - 211'];
    $partial=recoverySnapshot(211,10,2); $GLOBALS['recoveryAfterFailure']['10:ppp/secret/set']=true;
    $key=recoveryRun($partial); recoveryCheck($queue->delete([$key])[$key],'Unknown legacy fixture cancellation failed');
    $start=count($GLOBALS['recoveryWrites']); $nextKey=recoveryRun(recoverySnapshot(211,20,3));
    recoveryCheck(!$queue->completed([$nextKey]) && $queue->hasUnreconciled(211)
        && count($GLOBALS['recoveryWrites'])===$start
        && ($queue->list()[$nextKey]['error']??null)==='cancelled_resource_snapshot_incomplete',
        'Legacy cleanup guessed unknown auxiliary resources or confirmed incomplete retirement');

    // Bootstrap created incorrectly cannot be adopted silently during cancellation recovery.
    $paused=recoverySnapshot(204,30,1,['status'=>3]); $GLOBALS['recoveryCorruptProfile']=true;
    $pausedKey=recoveryRun($paused); $GLOBALS['recoveryCorruptProfile']=false;
    recoveryCheck(!$queue->completed([$pausedKey]) && $queue->delete([$pausedKey])[$pausedKey],'Partial bootstrap fixture failed');
    $next=recoverySnapshot(204,20,2); $nextKey=$queue->enqueue($next); $start=count($GLOBALS['recoveryWrites']);
    $queue->runSelected([$nextKey]);
    recoveryCheck(!$queue->completed([$nextKey]) && $queue->hasUnreconciled(204) && count($GLOBALS['recoveryWrites'])===$start
        && recoveryRows(20,'ppp/secret','name',$next['username'])===[],'Cancellation adopted a partially created suspension profile');
    $GLOBALS['recoveryRouters'][30]['ppp/profile']=array_values(array_filter($GLOBALS['recoveryRouters'][30]['ppp/profile']??[],static fn($row)=>($row['name']??null)!=='paused-recovery'));
    recoveryRestart(); $queue=myJobQueue(); $queue->runSelected([$nextKey]);
    recoveryCheck($queue->completed([$nextKey]) && !$queue->hasUnreconciled(204)
        && recoveryRows(30,'ppp/profile','name','paused-recovery')===[], 'An absent partial suspension profile was recreated during cleanup');

    // Cancelled UUID delivery acknowledges cancellation, creates no work and does not consume debt.
    $event='cancelled-recovery-uuid'; $snapshot=recoverySnapshot(205); $snapshot['_event_id']=$event;
    $GLOBALS['recoveryAfterFailure']['10:ppp/secret/add']=true; $key=recoveryRun($snapshot);
    recoveryCheck($queue->delete([$key])[$key],'UUID fixture cancellation failed');
    $request=(object)['entity'=>'service','entityId'=>'205','changeType'=>'edit','uuid'=>$event,
        'extraData'=>(object)['entity'=>(object)['id'=>205,'clientId'=>70,'status'=>1,'servicePlanId'=>1,
            'attributes'=>[(object)['key'=>'site','value'=>'Recovery router 10'],(object)['key'=>'user','value'=>$snapshot['username']],(object)['key'=>'pass','value'=>'PRIVATE-ACCOUNT-PASSWORD']]]]];
    $start=count($GLOBALS['recoveryWrites']); $keys=$queue->eventKeys($event); $action=new ApiAction($request); $action->exec();
    recoveryCheck(!($action->status()->error??false) && $action->result()['provisioning']==='cancelled'
        && $action->result()['jobCounts']===['known'=>1,'completed'=>0,'pending'=>0]
        && $queue->eventKeys($event)===$keys && $queue->hasUnreconciled(205) && count($GLOBALS['recoveryWrites'])===$start,
        'A cancelled UUID was replayed, misreported pending/completed or consumed its debt');
    $nextKey=recoveryRun(recoverySnapshot(205,20,2)); recoveryCheck($queue->completed([$nextKey]),'Fresh intention did not reconcile cancelled UUID resources');
    $start=count($GLOBALS['recoveryWrites']); $action=new ApiAction($request); $action->exec();
    recoveryCheck(!($action->status()->error??false) && $action->result()['provisioning']==='cancelled'
        && count($GLOBALS['recoveryWrites'])===$start && !$queue->hasUnreconciled(205)
        && $queue->completed([$nextKey]), 'Receipt after debt cleanup replayed the cancelled intent over its replacement');
    echo "Cancelled recovery passed: local cancellation, durable debts/reservations, prepared successors, exact auxiliary cleanup, deletion order, restart/failure recovery, bootstrap guards and cancelled UUID acknowledgements.\n";
} finally {
    if (function_exists('myJobQueue')) myJobQueue()->close();
    if (isset($apiSqlite)) $apiSqlite->close(); if (isset($apiSqliteCache)) $apiSqliteCache->close();
    chdir($original);
    // Only the exact freshly created fixture root is removed; no links are traversed.
    if (is_dir($directory) && realpath($directory)===$directory) {
        $entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) { if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname()); else unlink($entry->getPathname()); }
        rmdir($directory);
    }
}
