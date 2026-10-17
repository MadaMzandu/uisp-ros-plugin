<?php
// Manual queue actions use the real durable queue and local executors; no UISP or router connections.
require_once __DIR__ . '/../src/lib/api_job_queue.php';
set_error_handler(static function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
class JobActionLog {
    public array $entries = [];
    public function Append($entry, $level = 0): void { $this->entries[] = $entry; }
}
function MyLog() { static $log; return $log ??= new JobActionLog(); }
function actionCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function actionReject(callable $operation, string $message): void {
    try { $operation(); } catch (RuntimeException $expected) { return; }
    throw new RuntimeException($message);
}
function actionSnapshot(int $id, array $extra = []): array {
    return array_replace(['id' => $id, 'device' => 1, 'status' => 3, 'username' => 'PRIVATE-USER-' . $id,
        'password' => 'PRIVATE-PASSWORD', '_device' => ['id' => 1, 'ip' => '192.0.2.1', 'password' => 'PRIVATE-ROUTER-PASSWORD']], $extra);
}
function actionPrepared(int $id, array $extra = []): array {
    return actionSnapshot($id, ['_commands' => [['path' => '/ppp/secret', 'profile' => 'disabled']]] + $extra);
}
function actionDatabaseState(SQLite3 $db): array {
    $state = [];
    foreach (['jobs', 'service_versions', 'service_event_locks', 'service_tombstones', 'queue_meta', 'cancellation_cleanups'] as $table) {
        $result = $db->query('SELECT * FROM ' . $table . ' ORDER BY rowid');
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) $state[$table][] = $row;
    }
    return $state;
}
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-job-actions-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
try {
    $queue = new ApiJobQueue($directory . '/jobs.db', null);
    $second = new ApiJobQueue($directory . '/jobs.db', null);
    $property = new ReflectionProperty(ApiJobQueue::class, 'db');
    if (PHP_VERSION_ID < 80100) $property->setAccessible(true);
    $database = $property->getValue($queue);

    // Reproduce a newer manual snapshot blocked by an older prepared failed attempt.
    $initial = actionSnapshot(662, ['_force_reconnect' => true, '_prior_network' => ['address' => null, 'address6' => null]]);
    $old = $queue->enqueue($initial);
    $queue->run([$old], static function ($snapshot, $operation, $job, ApiJobQueue $store): bool {
        $snapshot['_commands'] = [['path' => '/ppp/secret', 'profile' => 'disabled']];
        $store->freezePrepared($job, $snapshot);
        throw new RuntimeException('The configured session profile could not be verified');
    });
    $replacement = $initial; $replacement['_prior_network']['address'] = '192.0.2.66';
    $current = $queue->enqueue($replacement);
    $later = $queue->enqueue(actionSnapshot(662, ['status' => 1]), 'queues_set');
    $foreign = $queue->enqueue(actionSnapshot(831));
    MyLog()->entries = [];
    $before = actionDatabaseState($database);
    $status = $queue->selectionStatus([$old, $current, 'Q999999']);
    $listed = $queue->list();
    actionCheck(actionDatabaseState($database) === $before && MyLog()->entries === [], 'Observation changed jobs or generated an attempt');
    actionCheck($status[$old]['canDelete'] === true && $status[$old]['deleteBlockedReason'] === null
        && $status[$old]['attempts'] === 1 && $status[$old]['deviceId'] === 1
        && $status[$current]['blockedBy'] === $old && $status[$current]['blockedReason'] === 'earlier_job'
        && $status[$current]['canDelete'] === true && $status[$current]['attempts'] === 0,
        'Pending cancellation eligibility or the oldest predecessor was not described');
    actionCheck($status['Q999999']['state'] === 'missing' && $status['Q999999']['deleteBlockedReason'] === 'missing'
        && $status['Q999999']['serviceId'] === null && $status['Q999999']['deviceId'] === null, 'Unknown job status was guessed');
    actionCheck($listed[$current]['blockedBy'] === $old && $listed[$old]['deleteBlockedReason'] === null, 'Job listing omitted action diagnostics');
    actionCheck(strpos(json_encode($status), 'PRIVATE-') === false && strpos(json_encode($status), '192.0.2.') === false,
        'Action diagnostics exposed account or router payload fields');
    $calls = [];
    actionCheck($queue->run([$current], static function () use (&$calls): bool { $calls[] = 'unexpected'; return true; }) === []
        && $calls === [], 'Existing automatic/Batch execution unexpectedly expanded selected jobs');
    $failed = $queue->runSelected([$current], static function ($snapshot, $operation, $job) use (&$calls): bool {
        $calls[] = 'Q' . $job['id'];
        throw new RuntimeException('router_session_profile_missing: profilePath=/ppp/profile profileName="disabled"');
    });
    actionCheck($calls === [$old] && $failed[$old]['attempted'] === true && $failed[$old]['success'] === false
        && $failed[$old]['attempts'] === 2 && $failed[$current]['attempted'] === false && $failed[$current]['error'] === null
        && $failed[$current]['blockedBy'] === $old && $failed[$current]['blockedReason'] === 'earlier_job',
        'A failed prerequisite was skipped or an unattempted successor received a fabricated error');
    actionCheck(array_keys($failed) === [$old, $current] && count(MyLog()->entries) === 1
        && MyLog()->entries[0]['jobId'] === $old, 'Manual retry executed unrelated/newer work or logged a nonexistent attempt');
    $before = actionDatabaseState($database);
    actionCheck($queue->selectionStatus([$old])[$old]['canDelete'] && actionDatabaseState($database) === $before,
        'Reading prepared cancellation eligibility changed its durable payload or barriers');
    $calls = [];
    $recovered = $queue->runSelected([$old, $current, $current], static function ($snapshot, $operation, $job) use (&$calls): bool {
        $calls[] = 'Q' . $job['id']; return true;
    });
    actionCheck($calls === [$old, $current] && $recovered[$old]['success'] && $recovered[$current]['success']
        && $queue->completed([$old, $current]) && $queue->list()[$later]['attempts'] === 0
        && $queue->list()[$foreign]['attempts'] === 0, 'Recovery lost ordering, repeated an attempt, or crossed its selected scope');
    $replayed = $queue->runSelected([$current], static function (): bool { throw new RuntimeException('Completed job replayed'); });
    actionCheck($replayed[$current]['success'] && !$replayed[$current]['attempted']
        && $replayed[$current]['deleteBlockedReason'] === 'not_pending', 'Completed selection did not remain idempotent');

    // An older selected version cannot authorize a newer update for the same service.
    $first = $queue->enqueue(actionPrepared(900));
    $newer = $queue->enqueue(actionPrepared(900, ['status' => 1]));
    $calls = [];
    $onlyOld = $queue->runSelected([$first], static function ($snapshot, $operation, $job) use (&$calls): bool {
        $calls[] = 'Q' . $job['id']; return true;
    });
    actionCheck($calls === [$first] && array_keys($onlyOld) === [$first] && $queue->list()[$newer]['attempts'] === 0,
        'Selecting an older version ran its newer intent');

    // A migration's old-router removal remains mandatory before the replacement account.
    [$remove, $replace] = $queue->enqueueBatch([
        ['snapshot' => actionPrepared(910, ['device' => 1]), 'operation' => 'delete'],
        ['snapshot' => actionSnapshot(910, ['device' => 2]), 'operation' => 'update'],
    ]);
    $calls = [];
    $queue->runSelected([$replace], static function ($snapshot, $operation) use (&$calls): bool {
        $calls[] = [$operation, $snapshot['device']]; return true;
    });
    actionCheck($calls === [['delete', 1], ['update', 2]] && $queue->completed([$remove, $replace]),
        'Manual migration retry skipped cleanup or changed its frozen endpoint');
    $cleanup = $queue->enqueue(actionSnapshot(911, ['_cleanup' => actionPrepared(911, ['device' => 2])]));
    actionCheck($queue->selectionStatus([$cleanup])[$cleanup]['canDelete']
        && $queue->delete([$cleanup])[$cleanup] && $queue->hasUnreconciled(911)
        && $queue->hasDeviceReferences([2]), 'Cancelling a prepared migration lost its cleanup debt');

    // A live worker is never overtaken, even when both its job and a successor are in the expanded selection.
    $running = $queue->enqueue(actionPrepared(920));
    $waiting = $queue->enqueue(actionSnapshot(920, ['status' => 1]));
    $queue->run([$running], static function () use ($second, $running, $waiting): bool {
        $nested = $second->runSelected([$waiting], static function (): bool { throw new RuntimeException('Concurrent worker wrote'); });
        actionCheck(!$nested[$running]['attempted'] && $nested[$running]['state'] === 'running'
            && $nested[$running]['deleteBlockedReason'] === 'running' && $nested[$waiting]['blockedBy'] === $running
            && $nested[$waiting]['blockedReason'] === 'earlier_job' && !$nested[$waiting]['attempted'], 'A live prerequisite was misreported or bypassed');
        actionCheck($second->delete([$running])[$running] === false, 'A running operation was deleted');
        return true;
    });
    actionCheck($queue->list()[$waiting]['attempts'] === 0, 'An outer worker authorized a successor that was not selected by it');

    // A different request's service gate blocks execution without claiming an attempt.
    $gated = $queue->enqueue(actionSnapshot(930));
    $queue->withServiceLock(930, static function () use ($second, $gated): void {
        $outcome = $second->runSelected([$gated], static function (): bool { throw new RuntimeException('Service gate bypassed'); });
        actionCheck(!$outcome[$gated]['attempted'] && $outcome[$gated]['blockedReason'] === 'service_event'
            && $outcome[$gated]['error'] === null, 'A service event lock was hidden or bypassed');
    });
    $maintenanceKey = $queue->enqueue(actionSnapshot(931));
    $queue->withMaintenance(static function () use ($second, $maintenanceKey): void {
        $outcome = $second->runSelected([$maintenanceKey], static function (): bool { throw new RuntimeException('Maintenance bypassed'); });
        actionCheck(!$outcome[$maintenanceKey]['attempted'] && $outcome[$maintenanceKey]['blockedReason'] === 'maintenance',
            'Maintenance was bypassed or reported as an attempted failure');
    });

    // Retrying a dependent update may finish terminal cleanup, but cannot reactivate it without an authorized fresh event.
    $terminal = $queue->enqueue(actionPrepared(940, ['_terminal' => true]), 'delete');
    $blocked = $queue->enqueue(actionSnapshot(940, ['status' => 1]));
    $calls = [];
    $terminalResult = $queue->runSelected([$blocked], static function ($snapshot, $operation) use (&$calls): bool { $calls[] = $operation; return true; });
    actionCheck($calls === ['delete'] && $terminalResult[$terminal]['success'] && !$terminalResult[$blocked]['attempted']
        && $terminalResult[$blocked]['blockedReason'] === 'terminal' && $terminalResult[$blocked]['blockedBy'] === null
        && $terminalResult[$blocked]['error'] === null && $queue->isServiceBlocked(940), 'A manual retry bypassed terminal protection');

    $deletable = $queue->enqueue(actionSnapshot(950));
    actionCheck($queue->selectionStatus([$deletable])[$deletable]['canDelete'] && $queue->delete([$deletable])[$deletable],
        'An unstarted disposable job could no longer be deleted');
    actionCheck($queue->selectionStatus([$deletable])[$deletable]['deleteBlockedReason'] === 'not_pending'
        && $queue->runSelected(['bad', 0]) === [], 'Inactive/invalid job selection was not handled safely');
    $missingResult = $queue->runSelected(['Q999999'], static function (): bool { throw new RuntimeException('Unknown selection executed'); });
    actionCheck(!$missingResult['Q999999']['attempted'] && !$missingResult['Q999999']['success']
        && $missingResult['Q999999']['state'] === 'missing' && $missingResult['Q999999']['error'] === null,
        'Unknown selection fabricated an execution error');

    // Removing entries from the job list must never enqueue RouterOS account-retirement work.
    $protected = $queue->enqueue(actionPrepared(960));
    $disposable = $queue->enqueue(actionSnapshot(961));
    $untouched = $queue->enqueue(actionSnapshot(962));
    $existingDelete = $queue->enqueue(actionSnapshot(963), 'delete');
    $deleteSuccessor = $queue->enqueue(actionSnapshot(963, ['status' => 1]));
    $beforeRows = actionDatabaseState($database)['jobs'];
    $identities = static fn(array $rows): array => array_map(static fn(array $row): array =>
        ['id' => $row['id'], 'service_id' => $row['service_id'], 'operation' => $row['operation']], $rows);
    $byKey = static function (array $rows): array {
        $result = []; foreach ($rows as $row) $result['Q' . $row['id']] = $row; return $result;
    };
    $beforeByKey = $byKey($beforeRows); MyLog()->entries = [];
    $deleted = $queue->delete([$protected, $disposable]);
    $afterRows = actionDatabaseState($database)['jobs']; $afterByKey = $byKey($afterRows);
    actionCheck($deleted === [$protected => true, $disposable => true]
        && $identities($afterRows) === $identities($beforeRows)
        && $afterByKey[$protected]['status'] === 'cancelled' && $queue->hasUnreconciled(960)
        && $afterByKey[$untouched] === $beforeByKey[$untouched]
        && $afterByKey[$existingDelete] === $beforeByKey[$existingDelete]
        && $afterByKey[$deleteSuccessor] === $beforeByKey[$deleteSuccessor]
        && $afterByKey[$disposable]['status'] === 'cancelled'
        && $afterByKey[$disposable]['attempts'] === 0 && MyLog()->entries === [],
        'Deleting selected queue entries created/converted a retirement job or changed unrelated work');
    // Cancelling an unstarted cleanup also cancels its dependent replacement instead of allowing it to overtake cleanup.
    actionCheck($queue->delete([$existingDelete])[$existingDelete] === true, 'An unstarted cleanup could not be cancelled');
    $finalRows = actionDatabaseState($database)['jobs']; $finalByKey = $byKey($finalRows);
    actionCheck($identities($finalRows) === $identities($beforeRows)
        && $finalByKey[$existingDelete]['status'] === 'cancelled' && $finalByKey[$deleteSuccessor]['status'] === 'cancelled'
        && $finalByKey[$untouched] === $beforeByKey[$untouched] && $finalByKey[$protected] === $afterByKey[$protected],
        'Cleanup cancellation created a new delete or crossed its same-service dependency scope');
    // Native deletes clone the last applied resources before any attempt; cancelling them keeps ownership and reservations.
    $owned = actionPrepared(970, ['device' => 6, '_device' => ['id' => 6, 'ip' => '192.0.2.6'],
        'address' => '192.0.2.170', 'address6' => '2001:db8::170']);
    $owned['_commands'][0] += ['remote-address' => '192.0.2.170', 'password' => 'PRIVATE-COMMAND-PASSWORD'];
    $appliedKey = $queue->enqueue($owned);
    actionCheck($queue->run([$appliedKey], static fn(): bool => true)[$appliedKey]['success'], 'Could not establish the applied fixture');
    $appliedBefore = $queue->appliedSnapshot(970);
    $clonedDelete = $queue->enqueue($appliedBefore, 'delete');
    $rowBefore = $byKey(actionDatabaseState($database)['jobs'])[$clonedDelete];
    $resourcesBefore = $queue->resourceSnapshots(970);
    $addressesBefore = [$queue->reservedAddresses(), $queue->reservedAddresses(true)];
    actionCheck($queue->selectionStatus([$clonedDelete])[$clonedDelete]['canDelete']
        && $queue->list()[$clonedDelete]['deleteBlockedReason'] === null
        && $queue->delete([$clonedDelete]) === [$clonedDelete => true],
        'A native zero-attempt deletion with cloned commands was treated as possibly executed');
    $rowAfter = $byKey(actionDatabaseState($database)['jobs'])[$clonedDelete];
    $retained = json_decode($rowAfter['payload'], true, 512, JSON_THROW_ON_ERROR);
    actionCheck($queue->appliedSnapshot(970) === $appliedBefore && $rowAfter['identity'] === $rowBefore['identity']
        && $rowAfter['status'] === 'cancelled' && $rowAfter['attempts'] === 0
        && count($queue->resourceSnapshots(970)) === count($resourcesBefore) + 2 && $queue->hasUnreconciled(970)
        && [$queue->reservedAddresses(), $queue->reservedAddresses(true)] === $addressesBefore
        && $queue->hasDeviceReferences([6]) && $retained['address'] === '192.0.2.170'
        && $retained['_commands'][0]['remote-address'] === '192.0.2.170'
        && !isset($retained['password']) && !isset($retained['_commands'][0]['password']),
        'Cancelling cloned commands released applied identity/resources or retained obsolete secrets');

    // Cancelling terminal cleanup preserves its tombstone, including when its prepared successor was selected first.
    $cancelTerminal = $queue->enqueue(actionPrepared(971, ['_event_id' => 'terminate-971', '_terminal' => true]), 'delete');
    $cancelTerminalSuccessor = $queue->enqueue(actionPrepared(971, ['status' => 1]));
    $terminalBefore = actionDatabaseState($database)['service_tombstones'];
    actionCheck($queue->selectionStatus([$cancelTerminal])[$cancelTerminal]['canDelete']
        && $queue->selectionStatus([$cancelTerminalSuccessor])[$cancelTerminalSuccessor]['canDelete'],
        'Event cleanup or prepared update cancellation eligibility was incorrect');
    $terminalCancelled = $queue->delete([$cancelTerminalSuccessor, $cancelTerminal, $deletable]);
    actionCheck($terminalCancelled === [$cancelTerminalSuccessor => true, $cancelTerminal => true, $deletable => false]
        && $queue->isServiceBlocked(971) && actionDatabaseState($database)['service_tombstones'] === $terminalBefore,
        'Terminal cancellation lost its tombstone, missed a selected cascaded successor, or counted an earlier cancellation');
    $freshBlocked = $queue->enqueue(actionSnapshot(971, ['status' => 1]));
    actionCheck($queue->runSelected([$freshBlocked], static function (): bool { throw new RuntimeException('Cancelled terminal guard bypassed'); })
        [$freshBlocked]['blockedReason'] === 'terminal', 'Cancelling the cleanup implicitly reactivated a terminated service');

    // A migration cascade reports all selected deletions/replacements while leaving older failed and foreign work intact.
    $olderFailure = $queue->enqueue(actionPrepared(972, ['device' => 6]));
    $queue->run([$olderFailure], static function (): bool { throw new RuntimeException('Local connection failure fixture'); });
    [$migrationDelete, $migrationDeleteAgain, $migrationReplacement] = $queue->enqueueBatch([
        ['snapshot' => actionPrepared(972, ['device' => 6, '_event_id' => 'migration-old-972']), 'operation' => 'delete'],
        ['snapshot' => actionPrepared(972, ['device' => 6, '_event_id' => 'migration-repeated-972']), 'operation' => 'delete'],
        ['snapshot' => actionPrepared(972, ['device' => 7, 'status' => 1]), 'operation' => 'update'],
    ]);
    $migrationBefore = $byKey(actionDatabaseState($database)['jobs']);
    actionCheck($queue->delete([$migrationDelete, $migrationDeleteAgain, $migrationReplacement]) === [
        $migrationDelete => true, $migrationDeleteAgain => true, $migrationReplacement => true],
        'Selected migration successors cancelled by an earlier deletion were reported as refused');
    $migrationAfter = $byKey(actionDatabaseState($database)['jobs']);
    actionCheck($migrationAfter[$olderFailure] === $migrationBefore[$olderFailure]
        && $migrationAfter[$untouched] === $migrationBefore[$untouched]
        && $migrationAfter[$migrationReplacement]['status'] === 'cancelled',
        'Cancellation changed older attempted work or another service, or exposed the replacement account');

    // Frozen attempts, nested cleanup and imports can be cancelled without discarding their possible effects.
    $frozenDelete = $queue->enqueue(actionPrepared(973, ['_delete_frozen' => true]), 'delete');
    $nestedCleanupDelete = $queue->enqueue(actionPrepared(974, ['_cleanup' => actionPrepared(974)]), 'delete');
    $attemptedDelete = $queue->enqueue(actionPrepared(975), 'delete');
    $queue->run([$attemptedDelete], static function (): bool { throw new RuntimeException('Partial delete fixture'); });
    $nullCleanupDelete = $queue->enqueue(actionPrepared(978, ['_cleanup' => null]), 'delete');
    $falseFrozenDelete = $queue->enqueue(actionPrepared(979, ['_delete_frozen' => false]), 'delete');
    $legacyPath = $directory . '/queue.json';
    file_put_contents($legacyPath, json_encode([
        'OLD_DELETE' => actionPrepared(976, ['action' => 'delete']),
        'event:imported' => actionPrepared(977, ['action' => 'delete', '_event_id' => 'imported']),
    ], JSON_THROW_ON_ERROR));
    $importer = new ApiJobQueue($directory . '/jobs.db', $legacyPath);
    $importer->close();
    $importedDeletes = [];
    foreach ($queue->list() as $key => $job) if (in_array($job['id'], [976, 977], true)) $importedDeletes[] = $key;
    actionCheck(count($importedDeletes) === 2 && !is_file($legacyPath), 'Legacy cancellation fixtures were not actually imported');
    $protectedSelection = array_merge([$frozenDelete, $nestedCleanupDelete, $attemptedDelete,
        $nullCleanupDelete, $falseFrozenDelete], $importedDeletes);
    $protectedBefore = actionDatabaseState($database);
    foreach ($queue->selectionStatus($protectedSelection) as $job) {
        actionCheck($job['canDelete'] && $job['deleteBlockedReason'] === null, 'A pending frozen/imported job could not be cancelled');
    }
    actionCheck($queue->delete($protectedSelection) === array_fill_keys($protectedSelection, true)
        && $identities(actionDatabaseState($database)['jobs']) === $identities($protectedBefore['jobs']),
        'Cancelling frozen/imported work created another job or refused a pending selection');
    foreach ([973,974,975,976,977,978,979] as $serviceId) {
        actionCheck($queue->hasUnreconciled($serviceId), 'Cancelled frozen/imported work lost its resource debt');
    }

    // Reopening and compacting old cancelled snapshots keeps their durable residues; cancellation never creates new jobs.
    $allCancelled = [$clonedDelete, $cancelTerminal, $cancelTerminalSuccessor,
        $migrationDelete, $migrationDeleteAgain, $migrationReplacement];
    $database->exec("UPDATE jobs SET updated='2000-01-01T00:00:00+00:00' WHERE id IN ("
        . implode(',', array_map(static fn(string $key): int => (int) substr($key, 1), $allCancelled)) . ')');
    $beforeRestart = actionDatabaseState($database);
    $addressesBeforeRestart = [$queue->reservedAddresses(), $queue->reservedAddresses(true)];
    $second->close(); $queue->close();
    $queue = new ApiJobQueue($directory . '/jobs.db', null);
    $second = new ApiJobQueue($directory . '/jobs.db', null);
    $database = $property->getValue($queue);
    actionCheck($identities(actionDatabaseState($database)['jobs']) === $identities($beforeRestart['jobs'])
        && $queue->appliedSnapshot(970) === $appliedBefore && $queue->isServiceBlocked(971)
        && [$queue->reservedAddresses(), $queue->reservedAddresses(true)] === $addressesBeforeRestart,
        'Restart pruned cancelled resource ownership, lost terminal intent, or enqueued new work');
    $calls = [];
    $inactive = $queue->runSelected($allCancelled, static function () use (&$calls): bool { $calls[] = true; return true; });
    actionCheck($calls === [] && count($inactive) === count($allCancelled)
        && $queue->delete($allCancelled) === array_fill_keys($allCancelled, false),
        'Cancelled work executed again or a previous call was counted as a new cancellation');
    foreach ($inactive as $job) actionCheck($job['state'] === 'cancelled' && !$job['attempted'], 'Cancelled snapshot was revived after restart');

    // Cancellation retains each exact resource snapshot; identity equality cannot discard different resources.
    $baseline = actionPrepared(1001, ['device' => 41, 'username' => 'baseline-account', 'address' => '192.0.2.201',
        '_device' => ['id' => 41, 'ip' => '192.0.2.41', 'password' => 'PRIVATE-BASE-ROUTER']]);
    $baselineKey = $queue->enqueue($baseline);
    actionCheck($queue->run([$baselineKey], static fn(): bool => true)[$baselineKey]['success'], 'Debt baseline did not apply');
    $baselineApplied = $queue->appliedSnapshot(1001);
    $cleanupPart = actionPrepared(1001, ['device' => 40, 'username' => 'cleanup-account', 'address' => '192.0.2.200',
        '_device' => ['id' => 40, 'ip' => '192.0.2.40', 'password' => 'PRIVATE-CLEANUP-ROUTER']]);
    $partialPart = actionPrepared(1001, ['device' => 42, 'username' => 'partial-account', 'address' => '192.0.2.202',
        'address6' => '2001:db8::202', '_cleanup' => $cleanupPart, '_force_reconnect' => true,
        '_device' => ['id' => 42, 'ip' => '192.0.2.42', 'password' => 'PRIVATE-PARTIAL-ROUTER']]);
    $partialPart['_commands'][0]['password'] = 'PRIVATE-COMMAND-SECRET';
    $partialPart['_commands'][] = ['path' => '/ppp/profile', 'name' => 'paused-fixture',
        '_bootstrap_suspension' => true, '_bootstrap_required' => true,
        '_suspension_configuration' => ['profileName' => 'paused-fixture', 'addressList' => 'paused-list', 'rateLimit' => '1M/1M']];
    $partialKey = $queue->enqueue($partialPart);
    $queue->run([$partialKey], static fn(): bool => false);
    $beforeCancelIds = $identities(actionDatabaseState($database)['jobs']); MyLog()->entries = [];
    actionCheck($queue->delete([$partialKey])[$partialKey] && $queue->appliedSnapshot(1001) === $baselineApplied
        && $identities(actionDatabaseState($database)['jobs']) === $beforeCancelIds && MyLog()->entries === []
        && $queue->hasUnreconciled(1001), 'Cancelling attempted work changed applied state, ran an attempt or created jobs');
    $parts = $database->query('SELECT slot,payload FROM cancellation_cleanups WHERE source_job_id=' . (int) substr($partialKey, 1) . ' ORDER BY id');
    $slots = []; $privateParts = [];
    while ($part = $parts->fetchArray(SQLITE3_ASSOC)) { $slots[] = $part['slot']; $privateParts[] = json_decode($part['payload'], true, 512, JSON_THROW_ON_ERROR); }
    actionCheck($slots === ['cleanup', 'applied', 'commands'], 'Cancellation collapsed independent cleanup/applied/partial resource snapshots');
    foreach ($privateParts as $part) actionCheck(!isset($part['password']) && !isset($part['_commands'][0]['password'])
        && isset($part['_device']['password']), 'Private cleanup material retained account secrets or lost the recorded transport password');
    actionCheck($privateParts[2]['_commands'][1]['_bootstrap_required'] === true,
        'Cancellation discarded the unresolved suspension-profile bootstrap debt');
    $foreignDebtJob = $queue->enqueue(actionPrepared(1002, ['device' => 44]));
    $queue->delete([$foreignDebtJob]);
    $foreignDebtId = (int) $database->querySingle('SELECT id FROM cancellation_cleanups WHERE service_id=1002 LIMIT 1');
    $drain = $queue->enqueue(actionSnapshot(1001, ['device' => 43, 'username' => 'current-desired',
        'address' => '192.0.2.203', '_event_id' => 'fresh-after-cancellation']));
    $firstDebtIds = []; $ownedJob = null;
    $queue->run([$drain], static function ($snapshot, $operation, $job, ApiJobQueue $store) use (&$firstDebtIds, &$ownedJob, $foreignDebtId, $database): bool {
        $ownedJob = $job; $debts = $store->cancellationCleanups($job); $firstDebtIds = array_column($debts, 'id');
        actionCheck(count($debts) === 3, 'The newer owner did not receive every older cleanup snapshot');
        actionReject(static fn() => $store->cancellationCleanups(array_replace($job, ['owner' => 'foreign-owner'])), 'Foreign owner read private debt');
        actionReject(static fn() => $store->confirmCancellationCleanup($job, $foreignDebtId), 'Cleanup confirmation crossed services');
        $store->confirmCancellationCleanup($job, $debts[0]['id']);
        actionCheck(count($store->cancellationCleanups($job)) === 2 && $store->hasUnreconciled(1001),
            'One cleanup confirmation discarded the other identities');
        throw new RuntimeException('Fixture stops after one confirmed physical cleanup');
    });
    actionReject(static fn() => $queue->cancellationCleanups($ownedJob), 'Finished/failed owner retained private debt access');
    actionCheck($queue->hasDeviceReferences([41]) && $queue->hasDeviceReferences([42])
        && in_array(['id' => 1001, 'address6' => '2001:db8::202'], $queue->reservedAddresses(true), true),
        'Partial cleanup released the other frozen routers or IPv6 address');
    $second->close(); $queue->close();
    $queue = new ApiJobQueue($directory . '/jobs.db', null); $second = new ApiJobQueue($directory . '/jobs.db', null);
    $database = $property->getValue($queue);
    $queue->run([$drain], static function ($snapshot, $operation, $job, ApiJobQueue $store) use ($firstDebtIds, $partialKey, $database): bool {
        $debts = $store->cancellationCleanups($job);
        actionCheck(array_column($debts, 'id') === array_slice($firstDebtIds, 1), 'Restart repeated confirmed cleanup or lost unfinished debt');
        $store->confirmCancellationCleanup($job, $debts[0]['id']);
        actionCheck($store->appliedSnapshot(1001) === [] && $store->hasUnreconciled(1001)
            && $database->querySingle('SELECT status FROM jobs WHERE id=' . (int) substr($partialKey, 1)) === 'cancelled',
            'Confirming the borrowed applied identity prematurely cleaned the cancelled partial identity');
        $store->confirmCancellationCleanup($job, $debts[1]['id']);
        actionCheck(!$store->hasUnreconciled(1001)
            && $database->querySingle('SELECT status FROM jobs WHERE id=' . (int) substr($partialKey, 1)) === 'cleaned',
            'The source was not retired after its last explicit confirmation');
        return true;
    });
    actionCheck($queue->completed([$drain]) && $queue->appliedSnapshot(1001)['username'] === 'current-desired'
        && !$queue->hasDeviceReferences([40,41,42]) && $queue->hasUnreconciled(1002),
        'Reconciliation replayed the cancelled intention, leaked old resources, or cleared another service');

    // An ordinary success alone cannot infer that cancelled resources of the same identity were removed.
    $sameSnapshot = actionPrepared(1005, ['address' => '192.0.2.205']);
    $sameSource = $queue->enqueue($sameSnapshot); $queue->delete([$sameSource]);
    $sameNext = $queue->enqueue($sameSnapshot + ['_event_id' => 'same-identity-current']);
    $queue->run([$sameNext], static fn(): bool => true);
    actionCheck($queue->hasUnreconciled(1005)
        && $database->querySingle('SELECT status FROM jobs WHERE id=' . (int) substr($sameSource, 1)) === 'cancelled',
        'A same-identity success inferred cleanup without confirming frozen resources');
    $versionOwner = $queue->enqueue(actionSnapshot(1003));
    $queue->run([$versionOwner], static function ($snapshot, $operation, $job, ApiJobQueue $store) use ($database): bool {
        $future = $store->enqueue(actionPrepared(1003, ['_event_id' => 'later-version'])); $store->delete([$future]);
        $futureDebtId = (int) $database->querySingle('SELECT id FROM cancellation_cleanups WHERE service_id=1003 LIMIT 1');
        actionCheck($store->cancellationCleanups($job) === [], 'An older operation received a later cancellation');
        actionReject(static fn() => $store->confirmCancellationCleanup($job, $futureDebtId), 'An older operation confirmed future debt');
        return true;
    });

    // Upgrade seeds old cancelled/superseded prepared rows before compaction and never duplicates its seed.
    $legacyCancelled = $queue->enqueue(actionPrepared(1004, ['_device' => ['id' => 48, 'ip' => '192.0.2.48', 'password' => 'PRIVATE-LEGACY-ROUTER']]));
    $legacySuperseded = $queue->enqueue(actionPrepared(1006));
    $database->exec("UPDATE jobs SET status='cancelled',updated='2000-01-01' WHERE id=" . (int) substr($legacyCancelled, 1));
    $database->exec("UPDATE jobs SET status='superseded',updated='2000-01-01' WHERE id=" . (int) substr($legacySuperseded, 1));
    $database->exec("DELETE FROM queue_meta WHERE key='cancellation_cleanups_seeded_v1'");
    $second->close(); $queue->close();
    $queue = new ApiJobQueue($directory . '/jobs.db', null); $database = $property->getValue($queue);
    actionCheck($queue->hasUnreconciled(1004) && $queue->hasUnreconciled(1006), 'Upgrade lost historical cancelled/superseded resources');
    $seedCount = (int) $database->querySingle('SELECT count(*) FROM cancellation_cleanups');
    $second = new ApiJobQueue($directory . '/jobs.db', null);
    actionCheck((int) $database->querySingle('SELECT count(*) FROM cancellation_cleanups') === $seedCount,
        'Reopening duplicated cancellation debt');
    // A cleanup confirmed before cancellation must not keep the retired endpoint referenced forever.
    $alreadyCleaned = actionPrepared(1007, ['device' => 49, '_device' => ['id' => 49, 'ip' => '192.0.2.49'],
        'username' => 'previously-removed', 'address' => '192.0.2.249']);
    $afterCleanup = $queue->enqueue(actionSnapshot(1007, ['device' => 50, '_device' => ['id' => 50, 'ip' => '192.0.2.50'],
        '_cleanup' => $alreadyCleaned]));
    $queue->run([$afterCleanup], static function ($snapshot, $operation, $job, ApiJobQueue $store) use ($alreadyCleaned): bool {
        $store->confirmCleanup($job, $alreadyCleaned);
        throw new RuntimeException('Preparation failed after confirmed cleanup, before any new commands');
    });
    actionCheck($queue->delete([$afterCleanup])[$afterCleanup] && !$queue->hasUnreconciled(1007)
        && !$queue->hasDeviceReferences([49,50]) && $queue->hasUnreconciled(911)
        && $queue->hasDeviceReferences([2]),
        'Compaction retained confirmed cleanup forever or dropped a different unconfirmed cleanup');
    // A never-started native successor borrows reservations but cannot have changed the confirmed account.
    $priorBaseline = actionPrepared(1008, ['address' => '192.0.2.208']);
    $priorBaselineKey = $queue->enqueue($priorBaseline); $queue->run([$priorBaselineKey], static fn(): bool => true);
    $priorApplied = $queue->appliedSnapshot(1008);
    foreach ([null, 'unstarted-native-event'] as $nativeEventId) {
        $priorOnly = actionSnapshot(1008, ['_prior_network' => ['address' => '192.0.2.208'], '_event_id' => $nativeEventId]);
        $unstartedPrior = $queue->enqueue($priorOnly);
        $reservedBefore = $queue->reservedAddresses();
        actionCheck($queue->delete([$unstartedPrior])[$unstartedPrior] && !$queue->hasUnreconciled(1008)
            && $queue->appliedSnapshot(1008) === $priorApplied && $queue->reservedAddresses() === $reservedBefore,
            'Cancelling an unstarted prior-only successor invented a physical cleanup of the applied account');
    }
    // Attempt history and imports remain conservative even without frozen commands.
    $unpreparedAttempt = $queue->enqueue(actionSnapshot(1008, ['_event_id' => 'attempted-without-commands']));
    $queue->run([$unpreparedAttempt], static fn(): bool => false);
    actionCheck($queue->delete([$unpreparedAttempt])[$unpreparedAttempt] && $queue->hasUnreconciled(1008)
        && $queue->appliedSnapshot(1008) === $priorApplied,
        'An attempted legacy-shaped snapshot lost tracking of its possibly affected applied account');
    $importBaseline = actionPrepared(1009, ['address' => '192.0.2.209']);
    $importBaselineKey = $queue->enqueue($importBaseline); $queue->run([$importBaselineKey], static fn(): bool => true);
    $legacyPriorPath = $directory . '/legacy-prior-only.json';
    file_put_contents($legacyPriorPath, json_encode(['OLD_PRIOR_ONLY' => actionSnapshot(1009,
        ['_prior_network' => ['address' => '192.0.2.209']])], JSON_THROW_ON_ERROR));
    $importer = new ApiJobQueue($directory . '/jobs.db', $legacyPriorPath); $importer->close();
    $legacyPriorKey = null;
    foreach ($queue->list() as $key => $item) if ((int) $item['id'] === 1009) $legacyPriorKey = $key;
    actionCheck($legacyPriorKey !== null && $queue->delete([$legacyPriorKey])[$legacyPriorKey]
        && $queue->hasUnreconciled(1009), 'An imported prior-only row was incorrectly treated as provably never started');
    // Historical event compaction removes the payload event marker but keeps its durable native key.
    $historicalBaseline = $queue->enqueue(actionPrepared(1011, ['address' => '192.0.2.211']));
    $queue->run([$historicalBaseline], static fn(): bool => true);
    $historicalApplied = $queue->appliedSnapshot(1011);
    $historicalEvent = $queue->enqueue(actionSnapshot(1011, ['_event_id' => 'historical-unstarted-event']));
    $queue->delete([$historicalEvent]);
    $historicalPayload = json_decode($database->querySingle('SELECT payload FROM jobs WHERE id=' . (int) substr($historicalEvent, 1)), true);
    actionCheck(!isset($historicalPayload['_event_id']) && !$queue->hasUnreconciled(1011),
        'The historical native event fixture was not compacted without a cleanup debt');
    $reservedBeforeReseed = $queue->reservedAddresses();
    $database->exec("DELETE FROM queue_meta WHERE key='cancellation_cleanups_seeded_v1'");
    $second->close(); $queue->close();
    $queue = new ApiJobQueue($directory . '/jobs.db', null); $database = $property->getValue($queue);
    $second = new ApiJobQueue($directory . '/jobs.db', null);
    actionCheck(!$queue->hasUnreconciled(1011) && $queue->appliedSnapshot(1011) === $historicalApplied
        && $queue->reservedAddresses() === $reservedBeforeReseed,
        'Historical reseeding misclassified an unstarted compacted native event as an uncertain import');
    MyLog()->entries = [];
    $connectionCodes = ['router_tcp_connect_failed', 'router_tls_connect_failed', 'router_login_rejected',
        'router_login_timeout', 'router_login_connection_closed', 'router_login_protocol_error', 'router_login_write_failed'];
    foreach ($connectionCodes as $index => $code) {
        $connectionJob = $queue->enqueue(actionSnapshot(980 + $index));
        $queue->run([$connectionJob], static function () use ($code): bool { throw new RuntimeException($code . ': PRIVATE-RESPONSE'); });
    }
    actionCheck(array_column(MyLog()->entries, 'errorCode') === $connectionCodes
        && strpos(json_encode(MyLog()->entries), 'PRIVATE-') === false,
        'Connection diagnostics lost their stable code or exposed the raw response');
    echo "Job actions passed: ordered manual prerequisites, bounded scope, pending cancellation without router work, running guards, durable per-snapshot cleanup debt, owner/service/version confirmation, preserved resources/tombstones, exact cascade counts, historical migration and restart.\n";
} finally {
    if (isset($importer)) $importer->close();
    if (isset($second)) $second->close();
    if (isset($queue)) $queue->close();
    $resolved = realpath($directory); $temporaryRoot = realpath(sys_get_temp_dir());
    if ($resolved && $temporaryRoot && strpos($resolved, $temporaryRoot . DIRECTORY_SEPARATOR . 'uisp-job-actions-') === 0) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($resolved);
    }
}
