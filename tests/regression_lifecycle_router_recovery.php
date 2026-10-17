<?php
// Read-only recovery of purged legacy identities; every router row and write below is synthetic RAM.
require_once __DIR__.'/../src/lib/api_lifecycle_router_recovery.php';
set_error_handler(static function($severity,$message,$file,$line) {
    if (error_reporting()&$severity) throw new ErrorException($message,0,$severity,$file,$line);
    return false;
});
function recoveryCheck(bool $value,string $message): void { if (!$value) throw new RuntimeException($message); }
function recoveryReject(callable $call,array $codes): void {
    try { $call(); } catch (RuntimeException $error) { recoveryCheck(in_array($error->getMessage(),$codes,true),'Wrong recovery rejection: '.$error->getMessage()); return; }
    throw new RuntimeException('An unsafe recovery was accepted');
}
$legacy=['id'=>710,'clientId'=>810,'device'=>10,'planId'=>20,'status'=>1];
$device=['id'=>10,'type'=>'mikrotik','ip'=>'192.0.2.10','user'=>'fixture-api','password'=>'PRIVATE-ROUTER'];
$base=[
    'ppp/secret'=>[['.id'=>'*A1','name'=>'actual-legacy-user','comment'=>'810 - Fixture old owner - 710','remote-address'=>'192.0.2.71'],
        ['.id'=>'*A2','name'=>'current-user','comment'=>'811 - Fixture current owner - 711','remote-address'=>'192.0.2.72']],
    'ip/hotspot/user'=>[], 'ip/dhcp-server/lease'=>[],
    'ipv6/dhcp-server/binding'=>[['.id'=>'*B1','duid'=>'0x00030001001122334455','iaid'=>'1','comment'=>'810 - Fixture old owner - 710','address'=>'2001:db8:71::/64']],
    'queue/simple'=>[['.id'=>'*C1','name'=>'uisp-service-710-suspension','comment'=>'uisp-ros-plugin:suspension:710'],
        ['.id'=>'*C2','name'=>'uisp-service-710-parent','comment'=>'uisp-ros-plugin:service-parent:710'],
        ['.id'=>'*C3','name'=>'uisp-plan-20-parent','comment'=>'uisp-ros-plugin:plan-parent:20']],
    'ppp/profile'=>[['.id'=>'*D1','name'=>'Fixture plan [Override S#710]','comment'=>'Servicio #710'],
        ['.id'=>'*D2','name'=>'Fixture plan [UISP#20]','comment'=>'Plan #20']],
    'ip/hotspot/user/profile'=>[],
    'ppp/active'=>[['.id'=>'*E1','name'=>'actual-legacy-user'],['.id'=>'*E2','name'=>'current-user']],
    'ip/hotspot/active'=>[],
];
$reader=static function(array &$catalog,array &$reads): callable {
    return static function(array $target,string $path,array $filter,array $fields) use (&$catalog,&$reads): array {
        recoveryCheck($target['id']===10 && $target['ip']==='192.0.2.10','Recovery changed the recorded router');
        recoveryCheck(!array_intersect($fields,['password','token','secret']),'Recovery requested private router properties');
        $reads[]=[$path,$filter,$fields];
        return array_values(array_filter($catalog[$path]??[],static function(array $row) use ($filter): bool {
            foreach ($filter as $key=>$value) if (($row[$key]??null)!==$value) return false;
            return true;
        }));
    };
};
$catalog=$base; $reads=[];
$snapshots=(new ApiLifecycleRouterRecovery($reader($catalog,$reads)))->recover($legacy+['username'=>'untrusted-cache-user'],$device);
recoveryCheck(count($snapshots)===1 && $snapshots[0]['username']==='actual-legacy-user' && $snapshots[0]['_delete_frozen']===true,
    'The recovered identity was not based solely on exact current router ownership');
$commands=$snapshots[0]['_commands'];
recoveryCheck(count($commands)===6 && !str_contains(json_encode($commands),'current-user') && !str_contains(json_encode($commands),'uisp-plan-20-parent'),
    'Recovery omitted owned auxiliary resources or selected another service/shared resource');
