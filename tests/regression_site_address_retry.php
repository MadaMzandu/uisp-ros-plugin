<?php
// Real queue/build/allocation code with synthetic UISP and RouterOS transports only.
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
function retryCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$source = realpath(__DIR__ . '/../src'); $original = getcwd(); $originalIncludePath = get_include_path();
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-site-retry-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
foreach (['lib','includes','data','vendor'] as $folder) mkdir($directory . '/' . $folder, 0700);
foreach (glob($source . '/lib/*.php') as $file) copy($file, $directory . '/lib/' . basename($file));
// Pause a real worker after preparation but before its physical endpoint gate.
// The synthetic interleaving invokes the actual device save API, never a direct setting write.
$batchPath=$directory.'/lib/batch.php';
$batchSource=str_replace('        // Different plans can still collide on the same account or DHCP identity.',
    '        if (isset($GLOBALS["retryBeforeEndpoint"])) { $hook=$GLOBALS["retryBeforeEndpoint"]; unset($GLOBALS["retryBeforeEndpoint"]); $hook(); }'."\n".
    '        // Different plans can still collide on the same account or DHCP identity.',file_get_contents($batchPath),$hookCount);
retryCheck($hookCount===1,'Worker endpoint interleaving hook was not precise');file_put_contents($batchPath,$batchSource);
foreach (glob($source . '/includes/*') as $file) if (is_file($file)) copy($file, $directory . '/includes/' . basename($file));
file_put_contents($directory . '/vendor/autoload.php', <<<'PHP'
<?php
namespace Ubnt\UcrmPluginSdk\Service;
class UcrmApi {
    public static array $services = [], $patches = [];
    public static bool $unavailable = false;
    public static function create() { return new self(); }
    public function get($path, $data = []) {
        if (self::$unavailable) throw new \RuntimeException('Fixture UISP unavailable');
        if ($path === 'custom-attributes') return [
            ['id'=>1,'key'=>'site','attributeType'=>'service'], ['id'=>2,'key'=>'account','attributeType'=>'service'],
            ['id'=>3,'key'=>'ip','attributeType'=>'service']];
        if ($path === 'clients/71') return ['id'=>71,'isArchived'=>false];
        if (preg_match('#^clients/services/(\d+)$#D', $path, $match) && isset(self::$services[(int)$match[1]])) return self::$services[(int)$match[1]];
        throw new \RuntimeException('Unexpected synthetic UISP request: ' . $path);
    }
    public function patch($path, $data = []) {
        if (self::$unavailable) throw new \RuntimeException('Fixture UISP unavailable');
        if (!preg_match('#^clients/services/(\d+)$#D', $path, $match) || !isset(self::$services[(int)$match[1]])) throw new \RuntimeException('Unexpected synthetic PATCH');
        self::$patches[] = ['path'=>$path,'data'=>$data];
        foreach ($data['attributes'] as $change) foreach (self::$services[(int)$match[1]]['attributes'] as &$attribute)
            if ($attribute['customAttributeId'] === $change['customAttributeId']) $attribute['value'] = $change['value'];
        unset($attribute);
        return self::$services[(int)$match[1]];
    }
}
PHP);
file_put_contents($directory . '/lib/mt.php', <<<'PHP'
<?php
class MT {
    public static array $writes=[];
    public static bool $fail=false;
    public static $writeHook=null;
    public function do_batch($device,$commands,?callable $beforeWrite=null): int {
        if (self::$fail) throw new RuntimeException('Fixture router transport unavailable');
        if (self::$writeHook!==null) { $hook=self::$writeHook;self::$writeHook=null;$hook(); }
        if ($beforeWrite) $beforeWrite($commands);
        foreach ($commands as $command) self::$writes[]=['device'=>(int)$device->id,'command'=>$command];
        return count($commands);
    }
    public function failed(): array { return []; }
    public function success(): array { return []; }
}
PHP);
chdir($directory); set_include_path($directory . '/lib' . PATH_SEPARATOR . $originalIncludePath);
require 'vendor/autoload.php'; require 'lib/api_common.php'; require 'lib/api_setup.php'; require 'lib/batch.php';
require 'lib/api_update.php';
try {
    file_put_contents('data/config.json',json_encode(['debugEnable'=>false]));
    $apiSqlite=new ApiSqlite('data/data.db'); $apiSqlite->exec(file_get_contents('includes/schema.sql'));
    (new ApiSetup())->config_load();
    $apiSqlite->saveConfig(['version'=>MY_VERSION,'disable_contention'=>true,'disabled_profile'=>'fixture-paused',
        'disabled_rate'=>0,'auto_ppp_user'=>false,'router_ppp_pool'=>true,
        'device_name_attr'=>'site','pppoe_user_attr'=>'account','ip_addr_attr'=>'ip','ip_addr6_attr'=>'']);
    $apiSqliteCache=new ApiSqlite('data/cache.db'); $apiSqliteCache->exec(file_get_contents('includes/cache.sql'));
    $apiJobQueue=myJobQueue();
    $device=['id'=>21,'name'=>'Fixture destination','type'=>'mikrotik','ip'=>'192.0.2.21','user'=>'fixture',
        'password'=>'fixture','pool'=>'198.51.100.0/24','pool6'=>null,'pfxLength'=>64];
    $apiSqlite->insert($device,'devices');
    $sourceDevice=$device; $sourceDevice['id']=22; $sourceDevice['name']='Fixture former site'; $sourceDevice['ip']='192.0.2.22'; $sourceDevice['pool']='203.0.113.0/24';
    $apiSqlite->insert($sourceDevice,'devices');
    $plan=['id'=>1,'name'=>'Fixture Plan','ratio'=>1,'uploadSpeed'=>7,'downloadSpeed'=>21]; $apiSqlite->insert($plan,'plans');
    $plan=$apiSqlite->selectCustom('SELECT * FROM plans WHERE id=1')[0];
    $apiSqliteCache->insert(['id'=>71,'firstName'=>'Fixture','lastName'=>'Client'],'clients');
    $prepared = static function (int $id, string $old, ?string $current, bool $automatic=false) use ($device, $plan, $apiSqlite, $apiSqliteCache): array {
        $username='fixture-retry-' . $id;
        $service=['id'=>$id,'clientId'=>71,'device'=>21,'planId'=>1,'status'=>1,'username'=>$username,
            'company'=>null,'firstName'=>'Fixture','lastName'=>'Client','password'=>'PRIVATE-FIXTURE-PASSWORD',
            'uploadSpeed'=>7,'downloadSpeed'=>21,'network'=>['address'=>$old,'address6'=>null],
            '_device'=>$device,'_config'=>(array)$apiSqlite->readConfig(),'_plan'=>$plan];
        $data=(new MtData())->get_data($service,$plan); $commands=[];
        foreach (['pool','parents','profiles','queues','accounts','dhcp6','disconn'] as $key) if (isset($data[$key])) $commands[]=$data[$key];
        $service['_commands']=MtData::safePreparedCommands($service,$commands,'update');
        if ($automatic) $service['network']['address']=null;
        $apiSqliteCache->insert(['id'=>$id,'clientId'=>71,'device'=>21,'planId'=>1,'status'=>1,'username'=>$username,'password'=>'PRIVATE-FIXTURE-PASSWORD'],'services');
        $apiSqliteCache->insert(['id'=>$id,'address'=>$current],'network');
        \Ubnt\UcrmPluginSdk\Service\UcrmApi::$services[$id]=['id'=>$id,'clientId'=>71,'status'=>1,'attributes'=>[
            ['key'=>'site','customAttributeId'=>1,'value'=>'Fixture destination'],
            ['key'=>'account','customAttributeId'=>2,'value'=>$username],['key'=>'ip','customAttributeId'=>3,'value'=>$current]]];
        return $service;
    };
    $principal = static function (array $snapshot): string {
        foreach ($snapshot['_commands'] ?? [] as $command) if (trim($command['path']??'', '/')==='ppp/secret') return $command['remote-address'];
        throw new RuntimeException('Missing applied principal');
    };

    // Simulate an older release: the pending job already froze a foreign-Site address.
    $old=$prepared(601,'203.0.113.61','203.0.113.61'); $key=$apiJobQueue->enqueue($old);
    MT::$fail=true; $apiJobQueue->runSelected([$key]);
    $replacement=$apiSqlite->selectAddress(601);
    retryCheck($replacement !== '203.0.113.61' && strpos($replacement??'', '198.51.100.')===0 && !MT::$writes,
        'Prepared retry did not allocate from the destination before its first router write: ' . json_encode($apiJobQueue->list()));
    retryCheck(in_array('203.0.113.61',array_column($apiJobQueue->reservedAddresses(false),'address'),true),
        'The potentially live old address was released while replacement was still pending');
    $patches=count(\Ubnt\UcrmPluginSdk\Service\UcrmApi::$patches); MT::$fail=false; $apiJobQueue->runSelected([$key]);
    retryCheck($apiJobQueue->completed([$key]) && $principal($apiJobQueue->appliedSnapshot(601))===$replacement
        && $apiSqlite->selectAddress(601)===$replacement && count(\Ubnt\UcrmPluginSdk\Service\UcrmApi::$patches)===$patches,
        'Retry after transport failure rotated the replacement, replayed CRM clearing or retained the old command');
    foreach (MT::$writes as $row) retryCheck(($row['command']['remote-address']??null)!=='203.0.113.61','A foreign old IP was sent to RouterOS');
    retryCheck(!in_array('203.0.113.61',array_column($apiJobQueue->reservedAddresses(false),'address'),true),
        'The superseded address remained reserved after replacement completed');

    // Fresh UISP manual IP is adopted and is never erased by an older job.
    $manual=$prepared(602,'203.0.113.62','198.51.100.62'); $key=$apiJobQueue->enqueue($manual);
    $patches=count(\Ubnt\UcrmPluginSdk\Service\UcrmApi::$patches); $apiJobQueue->runSelected([$key]);
    retryCheck($apiJobQueue->completed([$key]) && $principal($apiJobQueue->appliedSnapshot(602))==='198.51.100.62'
        && count(\Ubnt\UcrmPluginSdk\Service\UcrmApi::$patches)===$patches,
        'A pending prepared command overwrote the newer manual UISP address');

    // An automatic old snapshot can have no desired literal while its command still contains the old address.
    $automatic=$prepared(603,'203.0.113.63',null,true); $key=$apiJobQueue->enqueue($automatic); $apiJobQueue->runSelected([$key]);
    retryCheck($apiJobQueue->completed([$key]) && strpos($principal($apiJobQueue->appliedSnapshot(603)), '198.51.100.')===0,
        'A frozen automatic principal kept its foreign-Site address');

    // Unavailable authoritative evidence leaves the old account/reservation untouched.
    $unknown=$prepared(604,'203.0.113.64','203.0.113.64'); $key=$apiJobQueue->enqueue($unknown);
    $writes=count(MT::$writes); $patches=count(\Ubnt\UcrmPluginSdk\Service\UcrmApi::$patches);
    \Ubnt\UcrmPluginSdk\Service\UcrmApi::$unavailable=true; $apiJobQueue->runSelected([$key]);
    \Ubnt\UcrmPluginSdk\Service\UcrmApi::$unavailable=false;
    retryCheck(!$apiJobQueue->completed([$key]) && count(MT::$writes)===$writes && $apiSqlite->selectAddress(604)==='203.0.113.64'
        && count(\Ubnt\UcrmPluginSdk\Service\UcrmApi::$patches)===$patches,
        'Unconfirmed fresh UISP state allowed a stale write or changed the existing address');

    // A manual correction made after reservation but before a transport retry still wins.
    $intervened=$prepared(605,'203.0.113.65','203.0.113.65'); $key=$apiJobQueue->enqueue($intervened);
    MT::$fail=true; $apiJobQueue->runSelected([$key]); MT::$fail=false;
    $allocated=$apiSqlite->selectAddress(605); retryCheck($allocated!=='203.0.113.65','Intervention fixture was not prepared');
    foreach (\Ubnt\UcrmPluginSdk\Service\UcrmApi::$services[605]['attributes'] as &$attribute)
        if ($attribute['customAttributeId']===3) $attribute['value']='198.51.100.65';
    unset($attribute);
    $patches=count(\Ubnt\UcrmPluginSdk\Service\UcrmApi::$patches); $apiJobQueue->runSelected([$key]);
    retryCheck($apiJobQueue->completed([$key]) && $principal($apiJobQueue->appliedSnapshot(605))==='198.51.100.65'
        && count(\Ubnt\UcrmPluginSdk\Service\UcrmApi::$patches)===$patches,
        'A transport retry overrode the manual IP entered after its replacement was reserved');

    // Classification and allocation must both use current pool mode, not the old job's global pool.
    $oldGlobal=$prepared(606,'203.0.113.66','203.0.113.66');
    $oldGlobal['_config']['router_ppp_pool']=false; $oldGlobal['_config']['ppp_pool']='203.0.113.0/24';
    $oldGlobal['_config']['excl_addr']=''; $oldGlobal['_config']['disabled_profile']='fixture-frozen-profile';
    $apiSqlite->saveConfig(['excl_addr'=>'198.51.100.4']); $key=$apiJobQueue->enqueue($oldGlobal); $apiJobQueue->runSelected([$key]);
    $globalApplied=$apiJobQueue->appliedSnapshot(606);
    retryCheck($apiJobQueue->completed([$key]) && strpos($principal($globalApplied),'198.51.100.')===0
        && $principal($globalApplied)!=='198.51.100.4' && filter_var($globalApplied['_config']['router_ppp_pool'],FILTER_VALIDATE_BOOLEAN)
        && $globalApplied['_config']['disabled_profile']==='fixture-frozen-profile',
        'A refreshed foreign-Site job allocated from its stale global pool or changed unrelated configuration: ' . json_encode($apiJobQueue->list()));

    // A partially created suspension profile retains its verification evidence through an IP rebuild.
    $suspended=$prepared(607,'203.0.113.67','203.0.113.67'); $suspended['status']=3;
    \Ubnt\UcrmPluginSdk\Service\UcrmApi::$services[607]['status']=3;
    $data=(new MtData())->get_data($suspended,$plan); $commands=[];
    foreach (['pool','parents','profiles','queues','accounts','dhcp6','disconn'] as $name) if (isset($data[$name])) $commands[]=$data[$name];
    $suspended['_commands']=MtData::safePreparedCommands($suspended,$commands,'update');
    foreach ($suspended['_commands'] as &$command) {
        if (($command['_bootstrap_suspension']??false)===true) {
            $command['_bootstrap_required']=true; $command['local-address']='192.0.2.254';
        }
        if (trim($command['path']??'','/')==='ppp/active') {
            $command['_reconnect_required']=true; $command['_profile_revision']=7;
        }
    }
    unset($command);
    $key=$apiJobQueue->enqueue($suspended); MT::$fail=true; $apiJobQueue->runSelected([$key]); MT::$fail=false;
    $pending=$apiJobQueue->resourceSnapshots(607)[0];
    $bootstrap=array_values(array_filter($pending['_commands'],static fn($command)=>($command['_bootstrap_suspension']??false)===true))[0];
    retryCheck($bootstrap['_bootstrap_required']===true && $bootstrap['local-address']==='192.0.2.254'
        && in_array('203.0.113.67',array_column($apiJobQueue->reservedAddresses(false),'address'),true),
        'Refreshing the IP erased the partially created profile evidence or its old address hold');
    $apiJobQueue->runSelected([$key]);
    $applied=$apiJobQueue->appliedSnapshot(607);
    $bootstrap=array_values(array_filter($applied['_commands'],static fn($command)=>($command['_bootstrap_suspension']??false)===true))[0];
    retryCheck($apiJobQueue->completed([$key]) && $bootstrap['_bootstrap_required']===true && $bootstrap['local-address']==='192.0.2.254',
        'A completed retry lost its suspension profile creation evidence');

    // Check the rebuild boundary before normal reconnection reconciliation updates current revisions.
    $preserve=new ReflectionMethod(Batch::class,'preservePreparedGuards'); if (PHP_VERSION_ID<80100) $preserve->setAccessible(true);
    $rebuilt=$suspended['_commands'];
    foreach ($rebuilt as &$command) {
        if (($command['_bootstrap_suspension']??false)===true) {
            $command['_bootstrap_required']=false; $command['local-address']=null;
        }
        if (trim($command['path']??'','/')==='ppp/active') unset($command['_reconnect_required'],$command['_profile_revision']);
    }
    unset($command);
    $legacy=$suspended['_commands'];
    foreach ($legacy as &$command) if (trim($command['path']??'','/')==='ppp/active') unset($command['_service_id'],$command['_client_id']);
    unset($command);
    $retained=$preserve->invoke(new Batch(),$legacy,$rebuilt,$suspended);
    $active=array_values(array_filter($retained,static fn($command)=>trim($command['path']??'','/')==='ppp/active'))[0];
    retryCheck($active['_reconnect_required']===true && $active['_profile_revision']===7,
        'Rebuilding a legacy prepared command erased pending reconnection or shared-profile revision evidence');
    foreach (['configuration','missing','duplicate','local-address','revision'] as $change) {
        $before=$suspended['_commands']; $after=$rebuilt;
        foreach ($after as $index=>&$command) if (($command['_bootstrap_suspension']??false)===true) {
            if ($change==='configuration') $command['_suspension_configuration']['addressList']='different-fixture-list';
            if ($change==='missing') unset($after[$index]);
            if ($change==='duplicate') $after[]=$command;
            break;
        }
        unset($command);
        if ($change==='local-address' || $change==='revision') foreach ($before as &$command) {
            if ($change==='local-address' && ($command['_bootstrap_suspension']??false)===true) $command['local-address']='unverified';
            if ($change==='revision' && trim($command['path']??'','/')==='ppp/active') $command['_profile_revision']=-1;
        }
        unset($command); $rejected=false;
        try { $preserve->invoke(new Batch(),$before,$after,$suspended); }
        catch (RuntimeException $failure) { $rejected=$failure->getMessage()==='site_address_prepared_guard_changed'; }
        retryCheck($rejected,'An IP rebuild accepted changed or incomplete preparation evidence: ' . $change);
    }
    // A pool edit must save without a global catalog refresh. This SDK deliberately
    // rejects catalog GETs, as a deployment with an unrelated unknown Site would.
    $poolChange=$prepared(608,'198.51.100.68','198.51.100.68');
    $oldKey=$apiJobQueue->enqueue($poolChange);
    $apiSqlite->execute('DELETE FROM network WHERE id=608');
    $apiSqlite->insert(['id'=>699,'address'=>'198.51.100.68'],'network');
    $beforeEditJobs=$apiJobQueue->list(); $beforeEditCache=hash_file('sha256','data/cache.db');
    $edit=new ApiUpdate(['action'=>'edit','data'=>['id'=>21,'name'=>$device['name'],'ip'=>$device['ip'],
        'user'=>'fixture','password'=>'fixture','pool'=>'198.18.0.0/24','pool6'=>null,'pfxLength'=>64]],'devices');
    $edit->exec();
    retryCheck(($edit->result()['pool']??null)==='198.18.0.0/24' && $apiJobQueue->list()===$beforeEditJobs
        && hash_file('sha256','data/cache.db')===$beforeEditCache,
        'Saving a pool depended on the global UISP cache, changed reservations or started router work');
    retryCheck((int)$apiSqlite->singleQuery('SELECT count(*) FROM device_pool_history')===1,
        'Saving the new pool lost the previous Site range provenance');
    $patches=count(\Ubnt\UcrmPluginSdk\Service\UcrmApi::$patches);
    $apiJobQueue->runSelected([$oldKey]);
    $replacement=$apiSqlite->selectAddress(608);
    retryCheck($apiJobQueue->completed([$oldKey]) && strpos($replacement??'','198.18.0.')===0
        && $principal($apiJobQueue->appliedSnapshot(608))===$replacement
        && $apiSqlite->selectAddress(699)==='198.51.100.68'
        && count(\Ubnt\UcrmPluginSdk\Service\UcrmApi::$patches)===$patches+1,
        'An older prepared job replayed a former Site IP or was blocked by its obsolete reservation: '.json_encode($apiJobQueue->list()));
    $patches=count(\Ubnt\UcrmPluginSdk\Service\UcrmApi::$patches);
    $repeat=new Batch(); $repeatSuccess=$repeat->set_accounts([608]);
    retryCheck($repeatSuccess && $apiSqlite->selectAddress(608)===$replacement
        && count(\Ubnt\UcrmPluginSdk\Service\UcrmApi::$patches)===$patches,
        'A second synchronization rotated the pool replacement or repeated its CRM clear: '.json_encode($repeat->outcomes()));
    $history=$apiSqlite->selectAllFromTable('device_pool_history');
    $invalid=new ApiUpdate(['action'=>'edit','data'=>['id'=>21,'pool'=>'198.18.0.0/999']],'devices');
    $rejected=false;try{$invalid->exec();}catch(InvalidArgumentException $error){$rejected=$error->getMessage()==='invalid_device_pool';}
    retryCheck($rejected && $apiSqlite->singleQuery('SELECT pool FROM devices WHERE id=21')==='198.18.0.0/24'
        && $apiSqlite->selectAllFromTable('device_pool_history')===$history,'An invalid pool partially changed saved settings/history');
    // A pool can be saved after a job prepares commands but before the worker owns
    // its final endpoint gate. Reject that stale batch, then recover with the new pool.
    $race=$prepared(609,'198.18.0.69','198.18.0.69');$race['_device']=$apiSqlite->singleQuery('SELECT * FROM devices WHERE id=21',true);
    $raceKey=$apiJobQueue->enqueue($race);$writes=count(MT::$writes);
    $GLOBALS['retryBeforeEndpoint']=static function():void{(new ApiUpdate(['action'=>'edit','data'=>['id'=>21,'pool'=>'198.19.0.0/24']],'devices'))->exec();};
    $apiJobQueue->runSelected([$raceKey]);
    retryCheck(!$apiJobQueue->completed([$raceKey]) && count(MT::$writes)===$writes
        && $apiSqlite->singleQuery('SELECT pool FROM devices WHERE id=21')==='198.19.0.0/24',
        'A prepared worker applied its obsolete pool after a concurrent device save');
    $apiJobQueue->runSelected([$raceKey]);$raceAddress=$apiSqlite->selectAddress(609);
    retryCheck($apiJobQueue->completed([$raceKey])&&strpos($raceAddress??'','198.19.0.')===0
        &&$principal($apiJobQueue->appliedSnapshot(609))===$raceAddress,'Stale pool guard prevented a normal retry with the new pool');
    // An empty or exhausted replacement range cannot clear UISP or retire the old account.
    $empty=$prepared(610,'198.19.0.70','198.19.0.70');$empty['_device']=$apiSqlite->singleQuery('SELECT * FROM devices WHERE id=21',true);
    $emptyKey=$apiJobQueue->enqueue($empty);(new ApiUpdate(['action'=>'edit','data'=>['id'=>21,'pool'=>null]],'devices'))->exec();
    $writes=count(MT::$writes);$patches=count(\Ubnt\UcrmPluginSdk\Service\UcrmApi::$patches);$apiJobQueue->runSelected([$emptyKey]);
    retryCheck(!$apiJobQueue->completed([$emptyKey])&&count(MT::$writes)===$writes
        &&count(\Ubnt\UcrmPluginSdk\Service\UcrmApi::$patches)===$patches&&$apiSqlite->selectAddress(610)==='198.19.0.70',
        'An empty destination pool cleared the live attribute or allowed stale router writes');
    (new ApiUpdate(['action'=>'edit','data'=>['id'=>21,'pool'=>'198.20.0.0/30']],'devices'))->exec();
    foreach([690=>'198.20.0.1',691=>'198.20.0.2']as$owner=>$address)$apiSqlite->insert(['id'=>$owner,'address'=>$address],'network');
    $apiJobQueue->runSelected([$emptyKey]);
    retryCheck(!$apiJobQueue->completed([$emptyKey])&&count(MT::$writes)===$writes
        &&count(\Ubnt\UcrmPluginSdk\Service\UcrmApi::$patches)===$patches&&$apiSqlite->selectAddress(610)==='198.19.0.70',
        'An exhausted destination pool caused premature CRM clearing or router mutation');
    // A larger replacement that still contains the old address needs no rotation.
    (new ApiUpdate(['action'=>'edit','data'=>['id'=>21,'pool'=>'198.18.0.0/15']],'devices'))->exec();$apiJobQueue->runSelected([$emptyKey]);
    retryCheck($apiJobQueue->completed([$emptyKey])&&$apiSqlite->selectAddress(610)==='198.19.0.70'
        &&count(\Ubnt\UcrmPluginSdk\Service\UcrmApi::$patches)===$patches,'An address valid in the enlarged pool was blocked or rotated: '.json_encode([
            $apiJobQueue->selectionStatus([$emptyKey]),$apiSqlite->selectAddress(610),count(\Ubnt\UcrmPluginSdk\Service\UcrmApi::$patches),$patches]));
    // Once a worker holds the physical endpoint gate, a setting save must defer
    // before either its device row or pool provenance can change.
    $locked=$prepared(611,'198.18.0.71','198.18.0.71');$locked['_device']=$apiSqlite->singleQuery('SELECT * FROM devices WHERE id=21',true);
    $lockedKey=$apiJobQueue->enqueue($locked);$history=$apiSqlite->selectAllFromTable('device_pool_history');$saveRefused=false;
    MT::$writeHook=static function()use(&$saveRefused):void{
        try{(new ApiUpdate(['action'=>'edit','data'=>['id'=>21,'pool'=>'198.21.0.0/24']],'devices'))->exec();}
        catch(RuntimeException$error){$saveRefused=strpos($error->getMessage(),'provisioning or maintenance operation')!==false;}
    };
    $apiJobQueue->runSelected([$lockedKey]);
    retryCheck($saveRefused&&$apiJobQueue->completed([$lockedKey])&&$apiSqlite->singleQuery('SELECT pool FROM devices WHERE id=21')==='198.18.0.0/15'
        &&$apiSqlite->selectAllFromTable('device_pool_history')===$history,'Concurrent pool save partially committed during an active router write');
    // The new final guard does not reinterpret a frozen shared/global PPP pool.
    $global=$locked;$global['_config']['router_ppp_pool']=false;$global['_config']['ppp_pool']='198.18.0.0/24';
    (new ApiUpdate(['action'=>'edit','data'=>['id'=>21,'pool'=>null]],'devices'))->exec();
    $guard=new ReflectionMethod(Batch::class,'assertSavedPoolAddresses');if(PHP_VERSION_ID<80100)$guard->setAccessible(true);
    $guard->invoke(new Batch(),$global,(object)$locked['_device']);
    echo "Queued Site address retries passed: prepared snapshots, stable replacement, manual overrides, pool edits without global refresh, same-Site former ranges and obsolete conflicting reservations.\n";
} finally {
    if (isset($apiJobQueue)) $apiJobQueue->close(); if (isset($apiSqlite)) $apiSqlite->close(); if (isset($apiSqliteCache)) $apiSqliteCache->close();
    chdir($original); set_include_path($originalIncludePath);
    $resolved=realpath($directory); $temporaryRoot=realpath(sys_get_temp_dir());
    if ($resolved && $temporaryRoot && strpos($resolved,$temporaryRoot . DIRECTORY_SEPARATOR . 'uisp-site-retry-')===0) {
        $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); rmdir($resolved);
    }
}
