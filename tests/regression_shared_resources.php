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
        if (preg_match('~^clients/services/(91|92|95|96|97)$~D',$path,$match))
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
            if($path==='queue/simple' && ($command['name'] ?? '')==='uisp-plan-1-parent' && ($command['action'] ?? '')!=='remove') self::$parents[$command['name']]=$command;
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
    checkShared($retried['_device']===$frozen['_device'] && $retried['_plan']===$frozen['_plan'] && $retried['_config']===$frozen['_config'],'Refreshing shared resources changed the frozen service identity/settings');
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
    echo "Shared resource regression passed: fresh owned readable profile/parent/config, renamed-plan account references, unique physical-router members, applied/prepared IPv4/IPv6 targets, frozen account and disabled-contention safety.\n";
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
