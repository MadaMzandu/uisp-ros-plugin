<?php
// Public pending-job explanations use only frozen intent and applied journals.
set_error_handler(static function ($severity,$message,$file,$line) {
    if (error_reporting() & $severity) throw new ErrorException($message,0,$severity,$file,$line);
    return false;
});
require_once __DIR__.'/../src/lib/mt_data.php';
require_once __DIR__.'/../src/lib/api_job_queue.php';
require_once __DIR__.'/../src/lib/api_job_changes.php';
function jobChangesCheck(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
function jobChangesRows(array $summary): array { return array_column($summary['changes'],null,'field'); }
class JobChangesAddresses extends ApiIP { public function beginService(int $serviceId): void {} }
class JobChangesBuilder extends MtData
{
    public string $ipv4='192.0.2.10';
    protected function ipapi(): ApiIP { static $addresses; return $addresses ??= new JobChangesAddresses(true); }
    protected function ip($ip6=false): ?string { return $ip6 ? null : $this->ipv4; }
}
$basePlan=['id'=>9,'name'=>'Synthetic Plan','ratio'=>2,'uploadSpeed'=>5,'downloadSpeed'=>20,
    'limitUpload'=>2500000,'limitDownload'=>15000000,'burstUpload'=>10000000,'burstDownload'=>30000000,
    'threshUpload'=>4000000,'threshDownload'=>12000000,'priorityUpload'=>4,'priorityDownload'=>5,
    'timeUpload'=>8,'timeDownload'=>10,'_sourceLimitUpload'=>50,'_sourceLimitDownload'=>25,
    '_sourceBurstUpload'=>100,'_sourceBurstDownload'=>50,'_sourceThreshUpload'=>20,'_sourceThreshDownload'=>40];
$service=['id'=>101,'clientId'=>201,'device'=>1,'planId'=>9,'status'=>1,'username'=>'synthetic-account',
    'company'=>null,'firstName'=>'Synthetic','lastName'=>'Client','password'=>'PRIVATE-ACCOUNT-SECRET',
    'action'=>'update','network'=>['address'=>'192.0.2.10','address6'=>null],
    '_device'=>['id'=>1,'name'=>'Synthetic Site','ip'=>'192.0.2.1','user'=>'PRIVATE-ROUTER-USER','password'=>'PRIVATE-ROUTER-SECRET'],
    '_config'=>['disable_contention'=>true,'active_list'=>'fixture-active','disabled_profile'=>'fixture-disabled','disabled_rate'=>1],
    '_token'=>'PRIVATE-TOKEN'];
$build=static function (array $service,array $plan,string $ip='192.0.2.10'): array {
    $builder=new JobChangesBuilder(); $builder->ipv4=$ip;
    $service['_plan']=ApiServiceQos::effectivePlan($service,$plan);
    $commands=array_values($builder->get_data($service,$plan));
    foreach($commands as &$command) if (in_array(trim($command['path'],'/'),['ppp/active','ip/hotspot/active'],true)) $command['_reconnect_required']=false;
    unset($command);
    $service['_commands']=MtData::safePreparedCommands($service,$commands,'update');
    return $service;
};
$previous=$build($service,$basePlan);
$unchanged=ApiJobChanges::summarize($previous,$previous);
jobChangesCheck($unchanged['status']==='prepared' && $unchanged['reference']==='last_applied'
    && $unchanged['changes']===[] && !$unchanged['hasDifferences'] && $unchanged['reconnect']===false,
    'Identical real PPP commands did not produce a comparable-field no-op');

$override=$service; $override['trafficShapingOverrideEnabled']=true;
$override['uploadSpeedOverride']=10; $override['downloadSpeedOverride']=35;
$override['device']=2; $override['_device']['id']=2; $override['_device']['name']='Second Synthetic Site';
$override['network']['address']='198.51.100.20'; $override['_force_reconnect']=true;
$next=$build($override,$basePlan,'198.51.100.20');
$before=serialize([$previous,$next]); $summary=ApiJobChanges::summarize($next,$previous); $rows=jobChangesRows($summary);
jobChangesCheck($before===serialize([$previous,$next]),'Explaining a job mutated its private or applied snapshots');
foreach(['site','ipv4','profile','rate','burst','guarantee'] as $field) jobChangesCheck(isset($rows[$field]),'Real override/move omitted '.$field);
jobChangesCheck($rows['ipv4']['before']==='192.0.2.10' && $rows['ipv4']['after']==='198.51.100.20'
    && $rows['rate']['before']==='5M/20M' && $rows['rate']['after']==='10M/35M'
    && $rows['profile']['after']==='Synthetic Plan [Override S#101]' && $summary['reconnect']===true && $summary['hasDifferences'],
    'The explanation lost the exact frozen address, service override profile or reconnect intent');
jobChangesCheck(strpos(json_encode($summary),'PRIVATE')===false && strpos(json_encode($summary),'comment')===false,
    'A private account, router, token or raw comment escaped the safe explanation');
$staleMac=$next; $staleMac['mac']='02:00:00:00:01:99';
jobChangesCheck(ApiJobChanges::summarize($staleMac,$previous)===$summary,
    'A stale optional MAC overrode the proven frozen PPP commands or their forced reconnect');

$priority=$previous;
foreach($priority['_commands'] as &$command) if(trim($command['path'],'/')==='ppp/profile') {
    $parts=explode(' ',$command['rate-limit']); $parts[4]='6'; $command['rate-limit']=implode(' ',$parts);
} unset($command);
$rows=jobChangesRows(ApiJobChanges::summarize($priority,$previous));
jobChangesCheck(array_keys($rows)===['priority'] && $rows['priority']['before']==='4' && $rows['priority']['after']==='6',
    'A priority-only change was called unchanged or included unrelated fields');
$guarantee=$previous;
foreach($guarantee['_commands'] as &$command) if(trim($command['path'],'/')==='ppp/profile') {
    $parts=explode(' ',$command['rate-limit']); $parts[5]='1000000/2000000'; $command['rate-limit']=implode(' ',$parts);
} unset($command);
$rows=jobChangesRows(ApiJobChanges::summarize($guarantee,$previous));
jobChangesCheck(array_keys($rows)===['guarantee'],'A guaranteed-rate-only change was called unchanged');

$parents=[$previous,$next];
foreach($parents as $index=>&$snapshot) {
    foreach($snapshot['_commands'] as &$command) if(trim($command['path'],'/')==='ppp/profile') $command['parent-queue']='uisp-plan-9-parent';
    unset($command);
    $snapshot['_commands'][]=['path'=>'/queue/simple','name'=>'uisp-plan-9-parent','_owner'=>'uisp-ros-plugin:plan-parent:9',
        'target'=>'192.0.2.10','max-limit'=>$index===0?'5M/20M':'10M/35M'];
} unset($snapshot);
$rows=jobChangesRows(ApiJobChanges::summarize($parents[1],$parents[0]));
jobChangesCheck($rows['contention']['before']==='uisp-plan-9-parent / 5M/20M'
    && $rows['contention']['after']==='uisp-plan-9-parent / 10M/35M','The frozen contention parent/budget was not compared');

$queued=$service; unset($queued['_commands']); $queued['address']=null;
$queued['network']['address']='198.51.100.99';
$summary=ApiJobChanges::summarize($queued,$previous); $rows=jobChangesRows($summary);
jobChangesCheck($summary['status']==='queued' && $summary['reconnect']===null
    && !$rows['ipv4']['afterKnown'] && $rows['ipv4']['after']===null && !$rows['profile']['afterKnown'],
    'An automatic unresolved assignment was presented as an IP removal or copied a stale nested reservation');
$queued['address']='198.51.100.55';
$rows=jobChangesRows(ApiJobChanges::summarize($queued,$previous));
jobChangesCheck($rows['ipv4']['afterKnown'] && $rows['ipv4']['after']==='198.51.100.55','A known manual queued address became unknown');
$queued['trafficShapingOverrideEnabled']=true; $queued['uploadSpeedOverride']=null; $queued['downloadSpeedOverride']=35;
$rows=jobChangesRows(ApiJobChanges::summarize($queued,$previous));
jobChangesCheck($rows['rate']['after']==='0/35M' && !$rows['burst']['afterKnown'],'A queued unlimited direction was lost or its unresolved inherited burst invented');

$summary=ApiJobChanges::summarize($next,null);
jobChangesCheck($summary['reference']==='none' && !$summary['hasDifferences']
    && !array_filter($summary['changes'],static fn($row)=>$row['beforeKnown']), 'Absent applied history was invented as a previous router state');
$wrong=$previous; $wrong['id']=999;
jobChangesCheck(ApiJobChanges::summarize($next,$wrong)['reference']==='none','A different service supplied the previous-state reference');

$deleted=$previous; $deleted['action']='delete';
$rows=jobChangesRows(ApiJobChanges::summarize($deleted,$previous));
jobChangesCheck($rows['account']['before']==='synthetic-account' && $rows['account']['afterKnown'] && $rows['account']['after']===null
    && $rows['ipv4']['after']===null && $rows['state']['after']==='ended', 'Deletion did not distinguish removal from unknown intent');
$migrated=$next; $migrated['username']='new-current-account';
$historical=ApiJobChanges::summarize($deleted,$migrated); $rows=jobChangesRows($historical);
jobChangesCheck($historical['reference']==='job_target' && $rows['account']['before']==='synthetic-account'
    && strpos(json_encode($historical),'new-current-account')===false && strpos($rows['site']['before']??'','#2')===false,
    'Historical cleanup was described as deleting the new current account or router');
foreach(['queues_set','queues_remove'] as $operation) {
    $queueOnly=$next; $queueOnly['action']=$operation;
    $summary=ApiJobChanges::summarize($queueOnly,$previous); $rows=jobChangesRows($summary);
    foreach(['account','site','ipv4','ipv6','profile','state','ownership'] as $field) jobChangesCheck(!isset($rows[$field]),'Queue-only maintenance claimed an account change: '.$field);
    jobChangesCheck($summary['reconnect']===false,'Queue-only maintenance claimed a subscriber reconnect');
}

$suspended=$service; $suspended['status']=3;
$suspended=$build($suspended,$basePlan);
$summary=ApiJobChanges::summarize($suspended,$previous); $rows=jobChangesRows($summary);
jobChangesCheck($rows['state']['after']==='suspended' && $rows['profile']['after']==='fixture-disabled'
    && $rows['rate']['after']==='1M/1M', 'Suspension lost its real profile and per-service cap');
$reordered=$suspended; $reordered['_commands']=array_reverse($reordered['_commands']);
jobChangesCheck(ApiJobChanges::summarize($reordered,$previous)===$summary,'Reordering independent commands changed the suspension explanation');

$hotspot=$service; $hotspot['hotspot']=true; $hotspot=$build($hotspot,$basePlan);
$summary=ApiJobChanges::summarize($hotspot,null); $rows=jobChangesRows($summary);
jobChangesCheck($rows['account']['after']==='synthetic-account' && $rows['ipv4']['after']==='192.0.2.10'
    && $rows['rate']['after']==='5M/20M' && $rows['profile']['after']==='Synthetic Plan [UISP#9]', 'Real Hotspot commands were not recognized');
$dhcp=$service; $dhcp['mac']='02:00:00:00:01:01'; $dhcp=$build($dhcp,$basePlan);
$summary=ApiJobChanges::summarize($dhcp,null); $rows=jobChangesRows($summary);
jobChangesCheck($rows['account']['after']==='02:00:00:00:01:01' && $rows['profile']['afterKnown'] && $rows['profile']['after']===null
    && $rows['rate']['after']==='5M/20M' && $rows['priority']['after']==='4/5' && $summary['reconnect']===false,
    'Real DHCP shaping confused a MAC account with its optional PPP username');
$dhcp['_commands'][]=['path'=>'/ipv6/dhcp-server/binding','address'=>'2001:db8:1::/64','_service_id'=>101];
$dhcp['_commands'][]=['path'=>'/ipv6/dhcp-server/binding','address'=>'2001:db8:2::/64','_service_id'=>999];
$rows=jobChangesRows(ApiJobChanges::summarize($dhcp,null));
jobChangesCheck($rows['ipv6']['after']==='2001:db8:1::/64','A foreign cleanup binding replaced this service IPv6 intent');
$dhcp['_commands'][]=['path'=>'/ipv6/dhcp-server/binding','address'=>'2001:db8:3::/64','_service_id'=>101];
$rows=jobChangesRows(ApiJobChanges::summarize($dhcp,null));
jobChangesCheck(!$rows['ipv6']['afterKnown'],'Ambiguous bindings were presented as one certain IPv6 assignment');

$bounded=$next; $bounded['username']=str_repeat('á',300);
$bounded['_device']['name']=str_repeat('路',300); $bounded['_commands']=array_fill(0,257,['path'=>'/ppp/secret','password'=>'PRIVATE-HUGE']);
$summary=ApiJobChanges::summarize($bounded,$previous);
jobChangesCheck(count($summary['changes'])<=12 && $summary['status']==='queued','An oversized command journal was treated as fully prepared');
foreach($summary['changes'] as $row) foreach(['before','after'] as $side)
    jobChangesCheck($row[$side]===null || (strlen($row[$side])<=256 && preg_match('//u',$row[$side])===1),'A public explanation value exceeded its UTF-8 bound');

// Exercise the actual listing route before redaction, preserving its raw journal byte-for-byte.
$directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'uisp-job-changes-'.bin2hex(random_bytes(8)); mkdir($directory,0700); $queue=null;
try {
    $path=$directory.'/jobs.db'; $queue=new ApiJobQueue($path,null);
    $first=$queue->enqueue($previous); $queue->runSelected([$first],static fn()=>true);
    $key=$queue->enqueue($next); $queuedKey=$queue->enqueue(array_replace($queued,['id'=>102,'clientId'=>202]));
    $beforeHash=hash_file('sha256',$path); $beforeHistory=$queue->appliedSnapshot(101);
    $listed=$queue->list(); $again=$queue->list();
    jobChangesCheck($listed===$again && hash_file('sha256',$path)===$beforeHash && $queue->appliedSnapshot(101)===$beforeHistory,
        'Read-only pending explanations altered jobs or their applied history');
    jobChangesCheck($listed[$key]['plannedChanges']===ApiJobChanges::summarize($next,$previous)
        && $listed[$queuedKey]['plannedChanges']['reference']==='none','Actual listing did not attach the correct per-service applied reference');
    $public=json_encode($listed,JSON_THROW_ON_ERROR);
    jobChangesCheck(strpos($public,'PRIVATE')===false && !isset($listed[$key]['_commands']) && !isset($listed[$key]['_device']),
        'Actual listing exposed private snapshot fields alongside explanations');
    jobChangesCheck(strpos(json_encode($queue->appliedSnapshot(101)),'PRIVATE-ACCOUNT-SECRET')!==false,
        'Public redaction scrubbed the private journal needed by execution');
    echo "Job changes checks passed: real PPP/Hotspot/DHCP commands, overrides and unlimited rates, bursts, guarantees, priorities, contention, suspension, unresolved allocation, queue-only operations, deletion, no-op, bounded private DTOs and read-only actual queue listing.\n";
} finally {
    if ($queue) $queue->close();
    $resolved=realpath($directory); $root=realpath(sys_get_temp_dir());
    if ($resolved && $root && strpos($resolved,$root.DIRECTORY_SEPARATOR.'uisp-job-changes-')===0) {
        $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file) { if($file->isDir()&&!$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
        rmdir($resolved);
    }
    restore_error_handler();
}
