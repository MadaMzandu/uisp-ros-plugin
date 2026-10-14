<?php

require_once __DIR__ . '/api_network_address_evidence.php';

/** Independent, read-only CRM scans. This state never participates in allocation or jobs. */
final class ApiCrmNetworkMonitor
{
    public const INTERVAL_SECONDS = 300;
    public const PAGE_SIZE = 500;
    public const MAX_REQUESTS = 3;
    private const BUDGET_SECONDS = 12.0;
    private const MAX_SERVICES = 100000;
    private const APPLICATION_ID = 1129467214;
    private const SCHEMA_VERSION = 3;
    private $request;
    private $clock;
    private string $dataPath;
    private string $statePath;

    public function __construct(?callable $request = null, string $dataPath = 'data/data.db', string $statePath = 'data/crm-network-monitor.db', ?callable $clock = null)
    {
        $this->request = $request;
        $this->dataPath = $dataPath;
        $this->statePath = $statePath;
        $this->clock = $clock;
    }

    /** A scheduling failure is diagnostic only and cannot fail a router worker. */
    public function tick(): array
    {
        $database = null;
        $lock = null;
        try {
            $this->assertPaths();
            $lockPath = $this->statePath . '.lock';
            if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) throw new RuntimeException('state_unavailable');
            $lock = fopen($lockPath, 'c+b');
            if ($lock === false) throw new RuntimeException('state_unavailable');
            if (!flock($lock, LOCK_EX | LOCK_NB)) return $this->read();
            if (!chmod($lockPath, 0600)) throw new RuntimeException('state_unavailable');
            $database = $this->openState(true);
            $meta = $this->metadata($database);
            $now = $this->now();
            $mapping = $this->configuredMapping();
            if ($mapping === null || ($mapping['address'] === null && $mapping['address6'] === null)) {
                $this->failCycle($database, 'mapping_unavailable', $now);
                return $this->read();
            }
            $configHash = ApiNetworkAddressEvidence::mappingFingerprint($mapping);
            $active = $meta['cycle_started_at'] !== null;
            $configChanged = $meta['config_fingerprint'] !== $configHash;
            if (!$active && !$configChanged && $meta['last_attempt_at'] !== null && $now - $meta['last_attempt_at'] < self::INTERVAL_SECONDS) return $this->read();
            $deadline = $now + self::BUDGET_SECONDS;
            $requests = 0;
            try {
                if (!$active || $configChanged) {
                    $statement = $database->prepare('UPDATE monitor_meta SET config_fingerprint=:config,last_attempt_at=:now WHERE id=1');
                    $statement->bindValue(':config', $configHash, SQLITE3_TEXT);
                    $statement->bindValue(':now', $now, SQLITE3_FLOAT);
                    $statement->execute()->finalize(); $statement->close();
                }
                $requests++;
                $catalog = $this->get('custom-attributes', []);
                if (!is_array($catalog) || count($catalog) > 10000) throw new RuntimeException('mapping_unavailable');
                $validated = ApiNetworkAddressEvidence::validateMapping($mapping, $catalog);
                if ($validated['address'] === null && $validated['address6'] === null) throw new RuntimeException('mapping_unavailable');
                $catalogHash = ApiNetworkAddressEvidence::mappingFingerprint($validated);
                if (!$active || $configChanged || $meta['mapping_fingerprint'] !== $catalogHash) {
                    $this->beginCycle($database, $now, $configHash, $catalogHash, $validated, $mapping);
                    $meta = $this->metadata($database);
                }
                while ($requests < self::MAX_REQUESTS && $this->now() < $deadline) {
                    $offset = $meta['scan_offset'];
                    if ($offset >= self::MAX_SERVICES) throw new RuntimeException('scan_limit');
                    $requests++;
                    $page = $this->get('clients/services', ['offset' => $offset, 'limit' => self::PAGE_SIZE]);
                    $rows = $this->parsePage($page, $validated, $mapping);
                    $currentMapping = $this->configuredMapping();
                    if ($currentMapping === null || ApiNetworkAddressEvidence::mappingFingerprint($currentMapping) !== $configHash) throw new RuntimeException('mapping_changed');
                    $this->stage($database, $rows, $offset);
                    $meta = $this->metadata($database);
                    if (count($rows) < self::PAGE_SIZE) {
                        $this->publish($database, $this->now());
                        break;
                    }
                }
            } catch (Throwable $failure) {
                $reason = $failure instanceof RuntimeException && in_array($failure->getMessage(), self::errors(), true) ? $failure->getMessage() : 'crm_unavailable';
                $this->failCycle($database, $reason, $this->now());
            }
            return $this->read();
        } catch (Throwable $failure) {
            $report = $this->read();
            $report['last_error'] = 'state_unavailable';
            $report['stale'] = true;
            return $report;
        } finally {
            if ($database !== null) $database->close();
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    /** Existing files only: no API, state creation, migration, cache, or queue writes. */
    public function read(): array
    {
        $report = $this->emptyReport();
        $database = null;
        try {
            $this->assertPaths();
            if (!is_file($this->statePath)) return $report;
            $database = $this->openState(false);
            $database->exec('BEGIN');
            $meta = $this->metadata($database);
            $report['scan_in_progress'] = $meta['cycle_started_at'] !== null;
            $report['scan_started_at'] = self::timestamp($meta['cycle_started_at']);
            $report['services_scanned'] = $meta['scan_offset'];
            $report['snapshot_services_count'] = $meta['published_count'];
            $report['last_error'] = $meta['last_error'];
            $report['checked_at'] = self::timestamp($meta['published_at']);
            $report['families'] = $this->families($meta['published_mapping']);
            if ($meta['published_at'] === null) return $report;
            $mapping = $this->configuredMapping();
            if ($mapping === null || $meta['published_config_fingerprint'] !== ApiNetworkAddressEvidence::mappingFingerprint($mapping)) {
                $report['last_error'] = 'mapping_changed';
                return $report;
            }
            foreach (['address' => 'IPv4', 'address6' => 'IPv6'] as $field => $family) {
                if ($mapping[$field] !== null && !in_array($family, $report['families'], true)) $report['unavailable_families'][] = $family;
            }
            $report['coverage_complete'] = !$report['unavailable_families'];
            if ((int) $database->querySingle('SELECT COUNT(*) FROM snapshot_services') !== $meta['published_count']
                || (int) $database->querySingle("SELECT COUNT(*) FROM snapshot_services WHERE typeof(id)<>'integer' OR id<1 OR typeof(client_id)<>'integer' OR client_id<1 OR typeof(status)<>'integer' OR status<0 OR status>8 OR typeof(address_known)<>'integer' OR address_known NOT IN (0,1) OR typeof(address6_known)<>'integer' OR address6_known NOT IN (0,1) OR (address IS NOT NULL AND (typeof(address)<>'text' OR length(address)>15)) OR (address6 IS NOT NULL AND (typeof(address6)<>'text' OR length(address6)>43))") !== 0) throw new RuntimeException('state_unavailable');
            foreach (['address', 'address6'] as $field) {
                $reason = $field . '_reason';
                $known = $field . '_known';
                if ((int) $database->querySingle('SELECT COUNT(*) FROM snapshot_services WHERE (' . $known . '=1 AND ' . $reason . ' IS NOT NULL) OR (' . $known . '=0 AND (' . $reason . " IS NULL OR " . $reason . " NOT IN ('mapping_unknown','attributes_unknown','attribute_ambiguous','value_unknown','value_invalid')))") !== 0) throw new RuntimeException('state_unavailable');
            }
            foreach (['address', 'address6'] as $field) {
                // An unsupported optional family without historical ownership
                // is a coverage limitation, not one bad address per customer.
                $report['unknown_address_count'] += (int) $database->querySingle('SELECT COUNT(*) FROM snapshot_services WHERE ' . $field . '_known=0 AND (' . $field . "_reason<>'mapping_unknown' OR " . $field . ' IS NOT NULL)');
            }
            $report['address_issues'] = $this->issues($database);
            $report['address_issues_truncated'] = $report['unknown_address_count'] > count($report['address_issues']);
            if ($report['unknown_address_count'] > 0) $report['coverage_complete'] = false;
            $report['available'] = true;
            $report['stale'] = $report['unknown_address_count'] > 0 || $meta['last_error'] !== null || $this->now() - $meta['published_at'] >= self::INTERVAL_SECONDS * 2;
            if (!$report['scan_in_progress']) $report['services_scanned'] = $meta['published_count'];
            foreach (['address' => 'IPv4', 'address6' => 'IPv6'] as $field => $family) {
                $result = $database->query('SELECT ' . $field . ' AS address,COUNT(*) AS owners FROM snapshot_services WHERE ' . $field . ' IS NOT NULL GROUP BY ' . $field . ' HAVING COUNT(*)>1 ORDER BY ' . $field);
                try {
                    while ($group = $result->fetchArray(SQLITE3_ASSOC)) {
                        $report['duplicate_groups']++;
                        if (count($report['conflicts']) >= 20) continue;
                        $owners = $this->owners($database, $field, $group['address']);
                        $unknownCount = $this->unknownGroupOwners($database, $field, $group['address']);
                        $report['conflicts'][] = ['family' => $family, 'address' => $group['address'],
                            'service_ids' => array_column($owners, 'service_id'), 'service_count' => (int) $group['owners'],
                            'services_truncated' => (int) $group['owners'] > count($owners), 'owners' => $owners,
                            'current_confirmed' => $unknownCount === 0, 'stale' => $unknownCount > 0];
                    }
                } finally { $result->finalize(); }
            }
            $report['conflicts_truncated'] = $report['duplicate_groups'] > count($report['conflicts']);
            return $report;
        } catch (Throwable $failure) {
            $report = $this->emptyReport();
            $report['last_error'] = 'state_unavailable';
            return $report;
        } finally { if ($database !== null) $database->close(); }
    }

    private function emptyReport(): array
    {
        return ['available' => false, 'duplicate_groups' => 0, 'conflicts' => [], 'conflicts_truncated' => false,
            'checked_at' => null, 'scan_started_at' => null, 'scan_in_progress' => false, 'last_error' => null,
            'stale' => true, 'services_scanned' => 0, 'snapshot_services_count' => 0, 'families' => [], 'unavailable_families' => [],
            'coverage_complete' => false, 'unknown_address_count' => 0, 'address_issues' => [], 'address_issues_truncated' => false,
            'interval_seconds' => self::INTERVAL_SECONDS,
            'source' => 'crm_snapshot', 'warning_only' => true];
    }

    private function assertPaths(): void
    {
        $dataDirectory = realpath(dirname($this->dataPath));
        $stateDirectory = realpath(dirname($this->statePath));
        if ($dataDirectory === false || $stateDirectory === false || $dataDirectory !== $stateDirectory
            || is_link($this->dataPath) || !is_file($this->dataPath) || is_link($this->statePath)
            || (file_exists($this->statePath) && !is_file($this->statePath))
            || (is_file($this->statePath) && filesize($this->statePath) > 134217728)
            || realpath($this->dataPath) === realpath($this->statePath)) throw new RuntimeException('state_unavailable');
    }

    private function openState(bool $write): SQLite3
    {
        $created = !file_exists($this->statePath);
        if ($created && !$write) throw new RuntimeException('state_unavailable');
        if ($created) {
            $file = fopen($this->statePath, 'x+b');
            if ($file === false) throw new RuntimeException('state_unavailable');
            fclose($file);
            if (!chmod($this->statePath, 0600)) throw new RuntimeException('state_unavailable');
        }
        $database = new SQLite3($this->statePath, $write ? SQLITE3_OPEN_READWRITE : SQLITE3_OPEN_READONLY);
        try {
            $database->enableExceptions(true);
            $database->busyTimeout(100);
            if ($write && !chmod($this->statePath, 0600)) throw new RuntimeException('state_unavailable');
            if ($created) {
                $database->exec('PRAGMA journal_mode=DELETE; PRAGMA application_id=' . self::APPLICATION_ID . '; PRAGMA user_version=' . self::SCHEMA_VERSION);
                $database->exec('BEGIN IMMEDIATE');
                $database->exec('CREATE TABLE monitor_meta(id INTEGER PRIMARY KEY CHECK(id=1),published_at REAL,published_count INTEGER NOT NULL DEFAULT 0,published_config_fingerprint TEXT,published_mapping TEXT,cycle_started_at REAL,last_attempt_at REAL,scan_offset INTEGER NOT NULL DEFAULT 0,config_fingerprint TEXT,mapping_fingerprint TEXT,mapping TEXT,last_error TEXT,published_keys TEXT,cycle_keys TEXT);
                    INSERT INTO monitor_meta(id) VALUES(1);
                    CREATE TABLE snapshot_services(id INTEGER PRIMARY KEY CHECK(id>0),client_id INTEGER NOT NULL CHECK(client_id>0),status INTEGER NOT NULL CHECK(status BETWEEN 0 AND 8),address TEXT,address6 TEXT,address_known INTEGER NOT NULL CHECK(address_known IN (0,1)),address6_known INTEGER NOT NULL CHECK(address6_known IN (0,1)),address_reason TEXT,address6_reason TEXT);
                    CREATE TABLE staging_services(id INTEGER PRIMARY KEY CHECK(id>0),client_id INTEGER NOT NULL CHECK(client_id>0),status INTEGER NOT NULL CHECK(status BETWEEN 0 AND 8),address TEXT,address6 TEXT,address_known INTEGER NOT NULL CHECK(address_known IN (0,1)),address6_known INTEGER NOT NULL CHECK(address6_known IN (0,1)),address_reason TEXT,address6_reason TEXT);
                    CREATE INDEX snapshot_address ON snapshot_services(address);
                    CREATE INDEX snapshot_address6 ON snapshot_services(address6)');
                $database->exec('COMMIT');
            }
            if ((int) $database->querySingle('PRAGMA application_id') !== self::APPLICATION_ID
                || (int) $database->querySingle('PRAGMA user_version') !== self::SCHEMA_VERSION
                || strtolower((string) $database->querySingle('PRAGMA journal_mode')) !== 'delete'
                || (int) $database->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type IN ('trigger','view')") !== 0
                || (int) $database->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'") !== 3) throw new RuntimeException('state_unavailable');
            foreach (['snapshot_services', 'staging_services'] as $table) {
                $expected = ['id', 'client_id', 'status', 'address', 'address6', 'address_known', 'address6_known', 'address_reason', 'address6_reason'];
                if ($this->columns($database, $table) !== $expected) throw new RuntimeException('state_unavailable');
            }
            if ($this->columns($database, 'monitor_meta') !== ['id', 'published_at', 'published_count', 'published_config_fingerprint', 'published_mapping', 'cycle_started_at', 'last_attempt_at', 'scan_offset', 'config_fingerprint', 'mapping_fingerprint', 'mapping', 'last_error', 'published_keys', 'cycle_keys']) throw new RuntimeException('state_unavailable');
            $this->metadata($database);
            return $database;
        } catch (Throwable $failure) { $database->close(); throw $failure; }
    }

    private function columns(SQLite3 $database, string $table): array
    {
        $columns = [];
        $result = $database->query('PRAGMA table_info(' . $table . ')');
        try { while ($row = $result->fetchArray(SQLITE3_ASSOC)) $columns[] = $row['name']; }
        finally { $result->finalize(); }
        return $columns;
    }

    private function metadata(SQLite3 $database): array
    {
        if ((int) $database->querySingle('SELECT COUNT(*) FROM monitor_meta') !== 1) throw new RuntimeException('state_unavailable');
        $meta = $database->querySingle('SELECT * FROM monitor_meta WHERE id=1', true);
        if (!is_array($meta) || !$meta) throw new RuntimeException('state_unavailable');
        foreach (['published_count', 'scan_offset'] as $key) {
            if (!is_int($meta[$key]) || $meta[$key] < 0 || $meta[$key] > self::MAX_SERVICES) throw new RuntimeException('state_unavailable');
        }
        foreach (['published_at', 'cycle_started_at', 'last_attempt_at'] as $key) {
            if ($meta[$key] !== null && (!is_numeric($meta[$key]) || !is_finite((float) $meta[$key]) || $meta[$key] < 0)) throw new RuntimeException('state_unavailable');
        }
        foreach (['published_config_fingerprint', 'config_fingerprint', 'mapping_fingerprint'] as $key) {
            if ($meta[$key] !== null && (!is_string($meta[$key]) || preg_match('/^[a-f0-9]{64}$/D', $meta[$key]) !== 1)) throw new RuntimeException('state_unavailable');
        }
        if ($meta['last_error'] !== null && !in_array($meta['last_error'], self::errors(), true)) throw new RuntimeException('state_unavailable');
        foreach (['mapping', 'published_mapping'] as $key) {
            if ($meta[$key] === null) continue;
            $decoded = json_decode($meta[$key], true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || array_keys($decoded) !== ['address', 'address6']) throw new RuntimeException('state_unavailable');
            $this->families($meta[$key]);
        }
        foreach (['published_keys', 'cycle_keys'] as $key) {
            if ($meta[$key] === null) continue;
            $this->rawKeys($meta[$key]);
        }
        return $meta;
    }

    private function configuredMapping(): ?array
    {
        if (is_link($this->dataPath) || !is_file($this->dataPath)) return null;
        $database = new SQLite3($this->dataPath, SQLITE3_OPEN_READONLY);
        try { $database->enableExceptions(true); $database->busyTimeout(100); return ApiNetworkAddressEvidence::mapping($database); }
        finally { $database->close(); }
    }

    private function beginCycle(SQLite3 $database, float $now, string $configHash, string $catalogHash, array $mapping, array $keys): void
    {
        $database->exec('BEGIN IMMEDIATE');
        try {
            $database->exec('DELETE FROM staging_services');
            $statement = $database->prepare('UPDATE monitor_meta SET cycle_started_at=:now,last_attempt_at=:now,scan_offset=0,config_fingerprint=:config,mapping_fingerprint=:catalog,mapping=:mapping,cycle_keys=:keys,last_error=NULL WHERE id=1');
            $statement->bindValue(':now', $now, SQLITE3_FLOAT);
            $statement->bindValue(':config', $configHash, SQLITE3_TEXT);
            $statement->bindValue(':catalog', $catalogHash, SQLITE3_TEXT);
            $statement->bindValue(':mapping', json_encode($mapping, JSON_THROW_ON_ERROR), SQLITE3_TEXT);
            $statement->bindValue(':keys', json_encode($keys, JSON_THROW_ON_ERROR), SQLITE3_TEXT);
            $statement->execute()->finalize(); $statement->close();
            $database->exec('COMMIT');
        } catch (Throwable $failure) { $database->exec('ROLLBACK'); throw $failure; }
    }

    private function parsePage($page, array $mapping, array $configured): array
    {
        if (!is_array($page) || count($page) > self::PAGE_SIZE || ($page && array_keys($page) !== range(0, count($page) - 1))) throw new RuntimeException('invalid_service_page');
        $rows = [];
        $ids = [];
        foreach ($page as $item) {
            if (!is_array($item) && !is_object($item)) throw new RuntimeException('invalid_service_page');
            $item = (array) $item;
            if (count($item) > 256 || (isset($item['attributes']) && is_array($item['attributes']) && count($item['attributes']) > 1000)) throw new RuntimeException('invalid_service_page');
            $id = self::positiveId($item['id'] ?? null);
            $clientId = self::positiveId($item['clientId'] ?? null);
            $status = self::status($item['status'] ?? null);
            if ($id === null || $clientId === null || $status === null || isset($ids[$id])) throw new RuntimeException('invalid_service_page');
            $ids[$id] = true;
            $evidence = ApiNetworkAddressEvidence::parse($item, $mapping);
            $row = ['id' => $id, 'client_id' => $clientId, 'status' => $status, 'address' => null, 'address6' => null];
            foreach (['address', 'address6'] as $field) {
                $known = $configured[$field] === null || $evidence[$field]['known'] === true;
                $reason = $known ? null : $evidence[$field]['reason'];
                if ($reason !== null && !in_array($reason, self::addressErrors(), true)) $reason = 'attributes_unknown';
                $row[$field] = $known ? $evidence[$field]['value'] : null;
                $row[$field . '_known'] = $known ? 1 : 0;
                $row[$field . '_reason'] = $reason;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function stage(SQLite3 $database, array $rows, int $offset): void
    {
        $database->exec('BEGIN IMMEDIATE');
        try {
            $statement = $database->prepare('INSERT INTO staging_services(id,client_id,status,address,address6,address_known,address6_known,address_reason,address6_reason) VALUES(:id,:client_id,:status,:address,:address6,:address_known,:address6_known,:address_reason,:address6_reason)');
            foreach ($rows as $row) {
                foreach ($row as $key => $value) $statement->bindValue(':' . $key, $value, $value === null ? SQLITE3_NULL : (is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT));
                try { $statement->execute()->finalize(); }
                catch (Throwable $failure) { throw new RuntimeException('invalid_service_page', 0, $failure); }
            }
            $statement->close();
            $statement = $database->prepare('UPDATE monitor_meta SET scan_offset=:offset WHERE id=1');
            $statement->bindValue(':offset', $offset + count($rows), SQLITE3_INTEGER);
            $statement->execute()->finalize(); $statement->close();
            $database->exec('COMMIT');
        } catch (Throwable $failure) { $database->exec('ROLLBACK'); throw $failure; }
    }

    private function publish(SQLite3 $database, float $now): void
    {
        $database->exec('BEGIN IMMEDIATE');
        try {
            $meta = $this->metadata($database);
            $oldKeys = $this->rawKeys($meta['published_keys']);
            $newKeys = $this->rawKeys($meta['cycle_keys']);
            $oldMapping = $meta['published_mapping'] === null ? [] : json_decode($meta['published_mapping'], true, 8, JSON_THROW_ON_ERROR);
            $newMapping = json_decode($meta['mapping'], true, 8, JSON_THROW_ON_ERROR);
            $carry = [];
            foreach (['address', 'address6'] as $field) {
                $newDescriptor = $newMapping[$field] ?? null;
                $oldDescriptor = $oldMapping[$field] ?? null;
                $carry[$field] = $newKeys[$field] !== null && $newKeys[$field] === $oldKeys[$field]
                    && ($newDescriptor === null || $newDescriptor === $oldDescriptor);
            }
            // Unknown evidence preserves only this exact service's last known
            // address under the same attribute provenance. Missing catalog
            // evidence may retain the same key across repeated unknown cycles;
            // a renamed key or replacement attribute ID cannot inherit it.
            $statement = $database->prepare('INSERT OR REPLACE INTO snapshot_services(id,client_id,status,address,address6,address_known,address6_known,address_reason,address6_reason)
                SELECT fresh.id,fresh.client_id,fresh.status,
                    CASE WHEN fresh.address_known=1 THEN fresh.address WHEN :carry4=1 THEN old.address ELSE NULL END,
                    CASE WHEN fresh.address6_known=1 THEN fresh.address6 WHEN :carry6=1 THEN old.address6 ELSE NULL END,
                    fresh.address_known,fresh.address6_known,fresh.address_reason,fresh.address6_reason
                FROM staging_services fresh LEFT JOIN snapshot_services old ON old.id=fresh.id');
            $statement->bindValue(':carry4', $carry['address'] ? 1 : 0, SQLITE3_INTEGER);
            $statement->bindValue(':carry6', $carry['address6'] ? 1 : 0, SQLITE3_INTEGER);
            $statement->execute()->finalize(); $statement->close();
            $database->exec('DELETE FROM snapshot_services WHERE id NOT IN (SELECT id FROM staging_services)');
            $statement = $database->prepare('UPDATE monitor_meta SET published_at=:now,published_count=scan_offset,published_config_fingerprint=config_fingerprint,published_mapping=mapping,published_keys=cycle_keys,cycle_started_at=NULL,last_error=NULL WHERE id=1');
            $statement->bindValue(':now', $now, SQLITE3_FLOAT);
            $statement->execute()->finalize(); $statement->close();
            $database->exec('DELETE FROM staging_services; COMMIT');
        } catch (Throwable $failure) { $database->exec('ROLLBACK'); throw $failure; }
    }

    private function failCycle(SQLite3 $database, string $reason, float $now): void
    {
        $database->exec('BEGIN IMMEDIATE');
        try {
            $database->exec('DELETE FROM staging_services');
            $statement = $database->prepare('UPDATE monitor_meta SET cycle_started_at=NULL,last_attempt_at=:now,scan_offset=0,last_error=:error WHERE id=1');
            $statement->bindValue(':now', $now, SQLITE3_FLOAT);
            $statement->bindValue(':error', $reason, SQLITE3_TEXT);
            $statement->execute()->finalize(); $statement->close();
            $database->exec('COMMIT');
        } catch (Throwable $failure) { $database->exec('ROLLBACK'); throw $failure; }
    }

    private function owners(SQLite3 $database, string $field, string $address): array
    {
        $statement = $database->prepare('SELECT * FROM snapshot_services WHERE ' . $field . '=:address ORDER BY id LIMIT 50');
        $statement->bindValue(':address', $address, SQLITE3_TEXT);
        $result = $statement->execute();
        $owners = [];
        try {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $id = self::positiveId($row['id']); $client = self::positiveId($row['client_id']); $status = self::status($row['status']);
                if ($id === null || $client === null || $status === null || ApiNetworkAddressEvidence::normalize($address, $field) !== $address) throw new RuntimeException('state_unavailable');
                $owners[] = ['service_id' => $id, 'client_id' => $client, 'service_status_code' => $status,
                    'service_status' => self::statusLabel($status), 'service_url' => '/crm/client/service/' . $id,
                    'client_url' => '/crm/client/' . $client, 'source' => 'crm_snapshot',
                    'current_address' => $row[$field . '_known'] === 1 ? $address : null,
                    'current_address_known' => $row[$field . '_known'] === 1, 'saved_address' => $address,
                    'address_refresh_error' => $row[$field . '_reason']];
            }
        } finally { $result->finalize(); $statement->close(); }
        return $owners;
    }

    private function unknownGroupOwners(SQLite3 $database, string $field, string $address): int
    {
        $statement = $database->prepare('SELECT COUNT(*) FROM snapshot_services WHERE ' . $field . '=:address AND ' . $field . '_known=0');
        $statement->bindValue(':address', $address, SQLITE3_TEXT);
        $result = $statement->execute();
        try { return (int) $result->fetchArray(SQLITE3_NUM)[0]; }
        finally { $result->finalize(); $statement->close(); }
    }

    private function issues(SQLite3 $database): array
    {
        $result = $database->query("SELECT * FROM (SELECT id,client_id,'IPv4' AS family,address_reason AS reason FROM snapshot_services WHERE address_known=0 AND (address_reason<>'mapping_unknown' OR address IS NOT NULL)
            UNION ALL SELECT id,client_id,'IPv6' AS family,address6_reason AS reason FROM snapshot_services WHERE address6_known=0 AND (address6_reason<>'mapping_unknown' OR address6 IS NOT NULL))
            ORDER BY CASE reason WHEN 'value_invalid' THEN 0 WHEN 'attribute_ambiguous' THEN 1 WHEN 'value_unknown' THEN 2 WHEN 'attributes_unknown' THEN 3 ELSE 4 END,id,family LIMIT 20");
        $issues = [];
        try {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $id = self::positiveId($row['id']); $client = self::positiveId($row['client_id']);
                if ($id === null || $client === null || !in_array($row['reason'], self::addressErrors(), true)) throw new RuntimeException('state_unavailable');
                $issues[] = ['service_id' => $id, 'client_id' => $client, 'family' => $row['family'], 'reason' => $row['reason'],
                    'service_url' => '/crm/client/service/' . $id, 'client_url' => '/crm/client/' . $client];
            }
        } finally { $result->finalize(); }
        return $issues;
    }

    private function families(?string $json): array
    {
        if ($json === null) return [];
        $mapping = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        $families = [];
        foreach (['address' => 'IPv4', 'address6' => 'IPv6'] as $field => $family) {
            $descriptor = $mapping[$field] ?? null;
            if ($descriptor === null) continue;
            if (!is_array($descriptor) || !is_string($descriptor['key'] ?? null) || self::positiveId($descriptor['id'] ?? null) === null) throw new RuntimeException('state_unavailable');
            $families[] = $family;
        }
        return $families;
    }

    private function rawKeys(?string $json): array
    {
        if ($json === null) return ['address' => null, 'address6' => null];
        $keys = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($keys) || array_keys($keys) !== ['address', 'address6']) throw new RuntimeException('state_unavailable');
        foreach ($keys as $value) {
            if ($value !== null && (!is_string($value) || $value === '' || trim($value) !== $value || strlen($value) > 255
                || preg_match('/[\x00-\x1f\x7f]/', $value) || preg_match('//u', $value) !== 1)) throw new RuntimeException('state_unavailable');
        }
        return $keys;
    }

    private function get(string $path, array $options)
    {
        try {
            if ($this->request !== null) return ($this->request)($path, $options);
            require_once __DIR__ . '/api_ucrm.php';
            return (new ApiUcrm())->get($path, $options);
        } catch (Throwable $failure) { throw new RuntimeException('crm_unavailable', 0, $failure); }
    }

    private function now(): float
    {
        $value = $this->clock === null ? microtime(true) : (float) ($this->clock)();
        if (!is_finite($value) || $value < 0) throw new RuntimeException('state_unavailable');
        return $value;
    }
    private static function timestamp($value): ?string { return $value === null ? null : gmdate('c', (int) $value); }
    private static function positiveId($value): ?int
    {
        if (!is_int($value) && !is_string($value)) return null;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }
    private static function status($value): ?int
    {
        if (!is_int($value) && !is_string($value)) return null;
        $status = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 8]]);
        return $status === false ? null : $status;
    }
    private static function statusLabel(int $status): string
    {
        return [0 => 'Preparado', 1 => 'Activo', 2 => 'Terminado', 3 => 'Suspendido', 4 => 'Preparado bloqueado', 5 => 'Obsoleto', 6 => 'Diferido', 7 => 'Cotizado', 8 => 'Inactivo'][$status];
    }
    private static function errors(): array { return ['state_unavailable', 'mapping_unavailable', 'mapping_changed', 'crm_unavailable', 'invalid_service_page', 'unknown_address', 'scan_limit']; }
    private static function addressErrors(): array { return ['mapping_unknown', 'attributes_unknown', 'attribute_ambiguous', 'value_unknown', 'value_invalid']; }
}
