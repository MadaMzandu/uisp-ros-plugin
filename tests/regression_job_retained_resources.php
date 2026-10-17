<?php
// Real durable queue lifecycle; no UISP or RouterOS connections are created.
require_once __DIR__ . '/../src/lib/api_job_queue.php';
require_once __DIR__ . '/../src/lib/api_jobs_status.php';
set_error_handler(static function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line); return false;
});
function retainedResourceCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function retainedResourceAddresses(ApiJobQueue $queue, bool $ipv6 = false): array {
    $values = array_column($queue->reservedAddresses($ipv6), $ipv6 ? 'address6' : 'address');
    sort($values, SORT_STRING); return $values;
}
$directory = sys_get_temp_dir() . '/uisp-retained-resources-' . bin2hex(random_bytes(8)); mkdir($directory, 0700);
$path = $directory . '/jobs.db'; $queue = null; $database = null;
try {
    $queue = new ApiJobQueue($path, null);
    $snapshot = ['id'=>701,'clientId'=>81,'device'=>7,'status'=>1,'username'=>'fixture-retained-account',
        'password'=>'PRIVATE_ACCOUNT_PASSWORD', '_device'=>['id'=>7,'ip'=>'192.0.2.7','port'=>8728,
            'user'=>'fixture-api','password'=>'PRIVATE_ROUTER_PASSWORD'],
        'network'=>['address'=>'10.77.0.11','address6'=>'fd77:11::/64']];
    $cleanup = $snapshot; $cleanup['device']=8; $cleanup['_device']['id']=8; $cleanup['_device']['ip']='192.0.2.8';
    $cleanup['network']=['address'=>'10.77.0.12','address6'=>'fd77:12::/64'];
    $cleanup['_commands']=[['path'=>'/ppp/secret','name'=>$snapshot['username'],
        'remote-address'=>'10.77.0.12','remote-ipv6-prefix'=>'fd77:12::/64','password'=>'PRIVATE_ACCOUNT_PASSWORD']];
    $key = $queue->enqueue($snapshot);
    $queue->run([$key], static function (array $payload, string $operation, array $job, ApiJobQueue $store) use ($cleanup): bool {
        $payload['_commands']=[['path'=>'/ppp/secret','name'=>$payload['username'],
            'remote-address'=>'10.77.0.11','remote-ipv6-prefix'=>'fd77:11::/64','password'=>'PRIVATE_ACCOUNT_PASSWORD']];
        $payload['_prior_network']=['address'=>'10.77.0.10','address6'=>'fd77:10::/64'];
        $payload['_cleanup']=$cleanup; $store->freezePrepared($job, $payload); return false;
    });
    retainedResourceCheck(($queue->delete([$key])[$key] ?? false) && $queue->hasUnreconciled(701),
        'Fixture did not retain a partially applied cancelled job');
    $before4=retainedResourceAddresses($queue); $before6=retainedResourceAddresses($queue, true);
    $database = new SQLite3($path); $database->enableExceptions(true);
    $sourceId=(int)substr($key,1); $source=$database->querySingle('SELECT * FROM jobs WHERE id=' . $sourceId, true);
    $debts=[]; $result=$database->query('SELECT * FROM cancellation_cleanups WHERE source_job_id=' . $sourceId . ' ORDER BY id');
    while ($row=$result->fetchArray(SQLITE3_ASSOC)) $debts[]=$row; $result->finalize();
    // Model the API's already reviewed, backed-up transaction. Its own suite
    // verifies authorization and races; this suite verifies subsequent workers.
    $retain = static function (array $row, string $slot) use ($database, $source): void {
        $statement=$database->prepare('INSERT INTO retained_resources(source_job_id,service_id,source_version,slot,payload,identity,retained_at) VALUES(?,?,?,?,?,?,?)');
        $values=[$source['id'],$source['service_id'],$source['version'],$slot,$row['payload'],$row['identity'],'2026-10-14T00:00:00Z'];
        foreach ($values as $index=>$value) $statement->bindValue($index+1,$value,is_int($value)?SQLITE3_INTEGER:SQLITE3_TEXT);
        $statement->execute()->finalize(); $statement->close();
    };
    $database->exec('BEGIN IMMEDIATE'); foreach ($debts as $debt) $retain($debt,$debt['slot']); $retain($source,'source');
    $database->exec('DELETE FROM cancellation_cleanups WHERE source_job_id=' . $sourceId);
    $database->exec("UPDATE jobs SET status='cleaned',updated='2020-01-01T00:00:00Z' WHERE id=" . $sourceId); $database->exec('COMMIT');
    $retainedBefore=$database->querySingle('SELECT group_concat(payload) FROM retained_resources WHERE source_job_id=' . $sourceId);
    retainedResourceCheck(!$queue->hasUnreconciled(701) && $queue->list()===[], 'Conserved history remained executable cancellation debt');
    retainedResourceCheck(retainedResourceAddresses($queue)===$before4 && retainedResourceAddresses($queue,true)===$before6,
        'Explicit conservation released a historical IPv4 or IPv6 reservation');
    retainedResourceCheck($queue->hasDeviceReferences([7]) && $queue->hasDeviceReferences([8]) && !$queue->hasDeviceReferences([9]),
        'Retired resources no longer protected their router references');
    $members=$queue->resourceSnapshots(701);
    retainedResourceCheck(count($members)>=2 && strpos(json_encode($members),'PRIVATE_')===false,
        'Shared-resource membership lost retained snapshots or exposed their credentials');
    retainedResourceCheck($queue->resourceSnapshots(999)===[], 'Retained resources leaked into another service membership');

    // Unfrozen cancelled intent is not current occupancy. Its prior/cleanup
    // evidence still is, even after the original source history expires.
    $unprepared=['id'=>702,'clientId'=>82,'device'=>9,'username'=>'fixture-unprepared',
        '_device'=>['id'=>9,'ip'=>'192.0.2.9'],'network'=>['address'=>'10.77.0.77'],
        '_prior_network'=>['address'=>'10.77.0.76','address6'=>'malformed-fixture'],
        '_cleanup'=>['id'=>702,'clientId'=>82,'device'=>10,'_device'=>['id'=>10,'ip'=>'192.0.2.10'],
            'network'=>['address'=>'10.77.0.75']]];
    $oldApplied=$unprepared; unset($oldApplied['_prior_network'],$oldApplied['_cleanup']);
    $oldApplied['network']=['address'=>'10.77.0.76'];
    $identityMethod=new ReflectionMethod(ApiJobQueue::class,'identity');
    if (PHP_VERSION_ID < 80100) $identityMethod->setAccessible(true);
    foreach (['source'=>$unprepared,'applied'=>$oldApplied] as $slot=>$held) {
        $statement=$database->prepare("INSERT INTO retained_resources(source_job_id,service_id,source_version,slot,payload,identity,retained_at) VALUES(702,702,1,?,?,?,'2026-10-14T00:00:00Z')");
        $statement->bindValue(1,$slot); $statement->bindValue(2,json_encode($held));
        $statement->bindValue(3,$identityMethod->invoke($queue,$held)); $statement->execute()->finalize(); $statement->close();
    }
    $values=retainedResourceAddresses($queue);
    retainedResourceCheck(in_array('10.77.0.76',$values,true) && in_array('10.77.0.75',$values,true)
        && !in_array('10.77.0.77',$values,true), 'Cancelled unfrozen desired IP became a resource or lost its historical reservations');
    retainedResourceCheck(!in_array('malformed-fixture',retainedResourceAddresses($queue,true),true), 'Malformed retained value blocked the allocator');
    retainedResourceCheck($queue->hasDeviceReferences([9]) && $queue->hasDeviceReferences([10]), 'Nested preserved endpoint was released');

    $queue->close(); $queue=new ApiJobQueue($path,null);
    retainedResourceCheck((int)$database->querySingle('SELECT count(*) FROM jobs WHERE id=' . $sourceId)===0,
        'Fixture did not compact expired original source history');
    retainedResourceCheck(!$queue->hasUnreconciled(701) && (int)$database->querySingle('SELECT count(*) FROM cancellation_cleanups')===0,
        'A worker restart seeded executable cleanup from reviewed ownership');
    retainedResourceCheck(in_array('10.77.0.10',retainedResourceAddresses($queue),true) && $queue->hasDeviceReferences([8]),
        'Compacting source history released independently retained resources');
    $update=$queue->enqueue(array_replace($snapshot,['status'=>3])); $calls=[];
    $queue->run([$update],static function(array $payload,string $operation,array $job,ApiJobQueue $store) use (&$calls): bool {
        retainedResourceCheck($store->cancellationCleanups($job)===[], 'A normal update would remove conserved resources or disconnect the account');
        retainedResourceCheck(empty($payload['_force_reconnect']), 'Reviewing old cancellation forced a reconnect on a later update');
        $calls[]=$payload['id']; return true;
    });
    $other=$queue->enqueue(['id'=>703,'clientId'=>83,'device'=>11,'username'=>'fixture-unrelated','status'=>1]);
    $queue->run([$other],static function(array $payload) use (&$calls): bool { $calls[]=$payload['id']; return true; });
    retainedResourceCheck($queue->completed([$update,$other]) && $calls===[701,703], 'Conserved history blocked current or unrelated service work');
    retainedResourceCheck($database->querySingle('SELECT group_concat(payload) FROM retained_resources WHERE source_job_id=' . $sourceId)===$retainedBefore,
        'A normal job changed the protected original payloads');
    retainedResourceCheck((new ApiJobsStatus($path,$directory . '/queue.json'))->read()['total']===0,
        'Conserved ownership still appeared as pending jobs or pending recovery');
    echo "OK: reviewed ownership persists through compaction and restart, retains addresses and router/shared-resource references, and never becomes automatic cancellation cleanup\n";
} finally {
    if ($queue instanceof ApiJobQueue) $queue->close(); if ($database instanceof SQLite3) $database->close();
    foreach (glob($directory . '/.uisp-queue-locks-*',GLOB_ONLYDIR) ?: [] as $locks) {
        foreach (glob($locks . '/*.lock') ?: [] as $file) unlink($file); rmdir($locks);
    }
    foreach (glob($directory . '/*') ?: [] as $file) if (is_file($file)) unlink($file); rmdir($directory);
}
