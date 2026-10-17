<?php
// A retry for one customer must never restore an obsolete shared profile for another customer.
set_error_handler(function ($severity,$message,$file,$line) {
    if(error_reporting() & $severity) throw new ErrorException($message,0,$severity,$file,$line);
    return false;
});
function checkShared(bool $condition,string $message): void { if(!$condition) throw new RuntimeException($message); }
function snapshotShared(string $key): array {
    $db=new SQLite3('data/jobs.db',SQLITE3_OPEN_READONLY);
    try { return json_decode($db->querySingle('SELECT payload FROM jobs WHERE id='.(int)substr($key,1)),true,512,JSON_THROW_ON_ERROR); }
    finally { $db->close(); }
}
function individualShared(array $commands): array {
    $individual = array_values(array_filter($commands,static function(array $command): bool {
        $path=trim($command['path'] ?? '','/');
        return !in_array($path,['ppp/profile','ip/hotspot/user/profile'],true)
            && !($path==='queue/simple' && in_array($command['name'] ?? '',['servicePlan-1-parent','uisp-plan-1-parent'],true));
    }));
    foreach ($individual as &$command) {
        if (in_array(trim($command['path'] ?? '', '/'), ['ppp/active','ip/hotspot/active'], true))
            unset($command['_reconnect_required']); // Policy follows refreshed rates; the individual identity stays frozen.
    }
    unset($command);
    return $individual;
}
$source=realpath(__DIR__.'/../src'); $original=getcwd();
$directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'uisp-shared-resources-'.bin2hex(random_bytes(8));
mkdir($directory,0700);
foreach(['lib','includes','data','vendor'] as $folder) mkdir($directory.'/'.$folder,0700);
foreach(glob($source.'/lib/*.php') as $file) copy($file,$directory.'/lib/'.basename($file));
foreach(glob($source.'/includes/*') as $file) if(is_file($file)) copy($file,$directory.'/includes/'.basename($file));
file_put_contents($directory.'/vendor/autoload.php', <<<'PHP'
<?php
namespace Ubnt\UcrmPluginSdk\Service;
class UcrmApi {
    public static function create() { return new self(); }
    public function get($path, $options=[]) {
        if ($path==='clients/70') return ['id'=>70,'isArchived'=>false];
        if (preg_match('~^clients/services/(91|92|95|96|97|110|111|112)$~D',$path,$match))
            return ['id'=>(int)$match[1],'clientId'=>70,'status'=>in_array((int)$match[1],[96,97],true)?2:1];
        throw new \RuntimeException('Network prohibited in shared resource regression');
    }
}
PHP);
file_put_contents($directory.'/lib/mt.php', <<<'PHP'
<?php
class MT {
    public static ?string $failedAccount=null;
    public static array $profiles=[];
    public static array $parents=[];
    public static array $batches=[];
    public function do_batch($device,$commands): int {
        self::$batches[]=$commands;
        foreach($commands as $command) {
            $path=trim($command['path'],'/');
            if($path==='ppp/profile' && ($command['action'] ?? '')!=='remove') self::$profiles[$command['name']]=$command;
            if($path==='queue/simple' && preg_match('/^uisp-plan-[1-9][0-9]*-parent$/D',$command['name'] ?? '') && ($command['action'] ?? '')!=='remove') self::$parents[$command['name']]=$command;
            if($path==='ppp/secret' && ($command['name'] ?? '')===self::$failedAccount) return 0;
        }
        return count($commands);
    }
    public function failed(): array{return [];}
    public function success(): array{return [];}
}
PHP);
chdir($directory); set_include_path($directory.'/lib'.PATH_SEPARATOR.get_include_path());
require 'lib/api_common.php'; require 'lib/api_setup.php'; require 'lib/api_update.php'; require 'lib/batch.php';
try {
    $apiSqlite=new ApiSqlite('data/data.db'); $apiSqlite->exec(file_get_contents('includes/schema.sql'));
    (new ApiSetup())->config_load(); $apiSqlite->saveConfig(['version'=>MY_VERSION,'disable_contention'=>false,'active_list'=>'old-active']);
    $apiSqliteCache=new ApiSqlite('data/cache.db'); $apiSqliteCache->exec(file_get_contents('includes/cache.sql'));
    $apiJobQueue=myJobQueue();
    $apiSqlite->insert(['id'=>10,'name'=>'Fixture Router','type'=>'mikrotik','ip'=>'192.0.2.10','user'=>'fixture','password'=>'fixture'],'devices');
    $apiSqlite->insert(['id'=>1,'name'=>'Fixture Plan','ratio'=>1,'uploadSpeed'=>20,'downloadSpeed'=>50],'plans');
    $apiSqliteCache->insert(['id'=>70,'firstName'=>'Fresh','lastName'=>'Client'],'clients');
    foreach([91,92] as $id) {
        $apiSqliteCache->insert(['id'=>$id,'clientId'=>70,'device'=>10,'planId'=>1,'status'=>1,'username'=>'fixture-'.$id,'password'=>' fixture 0 ',
            'uploadSpeed'=>20,'downloadSpeed'=>50],'services');
        $apiSqliteCache->insert(['id'=>$id,'address'=>'192.0.2.'.$id,'address6'=>$id===91?'fd12::/64':null],'network');
        checkShared((new Batch())->set_accounts([$id]),'Initial fixture account failed');
    }
    MT::$failedAccount='fixture-91';
    checkShared(!(new Batch())->set_accounts([91]),'Old operation did not become a prepared failed retry');
    $oldKey=array_keys($apiJobQueue->list())[0];
    $frozen=snapshotShared($oldKey);
    checkShared(MT::$parents['uisp-plan-1-parent']['max-limit']==='40M/100M','Initial shared parent fixture was not populated');
    $apiSqlite->insert(['id'=>11,'name'=>'Same physical router','type'=>'mikrotik','ip'=>'192.0.2.10','ssl'=>true,'user'=>'fixture','password'=>'fixture'],'devices');
    $apiSqliteCache->insert(['id'=>95,'clientId'=>70,'device'=>11,'planId'=>1,'status'=>1,'username'=>'fixture-95','password'=>'fixture',
        'uploadSpeed'=>20,'downloadSpeed'=>50],'services');
    $apiSqliteCache->insert(['id'=>95,'address'=>'192.0.2.95'],'network');
    checkShared((new Batch())->set_accounts([95]),'A second ID for the same physical router failed');
    foreach([96=>2,97=>1] as $id=>$status) {
        $apiSqliteCache->insert(['id'=>$id,'clientId'=>70,'device'=>10,'planId'=>1,'status'=>$status,'username'=>'stale-'.$id,'password'=>'fixture',
            'uploadSpeed'=>20,'downloadSpeed'=>50],'services');
        $apiSqliteCache->insert(['id'=>$id,'address'=>'192.0.2.'.$id,'address6'=>$id===96?'invalid-old-prefix':null],'network');
    }
    $apiJobQueue->enqueue(['id'=>97,'device'=>10,'planId'=>1,'clientId'=>70,'status'=>2,'username'=>'stale-97','_terminal'=>true],'delete');
    checkShared($apiJobQueue->isServiceBlocked(97),'Stale active cache fixture did not receive a terminal barrier');
    $edit=new ApiUpdate((object)['action'=>'edit','data'=>(object)['id'=>1,'uploadOverride'=>15]],'plans'); $edit->exec();
    $apiSqlite->edit(['id'=>1,'name'=>'Fixture Plan Renamed'],'plans');
    $currentProfileName='Fixture Plan Renamed [UISP#1]';
    $expectedIndividual=individualShared($frozen['_commands']);
    foreach($expectedIndividual as &$command) {
        if(in_array(trim($command['path'] ?? '', '/'), ['ppp/secret','ip/hotspot/user'], true)
            && ($command['action'] ?? '')!=='remove') $command['profile']=$currentProfileName;
    }
    unset($command);
    $apiSqliteCache->edit(['id'=>91,'password'=>'a newer credential'],'services');
    $apiSqliteCache->edit(['id'=>91,'address'=>'192.0.2.191','address6'=>'fd13::/64'],'network');
    $apiSqlite->saveConfig(['active_list'=>'new-active']);
    $keys=[]; foreach($apiJobQueue->list() as $key=>$job) if($job['id']===92) $keys[]=$key;
    checkShared(count($keys)===1,'New plan did not schedule the second service');
    $apiJobQueue->run($keys);
    $before=MT::$profiles[$currentProfileName]['rate-limit'];
    checkShared(strpos($before,'15M/')===0,'New plan was not applied to the shared profile');
    $parentBefore=MT::$parents['uisp-plan-1-parent'];
    checkShared($parentBefore['max-limit']==='45M/150M','Shared parent did not count unique services across physical-router aliases');
    checkShared(strpos($parentBefore['target'],'192.0.2.191')!==false,'Shared parent did not read current membership addresses');
    foreach(['192.0.2.91','192.0.2.95','fd12::/64','fd13::/64'] as $address)
        checkShared(in_array($address,explode(',',$parentBefore['target']),true),'Shared parent lost an applied/frozen/alias target: '.$address);
    foreach(['192.0.2.96','192.0.2.97'] as $address)
        checkShared(!in_array($address,explode(',',$parentBefore['target']),true),'Terminal or blocked stale cache recreated a parent member: '.$address);
    checkShared(($parentBefore['_owner'] ?? '')==='uisp-ros-plugin:plan-parent:1','Shared parent is missing verified plugin ownership');
    checkShared(MT::$profiles[$currentProfileName]['address-list']==='new-active','Shared profile did not use the current firewall list');
    MT::$batches=[];
    $apiJobQueue->run([$oldKey]);
    $after=MT::$profiles[$currentProfileName]['rate-limit'];
    checkShared($after===$before,'Retrying the old customer restored the obsolete shared profile: '.$before.' -> '.$after);
    $parentAfter=MT::$parents['uisp-plan-1-parent'];
    unset($parentAfter['batch'],$parentBefore['batch']);
    checkShared($parentAfter===$parentBefore,'Retrying the old customer restored an obsolete shared parent');
    $retried=snapshotShared($oldKey);
    $reconnect=array_values(array_filter($retried['_commands'],static fn(array $command): bool=>trim($command['path'],'/')==='ppp/active'));
    checkShared(count($reconnect)===1 && ($reconnect[0]['_reconnect_required'] ?? null)===true,
        'A refreshed shared profile did not require its own customer to reconnect');
    checkShared(individualShared($retried['_commands'])===$expectedIndividual,'Refreshing shared resources changed an individual command beyond its current shared profile reference');
    checkShared($retried['_device']===$frozen['_device'] && $retried['_config']===$frozen['_config'],
        'Refreshing shared resources changed frozen device or account configuration');
    checkShared((int)$retried['_plan']['id']===(int)$frozen['_plan']['id']
        && $retried['_plan']['name']==='Fixture Plan Renamed' && (float)$retried['_plan']['uploadSpeed']===15.0,
        'The applied plan snapshot did not record the current shared profile policy');
    $account=array_values(array_filter($retried['_commands'],static fn(array $command): bool=>trim($command['path'],'/')==='ppp/secret'))[0];
    checkShared($account['password']===' fixture 0 ' && $account['remote-address']==='192.0.2.91'
        && $account['profile']===$currentProfileName,'Retry used current cache credentials/address or an obsolete profile reference');
    $apiSqlite->edit(['id'=>1,'ratio'=>2],'plans');
    $apiJobQueue->run([$oldKey]);
    checkShared(MT::$parents['uisp-plan-1-parent']['max-limit']==='30M/100M','Three unique services at ratio two did not receive two current-speed shares');
    $apiSqlite->saveConfig(['disable_contention'=>true,'active_list'=>'latest-active','disabled_profile'=>'external-suspended']);
    MT::$batches=[];
    $apiJobQueue->run([$oldKey]);
    checkShared(MT::$profiles[$currentProfileName]['parent-queue']==='none','Retry restored a parent link after contention was disabled');
    checkShared(MT::$profiles[$currentProfileName]['address-list']==='latest-active','Retry restored an obsolete profile firewall list');
    foreach(MT::$batches as $commands) foreach($commands as $command) {
        checkShared(!(trim($command['path'],'/')==='queue/simple' && in_array($command['name'] ?? '',['servicePlan-1-parent','uisp-plan-1-parent'],true)),'Disabled contention recreated a shared parent');
        checkShared(($command['name'] ?? '')!=='external-suspended','Refreshing a shared resource modified the external suspension profile');
    }
    checkShared(individualShared(snapshotShared($oldKey)['_commands'])===$expectedIndividual,'Current contention settings changed frozen individual fields or restored an old profile reference');
    $apiSqlite->execute('DELETE FROM plans WHERE id=?',[1]);
    MT::$batches=[];
    $apiJobQueue->run([$oldKey]);
    checkShared(MT::$batches===[],'A missing shared plan permitted stale writes to the router');

    // Service-specific speeds share the real catalog parent, while profile and
    // burst policy remain isolated from ordinary subscribers of that plan.
    MT::$failedAccount=null;
    $apiSqlite->saveConfig(['disable_contention'=>false]);
    $apiSqlite->insert(['id'=>2,'name'=>'Override Fixture','ratio'=>2,'uploadSpeed'=>5,'downloadSpeed'=>20,
        'burstUpload'=>50,'burstDownload'=>50,'threshUpload'=>20,'threshDownload'=>20,'timeUpload'=>5,'timeDownload'=>5],'plans');
    $apiSqliteCache->insert(['id'=>110,'clientId'=>70,'device'=>10,'planId'=>2,'status'=>1,'username'=>'override-110','password'=>'fixture',
        'uploadSpeed'=>5,'downloadSpeed'=>20,'uploadSpeedOverride'=>10,'downloadSpeedOverride'=>35,'trafficShapingOverrideEnabled'=>1],'services');
    $apiSqliteCache->insert(['id'=>110,'address'=>'198.51.100.110'],'network');
    checkShared((new Batch())->set_accounts([110]),'Service override failed to apply');
    $private=MT::$profiles['uisp-service-110-profile'];
    checkShared(strpos($private['rate-limit'],'10M/35M 15000000/52500000 ')===0,'Private override did not retain its own speeds and proportional burst');
    $overrideParent=MT::$parents['uisp-plan-2-parent'];
    checkShared($overrideParent['limit-at']==='10M/35M' && $overrideParent['max-limit']==='15M/52.5M',
        'A lone override at contention ratio two was capped below its normal or burst rate');
    checkShared($private['parent-queue']==='uisp-plan-2-parent','The service override lost real-plan contention membership');
    $apiSqliteCache->insert(['id'=>111,'clientId'=>70,'device'=>10,'planId'=>2,'status'=>1,'username'=>'ordinary-111','password'=>'fixture',
        'uploadSpeed'=>5,'downloadSpeed'=>20,'trafficShapingOverrideEnabled'=>0],'services');
    $apiSqliteCache->insert(['id'=>111,'address'=>'198.51.100.111'],'network');
    checkShared((new Batch())->set_accounts([111]),'Ordinary member of the override plan failed');
    $base=MT::$profiles['Override Fixture [UISP#2]'];
    checkShared(strpos($base['rate-limit'],'5M/20M 7500000/30000000 ')===0,'A service override changed the shared base profile');
    checkShared(MT::$profiles['uisp-service-110-profile']===$private,'An ordinary service refresh overwrote the private profile');
    $mixedParent=MT::$parents['uisp-plan-2-parent'];
    checkShared($mixedParent['limit-at']==='10M/35M' && $mixedParent['max-limit']==='15M/52.5M',
        'Mixed ordinary/override members did not retain the largest normal and burst rates');
    foreach(['198.51.100.110','198.51.100.111'] as $address)
        checkShared(in_array($address,explode(',',$mixedParent['target']),true),'Mixed parent omitted a member target');

    // A retry refreshes policy from the current catalog without replacing the
    // explicit service speed with the catalog speed or its private identity.
    MT::$failedAccount='override-110';
    checkShared(!(new Batch())->set_accounts([110]),'Override retry fixture did not fail as expected');
    $overrideKey=null;
    foreach($apiJobQueue->list() as $key=>$job) if((int)$job['id']===110) $overrideKey=$key;
    checkShared($overrideKey!==null,'Missing frozen override retry');
    $apiSqlite->edit(['id'=>2,'uploadOverride'=>7,'downloadOverride'=>25,'burstUpload'=>100,'burstDownload'=>100],'plans');
    $apiJobQueue->run([$overrideKey]);
    $refreshedPrivate=MT::$profiles['uisp-service-110-profile'];
    checkShared(strpos($refreshedPrivate['rate-limit'],'10M/35M 20000000/70000000 ')===0,
        'A current plan refresh replaced private override speeds or failed to recompute its burst');
    $retryAccount=array_values(array_filter(snapshotShared($overrideKey)['_commands'],static fn(array $command): bool=>trim($command['path'],'/')==='ppp/secret'))[0];
    checkShared($retryAccount['profile']==='uisp-service-110-profile','A frozen retry reverted to the shared plan profile');
    $apiSqliteCache->edit(['id'=>110,'trafficShapingOverrideEnabled'=>0],'services');
    MT::$failedAccount=null;
    checkShared((new Batch())->set_accounts([110]),'Removing the override failed to apply');
    $returnedParent=MT::$parents['uisp-plan-2-parent'];
    checkShared($returnedParent['limit-at']==='7M/25M' && $returnedParent['max-limit']==='14M/50M',
        'Returning the current override to the plan retained its obsolete normal/burst capacity');
    $lastCommands=MT::$batches[count(MT::$batches)-1];
    $returnedAccount=array_values(array_filter($lastCommands,static fn(array $command): bool=>trim($command['path'],'/')==='ppp/secret' && ($command['action'] ?? '')!=='remove'))[0];
    checkShared($returnedAccount['profile']==='Override Fixture [UISP#2]','Removing an override did not return to the real base plan');
    $accountIndex=$reconnectIndex=$retiredIndex=null;
    foreach($lastCommands as $index=>$command) {
        $path=trim($command['path'],'/');
        if($path==='ppp/secret' && ($command['action'] ?? '')!=='remove') $accountIndex=$index;
        if($path==='ppp/active') $reconnectIndex=$index;
        if($path==='ppp/profile' && ($command['action'] ?? '')==='remove') {
            checkShared(($command['_owner'] ?? '')==='uisp-ros-plugin:service-profile:110'
                && $command['name']==='uisp-service-110-profile','Override removal tried to retire a shared or unowned profile');
            $retiredIndex=$index;
        }
    }
    checkShared($accountIndex!==null && $reconnectIndex!==null && $retiredIndex!==null
        && $retiredIndex>$accountIndex && $retiredIndex>$reconnectIndex,
        'The former private profile was not retired after account reassignment and reconnection');
    $apiSqliteCache->edit(['id'=>110,'trafficShapingOverrideEnabled'=>1,'uploadSpeedOverride'=>null,'downloadSpeedOverride'=>35],'services');
    checkShared((new Batch())->set_accounts([110]),'An unlimited upload override failed to apply');
    $unlimitedParent=MT::$parents['uisp-plan-2-parent'];
    checkShared($unlimitedParent['limit-at']==='0M/35M' && $unlimitedParent['max-limit']==='0M/70M',
        'A finite parent throttled an unlimited override direction');
    checkShared(strpos(MT::$profiles['uisp-service-110-profile']['rate-limit'],'0/35M 0/70000000 ')===0,
        'Unlimited service override did not retain its RouterOS zero rate and zero burst: '.MT::$profiles['uisp-service-110-profile']['rate-limit']);
    $apiSqliteCache->edit(['id'=>110,'trafficShapingOverrideEnabled'=>0],'services');
    checkShared((new Batch())->set_accounts([110]),'Returning an unlimited override to the plan failed');
    checkShared(MT::$parents['uisp-plan-2-parent']['max-limit']==='14M/50M',
        'A completed unlimited override leaked unlimited shared parent capacity');
    $apiSqliteCache->insert(['id'=>112,'clientId'=>70,'device'=>10,'planId'=>2,'status'=>1,'username'=>'override-112','password'=>'fixture',
        'uploadSpeed'=>5,'downloadSpeed'=>20,'uploadSpeedOverride'=>10,'downloadSpeedOverride'=>35,'trafficShapingOverrideEnabled'=>1],'services');
    $apiSqliteCache->insert(['id'=>112,'address'=>'198.51.100.112'],'network');
    checkShared((new Batch())->set_accounts([112]),'A third mixed-rate contention member failed');
    $weightedParent=MT::$parents['uisp-plan-2-parent'];
    checkShared($weightedParent['limit-at']==='16M/56.666667M' && $weightedParent['max-limit']==='20M/70M',
        'Three mixed-rate members did not receive proportional rounded contention shares and burst headroom');
    $readDb=new ApiSqlite('data/data.db',true); $readCache=new ApiSqlite('data/cache.db',true);
    try {
        $plans=Batch::previewPlans($readDb);
        $previewService=$readCache->singleQuery('SELECT s.*,n.address,n.address6 FROM services s LEFT JOIN network n ON s.id=n.id WHERE s.id=112',true);
        $previewService+=['company'=>null,'firstName'=>'Fresh','lastName'=>'Client','network'=>[
            'address'=>$previewService['address'],'address6'=>$previewService['address6']],
            '_device'=>$readDb->singleQuery('SELECT * FROM devices WHERE id=10',true),'_config'=>(array)$readDb->readConfig(),'action'=>'update'];
        $projection=['reserved4'=>[],'reserved6'=>[],'blocked'=>[97=>true],'resourceSnapshots'=>$apiJobQueue->resourceSnapshots(),
            'applied'=>$apiJobQueue->appliedSnapshot(112)];
        $beforePreview=[];
        foreach(['data/data.db','data/cache.db','data/jobs.db'] as $file) $beforePreview[$file]=hash_file('sha256',$file);
        $preview=MtData::previewCommands($previewService,$plans[2],new ApiIP(true,false,['db'=>$readDb,'cache'=>$readCache]+$projection),
            $readDb,$readCache,$projection);
        $previewParent=array_values(array_filter($preview,static fn(array $command):bool=>trim($command['path'],'/')==='queue/simple'
            && ($command['name'] ?? '')==='uisp-plan-2-parent'))[0];
        unset($previewParent['batch'],$weightedParent['batch']);
        checkShared($previewParent===$weightedParent,'Mixed-rate preview and execution calculated different parent policy or membership');
        $off=$previewService; $off['trafficShapingOverrideEnabled']=0;
        $offPreview=MtData::previewCommands($off,$plans[2],new ApiIP(true,false,['db'=>$readDb,'cache'=>$readCache]+$projection),
            $readDb,$readCache,$projection);
        $offParent=array_values(array_filter($offPreview,static fn(array $command):bool=>trim($command['path'],'/')==='queue/simple'
            && ($command['name'] ?? '')==='uisp-plan-2-parent'))[0];
        checkShared($offParent['max-limit']==='14M/50M' && $offParent['limit-at']==='14M/50M',
            'Override-off preview kept the current service old custom normal or burst rate');
        checkShared(count(array_filter($offPreview,static fn(array $command):bool=>trim($command['path'],'/')==='ppp/profile'
            && ($command['action'] ?? '')==='remove' && ($command['_owner'] ?? '')==='uisp-ros-plugin:service-profile:112'))===1,
            'Override-off review omitted the exact private-profile cleanup included in execution');
        foreach($beforePreview as $file=>$hash) checkShared(hash_file('sha256',$file)===$hash,'Read-only parent review modified '.$file);
    } finally { $readDb->close(); $readCache->close(); }
    $fallback=ApiSharedResources::parentMembership(['target'=>'203.0.113.112'],array_replace($previewService,['planId'=>0,'uploadSpeedOverride'=>null]),
        array_replace($plans[2],['id'=>0]),null,null,[],[]);
    checkShared($fallback['target']==='203.0.113.112' && $fallback['limit-at']==='0M/35M' && $fallback['max-limit']==='0M/70M',
        'Fallback parent lost allocated target, unlimited direction or burst headroom');
    $suspended=array_replace($previewService,['id'=>114,'status'=>3,'username'=>'suspended-114','uploadSpeedOverride'=>null,
        'downloadSpeedOverride'=>null,'address'=>'198.51.100.114','address6'=>null,'network'=>['address'=>'198.51.100.114','address6'=>null],
        '_commands'=>[['path'=>'/ppp/secret','profile'=>'external-suspended','remote-address'=>'198.51.100.114'],
            ['path'=>'/queue/simple','name'=>'uisp-service-114-suspension','target'=>'198.51.100.114','parent'=>'none']]]);
    $apiSqliteCache->insert(array_intersect_key($suspended,array_fill_keys(['id','clientId','device','planId','status','username',
        'trafficShapingOverrideEnabled','uploadSpeedOverride','downloadSpeedOverride'],true)),'services');
    $apiSqliteCache->insert(['id'=>114,'address'=>'198.51.100.114'],'network');
    $apiSqlite->insert(['id'=>114,'clientId'=>70,'device'=>10,'planId'=>2,'status'=>3],'services');
    $apiSqlite->insert(['id'=>114,'address'=>'198.51.100.114'],'network');
    $withoutSuspended=ApiSharedResources::parentMembership(['target'=>''], $previewService, $plans[2],
        $apiSqlite,$apiSqliteCache,[$suspended],[]);
    checkShared($withoutSuspended['limit-at']==='16M/56.666667M' && $withoutSuspended['max-limit']==='20M/70M'
        && !in_array('198.51.100.114',explode(',',$withoutSuspended['target']),true),
        'A suspended unlimited service became an active contention member');
    $pendingCleanup=array_replace($suspended,['id'=>115,'status'=>2,'address'=>'198.51.100.115','network'=>['address'=>'198.51.100.115'],
        '_commands'=>[['path'=>'/ppp/profile','name'=>'uisp-service-115-profile','parent-queue'=>'uisp-plan-2-parent','action'=>'remove'],
            ['path'=>'/ppp/secret','name'=>'retiring-115','remote-address'=>'198.51.100.115','action'=>'remove']]]);
    $retainedParent=ApiSharedResources::parentMembership(['target'=>''], $previewService, $plans[2],
        $apiSqlite,$apiSqliteCache,[$suspended,$pendingCleanup],[]);
    checkShared($retainedParent['max-limit']==='0M/0M' && $retainedParent['limit-at']==='0M/0M'
        && in_array('198.51.100.115',explode(',',$retainedParent['target']),true),
        'An unconfirmed terminal cleanup lost its actual old parent membership or unlimited capacity');
    echo "Shared resource regression passed: fresh owned shared resources, alias membership and frozen retries, isolated service overrides, proportional burst and mixed-rate contention, and override removal.\n";
} finally {
    if(isset($apiJobQueue)) $apiJobQueue->close();
    if(isset($apiSqlite)) $apiSqlite->close();
    if(isset($apiSqliteCache)) $apiSqliteCache->close();
    chdir($original);
    $resolved=realpath($directory); $temporaryRoot=realpath(sys_get_temp_dir());
    if($resolved && $temporaryRoot && strpos($resolved,$temporaryRoot.DIRECTORY_SEPARATOR.'uisp-shared-resources-')===0) {
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($iterator as $file) $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
        rmdir($resolved);
    }
}
