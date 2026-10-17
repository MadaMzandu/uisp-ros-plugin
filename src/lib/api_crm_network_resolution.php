<?php

require_once __DIR__ . '/api_crm_network_monitor.php';
require_once __DIR__ . '/api_crm_network_history.php';
require_once __DIR__ . '/api_network_cleanup.php';

/** Explicit CRM IP corrections. Automatic monitoring never invokes this class. */
final class ApiCrmNetworkResolution
{
    public const MAX_SERVICES = 3;
    private const TTL = 600;
    private const BUDGET = 12.0;
    private $request;
    private $clock;
    private string $dataPath;
    private string $statePath;

    public function __construct(?callable $request = null, string $dataPath = 'data/data.db',
        string $statePath = 'data/crm-network-monitor.db', ?callable $clock = null)
    {
        $this->request = $request;
        $this->dataPath = $dataPath;
        $this->statePath = $statePath;
        $this->clock = $clock;
    }

    /** Called only after public.php has checked admin permissions and origin. */
    public static function panelRequest(string $method, ?object $request): bool
    {
        $verify = $method === 'POST' && ($request->changeType ?? '') === 'list' && ($request->target ?? '') === 'crm_network_resolution';
        $resolve = $method === 'POST' && ($request->changeType ?? '') === 'resolve';
        if (!$verify && !$resolve) return false;
        if (!empty($request->entity) || ($resolve && ($request->target ?? '') !== 'crm_network_conflicts')) throw new InvalidArgumentException('invalid_crm_resolution_target');
        $data = $request->data ?? null;
        if (!is_object($data)) throw new InvalidArgumentException('invalid_crm_resolution_data');
        if (is_file('data/data.db')) (new ApiSetup())->assertCompatible();
        $api = new self();
        if ($verify) {
            if (($request->action ?? '') !== 'verify' || !is_array($data->serviceIds ?? null)
                || array_diff(array_keys(get_object_vars($data)), ['serviceIds'])) throw new InvalidArgumentException('invalid_crm_resolution_request');
            $result = $api->verify($data->serviceIds);
            $message = 'crm_network_resolution_verified';
        } elseif (($request->action ?? '') === 'preview') {
            if (array_diff(array_keys(get_object_vars($data)), ['serviceId', 'family', 'operation', 'address'])
                || self::id($data->serviceId ?? null) === null || !is_string($data->family ?? null)
                || !is_string($data->operation ?? null) || (property_exists($data, 'address') && !is_string($data->address))
                || ($data->operation !== 'replace' && property_exists($data, 'address'))) throw new InvalidArgumentException('invalid_crm_resolution_request');
            $result = $api->preview(self::id($data->serviceId), $data->family, $data->operation, $data->address ?? null);
            $message = 'crm_network_resolution_preview';
        } elseif (($request->action ?? '') === 'apply') {
            if (!is_string($data->previewToken ?? null) || array_diff(array_keys(get_object_vars($data)), ['previewToken'])) throw new InvalidArgumentException('invalid_crm_resolution_request');
            $result = $api->apply($data->previewToken);
            $message = 'crm_network_resolution_applied';
        } else throw new InvalidArgumentException('invalid_crm_resolution_action');
        http_response_code(200);
        respond($message, false, $result);
        return true;
    }

