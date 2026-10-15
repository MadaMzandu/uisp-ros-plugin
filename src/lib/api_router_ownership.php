<?php

require_once __DIR__ . '/api_site_address_policy.php';

/** Safe identifiers only; RouterOS rows and UISP responses never become error text. */
final class RouterOwnershipConflict extends RuntimeException
{
    public array $context;

    public function __construct(int $current, int $previous, string $reason)
    {
        $this->context = ['requestedServiceId' => $current, 'previousServiceId' => $previous, 'reason' => $reason];
        parent::__construct('router_ownership_conflict: current_service=' . $current . ' previous_service=' . $previous . ' reason=' . $reason);
    }
}

/** Request-scoped permission to update one verified account with an obsolete or contradictory service marker. */
final class ApiRouterOwnership
{
    private array $service;
    private array $device;
    private $request;
    private string $dataPath;
    private array $issued = [];
    private int $current = 0;
    private int $previous = 0;
    private int $requests = 0;
    private float $deadline = 0;

    public function __construct(array $service, array $device, ?callable $request = null, string $dataPath = 'data/data.db')
    {
        $this->service = $service; $this->device = $device; $this->request = $request; $this->dataPath = $dataPath;
    }

    /** The caller must reread the exact router row and call matches immediately before its set command. */
    public function authorize(array $command, array $row): array
    {
        $this->current = self::id($this->service['id'] ?? null) ?? 0;
        $this->previous = 0; $this->requests = 0; $this->deadline = microtime(true) + 12;
        try {
            $binding = $this->binding($command, $row);
            if ($binding === null) $this->fail('identity_unverified');
            $this->previous = $binding['previous_service'];
            [$configuration, $devices] = ApiSiteAddressPolicy::stored($this->dataPath);
            $did = self::id($this->service['device'] ?? null);
            if ($did === null || !isset($devices[$did]) || self::id($this->device['id'] ?? null) !== $did
                || !is_string($this->device['ip'] ?? null) || trim($this->device['ip']) === ''
                || (int) ($this->device['port'] ?? 8728) < 1 || (int) ($this->device['port'] ?? 8728) > 65535
                || self::endpoint($devices[$did]) !== self::endpoint($this->device)) $this->fail('router_changed');
            $fingerprint = self::fingerprint([$configuration, $devices]);
            $catalog = $this->call('custom-attributes');
            $site = $this->descriptor($configuration['device_name_attr'] ?? null, $catalog, 'mapping_site_unverified');
            $username = $this->descriptor($configuration['pppoe_user_attr'] ?? null, $catalog, 'mapping_user_unverified');
            $client = $this->call('clients/' . $binding['client']);
            if ((!is_array($client) && !is_object($client)) || self::id(((array) $client)['id'] ?? null) !== $binding['client']
                || !in_array(((array) $client)['isArchived'] ?? null, [false, 0, '0'], true)) $this->fail('client_unverified');
            $freshTarget = $this->call('clients/services/' . $this->current);
            $target = $this->target($freshTarget, $catalog, $configuration, $devices);
            $old = $this->oldService();
            $evidence = $this->previousEvidence($old, (array)$freshTarget, $binding, $catalog, $configuration, $devices, $site, $username);
            $this->assertQueueClear($binding, $devices, $evidence);
            $collection = $this->collection($username, $binding['name']);
            if (!isset($collection[$this->current])) $this->fail('catalog_incomplete');
            $catalogTarget = $this->target($collection[$this->current], $catalog, $configuration, $devices);
            if ($catalogTarget['status'] !== $target['status']) $this->fail('target_changed');
            if ($old === null && isset($collection[$this->previous])) $this->fail('previous_changed');
            if ($old !== null && isset($collection[$this->previous])
                && (self::id($collection[$this->previous]['clientId'] ?? null) !== self::id($old['clientId'])
                || (int) $collection[$this->previous]['status'] !== (int) $old['status'])) $this->fail('previous_changed');
            foreach ($collection as $sid => $peer) {
                if ($sid === $this->current || in_array((int) $peer['status'], [2, 5, 8], true)) continue;
                if ($this->attribute($peer, $username) !== $binding['name']) continue;
                $peerSite = $this->attribute($peer, $site);
                if ($peerSite === null || trim($peerSite) === '') $this->fail('peer_site_unverified');
                $matches = [];
                foreach ($devices as $id => $candidate) if (strcasecmp(trim((string) ($candidate['name'] ?? '')), trim($peerSite)) === 0) $matches[] = $id;
                if (count($matches) !== 1) $this->fail('peer_site_unverified');
                if (self::endpoint($devices[$matches[0]])['address'] === self::endpoint($this->device)['address']) $this->fail('account_claimed');
            }
            // A stale event, reactivated old service, or changed local routing invalidates the decision.
            $finalFreshTarget = $this->call('clients/services/' . $this->current);
            $finalTarget = $this->target($finalFreshTarget, $catalog, $configuration, $devices);
            $finalEvidence = $this->previousEvidence($this->oldService(), (array)$finalFreshTarget, $binding,
                $catalog, $configuration, $devices, $site, $username);
            if ($finalTarget['status'] !== $target['status'] || $finalEvidence !== $evidence) $this->fail('target_changed');
            if (self::fingerprint(ApiSiteAddressPolicy::stored($this->dataPath)) !== $fingerprint) $this->fail('router_changed');
            $this->assertQueueClear($binding, $devices, $evidence);
            $token = bin2hex(random_bytes(32));
            $this->backup($binding, $token, $evidence);
            $this->issued[$token] = ['binding' => $binding, 'evidence' => $evidence, 'configuration' => $fingerprint, 'expires' => microtime(true) + 30];
            return ['token' => $token, 'current_service' => $this->current, 'previous_service' => $this->previous];
        } catch (RouterOwnershipConflict $failure) { throw $failure; }
        catch (Throwable $failure) { $this->fail('verification_unavailable'); }
    }

