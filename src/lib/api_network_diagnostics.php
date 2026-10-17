<?php

require_once __DIR__ . '/api_network_address_evidence.php';

/**
 * Read-only owner information for the setup address-conflict diagnostic.
 *
 * Local records describe the plugin's last known state, not the current CRM
 * state. CRM reads happen only when the authenticated caller explicitly asks
 * to refresh a bounded selection; they never populate the cache or queue.
 */
final class ApiNetworkDiagnostics
{
    public const MAX_REFRESH_SERVICES = 3;
    public const MAX_REFRESH_CLIENTS = 3;
    private const REFRESH_BUDGET_SECONDS = 12.0;

    private $request;
    private $clock;
    private string $dataPath;
    private string $cachePath;

    public function __construct(?callable $request = null, string $dataPath = 'data/data.db', string $cachePath = 'data/cache.db', ?callable $clock = null)
    {
        $this->request = $request;
        $this->clock = $clock;
        $this->dataPath = $dataPath;
        $this->cachePath = $cachePath;
    }

    public function enrich(array $details, bool $refresh = false, array $serviceIds = [], bool $refreshClients = false): array
    {
        if ($refresh && $refreshClients) throw new InvalidArgumentException('invalid_diagnostic_refresh_mode');
        $ids = [];
        foreach (array_slice($details['conflicts'] ?? [], 0, 20) as $conflict) {
            foreach (array_slice($conflict['service_ids'] ?? [], 0, 50) as $value) {
                $id = self::positiveId($value);
                if ($id !== null) $ids[$id] = $id;
            }
        }

        $database = $this->openExisting($this->dataPath);
        $cache = $this->openExisting($this->cachePath);
        $mapping = $database === null ? ['address' => null, 'address6' => null] : ApiNetworkAddressEvidence::mapping($database);
        try {
            $local = $this->rows($database, 'services', ['id', 'clientId', 'status'], array_values($ids));
            $cached = $this->rows($cache, 'services', ['id', 'clientId', 'status'], array_values($ids));
            $clientIds = [];
            $owners = [];
            foreach ($ids as $id) {
                // Main database identity wins. Missing legacy fields may use
                // cache values only when they belong to the same client.
                $service = $local[$id] ?? $cached[$id] ?? [];
                $clientId = self::positiveId($service['clientId'] ?? null);
                if ($clientId === null) $clientId = self::positiveId($cached[$id]['clientId'] ?? null);
                $status = self::statusCode($service['status'] ?? null);
                if ($status === null && $clientId === self::positiveId($cached[$id]['clientId'] ?? null)) {
                    $status = self::statusCode($cached[$id]['status'] ?? null);
                }
                if ($clientId !== null) $clientIds[$clientId] = $clientId;
                $owners[$id] = [
                    'service_id' => $id,
                    'client_id' => $clientId,
                    'client_name' => null,
                    'service_status_code' => $status,
                    'service_status' => self::statusLabel($status),
                    'client_archived' => null,
                    // Neither a missing local row nor an API failure confirms
                    // deletion. CRM exposes isArchived, not a deleted flag.
                    'client_deleted' => null,
                    'source' => isset($local[$id]) ? 'database' : (isset($cached[$id]) ? 'cache' : 'missing'),
                    'client_source' => 'missing',
                    'service_url' => '/crm/client/service/' . $id,
                    // Verified in the operator's current CRM breadcrumb. The
                    // service route alone is insufficient for archived records.
                    'client_url' => $clientId === null ? null : '/crm/client/' . $clientId,
                    'refresh_error' => null,
                    'client_refresh_error' => null,
                    'client_verified_at' => null,
                    'address_evidence' => ApiNetworkAddressEvidence::parse([], []),
                    'address_verified_at' => null,
                ];
            }

            $clients = $this->rows($cache, 'clients', ['id', 'company', 'firstName', 'lastName', 'isArchived'], array_values($clientIds));
            foreach ($owners as &$owner) {
                $client = $clients[$owner['client_id'] ?? 0] ?? null;
                if ($client === null) continue;
                $owner['client_name'] = self::clientName($client);
                $owner['client_archived'] = self::boolean($client['isArchived'] ?? null);
                $owner['client_source'] = 'cache';
            }
            unset($owner);
        } finally {
            if ($database !== null) $database->close();
            if ($cache !== null) $cache->close();
        }

        $summary = ['requested' => $refresh, 'service_limit' => self::MAX_REFRESH_SERVICES,
            'services_attempted' => 0, 'api_requests' => 0, 'api_request_limit' => 7,
            'catalog_requests' => 0, 'truncated' => false, 'timed_out' => false];
        if ($refresh) $summary = $this->refresh($owners, $serviceIds, $summary, $mapping);
        $clientSummary = ['requested' => $refreshClients, 'client_limit' => self::MAX_REFRESH_CLIENTS,
            'clients_attempted' => 0, 'api_requests' => 0, 'truncated' => false, 'timed_out' => false];
        if ($refreshClients) $clientSummary = $this->refreshClients($owners, $serviceIds, $clientSummary);
        foreach ($details['conflicts'] ?? [] as $index => $conflict) {
            $details['conflicts'][$index]['owners'] = [];
            foreach (array_slice($conflict['service_ids'] ?? [], 0, 50) as $value) {
                $id = self::positiveId($value);
                if ($id !== null && isset($owners[$id])) {
                    $owner = $owners[$id];
                    $field = ($conflict['family'] ?? null) === 'IPv6' ? 'address6' : 'address';
                    $evidence = $owner['address_evidence'][$field];
                    $owner['saved_address'] = is_string($conflict['address'] ?? null) ? $conflict['address'] : null;
                    $owner['current_address'] = $evidence['value'];
                    $owner['current_address_known'] = $evidence['known'];
                    $owner['address_comparison'] = ApiNetworkAddressEvidence::compare($owner['saved_address'], $evidence, $field);
                    $owner['address_refresh_error'] = $evidence['reason'];
                    $details['conflicts'][$index]['owners'][] = $owner;
                }
            }
        }
        $details['owner_refresh'] = $summary;
        $details['client_refresh'] = $clientSummary;
        return $details;
    }

