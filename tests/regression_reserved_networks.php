<?php
// Real reservation, queue, Batch and command generation against an in-memory transport.
set_error_handler(function ($severity,$message,$file,$line) {
    if (error_reporting() & $severity) throw new ErrorException($message,0,$severity,$file,$line);
    return false;
});
function reservedCheck(bool $condition,string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function reservedReject(callable $work,int $requested,int $blocking,string $source,string $family): void {
    try { $work(); }
    catch (InvalidNetworkAddress $error) {
        reservedCheck($error->context()===['requestedServiceId'=>$requested,'blockingServiceId'=>$blocking,
            'addressSource'=>$source,'addressFamily'=>$family], 'Address failure lost its safe ownership context');
        reservedCheck(strpos($error->getMessage(),'PRIVATE-')===false, 'Address failure disclosed an imported value');
        return;
    }
    throw new RuntimeException('Invalid reservation unexpectedly succeeded');
}
$source=realpath(__DIR__ . '/../src');
$original=getcwd(); $originalIncludePath=get_include_path();
$directory=sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-reserved-networks-' . bin2hex(random_bytes(8));
mkdir($directory,0700);
foreach (['lib','includes','data','vendor'] as $folder) mkdir($directory . '/' . $folder,0700);
foreach (glob($source . '/lib/*.php') as $file) copy($file,$directory . '/lib/' . basename($file));
foreach (glob($source . '/includes/*') as $file) if (is_file($file)) copy($file,$directory . '/includes/' . basename($file));
file_put_contents($directory . '/vendor/autoload.php', '<?php namespace Ubnt\\UcrmPluginSdk\\Service; class UcrmApi { public static function create(){ return new self(); } public function get(){ throw new \\RuntimeException("External network prohibited"); } }');
file_put_contents($directory . '/lib/mt.php', <<<'PHP'
<?php
class MT {
    public static array $writes=[];
    public function do_batch($device,$commands,?callable $beforeWrite=null): int {
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
        'disabled_rate'=>0,'auto_ppp_user'=>false]);
    $apiSqliteCache=new ApiSqlite('data/cache.db'); $apiSqliteCache->exec(file_get_contents('includes/cache.sql'));
    $apiJobQueue=myJobQueue();
    foreach ([21,22] as $id) $apiSqlite->insert(['id'=>$id,'name'=>'Fixture Router ' . $id,
        'type'=>'mikrotik','ip'=>'192.0.2.' . $id,'user'=>'fixture','password'=>'fixture',
        'pool'=>'198.51.100.0/24','pool6'=>null,'pfxLength'=>64], 'devices');
    $apiSqlite->insert(['id'=>1,'name'=>'Fixture Plan','ratio'=>1,'uploadSpeed'=>7,'downloadSpeed'=>21], 'plans');
    $apiSqliteCache->insert(['id'=>71,'firstName'=>'Fixture','lastName'=>'Client'], 'clients');
    foreach ([501=>21,502=>22] as $sid=>$did) {
        $address='192.0.2.' . ($sid-450);
        $apiSqliteCache->insert(['id'=>$sid,'clientId'=>71,'device'=>$did,'planId'=>1,'status'=>1,
            'username'=>'fixture-' . $sid,'password'=>'fixture','uploadSpeed'=>7,'downloadSpeed'=>21], 'services');
        $network=['id'=>$sid,'address'=>$address];
        if ($sid===501) $network['address6']='2001:db8:1::/64';
        $apiSqlite->insert($network, 'network'); $apiSqliteCache->insert($network, 'network');
    }
    $apiSqlite->insert(['id'=>599,'address'=>'PRIVATE-RESERVATION-PASSWORD','address6'=>'PRIVATE-IPV6-PASSWORD'], 'network');
    $apiSqliteCache->insert(['id'=>503,'clientId'=>71,'device'=>22,'planId'=>1,'status'=>1,
        'username'=>'fixture-503','password'=>'fixture','uploadSpeed'=>7,'downloadSpeed'=>21], 'services');
    $apiSqliteCache->insert(['id'=>503,'address'=>'192.0.2.51'], 'network');

    $ip=new ApiIP(); $beforeChanges=$apiSqlite->singleQuery('SELECT total_changes()');
    $ip->set(501,'192.0.2.51'); $ip->set(501,'2001:db8:1::/64',true);
    reservedCheck($apiSqlite->singleQuery('SELECT total_changes()')===$beforeChanges,
        'Unchanged reservation reuse rewrote ownership');
    $ip->set(510,'192.0.2.99');
    reservedCheck($ip->assign(511,(object)['pool'=>'198.51.100.0/24'])==='198.51.100.1',
        'Malformed peer history blocked allocation from a valid pool');
    $ip->set(512,'2001:db8:2::/64',true);
    reservedCheck($apiSqlite->selectAddress(599)==='PRIVATE-RESERVATION-PASSWORD'
        && $apiSqlite->selectAddress(599,true)==='PRIVATE-IPV6-PASSWORD',
        'Peer isolation silently changed the malformed row instead of leaving it reviewable');
    reservedReject(fn()=>$ip->set(501,'PRIVATE-CRM-PASSWORD'),501,501,'input','IPv4');
    reservedReject(fn()=>$ip->set(501,'192.0.2.51/32'),501,501,'input','IPv4');
    reservedCheck($apiSqlite->selectAddress(501)==='192.0.2.51'
        && $apiSqlite->selectAddress(501,true)==='2001:db8:1::/64'
        && $apiSqlite->selectAddress(510)==='192.0.2.99', 'A failed own-input acquisition changed retained reservations');
    reservedReject(fn()=>(new ApiIP())->find_assigned(599),599,599,'reserved','IPv4');
    reservedReject(fn()=>(new ApiIP())->assign(599,(object)['pool'=>'198.51.100.0/24']),599,599,'reserved','IPv4');
    reservedReject(fn()=>(new ApiIP())->find_assigned(599,true),599,599,'reserved','IPv6');
    $apiSqlite->insert(['id'=>598,'address6'=>'2001:db8::598'], 'network');
    reservedReject(fn()=>(new ApiIP())->find_assigned(598,true),598,598,'reserved','IPv6');
    reservedReject(fn()=>(new ApiIP())->assign(598,(object)['pool6'=>'2001:db8::/48'],true),598,598,'reserved','IPv6');
    $apiSqlite->insert(['id'=>597,'address'=>'0','address6'=>'0'], 'network');
    reservedReject(fn()=>(new ApiIP())->find_assigned(597),597,597,'reserved','IPv4');
    reservedReject(fn()=>(new ApiIP())->assign(597,(object)['pool'=>'198.51.100.0/24']),597,597,'reserved','IPv4');
    reservedReject(fn()=>(new ApiIP())->find_assigned(597,true),597,597,'reserved','IPv6');
    reservedReject(fn()=>(new ApiIP())->assign(597,(object)['pool6'=>'2001:db8::/48'],true),597,597,'reserved','IPv6');
    $apiSqlite->delete(597,'network');

    // CRM now has a duplicate on another router, plus corrupt old local rows.
    // Neither fact may stop valid, unchanged accounts from changing billing state.
    foreach ([3,1] as $status) foreach ([501=>21,502=>22] as $sid=>$did) {
        $apiSqliteCache->edit(['id'=>$sid,'status'=>$status], 'services');
        $start=count(MT::$writes);
        reservedCheck((new Batch())->set_accounts([$sid]), 'Unrelated legacy corruption blocked a billing transition');
        $applied=$apiJobQueue->appliedSnapshot($sid);
        reservedCheck(($applied['status']??null)===$status && $apiSqlite->selectAddress($sid)==='192.0.2.' . ($sid-450),
            'Billing transition changed its existing address or lost its applied status');
        $accounts=array_values(array_filter(array_slice(MT::$writes,$start),static fn($row)=>
            trim($row['command']['path']??'','/')==='ppp/secret'));
        reservedCheck(count($accounts)===1 && $accounts[0]['device']===$did
            && $accounts[0]['command']['remote-address']==='192.0.2.' . ($sid-450),
            'Billing transition did not prepare its exact account on the intended router');
    }
    foreach ([504=>'192.0.2.101',505=>'PRIVATE-CRM-ADDRESS-PASSWORD'] as $sid=>$address) {
        $apiSqliteCache->insert(['id'=>$sid,'clientId'=>71,'device'=>22,'planId'=>1,'status'=>3,
            'username'=>'fixture-' . $sid,'password'=>'fixture','uploadSpeed'=>7,'downloadSpeed'=>21], 'services');
        $apiSqliteCache->insert(['id'=>$sid,'address'=>$address], 'network');
    }
    $start=count(MT::$writes); $bulk=new Batch();
    reservedCheck($bulk->set_accounts([504,502]) && $bulk->outcomes()['failed']===0
        && $bulk->outcomes()['completed']===2 && $apiJobQueue->appliedSnapshot(502)['status']===1
        && $apiSqlite->selectAddress(599)==='PRIVATE-RESERVATION-PASSWORD',
        'Malformed peer state blocked an acquisition or another billing transition');
    reservedCheck(!(new Batch())->set_accounts([505]) && $apiSqlite->selectAddress(505)===null,
        'A corrupt current-service address fell back to an allocation');
    $pending=$apiJobQueue->list();
    $blocked=array_values(array_filter($pending,static fn($row)=>(int)$row['id']===504));
    $invalid=array_values(array_filter($pending,static fn($row)=>(int)$row['id']===505));
    reservedCheck($blocked===[] && count($invalid)===1
        && $invalid[0]['error']==='invalid_network_address: requested_service=505 blocking_service=505 source=input family=IPv4',
        'Peer isolation suppressed an own-input error or left an unrelated job blocked');
    // Acquisition remains global across routers; clearing corrupt fixtures does
    // not allow the new duplicate to claim the incumbent's valid address.
    $apiSqlite->delete(599,'network'); $apiSqlite->delete(598,'network');
    $start=count(MT::$writes);
    reservedCheck(!(new Batch())->set_accounts([503]) && count(MT::$writes)===$start
        && $apiSqlite->selectAddress(503)===null, 'A new CRM duplicate weakened global reservation ownership');
    $apiSqliteCache->edit(['id'=>502,'status'=>3], 'services');
    reservedCheck((new Batch())->set_accounts([502]) && $apiJobQueue->appliedSnapshot(502)['status']===3,
        'A failed duplicate prevented a following unrelated suspension');
    $logs=is_file('data/plugin.log') ? file_get_contents('data/plugin.log') : '';
    reservedCheck(strpos($logs,'PRIVATE-')===false, 'Reservation diagnostics disclosed credential-like corrupt values');
    echo "Reserved networks passed: malformed peer history is isolated and retained, unchanged IPv4/IPv6 billing reuse, strict valid ownership, cross-router duplicate isolation, invalid own reservation rejection and safe diagnostics.\n";
} finally {
    if (isset($apiJobQueue)) $apiJobQueue->close();
    if (isset($apiSqlite)) $apiSqlite->close();
    if (isset($apiSqliteCache)) $apiSqliteCache->close();
    chdir($original); set_include_path($originalIncludePath);
    $resolved=realpath($directory); $temporaryRoot=realpath(sys_get_temp_dir());
    if ($resolved && $temporaryRoot && strpos($resolved,$temporaryRoot . DIRECTORY_SEPARATOR . 'uisp-reserved-networks-')===0) {
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($resolved);
    }
}