    public function verify(array $serviceIds): array
    {
        $ids = self::selection($serviceIds);
        $report = $this->monitor()->read();
        $eligible = $this->eligible($report);
        foreach ($ids as $id) if (!isset($eligible[$id]) && !$this->hasCorrection($id)) throw new InvalidArgumentException('crm_resolution_service_not_found');
        $deadline = $this->now() + self::BUDGET;
        $mapping = $this->mapping();
        $validated = ['address' => null, 'address6' => null];
        $requests = 0;
        if ($this->now() < $deadline) {
            $requests++;
            try { $validated = ApiNetworkAddressEvidence::validateMapping($mapping, $this->call('custom-attributes')); }
            catch (Throwable $ignored) {}
        }
        $owners = [];
        $clients = [];
        foreach ($ids as $id) {
            $owner = $this->owner($id, $eligible[$id]['client_id'] ?? null);
            if ($this->now() >= $deadline) $owner['refresh_error'] = 'deadline';
            else {
                $requests++;
                try {
                    $service = $this->service($id);
                    $owner = array_replace($owner, $this->owner($id, self::id($service['clientId']), self::status($service['status'])));
                    $owner['service_exists'] = true;
                    $owner['address_evidence'] = ApiNetworkAddressEvidence::parse($service, $validated);
                    foreach (['address' => 'IPv4', 'address6' => 'IPv6'] as $field => $family) {
                        if ($validated[$field] !== null && ApiCrmNetworkHistory::reviewed($service, $validated[$field], $field, dirname($this->dataPath))) $owner['historical_families'][] = $family;
                    }
                    $owner['address_verified_at'] = self::date($this->now());
                } catch (Throwable $failure) {
                    if (self::notFound($failure)) {
                        $owner['service_exists'] = false;
                        $owner['address_evidence'] = ['address' => ['known' => true, 'value' => null, 'reason' => null],
                            'address6' => ['known' => true, 'value' => null, 'reason' => null]];
                        $owner['address_verified_at'] = self::date($this->now());
                    } else $owner['refresh_error'] = 'unavailable';
                }
            }
            $clientId = $owner['client_id'];
            if ($clientId !== null && !array_key_exists($clientId, $clients)) {
                if ($this->now() >= $deadline) $clients[$clientId] = ['client_refresh_error' => 'deadline'];
                else {
                    $requests++;
                    try { $clients[$clientId] = $this->client($clientId); }
                    catch (Throwable $ignored) { $clients[$clientId] = ['client_refresh_error' => 'unavailable']; }
                }
            }
            $owner['local_assignment_state'] = ApiNetworkCleanup::localAssignmentState($id, $this->dataPath);
            $owner['local_assignment_checked_at'] = self::date($this->now());
            $owners[$id] = array_replace($owner, $clients[$clientId] ?? []);
        }
        $groups = [];
        foreach ($report['conflicts'] ?? [] as $group) {
            if (!array_intersect($group['service_ids'], $ids)) continue;
            $field = $group['family'] === 'IPv6' ? 'address6' : 'address';
            $complete = !$group['services_truncated'] && count($group['service_ids']) === $group['service_count'];
            $addresses = [];
            foreach ($group['service_ids'] as $id) {
                $evidence = $owners[$id]['address_evidence'][$field] ?? null;
                if (!is_array($evidence) || $evidence['known'] !== true) { $complete = false; continue; }
                if (in_array($group['family'], $owners[$id]['historical_families'] ?? [], true)) continue;
                if ($evidence['value'] !== null) $addresses[$evidence['value']] = ($addresses[$evidence['value']] ?? 0) + 1;
            }
            $duplicate = $addresses && max($addresses) > 1;
            $groups[] = ['family' => $group['family'], 'address' => $group['address'], 'service_ids' => $group['service_ids'],
                'service_count' => $group['service_count'], 'state' => $duplicate ? 'duplicate' : ($complete ? 'resolved' : 'unknown')];
        }
        $complete = true;
        foreach ($owners as $owner) if ($owner['service_exists'] === null || $owner['refresh_error'] !== null) $complete = false;
        return ['owners' => array_values($owners), 'groups' => $groups, 'checked_at' => self::date($this->now()),
            'snapshot_checked_at' => $report['checked_at'], 'selection_complete' => $complete,
            'api_requests' => $requests, 'awaiting_full_scan' => true];
    }

