<?php

/** Deliberate local reservation repair; this class never provisions or writes to UISP. */
final class ApiNetworkCleanup
{
    public const MAX_SERVICES = 3;
    private const MAX_REQUESTS = 9;
    private const REQUEST_BUDGET = 12.0;
    private const PREVIEW_TTL = 600;
    private $request;
    private $clock;
    private $backup;
    private string $dataPath;
    private string $jobsPath;
    private string $legacyPath;

    public function __construct(?callable $request = null, string $dataPath = 'data/data.db',
        string $jobsPath = 'data/jobs.db', string $legacyPath = 'data/queue.json', ?callable $clock = null,
        ?callable $backup = null)
    {
        $this->request = $request;
        $this->dataPath = $dataPath;
        $this->jobsPath = $jobsPath;
        $this->legacyPath = $legacyPath;
        $this->clock = $clock;
        $this->backup = $backup;
    }

    public function preview(array $serviceIds): array
    {
        $ids = self::selection($serviceIds);
        $database = $this->openDatabase(false);
        try {
            $this->assertSafeSchema($database);
            $verified = $this->verify($database, $ids);
            $candidates = [];
            $blocked = $verified['blocked'];
            $records = [];
            foreach ($verified['records'] as $id => $record) {
                try { $this->assertQueueSafe([$id], $record['candidate']['witness_service_ids']); }
                catch (Throwable $error) {
                    $blocked[] = ['service_id' => (int) $id, 'reason' => 'queue_busy'];
                    continue;
                }
                $records[$id] = $record;
                $candidates[] = $record['candidate'];
            }
        } finally { $database->close(); }
        $expires = (int) $this->now() + self::PREVIEW_TTL;
        $token = null;
        if ($records) {
            $this->pruneTokens();
            $token = bin2hex(random_bytes(32));
            $path = $this->tokenPath($token);
            $state = ['expires' => $expires, 'database' => $this->databaseIdentity(), 'records' => $records];
            $stream = @fopen($path, 'x');
            if (!$stream) throw new InvalidArgumentException('cleanup_preview_write_failed');
            try {
                if (!chmod($path, 0600)) throw new InvalidArgumentException('cleanup_preview_write_failed');
                $json = json_encode($state, JSON_THROW_ON_ERROR);
                if (fwrite($stream, $json) !== strlen($json) || !fflush($stream)) {
                    throw new InvalidArgumentException('cleanup_preview_write_failed');
                }
            } catch (Throwable $error) {
                fclose($stream);
                @unlink($path);
                throw $error;
            }
            fclose($stream);
        }
        return ['previewToken' => $token, 'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', $expires),
            'candidates' => $candidates, 'blocked' => $blocked, 'api_requests' => $verified['api_requests']];
    }

    public function apply(string $token, array $serviceIds): array
    {
        $ids = self::selection($serviceIds);
        $tokenPath = $this->tokenPath($token);
        if (!is_file($tokenPath) || is_link($tokenPath) || filesize($tokenPath) > 65536) {
            throw new InvalidArgumentException('invalid_cleanup_token');
        }
        $state = json_decode((string) file_get_contents($tokenPath), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($state) || !is_int($state['expires'] ?? null) || !is_array($state['records'] ?? null)
            || ($state['database'] ?? '') !== $this->databaseIdentity()) {
            throw new InvalidArgumentException('invalid_cleanup_token');
        }
        if ($state['expires'] <= $this->now()) throw new InvalidArgumentException('cleanup_preview_expired');
        foreach ($ids as $id) {
            if (!isset($state['records'][$id])) throw new InvalidArgumentException('cleanup_selection_invalid');
        }
        $locks = $this->acquireMaintenance();
        $database = null;
        $transaction = false;
        $backupName = null;
        try {
            $database = $this->openDatabase(true);
            $this->assertSafeSchema($database);
            $witnesses = [];
            foreach ($ids as $id) {
                $record = $state['records'][$id];
                $this->assertSnapshot($database, $id, $record);
                foreach ($record['candidate']['witness_service_ids'] as $witness) $witnesses[$witness] = $witness;
            }
            $this->assertQueueSafe($ids, array_values($witnesses));
            // A preview is never evidence sufficient to delete. Recheck the service
            // and independently confirm each recorded peer with fresh CRM GETs.
            $verified = $this->verify($database, $ids, $state['records']);
            if ($verified['blocked'] || count($verified['records']) !== count($ids)) {
                throw new InvalidArgumentException('cleanup_verification_failed');
            }
            foreach ($ids as $id) $this->assertSnapshot($database, $id, $state['records'][$id]);
            $this->assertQueueSafe($ids, array_values($witnesses));
            if ($state['expires'] <= $this->now()) throw new InvalidArgumentException('cleanup_preview_expired');
            $database->exec('BEGIN IMMEDIATE');
            $transaction = true;
            foreach ($ids as $id) $this->assertSnapshot($database, $id, $state['records'][$id]);
            // A separate read connection sees the same committed pre-change pages,
            // including WAL data, while BEGIN IMMEDIATE excludes other DB writers.
            // Backing up the write connection itself would return SQLITE_LOCKED.
            $backupName = $this->createBackup();
            $network = $this->columns($database, 'network');
            $services = $this->columns($database, 'services');
            $clearedAssignments = [];
            foreach ($ids as $id) {
                $candidate = $verified['records'][$id]['candidate'];
                $stale = $candidate['reason'] === 'stale_address';
                $fields = $stale ? $candidate['fields'] : ['address', 'address6'];
                if ($stale) {
                    $assignments = [];
                    foreach ($fields as $field) if (isset($network[$field])) $assignments[] = '"' . $field . '"=NULL';
                    if ($assignments && isset($network['id'])) {
                        $this->execute($database, 'UPDATE network SET ' . implode(',', $assignments) . ' WHERE id=?', [$id]);
                    }
                } elseif (isset($network['id'])) $this->execute($database, 'DELETE FROM network WHERE id=?', [$id]);
                $legacy = [];
                foreach ($fields as $field) {
                    $legacyField = $field === 'address' ? 'address' : 'prefix6';
                    if (isset($services[$legacyField])) $legacy[] = '"' . $legacyField . '"=NULL';
                }
                if ($legacy) $this->execute($database, 'UPDATE services SET ' . implode(',', $legacy) . ' WHERE id=?', [$id]);
                $clearedAssignments[] = ['service_id' => $id, 'fields' => $fields, 'reason' => $candidate['reason']];
            }
            $remaining = $this->duplicateCount($database);
            $database->exec('COMMIT');
            $transaction = false;
            // Consume only a successfully applied preview; failed work remains
            // reviewable, but every retry must independently verify CRM again.
            @unlink($tokenPath);
            return ['removed_service_ids' => $ids, 'backup_name' => $backupName,
                'cleared_assignments' => $clearedAssignments, 'duplicate_groups' => $remaining, 'requires_reload' => true];
        } catch (Throwable $error) {
            if ($database !== null && $transaction) {
                try { $database->exec('ROLLBACK'); } catch (Throwable $ignored) {}
            }
            if ($error instanceof InvalidArgumentException) throw $error;
            throw new InvalidArgumentException('cleanup_failed', 0, $error);
        } finally {
            if ($database !== null) $database->close();
            foreach (array_reverse($locks) as $lock) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    /** Only this narrowly validated private snapshot family is downloadable here. */
    public static function backupName($name): bool
    {
        return is_string($name) && preg_match('/^network-cleanup-\d{8}T\d{6}Z-[a-f0-9]{32}\.sqlite$/D', $name) === 1;
    }

    public static function download(string $name, string $directory = 'data'): void
    {
        if (!self::backupName($name)) throw new InvalidArgumentException('invalid_backup_name');
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (is_link($path) || !is_file($path)) throw new InvalidArgumentException('invalid_sqlite_backup');
        self::validateBackup($path);
        $stream = fopen($path, 'rb');
        if (!$stream) throw new InvalidArgumentException('backup_download_failed');
        try {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
            header('Content-Length: ' . fstat($stream)['size']);
            fpassthru($stream);
        } finally { fclose($stream); }
    }

    public static function listBackups(string $directory = 'data'): array
    {
        if (!is_dir($directory)) return [];
        $paths = glob($directory . DIRECTORY_SEPARATOR . 'network-cleanup-*.sqlite') ?: [];
        rsort($paths, SORT_STRING);
        $rows = [];
        foreach (array_slice($paths, 0, 20) as $path) {
            $name = basename($path);
            if (!self::backupName($name) || is_link($path)) continue;
            try { self::validateBackup($path); } catch (Throwable $error) { continue; }
            $date = filemtime($path);
            $rows[] = ['id' => $name, 'name' => $name, 'date' => $date === false ? null : date('c', $date)];
        }
        return $rows;
    }

    private function verify(SQLite3 $database, array $ids, ?array $expected = null): array
    {
        $deadline = $this->now() + self::REQUEST_BUDGET;
        $apiRequests = 0;
        $results = [];
        $lookup = function (int $id) use (&$results, &$apiRequests, $deadline): array {
            if (isset($results[$id])) return $results[$id];
            if ($apiRequests >= self::MAX_REQUESTS || $this->now() >= $deadline) return ['kind' => 'deadline'];
            $apiRequests++;
            return $results[$id] = $this->liveService($id);
        };
        $catalogResult = null;
        $catalog = function () use (&$catalogResult, &$apiRequests, $deadline): array {
            if ($catalogResult !== null) return $catalogResult;
            if ($apiRequests >= self::MAX_REQUESTS || $this->now() >= $deadline) return ['kind' => 'deadline'];
            $apiRequests++;
            try { return $catalogResult = ['kind' => 'present', 'value' => $this->get('custom-attributes')]; }
            catch (Throwable $error) { return $catalogResult = ['kind' => 'unavailable']; }
        };
        $records = [];
        $blocked = [];
        foreach ($ids as $id) {
            $snapshot = $this->snapshot($database, $id);
            $groups = $this->groups($database, $snapshot);
            if (!$groups) { $blocked[] = ['service_id' => $id, 'reason' => 'not_in_conflict']; continue; }
            $live = $lookup($id);
            if (!in_array($live['kind'], ['absent', 'present'], true)) {
                $reason = $live['kind'] === 'deadline' ? 'deadline' : 'crm_unavailable';
                $blocked[] = ['service_id' => $id, 'reason' => $reason];
                continue;
            }
            $stale = $live['kind'] === 'present';
            $evidence = [];
            $mapping = null;
            $liveFingerprint = null;
            if ($stale) {
                // A CRM address edit is distinct from a deleted service. An
                // unknown/reassigned local identity cannot authorize a repair.
                $clientId = self::positiveId($snapshot['service']['clientId'] ?? null);
                if ($clientId === null || $clientId !== self::positiveId($live['service']['clientId'])) {
                    $blocked[] = ['service_id' => $id, 'reason' => 'identity_changed'];
                    continue;
                }
                require_once __DIR__ . '/api_network_address_evidence.php';
                $rawMapping = ApiNetworkAddressEvidence::mapping($database);
                $definitions = array_filter($rawMapping) ? $catalog() : ['kind' => 'present', 'value' => []];
                if ($definitions['kind'] === 'deadline') {
                    $blocked[] = ['service_id' => $id, 'reason' => 'deadline'];
                    continue;
                }
                $mapping = ApiNetworkAddressEvidence::validateMapping($rawMapping, $definitions['value'] ?? null);
                $addresses = ApiNetworkAddressEvidence::parse($live['service'], $mapping);
                $selectedGroups = [];
                $unknown = false;
                foreach ($groups as $group) {
                    $comparison = ApiNetworkAddressEvidence::compare($group['address'], $addresses[$group['field']], $group['field']);
                    if ($comparison === 'unknown') $unknown = true;
                    if (!in_array($comparison, ['removed', 'changed'], true)) continue;
                    $selectedGroups[] = $group;
                    $evidence[] = ['field' => $group['field'], 'saved_address' => $group['address'],
                        'current_address' => $addresses[$group['field']]['value'], 'state' => $comparison];
                }
                if (!$selectedGroups) {
                    $blocked[] = ['service_id' => $id, 'reason' => $unknown ? 'address_unknown' : 'service_exists'];
                    continue;
                }
                $groups = $selectedGroups;
                $liveFingerprint = hash('sha256', json_encode(['client_id' => $clientId,
                    'config' => ApiNetworkAddressEvidence::mappingFingerprint($rawMapping),
                    'mapping' => ApiNetworkAddressEvidence::mappingFingerprint($mapping), 'addresses' => $addresses], JSON_THROW_ON_ERROR));
            }
            // Applying cannot change from deletion to reconciliation, or adopt
            // a different CRM value/mapping after the operator reviewed it.
            if ($expected !== null && (($expected[$id]['candidate']['reason'] ?? null) !== ($stale ? 'stale_address' : 'crm_not_found')
                || ($stale && (!is_string($expected[$id]['live_fingerprint'] ?? null)
                    || !hash_equals($expected[$id]['live_fingerprint'], $liveFingerprint))))) {
                $blocked[] = ['service_id' => $id, 'reason' => 'local_changed'];
                continue;
            }
            $witnesses = [];
            $verifiedGroups = [];
            $reason = null;
            foreach ($groups as $group) {
                $peerIds = array_values(array_filter($group['peer_ids'], static function (int $peer) use ($id): bool { return $peer !== $id; }));
                if ($expected !== null) {
                    $match = null;
                    foreach ($expected[$id]['groups'] as $prior) {
                        if ($prior['field'] === $group['field'] && $prior['address'] === $group['address']) { $match = $prior; break; }
                    }
                    if ($match === null || !in_array($match['witness_id'], $peerIds, true)) { $reason = 'local_changed'; break; }
                    // Applying an approved snapshot cannot silently choose a new
                    // different witness if the reviewed one is now unavailable.
                    $peerIds = [$match['witness_id']];
                }
                $witness = null;
                foreach ($peerIds as $peer) {
                    $result = $lookup($peer);
                    if ($result['kind'] === 'present') {
                        if (!$stale) { $witness = $peer; break; }
                        $peerSnapshot = $this->snapshot($database, $peer);
                        $peerClientId = self::positiveId($peerSnapshot['service']['clientId'] ?? null);
                        if ($peerClientId !== null && $peerClientId !== self::positiveId($result['service']['clientId'])) continue;
                        $peerAddresses = ApiNetworkAddressEvidence::parse($result['service'], $mapping);
                        $oldAddress = ApiNetworkAddressEvidence::normalize($group['address'], $group['field']);
                        if ($oldAddress !== null && $peerAddresses[$group['field']]['known'] === true
                            && $peerAddresses[$group['field']]['value'] === $oldAddress) { $witness = $peer; break; }
                    }
                    if ($result['kind'] === 'deadline') { $reason = 'deadline'; break; }
                }
                if ($witness === null) { $reason = $reason ?? 'no_live_peer'; break; }
                $witnesses[$witness] = $witness;
                $verifiedGroups[] = ['field' => $group['field'], 'address' => $group['address'],
                    'witness_id' => $witness, 'witness_snapshot' => $this->snapshot($database, $witness)['fingerprint']];
            }
            if ($reason !== null) { $blocked[] = ['service_id' => $id, 'reason' => $reason]; continue; }
            $candidate = ['service_id' => $id, 'client_id' => self::positiveId($snapshot['service']['clientId'] ?? null),
                'address' => $snapshot['address'], 'address6' => $snapshot['address6'],
                'reason' => $stale ? 'stale_address' : 'crm_not_found', 'witness_service_ids' => array_values($witnesses)];
            if ($stale) {
                $candidate['fields'] = array_column($evidence, 'field');
                $candidate['evidence'] = $evidence;
            }
            $records[$id] = ['candidate' => $candidate, 'fingerprint' => $snapshot['fingerprint'], 'groups' => $verifiedGroups];
            if ($stale) {
                $records[$id]['live_fingerprint'] = $liveFingerprint;
                $records[$id]['config_fingerprint'] = ApiNetworkAddressEvidence::mappingFingerprint($rawMapping);
            }
        }
        return ['records' => $records, 'blocked' => $blocked, 'api_requests' => $apiRequests];
    }

    private function liveService(int $id): array
    {
        try {
            $value = $this->get('clients/services/' . $id);
            if (!is_object($value) && !is_array($value)) return ['kind' => 'unavailable'];
            $value = (array) $value;
            if (self::positiveId($value['id'] ?? null) !== $id || self::positiveId($value['clientId'] ?? null) === null) {
                return ['kind' => 'unavailable'];
            }
            // An existing ended, inactive or archived service is still present.
            // Neither its status nor client lifecycle makes it safe to remove.
            return ['kind' => 'present', 'service' => $value];
        } catch (Throwable $error) {
            for ($current = $error; $current !== null; $current = $current->getPrevious()) {
                if ($current instanceof \GuzzleHttp\Exception\RequestException) {
                    return ['kind' => $current->hasResponse() && $current->getResponse()->getStatusCode() === 404 ? 'absent' : 'unavailable'];
                }
            }
            return ['kind' => 'unavailable'];
        }
    }

    private function get(string $path)
    {
        if ($this->request !== null) return ($this->request)($path);
        require_once __DIR__ . '/api_ucrm.php';
        return (new ApiUcrm())->get($path);
    }

    private function snapshot(SQLite3 $database, int $id): array
    {
        $networkColumns = $this->columns($database, 'network');
        $serviceColumns = $this->columns($database, 'services');
        $network = isset($networkColumns['id']) ? $this->row($database, 'network', $id) : null;
        $service = isset($serviceColumns['id']) ? $this->row($database, 'services', $id) : null;
        // An existing network row wins, including NULL, as in INSERT OR IGNORE.
        $address = $network !== null ? ($network['address'] ?? null)
            : (isset($serviceColumns['address']) ? ($service['address'] ?? null) : null);
        $address6 = $network !== null ? ($network['address6'] ?? null)
            : (isset($serviceColumns['address'], $serviceColumns['prefix6']) ? ($service['prefix6'] ?? null) : null);
        $fingerprint = hash('sha256', json_encode(['network_columns' => $networkColumns, 'service_columns' => $serviceColumns,
            'network' => $network, 'service' => $service], JSON_THROW_ON_ERROR));
        return ['service' => $service, 'address' => $address, 'address6' => $address6, 'fingerprint' => $fingerprint];
    }

    private function assertSnapshot(SQLite3 $database, int $id, array $record): void
    {
        if (!hash_equals($record['fingerprint'], $this->snapshot($database, $id)['fingerprint'])) {
            throw new InvalidArgumentException('cleanup_local_changed');
        }
        if (isset($record['config_fingerprint'])) {
            require_once __DIR__ . '/api_network_address_evidence.php';
            if (!hash_equals($record['config_fingerprint'],
                ApiNetworkAddressEvidence::mappingFingerprint(ApiNetworkAddressEvidence::mapping($database)))) {
                throw new InvalidArgumentException('cleanup_local_changed');
            }
        }
        foreach ($record['groups'] as $group) {
            if (!hash_equals($group['witness_snapshot'], $this->snapshot($database, $group['witness_id'])['fingerprint'])) {
                throw new InvalidArgumentException('cleanup_local_changed');
            }
        }
    }

    private function groups(SQLite3 $database, array $snapshot): array
    {
        $groups = [];
        foreach (['address', 'address6'] as $field) {
            $value = $snapshot[$field];
            if ($value === null || $value === '') continue;
            $effective = $this->effectiveSql($database, $field);
            if ($effective === null) continue;
            $rows = $this->query($database, 'SELECT id FROM (' . $effective . ') WHERE address=? ORDER BY id LIMIT 50', [$value]);
            $ids = [];
            foreach ($rows as $row) { $id = self::positiveId($row['id']); if ($id !== null) $ids[$id] = $id; }
            if (count($ids) > 1) $groups[] = ['field' => $field, 'address' => (string) $value, 'peer_ids' => array_values($ids)];
        }
        return $groups;
    }

    private function effectiveSql(SQLite3 $database, string $field): ?string
    {
        $network = $this->columns($database, 'network');
        $services = $this->columns($database, 'services');
        $parts = [];
        if (isset($network['id'], $network[$field])) $parts[] = 'SELECT id,"' . $field . '" AS address FROM network';
        $legacy = $field === 'address' ? 'address' : 'prefix6';
        if (isset($services['id'], $services['address'], $services[$legacy])) {
            $parts[] = 'SELECT s.id,s."' . $legacy . '" AS address FROM services s'
                . (isset($network['id']) ? ' WHERE NOT EXISTS(SELECT 1 FROM network n WHERE n.id=s.id)' : '');
        }
        return $parts ? implode(' UNION ALL ', $parts) : null;
    }

    private function duplicateCount(SQLite3 $database): int
    {
        $count = 0;
        foreach (['address', 'address6'] as $field) {
            $effective = $this->effectiveSql($database, $field);
            if ($effective !== null) $count += (int) $database->querySingle('SELECT count(*) FROM (SELECT address FROM ('
                . $effective . ") WHERE address IS NOT NULL AND address<>'' GROUP BY address HAVING count(*)>1)");
        }
        return $count;
    }

    private function assertSafeSchema(SQLite3 $database): void
    {
        $found = false;
        foreach (['services', 'network'] as $table) {
            $columns = $this->columns($database, $table);
            if (!$columns) continue;
            $primary = [];
            foreach ($columns as $field => $column) if ($column['pk']) $primary[] = $field;
            if ($primary !== ['id']) throw new InvalidArgumentException('cleanup_schema_unsupported');
            $found = true;
        }
        if (!$found || $database->querySingle("SELECT count(*) FROM sqlite_master WHERE type='trigger' AND tbl_name IN ('services','network')")) {
            throw new InvalidArgumentException('cleanup_schema_unsupported');
        }
    }

    private function assertQueueSafe(array $candidateIds, array $witnessIds): void
    {
        $ids = array_values(array_unique(array_merge($candidateIds, $witnessIds)));
        if (is_link($this->jobsPath) || is_link($this->legacyPath)) throw new InvalidArgumentException('cleanup_queue_busy');
        if (is_file($this->jobsPath)) {
            $database = new SQLite3($this->jobsPath, SQLITE3_OPEN_READONLY);
            $database->enableExceptions(true);
            $database->busyTimeout(500);
            try {
                $meta = $this->columns($database, 'queue_meta');
                if ($meta && (!isset($meta['key']) || $database->querySingle("SELECT count(*) FROM queue_meta WHERE key='maintenance'"))) {
                    throw new InvalidArgumentException('cleanup_queue_busy');
                }
                $events = $this->columns($database, 'service_event_locks');
                if ($events && $database->querySingle('SELECT count(*) FROM service_event_locks')) throw new InvalidArgumentException('cleanup_queue_busy');
                $jobs = $this->columns($database, 'jobs');
                if (!isset($jobs['id'], $jobs['status'], $jobs['service_id'])
                    || $database->querySingle("SELECT count(*) FROM jobs WHERE status='running' OR service_id IS NULL
                        OR typeof(service_id)<>'integer' OR service_id<1 OR status IS NULL
                        OR status NOT IN ('pending','running','succeeded','cancelled','superseded','cleaned')")) {
                    throw new InvalidArgumentException('cleanup_queue_busy');
                }
                if ($this->query($database, "SELECT service_id FROM jobs WHERE service_id IN (" . self::placeholders($ids)
                    . ") AND status<>'succeeded' LIMIT 1", $ids)) throw new InvalidArgumentException('cleanup_queue_busy');
                $cleanup = $this->columns($database, 'cancellation_cleanups');
                if ($cleanup && (!isset($cleanup['service_id']) || $this->query($database,
                    'SELECT service_id FROM cancellation_cleanups WHERE service_id IN (' . self::placeholders($ids) . ') LIMIT 1', $ids))) {
                    throw new InvalidArgumentException('cleanup_queue_busy');
                }
                $versions = $this->columns($database, 'service_versions');
                $ownership = [];
                foreach (['applied_payload', 'applied_identity'] as $field) {
                    if (isset($versions[$field])) $ownership[] = '"' . $field . '" IS NOT NULL';
                }
                if ($versions && (!isset($versions['service_id']) || $this->query($database,
                    'SELECT service_id FROM service_versions WHERE service_id IN (' . self::placeholders($candidateIds)
                    . ')' . (isset($versions['applied_payload']) ? ' AND (' . implode(' OR ', $ownership) . ')' : '')
                    . ' LIMIT 1', $candidateIds))) throw new InvalidArgumentException('cleanup_queue_busy');
            } catch (Throwable $error) {
                throw new InvalidArgumentException('cleanup_queue_busy', 0, $error);
            } finally { $database->close(); }
        }
        if (is_file($this->legacyPath)) {
            if (filesize($this->legacyPath) > 1048576) throw new InvalidArgumentException('cleanup_queue_busy');
            try {
                $json = file_get_contents($this->legacyPath);
                if ($json === false) throw new RuntimeException('legacy_queue_read_failed');
                $entries = trim($json) === '' ? [] : json_decode($json, true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($entries)) throw new RuntimeException('legacy_queue_invalid');
                foreach ($entries as $entry) {
                    if (!is_array($entry) || self::positiveId($entry['id'] ?? null) === null
                        || in_array(self::positiveId($entry['id']), $ids, true)) throw new RuntimeException('legacy_queue_busy');
                }
            } catch (Throwable $error) { throw new InvalidArgumentException('cleanup_queue_busy', 0, $error); }
        }
    }

    private function acquireMaintenance(): array
    {
        $identity = realpath($this->jobsPath) ?: (realpath(dirname($this->jobsPath)) ?: dirname($this->jobsPath))
            . DIRECTORY_SEPARATOR . basename($this->jobsPath);
        if (PHP_OS_FAMILY === 'Windows') $identity = strtolower($identity);
        $directory = dirname($identity) . DIRECTORY_SEPARATOR . '.uisp-queue-locks-' . hash('sha256', $identity);
        if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory))) {
            throw new InvalidArgumentException('cleanup_queue_busy');
        }
        $paths = [$directory . DIRECTORY_SEPARATOR . 'maintenance.lock', dirname($this->dataPath) . DIRECTORY_SEPARATOR . 'setup.lock',
            dirname($this->dataPath) . DIRECTORY_SEPARATOR . 'backup.lock'];
        $locks = [];
        try {
            foreach ($paths as $path) {
                if (is_link($path)) throw new InvalidArgumentException('cleanup_queue_busy');
                $lock = @fopen($path, 'c');
                if (!$lock) throw new InvalidArgumentException('cleanup_queue_busy');
                if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); throw new InvalidArgumentException('cleanup_queue_busy'); }
                $locks[] = $lock;
            }
            return $locks;
        } catch (Throwable $error) {
            foreach (array_reverse($locks) as $lock) { flock($lock, LOCK_UN); fclose($lock); }
            throw $error;
        }
    }

    private function createBackup(): string
    {
        $name = 'network-cleanup-' . gmdate('Ymd\THis\Z', (int) $this->now()) . '-' . bin2hex(random_bytes(16)) . '.sqlite';
        $path = dirname($this->dataPath) . DIRECTORY_SEPARATOR . $name;
        $temporary = $path . '.tmp';
        $reader = null;
        $destination = null;
        try {
            $file = @fopen($temporary, 'x');
            if (!$file) throw new RuntimeException('backup_create_failed');
            fclose($file);
            if (!chmod($temporary, 0600)) throw new RuntimeException('backup_permissions_failed');
            if ($this->backup !== null) {
                if (($this->backup)($this->dataPath, $temporary) !== true) throw new RuntimeException('backup_failed');
            } else {
                $reader = new SQLite3($this->dataPath, SQLITE3_OPEN_READONLY);
                $reader->enableExceptions(true);
                $reader->busyTimeout(1000);
                $destination = new SQLite3($temporary, SQLITE3_OPEN_READWRITE);
                $destination->enableExceptions(true);
                if (!$reader->backup($destination)) throw new RuntimeException('backup_failed');
                $destination->close(); $destination = null;
                $reader->close(); $reader = null;
            }
            if (!chmod($temporary, 0600)) throw new RuntimeException('backup_permissions_failed');
            self::validateBackup($temporary);
            if (!rename($temporary, $path)) throw new RuntimeException('backup_publish_failed');
            if (!chmod($path, 0600)) throw new RuntimeException('backup_permissions_failed');
            return $name;
        } catch (Throwable $error) {
            throw new InvalidArgumentException('cleanup_backup_failed', 0, $error);
        } finally {
            if ($destination !== null) $destination->close();
            if ($reader !== null) $reader->close();
            if (is_file($temporary)) @unlink($temporary);
        }
    }

    private static function validateBackup(string $path): void
    {
        if (is_link($path) || !is_file($path) || filesize($path) < 16
            || file_get_contents($path, false, null, 0, 16) !== "SQLite format 3\0") throw new InvalidArgumentException('invalid_sqlite_backup');
        $database = new SQLite3($path, SQLITE3_OPEN_READONLY);
        $database->enableExceptions(true);
        try {
            if ($database->querySingle('PRAGMA integrity_check') !== 'ok') throw new InvalidArgumentException('backup_integrity_failed');
            $tables = [];
            foreach (['services' => 'id', 'config' => 'key', 'network' => 'id'] as $table => $expected) {
                $result = $database->query('PRAGMA table_info("' . $table . '")');
                $fields = [];
                $primary = [];
                try {
                    while ($column = $result->fetchArray(SQLITE3_ASSOC)) {
                        $fields[$column['name']] = true;
                        if ($column['pk']) $primary[] = $column['name'];
                    }
                } finally { $result->finalize(); }
                if (($table !== 'network' || $fields) && $primary !== [$expected]) throw new InvalidArgumentException('backup_schema_invalid');
                $tables[$table] = $fields;
            }
            if (!$tables['network'] && !isset($tables['services']['address'])) throw new InvalidArgumentException('backup_schema_invalid');
        } finally { $database->close(); }
    }

    private function pruneTokens(): void
    {
        $paths = glob(dirname($this->dataPath) . DIRECTORY_SEPARATOR . 'network-cleanup-preview-*.json') ?: [];
        $active = 0;
        foreach ($paths as $path) {
            if (!preg_match('/^network-cleanup-preview-[a-f0-9]{64}\.json$/D', basename($path)) || is_link($path)) continue;
            $date = filemtime($path);
            if ($date !== false && $date + self::PREVIEW_TTL + 60 < $this->now()) { @unlink($path); continue; }
            $active++;
        }
        if ($active >= 64) throw new InvalidArgumentException('cleanup_preview_limit');
    }

    private function openDatabase(bool $write): SQLite3
    {
        if (!is_file($this->dataPath) || is_link($this->dataPath)) throw new InvalidArgumentException('cleanup_database_missing');
        $database = new SQLite3($this->dataPath, $write ? SQLITE3_OPEN_READWRITE : SQLITE3_OPEN_READONLY);
        $database->enableExceptions(true);
        $database->busyTimeout(1000);
        return $database;
    }

    private function databaseIdentity(): string { return hash('sha256', (string) realpath($this->dataPath)); }
    private function now(): float { return $this->clock === null ? microtime(true) : (float) ($this->clock)(); }
    private function tokenPath(string $token): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) throw new InvalidArgumentException('invalid_cleanup_token');
        return dirname($this->dataPath) . DIRECTORY_SEPARATOR . 'network-cleanup-preview-' . $token . '.json';
    }
    private static function positiveId($value): ?int
    {
        if (!is_int($value) && !is_string($value)) return null;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }
    private static function selection(array $values): array
    {
        if (!$values || count($values) > self::MAX_SERVICES) throw new InvalidArgumentException('cleanup_service_limit');
        $ids = [];
        foreach ($values as $value) {
            $id = self::positiveId($value);
            if ($id === null) throw new InvalidArgumentException('invalid_cleanup_service_ids');
            $ids[$id] = $id;
        }
        return array_values($ids);
    }
    private static function placeholders(array $values): string { return implode(',', array_fill(0, count($values), '?')); }
    private function columns(SQLite3 $database, string $table): array
    {
        $rows = $this->query($database, 'PRAGMA table_info("' . $table . '")');
        $columns = [];
        foreach ($rows as $column) $columns[$column['name']] = $column;
        return $columns;
    }
    private function row(SQLite3 $database, string $table, int $id): ?array
    {
        return $this->query($database, 'SELECT * FROM "' . $table . '" WHERE id=?', [$id])[0] ?? null;
    }
    private function execute(SQLite3 $database, string $sql, array $values): void
    {
        $this->query($database, $sql, $values);
    }
    private function query(SQLite3 $database, string $sql, array $values = []): array
    {
        $statement = $database->prepare($sql);
        try {
            foreach ($values as $index => $value) $statement->bindValue($index + 1, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
            $result = $statement->execute();
            try {
                $rows = [];
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
                return $rows;
            } finally { $result->finalize(); }
        } finally { $statement->close(); }
    }
}
