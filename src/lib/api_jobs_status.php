<?php

/** Local counts only. Never constructs, imports, recovers or executes a job queue. */
final class ApiJobsStatus
{
    private string $jobsPath;
    private string $legacyPath;

    public function __construct(string $jobsPath = 'data/jobs.db', string $legacyPath = 'data/queue.json')
    {
        $this->jobsPath = $jobsPath;
        $this->legacyPath = $legacyPath;
    }

    public function read(): array
    {
        $database = null;
        try {
            if (is_link($this->jobsPath) || is_link($this->legacyPath)) throw new RuntimeException('unsafe_queue_path');
            $pending = 0; $running = 0; $failed = 0; $recovery = 0;
            $source = 'empty';
            if (file_exists($this->jobsPath)) {
                if (!is_file($this->jobsPath)) throw new RuntimeException('invalid_queue_file');
                $database = new SQLite3($this->jobsPath, SQLITE3_OPEN_READONLY);
                $database->enableExceptions(true);
                $database->busyTimeout(500);
                $database->exec('BEGIN');
                $jobs = self::columns($database, 'jobs');
                if (!isset($jobs['id'], $jobs['status'], $jobs['error']) || $database->querySingle(
                    "SELECT count(*) FROM jobs WHERE typeof(id)<>'integer' OR id<1 OR status IS NULL
                     OR status NOT IN ('pending','running','succeeded','cancelled','superseded','cleaned')
                     OR (error IS NOT NULL AND typeof(error)<>'text')")) throw new RuntimeException('invalid_queue_schema');
                $row = $database->querySingle("SELECT
                    coalesce(sum(CASE WHEN status='pending' THEN 1 ELSE 0 END),0) AS pending,
                    coalesce(sum(CASE WHEN status='running' THEN 1 ELSE 0 END),0) AS running,
                    coalesce(sum(CASE WHEN status='pending' AND error IS NOT NULL AND error<>'' THEN 1 ELSE 0 END),0) AS failed
                    FROM jobs", true);
                $pending = (int) $row['pending']; $running = (int) $row['running']; $failed = (int) $row['failed'];
                $cleanup = self::columns($database, 'cancellation_cleanups');
                if ($cleanup) {
                    if (!isset($cleanup['source_job_id']) || $database->querySingle(
                        "SELECT count(*) FROM cancellation_cleanups WHERE typeof(source_job_id)<>'integer' OR source_job_id<1"))
                        throw new RuntimeException('invalid_recovery_schema');
                    $recovery = (int) $database->querySingle("SELECT count(DISTINCT source_job_id) FROM cancellation_cleanups c
                        WHERE NOT EXISTS(SELECT 1 FROM jobs j WHERE j.id=c.source_job_id AND j.status IN ('pending','running'))");
                }
                $source = 'sqlite';
            }
            [$legacyPending, $legacyFailed] = $this->legacy($database);
            if ($legacyPending > 0 && $source === 'empty') $source = 'legacy';
            $pending += $legacyPending; $failed += $legacyFailed;
            return ['available' => true, 'pending' => $pending, 'running' => $running, 'failed' => $failed,
                'recovery' => $recovery, 'total' => $pending + $running + $recovery, 'legacy_pending' => $legacyPending,
                'checked_at' => gmdate('Y-m-d\TH:i:s\Z'), 'source' => $source, 'reason' => null];
        } catch (Throwable $ignored) {
            // A failed read must not replace a previously visible nonzero count with zero.
            return ['available' => false, 'pending' => null, 'running' => null, 'failed' => null, 'recovery' => null,
                'total' => null, 'legacy_pending' => null, 'checked_at' => null, 'source' => null, 'reason' => 'unavailable'];
        } finally { if ($database !== null) $database->close(); }
    }

    private function legacy(?SQLite3 $database): array
    {
        if (!file_exists($this->legacyPath)) return [0, 0];
        if (!is_file($this->legacyPath) || filesize($this->legacyPath) > 1048576) throw new RuntimeException('invalid_legacy_queue');
        $json = file_get_contents($this->legacyPath);
        if ($json === false) throw new RuntimeException('legacy_read_failed');
        if (trim($json) === '') return [0, 0];
        $entries = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($entries)) throw new RuntimeException('invalid_legacy_queue');
        if (!$entries) return [0, 0];
        $jobs = $database !== null ? self::columns($database, 'jobs') : [];
        $receipts = $database !== null ? self::columns($database, 'job_receipts') : [];
        if ($database !== null) {
            $meta = self::columns($database, 'queue_meta');
            if ($meta && !isset($meta['key'])) throw new RuntimeException('invalid_queue_meta');
            if ($meta && self::hasKey($database, 'SELECT 1 FROM queue_meta WHERE key=?', 'legacy:' . hash('sha256', $json))) return [0, 0];
            if (!isset($jobs['legacy_key']) || ($receipts && !isset($receipts['legacy_key']))) throw new RuntimeException('legacy_state_unknown');
        }
        $pending = 0; $failed = 0;
        foreach ($entries as $key => $entry) {
            if (!is_array($entry) || filter_var($entry['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
                || !in_array($entry['action'] ?? 'update', ['update', 'delete', 'queues_set'], true)) throw new RuntimeException('invalid_legacy_entry');
            $canonical = self::canonical($entry);
            $legacyKey = (string) $key . ':' . hash('sha256', $canonical);
            if ($database !== null && (self::hasKey($database, 'SELECT 1 FROM jobs WHERE legacy_key=?', $legacyKey)
                || ($receipts && self::hasKey($database, 'SELECT 1 FROM job_receipts WHERE legacy_key=?', $legacyKey)))) continue;
            $pending++;
            if (isset($entry['error']) && $entry['error'] !== '') {
                if (!is_string($entry['error'])) throw new RuntimeException('invalid_legacy_error');
                $failed++;
            }
        }
        return [$pending, $failed];
    }

    private static function canonical(array $value): string
    {
        ksort($value);
        foreach ($value as $key => $item) if (is_array($item)) $value[$key] = json_decode(self::canonical($item), true, 64, JSON_THROW_ON_ERROR);
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function hasKey(SQLite3 $database, string $sql, string $key): bool
    {
        $statement = $database->prepare($sql);
        try {
            $statement->bindValue(1, $key, SQLITE3_TEXT);
            $result = $statement->execute();
            try { return $result->fetchArray(SQLITE3_NUM) !== false; }
            finally { $result->finalize(); }
        } finally { $statement->close(); }
    }

    private static function columns(SQLite3 $database, string $table): array
    {
        if (!in_array($table, ['jobs', 'cancellation_cleanups', 'queue_meta', 'job_receipts'], true)) throw new RuntimeException('invalid_queue_table');
        $result = $database->query("SELECT type FROM sqlite_master WHERE name='" . $table . "'");
        try { $entry = $result->fetchArray(SQLITE3_ASSOC); }
        finally { $result->finalize(); }
        if ($entry && $entry['type'] !== 'table') throw new RuntimeException('invalid_queue_schema');
        if (!$entry) return [];
        $result = $database->query('PRAGMA table_info("' . $table . '")');
        $columns = [];
        try { while ($row = $result->fetchArray(SQLITE3_ASSOC)) $columns[$row['name']] = true; }
        finally { $result->finalize(); }
        return $columns;
    }
}