    /** Proofs are private to this instance and bind row id, name, comment, command and physical endpoint. */
    public function matches(array $proof, array $command, array $row, array $device): bool
    {
        $token = $proof['token'] ?? null;
        if (!is_string($token) || !isset($this->issued[$token])) return false;
        $state = $this->issued[$token];
        try {
            $stored = ApiSiteAddressPolicy::stored($this->dataPath);
            if ($state['expires'] < microtime(true) || $state['binding'] !== $this->binding($command, $row)
                || self::endpoint($device) !== self::endpoint($this->device) || $state['configuration'] !== self::fingerprint($stored)) return false;
            $this->assertQueueClear($state['binding'], $stored[1], $state['evidence']); return true;
        } catch (Throwable $failure) { return false; }
    }

    private function binding(array $command, array $row): ?array
    {
        $path = trim((string) ($command['path'] ?? ''), '/');
        $sid = self::id($this->service['id'] ?? null); $cid = self::id($this->service['clientId'] ?? null);
        $name = $command['name'] ?? null; $comment = $row['comment'] ?? null; $rowId = $row['.id'] ?? null;
        $rowAddress = $row[$path === 'ppp/secret' ? 'remote-address' : 'address'] ?? null;
        $commandAddress = $command[$path === 'ppp/secret' ? 'remote-address' : 'address'] ?? null;
        if (!in_array($path, ['ppp/secret', 'ip/hotspot/user'], true) || $path !== (empty($this->service['hotspot']) ? 'ppp/secret' : 'ip/hotspot/user')
            || ($command['action'] ?? null) === 'remove'
            || $sid === null || $cid === null || self::id($command['_service_id'] ?? null) !== $sid
            || self::id($command['_client_id'] ?? null) !== $cid || !is_string($name) || $name === '' || strlen($name) > 256
            || preg_match('/[\x00-\x1f\x7f]/', $name) || ($this->service['username'] ?? null) !== $name || ($row['name'] ?? null) !== $name
            || !is_string($comment) || strlen($comment) > 2048 || !is_string($rowId) || preg_match('/^\*[a-fA-F0-9]+$/D', $rowId) !== 1
            || preg_match('/^([1-9][0-9]*) - .+ - ([1-9][0-9]*)$/sD', $comment, $owner) !== 1
            || self::id($owner[1]) !== $cid || self::id($owner[2]) === null || (int) $owner[2] === $sid
            || !is_string($command['comment'] ?? null)
            || preg_match('/^' . $cid . ' - .+ - ' . $sid . '$/sD', $command['comment']) !== 1
            || filter_var($this->service['mac'] ?? '', FILTER_VALIDATE_MAC)
            || ($rowAddress !== null && (!is_string($rowAddress) || strlen($rowAddress) > 256))
            || ($commandAddress !== null && (!is_string($commandAddress) || strlen($commandAddress) > 256))) return null;
        return ['current_service' => $sid, 'previous_service' => (int) $owner[2], 'client' => $cid,
            'path' => $path, 'row_id' => $rowId, 'name' => $name, 'old_comment' => $comment,
            'new_comment' => $command['comment'], 'row_address' => $rowAddress, 'command_address' => $commandAddress,
            'command' => self::fingerprint($command), 'endpoint' => self::fingerprint(self::endpoint($this->device))];
    }

