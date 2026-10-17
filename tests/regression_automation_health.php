<?php
// Local synthetic fixtures only: no CRM, router, queue initialization or setup.
set_error_handler(static function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
require_once __DIR__ . '/../src/lib/api_automation_health.php';
$childMode = $argv[1] ?? '';
if (in_array($childMode, ['--child', '--contended', '--blocked'], true)) {
    $directory = $argv[2];
    $started = microtime(true);
    $token = ApiAutomationHealth::schedulerStarted($directory);
    if ($childMode === '--blocked') {
        echo json_encode(['captured'=>$token!==null,'seconds'=>microtime(true)-$started]);
        exit(0);
    }
    ApiAutomationHealth::webhookReceived($directory);
    ApiAutomationHealth::schedulerFinished($token, true, null, $directory);
    // Bounded telemetry may be skipped under contention; it must never fail a job.
    echo $token === null ? ($childMode==='--contended' ? 'skipped' : 'failed') : 'completed';
    exit($token === null && $childMode!=='--contended' ? 1 : 0);
}
function healthCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function healthChild(string $directory, string $mode): array {
    $command = [PHP_BINARY,'-n','-d','extension_dir=' . ini_get('extension_dir'),'-d','extension=sqlite3',__FILE__,$mode,$directory];
    $process = proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    healthCheck(is_resource($process),'Could not start isolated concurrent fixture');
    fclose($pipes[0]); return [$process,$pipes];
}
function healthChildResult(array $child): array {
    [$process,$pipes] = $child;
    $output = stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($process),$output,$errors];
}
function healthFiles(string $directory): array {
    $files = [];
    if (!is_dir($directory)) return $files;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file)
        if ($file->isFile()) $files[$file->getPathname()] = hash_file('sha256', $file->getPathname());
    ksort($files); return $files;
}
function healthClean(string $directory): void {
    if (!is_dir($directory) || is_link($directory)) return;
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) healthClean($entry->getPathname());
        else unlink($entry->getPathname());
    }
    rmdir($directory);
}
$directory = sys_get_temp_dir() . '/uisp-automation-health-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$statePath = $directory . '/automation-health/state.json';
try {
    $missing = $directory . '/not-created';
    $report = ApiAutomationHealth::read($missing);
    healthCheck($report['scheduler']['state'] === 'never_observed' && $report['webhook']['state'] === 'never_observed'
        && $report['queue']['state'] === 'empty' && !file_exists($missing), 'Empty health poll created operational files or invented history');
    $report = ApiAutomationHealth::read($directory);
    healthCheck(count(scandir($directory)) === 2 && !$report['scheduler']['scheduleKnown'] && $report['scheduler']['nextExpectedAt'] === null,
        'Health poll fabricated external cron configuration or initialized storage');

    $token = ApiAutomationHealth::schedulerStarted($directory);
    healthCheck(is_string($token) && preg_match('/^[a-f0-9]{32}$/D', $token) && is_file($statePath), 'Scheduler start was not recorded');
    ApiAutomationHealth::webhookReceived($directory);
    $running = ApiAutomationHealth::read($directory);
    healthCheck($running['scheduler']['state'] === 'running' && $running['scheduler']['activeRuns'] === 1
        && $running['webhook']['state'] === 'observed', 'Accepted webhook lost a concurrent running heartbeat');
    ApiAutomationHealth::schedulerFinished($token, false, 'PRIVATE_ERROR_CUSTOMER_PASSWORD', $directory);
    $failed = ApiAutomationHealth::read($directory);
    healthCheck($failed['scheduler']['state'] === 'failed' && $failed['scheduler']['lastFailedAt'] !== null
        && $failed['scheduler']['lastErrorCode'] === 'scheduled_operation_failed'
        && !str_contains(file_get_contents($statePath), 'PRIVATE') && !str_contains(json_encode($failed), 'PRIVATE'), 'Failure diagnostic leaked arbitrary error data');
    $before = file_get_contents($statePath);
    ApiAutomationHealth::schedulerFinished(str_repeat('0', 32), true, null, $directory);
    healthCheck(file_get_contents($statePath) === $before, 'Unissued finish token changed run observations');
    $token = ApiAutomationHealth::schedulerStarted($directory);
    ApiAutomationHealth::schedulerFinished($token, true, null, $directory);
    $success = ApiAutomationHealth::read($directory);
    healthCheck($success['scheduler']['state'] === 'observed' && $success['scheduler']['activeRuns'] === 0
        && $success['scheduler']['lastSucceededAt'] !== null && $success['scheduler']['lastErrorCode'] === null,
        'Successful run failed to preserve history or invented a configured interval');

    // An inferred cadence is explicitly an observation, never a promised next cron run.
    $now = time(); $state = json_decode(file_get_contents($statePath), true);
    $state['starts'] = [$now - 600, $now - 300, $now]; $state['lastStartedAt'] = $now;
    file_put_contents($statePath, json_encode($state));
    $healthy = ApiAutomationHealth::read($directory, $now);
    $delayed = ApiAutomationHealth::read($directory, $now + 901);
    healthCheck($healthy['scheduler']['state'] === 'healthy' && $healthy['scheduler']['observedIntervalSeconds'] === 300
        && $healthy['scheduler']['nextExpectedAt'] === null && $delayed['scheduler']['state'] === 'delayed', 'Observed cadence was not bounded or overdue state was lost');
    $state['starts'] = [$now]; $state['active'] = [str_repeat('1',32) => $now];
    file_put_contents($statePath, json_encode($state));
    healthCheck(ApiAutomationHealth::read($directory, $now + 3601)['scheduler']['state'] === 'unknown', 'Unfinished first run was reported as running forever');
    $state['active'] = []; file_put_contents($statePath, json_encode($state));

    $jobs = new SQLite3($directory . '/jobs.db');
    $jobs->exec("CREATE TABLE jobs(id INTEGER PRIMARY KEY,status TEXT,error TEXT,legacy_key TEXT,created TEXT,next_attempt INTEGER);
        CREATE TABLE queue_meta(key TEXT PRIMARY KEY,value TEXT);
        CREATE TABLE cancellation_cleanups(id INTEGER PRIMARY KEY,source_job_id INTEGER);
        CREATE TABLE job_receipts(id INTEGER PRIMARY KEY,legacy_key TEXT)");
    $insert = $jobs->prepare('INSERT INTO jobs VALUES(?,?,?,?,?,?)');
    foreach ([[1,'pending','PRIVATE_ERROR',null,gmdate('c',$now-7200),$now+60],
        [2,'pending',null,null,gmdate('c',$now-1800),$now+120],
        [3,'running',null,null,gmdate('c',$now-2000),0],
        [4,'succeeded',null,null,gmdate('c',$now-90000),0]] as $row) {
        foreach ($row as $index => $value) $insert->bindValue($index+1,$value,is_int($value)?SQLITE3_INTEGER:($value===null?SQLITE3_NULL:SQLITE3_TEXT));
        $insert->execute()->finalize();
    }
    $insert->close(); $jobs->close();
    $data = new SQLite3($directory . '/data.db');
    $data->exec('CREATE TABLE config(key TEXT PRIMARY KEY,value TEXT)');
    $insert = $data->prepare('INSERT INTO config VALUES(?,?)');
    $insert->bindValue(1,'last_cache');$insert->bindValue(2,gmdate('c',$now-3600));$insert->execute()->finalize();$insert->close();$data->close();
    $before = healthFiles($directory);
    $report = ApiAutomationHealth::read($directory, $now);
    healthCheck($report['queue']['pending'] === 2 && $report['queue']['running'] === 1 && $report['queue']['failed'] === 1
        && $report['queue']['oldestPendingAgeSeconds'] === 7200 && $report['queue']['nextDueAt'] === gmdate('Y-m-d\TH:i:s\Z',$now+60)
        && $report['queue']['dueNow'] === false && $report['cache']['lastFullRefreshAt'] === gmdate('Y-m-d\TH:i:s\Z',$now-3600)
        && $report['maintenance']['state'] === 'not_recorded', 'Health lost queue age, backoff, failed subset or confirmed cache refresh');
    healthCheck(healthFiles($directory) === $before && !str_contains(json_encode($report), 'PRIVATE'), 'Health poll changed local state or exposed job details');

    $jobs = new SQLite3($directory . '/jobs.db');
    $jobs->exec("UPDATE jobs SET next_attempt=0 WHERE id=2");
    $insert = $jobs->prepare('INSERT INTO queue_meta VALUES(?,?)');
    $insert->bindValue(1,'maintenance');$insert->bindValue(2,json_encode(['lease'=>$now+300,'owner'=>'PRIVATE_OWNER']));$insert->execute()->finalize();$insert->close();$jobs->close();
    $report = ApiAutomationHealth::read($directory, $now);
    healthCheck($report['queue']['dueNow'] && $report['maintenance']['state'] === 'recorded' && $report['maintenance']['active']
        && !str_contains(json_encode($report),'PRIVATE'), 'Due work or recorded maintenance lease was hidden/leaked');
    healthCheck(ApiAutomationHealth::read($directory, $now+301)['maintenance']['active'] === null, 'Expired maintenance record was treated as an active owner');
    $jobs = new SQLite3($directory . '/jobs.db');$jobs->exec("UPDATE jobs SET created='not-a-date' WHERE id=1");$jobs->close();
    $report = ApiAutomationHealth::read($directory, $now);
    healthCheck($report['queue']['pending'] === 2 && !$report['queue']['timingKnown'], 'Invalid timing erased independently confirmed counts');

    // A held writer lock must skip capture within its bounded budget, preserve
    // the existing state and let the calling operation continue successfully.
    $beforeContention = file_get_contents($statePath);
    $guard = fopen($directory . '/automation-health/state.lock','c+b');
    healthCheck(is_resource($guard) && flock($guard,LOCK_EX|LOCK_NB),'Could not hold synthetic telemetry contention guard');
    try { [$exit,$output,$errors] = healthChildResult(healthChild($directory,'--blocked')); }
    finally { flock($guard,LOCK_UN);fclose($guard); }
    $blocked = json_decode($output,true,8,JSON_THROW_ON_ERROR);
    healthCheck($exit===0 && $errors==='' && $blocked['captured']===false && $blocked['seconds']>=0.45 && $blocked['seconds']<2.0
        && file_get_contents($statePath)===$beforeContention,'Telemetry contention blocked execution, corrupted state or exceeded its bounded capture policy');

    // Parallel requests may skip a best-effort observation after 0.5 seconds.
    // All callers must finish cleanly, and every captured update must leave a
    // valid bounded state. An unfinished captured run stays visible as running.
    $processes = [];
    for ($i=0;$i<8;$i++) $processes[]=healthChild($directory,'--contended');
    $childResults = [];
    foreach ($processes as $child) $childResults[]=healthChildResult($child);
    foreach ($childResults as [$exit,$output,$errors])
        healthCheck($exit===0 && in_array($output,['completed','skipped'],true) && $errors==='', 'Concurrent health caller failed: ' . $exit . ' ' . $output . ' ' . $errors);
    $concurrent = ApiAutomationHealth::read($directory);
    $capturedState = json_decode(file_get_contents($statePath),true,8,JSON_THROW_ON_ERROR);
    healthCheck(is_int($concurrent['scheduler']['activeRuns']) && $concurrent['scheduler']['activeRuns']===count($capturedState['active'])
        && $concurrent['scheduler']['activeRuns']<=8 && $concurrent['scheduler']['lastResult']==='succeeded'
        && ($concurrent['scheduler']['activeRuns']===0 || $concurrent['scheduler']['state']==='running')
        && $concurrent['webhook']['lastAcceptedAt']!==null && filesize($statePath)<32768,'Concurrent capture corrupted or hid incomplete observations');

    // With no competing writer, eight separate executions must all record
    // their lifecycle and preserve any earlier incomplete observations.
    $activeBeforeSerial = $capturedState['active'];
    for ($i=0;$i<8;$i++) {
        [$exit,$output,$errors] = healthChildResult(healthChild($directory,'--child'));
        healthCheck($exit===0 && $output==='completed' && $errors==='', 'Serialized health execution lost its observation');
    }
    $serialized = json_decode(file_get_contents($statePath),true,8,JSON_THROW_ON_ERROR);
    healthCheck($serialized['active']===$activeBeforeSerial && $serialized['lastResult']==='succeeded'
        && count($serialized['starts'])===min(12,count($capturedState['starts'])+8),'Serialized lifecycle updates lost prior state or failed to keep bounded history');

    $before = file_get_contents($statePath);file_put_contents($statePath,'{"version":1,"PRIVATE":"secret"}');
    $bad = ApiAutomationHealth::read($directory);
    healthCheck($bad['scheduler']['state']==='unknown' && $bad['webhook']['state']==='unknown'
        && !str_contains(json_encode($bad),'PRIVATE'), 'Corrupt heartbeat fabricated success or serialized untrusted state');
    healthCheck(ApiAutomationHealth::schedulerStarted($directory)===null,'Corrupt state was silently replaced by a successful observation');
    file_put_contents($statePath,$before);
    $data = new SQLite3($directory . '/data.db');$data->exec("UPDATE config SET value='2026-02-30T00:00:00Z'");$data->close();
    healthCheck(ApiAutomationHealth::read($directory)['cache']['state']==='unknown','Impossible cache timestamp was accepted');
    $source = file_get_contents(__DIR__ . '/../src/main.php');
    healthCheck(str_contains($source,'ApiAutomationHealth::schedulerStarted') && str_contains($source,'register_shutdown_function')
        && substr_count($source,'ApiAutomationHealth::schedulerFinished')===3,'Scheduled lifecycle does not record success/failure/shutdown');
    echo "Automation health passed: read-only local state, real scheduler/webhook observations, bounded concurrent writes, cadence, failed/unknown states, queue age/backoff, cache timestamps and privacy.\n";
} finally { healthClean($directory); }
