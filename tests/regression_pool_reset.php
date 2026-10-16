<?php
// Exercise real allocations, frozen jobs and command generation; no external transport is used.
set_error_handler(function ($severity,$message,$file,$line) {
    if (error_reporting() & $severity) throw new ErrorException($message,0,$severity,$file,$line);
    return false;
});
function resetCheck(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
$source=realpath(__DIR__ . '/../src');
$original=getcwd(); $originalIncludePath=get_include_path();
$directory=sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-pool-reset-' . bin2hex(random_bytes(8));
mkdir($directory,0700);
foreach (['lib','includes','data','vendor'] as $folder) mkdir($directory . '/' . $folder,0700);
foreach (glob($source . '/lib/*.php') as $file) copy($file,$directory . '/lib/' . basename($file));
foreach (glob($source . '/includes/*') as $file) if (is_file($file)) copy($file,$directory . '/includes/' . basename($file));
file_put_contents($directory . '/vendor/autoload.php', <<<'PHP'
<?php
namespace Ubnt\UcrmPluginSdk\Service;
class UcrmApi {
    public static function create() { return new self(); }
    public function get($path, $options=[]) {
        if ($path==='clients/71') return ['id'=>71,'isArchived'=>false];
        if ($path==='clients/services/501') return ['id'=>501,'clientId'=>71,'status'=>1];
        throw new \RuntimeException('External network prohibited');
    }
}
PHP);
file_put_contents($directory . '/lib/mt.php', <<<'PHP'
<?php
class MT {
    public static array $writes=[];
    public static bool $fail=false;
    public function do_batch($device,$commands,?callable $beforeWrite=null): int {
        if (self::$fail) throw new RuntimeException('Fixture transport interruption');
        if ($beforeWrite) $beforeWrite($commands);
        foreach ($commands as $command) self::$writes[]=['device'=>(int)$device->id,'command'=>$command];
        return count($commands);
    }
    public function failed(): array { return []; }
    public function success(): array { return []; }
}
PHP);
chdir($directory); set_include_path($directory . '/lib' . PATH_SEPARATOR . $originalIncludePath);
require 'lib/api_common.php'; require 'lib/api_setup.php'; require 'lib/batch.php';
try {
    file_put_contents('data/config.json',json_encode(['debugEnable'=>false]));
    $apiSqlite=new ApiSqlite('data/data.db'); $apiSqlite->exec(file_get_contents('includes/schema.sql'));
    (new ApiSetup())->config_load();
    $apiSqlite->saveConfig(['version'=>MY_VERSION,'disable_contention'=>true,'disabled_profile'=>'fixture-paused',
        'disabled_rate'=>0,'auto_ppp_user'=>false,'excl_addr'=>'198.51.100.3']);
    $apiSqliteCache=new ApiSqlite('data/cache.db'); $apiSqliteCache->exec(file_get_contents('includes/cache.sql'));
    $apiJobQueue=myJobQueue();
    $device=['id'=>21,'name'=>'Fixture router','type'=>'mikrotik','ip'=>'192.0.2.21','user'=>'fixture',
        'password'=>'fixture','pool'=>'198.51.100.0/29','pool6'=>'2001:db8:100::/62','pfxLength'=>64];
    $apiSqlite->insert($device,'devices');
    $apiSqlite->insert(['id'=>1,'name'=>'Fixture Plan','ratio'=>1,'uploadSpeed'=>7,'downloadSpeed'=>21],'plans');
    $apiSqliteCache->insert(['id'=>71,'firstName'=>'Fixture','lastName'=>'Client'],'clients');
    $service=['id'=>501,'clientId'=>71,'device'=>21,'planId'=>1,'status'=>1,'username'=>'fixture-reset',
        'company'=>null,'firstName'=>'Fixture','lastName'=>'Client',
        'password'=>'fixture','uploadSpeed'=>7,'downloadSpeed'=>21,'address'=>'198.51.100.1','address6'=>'2001:db8:100::/64'];
    $apiSqliteCache->insert(array_diff_key($service,array_flip(['address','address6','company','firstName','lastName'])),'services');
    $apiSqliteCache->insert(['id'=>501,'address'=>$service['address'],'address6'=>$service['address6']],'network');
    resetCheck((new Batch())->set_accounts([$service]),'Initial fixed account did not apply: ' . json_encode($apiJobQueue->list()));
    resetCheck(empty($apiJobQueue->appliedSnapshot(501)['_pool_allocations']),'Fixed CRM address manufactured pool allocation proof');
    $apiSqlite->insert(['id'=>502,'address'=>'198.51.100.2','address6'=>'2001:db8:100:1::/64'],'network');

    $generation=str_repeat('a',32);
    $service['address']=null; $service['address6']=null;
    $apiSqliteCache->execute('UPDATE network SET address=NULL,address6=NULL WHERE id=501');
    $service['_network_reset']=['address'=>['generation'=>$generation,'previous'=>'198.51.100.1'],
        'address6'=>['generation'=>$generation,'previous'=>'2001:db8:100::/64']];
    // An explicitly cleared flat attribute must also beat stale nested imported values.
    $service['network']=['address'=>'198.51.100.1','address6'=>'2001:db8:100::/64'];
    $before=$apiSqlite->singleQuery('SELECT total_changes()');
    $validator=new MtData(); $validator->validateBeforeCleanup($service+['_device'=>$device],
        $apiJobQueue->appliedSnapshot(501)['_plan'],false);
    resetCheck($apiSqlite->singleQuery('SELECT total_changes()')===$before
        && $apiSqlite->selectAddress(501)==='198.51.100.1'
        && !$apiSqlite->singleQuery("SELECT 1 FROM sqlite_master WHERE name='pool_allocations'"),
        'Read-only reset preflight acquired ownership or created a receipt table');
    MT::$fail=true;
    resetCheck(!(new Batch())->set_accounts([$service]),'Interrupted transport unexpectedly completed');
    $jobs=$apiJobQueue->list(); $key=array_key_last($jobs);
    resetCheck($key!==null && $apiSqlite->selectAddress(501)==='198.51.100.4'
        && $apiSqlite->selectAddress(501,true)==='2001:db8:100:2::/64',
        'Reset reused old/peer/excluded address instead of selecting another address per family');
    $reserved4=$apiJobQueue->reservedAddresses(false); $reserved6=$apiJobQueue->reservedAddresses(true);
    resetCheck(in_array('198.51.100.1',array_column($reserved4,'address'),true)
        && in_array('2001:db8:100::/64',array_column($reserved6,'address6'),true),
        'Selecting a replacement released the old live address before command completion');
    MT::$fail=false;
    $apiJobQueue->runSelected([$key]);
    $applied=$apiJobQueue->appliedSnapshot(501);
    foreach (['address'=>'198.51.100.4','address6'=>'2001:db8:100:2::/64'] as $field=>$value) {
        resetCheck(($applied['_pool_allocations'][$field]['value']??null)===$value
            && $applied['_pool_allocations'][$field]['generation']===$generation,
            'Frozen retry lost actual allocation proof or rotated the address');
    }
    $start=count(MT::$writes);
    resetCheck((new Batch())->set_accounts([$service]) && $apiSqlite->selectAddress(501)==='198.51.100.4'
        && $apiSqlite->selectAddress(501,true)==='2001:db8:100:2::/64',
        'Repeated billing edit with the same reset generation rotated the pool address again');
    $accounts=array_values(array_filter(array_slice(MT::$writes,$start),static fn($row)=>trim($row['command']['path']??'','/')==='ppp/secret'));
    resetCheck(count($accounts)===1 && $accounts[0]['command']['remote-address']==='198.51.100.4',
        'Account command did not use the newly selected IPv4 address');

    // Automatic router-pool changes replace incompatible reservations; literal CRM addresses remain authoritative.
    $other=['id'=>510,'clientId'=>71,'device'=>21,'planId'=>1,'status'=>1,'username'=>'fixture-auto',
        'company'=>null,'firstName'=>'Fixture','lastName'=>'Client',
        'password'=>'fixture','uploadSpeed'=>7,'downloadSpeed'=>21,'address'=>null,'address6'=>null];
    $apiSqlite->insert(['id'=>510,'address'=>'203.0.113.9'],'network');
    resetCheck((new Batch())->set_accounts([$other]) && $apiSqlite->selectAddress(510)==='198.51.100.1',
        'An automatic account retained the former router pool address');
    $fixed=$other; $fixed['id']=511; $fixed['username']='fixture-fixed'; $fixed['address']='203.0.113.10';
    resetCheck((new Batch())->set_accounts([$fixed]) && $apiSqlite->selectAddress(511)==='203.0.113.10'
        && empty($apiJobQueue->appliedSnapshot(511)['_pool_allocations']['address']),
        'A literal fixed CRM address was replaced solely because it lies outside the router pool');
    $missed=$other; $missed['id']=520; $missed['username']='fixture-missed-webhook'; $missed['address']='198.51.100.6';
    resetCheck((new Batch())->set_accounts([$missed]),'Missed-webhook fixture did not apply its fixed address');
    $missed['address']=null;
    resetCheck((new Batch())->set_accounts([$missed]) && $apiSqlite->selectAddress(520)==='198.51.100.5'
        && ($apiJobQueue->appliedSnapshot(520)['_pool_allocations']['address']['previous']??null)==='198.51.100.6',
        'Full synchronization after a missed attribute-clear webhook restored the previous literal IP');
    unset($missed['_network_reset']);
    $priorProof=$apiJobQueue->appliedSnapshot(520)['_pool_allocations']['address'];
    $missed['status']=3;
    resetCheck((new Batch())->set_accounts([$missed]) && $apiSqlite->selectAddress(520)==='198.51.100.5'
        && ($apiJobQueue->appliedSnapshot(520)['_pool_allocations']['address']??null)===$priorProof,
        'Ordinary synchronization rotated an automatic address or lost its pending CRM export receipt');
    $missed['_network_reset']=['address'=>['generation'=>str_repeat('c',32),'previous'=>'198.51.100.6']];
    $missed['address']='203.0.113.11';
    resetCheck((new Batch())->set_accounts([$missed]) && $apiSqlite->selectAddress(520)==='203.0.113.11'
        && empty($apiJobQueue->appliedSnapshot(520)['_pool_allocations']['address']),
        'A new explicit fixed address did not supersede an unfinished reset marker');
    try { (new ApiIP())->set(512,'198.51.100.2'); throw new RuntimeException('Peer collision unexpectedly applied'); }
    catch (NetworkAddressConflict $error) {
        resetCheck($error->context()===['requestedServiceId'=>512,'blockingServiceId'=>502,
            'addressSource'=>'reserved','addressFamily'=>'IPv4'],'Collision diagnostics did not identify the exact valid owner');
    }

    // Failing the second family must roll back the first and keep the live account untouched.
    $broken=$service; unset($broken['network']);
    $broken['_network_reset']=['address'=>['generation'=>str_repeat('b',32),'previous'=>'198.51.100.4'],
        'address6'=>['generation'=>str_repeat('b',32),'previous'=>'2001:db8:100:2::/64']];
    $broken['_device']=$device; $broken['_device']['pool6']='2001:db8:100:2::/64';
    $start=count(MT::$writes);
    resetCheck(!(new Batch())->set_accounts([$broken]) && count(MT::$writes)===$start
        && $apiSqlite->selectAddress(501)==='198.51.100.4'
        && $apiSqlite->selectAddress(501,true)==='2001:db8:100:2::/64',
        'Exhausted reset pool changed reservations or wrote cleanup commands against the existing service');
    $receipt=$apiSqlite->selectCustom('SELECT generation FROM pool_allocations WHERE service_id=501 AND family=4');
    resetCheck($receipt[0]['generation']===$generation,'Failed dual-stack build committed a partial allocation receipt');

    // Cleanup preflight must reuse the exact reset receipt even when every other pool host is occupied.
    $narrow=$other; $narrow['id']=530; $narrow['username']='fixture-receipt-preflight';
    $narrow['_device']=$device; $narrow['_device']['pool']='198.51.100.8/30'; $narrow['_device']['pool6']=null;
    $narrow['_network_reset']=['address'=>['generation'=>str_repeat('d',32),'previous'=>'198.51.100.9']];
    $apiSqlite->insert(['id'=>530,'address'=>'198.51.100.9'],'network');
    resetCheck((new Batch())->set_accounts([$narrow]) && $apiSqlite->selectAddress(530)==='198.51.100.10',
        'Narrow-pool receipt fixture did not allocate its only replacement host');
    $before=$apiSqlite->singleQuery('SELECT total_changes()');
    (new MtData())->validateBeforeCleanup($narrow,$apiJobQueue->appliedSnapshot(530)['_plan'],true);
    resetCheck($apiSqlite->singleQuery('SELECT total_changes()')===$before
        && $apiSqlite->selectAddress(530)==='198.51.100.10',
        'Read-only cleanup preflight rejected or rewrote an already allocated matching reset receipt');

    // The built-in global PPP configuration intentionally combines IPv4 and IPv6 CIDRs.
    $global=(object)['ppp_pool'=>'203.0.113.0/29,2001:db8:200::/62'];
    $apiSqlite->insert(['id'=>540,'address'=>'203.0.113.1','address6'=>'2001:db8:200::/64'],'network');
    $globalReset4=['generation'=>str_repeat('e',32),'previous'=>'203.0.113.1'];
    $globalReset6=['generation'=>str_repeat('e',32),'previous'=>'2001:db8:200::/64'];
    $globalAddresses=new ApiIP();
    resetCheck($globalAddresses->assign(540,null,false,$global,$globalReset4)==='203.0.113.2'
        && $globalAddresses->assign(540,null,true,$global,$globalReset6)==='2001:db8:200:1::/64',
        'A mixed global PPP pool rejected the valid opposite family or lost IPv6 allocation');
    $before=$apiSqlite->singleQuery('SELECT total_changes()');
    resetCheck((new ApiIP(true,true))->assign(540,null,false,$global,$globalReset4)==='203.0.113.2'
        && (new ApiIP(true,true))->assign(540,null,true,$global,$globalReset6)==='2001:db8:200:1::/64'
        && $apiSqlite->singleQuery('SELECT total_changes()')===$before,
        'Mixed global pool reset validation rewrote ownership or failed to reuse exact receipts');
    try {
        (new ApiIP())->assign(541,null,false,(object)['ppp_pool'=>'203.0.113.0/29,2001:db8::/999']);
        throw new RuntimeException('Malformed opposite-family pool was silently ignored');
    } catch (InvalidArgumentException $expected) {
        resetCheck($apiSqlite->selectAddress(541)===null,'Rejecting a malformed pool still acquired an address');
    }
    echo "Pool resets passed: authoritative clear, independent dual-stack generations, read-only preflight, old ownership retention, frozen retry stability, actual allocation receipts, pool migration, fixed overrides and exact collision diagnostics.\n";
} finally {
    if (isset($apiJobQueue)) $apiJobQueue->close();
    if (isset($apiSqlite)) $apiSqlite->close();
    if (isset($apiSqliteCache)) $apiSqliteCache->close();
    chdir($original); set_include_path($originalIncludePath);
    $resolved=realpath($directory); $temporaryRoot=realpath(sys_get_temp_dir());
    if ($resolved && $temporaryRoot && strpos($resolved,$temporaryRoot . DIRECTORY_SEPARATOR . 'uisp-pool-reset-')===0) {
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($resolved);
    }
}
