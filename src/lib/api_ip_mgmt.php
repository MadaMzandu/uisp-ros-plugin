<?php
require_once __DIR__ . '/api_ucrm.php';
require_once __DIR__ . '/api_sqlite.php';
require_once __DIR__ . '/api_crm_network_resolution.php';
require_once __DIR__ . '/api_site_address_policy.php';

/** A local reservation is not permission to replace a newer UISP attribute. */
final class ApiIpAttributeExport
{
    public static function allocation(array $row): ?array
    {
        $database = null;
        try {
            if (!is_file('data/jobs.db') || is_link('data/jobs.db')) return null;
            $database = new SQLite3('data/jobs.db', SQLITE3_OPEN_READONLY);
            $database->enableExceptions(true); $database->busyTimeout(500); $database->exec('BEGIN');
            if ($database->querySingle("SELECT count(*) FROM sqlite_master WHERE (type='view' AND name IN ('jobs','service_versions')) OR (type='trigger' AND tbl_name IN ('jobs','service_versions'))")) return null;
            $sid = self::id($row['id'] ?? null); $client = self::id($row['clientId'] ?? null); $device = self::id($row['device'] ?? null);
            if ($sid === null || $client === null || $device === null) return null;
            $query = $database->prepare('SELECT version,applied_payload FROM service_versions WHERE service_id=?');
            $query->bindValue(1, $sid, SQLITE3_INTEGER); $result = $query->execute(); $version = $result->fetchArray(SQLITE3_ASSOC); $result->finalize(); $query->close();
            if (!$version || !is_int($version['version']) || $version['version'] < 1 || !is_string($version['applied_payload']) || strlen($version['applied_payload']) > 1048576) return null;
            $query = $database->prepare('SELECT version,status,operation FROM jobs WHERE service_id=? ORDER BY version DESC,id DESC LIMIT 1');
            $query->bindValue(1, $sid, SQLITE3_INTEGER); $result = $query->execute(); $latest = $result->fetchArray(SQLITE3_ASSOC); $result->finalize(); $query->close();
            if (!$latest || $latest['version'] !== $version['version'] || $latest['status'] !== 'succeeded' || $latest['operation'] !== 'update') return null;
            $query = $database->prepare("SELECT count(*) FROM jobs WHERE service_id=? AND status IN ('pending','running')");
            $query->bindValue(1, $sid, SQLITE3_INTEGER); $result = $query->execute(); $active = $result->fetchArray(SQLITE3_NUM); $result->finalize(); $query->close();
            if (!$active || (int) $active[0] !== 0) return null;
            $query = $database->prepare('SELECT count(*) FROM service_event_locks WHERE service_id=?');
            $query->bindValue(1, $sid, SQLITE3_INTEGER); $result = $query->execute(); $events = $result->fetchArray(SQLITE3_NUM); $result->finalize(); $query->close();
            if (!$events || (int) $events[0] !== 0 || $database->querySingle("SELECT count(*) FROM queue_meta WHERE key='maintenance'")) return null;
            $snapshot = json_decode($version['applied_payload'], true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($snapshot) || self::id($snapshot['id'] ?? null) !== $sid || self::id($snapshot['clientId'] ?? null) !== $client
                || self::id($snapshot['device'] ?? null) !== $device || !in_array((int) ($snapshot['status'] ?? -1), [1,3,4], true)) return null;
            $proof = $snapshot['_pool_allocations']['address'] ?? null;
            if (!is_array($proof) || !is_string($proof['value'] ?? null) || !array_key_exists('previous', $proof)
                || (!is_string($proof['previous']) && $proof['previous'] !== null)
                || !is_string($proof['generation'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $proof['generation']) !== 1) return null;
            $value = ApiNetworkAddressEvidence::normalize($proof['value'], 'address');
            if ($value === null || $value !== $proof['value'] || $value !== ApiNetworkAddressEvidence::normalize((string) $row['address'], 'address')) return null;
            if ($proof['previous'] !== null && ApiNetworkAddressEvidence::normalize($proof['previous'], 'address') === $value) return null;
            $principal = 0;
            foreach ($snapshot['_commands'] ?? [] as $command) {
                if (!is_array($command) || ($command['action'] ?? 'set') === 'remove') continue;
                $path = trim($command['path'] ?? '', '/');
                $field = $path === 'ppp/secret' ? 'remote-address' : (in_array($path, ['ip/hotspot/user','ip/dhcp-server/lease'], true) ? 'address' : null);
                if ($field === null) continue;
                if (self::id($command['_service_id'] ?? null) !== $sid || self::id($command['_client_id'] ?? null) !== $client
                    || !is_string($command[$field] ?? null) || ApiNetworkAddressEvidence::normalize($command[$field], 'address') !== $value) return null;
                $principal++;
            }
            if ($principal !== 1) return null;
            return ['service_id' => $sid, 'client_id' => $client, 'device_id' => $device, 'version' => $version['version'],
                'generation' => $proof['generation'], 'value' => $value, 'snapshot' => hash('sha256', $version['applied_payload']),
                'principal' => array_intersect_key($snapshot, array_fill_keys(['device','username','mac','hotspot'], true))];
        } catch (Throwable $ignored) { return null; }
        finally { if ($database !== null) $database->close(); }
    }

    /** Claim before PATCH: an ambiguous response must never replay an older allocation after a later edit. */
    public static function claim(array $proof): bool
    {
        $path = 'data/ip-attribute-export-' . $proof['service_id'] . '-address.json';
        $temporary = null;
        try {
            if (is_link($path) || (file_exists($path) && (!is_file($path) || filesize($path) > 2048))) return false;
            if (is_file($path)) {
                $existing = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
                if (!is_array($existing) || ($existing['service_id'] ?? null) !== $proof['service_id'] || !is_int($existing['version'] ?? null)
                    || !is_string($existing['generation'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $existing['generation']) !== 1
                    || $existing['version'] >= $proof['version'] || $existing['generation'] === $proof['generation']) return false;
            }
            $temporary = $path . '.' . bin2hex(random_bytes(16)) . '.tmp';
            $stream = fopen($temporary, 'x+b');
            if (!$stream) return false;
            try {
                if (!chmod($temporary, 0600)) return false;
                $json = json_encode(['service_id' => $proof['service_id'], 'version' => $proof['version'], 'generation' => $proof['generation']], JSON_THROW_ON_ERROR);
                if (fwrite($stream, $json) !== strlen($json) || !fflush($stream)) return false;
            } finally { fclose($stream); }
            if (!rename($temporary, $path)) return false;
            $temporary = null; return true;
        } catch (Throwable $ignored) { return false; }
        finally { if ($temporary !== null && is_file($temporary)) @unlink($temporary); }
    }

    private static function id($value): ?int
    {
        if (!is_int($value) && !is_string($value)) return null;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }
}

function sync_ip_attributes(): void
{
    $lock = fopen('data/ip-sync.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) fclose($lock);
        return;
    }
    try {
        $config = is_file('data/config.json') ? json_decode(file_get_contents('data/config.json'), true) : [];
        $value = $config['syncAttrHour'] ?? null;
        if ($value === null || $value === '') return;
        $hour = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 23]]);
        if ($hour === false) throw new RuntimeException('invalid_ip_sync_hour');
        $now = new DateTimeImmutable('now', new DateTimeZone(($config['syncTimezone'] ?? '') ?: date_default_timezone_get()));
        $date = $now->format('Y-m-d');
        $db = mySqlite();
        if ((int) $now->format('G') !== $hour || ($db->readConfig()->sync_attr_date ?? $config['syncAttrDate'] ?? null) === $date) return;
        $key = $db->readConfig()->ip_addr_attr ?? null;
        if (!$key) return;
        $api = new ApiUcrm(null, true);
        $catalog = $api->get('custom-attributes');
        $mapping = ApiNetworkAddressEvidence::validateMapping(['address' => $key, 'address6' => null], $catalog);
        $attribute = $mapping['address']['id'] ?? null;
        if ($attribute === null) throw new RuntimeException('ip_attribute_missing');
        $rows = $db->selectCustom('SELECT network.id,network.address,services.clientId,services.device,services.status FROM network JOIN services ON network.id=services.id WHERE services.status NOT IN (0,2,5,6,8)');
        $complete = true;
        foreach ($rows as $row) {
            if (!$row['address']) continue;
            if (ApiCrmNetworkResolution::skipAttributeExport((int) $row['id'], 'address', (string) $row['address'], 'data', ['key' => $key, 'id' => (int) $attribute], (int) $row['clientId'])) continue;
            try {
                $address = ApiNetworkAddressEvidence::normalize((string) $row['address'], 'address');
                if ($address === null) continue;
                $service = $api->get('clients/services/' . (int) $row['id']);
                if (!is_array($service) || (!is_int($service['id'] ?? null) && !is_string($service['id'] ?? null))
                    || (!is_int($service['clientId'] ?? null) && !is_string($service['clientId'] ?? null))
                    || filter_var($service['id'] ?? null, FILTER_VALIDATE_INT) !== (int) $row['id']
                    || filter_var($service['clientId'] ?? null, FILTER_VALIDATE_INT) !== (int) $row['clientId']
                    || !in_array($service['status'] ?? null, [1,3,4,'1','3','4'], true)) continue;
                $evidence = ApiNetworkAddressEvidence::parse($service, $mapping)['address'];
                if (!$evidence['known']) { $complete = false; continue; }
                $proof = ApiIpAttributeExport::allocation($row);
                if ($evidence['value'] !== null) {
                    // A current explicit UISP address always wins. An equal value needs no PATCH.
                    if ($proof !== null) ApiIpAttributeExport::claim($proof);
                    continue;
                }
                // Empty UISP is not permission to export an old reservation. Only a
                // new, fully applied pool allocation can fill it once.
                if ($proof === null) continue;
                [$configuration, $devices] = ApiSiteAddressPolicy::stored('data/data.db');
                if (!ApiSiteAddressPolicy::currentPrincipal($service, $proof['principal'], $catalog, $configuration, $devices)) continue;
                $current = $db->singleQuery('SELECT network.id,network.address,services.clientId,services.device,services.status FROM network JOIN services ON network.id=services.id WHERE network.id=?', true, [(int) $row['id']]);
                if ($current !== $row || ApiIpAttributeExport::allocation($row) !== $proof) continue;
                $confirmed = $api->get('clients/services/' . (int) $row['id']);
                if (!is_array($confirmed) || (!is_int($confirmed['id'] ?? null) && !is_string($confirmed['id'] ?? null))
                    || (!is_int($confirmed['clientId'] ?? null) && !is_string($confirmed['clientId'] ?? null))
                    || filter_var($confirmed['id'] ?? null, FILTER_VALIDATE_INT) !== (int) $row['id']
                    || filter_var($confirmed['clientId'] ?? null, FILTER_VALIDATE_INT) !== (int) $row['clientId']
                    || ($confirmed['status'] ?? null) !== $service['status']) continue;
                $confirmedEvidence = ApiNetworkAddressEvidence::parse($confirmed, $mapping)['address'];
                if (!$confirmedEvidence['known']) { $complete = false; continue; }
                if ($confirmedEvidence['value'] !== null) { ApiIpAttributeExport::claim($proof); continue; }
                [$configurationAfter, $devicesAfter] = ApiSiteAddressPolicy::stored('data/data.db');
                if ($configurationAfter !== $configuration || $devicesAfter !== $devices
                    || !ApiSiteAddressPolicy::currentPrincipal($confirmed, $proof['principal'], $catalog, $configurationAfter, $devicesAfter)
                    || $db->singleQuery('SELECT network.id,network.address,services.clientId,services.device,services.status FROM network JOIN services ON network.id=services.id WHERE network.id=?', true, [(int) $row['id']]) !== $row
                    || ApiIpAttributeExport::allocation($row) !== $proof) continue;
                if (!ApiIpAttributeExport::claim($proof)) continue;
                $result = $api->patch('clients/services/' . (int) $row['id'], ['attributes' => [['customAttributeId' => $attribute, 'value' => $address]]]);
                if (!is_array($result) || (!is_int($result['id'] ?? null) && !is_string($result['id'] ?? null))
                    || filter_var($result['id'] ?? null, FILTER_VALIDATE_INT) !== (int) $row['id']) throw new RuntimeException('ip_attribute_patch_failed');
            } catch (Throwable $error) {
                $complete = false;
                MyLog()->Append(['event' => 'ip_attribute_export_unconfirmed', 'serviceId' => (int) $row['id']], LOG_WARNING);
            }
        }
        // Keep runtime checkpoints out of UISP's native configuration file.
        if ($complete) $db->saveConfig(['sync_attr_date' => $date]);
    } catch (Throwable $error) {
        MyLog()->Append(['ip_attribute_sync_failed', 'message' => $error->getMessage()], LOG_ERR);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
sync_ip_attributes();
