<?php

/** Explicit, bounded RouterOS observation. Never provision, adopt, remove or disconnect anything. */
final class ApiJobRecoveryObservation
{
    private const MAX_SNAPSHOTS = 12;
    private const MAX_TARGETS = 60;
    private const MAX_DEVICES = 3;
    private const BUDGET = 12;
    private string $dataPath;
    private $reader;
    private $clock;
    private array $connections = [];

    public function __construct(string $dataPath = 'data/data.db', ?callable $reader = null, ?callable $clock = null)
    {
        $this->dataPath = $dataPath; $this->reader = $reader; $this->clock = $clock;
    }

    /** All targets must be absent. A same-name foreign or shared object remains protected. */
    public function inspect(array $snapshots): array
    {
        $checks = []; $targets = []; $devices = []; $started = $this->now(); $safe = false; $complete = true;
        $reason = 'router_snapshot_incomplete';
        try {
            if (!$snapshots || count($snapshots) > self::MAX_SNAPSHOTS) throw new InvalidArgumentException('router_observation_limit');
            foreach ($snapshots as $snapshot) {
                if (!is_array($snapshot) || self::id($snapshot['id'] ?? null) === null
                    || self::id($snapshot['clientId'] ?? null) === null || self::id($snapshot['device'] ?? null) === null
                    || !is_array($snapshot['_device'] ?? null)
                    || array_key_exists('_cleanup', $snapshot) || array_key_exists('_delete_frozen', $snapshot)
                    || !in_array($snapshot['action'] ?? 'update', ['update', 'queues_set'], true)) {
                    throw new InvalidArgumentException('router_snapshot_incomplete');
                }
                $did = self::id($snapshot['device']);
                $current = $this->device($did);
                if ($this->transport($current) !== $this->transport($snapshot['_device'])
                    || self::id($snapshot['_device']['id'] ?? null) !== $did) {
                    throw new InvalidArgumentException('router_authority_changed');
                }
                $devices[$did] = $current;
                if (count($devices) > self::MAX_DEVICES) throw new InvalidArgumentException('router_observation_limit');
                $commands = $snapshot['_commands'] ?? null;
                if ($commands !== null && !is_array($commands)) throw new InvalidArgumentException('router_snapshot_incomplete');
                if (!$commands) {
                    // Old versions sometimes retained only account identity. Show
                    // that account without claiming that unknown auxiliary resources are absent.
                    $complete = false; $commands = $this->principalCommands($snapshot);
                }
                if (count($commands) > self::MAX_TARGETS) throw new InvalidArgumentException('router_observation_limit');
                foreach ($commands as $command) {
                    foreach ($this->selectors($command, $snapshot) as $selector) {
                        $target = ['device_id' => $did] + $selector;
                        $key = hash('sha256', self::canonical($target)); $targets[$key] = $target;
                        if (count($targets) > self::MAX_TARGETS) throw new InvalidArgumentException('router_observation_limit');
                    }
                }
            }
            if (!$targets) throw new InvalidArgumentException('router_snapshot_incomplete');
            $safe = true;
            foreach ($targets as $target) {
                $check = ['device_id' => $target['device_id'], 'path' => '/' . $target['path'],
                    'selector_type' => $target['type'], 'identity_hint' => 'sha256:' . substr(hash('sha256', self::canonical($target['filter'])), 0, 12),
                    'selector_label' => $this->label((string) reset($target['filter'])),
                    'state' => 'unknown'];
                try {
                    if ($this->now() - $started >= self::BUDGET) throw new InvalidArgumentException('router_observation_limit');
                    $records = $this->read($devices[$target['device_id']], $target['path'], $target['filter']);
                    if ($this->now() - $started >= self::BUDGET) throw new InvalidArgumentException('router_observation_limit');
                    if (!is_array($records) || !self::isList($records) || count($records) > 100) {
                        throw new InvalidArgumentException('router_observation_unavailable');
                    }
                    foreach ($records as $record) {
                        if (!is_array($record) || !is_string($record['.id'] ?? null)
                            || preg_match('/^\*[a-fA-F0-9]+$/D', $record['.id']) !== 1) {
                            throw new InvalidArgumentException('router_observation_unavailable');
                        }
                        foreach ($target['filter'] as $field => $value) {
                            if (!is_string($record[$field] ?? null) || $record[$field] !== $value) {
                                throw new InvalidArgumentException('router_observation_unavailable');
                            }
                        }
                    }
                    $check['state'] = $records ? 'present' : 'absent';
                    if ($records) { $safe = false; $reason = 'router_resources_present'; }
                } catch (Throwable $error) {
                    $safe = false;
                    $reason = $error->getMessage() === 'router_observation_limit' ? 'router_observation_limit' : 'router_observation_unavailable';
                }
                $checks[] = $check;
                // Exhausted budgets are a partial observation, never absence proof.
                if ($reason === 'router_observation_limit') break;
            }
            if ($safe && !$complete) { $safe = false; $reason = 'router_snapshot_incomplete'; }
            elseif ($safe) $reason = 'observed_absent_resources';
        } catch (Throwable $error) {
            $safe = false;
            $known = ['router_snapshot_incomplete', 'router_authority_changed', 'router_observation_limit'];
            $reason = in_array($error->getMessage(), $known, true) ? $error->getMessage() : 'router_observation_unavailable';
        } finally {
            foreach ($this->connections as $api) { try { $api->disconnect(); } catch (Throwable $ignored) {} }
            $this->connections = [];
        }
        return ['safe' => $safe, 'reason' => $reason, 'checks' => $checks,
            'fingerprint' => hash('sha256', self::canonical(['targets' => $targets, 'devices' => $devices, 'checks' => $checks]))];
    }

