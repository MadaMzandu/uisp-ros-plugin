<?php
require_once __DIR__ . '/api_network_address_evidence.php';

/** Clear only a freshly verified foreign-Site address before requesting a new pool allocation. */
final class ApiSiteAddressPolicy
{
    /** Refresh only foreign-Site IP intent before an old queued update can replay it. */
    public static function refreshForExecution(array $service, ?array $expectedDevice = null, ?callable $request = null,
        string $dataPath = 'data/data.db', ?callable $preflight = null): array
    {
        [$configuration, $devices] = self::stored($dataPath);
        $did = self::id($service['device'] ?? null);
        if ($did === null || !isset($devices[$did])) return ['snapshot' => $service, 'changed' => false];
        $candidate = $service; $derived = [];
        $foreign = self::foreignAddresses($service, $devices[$did], $configuration, $devices);
        foreach (self::preparedAddresses($service) as $field => $value) {
            if (isset($foreign[$field])) continue;
            $withCommand = $service; unset($withCommand[$field]); $withCommand['network'][$field] = $value;
            $commandForeign = self::foreignAddresses($withCommand, $devices[$did], $configuration, $devices);
            if (!isset($commandForeign[$field])) continue;
            unset($candidate[$field]); $candidate['network'][$field] = $value; $derived[$field] = true;
            $foreign[$field] = $commandForeign[$field];
        }
        foreach (['address', 'address6'] as $field) {
            $literal = array_key_exists($field, $service) ? $service[$field] : ($service['network'][$field] ?? null);
            $reset = $service['_network_reset'][$field] ?? null;
            if (isset($foreign[$field]) || ($literal !== null && $literal !== '') || !is_array($reset)
                || !is_string($reset['previous'] ?? null) || !is_string($reset['generation'] ?? null)
                || preg_match('/^[a-f0-9]{32}$/D', $reset['generation']) !== 1) continue;
            $withPrevious = $service; unset($withPrevious[$field]); $withPrevious['network'][$field] = $reset['previous'];
            $resetForeign = self::foreignAddresses($withPrevious, $devices[$did], $configuration, $devices);
            if (!isset($resetForeign[$field])) continue;
            unset($candidate[$field]); $candidate['network'][$field] = $reset['previous']; $foreign[$field] = $resetForeign[$field];
        }
        if (!$foreign)
            return ['snapshot' => $service, 'changed' => false];
        $refreshed = self::reconcile($candidate, $expectedDevice, $request, $dataPath, $preflight, true);
        // Keep the exact account identity, credentials, status and prepared resource evidence.
        // The queue owns invalidating/rebuilding commands while retaining their old resources.
        $next = $service;
        if (isset($service['_config']) && is_array($service['_config']) && isset($refreshed['_config']))
            $next['_config'] = $refreshed['_config'];
        foreach (['address', 'address6'] as $field) {
            $value = array_key_exists($field, $refreshed) ? $refreshed[$field] : ($refreshed['network'][$field] ?? null);
            $before = array_key_exists($field, $service) ? $service[$field] : ($service['network'][$field] ?? null);
            $newReset = $refreshed['_network_reset'][$field] ?? null; $oldReset = $service['_network_reset'][$field] ?? null;
            if ($value !== $before || is_array($newReset) !== is_array($oldReset)
                || ($newReset['previous'] ?? null) !== ($oldReset['previous'] ?? null)
                || ($newReset['generation'] ?? null) !== ($oldReset['generation'] ?? null)) {
                unset($next[$field]); $next['network'][$field] = $value;
                if (isset($refreshed['_network_reset'][$field])) $next['_network_reset'][$field] = $refreshed['_network_reset'][$field];
                else unset($next['_network_reset'][$field]);
            }
        }
        return ['snapshot' => $next, 'changed' => $next !== $service || (bool) array_intersect_key($derived, $foreign)];
    }

