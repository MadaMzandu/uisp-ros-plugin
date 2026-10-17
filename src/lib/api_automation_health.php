<?php

require_once __DIR__ . '/api_jobs_status.php';

/** Local observations only. A panel poll never initializes or recovers a queue. */
final class ApiAutomationHealth
{
    private const MAX_BYTES = 32768;
    private const MAX_RUNS = 32;
    private const MAX_STARTS = 12;
    private const ERROR_CODES = ['scheduled_operation_failed', 'runtime_failure', 'cache_refresh_failed', 'version_mismatch'];

    public static function schedulerStarted(?string $dataDirectory = null): ?string
    {
        try {
            $token = bin2hex(random_bytes(16));
            $now = time();
            $saved = self::update($dataDirectory, static function (array $state) use ($token, $now): array {
                // Expired observations are not executable work and never hold a provisioning lock.
                $state['active'] = array_filter($state['active'], static fn(int $started): bool => $started > $now - 86400);
                if (count($state['active']) >= self::MAX_RUNS) throw new RuntimeException('health_limit');
                $state['active'][$token] = $now;
                $state['starts'][] = $now;
                $state['starts'] = array_slice($state['starts'], -self::MAX_STARTS);
                $state['lastStartedAt'] = max($state['lastStartedAt'] ?? 0, $now);
                return $state;
            });
            return $saved ? $token : null;
        } catch (Throwable $ignored) { return null; }
    }

    public static function schedulerFinished(?string $token, bool $success, ?string $safeErrorCode = null, ?string $dataDirectory = null): void
    {
        if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/D', $token)) return;
        $now = time();
        $code = in_array($safeErrorCode, self::ERROR_CODES, true) ? $safeErrorCode : 'scheduled_operation_failed';
        self::update($dataDirectory, static function (array $state) use ($token, $success, $code, $now): array {
            if (!isset($state['active'][$token])) return $state;
            unset($state['active'][$token]);
            $state['lastFinishedAt'] = $now;
            $state[$success ? 'lastSucceededAt' : 'lastFailedAt'] = $now;
            $state['lastResult'] = $success ? 'succeeded' : 'failed';
            $state['lastErrorCode'] = $success ? null : $code;
            return $state;
        });
    }

    /** Accepted means authenticated and syntactically valid; it does not mean applied. */
    public static function webhookReceived(?string $dataDirectory = null): void
    {
        $now = time();
        self::update($dataDirectory, static function (array $state) use ($now): array {
            $state['lastWebhookAt'] = max($state['lastWebhookAt'] ?? 0, $now);
            return $state;
        });
    }