    private function refreshClients(array &$owners, array $selection, array $summary): array
    {
        $clientIds = [];
        foreach ($selection ?: array_keys($owners) as $value) {
            $id = self::positiveId($value);
            // Only stored clients of services in this current conflict qualify.
            // A caller cannot pass an arbitrary client ID through this endpoint.
            if ($id === null || !isset($owners[$id])) continue;
            $clientId = $owners[$id]['client_id'];
            if ($clientId !== null) $clientIds[$clientId] = $clientId;
        }
        $summary['truncated'] = count($clientIds) > self::MAX_REFRESH_CLIENTS;
        $clientIds = array_slice($clientIds, 0, self::MAX_REFRESH_CLIENTS);
        $deadline = $this->now() + self::REFRESH_BUDGET_SECONDS;
        foreach ($clientIds as $clientId) {
            $client = null;
            $error = null;
            if ($this->now() >= $deadline) {
                $error = 'deadline';
                $summary['timed_out'] = true;
            } else {
                $summary['clients_attempted']++;
                $summary['api_requests']++;
                try { $client = $this->entity($this->get('clients/' . $clientId), $clientId); }
                catch (Throwable $failure) {}
                if ($client === null) $error = 'unavailable';
            }
            $this->applyClientResult($owners, $clientId, $client, $error, $client === null ? null : gmdate('c'));
        }
        return $summary;
    }

    private function applyClientResult(array &$owners, int $clientId, ?array $client, ?string $error, ?string $verifiedAt): void
    {
        foreach ($owners as &$owner) {
            if ($owner['client_id'] !== $clientId) continue;
            $owner['client_refresh_error'] = $error;
            $owner['client_verified_at'] = $verifiedAt;
            if ($client !== null) {
                $owner['client_name'] = self::clientName($client);
                $owner['client_archived'] = self::boolean($client['isArchived'] ?? null);
                $owner['client_source'] = 'crm';
            }
        }
        unset($owner);
    }

