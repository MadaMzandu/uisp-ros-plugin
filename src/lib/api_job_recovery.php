<?php

/** Inspect cancellation debt without constructing a queue or contacting RouterOS. */
final class ApiJobRecovery
{
    private const LIMIT = 100;
    private const TTL = 600;
    private string $jobsPath;
    private string $dataPath;
    private string $legacyPath;
    private $request;
    private $clock;
    private $backup;

    public function __construct(?callable $request = null, string $jobsPath = 'data/jobs.db',
        string $dataPath = 'data/data.db', string $legacyPath = 'data/queue.json',
        ?callable $clock = null, ?callable $backup = null)
    {
        $this->request = $request; $this->jobsPath = $jobsPath; $this->dataPath = $dataPath;
        $this->legacyPath = $legacyPath; $this->clock = $clock; $this->backup = $backup;
    }

    public function listing(): array
    {
        $database = null;
        try {
            if (!file_exists($this->jobsPath) && !is_link($this->jobsPath)) {
                return ['available' => true, 'groups' => [], 'total' => 0, 'has_more' => false,
                    'checked_at' => $this->timestamp(), 'reason' => null];
            }
            $database = $this->open(false); $database->exec('BEGIN'); $this->schema($database);
            $total = (int) $database->querySingle("SELECT count(DISTINCT source_job_id) FROM cancellation_cleanups c
                WHERE NOT EXISTS(SELECT 1 FROM jobs j WHERE j.id=c.source_job_id AND j.status IN ('pending','running'))");
            $sources = $this->query($database, "SELECT DISTINCT source_job_id FROM cancellation_cleanups c
                WHERE NOT EXISTS(SELECT 1 FROM jobs j WHERE j.id=c.source_job_id AND j.status IN ('pending','running'))
                ORDER BY source_job_id LIMIT " . self::LIMIT);
            $groups = [];
            foreach ($sources as $source) $groups[] = $this->group($database, (int) $source['source_job_id'])['public'];
            return ['available' => true, 'groups' => $groups, 'total' => $total,
                'has_more' => $total > count($groups), 'checked_at' => $this->timestamp(), 'reason' => null];
        } catch (Throwable $ignored) {
            return ['available' => false, 'groups' => [], 'total' => null, 'has_more' => false,
                'checked_at' => null, 'reason' => 'unavailable'];
        } finally { if ($database !== null) $database->close(); }
    }

    /** A preview only observes UISP and local evidence. It never retires recovery ownership. */
    public function preview(int $sourceJobId): array
    {
        if ($sourceJobId < 1) throw new InvalidArgumentException('invalid_recovery_selection');
        $database = null; $candidate = null; $serviceId = null; $reason = null; $record = null;
        try {
            $database = $this->open(false); $database->exec('BEGIN'); $this->schema($database);
            $group = $this->group($database, $sourceJobId); $serviceId = $group['public']['service_id'];
            $record = $this->verify($database, $group);
            $candidate = $record['candidate'];
        } catch (Throwable $error) {
            $allowed = ['queue_busy','current_ownership_unknown','different_resources','crm_unavailable',
                'crm_identity_changed','crm_status_changed','source_state_changed','legacy_queue_present'];
            $reason = in_array($error->getMessage(), $allowed, true) ? $error->getMessage() : 'unavailable';
        } finally { if ($database !== null) $database->close(); }
        $token = null; $expires = null;
        if ($record !== null) {
            $this->pruneTokens(); $token = bin2hex(random_bytes(32)); $expires = (int) $this->now() + self::TTL;
            $this->writeToken($token, ['expires' => $expires, 'queue' => $this->identity(),
                'source_job_id' => $sourceJobId, 'local_fingerprint' => $record['local_fingerprint'],
                'crm_fingerprint' => $record['crm_fingerprint']]);
        }
        return ['previewToken' => $token, 'expiresAt' => $expires === null ? null : gmdate('Y-m-d\TH:i:s\Z', $expires),
            'candidate' => $candidate, 'blocked' => $reason === null ? [] : [['source_job_id' => $sourceJobId,
                'service_id' => $serviceId, 'reason' => $reason]], 'checked_at' => $this->timestamp()];
    }

    /** Consolidate duplicate debt only; confirmed applied ownership and all jobs remain unchanged. */
    public function apply(string $token): array
    {
        $tokenPath = $this->tokenPath($token); $stream = null; $database = null; $locks = []; $transaction = false;
        try {
            if (is_link($tokenPath) || !is_file($tokenPath) || filesize($tokenPath) > 4096) throw new InvalidArgumentException('invalid_recovery_token');
            $stream = @fopen($tokenPath, 'rb');
            if (!$stream || !flock($stream, LOCK_EX | LOCK_NB)) throw new InvalidArgumentException('invalid_recovery_token');
            $state = json_decode((string) stream_get_contents($stream), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($state) || !is_int($state['expires'] ?? null) || !is_int($state['source_job_id'] ?? null)
                || $state['source_job_id'] < 1 || ($state['queue'] ?? null) !== $this->identity()
                || !is_string($state['local_fingerprint'] ?? null) || !is_string($state['crm_fingerprint'] ?? null)) {
                throw new InvalidArgumentException('invalid_recovery_token');
            }
            if ($state['expires'] <= $this->now()) throw new InvalidArgumentException('recovery_preview_expired');
            // Consuming the review prevents ambiguous client retries from repeating a mutation.
            flock($stream, LOCK_UN); fclose($stream); $stream = null;
            if (!@unlink($tokenPath)) throw new InvalidArgumentException('invalid_recovery_token');
            $locks = $this->locks(); $database = $this->open(true); $this->schema($database);
            $database->exec('BEGIN IMMEDIATE'); $transaction = true;
            $group = $this->group($database, $state['source_job_id']);
            $record = $this->verify($database, $group);
            if (!hash_equals($state['local_fingerprint'], $record['local_fingerprint'])
                || !hash_equals($state['crm_fingerprint'], $record['crm_fingerprint'])) throw new InvalidArgumentException('recovery_state_changed');
            if ($state['expires'] <= $this->now()) throw new InvalidArgumentException('recovery_preview_expired');
            $backupName = $this->createBackup();
            foreach ($group['rows'] as $row) {
                $this->execute($database, 'DELETE FROM cancellation_cleanups WHERE id=? AND source_job_id=? AND service_id=? AND payload=? AND identity=?',
                    [$row['id'], $row['source_job_id'], $row['service_id'], $row['payload'], $row['identity']]);
                if ($database->changes() !== 1) throw new InvalidArgumentException('recovery_state_changed');
            }
            $database->exec('COMMIT'); $transaction = false;
            return ['resolved' => [['source_job_id' => $group['public']['source_job_id'],
                'service_id' => $group['public']['service_id'], 'snapshot_count' => count($group['rows'])]],
                'backup_name' => $backupName, 'checked_at' => $this->timestamp(), 'router_changes' => false, 'crm_changes' => false];
        } finally {
            if ($stream !== null && is_resource($stream)) { flock($stream, LOCK_UN); fclose($stream); }
            if ($database !== null) { if ($transaction) $database->exec('ROLLBACK'); $database->close(); }
            foreach (array_reverse($locks) as $lock) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    public static function backupName($name): bool
    {
        return is_string($name) && preg_match('/^jobs-recovery-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{32}\.sqlite$/D', $name) === 1;
    }

    public static function download(string $name, string $directory = 'data'): void
    {
        if (!self::backupName($name)) throw new InvalidArgumentException('invalid_backup_name');
        $path = $directory . DIRECTORY_SEPARATOR . $name; self::validateBackup($path);
        $stream = @fopen($path, 'rb'); if (!$stream) throw new RuntimeException('backup_download_failed');
        try {
            header('Content-Type: application/octet-stream'); header('Content-Disposition: attachment; filename="' . $name . '"');
            header('X-Content-Type-Options: nosniff'); header('Cache-Control: no-store');
            header('Content-Length: ' . fstat($stream)['size']); fpassthru($stream);
        } finally { fclose($stream); }
    }

    public static function listBackups(string $directory = 'data'): array
    {
        if (!is_dir($directory)) return [];
        $paths = glob($directory . DIRECTORY_SEPARATOR . 'jobs-recovery-*.sqlite') ?: []; rsort($paths, SORT_STRING); $rows = [];
        foreach (array_slice($paths, 0, 20) as $path) {
            $name = basename($path); if (!self::backupName($name) || is_link($path)) continue;
            try { self::validateBackup($path); } catch (Throwable $ignored) { continue; }
            $date = filemtime($path); $rows[] = ['id' => $name, 'name' => $name, 'date' => $date === false ? null : date('c', $date)];
        }
        return $rows;
    }

    private static function validateBackup(string $path): void
    {
        if (is_link($path) || !is_file($path) || filesize($path) < 16
            || file_get_contents($path, false, null, 0, 16) !== "SQLite format 3\0") throw new InvalidArgumentException('invalid_sqlite_backup');
        $database = new SQLite3($path, SQLITE3_OPEN_READONLY); $database->enableExceptions(true);
        try {
            if ($database->querySingle('PRAGMA integrity_check') !== 'ok') throw new InvalidArgumentException('backup_integrity_failed');
            (new self(null, $path))->schema($database);
        } finally { $database->close(); }
    }

    private function group(SQLite3 $database, int $source): array
    {
        $rows = $this->query($database, 'SELECT * FROM cancellation_cleanups WHERE source_job_id=? ORDER BY id LIMIT ' . (self::LIMIT + 1), [$source]);
        if (!$rows || count($rows) > self::LIMIT) throw new InvalidArgumentException('source_state_changed');
        $sid = self::id($rows[0]['service_id']); if ($sid === null) throw new InvalidArgumentException('source_state_changed');
        $jobs = $this->query($database, 'SELECT * FROM jobs WHERE id=?', [$source]); $job = $jobs[0] ?? null;
        $clientId = null; $clientName = null; $devices = [];
        foreach ($rows as $row) {
            if (self::id($row['id']) === null || (int) $row['source_job_id'] !== $source
                || self::id($row['service_id']) !== $sid || !is_string($row['identity'])
                || !in_array($row['slot'], ['cleanup','applied','commands'], true)
                || self::id($row['source_version']) === null) throw new InvalidArgumentException('source_state_changed');
            $payload = $this->payload($row['payload']);
            if (self::id($payload['id'] ?? null) !== $sid) throw new InvalidArgumentException('source_state_changed');
            $cid = self::id($payload['clientId'] ?? null);
            if ($clientId !== null && $cid !== $clientId) throw new InvalidArgumentException('source_state_changed');
            $clientId = $cid;
            $name = $this->clientName($payload); if ($name !== null) $clientName = $name;
            $did = self::id($payload['device'] ?? null); if ($did !== null) $devices[$did] = $did;
        }
        $public = ['source_job_id' => $source, 'job_id' => 'Q' . $source, 'service_id' => $sid,
            'client_id' => $clientId, 'client_name' => $clientName, 'device_ids' => array_values($devices),
            'snapshot_count' => count($rows), 'reason' => 'cancelled_resources',
            'created_at' => is_string($job['updated'] ?? null) ? $this->text($job['updated'], 64) : null];
        return ['public' => $public, 'rows' => $rows, 'job' => $job];
    }

    private function verify(SQLite3 $database, array $group): array
    {
        if (is_link($this->legacyPath) || (file_exists($this->legacyPath) && (!is_file($this->legacyPath)
            || filesize($this->legacyPath) > 1048576))) throw new InvalidArgumentException('legacy_queue_present');
        if (is_file($this->legacyPath)) {
            $raw = file_get_contents($this->legacyPath);
            if ($raw === false || (trim($raw) !== '' && json_decode($raw, true, 64, JSON_THROW_ON_ERROR) !== []))
                throw new InvalidArgumentException('legacy_queue_present');
        }
        if ($database->querySingle("SELECT count(*) FROM jobs WHERE status='running'")
            || $database->querySingle('SELECT count(*) FROM service_event_locks')
            || $database->querySingle("SELECT count(*) FROM queue_meta WHERE key='maintenance'")) throw new InvalidArgumentException('queue_busy');
        // Without the one-time migration marker a future constructor would seed
        // the same cancelled rows again. Do not hide an uninitialized queue.
        if (!$database->querySingle("SELECT count(*) FROM queue_meta WHERE key='cancellation_cleanups_seeded_v1'"))
            throw new InvalidArgumentException('source_state_changed');
        $sid = $group['public']['service_id']; $source = $group['public']['source_job_id']; $job = $group['job'];
        if (!$job || (int) $job['service_id'] !== $sid || !in_array($job['status'], ['cancelled','superseded'], true))
            throw new InvalidArgumentException('source_state_changed');
        if ($this->query($database, "SELECT id FROM jobs WHERE service_id=? AND status IN ('pending','running') LIMIT 1", [$sid]))
            throw new InvalidArgumentException('queue_busy');
        $versions = $this->query($database, 'SELECT * FROM service_versions WHERE service_id=?', [$sid]);
        $version = $versions[0] ?? null;
        if (!$version || !is_string($version['applied_payload'] ?? null) || !is_string($version['applied_identity'] ?? null))
            throw new InvalidArgumentException('current_ownership_unknown');
        $applied = $this->payload($version['applied_payload']); $clientId = self::id($applied['clientId'] ?? null);
        if (self::id($applied['id'] ?? null) !== $sid || $clientId === null || self::status($applied['status'] ?? null) === null
            || !hash_equals($version['applied_identity'], $this->resourceIdentity($applied)))
            throw new InvalidArgumentException('current_ownership_unknown');
        $projection = $this->resourceProjection($applied);
        $sourceSnapshot = $this->payload($job['payload']);
        if (self::id($sourceSnapshot['id'] ?? null) !== $sid || self::id($sourceSnapshot['clientId'] ?? null) !== $clientId
            || !in_array($sourceSnapshot['action'] ?? 'update', ['update','queues_set'], true)
            || array_key_exists('_delete_frozen', $sourceSnapshot)) throw new InvalidArgumentException('different_resources');
        if (isset($sourceSnapshot['_commands']) && $this->resourceProjection($sourceSnapshot) !== $projection)
            throw new InvalidArgumentException('different_resources');
        if (isset($sourceSnapshot['_cleanup']) && empty($job['cleanup_done'])
            && (!is_array($sourceSnapshot['_cleanup']) || $this->resourceProjection($sourceSnapshot['_cleanup']) !== $projection))
            throw new InvalidArgumentException('different_resources');
        if (empty($job['prior_cleared']) && isset($sourceSnapshot['_prior_network'])) {
            if (!is_array($sourceSnapshot['_prior_network'])) throw new InvalidArgumentException('different_resources');
            $covered = $this->addresses($applied);
            foreach ($sourceSnapshot['_prior_network'] as $field => $value) {
                if (!in_array($field, ['address','address6'], true)) throw new InvalidArgumentException('different_resources');
                if ($value !== null && $value !== '' && (!is_string($value) || !in_array($value, $covered[$field], true)))
                    throw new InvalidArgumentException('different_resources');
            }
        }
        foreach ($group['rows'] as $row) {
            $snapshot = $this->payload($row['payload']);
            if ((int) $row['source_version'] !== (int) $job['version'] || $clientId !== self::id($snapshot['clientId'] ?? null)
                || !hash_equals($row['identity'], $this->resourceIdentity($snapshot))
                || !hash_equals($version['applied_identity'], $row['identity'])
                || $projection !== $this->resourceProjection($snapshot)) throw new InvalidArgumentException('different_resources');
        }
        $service = $this->get('clients/services/' . $sid);
        if (!is_array($service) && !is_object($service)) throw new InvalidArgumentException('crm_unavailable');
        $service = (array) $service;
        if (self::id($service['id'] ?? null) !== $sid || self::id($service['clientId'] ?? null) !== $clientId)
            throw new InvalidArgumentException('crm_identity_changed');
        if (self::status($service['status'] ?? null) === null || self::status($service['status']) !== self::status($applied['status']))
            throw new InvalidArgumentException('crm_status_changed');
        $client = $this->get('clients/' . $clientId);
        if (!is_array($client) && !is_object($client)) throw new InvalidArgumentException('crm_unavailable');
        $client = (array) $client;
        if (self::id($client['id'] ?? null) !== $clientId) throw new InvalidArgumentException('crm_identity_changed');
        $archived = in_array($client['isArchived'] ?? null, [true, 1, '1'], true) ? true
            : (in_array($client['isArchived'] ?? null, [false, 0, '0'], true) ? false : null);
        $candidate = $group['public'];
        $candidate['client_id'] = $clientId; $candidate['client_name'] = $this->clientName($client) ?? $candidate['client_name'];
        $candidate['crm_status'] = self::status($service['status']); $candidate['client_archived'] = $archived;
        $candidate['checked_at'] = $this->timestamp(); $candidate['resolution'] = 'redundant_current_resources';
        return ['candidate' => $candidate, 'local_fingerprint' => hash('sha256', self::canonical([
            'rows' => $group['rows'], 'job' => $job, 'version' => $version])),
            'crm_fingerprint' => hash('sha256', self::canonical(['service' => $service, 'client' => $client]))];
    }

    /** Retain every nonsecret resource value; discard only identity-free execution/display metadata. */
    private function resourceProjection(array $snapshot): string
    {
        if (self::id($snapshot['device'] ?? null) === null || !is_array($snapshot['_device'] ?? null)
            || !is_string($snapshot['_device']['ip'] ?? null) || $snapshot['_device']['ip'] === ''
            || !is_array($snapshot['_commands'] ?? null) || !$snapshot['_commands']
            || count($snapshot['_commands']) > 1000 || array_key_exists('_cleanup', $snapshot)
            || array_key_exists('_delete_frozen', $snapshot)
            || !in_array($snapshot['action'] ?? 'update', ['update','queues_set'], true))
            throw new InvalidArgumentException('current_ownership_unknown');
        // Removing a duplicate requires equivalent resource configuration, not merely account identity.
        $accounts = 0;
        foreach ($snapshot['_commands'] as $command) {
            if (!is_array($command) || !is_string($command['path'] ?? null)
                || !in_array(trim($command['path'], '/'), ['queue/simple','ppp/profile','ppp/secret','ppp/active',
                    'ip/hotspot/user/profile','ip/hotspot/user','ip/hotspot/active','ip/dhcp-server/lease','ipv6/dhcp-server/binding','ipv6/pool'], true)
                || !in_array($command['action'] ?? 'set', ['set','remove'], true))
                throw new InvalidArgumentException('current_ownership_unknown');
            if (in_array(trim($command['path'], '/'), ['ppp/secret','ip/hotspot/user','ip/dhcp-server/lease','ipv6/dhcp-server/binding'], true)
                && ($command['action'] ?? 'set') !== 'remove') {
                if ((trim($command['path'], '/') === 'ppp/secret' || trim($command['path'], '/') === 'ip/hotspot/user')
                    && (!is_string($command['name'] ?? null) || $command['name'] === '' || $command['name'] !== ($snapshot['username'] ?? null)))
                    throw new InvalidArgumentException('current_ownership_unknown');
                $accounts++;
            }
        }
        if ($accounts < 1) throw new InvalidArgumentException('current_ownership_unknown');
        foreach (['firstName','lastName','company','last','created','batch',
            '_force_reconnect','_event_id','_clear_terminal','_terminal','action'] as $key) unset($snapshot[$key]);
        $scrub = static function (array $value) use (&$scrub): array {
            foreach ($value as $key => $entry) {
                if (is_string($key) && (preg_match('/pass|token|secret|credential|authorization/i', $key)
                    || in_array($key, ['batch','_reconnect_required'], true))) unset($value[$key]);
                elseif (is_array($entry)) $value[$key] = $scrub($entry);
            }
            return $value;
        };
        $projection = self::canonical($scrub($snapshot)); $scrub = null; return $projection;
    }

    private function addresses(array $snapshot): array
    {
        $result = ['address' => [], 'address6' => []];
        $values = [$snapshot['address'] ?? null, $snapshot['address6'] ?? null,
            $snapshot['network']['address'] ?? null, $snapshot['network']['address6'] ?? null];
        foreach ($snapshot['_commands'] ?? [] as $command) {
            foreach (['remote-address','remote-ipv6-prefix'] as $key) $values[] = $command[$key] ?? null;
            if (in_array(trim($command['path'], '/'), ['ip/dhcp-server/lease','ipv6/dhcp-server/binding','ip/hotspot/user'], true)) $values[] = $command['address'] ?? null;
            if (trim($command['path'], '/') === 'queue/simple' && ($command['comment'] ?? '') !== 'do not delete'
                && !str_ends_with($command['name'] ?? '', '-parent')) $values[] = $command['target'] ?? null;
        }
        foreach ($values as $value) if (is_string($value) && $value !== '') foreach (explode(',', $value) as $address) {
            $address = trim($address); $field = strpos($address, ':') === false ? 'address' : 'address6'; $result[$field][] = $address;
        }
        return $result;
    }

    private function resourceIdentity(array $snapshot): string
    {
        $device = $snapshot['_device'] ?? []; if (!is_array($device)) throw new InvalidArgumentException('current_ownership_unknown');
        return hash('sha256', self::canonical(['device' => $snapshot['device'] ?? null, 'ip' => $device['ip'] ?? null,
            'port' => $device['port'] ?? null, 'username' => $snapshot['username'] ?? null, 'mac' => $snapshot['mac'] ?? null,
            'hotspot' => $snapshot['hotspot'] ?? null, 'duid' => $snapshot['duid'] ?? null, 'iaid' => $snapshot['iaid'] ?? null]));
    }

    private function schema(SQLite3 $database): void
    {
        foreach (['jobs' => ['id','service_id','status','version','payload'],
            'cancellation_cleanups' => ['id','source_job_id','service_id','source_version','slot','payload','identity'],
            'service_versions' => ['service_id','applied_payload','applied_identity'],
            'service_event_locks' => ['service_id'], 'queue_meta' => ['key']] as $table => $required) {
            $details = $this->query($database, 'PRAGMA table_info("' . $table . '")');
            $columns = array_column($details, 'name'); $primary = [];
            foreach ($details as $column) if ((int) $column['pk'] > 0) $primary[(int) $column['pk']] = $column['name'];
            ksort($primary); $expected = in_array($table, ['jobs','cancellation_cleanups'], true) ? 'id'
                : ($table === 'queue_meta' ? 'key' : 'service_id');
            if (array_diff($required, $columns)) throw new InvalidArgumentException('recovery_schema_unsupported');
            if (array_values($primary) !== [$expected]) throw new InvalidArgumentException('recovery_schema_unsupported');
        }
        if ($database->querySingle("SELECT count(*) FROM jobs WHERE typeof(id)<>'integer' OR id<1 OR typeof(service_id)<>'integer'
            OR service_id<1 OR typeof(version)<>'integer' OR version<1 OR status IS NULL
            OR status NOT IN ('pending','running','succeeded','cancelled','superseded','cleaned')")) throw new InvalidArgumentException('recovery_schema_unsupported');
        if ($database->querySingle("SELECT count(*) FROM sqlite_master WHERE type IN ('trigger','view')
            AND (name IN ('jobs','cancellation_cleanups','service_versions','service_event_locks','queue_meta')
              OR tbl_name IN ('jobs','cancellation_cleanups','service_versions','service_event_locks','queue_meta'))"))
            throw new InvalidArgumentException('recovery_schema_unsupported');
    }

    private function get(string $path)
    {
        try {
            if ($this->request !== null) return ($this->request)($path);
            require_once __DIR__ . '/api_ucrm.php'; return (new ApiUcrm())->get($path);
        } catch (Throwable $error) { throw new InvalidArgumentException('crm_unavailable', 0, $error); }
    }
    private function payload($raw): array
    {
        if (!is_string($raw) || strlen($raw) > 1048576) throw new InvalidArgumentException('source_state_changed');
        $value = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new InvalidArgumentException('source_state_changed'); return $value;
    }
    private function clientName(array $client): ?string
    {
        $company = $this->text($client['companyName'] ?? $client['company'] ?? null);
        if ($company !== null && $company !== '') return $company;
        $first = $this->text($client['firstName'] ?? null); $last = $this->text($client['lastName'] ?? null);
        $name = trim(($first ?? '') . ' ' . ($last ?? '')); return $name === '' ? null : $name;
    }
    private function text($value, int $limit = 200): ?string
    {
        return is_string($value) && strlen($value) <= $limit && !preg_match('/[\x00-\x1f\x7f]/', $value)
            && preg_match('//u', $value) === 1 ? $value : null;
    }
    private function open(bool $write): SQLite3
    {
        if (!is_file($this->jobsPath) || is_link($this->jobsPath)) throw new InvalidArgumentException('recovery_database_missing');
        $database = new SQLite3($this->jobsPath, $write ? SQLITE3_OPEN_READWRITE : SQLITE3_OPEN_READONLY);
        $database->enableExceptions(true); $database->busyTimeout(1000); return $database;
    }
    private function locks(): array
    {
        $identity = realpath($this->jobsPath) ?: (realpath(dirname($this->jobsPath)) ?: dirname($this->jobsPath)) . DIRECTORY_SEPARATOR . basename($this->jobsPath);
        if (PHP_OS_FAMILY === 'Windows') $identity = strtolower($identity);
        $directory = dirname($identity) . DIRECTORY_SEPARATOR . '.uisp-queue-locks-' . hash('sha256', $identity);
        if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory))) throw new InvalidArgumentException('queue_busy');
        $paths = [$directory . DIRECTORY_SEPARATOR . 'maintenance.lock', dirname($this->dataPath) . DIRECTORY_SEPARATOR . 'ip-sync.lock',
            dirname($this->dataPath) . DIRECTORY_SEPARATOR . 'setup.lock', dirname($this->dataPath) . DIRECTORY_SEPARATOR . 'backup.lock'];
        $locks = [];
        try {
            foreach ($paths as $path) {
                if (is_link($path) || (file_exists($path) && !is_file($path))) throw new InvalidArgumentException('queue_busy');
                $lock = @fopen($path, 'c+b'); if (!$lock) throw new InvalidArgumentException('queue_busy');
                if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); throw new InvalidArgumentException('queue_busy'); } $locks[] = $lock;
            }
            return $locks;
        } catch (Throwable $error) { foreach (array_reverse($locks) as $lock) { flock($lock, LOCK_UN); fclose($lock); } throw $error; }
    }
    private function createBackup(): string
    {
        $name = 'jobs-recovery-' . gmdate('Ymd\THis\Z', (int) $this->now()) . '-' . bin2hex(random_bytes(16)) . '.sqlite';
        $path = dirname($this->jobsPath) . DIRECTORY_SEPARATOR . $name; $temporary = $path . '.tmp'; $reader = null; $destination = null;
        try {
            $stream = @fopen($temporary, 'x'); if (!$stream) throw new RuntimeException('backup_failed'); fclose($stream);
            if (!chmod($temporary, 0600)) throw new RuntimeException('backup_failed');
            if ($this->backup !== null) {
                if (($this->backup)($this->jobsPath, $temporary) !== true) throw new RuntimeException('backup_failed');
            } else {
                $reader = $this->open(false); $destination = new SQLite3($temporary, SQLITE3_OPEN_READWRITE); $destination->enableExceptions(true);
                if (!$reader->backup($destination)) throw new RuntimeException('backup_failed');
                $destination->close(); $destination = null; $reader->close(); $reader = null;
            }
            if (!chmod($temporary, 0600)) throw new RuntimeException('backup_failed');
            $check = new SQLite3($temporary, SQLITE3_OPEN_READONLY); $check->enableExceptions(true);
            try { if ($check->querySingle('PRAGMA integrity_check') !== 'ok') throw new RuntimeException('backup_failed'); $this->schema($check); }
            finally { $check->close(); }
            if (!rename($temporary, $path) || !chmod($path, 0600)) throw new RuntimeException('backup_failed'); return $name;
        } catch (Throwable $error) { throw new InvalidArgumentException('recovery_backup_failed', 0, $error); }
        finally { if ($destination !== null) $destination->close(); if ($reader !== null) $reader->close(); if (is_file($temporary)) @unlink($temporary); }
    }
    private function pruneTokens(): void
    {
        $active = 0;
        foreach (glob(dirname($this->jobsPath) . DIRECTORY_SEPARATOR . 'jobs-recovery-preview-*.json') ?: [] as $path) {
            if (is_link($path) || !preg_match('/^jobs-recovery-preview-[a-f0-9]{64}\.json$/D', basename($path))) continue;
            $date = filemtime($path); if ($date !== false && $date + self::TTL + 60 < $this->now()) { @unlink($path); continue; } $active++;
        }
        if ($active >= 64) throw new InvalidArgumentException('recovery_preview_limit');
    }
    private function writeToken(string $token, array $state): void
    {
        $path = $this->tokenPath($token); $stream = @fopen($path, 'x'); if (!$stream) throw new InvalidArgumentException('recovery_preview_write_failed');
        try {
            if (!chmod($path, 0600)) throw new InvalidArgumentException('recovery_preview_write_failed');
            $json = json_encode($state, JSON_THROW_ON_ERROR);
            if (fwrite($stream, $json) !== strlen($json) || !fflush($stream)) throw new InvalidArgumentException('recovery_preview_write_failed');
        } catch (Throwable $error) { fclose($stream); @unlink($path); throw $error; } fclose($stream);
    }
    private function tokenPath(string $token): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new InvalidArgumentException('invalid_recovery_token');
        return dirname($this->jobsPath) . DIRECTORY_SEPARATOR . 'jobs-recovery-preview-' . $token . '.json';
    }
    private function identity(): string { return hash('sha256', (string) realpath($this->jobsPath)); }
    private function now(): float { return $this->clock === null ? microtime(true) : (float) ($this->clock)(); }
    private function timestamp(): string { return gmdate('Y-m-d\TH:i:s\Z', (int) $this->now()); }
    private static function id($value): ?int
    {
        if (!is_int($value) && !is_string($value)) return null;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]); return $id === false ? null : $id;
    }
    private static function status($value): ?int
    {
        if (!is_int($value) && !is_string($value)) return null;
        $status = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 8]]); return $status === false ? null : $status;
    }
    private static function canonical(array $value): string
    {
        ksort($value); foreach ($value as $key => $entry) if (is_array($entry)) $value[$key] = json_decode(self::canonical($entry), true, 64, JSON_THROW_ON_ERROR);
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    private function query(SQLite3 $database, string $sql, array $parameters = []): array
    {
        $statement = $database->prepare($sql);
        try {
            foreach (array_values($parameters) as $index => $value) $statement->bindValue($index + 1, $value,
                is_int($value) ? SQLITE3_INTEGER : ($value === null ? SQLITE3_NULL : SQLITE3_TEXT));
            $result = $statement->execute(); $rows = [];
            try { while ($row = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $row; } finally { $result->finalize(); } return $rows;
        } finally { $statement->close(); }
    }
    private function execute(SQLite3 $database, string $sql, array $parameters): void
    {
        $statement = $database->prepare($sql);
        try {
            foreach (array_values($parameters) as $index => $value) $statement->bindValue($index + 1, $value,
                is_int($value) ? SQLITE3_INTEGER : ($value === null ? SQLITE3_NULL : SQLITE3_TEXT));
            $result = $statement->execute(); $result->finalize();
        } finally { $statement->close(); }
    }
}