recoveryCheck($catalog===$base,'Read-only recovery changed the router catalogue');
foreach (['ppp/secret','ip/hotspot/user','ip/dhcp-server/lease','ipv6/dhcp-server/binding','queue/simple','ppp/profile','ip/hotspot/user/profile','ppp/active'] as $path)
    recoveryCheck(count(array_filter($reads,static fn($entry)=>$entry[0]===$path))===2,'Recovery did not confirm its second ownership observation: '.$path);
$profile=array_values(array_filter($commands,static fn($row)=>$row['path']==='/ppp/profile'))[0];
recoveryCheck($profile['_owner']==='uisp-ros-plugin:service-profile:710','Friendly private profile ownership was lost');

// The same IP, username or manual display label cannot substitute for an ownership marker.
foreach (['missing','foreign','manual','duplicate','damaged','conflicting-owner','invalid-profile','invalid-reserved-queue','malformed'] as $case) {
    $catalog=$base; $reads=[];
    if ($case==='missing') $catalog['ppp/secret']=[$base['ppp/secret'][1]];
    if ($case==='foreign') $catalog['ppp/secret'][0]['comment']='811 - Fixture current owner - 711';
    if ($case==='manual') $catalog['ppp/secret'][0]['comment']='Operator maintained';
    if ($case==='duplicate') $catalog['ppp/secret'][]=['.id'=>'*F1','name'=>'actual-legacy-user','comment'=>'811 - Fixture current owner - 711'];
    if ($case==='damaged') $catalog['ppp/secret'][0]['comment']='damaged - 710';
    if ($case==='conflicting-owner') $catalog['ppp/secret'][0]['comment']='999 - Conflicting owner - 710';
    if ($case==='invalid-profile') $catalog['ppp/profile'][0]['name']='Manual profile';
    if ($case==='invalid-reserved-queue') $catalog['queue/simple'][0]['name']='Manual queue';
    if ($case==='malformed') $catalog['ppp/secret'][0]['.id']='not-a-router-id';
    $before=$catalog;
    recoveryReject(fn()=>(new ApiLifecycleRouterRecovery($reader($catalog,$reads)))->recover($legacy,$device),
        ['lifecycle_router_identity_unknown','lifecycle_identity_changed','lifecycle_resources_unconfirmed']);
    recoveryCheck($catalog===$before,'A rejected '.$case.' recovery changed router rows');
}

// A changed owner or active session between two observations cannot freeze stale permission.
foreach (['owner','address','session','selector'] as $case) {
    $catalog=$base; $reads=[]; $wrapped=$reader($catalog,$reads); $counts=[];
    $changing=static function(array $target,string $path,array $filter,array $fields) use (&$catalog,&$counts,$case,$wrapped): array {
        $counts[$path]=($counts[$path]??0)+1;
        if ($path==='ppp/secret' && $counts[$path]===2) {
            if ($case==='owner') $catalog[$path][0]['comment']='811 - Current fixture owner - 711';
            if ($case==='address') $catalog[$path][0]['remote-address']='192.0.2.99';
            if ($case==='selector') $catalog[$path][]=['.id'=>'*FF','name'=>'actual-legacy-user','comment'=>'811 - Current fixture owner - 711'];
        }
        if ($case==='session' && $path==='ppp/active' && $counts[$path]===2) $catalog[$path][0]['.id']='*E3';
        return $wrapped($target,$path,$filter,$fields);
    };
    recoveryReject(fn()=>(new ApiLifecycleRouterRecovery($changing))->recover($legacy,$device),['lifecycle_changed','lifecycle_router_identity_unknown']);
}

// Dedicated DHCP/Hotspot identities are recovered from their own strict markers as well.
foreach (['dhcp','hotspot'] as $kind) {
    $catalog=$base; $reads=[]; $catalog['ppp/secret']=[$base['ppp/secret'][1]];
    $path=$kind==='dhcp'?'ip/dhcp-server/lease':'ip/hotspot/user';
    $catalog[$path][]=['.id'=>'*F1','comment'=>'810 - Fixture old owner - 710']
        +($kind==='dhcp'?['mac-address'=>'00:11:22:33:44:55','address'=>'192.0.2.71']:['name'=>'legacy-hotspot','address'=>'192.0.2.71']);
    $result=(new ApiLifecycleRouterRecovery($reader($catalog,$reads)))->recover($legacy,$device)[0];
    recoveryCheck($kind==='dhcp'?$result['mac']==='00:11:22:33:44:55':$result['username']==='legacy-hotspot'&&$result['hotspot']===true,
        'Recovery confused protocol identities');
}