    public static function reconcile(array $service, ?array $expectedDevice = null, ?callable $request = null,
        string $dataPath = 'data/data.db', ?callable $preflight = null, bool $refreshAddress = false): array
    {
        [$configuration, $devices] = self::stored($dataPath);
        $sid = self::id($service['id'] ?? null); $client = self::id($service['clientId'] ?? null); $did = self::id($service['device'] ?? null);
        if ($sid === null || $client === null || $did === null || !isset($devices[$did])) return $service;
        $device = $devices[$did];
        if ($expectedDevice !== null && (self::id($expectedDevice['id'] ?? null) !== $did
            || self::endpoint($expectedDevice) !== self::endpoint($device))) throw new RuntimeException('site_address_device_changed');
        $candidates = [];
        foreach (self::foreignAddresses($service, $device, $configuration, $devices) as $field => $address) {
            $existing = $service['_network_reset'][$field] ?? null;
            $generation = is_array($existing) && ($existing['previous'] ?? null) === $address
                && is_string($existing['generation'] ?? null) && preg_match('/^[a-f0-9]{32}$/D', $existing['generation']) === 1
                ? $existing['generation'] : bin2hex(random_bytes(16));
            $candidates[$field] = ['previous' => $address, 'generation' => $generation];
        }
        if (!$candidates) return $service;
        $call = static function (string $path, string $method = 'get', array $data = []) use ($request) {
            if ($request !== null) return $request($path, $method, $data);
            require_once __DIR__ . '/api_ucrm.php';
            return (new ApiUcrm(null, true))->request($path, $method, $data);
        };
        try {
            $catalog = $call('custom-attributes');
            $mapping = ApiNetworkAddressEvidence::validateMapping(['address' => $configuration['ip_addr_attr'] ?? null,
                'address6' => $configuration['ip_addr6_attr'] ?? null], $catalog);
            $site = self::descriptor($configuration['device_name_attr'] ?? null, $catalog);
            if ($site === null) throw new RuntimeException('site_address_mapping_unknown');
            $fresh = self::entity($call('clients/services/' . $sid), $sid, $client);
            self::assertSite($fresh, $site, $devices, $did);
            if (!self::currentPrincipal($fresh, $service, $catalog, $configuration, $devices)) throw new RuntimeException('site_address_identity_changed');
            $freshClient = $call('clients/' . $client);
            if (!is_array($freshClient) && !is_object($freshClient)) throw new RuntimeException('site_address_identity_changed');
            $freshClient = (array) $freshClient;
            if (self::id($freshClient['id'] ?? null) !== $client
                || !in_array($freshClient['isArchived'] ?? null, [false, 0, '0'], true)) throw new RuntimeException('site_address_identity_changed');
            $evidence = ApiNetworkAddressEvidence::parse($fresh, $mapping);
            $overrides = [];
            foreach ($candidates as $field => &$intent) {
                if ($mapping[$field] === null || !$evidence[$field]['known']) throw new RuntimeException('site_address_evidence_unknown');
                if ($evidence[$field]['value'] !== null && $evidence[$field]['value'] !== $intent['previous']) {
                    if (!$refreshAddress) throw new RuntimeException('site_address_changed');
                    $current = $service; unset($current[$field]); $current['network'][$field] = $evidence[$field]['value'];
                    $foreign = self::foreignAddresses($current, $device, $configuration, $devices);
                    if (!isset($foreign[$field])) { $overrides[$field] = $evidence[$field]['value']; continue; }
                    $intent = ['previous' => $foreign[$field], 'generation' => bin2hex(random_bytes(16))];
                }
                $receipt = self::receipt($sid, $field, dirname($dataPath));
                if ($receipt !== null) {
                    if ($receipt['client_id'] !== $client || $receipt['device_id'] !== $did || $receipt['attribute'] !== $mapping[$field]
                        || $receipt['previous'] !== $intent['previous'] || $receipt['site'] !== $site) {
                        if ($receipt['outcome'] === 'unconfirmed') throw new RuntimeException('site_address_review_required');
                    } else {
                        $intent['generation'] = $receipt['generation'];
                        if ($evidence[$field]['value'] !== null && $receipt['outcome'] === 'unconfirmed') throw new RuntimeException('site_address_write_unconfirmed');
                    }
                }
            }
            unset($intent);
            foreach ($overrides as $field => $value) unset($candidates[$field]);
            $cleared = $service;
            if (isset($cleared['_config']) && is_array($cleared['_config'])) {
                // Classification and rebuilding must use the same effective allocation policy.
                // Preserve billing, credentials and every unrelated frozen configuration field.
                foreach (['router_ppp_pool', 'ppp_pool', 'excl_addr'] as $key) {
                    if (array_key_exists($key, $configuration)) $cleared['_config'][$key] = $configuration[$key];
                    else unset($cleared['_config'][$key]);
                }
            }
            foreach ($overrides as $field => $value) {
                unset($cleared[$field], $cleared['_network_reset'][$field]); $cleared['network'][$field] = $value;
            }
            foreach ($candidates as $field => $intent) {
                unset($cleared[$field]); $cleared['network'][$field] = null; $cleared['_network_reset'][$field] = $intent;
            }
            // The exact builder must prove replacement availability before any CRM attribute is removed.
            if ($preflight === null) throw new RuntimeException('site_address_preflight_unavailable');
            try { $preflight($cleared, array_keys($candidates + $overrides), $device); }
            catch (Throwable $failure) { throw new RuntimeException('site_address_pool_unavailable', 0, $failure); }
            foreach ($candidates as $field => $intent) {
                if ($evidence[$field]['value'] === null) {
                    self::writeReceipt(['service_id' => $sid, 'client_id' => $client, 'device_id' => $did, 'field' => $field,
                        'attribute' => $mapping[$field], 'site' => $site, 'previous' => $intent['previous'],
                        'generation' => $intent['generation'], 'outcome' => 'confirmed'], dirname($dataPath));
                    continue;
                }
                [$currentConfiguration, $currentDevices] = self::stored($dataPath);
                if ($currentConfiguration !== $configuration || !isset($currentDevices[$did]) || self::endpoint($currentDevices[$did]) !== self::endpoint($device)) {
                    throw new RuntimeException('site_address_device_changed');
                }
                // Refresh once more immediately before this family's write; never clear a newer UISP value or Site.
                $before = self::entity($call('clients/services/' . $sid), $sid, $client);
                self::assertSite($before, $site, $currentDevices, $did);
                if (!self::currentPrincipal($before, $service, $catalog, $configuration, $currentDevices)) throw new RuntimeException('site_address_identity_changed');
                $beforeEvidence = ApiNetworkAddressEvidence::parse($before, $mapping)[$field];
                if (!$beforeEvidence['known'] || $beforeEvidence['value'] !== $intent['previous']) throw new RuntimeException('site_address_changed');
                $receipt = ['service_id' => $sid, 'client_id' => $client, 'device_id' => $did, 'field' => $field,
                    'attribute' => $mapping[$field], 'site' => $site, 'previous' => $intent['previous'],
                    'generation' => $intent['generation'], 'outcome' => 'unconfirmed'];
                self::writeReceipt($receipt, dirname($dataPath));
                $writeFailure = null;
                try { $call('clients/services/' . $sid, 'patch', ['attributes' => [['customAttributeId' => $mapping[$field]['id'], 'value' => null]]]); }
                catch (Throwable $failure) { $writeFailure = $failure; }
                try {
                    $after = self::entity($call('clients/services/' . $sid), $sid, $client);
                    self::assertSite($after, $site, $currentDevices, $did);
                    if (!self::currentPrincipal($after, $service, $catalog, $configuration, $currentDevices)) throw new RuntimeException('site_address_identity_changed');
                    $afterEvidence = ApiNetworkAddressEvidence::parse($after, $mapping)[$field];
                    if (!$afterEvidence['known'] || $afterEvidence['value'] !== null) throw new RuntimeException('site_address_write_unconfirmed');
                } catch (Throwable $failure) {
                    $code = self::rejected($writeFailure) ? 'site_address_write_rejected' : 'site_address_write_unconfirmed';
                    if ($code === 'site_address_write_rejected') { $receipt['outcome'] = 'rejected'; self::writeReceipt($receipt, dirname($dataPath)); }
                    throw new RuntimeException($code, 0, $failure);
                }
                $receipt['outcome'] = 'confirmed'; self::writeReceipt($receipt, dirname($dataPath));
            }
            [$finalConfiguration, $finalDevices] = self::stored($dataPath);
            if ($finalConfiguration !== $configuration || !isset($finalDevices[$did]) || self::endpoint($finalDevices[$did]) !== self::endpoint($device)) throw new RuntimeException('site_address_device_changed');
            $confirmed = self::entity($call('clients/services/' . $sid), $sid, $client);
            self::assertSite($confirmed, $site, $finalDevices, $did);
            if (!self::currentPrincipal($confirmed, $service, $catalog, $configuration, $finalDevices)) throw new RuntimeException('site_address_identity_changed');
            $confirmedEvidence = ApiNetworkAddressEvidence::parse($confirmed, $mapping);
            foreach ($candidates as $field => $intent) if (!$confirmedEvidence[$field]['known'] || $confirmedEvidence[$field]['value'] !== null) throw new RuntimeException('site_address_changed');
            foreach ($overrides as $field => $value) if (!$confirmedEvidence[$field]['known'] || $confirmedEvidence[$field]['value'] !== $value) throw new RuntimeException('site_address_changed');
            return $cleared;
        } catch (Throwable $failure) {
            if (strpos($failure->getMessage(), 'site_address_') === 0) throw $failure;
            throw new RuntimeException('site_address_verification_unavailable', 0, $failure);
        }
    }

