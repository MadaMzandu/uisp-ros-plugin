<?php
// Real Batch/ApiIP/job queue, synthetic GET-only CRM and an in-memory RouterOS transport.
set_error_handler(function ($severity,$message,$file,$line) {
    if (error_reporting() & $severity) throw new ErrorException($message,0,$severity,$file,$line);
    return false;
});
function repairBatchCheck(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
function repairBatchState(): array {
    global $apiSqlite;
    return ['network'=>$apiSqlite->selectAllFromTable('network'),'services'=>$apiSqlite->selectAllFromTable('services'),
        'plans'=>$apiSqlite->selectAllFromTable('plans'),'queue_hash'=>hash_file('sha256','data/jobs.db'),'writes'=>MT::$writes];
}
$source=realpath(__DIR__.'/../src'); $original=getcwd(); $originalInclude=get_include_path();
$directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'uisp-repair-batch-'.bin2hex(random_bytes(8)); mkdir($directory,0700);
foreach (['lib','includes','data','vendor'] as $folder) mkdir($directory.'/'.$folder,0700);
foreach (glob($source.'/lib/*.php') as $file) copy($file,$directory.'/lib/'.basename($file));
foreach (glob($source.'/includes/*') as $file) if (is_file($file)) copy($file,$directory.'/includes/'.basename($file));
file_put_contents($directory.'/vendor/autoload.php','<?php namespace Ubnt\\UcrmPluginSdk\\Service; class UcrmApi { public static function create(){return new self();} public function get(){throw new \\RuntimeException("External network prohibited");}}');
file_put_contents($directory.'/lib/mt.php', <<<'PHP'
<?php
class MT {
    public static array $writes=[];
    public function do_batch($device,$commands,?callable $beforeWrite=null): int {
        if ($beforeWrite) $beforeWrite($commands);
        foreach ($commands as $command) self::$writes[]=['device'=>(int)$device->id,'command'=>$command];
        return count($commands);
    }
    public function failed(): array{return [];} public function success(): array{return [];}
}
PHP);
chdir($directory); set_include_path($directory.'/lib'.PATH_SEPARATOR.$originalInclude);
require 'lib/api_common.php'; require 'lib/api_setup.php'; require 'lib/batch.php'; require 'lib/api_network_repair.php';
try {
    file_put_contents('data/config.json',json_encode(['debugEnable'=>false]));
    $apiSqlite=new ApiSqlite('data/data.db'); $apiSqlite->exec(file_get_contents('includes/schema.sql')); (new ApiSetup())->config_load();
    $apiSqlite->saveConfig(['version'=>MY_VERSION,'disable_contention'=>true,'disabled_profile'=>'fixture-paused',
        'disabled_rate'=>0,'auto_ppp_user'=>false,'ip_addr_attr'=>'fixture_ip','ip_addr6_attr'=>'fixture_ip6']);
    $apiSqliteCache=new ApiSqlite('data/cache.db'); $apiSqliteCache->exec(file_get_contents('includes/cache.sql')); $apiJobQueue=myJobQueue();
    $apiSqlite->insert(['id'=>21,'name'=>'Fixture Router','type'=>'mikrotik','ip'=>'192.0.2.21','user'=>'fixture','password'=>'fixture',
        'pool'=>'198.51.100.0/24','pool6'=>null,'pfxLength'=>64],'devices');
    $apiSqlite->insert(['id'=>1,'name'=>'Fixture First Plan','ratio'=>1,'uploadSpeed'=>7,'downloadSpeed'=>21],'plans');
    $apiSqlite->insert(['id'=>2,'name'=>'Fixture Current Plan','ratio'=>1,'uploadSpeed'=>11,'downloadSpeed'=>33],'plans');
    $apiSqliteCache->insert(['id'=>71,'firstName'=>'Fixture','lastName'=>'Client'],'clients');
    $apiSqliteCache->insert(['id'=>601,'clientId'=>71,'device'=>21,'planId'=>1,'status'=>1,'username'=>'fixture-601','password'=>'fixture',
        'uploadSpeed'=>7,'downloadSpeed'=>21],'services');
    $apiSqlite->insert(['id'=>601,'address'=>'192.0.2.61'],'network'); $apiSqliteCache->insert(['id'=>601,'address'=>'192.0.2.61'],'network');
    repairBatchCheck((new Batch())->set_accounts([601]),'Initial valid service was not applied');
    $appliedBefore=$apiJobQueue->appliedSnapshot(601); $plansBefore=$apiSqlite->selectAllFromTable('plans');
    foreach ([599=>'192.0.2.199',596=>'192.0.2.196'] as $id=>$address) {
        $apiSqlite->insert(['id'=>$id,'clientId'=>71,'device'=>21,'planId'=>1,'status'=>1],'services');
        $apiSqlite->insert(['id'=>$id,'address'=>'PRIVATE-MALFORMED-'.$id],'network');
        $apiSqliteCache->insert(['id'=>$id,'clientId'=>71,'device'=>21,'planId'=>1,'status'=>1,'username'=>'fixture-'.$id,'password'=>'fixture',
            'uploadSpeed'=>7,'downloadSpeed'=>21],'services');
        $apiSqliteCache->insert(['id'=>$id,'address'=>$address],'network');
    }
    $apiSqliteCache->edit(['id'=>601,'planId'=>2,'status'=>3,'uploadSpeed'=>11,'downloadSpeed'=>33],'services');
    $apiSqliteCache->edit(['id'=>601,'address'=>'192.0.2.62'],'network');
    $writeCount=count(MT::$writes);
    repairBatchCheck(!(new Batch())->set_accounts([601,599,596]),'Corrupt reservation unexpectedly allowed acquisition');
    repairBatchCheck(count(MT::$writes)===$writeCount && $apiJobQueue->appliedSnapshot(601)===$appliedBefore
        && $apiSqlite->selectAddress(601)==='192.0.2.61' && $apiSqlite->selectAllFromTable('plans')===$plansBefore,
        'Blocked update changed RouterOS/current applied status or rates');
    $pending=$apiJobQueue->list(); $keys=array_column($pending,'jobId');
    repairBatchCheck(count($pending)===3,'Expected three retained updates');
    $services=[];
    foreach ([601=>['192.0.2.62',3],599=>['192.0.2.199',1],596=>['192.0.2.196',1]] as $id=>$data) {
        $services[$id]=['id'=>$id,'clientId'=>71,'status'=>$data[1],'attributes'=>[
            ['key'=>'fixture_ip','customAttributeId'=>1,'value'=>$data[0]],['key'=>'fixture_ip6','customAttributeId'=>2,'value'=>null]]];
    }
    $request=static function(string $path,array $parameters) use (&$services) {
        if($path==='custom-attributes') return [['id'=>1,'key'=>'fixture_ip','attributeType'=>'service'],['id'=>2,'key'=>'fixture_ip6','attributeType'=>'service']];
        if($path==='clients/71') return ['id'=>71,'isArchived'=>false];
        if($path==='clients/services') return array_slice(array_values($services),$parameters['offset'],$parameters['limit']);
        if(preg_match('~^clients/services/([0-9]+)$~D',$path,$match) && isset($services[(int)$match[1]])) return $services[(int)$match[1]];
        throw new RuntimeException('Unexpected synthetic CRM read');
    };
    $repair=new ApiNetworkRepair($request); $initialState=repairBatchState();
    foreach ([599,596] as $id) {
        $preview=$repair->preview([['serviceId'=>$id,'fields'=>['address']]]);
        repairBatchCheck(count($preview['candidates'])===1 && $preview['candidates'][0]['pending_jobs']===1,
            'Actual queue-generated invalid prior/identity prevented explicit repair: '.json_encode($preview));
        $result=$repair->apply($preview['previewToken']);
        repairBatchCheck($result['jobs_preserved'] && hash_file('sha256','data/jobs.db')===$initialState['queue_hash']
            && MT::$writes===$initialState['writes'] && $apiJobQueue->appliedSnapshot(601)===$appliedBefore,
            'Repair itself wrote RouterOS, rewrote pending intent or changed current service');
    }
    $retries=$apiJobQueue->runSelected($keys);
    repairBatchCheck(count($retries)===3 && array_reduce($retries,static fn($good,$row)=>$good && $row['success'],true),
        'Normal selected retry did not complete after safe repair');
    $after=$apiJobQueue->appliedSnapshot(601);
    repairBatchCheck($after['status']===3 && $after['_plan']['id']===2 && $after['_plan']['uploadSpeed']===11
        && $after['_plan']['downloadSpeed']===33 && $apiSqlite->selectAddress(601)==='192.0.2.62'
        && $apiSqlite->selectAllFromTable('plans')===$plansBefore,'Normal retry lost intended status/rates or altered configured plans');
    $paused=array_values(array_filter(array_slice(MT::$writes,$writeCount),static fn($row)=>trim($row['command']['path']??'','/')==='ppp/secret'
        && ($row['command']['name']??null)==='fixture-601'));
    repairBatchCheck(count($paused)===1 && $paused[0]['command']['profile']==='fixture-paused','Ordinary retry lost the requested suspension');
    $apiSqliteCache->edit(['id'=>601,'status'=>1],'services'); $rateStart=count(MT::$writes);
    repairBatchCheck((new Batch())->set_accounts([601]),'Ordinary reactivation after repair failed');
    $profiles=array_values(array_filter(array_slice(MT::$writes,$rateStart),static fn($row)=>trim($row['command']['path']??'','/')==='ppp/profile'));
    repairBatchCheck((bool)array_filter($profiles,static fn($row)=>str_starts_with($row['command']['rate-limit']??'','11M/33M ')),
        'Plan outcome was inferred from label instead of actual generated RouterOS rate');
    // Corrupt an already applied local record; restoration to its exact confirmed
    // current IP is allowed without changing the account or its applied snapshot.
    $apiSqlite->edit(['id'=>599,'address'=>'PRIVATE-LOCAL-CORRUPTION'],'network');
    $sameBefore=repairBatchState(); $sameApplied=$apiJobQueue->appliedSnapshot(599);
    $preview=$repair->preview([['serviceId'=>599,'fields'=>['address']]]);
    repairBatchCheck(count($preview['candidates'])===1,'Exact current applied address was unnecessarily blocked');
    $repair->apply($preview['previewToken']);
    repairBatchCheck($apiSqlite->selectAddress(599)==='192.0.2.199' && $apiJobQueue->appliedSnapshot(599)===$sameApplied
        && hash_file('sha256','data/jobs.db')===$sameBefore['queue_hash'] && MT::$writes===$sameBefore['writes'],
        'Current-IP restoration changed live account ownership');
    $apiSqlite->edit(['id'=>599,'address'=>'PRIVATE-LOCAL-CORRUPTION'],'network');
    $services[599]['attributes'][0]['value']='192.0.2.198'; $apiSqliteCache->edit(['id'=>599,'address'=>'192.0.2.198'],'network');
    $changedApplied=$repair->preview([['serviceId'=>599,'fields'=>['address']]]);
    repairBatchCheck($changedApplied['previewToken']===null && $changedApplied['blocked'][0]['reason']==='applied_ownership',
        'Changing a corrupt local row released or replaced a different actually applied IP');
    $services[599]['attributes'][0]['value']=null; $apiSqliteCache->edit(['id'=>599,'address'=>null],'network');
    $blocked=$repair->preview([['serviceId'=>599,'fields'=>['address']]]);
    repairBatchCheck($blocked['previewToken']===null && $blocked['blocked'][0]['reason']==='applied_ownership',
        'A fresh CRM absence released an actually applied current IP');
    echo "Network repair Batch passed: two actual malformed-prior pending updates repaired separately, queue/RouterOS unchanged during repair, ordinary retry applies intended plan/status/IP, exact current applied IP restoration and no applied-address release.\n";
} finally {
    if(isset($apiJobQueue)) $apiJobQueue->close(); if(isset($apiSqlite)) $apiSqlite->close(); if(isset($apiSqliteCache)) $apiSqliteCache->close();
    chdir($original); set_include_path($originalInclude);
    $resolved=realpath($directory); $temporary=realpath(sys_get_temp_dir());
    if($resolved && $temporary && strpos($resolved,$temporary.DIRECTORY_SEPARATOR.'uisp-repair-batch-')===0) {
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($iterator as $file) $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname()); rmdir($resolved);
    }
}