    public function preview(int $id, string $family, string $operation, ?string $address = null): array
    {
        if ($id < 1) throw new InvalidArgumentException('invalid_crm_resolution_service');
        $field = self::field($family);
        if (!in_array($operation, ['replace', 'remove', 'history'], true)) throw new InvalidArgumentException('invalid_crm_resolution_operation');
        $value = $operation === 'replace' ? $this->newAddress($address, $field) : null;
        $report = $this->monitor()->read();
        $eligible = $this->eligible($report);
        if (!isset($eligible[$id]) && !$this->hasCorrection($id)) throw new InvalidArgumentException('crm_resolution_service_not_found');
        $current = $this->target($id, $field);
        if ($operation === 'history') {
            if ($current['status'] !== 2 || !$current['evidence']['known'] || $current['evidence']['value'] === null) throw new InvalidArgumentException('crm_resolution_history_unavailable');
            $value = $current['evidence']['value'];
        } elseif ($current['evidence']['known'] && $current['evidence']['value'] === $value) throw new InvalidArgumentException('crm_resolution_no_change');
        if ($operation === 'replace') $this->checkCollision($id, $field, $value, $current['mapping']);
        $client = [];
        try { $client = $this->client($current['client_id']); } catch (Throwable $ignored) {}
        if ($current['config_fingerprint'] !== ApiNetworkAddressEvidence::mappingFingerprint($this->mapping())) throw new InvalidArgumentException('crm_resolution_changed');
        $this->pruneTokens();
        $token = bin2hex(random_bytes(32));
        $expires = (int) $this->now() + self::TTL;
        $state = ['expires' => $expires, 'database' => $this->identity(), 'service_id' => $id, 'field' => $field,
            'operation' => $operation, 'value' => $value, 'fingerprint' => $current['fingerprint'],
            'config_fingerprint' => $current['config_fingerprint']];
        $this->writePrivate($this->tokenPath($token), $state, true);
        return array_replace($this->owner($id, $current['client_id'], $current['status']), $client,
            ['previewToken' => $token, 'expiresAt' => self::date($expires), 'family' => $family, 'operation' => $operation,
                'attribute_key' => $current['mapping'][$field]['key'], 'current_address' => $current['evidence']['value'],
                'current_address_known' => $current['evidence']['known'], 'new_address' => $value,
                'local_cleanup_required' => $this->localObsolete($id, $field, $operation === 'history' ? null : $value),
                'historical' => $operation === 'history', 'preserves_crm_history' => $operation === 'history',
                'requires_sync' => $operation !== 'history', 'uniqueness_checked' => false, 'router_changes' => false,
                'crm_webhook_possible' => $operation !== 'history']);
    }