    public static function foreignAddresses(array $service, array $device, array $configuration, array $devices): array
    {
        $candidates = [];
        foreach (['address' => false, 'address6' => true] as $field => $ipv6) {
            $literal = array_key_exists($field, $service) ? $service[$field] : ($service['network'][$field] ?? null);
            if (!is_string($literal) || trim($literal) === '') continue;
            try { $address = self::address($literal, $ipv6, $device); }
            catch (Throwable $ignored) { continue; }
            if (self::contained($address, self::pools($service, $device, $configuration, $ipv6))) continue;
            foreach ($devices as $peerId => $peer) {
                if ($peerId === (int) $device['id']) continue;
                if (self::contained($address, self::pools($service, $peer, $configuration, $ipv6, true))) {
                    $candidates[$field] = $address; break;
                }
            }
        }
        return $candidates;
    }

    private static function preparedAddresses(array $service): array
    {
        $addresses = [];
        foreach ($service['_commands'] ?? [] as $command) {
            if (!is_array($command) || ($command['action'] ?? '') === 'remove'
                || self::id($command['_service_id'] ?? null) !== self::id($service['id'] ?? null)
                || self::id($command['_client_id'] ?? null) !== self::id($service['clientId'] ?? null)) continue;
            $path = trim($command['path'] ?? '', '/'); $fields = [];
            if (in_array($path, ['ppp/secret', 'ip/hotspot/user'], true)
                && is_string($service['username'] ?? null) && ($command['name'] ?? null) === $service['username'])
                $fields = $path === 'ppp/secret' ? ['address' => 'remote-address', 'address6' => 'remote-ipv6-prefix'] : ['address' => 'address'];
            elseif ($path === 'ip/dhcp-server/lease' && filter_var($service['mac'] ?? '', FILTER_VALIDATE_MAC)
                && strcasecmp($command['mac-address'] ?? '', $service['mac']) === 0) $fields = ['address' => 'address'];
            elseif ($path === 'ipv6/dhcp-server/binding' && self::duid($service['duid'] ?? $service['mac'] ?? null) !== null
                && self::duid($command['duid'] ?? null) === self::duid($service['duid'] ?? $service['mac'] ?? null)
                && self::iaid($command['iaid'] ?? null) !== null
                && self::iaid($command['iaid']) === self::iaid($service['iaid'] ?? 1)) $fields = ['address6' => 'address'];
            foreach ($fields as $field => $key) if (is_string($command[$key] ?? null) && $command[$key] !== '') $addresses[$field][$command[$key]] = true;
        }
        $result = [];
        foreach ($addresses as $field => $values) if (count($values) === 1) $result[$field] = array_key_first($values);
        return $result;
    }