    public static function read(?string $dataDirectory = null, ?int $now = null): array
    {
        $directory = $dataDirectory ?? 'data';
        $now ??= time();
        $report = [
            'checkedAt' => self::iso($now),
            'scheduler' => ['state' => 'unknown', 'lastStartedAt' => null, 'lastFinishedAt' => null,
                'lastSucceededAt' => null, 'lastFailedAt' => null, 'lastResult' => null, 'lastErrorCode' => null,
                'activeRuns' => null, 'ageSeconds' => null, 'observedIntervalSeconds' => null,
                'staleAfterSeconds' => null, 'scheduleKnown' => false, 'nextExpectedAt' => null],
            'webhook' => ['state' => 'unknown', 'lastAcceptedAt' => null],
            'queue' => ['state' => 'unknown', 'pending' => null, 'running' => null, 'failed' => null,
                'oldestPendingAt' => null, 'oldestPendingAgeSeconds' => null, 'nextDueAt' => null,
                'dueNow' => null, 'timingKnown' => false],
            'cache' => ['state' => 'unknown', 'lastFullRefreshAt' => null],
            'maintenance' => ['state' => 'unknown', 'active' => null],
        ];
        try {
            self::assertDirectory($directory);
            $statePath = $directory . '/automation-health/state.json';
            if (is_link($directory . '/automation-health')) throw new RuntimeException('health_path');
            $state = self::load($statePath);
            $intervals = [];
            $starts = $state['starts']; sort($starts, SORT_NUMERIC);
            for ($i = 1; $i < count($starts); $i++) {
                $interval = $starts[$i] - $starts[$i - 1];
                // Concurrent launches do not establish scheduler cadence.
                if ($interval >= 30 && $interval <= 86400) $intervals[] = $interval;
            }
            $interval = null;
            if (count($intervals) >= 2) { sort($intervals, SORT_NUMERIC); $interval = $intervals[intdiv(count($intervals), 2)]; }
            $stale = $interval === null ? null : max(900, min(259200, $interval * 3));
            $active = count($state['active']);
            $lastStarted = $state['lastStartedAt'];
            $age = $lastStarted === null ? null : max(0, $now - $lastStarted);
            $mode = $lastStarted === null ? 'never_observed' : 'observed';
            if ($state['lastResult'] === 'failed') $mode = 'failed';
            if ($active > 0) $mode = 'running';
            if ($active > 0 && $interval === null && $age !== null && $age > 3600) $mode = 'unknown';
            // Without an observed cadence there is no reliable scheduled deadline.
            if ($age !== null && $stale !== null && $age > $stale) $mode = 'delayed';
            if ($mode === 'observed' && $interval !== null) $mode = 'healthy';
            $report['scheduler'] = array_replace($report['scheduler'], [
                'state' => $mode, 'lastStartedAt' => self::iso($lastStarted), 'lastFinishedAt' => self::iso($state['lastFinishedAt']),
                'lastSucceededAt' => self::iso($state['lastSucceededAt']), 'lastFailedAt' => self::iso($state['lastFailedAt']),
                'lastResult' => $state['lastResult'], 'lastErrorCode' => $state['lastErrorCode'], 'activeRuns' => $active,
                'ageSeconds' => $age, 'observedIntervalSeconds' => $interval, 'staleAfterSeconds' => $stale,
            ]);
            $report['webhook'] = ['state' => $state['lastWebhookAt'] === null ? 'never_observed' : 'observed',
                'lastAcceptedAt' => self::iso($state['lastWebhookAt'])];
        } catch (Throwable $ignored) {}
        try { self::assertDirectory($directory); $report['queue'] = self::queue($directory, $now); }
        catch (Throwable $ignored) {}
        try { self::assertDirectory($directory); $report['cache'] = self::cache($directory); }
        catch (Throwable $ignored) {}
        try { self::assertDirectory($directory); $report['maintenance'] = self::maintenance($directory, $now); }
        catch (Throwable $ignored) {}
        return $report;
    }

    private static function emptyState(): array
    {
        return ['version' => 1, 'lastStartedAt' => null, 'lastFinishedAt' => null, 'lastSucceededAt' => null,
            'lastFailedAt' => null, 'lastResult' => null, 'lastErrorCode' => null, 'starts' => [], 'active' => [], 'lastWebhookAt' => null];
    }

    private static function load(string $path): array
    {
        if (is_link($path)) throw new RuntimeException('health_path');
        if (!file_exists($path)) return self::emptyState();
        if (!is_file($path) || filesize($path) > self::MAX_BYTES) throw new RuntimeException('health_state');
        $raw = file_get_contents($path);
        if (!is_string($raw)) throw new RuntimeException('health_read');
        $value = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        $empty = self::emptyState();
        if (!is_array($value) || count($value) !== count($empty) || array_diff_key($value, $empty)
            || ($value['version'] ?? null) !== 1) throw new RuntimeException('health_schema');
        foreach (['lastStartedAt','lastFinishedAt','lastSucceededAt','lastFailedAt','lastWebhookAt'] as $key) {
            if ($value[$key] !== null && !self::timestamp($value[$key])) throw new RuntimeException('health_timestamp');
        }
        if (!in_array($value['lastResult'], [null, 'succeeded', 'failed'], true)
            || !in_array($value['lastErrorCode'], array_merge([null], self::ERROR_CODES), true)
            || !is_array($value['starts']) || array_keys($value['starts']) !== array_keys(array_values($value['starts']))
            || count($value['starts']) > self::MAX_STARTS || !is_array($value['active']) || count($value['active']) > self::MAX_RUNS)
            throw new RuntimeException('health_schema');
        foreach ($value['starts'] as $start) if (!self::timestamp($start)) throw new RuntimeException('health_timestamp');
        foreach ($value['active'] as $token => $start) {
            if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/D', $token) || !self::timestamp($start)) throw new RuntimeException('health_schema');
        }
        return $value;
    }