    private function selectors($command, array $snapshot): array
    {
        if (!is_array($command) || !is_string($command['path'] ?? null)
            || !in_array($command['action'] ?? 'set', ['set', 'remove'], true)) throw new InvalidArgumentException('router_snapshot_incomplete');
        $path = trim($command['path'], '/'); $name = $command['name'] ?? null;
        $named = ['ppp/secret', 'ppp/active', 'ip/hotspot/user', 'ip/hotspot/active',
            'ppp/profile', 'ip/hotspot/user/profile', 'queue/simple', 'ipv6/pool'];
        if (in_array($path, $named, true)) {
            if (!$this->text($name)) throw new InvalidArgumentException('router_snapshot_incomplete');
            $filter = [$path === 'ip/hotspot/active' ? 'user' : 'name' => $name];
            $targets = [['path' => $path, 'filter' => $filter, 'type' => 'name']];
            if (in_array($path, ['ppp/secret', 'ppp/active', 'ip/hotspot/user', 'ip/hotspot/active'], true)) {
                if ($name !== ($snapshot['username'] ?? null)) throw new InvalidArgumentException('router_snapshot_incomplete');
                $principal = in_array($path, ['ppp/secret', 'ppp/active'], true) ? 'ppp/secret' : 'ip/hotspot/user';
                $active = $principal === 'ppp/secret' ? 'ppp/active' : 'ip/hotspot/active';
                $targets[] = ['path' => $principal, 'filter' => ['name' => $name], 'type' => 'name'];
                $targets[] = ['path' => $active, 'filter' => [$active === 'ppp/active' ? 'name' : 'user' => $name], 'type' => 'name'];
            }
            // Managed profiles can be renamed through exact ownership selectors.
            if (in_array($path, ['ppp/profile', 'ip/hotspot/user/profile'], true)
                && preg_match('/^uisp-ros-plugin:(plan|service-profile):([1-9][0-9]*)$/D', $command['_owner'] ?? '', $match)) {
                $targets[] = ['path' => $path, 'filter' => ['comment' => $command['_owner']], 'type' => 'owner'];
                $targets[] = ['path' => $path, 'filter' => ['comment' => ($match[1] === 'plan' ? 'Plan #' : 'Servicio #') . $match[2]], 'type' => 'owner'];
            }
            return $targets;
        }
        if ($path === 'ip/dhcp-server/lease') {
            $mac = $command['mac-address'] ?? null;
            if (!is_string($mac) || !filter_var($mac, FILTER_VALIDATE_MAC)
                || strcasecmp($mac, $snapshot['mac'] ?? '') !== 0) throw new InvalidArgumentException('router_snapshot_incomplete');
            return [['path' => $path, 'filter' => ['mac-address' => strtoupper($mac)], 'type' => 'mac']];
        }
        if ($path === 'ipv6/dhcp-server/binding') {
            $duid = $command['duid'] ?? null; $iaid = $command['iaid'] ?? null;
            $source = $snapshot['duid'] ?? $snapshot['mac'] ?? '';
            if (!is_string($source)) throw new InvalidArgumentException('router_snapshot_incomplete');
            $source = preg_replace('/^0x/', '', preg_replace('/[:\s-]/', '', strtolower($source)));
            $sourceIaid = $snapshot['iaid'] ?? 1;
            if (is_string($sourceIaid) && preg_match('/^0x[0-9a-f]+$/iD', $sourceIaid)) $sourceIaid = hexdec($sourceIaid);
            if (!$this->text($duid) || !preg_match('/^(?:[0-9a-f]{2}){6,}$/D', $source) || $duid !== '0x' . $source
                || !is_scalar($iaid) || !preg_match('/^[0-9]+$/D', (string) $iaid)
                || !is_numeric($sourceIaid) || (float) $sourceIaid < 0 || (float) $sourceIaid > 4294967295
                || (float) $sourceIaid !== floor((float) $sourceIaid) || (int) $iaid !== (int) $sourceIaid) {
                throw new InvalidArgumentException('router_snapshot_incomplete');
            }
            // Query the wider exact DUID identity: another IAID is protected, never inferred absent.
            return [['path' => $path, 'filter' => ['duid' => $duid], 'type' => 'duid']];
        }
        throw new InvalidArgumentException('router_snapshot_incomplete');
    }