    private static function duid($value): ?string
    {
        if (!is_string($value)) return null;
        $value = preg_replace('/[:\s-]/', '', strtolower($value)); $value = preg_replace('/^0x/', '', $value);
        return preg_match('/^(?:[0-9a-f]{2}){6,}$/D', $value) === 1 ? $value : null;
    }

    private static function iaid($value): ?int
    {
        if (is_string($value) && preg_match('/^0x[0-9a-f]+$/iD', $value)) $value = hexdec($value);
        if (!is_numeric($value) || (float)$value < 0 || (float)$value > 4294967295 || (float)$value !== floor((float)$value)) return null;
        return (int)$value;
    }

    /** Minimal current facts for the scheduled exporter; no stale router allocation may target a changed Site/user. */
    public static function currentPrincipal(array $fresh, array $applied, array $catalog, array $configuration, array $devices): bool
    {
        try {
            $did = self::id($applied['device'] ?? null); if ($did === null || !isset($devices[$did])) return false;
            $site = self::descriptor($configuration['device_name_attr'] ?? null, $catalog);
            $username = self::descriptor($configuration['pppoe_user_attr'] ?? null, $catalog);
            $mac = self::descriptor($configuration['mac_addr_attr'] ?? null, $catalog);
            if ($site === null) return false;
            self::assertSite($fresh, $site, $devices, $did);
            if (filter_var($applied['mac'] ?? '', FILTER_VALIDATE_MAC)) {
                $value = $mac === null ? null : self::attribute($fresh, $mac);
                return is_string($value) && strcasecmp($value, $applied['mac']) === 0;
            }
            if ($mac !== null && filter_var(self::attribute($fresh, $mac) ?? '', FILTER_VALIDATE_MAC)) return false;
            $value = $username === null ? null : self::attribute($fresh, $username);
            return is_string($value) && $value !== '' && $value === ($applied['username'] ?? null);
        } catch (Throwable $ignored) { return false; }
    }