    public function apply(string $token): array
    {
        $path = $this->tokenPath($token);
        $lock = null;
        $maintenance = null;
        $tokenLock = null;
        try {
            if (is_link($path) || !is_file($path) || filesize($path) > 8192) throw new InvalidArgumentException('invalid_crm_resolution_token');
            $tokenLock = fopen($path, 'r+b');
            if (!$tokenLock || !flock($tokenLock, LOCK_EX | LOCK_NB)) throw new InvalidArgumentException('crm_resolution_busy');
            $state = json_decode((string) stream_get_contents($tokenLock), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($state) || !is_int($state['expires'] ?? null) || ($state['database'] ?? null) !== $this->identity()
                || self::id($state['service_id'] ?? null) === null || !in_array($state['field'] ?? null, ['address', 'address6'], true)
                || !in_array($state['operation'] ?? null, ['replace', 'remove', 'history'], true)
                || !is_string($state['fingerprint'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $state['fingerprint']) !== 1
                || !is_string($state['config_fingerprint'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $state['config_fingerprint']) !== 1
                || !array_key_exists('value', $state)) throw new InvalidArgumentException('invalid_crm_resolution_token');
            if ($state['expires'] <= $this->now()) throw new InvalidArgumentException('crm_resolution_preview_expired');
            $id = $state['service_id']; $field = $state['field']; $value = $state['value'];
            if (($state['operation'] === 'remove' && $value !== null)
                || ($state['operation'] !== 'remove' && (!is_string($value) || $this->newAddress($value, $field) !== $value))) throw new InvalidArgumentException('invalid_crm_resolution_token');
            $maintenance = $this->maintenanceGuard();
            // Exclude the daily exporter only. Router workers and CRM webhooks
            // never acquire this lock, so an HTTP callback cannot deadlock here.
            $lockPath = dirname($this->dataPath) . DIRECTORY_SEPARATOR . 'ip-sync.lock';
            if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) throw new InvalidArgumentException('crm_resolution_busy');
            $lock = fopen($lockPath, 'c+b');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new InvalidArgumentException('crm_resolution_busy');
            $current = $this->target($id, $field);
            if (!hash_equals($state['fingerprint'], $current['fingerprint'])
                || !hash_equals($state['config_fingerprint'], $current['config_fingerprint'])) throw new InvalidArgumentException('crm_resolution_changed');
            if ($state['operation'] === 'replace') $this->checkCollision($id, $field, $value, $current['mapping']);
            if ($state['expires'] <= $this->now()) throw new InvalidArgumentException('crm_resolution_preview_expired');
            if ($current['config_fingerprint'] !== ApiNetworkAddressEvidence::mappingFingerprint($this->mapping())) throw new InvalidArgumentException('crm_resolution_changed');
            // Keep only the exact IP intent, never service credentials or full
            // attributes. Install the hold before a potentially ambiguous PATCH.
            if ($state['operation'] === 'history') {
                if ($current['status'] !== 2 || !$current['evidence']['known'] || $current['evidence']['value'] !== $value) throw new InvalidArgumentException('crm_resolution_history_unavailable');
            } else $this->writePrivate($this->correctionPath($id, $field), ['service_id' => $id, 'field' => $field,
                'value' => $value, 'client_id' => $current['client_id'], 'attribute' => $current['mapping'][$field], 'created_at' => (int) $this->now()], false);
            if (!rewind($tokenLock) || !ftruncate($tokenLock, 0) || !fflush($tokenLock)) throw new InvalidArgumentException('crm_resolution_state_failed');
            flock($tokenLock, LOCK_UN); fclose($tokenLock); $tokenLock = null;
            @unlink($path); // Single-use even if the network reply is ambiguous.
            if ($state['operation'] === 'history') {
                $this->writePrivate(ApiCrmNetworkHistory::markerPath($id, $field, dirname($this->dataPath)),
                    ['service_id' => $id, 'client_id' => $current['client_id'], 'status' => 2, 'field' => $field,
                        'attribute' => $current['mapping'][$field], 'value' => $value,
                        'fingerprint' => $current['fingerprint'], 'created_at' => (int) $this->now()], false);
                return ['service_id' => $id, 'family' => $field === 'address' ? 'IPv4' : 'IPv6', 'operation' => 'history',
                    'applied' => true, 'uncertain' => false, 'current_address' => $value, 'current_address_known' => true,
                    'write_outcome' => 'not_requested', 'write_http_status' => null, 'write_reason' => null,
                    'historical' => true, 'preserves_crm_history' => true, 'requires_sync' => false,
                    'local_cleanup_required' => $this->localObsolete($id, $field, null), 'awaiting_full_scan' => true,
                    'crm_webhook_possible' => false];
            }
            $write = ['write_outcome' => 'accepted', 'write_http_status' => null, 'write_reason' => null];
            try { $this->call('clients/services/' . $id, 'patch', ['attributes' => [
                ['customAttributeId' => $current['mapping'][$field]['id'], 'value' => $value]]]); }
            catch (Throwable $failure) { $write = self::writeFailure($failure); }
            $applied = false;
            $sameTarget = false;
            $evidence = ['known' => false, 'value' => null, 'reason' => 'unavailable'];
            try {
                $after = $this->target($id, $field);
                $evidence = $after['evidence'];
                $sameTarget = $after['client_id'] === $current['client_id']
                    && $after['config_fingerprint'] === $current['config_fingerprint']
                    && $after['mapping'][$field] === $current['mapping'][$field];
                $applied = $sameTarget && $evidence['known'] && $evidence['value'] === $value;
            } catch (Throwable $ignored) {}
            // A rejected write and a known current state are a definite result,
            // even when the requested address was not applied. A later GET can
            // observe somebody else's change; it never changes the PATCH receipt.
            $uncertain = !$applied && !($write['write_outcome'] === 'rejected' && $sameTarget && $evidence['known']);
            return array_replace(['service_id' => $id, 'family' => $field === 'address' ? 'IPv4' : 'IPv6', 'operation' => $state['operation'],
                'applied' => $applied, 'uncertain' => $uncertain, 'current_address' => $evidence['value'],
                'current_address_known' => $evidence['known'], 'local_cleanup_required' => $this->localObsolete($id, $field, $value),
                'requires_sync' => true, 'awaiting_full_scan' => true, 'crm_webhook_possible' => true], $write);
        } finally {
            if (is_resource($tokenLock)) { flock($tokenLock, LOCK_UN); fclose($tokenLock); }
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
            if (is_resource($maintenance)) { flock($maintenance, LOCK_UN); fclose($maintenance); }
        }
    }

    /** Protect a deliberate correction from the daily reservation-to-CRM export. */
    public static function skipAttributeExport(int $id, string $field, string $localAddress, string $directory = 'data', ?array $attribute = null, ?int $clientId = null): bool
    {
        if ($id < 1 || !in_array($field, ['address', 'address6'], true)) return true;
        $path = $directory . DIRECTORY_SEPARATOR . 'crm-network-correction-' . $id . '-' . $field . '.json';
        if (!file_exists($path) && !is_link($path)) return false;
        try {
            if (is_link($path) || !is_file($path) || filesize($path) > 1024) return true;
            $state = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($state) || ($state['service_id'] ?? null) !== $id || ($state['field'] ?? null) !== $field
                || !array_key_exists('value', $state) || !is_int($state['created_at'] ?? null)) return true;
            if (!is_array($state['attribute'] ?? null) || !is_string($state['attribute']['key'] ?? null)
                || self::id($state['attribute']['id'] ?? null) === null || self::id($state['client_id'] ?? null) === null) return true;
            if ($attribute === null || $clientId === null) return true;
            // A remapped exporter addresses a different CRM field. Retain the
            // old hold in case that original field is configured again later.
            if ($attribute !== $state['attribute']) return false;
            // Main may still contain a previous owner after a CRM reassignment.
            // That stale identity must not bypass an explicit hold on this field.
            if ($clientId !== $state['client_id']) return true;
            $expected = $state['value'];
            if ($expected === null) return true;
            if (!is_string($expected) || ApiNetworkAddressEvidence::normalize($expected, $field) !== $expected) return true;
            if (ApiNetworkAddressEvidence::normalize($localAddress, $field) !== $expected) return true;
            // This reservation matches the reviewed CRM value; exporting it
            // cannot restore the old address. Router completion is independent.
            return !@unlink($path);
        } catch (Throwable $ignored) { return true; }
    }

