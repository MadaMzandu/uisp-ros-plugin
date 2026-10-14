<?php
// Counts use only temporary local files, never a live queue, CRM or router.
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
require_once __DIR__ . '/../src/lib/api_jobs_status.php';
function checkJobStatus(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$directory = sys_get_temp_dir() . '/uisp-jobs-status-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$jobs = $directory . '/jobs.db'; $legacy = $directory . '/queue.json';
try {
    $reader = new ApiJobsStatus($jobs, $legacy);
    $empty = $reader->read();
    checkJobStatus($empty['available'] && $empty['total'] === 0 && $empty['source'] === 'empty'
        && !is_file($jobs) && !is_file($legacy) && count(scandir($directory)) === 2, 'Empty read initialized queue storage');
    $entry = ['id' => 41, 'action' => 'update', 'password' => 'PRIVATE-SYNTHETIC-PASSWORD'];
    file_put_contents($legacy, json_encode(['fixture' => $entry]));
    $before = hash_file('sha256', $legacy);
    $report = $reader->read();
    checkJobStatus($report['available'] && $report['pending'] === 1 && $report['failed'] === 0 && $report['source'] === 'legacy'
        && !str_contains(json_encode($report), 'PRIVATE') && !is_file($jobs) && hash_file('sha256', $legacy) === $before,
        'Legacy summary leaked or imported the private job');
    file_put_contents($legacy, '[null]');
    $bad = $reader->read(); checkJobStatus(!$bad['available'] && $bad['total'] === null, 'Malformed legacy queue fabricated zero');
    unlink($legacy);
    $db = new SQLite3($jobs); $db->enableExceptions(true);
    $db->exec("CREATE TABLE jobs(id INTEGER PRIMARY KEY,status TEXT,error TEXT,legacy_key TEXT);
        CREATE TABLE queue_meta(key TEXT PRIMARY KEY,value TEXT);
        CREATE TABLE cancellation_cleanups(id INTEGER PRIMARY KEY,source_job_id INTEGER);
        CREATE TABLE job_receipts(id INTEGER PRIMARY KEY,legacy_key TEXT);
        INSERT INTO jobs VALUES(1,'pending','PRIVATE-ERROR',NULL),(2,'pending',NULL,NULL),(3,'running',NULL,NULL),
            (4,'succeeded',NULL,NULL),(5,'cancelled',NULL,NULL);
        INSERT INTO cancellation_cleanups VALUES(1,5),(2,5),(3,1)");
    $db->close();
    $before = hash_file('sha256', $jobs); $files = scandir($directory);
    $report = $reader->read();
    checkJobStatus($report['available'] && $report['pending'] === 2 && $report['running'] === 1 && $report['failed'] === 1
        && $report['recovery'] === 1 && $report['total'] === 4 && !str_contains(json_encode($report), 'PRIVATE'),
        'Counts lost error subset, running state, or deduplicated recovery');
    checkJobStatus(hash_file('sha256', $jobs) === $before && scandir($directory) === $files, 'Readonly summary mutated storage');
    // An imported legacy file may remain after unlink failed: count it only once.
    $json = json_encode(['fixture' => $entry]); file_put_contents($legacy, $json);
    $db = new SQLite3($jobs); $db->enableExceptions(true);
    $statement = $db->prepare('INSERT INTO queue_meta VALUES(?,?)');
    $statement->bindValue(1, 'legacy:' . hash('sha256', $json)); $statement->bindValue(2, 'fixture');
    $result = $statement->execute(); $result->finalize(); $statement->close(); $db->close();
    checkJobStatus($reader->read()['legacy_pending'] === 0, 'Imported legacy file double-counted');
    // A changed legacy file can contain an already-imported entry plus a new one.
    $canonical = $entry; ksort($canonical);
    $key = 'fixture:' . hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $db = new SQLite3($jobs);
    $statement = $db->prepare('UPDATE jobs SET legacy_key=? WHERE id=4'); $statement->bindValue(1, $key);
    $result = $statement->execute(); $result->finalize(); $statement->close(); $db->close();
    file_put_contents($legacy, json_encode(['fixture' => $entry, 'new' => ['id' => 42, 'action' => 'update', 'error' => 'PRIVATE-ERROR']]));
    $mixed = $reader->read();
    checkJobStatus($mixed['available'] && $mixed['pending'] === 3 && $mixed['failed'] === 2 && $mixed['legacy_pending'] === 1,
        'Changed legacy source was not deduplicated against recorded jobs');
    unlink($legacy);
    $db = new SQLite3($jobs); $db->exec("UPDATE jobs SET status='succeeded',error=NULL; DELETE FROM cancellation_cleanups"); $db->close();
    $done = $reader->read(); checkJobStatus($done['available'] && $done['total'] === 0 && $done['failed'] === 0, 'Completed queue still counted pending work');
    $db = new SQLite3($jobs); $db->exec("UPDATE jobs SET status='unknown' WHERE id=1"); $db->close();
    $unknown = $reader->read(); checkJobStatus(!$unknown['available'] && $unknown['pending'] === null, 'Unknown job status fabricated a known count');
    unlink($jobs); file_put_contents($jobs, 'not SQLite');
    checkJobStatus(!$reader->read()['available'], 'Corrupt SQLite queue fabricated success');
    echo "Jobs status passed: readonly counts, empty/legacy/current storage, error subset, recovery deduplication, imported-source deduplication, privacy and unknown-state handling.\n";
} finally {
    foreach (glob($directory . '/*') ?: [] as $file) if (is_file($file) || is_link($file)) unlink($file);
    rmdir($directory);
}