    private function principalCommands(array $snapshot): array
    {
        $mac = $snapshot['mac'] ?? null;
        if (is_string($mac) && filter_var($mac, FILTER_VALIDATE_MAC)) return [['path' => '/ip/dhcp-server/lease', 'mac-address' => $mac]];
        $username = $snapshot['username'] ?? null;
        if (!$this->text($username) || (isset($snapshot['hotspot'])
            && !in_array($snapshot['hotspot'], [true, false, 0, 1, '0', '1'], true))) throw new InvalidArgumentException('router_snapshot_incomplete');
        return [['path' => empty($snapshot['hotspot']) ? '/ppp/secret' : '/ip/hotspot/user', 'name' => $username]];
    }

    private function device(int $id): array
    {
        if (is_link($this->dataPath) || !is_file($this->dataPath)) throw new InvalidArgumentException('router_authority_changed');
        $db = new SQLite3($this->dataPath, SQLITE3_OPEN_READONLY); $db->enableExceptions(true); $db->busyTimeout(1000);
        try {
            if ($db->querySingle("SELECT count(*) FROM sqlite_master WHERE (type='view' AND name='devices') OR (type='trigger' AND tbl_name='devices')")) {
                throw new InvalidArgumentException('router_authority_changed');
            }
            $statement = $db->prepare('SELECT * FROM devices WHERE id=? LIMIT 2'); $statement->bindValue(1, $id, SQLITE3_INTEGER);
            $result = $statement->execute(); $device = $result->fetchArray(SQLITE3_ASSOC); $extra = $result->fetchArray(SQLITE3_ASSOC);
            $result->finalize(); $statement->close();
            if (!is_array($device) || $extra !== false || self::id($device['id'] ?? null) !== $id
                || !in_array($device['type'] ?? 'mikrotik', [null, '', 'mikrotik'], true)
                || !$this->text($device['user'] ?? null) || !is_string($device['password'] ?? null)) {
                throw new InvalidArgumentException('router_authority_changed');
            }
            return $device;
        } finally { $db->close(); }
    }

