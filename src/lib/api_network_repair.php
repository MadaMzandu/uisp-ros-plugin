<?php

require_once __DIR__ . '/api_network_address_evidence.php';
require_once __DIR__ . '/api_crm_network_history.php';

/** Explicit repair of malformed local reservations. Never writes to CRM, cache, queue or routers. */
final class ApiNetworkRepair
{
    public const MAX_SERVICES = 3;
    private const TTL = 600;
    private const BUDGET = 12.0;
    private const MAX_REQUESTS = 28;
    private const PAGE_SIZE = 500;
    private const MAX_PAGES = 20;
    private const MAX_ROWS = 25000;
    private $request;
    private $clock;
    private $backup;
    private string $dataPath;
    private string $jobsPath;
    private string $legacyPath;
    private string $cachePath;
    private int $requests = 0;
    private float $deadline = 0;

    public function __construct(?callable $request = null, string $dataPath = 'data/data.db',
        string $jobsPath = 'data/jobs.db', string $legacyPath = 'data/queue.json', ?callable $clock = null,
        ?callable $backup = null, ?string $cachePath = null)
    {
        $this->request = $request; $this->clock = $clock; $this->backup = $backup;
        $this->dataPath = $dataPath; $this->jobsPath = $jobsPath; $this->legacyPath = $legacyPath;
        $this->cachePath = $cachePath ?? dirname($dataPath) . DIRECTORY_SEPARATOR . 'cache.db';
    }