    private function target($value, array $catalog, array $configuration, array $devices): array
    {
        if (!is_array($value) && !is_object($value)) $this->fail('target_unverified');
        $value = (array) $value;
        if (self::id($value['id'] ?? null) !== $this->current || self::id($value['clientId'] ?? null) !== self::id($this->service['clientId'] ?? null)
            || !in_array($value['status'] ?? null, [1, 3, 4, 7, '1', '3', '4', '7'], true)
            || (int) $value['status'] !== (int) ($this->service['status'] ?? -1)
            || !ApiSiteAddressPolicy::currentPrincipal($value, $this->service, $catalog, $configuration, $devices)) $this->fail('target_changed');
        $hotspotKey = $configuration['hs_attr'] ?? null;
        $hotspotDescriptor = $this->descriptor($hotspotKey, $catalog, 'mapping_hotspot_unverified', empty($this->service['hotspot']));
        if ($hotspotDescriptor === null && is_string($hotspotKey) && $hotspotKey !== '') {
            foreach ($value['attributes'] ?? [] as $attribute) {
                if (!is_array($attribute) && !is_object($attribute)) $this->fail('mapping_hotspot_unverified');
                if ((((array)$attribute)['key']??null)===$hotspotKey) $this->fail('mapping_hotspot_unverified');
            }
        }
        $hotspot = $hotspotDescriptor === null ? null : $this->attribute($value, $hotspotDescriptor);
        if (!empty($hotspot) !== !empty($this->service['hotspot'])) $this->fail('target_changed');
        return ['status' => (int) $value['status']];
    }