    private function refresh(array &$owners, array $selection, array $summary, array $mapping): array
    {
        $selected = [];
        foreach ($selection ?: array_keys($owners) as $value) {
            $id = self::positiveId($value);
            // A request cannot turn the conflict endpoint into a general CRM
            // lookup. Only owners present in the current diagnostic qualify.
            if ($id !== null && isset($owners[$id])) $selected[$id] = $id;
        }
        $summary['truncated'] = count($selected) > self::MAX_REFRESH_SERVICES;
        $selected = array_slice($selected, 0, self::MAX_REFRESH_SERVICES);
        $deadline = $this->now() + self::REFRESH_BUDGET_SECONDS;
        $clientResults = [];
        $catalogLoaded = false;
        $validatedMapping = ['address' => null, 'address6' => null];
        foreach ($selected as $id) {
            if ($this->now() >= $deadline) {
                $owners[$id]['refresh_error'] = 'deadline';
                $clientId = $owners[$id]['client_id'];
                if ($clientId !== null && !array_key_exists($clientId, $clientResults)) {
                    $this->applyClientResult($owners, $clientId, null, 'deadline', null);
                }
                $summary['timed_out'] = true;
                continue;
            }
            $summary['services_attempted']++;
            $summary['api_requests']++;
            try {
                $service = $this->entity($this->get('clients/services/' . $id), $id);
                if ($service === null) throw new RuntimeException('invalid_diagnostic_entity');
                $liveClientId = self::positiveId($service['clientId'] ?? null);
                if ($liveClientId === null) throw new RuntimeException('invalid_diagnostic_client');
                if ($liveClientId !== $owners[$id]['client_id']) {
                    $owners[$id]['client_name'] = null;
                    $owners[$id]['client_archived'] = null;
                    $owners[$id]['client_source'] = 'missing';
                    $owners[$id]['client_verified_at'] = null;
                }
                $owners[$id]['client_id'] = $liveClientId;
                $owners[$id]['client_url'] = $liveClientId === null ? null : '/crm/client/' . $liveClientId;
                $owners[$id]['service_status_code'] = self::statusCode($service['status'] ?? null);
                $owners[$id]['service_status'] = self::statusLabel($owners[$id]['service_status_code']);
                $owners[$id]['source'] = 'crm';
                // One explicitly requested catalog read establishes that the
                // configured keys still identify service attributes. A renamed
                // or misspelled key must not turn an absent value into proof.
                if (!$catalogLoaded && ($mapping['address'] !== null || $mapping['address6'] !== null)) {
                    if ($this->now() >= $deadline) $summary['timed_out'] = true;
                    else {
                        $summary['api_requests']++;
                        $summary['catalog_requests']++;
                        try { $validatedMapping = ApiNetworkAddressEvidence::validateMapping($mapping, $this->get('custom-attributes')); }
                        catch (Throwable $error) {}
                    }
                    $catalogLoaded = true;
                }
                $owners[$id]['address_evidence'] = ApiNetworkAddressEvidence::parse($service, $validatedMapping);
                $owners[$id]['address_verified_at'] = gmdate('c');
            } catch (Throwable $error) {
                // The wrapper cannot distinguish authorization, connection,
                // deleted-service, or HTTP 404 causes safely. Retain local facts.
                $owners[$id]['refresh_error'] = 'unavailable';
            }

            $clientId = $owners[$id]['client_id'];
            if ($clientId === null) continue;
            if (!array_key_exists($clientId, $clientResults)) {
                if ($this->now() >= $deadline) {
                    $this->applyClientResult($owners, $clientId, null, 'deadline', null);
                    $summary['timed_out'] = true;
                    continue;
                }
                $summary['api_requests']++;
                try { $client = $this->entity($this->get('clients/' . $clientId), $clientId); }
                catch (Throwable $error) { $client = null; }
                $clientResults[$clientId] = ['client' => $client, 'error' => $client === null ? 'unavailable' : null,
                    'verified_at' => $client === null ? null : gmdate('c')];
            }
            $result = $clientResults[$clientId];
            // Archive facts belong to the client, including other conflict rows
            // whose service was not selected for this verification request.
            $this->applyClientResult($owners, $clientId, $result['client'], $result['error'], $result['verified_at']);
        }
        return $summary;
    }