    /** Purely local bounded inventory; it does not initialize any schema or perform a CRM request. */
    public function listing(): array
    {
        $database = $this->open(false);
        try {
            $database->exec('BEGIN');
            $this->schema($database);
            $rows = []; $seen = []; $total = 0;
            foreach ($this->localRows($database) as $entry) {
                if (!$this->malformed($entry['value'], $entry['field'])) continue;
                $id = self::id($entry['id']);
                if ($id === null) throw new InvalidArgumentException('repair_schema_unsupported');
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $total++;
                    if (count($rows) >= 50) continue;
                    $service = $this->row($database, 'services', $id);
                    $rows[$id] = ['service_id' => $id, 'client_id' => self::id($service['clientId'] ?? null),
                        'status' => self::status($service['status'] ?? null), 'fields' => []];
                }
                if (!isset($rows[$id])) continue;
                $field = $entry['field'];
                if (!isset($rows[$id]['fields'][$field])) $rows[$id]['fields'][$field] = [
                    'field' => $field, 'family' => self::family($field), 'sources' => []];
                $rows[$id]['fields'][$field]['sources'][] = ['source' => $entry['source'], 'value' => $entry['value']];
            }
            foreach ($rows as &$row) $row['fields'] = array_values($row['fields']);
            unset($row);
            return ['rows' => array_values($rows), 'total' => $total, 'truncated' => $total > count($rows),
                'checked_at' => $this->timestamp()];
        } finally { $database->close(); }
    }

    public function preview(array $selection): array
    {
        $selection = self::selection($selection);
        $database = $this->open(false);
        try {
            $this->schema($database);
            $verified = $this->verify($database, $selection);
        } finally { $database->close(); }
        $token = null; $expires = (int) $this->now() + self::TTL;
        if ($verified['records']) {
            $this->pruneTokens();
            $token = bin2hex(random_bytes(32));
            $this->writeToken($this->tokenPath($token), ['expires' => $expires, 'database' => $this->identity(),
                'records' => $verified['records']]);
        }
        return ['previewToken' => $token, 'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', $expires),
            'candidates' => array_column(array_values($verified['records']), 'candidate'),
            'blocked' => $verified['blocked'], 'api_requests' => $this->requests];
    }

    public function apply(string $token): array
    {
        $path = $this->tokenPath($token); $tokenLock = null; $locks = []; $database = null; $transaction = false;
        try {
            if (!is_file($path) || is_link($path) || filesize($path) > 65536) throw new InvalidArgumentException('invalid_repair_token');
            $tokenLock = fopen($path, 'r+b');
            if (!$tokenLock || !flock($tokenLock, LOCK_EX | LOCK_NB)) throw new InvalidArgumentException('repair_busy');
            $state = json_decode((string) stream_get_contents($tokenLock), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($state) || !is_int($state['expires'] ?? null) || ($state['database'] ?? null) !== $this->identity()
                || !is_array($state['records'] ?? null) || !$state['records'] || count($state['records']) > self::MAX_SERVICES) {
                throw new InvalidArgumentException('invalid_repair_token');
            }
            if ($state['expires'] <= $this->now()) throw new InvalidArgumentException('repair_preview_expired');
            $selection = [];
            foreach ($state['records'] as $id => $record) {
                if (self::id($id) === null || !is_array($record['candidate']['fields'] ?? null)
                    || !is_string($record['fingerprint'] ?? null)) throw new InvalidArgumentException('invalid_repair_token');
                $selection[] = ['serviceId' => (int) $id, 'fields' => array_column($record['candidate']['fields'], 'field')];
            }
            $selection = self::selection($selection);
            $locks = $this->locks();
            $database = $this->open(true);
            $this->schema($database);
            foreach ($state['records'] as $id => $record) $this->assertSnapshot($database, (int) $id, $record);
            $verified = $this->verify($database, $selection, $state['records']);
            if ($verified['blocked'] || count($verified['records']) !== count($state['records'])) {
                throw new InvalidArgumentException('repair_verification_failed');
            }
            if ($state['expires'] <= $this->now()) throw new InvalidArgumentException('repair_preview_expired');
            $database->exec('BEGIN IMMEDIATE'); $transaction = true;
            foreach ($verified['records'] as $id => $record) {
                $this->assertSnapshot($database, (int) $id, $record);
                // Re-read all local/cache/durable ownership inside the write barrier.
                $this->ownership($database, (int) $id, $record['candidate']['fields']);
                $this->queue((int) $id, $record['candidate']['client_id'], $record['candidate']['fields']);
            }
            $backupName = $this->createBackup();
            $repaired = [];
            foreach ($verified['records'] as $id => $record) {
                $fields = [];
                foreach ($record['candidate']['fields'] as $field) {
                    foreach ($field['saved'] as $saved) {
                        $table = $saved['source'] === 'network' ? 'network' : 'services';
                        $column = $saved['source'] === 'legacy' && $field['field'] === 'address6' ? 'prefix6' : $field['field'];
                        // Only the exact malformed source reviewed is changed. A valid
                        // peer source, other family, service identity and status remain intact.
                        $changed = $this->update($database, 'UPDATE "' . $table . '" SET "' . $column . '"=? WHERE id=? AND "' . $column . '"=?',
                            [$field['current_address'], (int) $id, $saved['value']]);
                        if ($changed !== 1) throw new InvalidArgumentException('repair_local_changed');
                    }
                    $fields[] = ['field' => $field['field'], 'family' => $field['family'], 'action' => $field['action'],
                        'current_address' => $field['current_address']];
                }
                $repaired[] = ['service_id' => (int) $id, 'fields' => $fields];
            }
            $database->exec('COMMIT'); $transaction = false;
            // Invalidate the private preview before releasing its request lock.
            rewind($tokenLock); ftruncate($tokenLock, 0); fflush($tokenLock);
            @unlink($path);
            return ['repaired' => $repaired, 'backup_name' => $backupName, 'requires_reload' => true,
                'checked_at' => $this->timestamp(), 'jobs_preserved' => true, 'requires_sync' => false];
        } catch (Throwable $error) {
            if ($database !== null && $transaction) try { $database->exec('ROLLBACK'); } catch (Throwable $ignored) {}
            if ($error instanceof InvalidArgumentException) throw $error;
            throw new InvalidArgumentException('repair_failed', 0, $error);
        } finally {
            if ($database !== null) $database->close();
            foreach (array_reverse($locks) as $lock) { flock($lock, LOCK_UN); fclose($lock); }
            if (is_resource($tokenLock)) { flock($tokenLock, LOCK_UN); fclose($tokenLock); }
        }
    }

    private function verify(SQLite3 $database, array $selection, ?array $expected = null): array
    {
        $this->requests = 0; $this->deadline = $this->now() + self::BUDGET;
        $records = []; $blocked = []; $mapping = null; $collection = null;
        $rawMapping = ApiNetworkAddressEvidence::mapping($database);
        foreach ($selection as $selected) {
            $id = $selected['serviceId'];
            try {
                $snapshot = $this->snapshot($database, $id);
                $clientId = self::id($snapshot['service']['clientId'] ?? null);
                if ($clientId === null) throw new InvalidArgumentException('identity_unknown');
                $fields = [];
                foreach ($selected['fields'] as $field) {
                    $saved = [];
                    foreach ($snapshot['sources'][$field] as $source) if ($this->malformed($source['value'], $field)) $saved[] = $source;
                    if (!$saved) throw new InvalidArgumentException('no_invalid_reservation');
                    $fields[$field] = ['field' => $field, 'family' => self::family($field), 'saved' => $saved];
                }
                $live = $this->service($id);
                $status = null;
                if ($live !== null) {
                    if (self::id($live['clientId']) !== $clientId) throw new InvalidArgumentException('identity_changed');
                    $status = self::status($live['status']);
                    // Independent fresh client identity; archival alone never authorizes clearing an IP.
                    $client = $this->call('clients/' . $clientId);
                    if ((!is_array($client) && !is_object($client)) || self::id(((array) $client)['id'] ?? null) !== $clientId) {
                        throw new InvalidArgumentException('identity_unknown');
                    }
                    if ($mapping === null) $mapping = ApiNetworkAddressEvidence::validateMapping($rawMapping, $this->call('custom-attributes'));
                    $evidence = ApiNetworkAddressEvidence::parse($live, $mapping);
                    foreach ($fields as $field => &$entry) {
                        if (!$evidence[$field]['known']) throw new InvalidArgumentException('crm_address_unknown');
                        $entry['current_address'] = $evidence[$field]['value'];
                        $entry['action'] = $entry['current_address'] === null ? 'clear' : 'replace';
                        $entry['reason'] = $entry['current_address'] === null ? 'crm_no_address' : 'crm_address';
                    }
                    unset($entry);
                } else foreach ($fields as &$entry) {
                    $entry['current_address'] = null; $entry['action'] = 'clear'; $entry['reason'] = 'crm_not_found';
                }
                unset($entry);
                $fields = array_values($fields);
                // Selected service may have a valid legacy copy. It must agree
                // exactly with the fresh CRM intent and is never overwritten.
                $this->ownership($database, $id, $fields);
                $pending = $this->queue($id, $clientId, $fields);
                foreach ($fields as $entry) if ($entry['current_address'] !== null) {
                    if ($collection === null) $collection = $this->collection();
                    $this->crmOwnership($id, $clientId, $status, $entry, $mapping, $collection);
                }
                $candidate = ['service_id' => $id, 'client_id' => $clientId, 'status' => $status,
                    'fields' => $fields, 'pending_jobs' => $pending];
                $record = ['candidate' => $candidate, 'fingerprint' => $snapshot['fingerprint'],
                    'config_fingerprint' => ApiNetworkAddressEvidence::mappingFingerprint($rawMapping),
                    'live_fingerprint' => hash('sha256', json_encode(['client_id' => $clientId, 'status' => $status,
                        'mapping' => $mapping, 'fields' => $fields], JSON_THROW_ON_ERROR))];
                if ($expected !== null && (!isset($expected[$id]) || !hash_equals($expected[$id]['fingerprint'], $record['fingerprint'])
                    || !hash_equals($expected[$id]['config_fingerprint'], $record['config_fingerprint'])
                    || !hash_equals($expected[$id]['live_fingerprint'], $record['live_fingerprint']))) {
                    throw new InvalidArgumentException('repair_local_changed');
                }
                $records[$id] = $record;
            } catch (Throwable $error) {
                $reason = $error instanceof InvalidArgumentException ? $error->getMessage() : 'crm_unavailable';
                $blocked[] = ['service_id' => $id, 'reason' => $reason];
            }
        }
        return ['records' => $records, 'blocked' => $blocked];
    }

    /** Complete bounded fresh collection makes textual IPv6 aliases and overlap visible. */
    private function collection(): array
    {
        $services = [];
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $rows = $this->call('clients/services', ['limit' => self::PAGE_SIZE, 'offset' => $page * self::PAGE_SIZE]);
            if (!is_array($rows) || count($rows) > self::PAGE_SIZE || ($rows && array_keys($rows) !== range(0, count($rows) - 1))) {
                throw new InvalidArgumentException('crm_ownership_unknown');
            }
            foreach ($rows as $service) {
                if (!is_object($service) && !is_array($service)) throw new InvalidArgumentException('crm_ownership_unknown');
                $service = (array) $service; $id = self::id($service['id'] ?? null);
                if ($id === null || self::id($service['clientId'] ?? null) === null || self::status($service['status'] ?? null) === null
                    || isset($services[$id])) throw new InvalidArgumentException('crm_ownership_unknown');
                $services[$id] = $service;
            }
            if (count($rows) < self::PAGE_SIZE) return $services;
        }
        throw new InvalidArgumentException('crm_ownership_limit');
    }

    private function crmOwnership(int $id, int $clientId, ?int $status, array $entry, array $mapping, array $services): void
    {
        $field = $entry['field']; $candidate = $this->range($entry['current_address'], $field);
        if (!isset($services[$id]) || self::id($services[$id]['clientId']) !== $clientId || self::status($services[$id]['status']) !== $status) {
            throw new InvalidArgumentException('crm_ownership_unknown');
        }
        foreach ($services as $peer => $service) {
            $evidence = ApiNetworkAddressEvidence::parse($service, $mapping)[$field];
            if (!$evidence['known']) throw new InvalidArgumentException('crm_ownership_unknown');
            if ((int) $peer === $id) {
                if ($evidence['value'] !== $entry['current_address']) throw new InvalidArgumentException('crm_address_changed');
                continue;
            }
            if ($evidence['value'] !== null && self::overlaps($candidate, $this->range($evidence['value'], $field))) {
                // Only an explicit, still-exact review of this ended service
                // excludes its historical address. Reactivation or any change
                // to identity, raw IP value or descriptor revokes that proof.
                if (ApiCrmNetworkHistory::reviewed($service, $mapping[$field], $field, dirname($this->dataPath))) continue;
                throw new InvalidArgumentException('address_in_use');
            }
        }
    }

    private function ownership(SQLite3 $database, int $id, array $fields): void
    {
        $rows = $this->localRows($database);
        if (is_link($this->cachePath)) throw new InvalidArgumentException('local_ownership_unknown');
        if (is_file($this->cachePath)) {
            $cache = new SQLite3($this->cachePath, SQLITE3_OPEN_READONLY);
            $cache->enableExceptions(true); $cache->busyTimeout(500);
            try {
                // Even inactive cached addresses remain conservatively occupied;
                // this explicit repair never uses status to free another owner.
                $cached = $this->localRows($cache, true);
                foreach ($cached as &$row) {
                    if (!in_array('address6', array_column($fields, 'field'), true) || $row['field'] !== 'address6'
                        || !is_string($row['value']) || strpos($row['value'], '/') !== false
                        || !filter_var(trim($row['value']), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) continue;
                    // The allocator expands an unprefixed cached IPv6 value with
                    // this router's configured client length. A /128 interpretation
                    // here would silently miss ownership of the rest of that prefix.
                    $service = $this->row($cache, 'services', self::id($row['id']) ?? 0);
                    $device = $this->row($database, 'devices', self::id($service['device'] ?? null) ?? 0);
                    if ($device === null) throw new InvalidArgumentException('local_ownership_unknown');
                    $length = $device['pfxLength'] ?? 64;
                    if ($length === '') $length = 64;
                    if ((!is_int($length) && !is_string($length)) || !ctype_digit((string) $length) || (int) $length < 1 || (int) $length > 128) {
                        throw new InvalidArgumentException('local_ownership_unknown');
                    }
                    $row['value'] = ApiNetworkAddressEvidence::normalize($row['value'] . '/' . $length, 'address6');
                }
                unset($row);
                $rows = array_merge($rows, $cached);
            } finally { $cache->close(); }
        }
        foreach ($fields as $entry) {
            $candidate = $entry['current_address'] === null ? null : $this->range($entry['current_address'], $entry['field']);
            foreach ($rows as $row) {
                if ($row['field'] !== $entry['field'] || $row['value'] === null || $row['value'] === '') continue;
                $range = $this->range($row['value'], $entry['field']);
                // Other malformed rows are preserved. A syntactically malformed
                // value cannot describe an occupied range in the allocator.
                if ($range === null) continue;
                if (self::id($row['id']) === null) throw new InvalidArgumentException('local_ownership_unknown');
                if ((int) $row['id'] === $id) {
                    if ($candidate === null || $range !== $candidate) throw new InvalidArgumentException('valid_local_reservation');
                } elseif ($candidate !== null && self::overlaps($candidate, $range)) throw new InvalidArgumentException('address_in_use');
            }
        }
    }

    /** Pending update is safe only before command preparation and without prior ownership debt. */
    private function queue(int $id, int $clientId, array $fields): int
    {
        if (is_link($this->jobsPath) || is_link($this->legacyPath)) throw new InvalidArgumentException('queue_unknown');
        $pending = 0;
        if (is_file($this->jobsPath)) {
            $database = new SQLite3($this->jobsPath, SQLITE3_OPEN_READONLY);
            $database->enableExceptions(true); $database->busyTimeout(500);
            try {
                foreach (['jobs' => ['id','service_id','status','operation','payload','prior_cleared','cleanup_done'],
                    'service_versions' => ['service_id','applied_payload','applied_identity'],
                    'queue_meta' => ['key','value'], 'service_event_locks' => ['service_id'],
                    'cancellation_cleanups' => ['service_id','payload']] as $table => $required) {
                    if (array_diff($required, array_keys($this->columns($database, $table)))) throw new InvalidArgumentException('queue_unknown');
                }
                if ($database->querySingle("SELECT count(*) FROM queue_meta WHERE key='maintenance'")
                    || $database->querySingle('SELECT count(*) FROM service_event_locks')) throw new InvalidArgumentException('queue_busy');
                $versions = $this->query($database, 'SELECT service_id,applied_payload,applied_identity FROM service_versions LIMIT ' . (self::MAX_ROWS + 1));
                if (count($versions) > self::MAX_ROWS) throw new InvalidArgumentException('queue_unknown');
                $appliedOwners = [];
                foreach ($versions as $version) {
                    $owner = self::id($version['service_id']);
                    if ($owner === null) throw new InvalidArgumentException('queue_unknown');
                    if ($owner === $id && ($version['applied_payload'] !== null || $version['applied_identity'] !== null)) {
                        if (!is_string($version['applied_identity']) || !is_string($version['applied_payload'])) throw new InvalidArgumentException('applied_ownership');
                        $this->sameApplied($this->payload($version['applied_payload']), $version['applied_identity'], $id, $clientId, $fields);
                    }
                    if ($version['applied_identity'] !== null && $version['applied_payload'] === null) throw new InvalidArgumentException('applied_address_unknown');
                    if ($version['applied_payload'] !== null || $version['applied_identity'] !== null) $appliedOwners[$owner] = true;
                    if ($version['applied_payload'] !== null) $this->payloadOwnership($owner, $this->payload($version['applied_payload']), $id, $fields);
                }
                $jobs = $this->query($database, 'SELECT service_id,status,operation,payload,prior_cleared,cleanup_done FROM jobs WHERE status IS NULL OR status<>\'succeeded\' LIMIT ' . (self::MAX_ROWS + 1));
                if (count($jobs) > self::MAX_ROWS) throw new InvalidArgumentException('queue_unknown');
                foreach ($jobs as $job) {
                    $owner = self::id($job['service_id']);
                    if ($owner === null || !in_array($job['status'], ['pending','running','cancelled','superseded','cleaned'], true)
                        || !in_array($job['prior_cleared'], [0,1], true) || !in_array($job['cleanup_done'], [0,1], true)) {
                        throw new InvalidArgumentException('queue_unknown');
                    }
                    if ($job['status'] === 'running') throw new InvalidArgumentException('queue_busy');
                    $payload = $this->payload($job['payload']);
                    if ($owner === $id && $job['status'] !== 'cleaned') {
                        if ($job['status'] !== 'pending' || $job['operation'] !== 'update') throw new InvalidArgumentException('queue_recovery');
                        $this->pending($payload, $id, $clientId, $fields); $pending++;
                    } elseif (in_array($job['status'], ['pending','cancelled','superseded'], true)) {
                        // Prepared commands and uncleared snapshots retain their ownership.
                        if (array_key_exists('_commands', $payload)) $this->payloadOwnership($owner, $payload, $id, $fields);
                        if (!(int) $job['prior_cleared'] && isset($payload['_prior_network'])) $this->payloadOwnership($owner,
                            ['network' => $payload['_prior_network']], $id, $fields,
                            $job['status'] === 'pending' && $job['operation'] === 'update' && !isset($appliedOwners[$owner])
                                && !array_key_exists('_commands', $payload) && !array_key_exists('_cleanup', $payload)
                                && !array_key_exists('_delete_frozen', $payload));
                        if (!(int) $job['cleanup_done'] && isset($payload['_cleanup'])) $this->payloadOwnership($owner, $payload['_cleanup'], $id, $fields);
                    }
                }
                $cleanups = $this->query($database, 'SELECT service_id,payload FROM cancellation_cleanups LIMIT ' . (self::MAX_ROWS + 1));
                if (count($cleanups) > self::MAX_ROWS) throw new InvalidArgumentException('queue_unknown');
                foreach ($cleanups as $cleanup) {
                    $owner = self::id($cleanup['service_id']);
                    if ($owner === null) throw new InvalidArgumentException('queue_unknown');
                    if ($owner === $id) throw new InvalidArgumentException('queue_recovery');
                    $debt = $this->payload($cleanup['payload']);
                    $this->payloadOwnership($owner, $debt, $id, $fields);
                    if (isset($debt['_prior_network'])) $this->payloadOwnership($owner, ['network' => $debt['_prior_network']], $id, $fields);
                }
            } catch (Throwable $error) {
                if ($error instanceof InvalidArgumentException) throw $error;
                throw new InvalidArgumentException('queue_unknown', 0, $error);
            } finally { $database->close(); }
        }
        if (is_file($this->legacyPath)) {
            if (filesize($this->legacyPath) > 1048576) throw new InvalidArgumentException('queue_unknown');
            $json = (string) file_get_contents($this->legacyPath);
            $entries = trim($json) === '' ? [] : json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($entries)) throw new InvalidArgumentException('queue_unknown');
            foreach ($entries as $entry) {
                if (!is_array($entry) || self::id($entry['id'] ?? null) === null) throw new InvalidArgumentException('queue_unknown');
                if (self::id($entry['id']) === $id) throw new InvalidArgumentException('legacy_queue_pending');
                $this->payloadOwnership(self::id($entry['id']), $entry, $id, $fields);
            }
        }
        return $pending;
    }

    private function pending(array $payload, int $id, int $clientId, array $fields): void
    {
        if (self::id($payload['id'] ?? null) !== $id || self::id($payload['clientId'] ?? null) !== $clientId) {
            throw new InvalidArgumentException('pending_identity_changed');
        }
        foreach (['_commands','_cleanup','_delete_frozen'] as $key) if (array_key_exists($key, $payload)) throw new InvalidArgumentException('queue_recovery');
        if (array_key_exists('_prior_identity', $payload) && (!is_string($payload['_prior_identity'])
            || !hash_equals($this->jobIdentity($payload), $payload['_prior_identity']))) throw new InvalidArgumentException('queue_recovery');
        if (isset($payload['_prior_network']) && !is_array($payload['_prior_network'])) throw new InvalidArgumentException('queue_unknown');
        if (isset($payload['network']) && !is_array($payload['network'])) throw new InvalidArgumentException('queue_unknown');
        foreach ($fields as $entry) {
            $field = $entry['field'];
            foreach ([$payload[$field] ?? null, $payload['network'][$field] ?? null] as $desired) {
                if ($desired === null || $desired === '') continue;
                try { $desired = ApiNetworkAddressEvidence::normalize($desired, $field); }
                catch (Throwable $error) { throw new InvalidArgumentException('pending_address_changed'); }
                if ($desired !== $entry['current_address']) throw new InvalidArgumentException('pending_address_changed');
            }
            $prior = $payload['_prior_network'][$field] ?? null;
            if ($prior !== null && $prior !== '') {
                $range = $this->range($prior, $field);
                $candidate = $entry['current_address'] === null ? null : $this->range($entry['current_address'], $field);
                if (!in_array($prior, array_column($entry['saved'], 'value'), true) && ($range === null || $candidate === null || $range !== $candidate)) {
                    throw new InvalidArgumentException('queue_recovery');
                }
            }
        }
    }

    /** Restore only the exact selected IP already confirmed on the current account. */
    private function sameApplied(array $payload, string $identity, int $id, int $clientId, array $fields): void
    {
        if (self::id($payload['id'] ?? null) !== $id || self::id($payload['clientId'] ?? null) !== $clientId
            || !hash_equals($this->jobIdentity($payload), $identity) || !is_array($payload['_commands'] ?? null)
            || !in_array($payload['action'] ?? null, ['update','queues_set'], true)
            || array_key_exists('_cleanup', $payload) || array_key_exists('_delete_frozen', $payload)) {
            throw new InvalidArgumentException('applied_ownership');
        }
        foreach ($fields as $entry) {
            $field = $entry['field'];
            if ($entry['current_address'] === null) throw new InvalidArgumentException('applied_ownership');
            $candidate = $this->range($entry['current_address'], $field); $owned = 0;
            foreach ([$payload[$field] ?? null, $payload['network'][$field] ?? null] as $value) {
                if ($value === null || $value === '') continue;
                if ($this->range($value, $field) !== $candidate) throw new InvalidArgumentException('applied_ownership');
            }
            foreach ($payload['_commands'] as $command) {
                if (!is_array($command) || !is_string($command['path'] ?? null)) throw new InvalidArgumentException('applied_ownership');
                if (($command['action'] ?? null) === 'remove') continue;
                $path = trim($command['path'], '/'); $values = [];
                if (in_array($path, ['ppp/secret','ppp/profile'], true)) $values[] = $command[$field === 'address' ? 'remote-address' : 'remote-ipv6-prefix'] ?? null;
                if (($field === 'address' && in_array($path, ['ip/dhcp-server/lease','ip/hotspot/user'], true))
                    || ($field === 'address6' && $path === 'ipv6/dhcp-server/binding')) $values[] = $command['address'] ?? null;
                if ($path === 'queue/simple' && ($command['comment'] ?? '') !== 'do not delete'
                    && !str_ends_with($command['name'] ?? '', '-parent')) $values[] = $command['target'] ?? null;
                foreach ($values as $value) {
                    if ($value === null || $value === '') continue;
                    if (!is_string($value)) throw new InvalidArgumentException('applied_ownership');
                    foreach (explode(',', $value) as $address) {
                        $actual = strpos($address, ':') === false ? 'address' : 'address6';
                        if ($actual !== $field) continue;
                        if ($this->range(trim($address), $field) !== $candidate) throw new InvalidArgumentException('applied_ownership');
                        $owned++;
                    }
                }
            }
            if ($owned < 1) throw new InvalidArgumentException('applied_ownership');
        }
    }

    private function jobIdentity(array $snapshot): string
    {
        $device = $snapshot['_device'] ?? [];
        if (!is_array($device)) throw new InvalidArgumentException('queue_unknown');
        $value = ['device' => $snapshot['device'] ?? null, 'ip' => $device['ip'] ?? null,
            'port' => $device['port'] ?? null, 'username' => $snapshot['username'] ?? null,
            'mac' => $snapshot['mac'] ?? null, 'hotspot' => $snapshot['hotspot'] ?? null,
            'duid' => $snapshot['duid'] ?? null, 'iaid' => $snapshot['iaid'] ?? null];
        ksort($value);
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function payloadOwnership(int $owner, array $payload, int $id, array $fields, bool $unpreparedPrior = false): void
    {
        if (isset($payload['network']) && !is_array($payload['network'])) throw new InvalidArgumentException('queue_unknown');
        $values = [$payload['address'] ?? null, $payload['address6'] ?? null,
            $payload['network']['address'] ?? null, $payload['network']['address6'] ?? null];
        if (isset($payload['_commands']) && !is_array($payload['_commands'])) throw new InvalidArgumentException('queue_unknown');
        foreach ($payload['_commands'] ?? [] as $command) {
            if (!is_array($command)) throw new InvalidArgumentException('queue_unknown');
            foreach (['remote-address','remote-ipv6-prefix'] as $key) $values[] = $command[$key] ?? null;
            $path = trim($command['path'] ?? '', '/');
            if (in_array($path, ['ip/dhcp-server/lease','ipv6/dhcp-server/binding','ip/hotspot/user'], true)) $values[] = $command['address'] ?? null;
            if ($path === 'queue/simple' && ($command['comment'] ?? '') !== 'do not delete'
                && !str_ends_with($command['name'] ?? '', '-parent')) $values[] = $command['target'] ?? null;
        }
        foreach ($values as $value) {
            if ($value === null || $value === '') continue;
            if (!is_string($value)) throw new InvalidArgumentException('queue_unknown');
            foreach (explode(',', $value) as $address) {
                $field = strpos($address, ':') === false ? 'address' : 'address6';
                $range = $this->range(trim($address), $field);
                if ($range === null) {
                    // A failed, unprepared update can retain the same malformed
                    // prior value that is listed for deliberate repair. It has
                    // neither confirmed nor frozen RouterOS ownership to release.
                    if ($unpreparedPrior) continue;
                    throw new InvalidArgumentException('applied_address_unknown');
                }
                foreach ($fields as $entry) if ($entry['field'] === $field && $entry['current_address'] !== null
                    && $owner !== $id && self::overlaps($range, $this->range($entry['current_address'], $field))) {
                    throw new InvalidArgumentException('address_in_use');
                }
            }
        }
    }

    private function payload($value): array
    {
        if (!is_string($value) || strlen($value) > 1048576) throw new InvalidArgumentException('queue_unknown');
        $payload = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) throw new InvalidArgumentException('queue_unknown');
        return $payload;
    }

    private function service(int $id): ?array
    {
        try {
            $service = $this->call('clients/services/' . $id);
            if (!is_array($service) && !is_object($service)) throw new InvalidArgumentException('crm_unavailable');
            $service = (array) $service;
            if (self::id($service['id'] ?? null) !== $id || self::id($service['clientId'] ?? null) === null
                || self::status($service['status'] ?? null) === null) throw new InvalidArgumentException('crm_unavailable');
            return $service;
        } catch (Throwable $error) {
            for ($current = $error; $current !== null; $current = $current->getPrevious()) {
                if ($current instanceof \GuzzleHttp\Exception\RequestException && $current->hasResponse()
                    && $current->getResponse()->getStatusCode() === 404) return null;
            }
            throw $error;
        }
    }

    private function call(string $path, array $parameters = [])
    {
        if ($this->requests >= self::MAX_REQUESTS || $this->now() >= $this->deadline) throw new InvalidArgumentException('deadline');
        $this->requests++;
        if ($this->request !== null) return ($this->request)($path, $parameters);
        require_once __DIR__ . '/api_ucrm.php';
        return (new ApiUcrm())->get($path, $parameters);
    }

    private function localRows(SQLite3 $database, bool $cache = false): array
    {
        $parts = [];
        foreach (['network' => ['address' => 'address', 'address6' => 'address6'],
            'services' => ['address' => 'address', 'address6' => 'prefix6']] as $table => $fields) {
            $columns = $this->columns($database, $table);
            foreach ($fields as $field => $column) if (isset($columns['id'], $columns[$column])) $parts[] =
                "SELECT id,'" . $field . "' AS field,'" . ($cache ? 'cache' : ($table === 'network' ? 'network' : 'legacy'))
                . "' AS source,\"" . $column . '" AS value FROM "' . $table . '"';
        }
        if (!$parts) throw new InvalidArgumentException('local_ownership_unknown');
        $rows = $this->query($database, 'SELECT * FROM (' . implode(' UNION ALL ', $parts) . ') ORDER BY id,field,source LIMIT ' . (self::MAX_ROWS + 1));
        if (count($rows) > self::MAX_ROWS) throw new InvalidArgumentException('local_ownership_limit');
        return $rows;
    }

    private function snapshot(SQLite3 $database, int $id): array
    {
        $network = $this->row($database, 'network', $id); $service = $this->row($database, 'services', $id);
        $sources = ['address' => [], 'address6' => []];
        foreach (['address' => 'address', 'address6' => 'prefix6'] as $field => $legacy) {
            if ($network !== null && array_key_exists($field, $network)) $sources[$field][] = ['source' => 'network', 'value' => $network[$field]];
            if ($service !== null && array_key_exists($legacy, $service)) $sources[$field][] = ['source' => 'legacy', 'value' => $service[$legacy]];
        }
        return ['service' => $service, 'sources' => $sources, 'fingerprint' => hash('sha256', json_encode([
            'network' => $network, 'service' => $service, 'network_columns' => $this->columns($database, 'network'),
            'service_columns' => $this->columns($database, 'services')], JSON_THROW_ON_ERROR))];
    }

    private function assertSnapshot(SQLite3 $database, int $id, array $record): void
    {
        if (!hash_equals($record['fingerprint'], $this->snapshot($database, $id)['fingerprint'])
            || !hash_equals($record['config_fingerprint'], ApiNetworkAddressEvidence::mappingFingerprint(ApiNetworkAddressEvidence::mapping($database)))) {
            throw new InvalidArgumentException('repair_local_changed');
        }
    }

    /** Match allocator range syntax, not stricter CRM host syntax. Valid occupied CIDRs are protected. */
    private function range($value, string $field): ?array
    {
        if (!is_string($value)) return null;
        $parts = explode('/', trim($value)); $ipv6 = $field === 'address6'; $maximum = $ipv6 ? 128 : 32;
        if (count($parts) > 2 || !filter_var($parts[0], FILTER_VALIDATE_IP, $ipv6 ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4)) return null;
        $length = $parts[1] ?? (string) $maximum;
        if (!ctype_digit($length) || (int) $length > $maximum) return null;
        $start = inet_pton($parts[0]); $end = $start;
        for ($index = 0; $index < strlen($start); $index++) {
            $bits = max(0, min(8, (int) $length - $index * 8));
            $mask = $bits === 0 ? 0 : (255 << (8 - $bits)) & 255;
            $start[$index] = chr(ord($start[$index]) & $mask);
            $end[$index] = chr(ord($start[$index]) | (255 ^ $mask));
        }
        return [bin2hex($start), bin2hex($end)];
    }

    private function malformed($value, string $field): bool { return $value !== null && $value !== '' && $this->range($value, $field) === null; }
    private static function overlaps(array $left, array $right): bool { return strcmp($left[0], $right[1]) <= 0 && strcmp($right[0], $left[1]) <= 0; }

    private function schema(SQLite3 $database): void
    {
        $found = false;
        foreach (['network','services','config'] as $table) {
            $columns = $this->columns($database, $table);
            if (!$columns) { if ($table === 'config') throw new InvalidArgumentException('repair_schema_unsupported'); continue; }
            $primary = [];
            foreach ($columns as $name => $column) if ($column['pk']) $primary[] = $name;
            if ($primary !== [$table === 'config' ? 'key' : 'id']) throw new InvalidArgumentException('repair_schema_unsupported');
            if ($table !== 'config') $found = true;
        }
        if (!$found || $database->querySingle("SELECT count(*) FROM sqlite_master WHERE type IN ('trigger','view') AND (name IN ('network','services','config') OR tbl_name IN ('network','services','config'))")) {
            throw new InvalidArgumentException('repair_schema_unsupported');
        }
    }

    private function locks(): array
    {
        $identity = realpath($this->jobsPath) ?: (realpath(dirname($this->jobsPath)) ?: dirname($this->jobsPath)) . DIRECTORY_SEPARATOR . basename($this->jobsPath);
        if (PHP_OS_FAMILY === 'Windows') $identity = strtolower($identity);
        $directory = dirname($identity) . DIRECTORY_SEPARATOR . '.uisp-queue-locks-' . hash('sha256', $identity);
        if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory))) throw new InvalidArgumentException('repair_busy');
        $paths = [$directory . DIRECTORY_SEPARATOR . 'maintenance.lock', dirname($this->dataPath) . DIRECTORY_SEPARATOR . 'ip-sync.lock',
            dirname($this->dataPath) . DIRECTORY_SEPARATOR . 'setup.lock', dirname($this->dataPath) . DIRECTORY_SEPARATOR . 'backup.lock'];
        $locks = [];
        try {
            foreach ($paths as $path) {
                if (is_link($path) || (file_exists($path) && !is_file($path))) throw new InvalidArgumentException('repair_busy');
                $lock = @fopen($path, 'c+b');
                if (!$lock) throw new InvalidArgumentException('repair_busy');
                if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); throw new InvalidArgumentException('repair_busy'); }
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
        $path = dirname($this->dataPath) . DIRECTORY_SEPARATOR . $name; $temporary = $path . '.tmp'; $reader = null; $destination = null;
        try {
            $file = @fopen($temporary, 'x');
            if (!$file) throw new RuntimeException('backup_failed');
            fclose($file);
            if (!chmod($temporary, 0600)) throw new RuntimeException('backup_failed');
            if ($this->backup !== null) {
                if (($this->backup)($this->dataPath, $temporary) !== true) throw new RuntimeException('backup_failed');
            } else {
                $reader = new SQLite3($this->dataPath, SQLITE3_OPEN_READONLY); $reader->enableExceptions(true); $reader->busyTimeout(1000);
                $destination = new SQLite3($temporary, SQLITE3_OPEN_READWRITE); $destination->enableExceptions(true);
                if (!$reader->backup($destination)) throw new RuntimeException('backup_failed');
                $destination->close(); $destination = null; $reader->close(); $reader = null;
            }
            if (!chmod($temporary, 0600)) throw new RuntimeException('backup_failed');
            $check = new SQLite3($temporary, SQLITE3_OPEN_READONLY);
            try { if ($check->querySingle('PRAGMA integrity_check') !== 'ok') throw new RuntimeException('backup_failed'); }
            finally { $check->close(); }
            if (!rename($temporary, $path) || !chmod($path, 0600)) throw new RuntimeException('backup_failed');
            return $name;
        } catch (Throwable $error) { throw new InvalidArgumentException('repair_backup_failed', 0, $error); }
        finally {
            if ($destination !== null) $destination->close(); if ($reader !== null) $reader->close();
            if (is_file($temporary)) @unlink($temporary);
        }
    }

    private function pruneTokens(): void
    {
        $active = 0;
        foreach (glob(dirname($this->dataPath) . DIRECTORY_SEPARATOR . 'network-repair-preview-*.json') ?: [] as $path) {
            if (is_link($path) || !preg_match('/^network-repair-preview-[a-f0-9]{64}\.json$/D', basename($path))) continue;
            $date = filemtime($path);
            if ($date !== false && $date + self::TTL + 60 < $this->now()) { @unlink($path); continue; }
            $active++;
        }
        if ($active >= 64) throw new InvalidArgumentException('repair_preview_limit');
    }

    private function writeToken(string $path, array $state): void
    {
        $stream = @fopen($path, 'x');
        if (!$stream) throw new InvalidArgumentException('repair_preview_write_failed');
        try {
            if (!chmod($path, 0600)) throw new InvalidArgumentException('repair_preview_write_failed');
            $json = json_encode($state, JSON_THROW_ON_ERROR);
            if (fwrite($stream, $json) !== strlen($json) || !fflush($stream)) throw new InvalidArgumentException('repair_preview_write_failed');
        } catch (Throwable $error) { fclose($stream); @unlink($path); throw $error; }
        fclose($stream);
    }

    private function tokenPath(string $token): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new InvalidArgumentException('invalid_repair_token');
        return dirname($this->dataPath) . DIRECTORY_SEPARATOR . 'network-repair-preview-' . $token . '.json';
    }
    private function open(bool $write): SQLite3
    {
        if (!is_file($this->dataPath) || is_link($this->dataPath)) throw new InvalidArgumentException('repair_database_missing');
        $database = new SQLite3($this->dataPath, $write ? SQLITE3_OPEN_READWRITE : SQLITE3_OPEN_READONLY);
        $database->enableExceptions(true); $database->busyTimeout(1000); return $database;
    }
    private function identity(): string { return hash('sha256', (string) realpath($this->dataPath)); }
    private function now(): float { return $this->clock === null ? microtime(true) : (float) ($this->clock)(); }
    private function timestamp(): string { return gmdate('Y-m-d\TH:i:s\Z', (int) $this->now()); }
    private static function family(string $field): string { return $field === 'address' ? 'IPv4' : 'IPv6'; }
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
    private static function selection(array $values): array
    {
        if (!$values || count($values) > self::MAX_SERVICES) throw new InvalidArgumentException('repair_service_limit');
        $selection = [];
        foreach ($values as $value) {
            $value = is_object($value) ? (array) $value : $value;
            if (!is_array($value) || array_diff(array_keys($value), ['serviceId','fields']) || self::id($value['serviceId'] ?? null) === null
                || !is_array($value['fields'] ?? null) || !$value['fields'] || count($value['fields']) > 2) {
                throw new InvalidArgumentException('invalid_repair_selection');
            }
            foreach ($value['fields'] as $field) if (!is_string($field) || !in_array($field, ['address','address6'], true)) {
                throw new InvalidArgumentException('invalid_repair_selection');
            }
            if (count(array_unique($value['fields'])) !== count($value['fields'])) throw new InvalidArgumentException('invalid_repair_selection');
            $id = self::id($value['serviceId']);
            if (isset($selection[$id])) throw new InvalidArgumentException('invalid_repair_selection');
            $selection[$id] = ['serviceId' => $id, 'fields' => $value['fields']];
        }
        return array_values($selection);
    }
    private function columns(SQLite3 $database, string $table): array
    {
        $columns = [];
        foreach ($this->query($database, 'PRAGMA table_info("' . $table . '")') as $column) $columns[$column['name']] = $column;
        return $columns;
    }
    private function row(SQLite3 $database, string $table, int $id): ?array
    {
        if (!isset($this->columns($database, $table)['id'])) return null;
        return $this->query($database, 'SELECT * FROM "' . $table . '" WHERE id=?', [$id])[0] ?? null;
    }
    private function query(SQLite3 $database, string $sql, array $values = []): array
    {
        $statement = $database->prepare($sql);
        try {
            foreach ($values as $index => $value) $statement->bindValue($index + 1, $value,
                $value === null ? SQLITE3_NULL : (is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT));
            $result = $statement->execute();
            try { $rows = []; while ($row = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $row; return $rows; }
            finally { $result->finalize(); }
        } finally { $statement->close(); }
    }
    private function update(SQLite3 $database, string $sql, array $values): int
    {
        $statement = $database->prepare($sql);
        try {
            foreach ($values as $index => $value) $statement->bindValue($index + 1, $value,
                $value === null ? SQLITE3_NULL : (is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT));
            $result = $statement->execute();
            try { return $database->changes(); } finally { $result->finalize(); }
        } finally { $statement->close(); }
    }
}