    private function oldService(): ?array
    {
        try { $old = $this->call('clients/services/' . $this->previous); }
        catch (Throwable $failure) {
            for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof \GuzzleHttp\Exception\RequestException && $cause->hasResponse()
                    && $cause->getResponse()->getStatusCode() === 404) return null;
            }
            throw $failure;
        }
        if (!is_array($old) && !is_object($old)) $this->fail('previous_unverified');
        $old = (array) $old;
        if (self::id($old['id'] ?? null) !== $this->previous || self::id($old['clientId'] ?? null) === null
            || !in_array($old['status'] ?? null, [0,1,2,3,4,5,6,7,8,'0','1','2','3','4','5','6','7','8'], true)) $this->fail('previous_unverified');
        return $old;
    }

    private function previousEvidence(?array $old, array $target, array $binding, array $catalog, array $configuration,
        array $devices, array $siteDescriptor, array $userDescriptor): array
    {
        if ($old === null) return ['kind'=>'retired_service','previous_status'=>'not_found'];
        $cid = self::id($old['clientId']); $status = (int)$old['status'];
        if ($cid === $binding['client']) {
            if (!in_array($status, [2,5,8], true)) $this->fail('previous_active');
            return ['kind'=>'retired_service','previous_status'=>(string)$status,'previous_client'=>$cid];
        }
        // A mixed marker is not a transfer from the other client. Prove that the
        // referenced service describes a different account on another router.
        $oldUser = $this->attribute($old, $userDescriptor); $oldSite = $this->attribute($old, $siteDescriptor);
        $targetSite = $this->attribute($target, $siteDescriptor);
        if (!is_string($oldUser) || $oldUser === '' || $oldUser === $binding['name'] || !is_string($oldSite)
            || trim($oldSite) === '' || !is_string($targetSite) || strcasecmp(trim($oldSite),trim($targetSite)) === 0) $this->fail('marker_unverified');
        $matches = [];
        foreach ($devices as $id=>$candidate) if (strcasecmp(trim((string)($candidate['name']??'')),trim($oldSite))===0) $matches[]=$id;
        if (count($matches)!==1 || trim((string)($devices[$matches[0]]['ip']??''))===''
            || self::endpoint($devices[$matches[0]])['address']===self::endpoint($this->device)['address']) $this->fail('marker_unverified');
        $addressDescriptor = $this->descriptor($configuration['ip_addr_attr']??null,$catalog,'marker_unverified');
        $targetAddress = $this->literalAddress($this->attribute($target,$addressDescriptor));
        $oldAddress = $this->literalAddress($this->attribute($old,$addressDescriptor));
        $observedAddress = $this->literalAddress($binding['row_address']);
        $commandAddress = $this->literalAddress($binding['command_address']);
        if ($targetAddress!==$observedAddress || $targetAddress!==$commandAddress || $oldAddress===$targetAddress) $this->fail('marker_unverified');
        return ['kind'=>'contradictory_service_marker','previous_status'=>(string)$status,'previous_client'=>$cid,
            'previous_username'=>$oldUser,'previous_site'=>$oldSite,'previous_endpoint'=>self::endpoint($devices[$matches[0]])['address'],
            'previous_address'=>$oldAddress,'target_site'=>$targetSite,'target_address'=>$targetAddress];
    }

    private function literalAddress($value): string
    {
        if (!is_string($value) || !filter_var(trim($value),FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) $this->fail('marker_unverified');
        return inet_ntop(inet_pton(trim($value)));
    }

    private function collection(array $username, string $value): array
    {
        $services = [];
        for ($page = 0; $page < 20; $page++) {
            $rows = $this->call('clients/services', ['customAttributeKey' => $username['key'], 'customAttributeValue' => $value,
                'limit' => 500, 'offset' => $page * 500]);
            if (!is_array($rows) || count($rows) > 500 || ($rows && array_keys($rows) !== range(0, count($rows) - 1))) $this->fail('catalog_incomplete');
            foreach ($rows as $row) {
                if (!is_array($row) && !is_object($row)) $this->fail('catalog_incomplete');
                $row = (array) $row; $sid = self::id($row['id'] ?? null);
                if ($sid === null || self::id($row['clientId'] ?? null) === null || isset($services[$sid])
                    || !in_array($row['status'] ?? null, [0,1,2,3,4,5,6,7,8,'0','1','2','3','4','5','6','7','8'], true)) $this->fail('catalog_incomplete');
                $services[$sid] = $row;
            }
            if (count($rows) < 500) return $services;
        }
        $this->fail('catalog_incomplete');
    }

    private function descriptor($key, $catalog, string $reason = 'mapping_unverified', bool $optional = false): ?array
    {
        if (!is_array($catalog) || !$catalog || array_keys($catalog) !== range(0, count($catalog) - 1)) $this->fail('mapping_unverified');
        $found = null; $ids = []; $keys = [];
        foreach ($catalog as $attribute) {
            if (!is_array($attribute) && !is_object($attribute)) $this->fail('mapping_unverified');
            $attribute = (array) $attribute; $id = self::id($attribute['id'] ?? null);
            $attributeKey = $attribute['key']??null;
            if ($id === null || isset($ids[$id]) || !is_string($attributeKey) || $attributeKey==='' || isset($keys[$attributeKey])) $this->fail('mapping_unverified');
            $ids[$id] = true; $keys[$attributeKey] = true;
            if (($attribute['key'] ?? null) !== $key) continue;
            if (($attribute['attributeType'] ?? null) !== 'service') $this->fail($reason);
            $found = ['id' => $id, 'key' => $key];
        }
        if ($found === null && !$optional) $this->fail($reason);
        return $found;
    }

    private function attribute(array $service, array $descriptor): ?string
    {
        $attributes = $service['attributes'] ?? null;
        if (!is_array($attributes) || ($attributes && array_keys($attributes) !== range(0, count($attributes) - 1))) $this->fail('catalog_incomplete');
        $found = false; $value = null;
        foreach ($attributes as $attribute) {
            if (!is_array($attribute) && !is_object($attribute)) $this->fail('catalog_incomplete');
            $attribute = (array) $attribute; $sameKey = ($attribute['key'] ?? null) === $descriptor['key'];
            $sameId = self::id($attribute['customAttributeId'] ?? null) === $descriptor['id'];
            if (!$sameKey && !$sameId) continue;
            if ($found || !$sameKey || (array_key_exists('customAttributeId', $attribute) && !$sameId)
                || !array_key_exists('value', $attribute) || (!is_string($attribute['value']) && $attribute['value'] !== null)) $this->fail('catalog_incomplete');
            $found = true; $value = $attribute['value'];
        }
        return $value;
    }

    private function call(string $path, array $parameters = [])
    {
        if (++$this->requests > 27 || microtime(true) >= $this->deadline) $this->fail('verification_unavailable');
        if ($this->request !== null) return ($this->request)($path, 'get', $parameters);
        require_once __DIR__ . '/api_uisp_client.php';
        $api = ApiUispClient::crm(); $remaining = $this->deadline - microtime(true);
        if ($remaining < 0.05) $this->fail('verification_unavailable');
        if (class_exists(\GuzzleHttp\Client::class)) {
            $property = new ReflectionProperty($api, 'client'); if (PHP_VERSION_ID < 80100) $property->setAccessible(true);
            $client = $property->getValue($api);
            if (!$client instanceof \GuzzleHttp\Client) $this->fail('verification_unavailable');
            $options = $client->getConfig(); $options['timeout'] = min(2.0, $remaining);
            $options['connect_timeout'] = min(1.0, $remaining); $options['read_timeout'] = min(2.0, $remaining);
            $property->setValue($api, new \GuzzleHttp\Client($options));
        }
        return json_decode(json_encode($api->get($path, $parameters), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Executable ownership claims matter; retained historical records are deliberately informative. */
    private function assertQueueClear(array $binding, array $devices, array $evidence): void
    {
        $path = dirname($this->dataPath) . DIRECTORY_SEPARATOR . 'jobs.db';
        if (!file_exists($path) && !is_link($path)) return;
        if (!is_file($path) || is_link($path)) $this->fail('queue_unverified');
        $db = null;
        try {
            $db = new SQLite3($path, SQLITE3_OPEN_READONLY); $db->enableExceptions(true); $db->busyTimeout(500); $db->exec('BEGIN');
            if ($db->querySingle("SELECT count(*) FROM sqlite_master WHERE (type='view' AND name IN ('jobs','cancellation_cleanups','service_versions')) OR (type='trigger' AND tbl_name IN ('jobs','cancellation_cleanups','service_versions'))")) $this->fail('queue_unverified');
            $queries = [["SELECT service_id,payload FROM jobs WHERE status IN ('pending','running')", false]];
            if ($db->querySingle("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='cancellation_cleanups'")) $queries[] = ['SELECT service_id,payload FROM cancellation_cleanups', false];
            if ($db->querySingle("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='service_versions'")) $queries[] = ['SELECT service_id,applied_payload AS payload FROM service_versions WHERE applied_payload IS NOT NULL', true];
            foreach ($queries as [$query,$applied]) {
                $read = $db->query($query . ' LIMIT 10001'); $count = 0;
                while ($row = $read->fetchArray(SQLITE3_ASSOC)) {
                    if (++$count > 10000 || self::id($row['service_id'] ?? null) === null || !is_string($row['payload'] ?? null) || strlen($row['payload']) > 2097152) $this->fail('queue_unverified');
                    $oldSid = (int)$row['service_id'] === $binding['previous_service'];
                    if ($oldSid && $evidence['kind']==='retired_service') {
                        if (!$applied) $this->fail('previous_job_pending');
                        // This confirmed historical account is what the retired-service proof is transferring.
                        continue;
                    }
                    $snapshot = json_decode($row['payload'], true, 32, JSON_THROW_ON_ERROR);
                    if (!is_array($snapshot)) $this->fail('queue_unverified');
                    if (self::id($snapshot['id']??null)!==(int)$row['service_id']) $this->fail('queue_unverified');
                    $this->checkSnapshot($snapshot, $binding, $devices, $evidence, $applied);
                }
                $read->finalize();
            }
        } catch (RouterOwnershipConflict $failure) { throw $failure; }
        catch (Throwable $failure) { $this->fail('queue_unverified'); }
        finally { if ($db !== null) $db->close(); }
    }

    private function checkSnapshot(array $snapshot, array $binding, array $devices, array $evidence, bool $applied, int $depth = 0): void
    {
        if ($depth > 4) $this->fail('queue_unverified');
        if (isset($snapshot['_cleanup'])) {
            if (!is_array($snapshot['_cleanup'])) $this->fail('queue_unverified');
            $this->checkSnapshot($snapshot['_cleanup'], $binding, $devices, $evidence, $applied, $depth + 1);
        }
        $sid = self::id($snapshot['id'] ?? null);
        if ($sid === $binding['current_service']) return;
        $contradictoryOld = $sid===$binding['previous_service'] && $evidence['kind']==='contradictory_service_marker';
        if ($sid === $binding['previous_service'] && !$contradictoryOld) {
            if (!$applied) $this->fail('previous_job_pending');
            return;
        }
        $snapshotUser = $snapshot['username']??null;
        $dhcp = filter_var($snapshot['mac']??'',FILTER_VALIDATE_MAC)!==false;
        $namedPrincipal = !$dhcp && is_string($snapshotUser) && $snapshotUser!=='';
        $knownPrincipal = $dhcp || $namedPrincipal;
        $snapshotPath = empty($snapshot['hotspot']) ? 'ppp/secret' : 'ip/hotspot/user';
        $claim = $namedPrincipal && $snapshotUser===$binding['name'] && $snapshotPath===$binding['path'];
        if (isset($snapshot['_commands'])) {
            if (!is_array($snapshot['_commands'])) $this->fail('queue_unverified');
            foreach ($snapshot['_commands'] as $command) {
                if (!is_array($command) || !is_string($command['path']??null)) $this->fail('queue_unverified');
                $path = trim($command['path'],'/');
                if (in_array($path,['ppp/secret','ppp/active','ip/hotspot/user','ip/hotspot/active'],true)) {
                    $name = $command['name']??($command['user']??null);
                    if (!is_string($name) || $name==='') $this->fail('queue_unverified');
                    $knownPrincipal = true;
                    $principalPath = in_array($path,['ppp/secret','ppp/active'],true) ? 'ppp/secret' : 'ip/hotspot/user';
                    if ($principalPath===$binding['path'] && $name===$binding['name']) $claim = true;
                }
            }
        }
        if (!$claim && !$contradictoryOld) return;
        if ($contradictoryOld && (!$knownPrincipal || self::id($snapshot['clientId']??null)!==$evidence['previous_client'])) $this->fail('queue_unverified');
        $did = self::id($snapshot['device'] ?? null); $device = $snapshot['_device'] ?? ($did === null ? null : ($devices[$did] ?? null));
        if (!is_array($device) || trim((string) ($device['ip'] ?? '')) === '') $this->fail('queue_unverified');
        if ($claim && self::endpoint($device)['address'] === self::endpoint($this->device)['address']) $this->fail($applied ? 'account_claimed' : 'previous_job_pending');
    }

    private function backup(array $binding, string $token, array $evidence): void
    {
        $parent = dirname($this->dataPath); $directory = $parent . DIRECTORY_SEPARATOR . 'router-ownership';
        if (!is_dir($parent) || is_link($parent) || is_link($directory) || (file_exists($directory) && !is_dir($directory))) $this->fail('backup_failed');
        if (!is_dir($directory) && !mkdir($directory, 0700)) $this->fail('backup_failed');
        if (!chmod($directory, 0700)) $this->fail('backup_failed');
        $path = $directory . DIRECTORY_SEPARATOR . $this->current . '-' . substr($token, 0, 32) . '.json';
        $stream = fopen($path, 'x+b'); if (!$stream) $this->fail('backup_failed');
        try {
            // Deliberately exclude command bodies, router credentials, account passwords and full UISP responses.
            unset($binding['command']);
            $state = ['created_at' => gmdate('Y-m-d\TH:i:s\Z'), 'previous_status' => $evidence['previous_status'], 'evidence'=>$evidence, 'account' => $binding];
            $json = json_encode($state, JSON_THROW_ON_ERROR);
            if (!flock($stream, LOCK_EX | LOCK_NB) || !chmod($path, 0600) || fwrite($stream, $json) !== strlen($json) || !fflush($stream)) $this->fail('backup_failed');
            if (function_exists('fsync') && !fsync($stream)) $this->fail('backup_failed');
        } finally { fclose($stream); }
    }

    private static function endpoint(array $device): array
    {
        $ip = strtolower(trim((string) ($device['ip'] ?? ''))); $port = (int) ($device['port'] ?? 8728);
        if (filter_var(trim($ip, '[]'), FILTER_VALIDATE_IP)) $ip = inet_ntop(inet_pton(trim($ip, '[]')));
        return ['address' => $ip . ':' . $port, 'ssl' => isset($device['ssl']) ? filter_var($device['ssl'], FILTER_VALIDATE_BOOLEAN) : $port === 8729,
            'user' => hash('sha256', (string) ($device['user'] ?? '')), 'password' => hash('sha256', (string) ($device['password'] ?? ''))];
    }

    private static function fingerprint(array $value): string { return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR)); }
    private static function id($value): ?int {
        if (!is_int($value) && !is_string($value)) return null;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]); return $id === false ? null : $id;
    }
    private function fail(string $reason): void { throw new RouterOwnershipConflict($this->current, $this->previous, $reason); }
}