// Protocol framing is strict, including modern empty replies; ignored properties never reach the snapshot.
$helper=new ApiLifecycleRouterRecovery(); $decode=new ReflectionMethod(ApiLifecycleRouterRecovery::class,'decode');
if (PHP_VERSION_ID<80100) $decode->setAccessible(true);
recoveryCheck($decode->invoke($helper,['!done'],['.id','name'])===[] && $decode->invoke($helper,['!empty','!done'],['.id','name'])===[],
    'An authoritative empty reply was rejected');
$row=$decode->invoke($helper,['!re','=.id=*A1','=name=fixture','=password=PRIVATE-ACCOUNT','!done'],['.id','name']);
recoveryCheck($row===[['.id'=>'*A1','name'=>'fixture']],'Unexpected protocol properties escaped the allowlist');
foreach ([['!trap','=message=denied','!done'],['!fatal'],['!re','=.id=*A1'],['!re','=.id=*A1','=.id=*A2','!done'],
    ['!done','!done'],['!re','=.id=*A1','=name='.str_repeat('X',4097),'!done']] as $words)
    recoveryReject(fn()=>$decode->invoke($helper,$words,['.id','name']),['lifecycle_router_unavailable']);
$catalog=$base; $reads=[];
recoveryReject(fn()=>(new ApiLifecycleRouterRecovery($reader($catalog,$reads),microtime(true)-1))->recover($legacy,$device),['lifecycle_resources_unconfirmed']);
recoveryCheck($reads===[],'Expired observation started a router request');

// Real command guards and retirement executor consume recovered frozen commands, without a database lookup or live I/O.
$originalInclude=get_include_path(); set_include_path(__DIR__.'/../src/lib'.PATH_SEPARATOR.$originalInclude);
require_once 'batch.php';
class RecoveryLog { public function Append($entry,$level=0): void {} }
function MyLog() { static $log; return $log??=new RecoveryLog(); }
class RecoveryRouterAPI extends RouterosAPI {
    private array $words=[];
    public function write($word,$param2=true) { $this->words[]=$word; return true; }
    public function finishSentence(): void {
        $path=trim(array_shift($this->words),'/'); $split=strrpos($path,'/'); $action=substr($path,$split+1); $path=substr($path,0,$split);
        recoveryCheck($action==='remove','Retirement issued a non-removal mutation');
        $id=null; foreach ($this->words as $word) if (str_starts_with($word,'=.id=')) $id=substr($word,5);
        recoveryCheck($id!==null,'A retirement mutation lacked an exact router record ID');
        $GLOBALS['recoveryRam'][$path]=array_values(array_filter($GLOBALS['recoveryRam'][$path]??[],static fn($row)=>!in_array($row['.id'],explode(',',$id),true)));
        $this->words=[];
    }
    public function read($parse=true) { return []; }
    public function disconnect() { $this->connected=false; }
}
class RecoveryMT extends MT {
    public function __construct() { $this->status=(object)['error'=>false,'message'=>'ok']; }
    protected function api_connect(): ?RouterosAPI { $api=new RecoveryRouterAPI(); $api->connected=true; return $api; }
    protected function read($path,$filter=null) {
        if (is_string($filter)) { $parsed=[]; foreach (explode(',',$filter) as $condition) { [$key,$value]=explode('=',ltrim($condition,'?'),2); $parsed[$key]=$value; } $filter=$parsed; }
        return array_values(array_filter($GLOBALS['recoveryRam'][trim($path,'/')]??[],static function($row) use($filter):bool {
            foreach ((array)$filter as $key=>$value) if ((string)($row[$key]??'')!==(string)$value) return false;
            return true;
        }));
    }
}
$catalog=$base; $reads=[]; $snapshot=(new ApiLifecycleRouterRecovery($reader($catalog,$reads)))->recover($legacy,$device)[0];
$snapshot['batch']='fixture-retirement'; $snapshot['_lifecycle_retirement']=['service_id'=>710,'client_id'=>810,'reason'=>'service_not_found'];
$prepare=new ReflectionMethod(Batch::class,'prepareSnapshotCommands'); if (PHP_VERSION_ID<80100) $prepare->setAccessible(true);
$prepared=$prepare->invoke(new Batch(),$snapshot,(object)$device,'delete');
recoveryCheck(count($prepared['_commands'])===6,'The ordinary Batch builder discarded recovered resources');
$GLOBALS['recoveryRam']=$base; $mt=new RecoveryMT();
$count=$mt->withRetirementScope(fn()=>$mt->do_batch((object)$device,$prepared['_commands']));
recoveryCheck($count===6 && !$mt->failed(),'The guarded executor could not retire a recovered legacy account: '.json_encode($mt->failed()));
recoveryCheck(array_column($GLOBALS['recoveryRam']['ppp/secret'],'name')===['current-user']
    && array_column($GLOBALS['recoveryRam']['ppp/active'],'name')===['current-user']
    && array_column($GLOBALS['recoveryRam']['ppp/profile'],'name')===['Fixture plan [UISP#20]']
    && array_column($GLOBALS['recoveryRam']['queue/simple'],'name')===['uisp-plan-20-parent'],
    'Recovered cleanup removed another current account, its session or a shared plan resource');