    private static function update(?string $dataDirectory, callable $change): bool
    {
        $lock = null; $temporary = null;
        try {
            $directory = $dataDirectory ?? 'data';
            self::assertDirectory($directory);
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return false;
            $private = $directory . '/automation-health';
            if (is_link($private) || (file_exists($private) && !is_dir($private))) return false;
            if (!is_dir($private) && !@mkdir($private, 0700) && !is_dir($private)) return false;
            @chmod($private, 0700);
            $lockPath = $private . '/state.lock';
            if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) return false;
            $lock = @fopen($lockPath, 'c+b');
            if ($lock === false) return false;
            @chmod($lockPath, 0600);
            $deadline = microtime(true) + 0.5;
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) return false;
                usleep(5000);
            }
            $path = $private . '/state.json';
            $state = $change(self::load($path));
            $encoded = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (strlen($encoded) > self::MAX_BYTES) return false;
            $temporary = $private . '/state-' . bin2hex(random_bytes(8)) . '.tmp';
            $file = @fopen($temporary, 'xb');
            if ($file === false) return false;
            try {
                if (!@chmod($temporary, 0600) || fwrite($file, $encoded) !== strlen($encoded) || !fflush($file)) return false;
            } finally { fclose($file); }
            if (!@rename($temporary, $path)) return false;
            $temporary = null;
            return true;
        } catch (Throwable $ignored) { return false; }
        finally {
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
            if ($temporary !== null && is_file($temporary)) @unlink($temporary);
        }
    }

    private static function queue(string $directory, int $now): array
    {
        $status = (new ApiJobsStatus($directory . '/jobs.db', $directory . '/queue.json'))->read();
        $result = ['state' => $status['available'] ? ($status['source'] === 'empty' ? 'empty' : 'observed') : 'unknown',
            'pending' => $status['pending'], 'running' => $status['running'], 'failed' => $status['failed'],
            'oldestPendingAt' => null, 'oldestPendingAgeSeconds' => null, 'nextDueAt' => null, 'dueNow' => null, 'timingKnown' => false];
        if (!$status['available'] || $status['legacy_pending'] > 0) return $result;
        if (!is_file($directory . '/jobs.db')) { $result['timingKnown'] = true; $result['dueNow'] = false; return $result; }
        $db = self::database($directory . '/jobs.db');
        try {
            if (!self::columns($db, 'jobs', ['status','created','next_attempt'])) return $result;
            $rows = $db->query("SELECT created,next_attempt FROM jobs WHERE status='pending' LIMIT 100001");
            $oldest = null; $next = null; $count = 0;
            try { while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
                if (++$count > 100000 || !is_string($row['created']) || strlen($row['created']) > 64
                    || !is_int($row['next_attempt']) || $row['next_attempt'] < 0) throw new RuntimeException('queue_timing');
                $created = self::parseDate($row['created']);
                if ($created === null) throw new RuntimeException('queue_timing');
                $oldest = $oldest === null ? $created : min($oldest, $created);
                $due = $row['next_attempt'] ?: $now;
                $next = $next === null ? $due : min($next, $due);
            } } finally { $rows->finalize(); }
            $result['oldestPendingAt'] = self::iso($oldest);
            $result['oldestPendingAgeSeconds'] = $oldest === null ? null : max(0, $now - $oldest);
            $result['nextDueAt'] = self::iso($next);
            $result['dueNow'] = $next !== null && $next <= $now;
            $result['timingKnown'] = true;
            return $result;
        } catch (Throwable $ignored) { return $result; }
        finally { $db->close(); }
    }

    private static function cache(string $directory): array
    {
        if (!file_exists($directory . '/data.db')) return ['state' => 'never_observed', 'lastFullRefreshAt' => null];
        $db = self::database($directory . '/data.db');
        try {
            if (!self::columns($db, 'config', ['key','value'])) throw new RuntimeException('cache_schema');
            $value = $db->querySingle("SELECT value FROM config WHERE key='last_cache' LIMIT 1");
            if ($value === null || $value === '' || $value === '2020-01-01') return ['state' => 'never_observed', 'lastFullRefreshAt' => null];
            $time = is_string($value) ? self::parseDate($value) : null;
            if ($time === null) throw new RuntimeException('cache_timestamp');
            return ['state' => 'observed', 'lastFullRefreshAt' => self::iso($time)];
        } finally { $db->close(); }
    }

    private static function maintenance(string $directory, int $now): array
    {
        if (!file_exists($directory . '/jobs.db')) return ['state' => 'not_recorded', 'active' => false];
        $db = self::database($directory . '/jobs.db');
        try {
            if (!self::columns($db, 'queue_meta', ['key','value'])) return ['state' => 'unknown', 'active' => null];
            $value = $db->querySingle("SELECT value FROM queue_meta WHERE key='maintenance' LIMIT 1");
            if ($value === null) return ['state' => 'not_recorded', 'active' => false];
            if (!is_string($value) || strlen($value) > 1024) throw new RuntimeException('maintenance_schema');
            $guard = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($guard) || !is_int($guard['lease'] ?? null)) throw new RuntimeException('maintenance_schema');
            // A recorded lease is a hint. The panel never recovers owners or probes their locks.
            return $guard['lease'] >= $now ? ['state' => 'recorded', 'active' => true] : ['state' => 'unknown', 'active' => null];
        } finally { $db->close(); }
    }

    private static function database(string $path): SQLite3
    {
        if (is_link($path) || !is_file($path)) throw new RuntimeException('database_path');
        $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
        $db->enableExceptions(true); $db->busyTimeout(100);
        return $db;
    }

    private static function columns(SQLite3 $db, string $table, array $required): bool
    {
        if (!in_array($table, ['jobs','queue_meta','config'], true)) throw new RuntimeException('table');
        $type = $db->querySingle("SELECT type FROM sqlite_master WHERE name='" . $table . "'");
        if ($type === null) return false;
        if ($type !== 'table') throw new RuntimeException('schema');
        $rows = $db->query('PRAGMA table_info("' . $table . '")'); $found = [];
        try { while ($row = $rows->fetchArray(SQLITE3_ASSOC)) $found[] = $row['name']; }
        finally { $rows->finalize(); }
        return !array_diff($required, $found);
    }

    private static function assertDirectory(string $directory): void
    {
        if ($directory === '' || is_link($directory) || (file_exists($directory) && !is_dir($directory))) throw new RuntimeException('data_path');
    }

    private static function parseDate(string $value): ?int
    {
        if (strlen($value) > 64 || !preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})?)?$/D', $value)) return null;
        try {
            $parsed = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors && ($errors['warning_count'] || $errors['error_count'])) return null;
            $time = $parsed->getTimestamp();
            return self::timestamp($time) ? $time : null;
        } catch (Throwable $ignored) { return null; }
    }

    private static function timestamp($value): bool { return is_int($value) && $value >= 946684800 && $value <= 4102444799; }
    private static function iso(?int $timestamp): ?string { return $timestamp === null ? null : gmdate('Y-m-d\TH:i:s\Z', $timestamp); }
}