    private function transport(array $device): array
    {
        $host = $device['ip'] ?? null; $port = $device['port'] ?? 8728;
        if (!$this->text($host) || !is_scalar($port) || filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false
            || !$this->text($device['user'] ?? null)) throw new InvalidArgumentException('router_authority_changed');
        $ssl = isset($device['ssl']) ? filter_var($device['ssl'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : (int) $port === 8729;
        $selfSigned = filter_var($device['allowSelfSigned'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($ssl === null || $selfSigned === null) throw new InvalidArgumentException('router_authority_changed');
        return ['ip' => $host, 'port' => (int) $port, 'ssl' => $ssl, 'allowSelfSigned' => $selfSigned, 'user' => $device['user']];
    }

    private function read(array $device, string $path, array $filter): array
    {
        if ($this->reader !== null) return ($this->reader)($device, $path, $filter);
        $id = (int) $device['id'];
        if (!isset($this->connections[$id])) {
            require_once __DIR__ . '/routeros_api.class.php';
            $api = new RouterosAPI(); $transport = $this->transport($device);
            $api->timeout = 2; $api->attempts = 1; $api->port = $transport['port'];
            $api->ssl = $transport['ssl']; $api->allow_self_signed = $transport['allowSelfSigned']; $api->debug = false;
            if (!$api->connect($transport['ip'], $transport['user'], $device['password'])) throw new InvalidArgumentException('router_observation_unavailable');
            $this->connections[$id] = $api;
        }
        $api = $this->connections[$id];
        $api->write('/' . $path . '/print', false);
        $api->write('=.proplist=.id,' . implode(',', array_keys($filter)), false);
        foreach ($filter as $key => $value) $api->write('?' . $key . '=' . $value, false);
        $api->finishSentence(); return $this->decodePrint($api->read(false), $filter);
    }

    /** The generic RouterOS parser intentionally ignores unknown words. Recovery must not. */
    private function decodePrint($words, array $filter): array
    {
        if (!is_array($words) || !self::isList($words) || !$words || count($words) > 2048
            || end($words) !== '!done' || count(array_keys($words, '!done', true)) !== 1) throw new InvalidArgumentException('router_observation_unavailable');
        if ($words === ['!done'] || $words === ['!empty', '!done']) return [];
        $fields = array_merge(['.id'], array_keys($filter)); $record = null; $rows = [];
        foreach ($words as $word) {
            if (!is_string($word) || strlen($word) > 1024) throw new InvalidArgumentException('router_observation_unavailable');
            if ($word === '!re' || $word === '!done') {
                if ($record !== null) {
                    if (array_diff($fields, array_keys($record))) throw new InvalidArgumentException('router_observation_unavailable');
                    $rows[] = $record;
                }
                $record = $word === '!re' ? [] : null; continue;
            }
            if ($record === null || !preg_match('/^=([^=]+)=(.*)$/sD', $word, $match)
                || !in_array($match[1], $fields, true) || array_key_exists($match[1], $record)) throw new InvalidArgumentException('router_observation_unavailable');
            $record[$match[1]] = $match[2];
        }
        if (!$rows) throw new InvalidArgumentException('router_observation_unavailable');
        return $rows;
    }

    private function text($value): bool { return is_string($value) && $value !== '' && strlen($value) <= 512 && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1 && preg_match('//u', $value) === 1; }
    private function label(string $value): string { return strlen($value) <= 160 ? $value : 'sha256:' . substr(hash('sha256', $value), 0, 12); }
    private function now(): float { return $this->clock === null ? microtime(true) : (float) ($this->clock)(); }
    private static function isList(array $value): bool { return $value === [] || array_keys($value) === range(0, count($value) - 1); }
    private static function id($value): ?int { if (!is_string($value) && !is_int($value)) return null; $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]); return $id === false ? null : $id; }
    private static function canonical(array $value): string { ksort($value); foreach ($value as $key => $entry) if (is_array($entry)) $value[$key] = json_decode(self::canonical($entry), true, 64, JSON_THROW_ON_ERROR); return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
}