    public function canReviewCleanup(array $ids): bool
    {
        $ids = self::selection($ids);
        $eligible = $this->eligible($this->monitor()->read());
        foreach ($ids as $id) if (!isset($eligible[$id]) && !$this->hasCorrection($id)) return false;
        return true;
    }

    private function target(int $id, string $field): array
    {
        $rawMapping = $this->mapping();
        $mapping = ApiNetworkAddressEvidence::validateMapping($rawMapping, $this->call('custom-attributes'));
        if ($mapping[$field] === null) throw new InvalidArgumentException('crm_resolution_mapping_unknown');
        $service = $this->service($id);
        $evidence = ApiNetworkAddressEvidence::parse($service, $mapping)[$field];
        if (!$evidence['known'] && $evidence['reason'] !== 'value_invalid') throw new InvalidArgumentException('crm_resolution_address_unknown');
        $client = self::id($service['clientId']); $status = self::status($service['status']);
        return ['mapping' => $mapping, 'client_id' => $client, 'status' => $status, 'evidence' => $evidence,
            'config_fingerprint' => ApiNetworkAddressEvidence::mappingFingerprint($rawMapping),
            'fingerprint' => ApiCrmNetworkHistory::fingerprint($service, $mapping[$field], $field)];
    }

    private function checkCollision(int $id, string $field, string $value, array $mapping): void
    {
        $peers = array_values(array_filter($this->monitor()->addressOwners($field, $value), static fn(int $peer): bool => $peer !== $id));
        // Stale or pending snapshot ownership is retained conservatively. Do
        // not introduce another known conflict because a peer GET timed out.
        if ($peers) throw new InvalidArgumentException('crm_resolution_address_in_use');
        $values = $field === 'address' ? [$value, $value . '/32'] : [$value];
        foreach ($values as $search) {
            try { $page = $this->call('clients/services', 'get', ['customAttributeKey' => $mapping[$field]['key'],
                'customAttributeValue' => $search, 'limit' => 50, 'offset' => 0]); }
            catch (Throwable $error) { throw new InvalidArgumentException('crm_resolution_collision_check_failed'); }
            if (!is_array($page) || count($page) >= 50 || ($page && array_keys($page) !== range(0, count($page) - 1))) {
                throw new InvalidArgumentException('crm_resolution_collision_check_failed');
            }
            foreach ($page as $service) {
                if (!is_array($service) && !is_object($service)) throw new InvalidArgumentException('crm_resolution_collision_check_failed');
                $service = (array) $service; $peer = self::id($service['id'] ?? null);
                if ($peer === null) throw new InvalidArgumentException('crm_resolution_collision_check_failed');
                if ($peer === $id) continue;
                if (ApiCrmNetworkHistory::reviewed($service, $mapping[$field], $field, dirname($this->dataPath))) continue;
                $evidence = ApiNetworkAddressEvidence::parse($service, $mapping)[$field];
                if (!$evidence['known']) throw new InvalidArgumentException('crm_resolution_collision_check_failed');
                if ($evidence['value'] === $value) throw new InvalidArgumentException('crm_resolution_address_in_use');
            }
        }
        // Exact server filters cannot cover every IPv6 textual alias or edits
        // made after this check. The preview never claims global uniqueness.
    }