    public static function stored(string $path): array
    {
        $db = null;
        try {
            if (is_link($path) || !is_file($path)) return [[], []];
            $db = new SQLite3($path, SQLITE3_OPEN_READONLY); $db->enableExceptions(true); $db->busyTimeout(500); $db->exec('BEGIN');
            if ($db->querySingle("SELECT count(*) FROM sqlite_master WHERE (type='view' AND name IN ('config','devices')) OR (type='trigger' AND tbl_name IN ('config','devices'))")) return [[], []];
            $configuration = []; $devices = []; $result = $db->query('SELECT key,value FROM config');
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if (!is_string($row['key']) || array_key_exists($row['key'], $configuration)) return [[], []];
                $configuration[$row['key']] = $row['value'];
            }
            $result->finalize(); $result = $db->query('SELECT * FROM devices');
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $id = self::id($row['id'] ?? null); if ($id === null || isset($devices[$id])) return [[], []];
                if (in_array($row['type'] ?? null, [null, '', 'mikrotik'], true)) $devices[$id] = $row;
            }
            $result->finalize(); return [$configuration, $devices];
        } catch (Throwable $ignored) { return [[], []]; }
        finally { if ($db !== null) $db->close(); }
    }

    private static function entity($value, int $id, int $client): array
    {
        if (!is_array($value) && !is_object($value)) throw new RuntimeException('site_address_identity_changed');
        $value = (array) $value;
        if (self::id($value['id'] ?? null) !== $id || self::id($value['clientId'] ?? null) !== $client
            || !in_array($value['status'] ?? null, [1,3,4,7,'1','3','4','7'], true)) throw new RuntimeException('site_address_identity_changed');
        return $value;
    }

    private static function assertSite(array $fresh, array $descriptor, array $devices, int $expected): void
    {
        $site = self::attribute($fresh, $descriptor); $matches = [];
        if (!is_string($site) || trim($site) === '') throw new RuntimeException('site_address_site_changed');
        foreach ($devices as $id => $device) if (is_string($device['name'] ?? null) && strcasecmp(trim($device['name']), trim($site)) === 0) $matches[] = $id;
        if ($matches !== [$expected]) throw new RuntimeException('site_address_site_changed');
    }

    private static function attribute(array $service, array $descriptor): ?string
    {
        $attributes = $service['attributes'] ?? null;
        if (!is_array($attributes) || ($attributes && array_keys($attributes) !== range(0, count($attributes) - 1))) throw new RuntimeException('site_address_evidence_unknown');
        $found = false; $value = null;
        foreach ($attributes as $attribute) {
            if (!is_array($attribute) && !is_object($attribute)) throw new RuntimeException('site_address_evidence_unknown');
            $attribute = (array) $attribute;
            $sameKey = ($attribute['key'] ?? null) === $descriptor['key'];
            $sameId = self::id($attribute['customAttributeId'] ?? null) === $descriptor['id'];
            if (!$sameKey && !$sameId) continue;
            if ($found || !$sameKey || (array_key_exists('customAttributeId', $attribute) && !$sameId)
                || !array_key_exists('value', $attribute) || (!is_string($attribute['value']) && $attribute['value'] !== null)) throw new RuntimeException('site_address_evidence_unknown');
            $found = true; $value = $attribute['value'];
        }
        return $value;
    }

    private static function descriptor($key, $catalog): ?array
    {
        if (!is_string($key) || $key === '' || !is_array($catalog) || ($catalog && array_keys($catalog) !== range(0, count($catalog) - 1))) return null;
        $found = null; $ids = [];
        foreach ($catalog as $attribute) {
            if (!is_array($attribute) && !is_object($attribute)) return null;
            $attribute = (array) $attribute; $id = self::id($attribute['id'] ?? null);
            if ($id === null || isset($ids[$id])) return null; $ids[$id] = true;
            if (($attribute['key'] ?? null) !== $key) continue;
            if ($found !== null || ($attribute['attributeType'] ?? null) !== 'service') return null;
            $found = ['key' => $key, 'id' => $id];
        }
        return $found;
    }

    private static function pools(array $service, array $device, array $configuration, bool $ipv6, bool $peer = false): array
    {
        $ppp = !filter_var($service['mac'] ?? '', FILTER_VALIDATE_MAC) && empty($service['hotspot']);
        $routerPool = filter_var($configuration['router_ppp_pool'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($routerPool === null && !$peer) return [];
        $literal = $ppp && $routerPool === false ? ($configuration['ppp_pool'] ?? null) : ($device[$ipv6 ? 'pool6' : 'pool'] ?? null);
        if (!is_string($literal) || trim($literal) === '') return [];
        $ranges = [];
        foreach (preg_split('/\s*,\s*/', trim($literal)) as $prefix) {
            // The shared PPP configuration intentionally accepts both families.
            // Validate every CIDR, then use only the family's effective ranges.
            $family6 = strpos($prefix, ':') !== false;
            try { $range = self::range($prefix, $family6); } catch (Throwable $ignored) { return []; }
            if ($family6 === $ipv6) $ranges[] = $range;
        }
        return $ranges;
    }

    private static function address(string $literal, bool $ipv6, array $device): string
    {
        if (!$ipv6) return (string) ApiNetworkAddressEvidence::normalize($literal, 'address');
        if (strpos($literal, '/') === false) $literal .= '/' . ($device['pfxLength'] ?: 64);
        return (string) ApiNetworkAddressEvidence::normalize($literal, 'address6');
    }

    private static function range(string $prefix, bool $ipv6): array
    {
        $parts = explode('/', trim($prefix)); $max = $ipv6 ? 128 : 32;
        if (count($parts) !== 2 || !ctype_digit($parts[1]) || (int) $parts[1] < 1 || (int) $parts[1] > $max
            || !filter_var($parts[0], FILTER_VALIDATE_IP, $ipv6 ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4)) throw new RuntimeException('invalid_pool');
        $start = inet_pton($parts[0]); $end = $start; $length = (int) $parts[1];
        for ($index = 0; $index < strlen($start); $index++) {
            $bits = max(0, min(8, $length - $index * 8)); $mask = $bits === 0 ? 0 : (255 << (8 - $bits)) & 255;
            $start[$index] = chr(ord($start[$index]) & $mask); $end[$index] = chr(ord($start[$index]) | (255 ^ $mask));
        }
        return [$start, $end];
    }

    private static function contained(string $address, array $pools): bool
    {
        $ipv6 = strpos($address, ':') !== false;
        if (strpos($address, '/') === false) $address .= $ipv6 ? '/128' : '/32';
        $range = self::range($address, $ipv6);
        foreach ($pools as $pool) if (strcmp($range[0], $pool[0]) >= 0 && strcmp($range[1], $pool[1]) <= 0) return true;
        return false;
    }

    private static function endpoint(array $device): array
    {
        return ['id' => self::id($device['id'] ?? null), 'ip' => $device['ip'] ?? null, 'port' => $device['port'] ?? 8728,
            'name' => $device['name'] ?? null, 'pool' => $device['pool'] ?? null, 'pool6' => $device['pool6'] ?? null, 'pfxLength' => $device['pfxLength'] ?? null];
    }

    private static function receipt(int $sid, string $field, string $directory): ?array
    {
        $path = $directory . DIRECTORY_SEPARATOR . 'site-address-policy-' . $sid . '-' . $field . '.json';
        if (!file_exists($path) && !is_link($path)) return null;
        if (is_link($path) || !is_file($path) || filesize($path) > 4096) throw new RuntimeException('site_address_review_required');
        $state = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($state) || ($state['service_id'] ?? null) !== $sid || ($state['field'] ?? null) !== $field
            || self::id($state['client_id'] ?? null) === null || self::id($state['device_id'] ?? null) === null
            || !is_array($state['attribute'] ?? null) || !is_array($state['site'] ?? null) || !is_string($state['previous'] ?? null)
            || !is_string($state['generation'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $state['generation']) !== 1
            || !in_array($state['outcome'] ?? null, ['confirmed','unconfirmed','rejected'], true)) throw new RuntimeException('site_address_review_required');
        return $state;
    }

    private static function writeReceipt(array $state, string $directory): void
    {
        $path = $directory . DIRECTORY_SEPARATOR . 'site-address-policy-' . $state['service_id'] . '-' . $state['field'] . '.json';
        if (is_link($path) || (file_exists($path) && !is_file($path))) throw new RuntimeException('site_address_receipt_failed');
        $stream = fopen($path, 'c+b'); if (!$stream) throw new RuntimeException('site_address_receipt_failed');
        try {
            if (!flock($stream, LOCK_EX | LOCK_NB) || !chmod($path, 0600)) throw new RuntimeException('site_address_receipt_failed');
            $json = json_encode($state, JSON_THROW_ON_ERROR);
            if (!ftruncate($stream, 0) || fwrite($stream, $json) !== strlen($json) || !fflush($stream)) throw new RuntimeException('site_address_receipt_failed');
        } finally { fclose($stream); }
    }

    private static function rejected(?Throwable $failure): bool
    {
        for (; $failure !== null; $failure = $failure->getPrevious()) {
            if (method_exists($failure, 'hasResponse') && $failure->hasResponse() && method_exists($failure, 'getResponse')) {
                $status = $failure->getResponse()->getStatusCode(); return $status >= 400 && $status < 500 && !in_array($status, [408,409,429], true);
            }
        }
        return false;
    }

    private static function id($value): ?int
    {
        if (!is_int($value) && !is_string($value)) return null;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]); return $id === false ? null : $id;
    }
}