    private function get(string $path)
    {
        if ($this->request !== null) return ($this->request)($path);
        // Loading the diagnostic from local data does not load or invoke the
        // API transport. The existing wrapper retains its timeout/auth policy.
        require_once __DIR__ . '/api_ucrm.php';
        return (new ApiUcrm())->get($path);
    }

    private function now(): float
    {
        return $this->clock === null ? microtime(true) : (float) ($this->clock)();
    }

    private function entity($value, int $id): ?array
    {
        if (!is_object($value) && !is_array($value)) return null;
        $value = (array) $value;
        return self::positiveId($value['id'] ?? null) === $id ? $value : null;
    }

    private function openExisting(string $path): ?SQLite3
    {
        if (!is_file($path)) return null;
        try {
            $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
            $db->enableExceptions(true);
            $db->busyTimeout(1000);
            return $db;
        } catch (Throwable $error) { return null; }
    }

    private function rows(?SQLite3 $db, string $table, array $allowedFields, array $ids): array
    {
        if ($db === null || !$ids) return [];
        // SQLite 3.24 installations can allow only 999 bound variables. The
        // setup diagnostic can contain 20 groups of 50 different owners.
        if (count($ids) > 500) {
            $rows = [];
            foreach (array_chunk($ids, 500) as $chunk) $rows += $this->rows($db, $table, $allowedFields, $chunk);
            return $rows;
        }
        try {
            $columns = [];
            $info = $db->query('PRAGMA table_info("' . $table . '")');
            try { while ($column = $info->fetchArray(SQLITE3_ASSOC)) $columns[$column['name']] = true; }
            finally { $info->finalize(); }
            if (!isset($columns['id'])) return [];
            $fields = array_values(array_filter($allowedFields, static function (string $field) use ($columns): bool {
                return isset($columns[$field]);
            }));
            $quoted = array_map(static function (string $field): string { return '"' . $field . '"'; }, $fields);
            $sql = 'SELECT ' . implode(',', $quoted) . ' FROM "' . $table . '" WHERE id IN ('
                . implode(',', array_fill(0, count($ids), '?')) . ')';
            $statement = $db->prepare($sql);
            try {
                foreach ($ids as $index => $id) $statement->bindValue($index + 1, $id, SQLITE3_INTEGER);
                $result = $statement->execute();
                try {
                    $rows = [];
                    while ($row = $result->fetchArray(SQLITE3_ASSOC)) $rows[(int) $row['id']] = $row;
                    return $rows;
                } finally { $result->finalize(); }
            } finally { $statement->close(); }
        } catch (Throwable $error) { return []; }
    }

    private static function positiveId($value): ?int
    {
        if (!is_int($value) && !is_string($value)) return null;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    private static function statusCode($value): ?int
    {
        if (!is_int($value) && !is_string($value)) return null;
        $status = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 999]]);
        return $status === false ? null : $status;
    }

    private static function statusLabel(?int $status): string
    {
        // UISP CRM's documented ServiceStatus enum:
        // https://jsapi.apiary.io/apis/unmscrm (ServiceStatus data structure).
        $labels = [0 => 'Preparado', 1 => 'Activo', 2 => 'Terminado', 3 => 'Suspendido',
            4 => 'Preparado bloqueado', 5 => 'Obsoleto', 6 => 'Diferido', 7 => 'Cotizado', 8 => 'Inactivo'];
        return $status === null ? 'Sin verificar' : ($labels[$status] ?? 'Estado ' . $status);
    }

    private static function boolean($value): ?bool
    {
        if ($value === true || $value === 1 || $value === '1') return true;
        if ($value === false || $value === 0 || $value === '0') return false;
        return null;
    }

    private static function clientName(array $client): ?string
    {
        $company = self::text($client['companyName'] ?? $client['company'] ?? null);
        if ($company !== null) return $company;
        $name = trim((self::text($client['firstName'] ?? null) ?? '') . ' ' . (self::text($client['lastName'] ?? null) ?? ''));
        return $name === '' ? null : $name;
    }

    private static function text($value): ?string
    {
        if (!is_string($value)) return null;
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