    private function eligible(array $report): array
    {
        $owners = [];
        foreach ($report['conflicts'] ?? [] as $group) foreach ($group['owners'] ?? [] as $owner) $owners[$owner['service_id']] = $owner;
        foreach ($report['address_issues'] ?? [] as $owner) $owners[$owner['service_id']] = $owner;
        foreach ($report['historical_assignments'] ?? [] as $owner) $owners[$owner['service_id']] = $owner;
        return $owners;
    }
    private function hasCorrection(int $id): bool
    {
        foreach (['address', 'address6'] as $field) {
            $path = $this->correctionPath($id, $field);
            if (is_file($path) && !is_link($path)) return true;
            $path = ApiCrmNetworkHistory::markerPath($id, $field, dirname($this->dataPath));
            if (is_file($path) && !is_link($path)) return true;
        }
        return false;
    }
    private function owner(int $id, ?int $clientId = null, ?int $status = null): array
    {
        $labels = [0 => 'Preparado', 1 => 'Activo', 2 => 'Terminado', 3 => 'Suspendido', 4 => 'Preparado bloqueado',
            5 => 'Obsoleto', 6 => 'Diferido', 7 => 'Cotizado', 8 => 'Inactivo'];
        return ['service_id' => $id, 'client_id' => $clientId, 'client_name' => null, 'client_archived' => null,
            'client_verified_at' => null, 'client_refresh_error' => null, 'service_status_code' => $status,
            'service_status' => $status === null ? 'Sin verificar' : $labels[$status], 'service_exists' => null, 'refresh_error' => null,
            'client_url' => $clientId === null ? null : '/crm/client/' . $clientId, 'service_url' => '/crm/client/service/' . $id,
            'historical_families' => [], 'address_evidence' => ['address' => ['known' => false, 'value' => null, 'reason' => 'unavailable'],
                'address6' => ['known' => false, 'value' => null, 'reason' => 'unavailable']], 'address_verified_at' => null];
    }
    private function client(int $id): array
    {
        $client = $this->call('clients/' . $id);
        if (!is_array($client) && !is_object($client)) throw new RuntimeException('invalid_client');
        $client = (array) $client;
        if (self::id($client['id'] ?? null) !== $id) throw new RuntimeException('invalid_client');
        $text = static function ($value): string { return is_string($value) ? substr(trim($value), 0, 512) : ''; };
        $name = $text($client['companyName'] ?? $client['company'] ?? '') ?: trim($text($client['firstName'] ?? '') . ' ' . $text($client['lastName'] ?? ''));
        $archive = $client['isArchived'] ?? null;
        return ['client_name' => $name ?: null, 'client_archived' => in_array($archive, [true, 1, '1'], true) ? true
            : (in_array($archive, [false, 0, '0'], true) ? false : null), 'client_verified_at' => self::date($this->now()), 'client_refresh_error' => null];
    }
    private function service(int $id): array
    {
        $service = $this->call('clients/services/' . $id);
        if (!is_array($service) && !is_object($service)) throw new RuntimeException('invalid_service');
        $service = (array) $service;
        if (self::id($service['id'] ?? null) !== $id || self::id($service['clientId'] ?? null) === null
            || self::status($service['status'] ?? null) === null) throw new RuntimeException('invalid_service');
        return $service;
    }
    private function mapping(): array
    {
        $database = $this->database();
        try { return ApiNetworkAddressEvidence::mapping($database); } finally { $database->close(); }
    }
    private function localObsolete(int $id, string $field, ?string $desired): bool
    {
        $database = null;
        try {
            $database = $this->database();
            $columns = [];
            $result = $database->query('PRAGMA table_info(network)');
            try { while ($row = $result->fetchArray(SQLITE3_ASSOC)) $columns[$row['name']] = true; } finally { $result->finalize(); }
            if (!isset($columns['id'], $columns[$field])) return false;
            $query = $database->prepare('SELECT ' . $field . ' AS address FROM network WHERE id=:id');
            $query->bindValue(':id', $id, SQLITE3_INTEGER); $result = $query->execute();
            try { $row = $result->fetchArray(SQLITE3_ASSOC); } finally { $result->finalize(); $query->close(); }
            if (!$row || $row['address'] === null || $row['address'] === '') return false;
            try { $saved = ApiNetworkAddressEvidence::normalize((string) $row['address'], $field); }
            catch (Throwable $ignored) { return true; }
            return $saved !== $desired;
        } catch (Throwable $ignored) { return true; }
        finally { if ($database !== null) $database->close(); }
    }
    private function database(): SQLite3
    {
        if (!is_file($this->dataPath) || is_link($this->dataPath)) throw new InvalidArgumentException('crm_resolution_database_missing');
        $database = new SQLite3($this->dataPath, SQLITE3_OPEN_READONLY);
        $database->enableExceptions(true); $database->busyTimeout(100);
        return $database;
    }
    private function maintenanceGuard()
    {
        $jobsPath = dirname($this->dataPath) . DIRECTORY_SEPARATOR . 'jobs.db';
        if (is_link($jobsPath)) throw new InvalidArgumentException('crm_resolution_busy');
        $identity = realpath($jobsPath) ?: (realpath(dirname($jobsPath)) ?: dirname($jobsPath)) . DIRECTORY_SEPARATOR . basename($jobsPath);
        if (PHP_OS_FAMILY === 'Windows') $identity = strtolower($identity);
        $directory = dirname($identity) . DIRECTORY_SEPARATOR . '.uisp-queue-locks-' . hash('sha256', $identity);
        if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory))) throw new InvalidArgumentException('crm_resolution_busy');
        $path = $directory . DIRECTORY_SEPARATOR . 'maintenance.lock';
        if (is_link($path) || (file_exists($path) && !is_file($path))) throw new InvalidArgumentException('crm_resolution_busy');
        $lock = fopen($path, 'c+b');
        if (!$lock || !flock($lock, LOCK_SH | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            throw new InvalidArgumentException('crm_resolution_busy');
        }
        try {
            if (is_file($jobsPath)) {
                $database = new SQLite3($jobsPath, SQLITE3_OPEN_READONLY);
                try {
                    $database->enableExceptions(true); $database->busyTimeout(100);
                    if ($database->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='queue_meta'")
                        && $database->querySingle("SELECT COUNT(*) FROM queue_meta WHERE key='maintenance'")) throw new InvalidArgumentException('crm_resolution_busy');
                } finally { $database->close(); }
            }
            return $lock;
        } catch (Throwable $error) { flock($lock, LOCK_UN); fclose($lock); throw new InvalidArgumentException('crm_resolution_busy'); }
    }
    private function call(string $path, string $method = 'get', array $data = [])
    {
        if ($this->request !== null) return ($this->request)($path, $method, $data);
        require_once __DIR__ . '/api_ucrm.php';
        return (new ApiUcrm())->request($path, $method, $data);
    }
    private function monitor(): ApiCrmNetworkMonitor { return new ApiCrmNetworkMonitor(null, $this->dataPath, $this->statePath, $this->clock); }
    private function identity(): string { return hash('sha256', (string) realpath($this->dataPath)); }
    private function now(): float { return $this->clock === null ? microtime(true) : (float) ($this->clock)(); }
    private static function date(float $value): string { return gmdate('Y-m-d\TH:i:s\Z', (int) $value); }
    private static function field(string $family): string
    {
        if (!in_array($family, ['IPv4', 'IPv6'], true)) throw new InvalidArgumentException('invalid_crm_resolution_family');
        return $family === 'IPv4' ? 'address' : 'address6';
    }
    private function newAddress(?string $value, string $field): string
    {
        if ($value === null || strlen($value) > 100) throw new InvalidArgumentException('invalid_crm_resolution_address');
        try { $address = ApiNetworkAddressEvidence::normalize($value, $field); }
        catch (Throwable $error) { throw new InvalidArgumentException('invalid_crm_resolution_address'); }
        if ($address === null) throw new InvalidArgumentException('invalid_crm_resolution_address');
        return $address;
    }
    private function tokenPath(string $token): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) throw new InvalidArgumentException('invalid_crm_resolution_token');
        return dirname($this->dataPath) . DIRECTORY_SEPARATOR . 'crm-network-resolution-preview-' . $token . '.json';
    }
    private function correctionPath(int $id, string $field): string
    {
        return dirname($this->dataPath) . DIRECTORY_SEPARATOR . 'crm-network-correction-' . $id . '-' . $field . '.json';
    }
    private function writePrivate(string $path, array $state, bool $exclusive): void
    {
        if (is_link($path) || (file_exists($path) && !is_file($path))) throw new InvalidArgumentException('crm_resolution_state_failed');
        $stream = fopen($path, $exclusive ? 'x+b' : 'c+b');
        if (!$stream) throw new InvalidArgumentException('crm_resolution_state_failed');
        try {
            if (!chmod($path, 0600)) throw new InvalidArgumentException('crm_resolution_state_failed');
            $json = json_encode($state, JSON_THROW_ON_ERROR);
            if (!ftruncate($stream, 0) || fwrite($stream, $json) !== strlen($json) || !fflush($stream)) throw new InvalidArgumentException('crm_resolution_state_failed');
        } finally { fclose($stream); }
    }
    private function pruneTokens(): void
    {
        $count = 0;
        foreach (glob(dirname($this->dataPath) . DIRECTORY_SEPARATOR . 'crm-network-resolution-preview-*.json') ?: [] as $path) {
            if (is_link($path) || preg_match('/^crm-network-resolution-preview-[a-f0-9]{64}\.json$/D', basename($path)) !== 1) continue;
            if (($date = filemtime($path)) !== false && $date + self::TTL + 60 < $this->now()) @unlink($path);
            else $count++;
        }
        if ($count >= 64) throw new InvalidArgumentException('crm_resolution_preview_limit');
    }
    private static function id($value): ?int
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
    private static function selection(array $values): array
    {
        if (!$values || count($values) > self::MAX_SERVICES) throw new InvalidArgumentException('crm_resolution_service_limit');
        $ids = [];
        foreach ($values as $value) { $id = self::id($value); if ($id === null) throw new InvalidArgumentException('invalid_crm_resolution_service'); $ids[$id] = $id; }
        return array_values($ids);
    }
    private static function notFound(Throwable $failure): bool
    {
        for ($error = $failure; $error !== null; $error = $error->getPrevious()) {
            if ($error instanceof \GuzzleHttp\Exception\RequestException) return $error->hasResponse() && $error->getResponse()->getStatusCode() === 404;
        }
        return false;
    }

    /** Return bounded diagnostics, never response bodies, URLs or exception messages. */
    private static function writeFailure(Throwable $failure): array
    {
        $status = null;
        for ($error = $failure; $error !== null; $error = $error->getPrevious()) {
            if ($error instanceof \GuzzleHttp\Exception\RequestException && $error->hasResponse()) {
                $code = $error->getResponse()->getStatusCode();
                if ($code >= 400 && $code <= 599) $status = $code;
                break;
            }
        }
        $rejected = $status !== null && $status < 500 && $status !== 408;
        $reasons = [400 => 'validation_failed', 401 => 'permission_denied', 403 => 'permission_denied',
            404 => 'not_found', 405 => 'method_not_allowed', 409 => 'conflict', 422 => 'validation_failed', 429 => 'rate_limited'];
        $reason = ($status === null ? null : ($reasons[$status] ?? null)) ?? ($status === null ? 'transport_unconfirmed'
            : ($rejected ? 'http_rejected' : 'server_unconfirmed'));
        return ['write_outcome' => $rejected ? 'rejected' : 'unconfirmed', 'write_http_status' => $status, 'write_reason' => $reason];
    }
}
