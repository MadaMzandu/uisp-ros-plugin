<?php
// Queue diagnostics use the real logger with debug disabled; executors never access UISP or RouterOS.
set_error_handler(function ($severity,$message,$file,$line) {
    if (error_reporting() & $severity) throw new ErrorException($message,0,$severity,$file,$line);
    return false;
});
function diagnosticsCheck(bool $condition,string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function diagnosticsEntries(): array {
    if (!is_file('data/plugin.log')) return [];
    $result=[];
    foreach (explode("\n",file_get_contents('data/plugin.log')) as $line) {
        $start=strpos($line,'{'); if ($start===false) continue;
        $entry=json_decode(substr($line,$start),true);
        if (is_array($entry) && ($entry['event']??null)==='job_attempt_failed') $result[]=$entry;
    }
    return $result;
}
$source=realpath(__DIR__ . '/../src');
$original=getcwd();
$originalIncludePath=get_include_path();
$directory=sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-job-diagnostics-' . bin2hex(random_bytes(8));
mkdir($directory,0700); mkdir($directory . '/data',0700);
chdir($directory);
set_include_path($source . '/lib' . PATH_SEPARATOR . $originalIncludePath);
require $source . '/lib/api_common.php';
require $source . '/lib/api_job_queue.php';
try {
    file_put_contents('data/config.json',json_encode(['debugEnable'=>false]));
    MyLog()->Append('ORDINARY-DEBUG-MUST-STAY-HIDDEN');
    $queue=new ApiJobQueue('data/jobs.db',null);
    $snapshot=static fn(int $id): array => [
        'id'=>$id,'device'=>27,'clientId'=>72,'planId'=>2,'status'=>3,
        'username'=>'PRIVATE-SERVICE-USER','password'=>'PRIVATE-SERVICE-PASSWORD',
        '_event_id'=>'PRIVATE-WEBHOOK-UUID-' . $id,
        '_device'=>['id'=>27,'ip'=>'192.0.2.44','user'=>'PRIVATE-ROUTER-USER','password'=>'PRIVATE-ROUTER-PASSWORD'],
        '_config'=>['webhookSecret'=>'PRIVATE-TOKEN-VALUE'],
        '_commands'=>[['path'=>'/ppp/secret','name'=>'PRIVATE-COMMAND-USER','password'=>'PRIVATE-COMMAND-PASSWORD']],
    ];
    $cases=[
        ['The configured session profile could not be verified','router_session_profile_unverified'],
        ['router_session_profile_missing: ppp/profile PRIVATE-PROFILE-VALUE','router_session_profile_missing'],
        ['router_session_profile_lookup_failed: PRIVATE-LOOKUP-VALUE','router_session_profile_lookup_failed'],
        ['router_session_profile_ambiguous: PRIVATE-PROFILE-VALUE','router_session_profile_ambiguous'],
        ['router_session_profile_invalid_identity: PRIVATE-IDENTITY-VALUE','router_session_profile_invalid_identity'],
        ['router_session_profile_bootstrap_failed: PRIVATE-BOOTSTRAP-VALUE','router_session_profile_bootstrap_failed'],
        ['router_session_profile_bootstrap_invalid_configuration: PRIVATE-CONFIG-VALUE','router_session_profile_bootstrap_invalid_configuration'],
        ['device connect failed','router_connection_failed'],
        ['RouterOS connection failed','router_connection_failed'],
        ['invalid_provisioning_number: service=1 field=PRIVATE-FIELD-VALUE','invalid_provisioning_number'],
        ['invalid_network_address: requested_service=421 blocking_service=799 source=reserved family=IPv4','invalid_network_address'],
        ['invalid_network_address: requested_service=422 blocking_service=422 source=input family=IPv6','invalid_network_address'],
        ['invalid_network_address: requested_service=423 blocking_service=798 source=applied family=IPv4','invalid_network_address'],
        ['invalid_network_address: requested_service=424 blocking_service=PRIVATE-OWNER source=reserved family=IPv4','provisioning_failed'],
        ['network_address_conflict: requested_service=425 blocking_service=799 source=reserved family=IPv4','network_address_conflict'],
        ['network_address_conflict: requested_service=426 blocking_service=798 source=cache family=IPv6','network_address_conflict'],
        ['network_address_conflict: requested_service=427 blocking_service=797 source=applied family=IPv4','network_address_conflict'],
        ['network_address_conflict: requested_service=428 blocking_service=PRIVATE-OWNER source=reserved family=IPv4','provisioning_failed'],
        ['ARBITRARY-EXCEPTION-VALUE username=PRIVATE-SERVICE-USER password=PRIVATE-SERVICE-PASSWORD https://fixture.invalid/?token=PRIVATE-TOKEN-VALUE','provisioning_failed'],
    ];
    $firstKey=null;
    foreach ($cases as $index=>[$message,$code]) {
        $key=$queue->enqueue($snapshot(410+$index)); $firstKey??=$key;
        $before=count(diagnosticsEntries());
        $results=$queue->run([$key],static function () use ($message): bool { throw new RuntimeException($message); });
        $pending=$queue->list()[$key];
        $events=diagnosticsEntries(); $entry=$events[count($events)-1];
        diagnosticsCheck(count($events)===$before+1 && $entry['jobId']===$key
            && $entry['serviceId']===410+$index && $entry['deviceId']===27 && $entry['operation']==='update'
            && $entry['attempts']===1 && $entry['nextRetry']===$pending['nextRetry'] && $entry['errorCode']===$code,
            'Persisted failure did not emit exactly one useful, correctly classified diagnostic');
        diagnosticsCheck($results[$key]['success']===false && $pending['state']==='pending' && $pending['error']===$message
            && $queue->appliedSnapshot(410+$index)===[], 'Failure logging altered the stored failure or applied state');
        $allowed=['event','jobId','serviceId','deviceId','operation','attempts','nextRetry','errorCode'];
        if (in_array($code,['invalid_network_address','network_address_conflict'],true)) {
            preg_match('/requested_service=([0-9]+) blocking_service=([0-9]+) source=(input|reserved|cache|applied) family=(IPv4|IPv6)$/D',$message,$context);
            diagnosticsCheck($entry['requestedServiceId']===(int)$context[1] && $entry['blockingServiceId']===(int)$context[2]
                && $entry['addressSource']===$context[3] && $entry['addressFamily']===$context[4],
                'Reservation failure lost its blocking service, source or family');
            $allowed=array_merge($allowed,['requestedServiceId','blockingServiceId','addressSource','addressFamily']);
        }
        diagnosticsCheck(array_diff(array_keys($entry),$allowed)===[], 'A diagnostic included fields outside its safe allowlist');
    }
    // Listing, opening another queue and observing a future retry never constitute an attempt.
    $before=count(diagnosticsEntries());
    $queue->list(); $second=new ApiJobQueue('data/jobs.db',null); $second->list(); $second->close();
    $called=false;
    diagnosticsCheck($queue->run(null,static function () use (&$called): bool { $called=true; return false; })===[] && !$called
        && count(diagnosticsEntries())===$before, 'Future retry or read-only observation repeated a failure event');
    // An explicit retry is an actual second attempt even before its automatic retry date.
    $queue->run([$firstKey],static fn(): bool=>false);
    $events=diagnosticsEntries(); $entry=$events[count($events)-1];
    diagnosticsCheck(count($events)===$before+1 && $entry['jobId']===$firstKey && $entry['attempts']===2
        && $entry['errorCode']==='operation_incomplete', 'Retry did not log once with its persisted attempt count');
    $before=count($events);
    $queue->run([$firstKey],static fn(): bool=>true);
    diagnosticsCheck($queue->completed([$firstKey]) && count(diagnosticsEntries())===$before,
        'Successful recovery emitted a failure record');
    diagnosticsCheck($queue->run([$firstKey],static fn(): bool=>false)===[] && count(diagnosticsEntries())===$before,
        'Completed work emitted a new failure on replay');
    // A defective logging sink cannot undo the committed queue transition or hide its result.
    $sinkKey=$queue->enqueue($snapshot(430));
    $apiLogger=new class extends ApiLogger {
        public function Append($entry,$level=self::DEBUG) { throw new RuntimeException('Fixture logger unavailable'); }
    };
    $sinkResults=$queue->run([$sinkKey],static function (): bool { throw new RuntimeException('device connect failed'); });
    $apiLogger=null;
    diagnosticsCheck(!$sinkResults[$sinkKey]['success'] && $queue->list()[$sinkKey]['state']==='pending'
        && $queue->list()[$sinkKey]['attempts']===1 && count(diagnosticsEntries())===$before,
        'Logging failure rolled back or retried a completed attempt');
    // Only an ownership-confirmed persisted failure may emit a diagnostic.
    $lostKey=$queue->enqueue($snapshot(431));
    $property=new ReflectionProperty(ApiJobQueue::class,'db');
    if (PHP_VERSION_ID<80100) $property->setAccessible(true);
    $database=$property->getValue($queue);
    $queue->run([$lostKey],static function ($snapshot,$operation,$job) use ($database): bool {
        $database->exec("UPDATE jobs SET owner='fixture-other-owner' WHERE id=" . (int)$job['id']);
        return false;
    });
    diagnosticsCheck(count(diagnosticsEntries())===$before, 'An unconfirmed finish emitted a persisted-failure diagnostic');
    // Unknown devices are represented as NULL, never the supplied string.
    $invalid=$snapshot(432); $invalid['device']='PRIVATE-INVALID-DEVICE';
    $invalidKey=$queue->enqueue($invalid);
    $queue->run([$invalidKey],static fn(): bool=>false);
    $events=diagnosticsEntries(); $entry=$events[count($events)-1];
    diagnosticsCheck($entry['deviceId']===null && $entry['operation']==='update', 'Invalid device input leaked into the diagnostic');
    $ownershipKey=$queue->enqueue($snapshot(450));
    $queue->run([$ownershipKey],static function (): bool {
        throw new RuntimeException('router_ownership_conflict: current_service=450 previous_service=449 reason=previous_active');
    });
    $events=diagnosticsEntries(); $entry=$events[count($events)-1];
    diagnosticsCheck($entry['errorCode']==='router_ownership_conflict' && $entry['requestedServiceId']===450
        && $entry['previousServiceId']===449 && $entry['ownershipReason']==='previous_active',
        'Account ownership diagnostic lost its safe current/previous service IDs and reason');
    $logs=file_get_contents('data/plugin.log');
    foreach (['PRIVATE-','192.0.2.44','fixture.invalid','ARBITRARY-EXCEPTION-VALUE','ORDINARY-DEBUG-MUST-STAY-HIDDEN',
        '/ppp/secret','username=','password='] as $private) diagnosticsCheck(strpos($logs,$private)===false,
        'Job diagnostic disclosed credentials, payload or arbitrary exception details');
    echo "Job diagnostics passed: safe failure codes and IDs with debug off, once per persisted attempt, exact retry metadata, no read/success/future/replay duplicates and logging failure isolation.\n";
} finally {
    if (isset($second)) $second->close();
    if (isset($queue)) $queue->close();
    chdir($original); set_include_path($originalIncludePath);
    $resolved=realpath($directory); $temporaryRoot=realpath(sys_get_temp_dir());
    if ($resolved && $temporaryRoot && strpos($resolved,$temporaryRoot . DIRECTORY_SEPARATOR . 'uisp-job-diagnostics-')===0) {
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($resolved);
    }
}