// The native observation path emits only bounded print requests on one connection, without passwords in .proplist.
class RecoveryPrintWire extends RouterosAPI {
    private array $words=[];
    private array $reply=[];
    public array $requests=[];
    public int $closed=0;
    public function __construct() { $this->connected=true; }
    public function write($word,$param2=true) { $this->words[]=$word; return true; }
    public function finishSentence(): void {
        $this->requests[]=$this->words; $path=trim(array_shift($this->words),'/');
        recoveryCheck(str_ends_with($path,'/print'),'Native recovery emitted a router mutation'); $path=substr($path,0,-6);
        $filter=[]; $fields=[];
        foreach ($this->words as $word) {
            if (str_starts_with($word,'=.proplist=')) $fields=explode(',',substr($word,11));
            elseif (str_starts_with($word,'?')) { [$key,$value]=explode('=',substr($word,1),2); $filter[$key]=$value; }
            else throw new RuntimeException('Unexpected native recovery request word');
        }
        recoveryCheck($fields && !array_intersect($fields,['password','token','secret']),'Native recovery requested credentials');
        $this->reply=[];
        foreach ($GLOBALS['recoveryWireCatalog'][$path]??[] as $row) {
            foreach ($filter as $key=>$value) if (($row[$key]??null)!==$value) continue 2;
            $this->reply[]='!re';
            foreach ($row as $key=>$value) if (in_array($key,$fields,true)) $this->reply[]='='.$key.'='.$value;
            $this->reply[]='=password=PRIVATE-UNREQUESTED';
        }
        if (!$this->reply) $this->reply[]='!empty';
        $this->reply[]='!done'; $this->words=[];
    }
    public function read($parse=true) { recoveryCheck($parse===false,'Native recovery used an unvalidated parsed response'); return $this->reply; }
    public function disconnect() { $this->closed++; $this->connected=false; }
}
$GLOBALS['recoveryWireCatalog']=$base; $wire=new RecoveryPrintWire(); $native=new ApiLifecycleRouterRecovery();
$nativeApi=new ReflectionProperty(ApiLifecycleRouterRecovery::class,'api'); if (PHP_VERSION_ID<80100) $nativeApi->setAccessible(true);
$nativeApi->setValue($native,$wire); $observed=$native->recover($legacy,$device);
recoveryCheck(count($wire->requests)===16 && $wire->closed===1,'Native recovery failed to reuse/close its observation connection');
recoveryCheck(!str_contains(json_encode($observed),'PRIVATE-UNREQUESTED'),'An unrequested private property escaped native decoding');
set_include_path($originalInclude);
echo "Lifecycle router recovery regression passed: purged legacy identities, strict owner/selector and endpoint guards, repeated observations, PPP/Hotspot/DHCP/IPv6, private resource retirement, protocol bounds and real Batch/MT cleanup.\n";
