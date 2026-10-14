<?php

/** Stored administrative snapshots available while an address-conflict upgrade is paused. */
final class ApiRecoveryReads
{
    private array $paths;
    /** @var array<string,SQLite3|null> */
    private array $databases = [];
    private array $columns = [];
    private string $includes;

    public function __construct(string $database = 'data/data.db', string $cache = 'data/cache.db', string $jobs = 'data/jobs.db')
    {
        $this->paths = ['main' => $database, 'cache' => $cache, 'jobs' => $jobs];
        $this->includes = dirname(__DIR__) . '/includes';
    }

    /** Return the usual target data, never route through setup, provisioning or cache synchronization. */
    public function list(object $request)
    {
        $target = $request->target ?? null;
        $action = $request->action ?? 'list';
        if (($request->changeType ?? null) !== 'list' || isset($request->entity)
            || !is_string($target) || ($action !== 'list' && !($target === 'sys' && $action === 'get'))
            || !in_array($target, ['devices', 'plans', 'config', 'messages', 'lang', 'sys', 'backups', 'jobs', 'services'], true)) {
            throw new InvalidArgumentException('recovery_read_unavailable');
        }
        if (isset($request->data) && !is_object($request->data)) throw new InvalidArgumentException('request_invalid');
        switch ($target) {
            case 'devices': $result = $this->devices(); break;
            case 'plans': $result = $this->plans(); break;
            case 'config': $result = (object) $this->config(); break;
            case 'messages':
            case 'lang': $result = $this->messages($request); break;
            case 'sys': $result = $this->system(); break;
            case 'backups': $result = $this->backups(); break;
            case 'jobs': $result = $this->jobs(); break;
            case 'services': $result = $this->services($request); break;
            default: throw new InvalidArgumentException('recovery_read_unavailable');
        }
        return $this->jsonSafe($result);
    }

    public function close(): void
    {
        foreach ($this->databases as $database) if ($database !== null) $database->close();
        $this->databases = [];
        $this->columns = [];
    }

    public function __destruct() { $this->close(); }

    private function jsonSafe($value)
    {
        if (is_float($value) && !is_finite($value)) return null;
        if (is_array($value) || is_object($value)) {
            foreach ($value as $key => $item) {
                if (is_object($value)) $value->$key = $this->jsonSafe($item);
                else $value[$key] = $this->jsonSafe($item);
            }
        }
        return $value;
    }

    private function database(string $name): ?SQLite3
    {
        if (!array_key_exists($name, $this->databases)) {
            $path = $this->paths[$name];
            if (!is_file($path)) return $this->databases[$name] = null;
            $database = new SQLite3($path, SQLITE3_OPEN_READONLY);
            $database->enableExceptions(true);
            $database->busyTimeout(5000);
            $database->exec('PRAGMA query_only=ON');
            $this->databases[$name] = $database;
        }
        return $this->databases[$name];
    }

    private static function identifier(string $name): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) throw new InvalidArgumentException('recovery_read_unavailable');
        return '"' . $name . '"';
    }

    private function tableColumns(string $database, string $table): array
    {
        $key = $database . ':' . $table;
        if (!isset($this->columns[$key])) {
            $connection = $this->database($database);
            $columns = [];
            if ($connection !== null) {
                $result = $connection->query('PRAGMA table_info(' . self::identifier($table) . ')');
                try { while ($row = $result->fetchArray(SQLITE3_ASSOC)) $columns[$row['name']] = true; }
                finally { $result->finalize(); }
            }
            $this->columns[$key] = $columns;
        }
        return $this->columns[$key];
    }

    /** Select only known public columns; legacy schemas supply NULL for missing fields. */
    private function rows(string $database, string $table, array $fields, string $where = '', array $parameters = []): array
    {
        $columns = $this->tableColumns($database, $table);
        if (!$columns) return [];
        $projection = [];
        foreach ($fields as $field) {
            $identifier = self::identifier($field);
            $projection[] = isset($columns[$field]) ? $identifier : 'NULL AS ' . $identifier;
        }
        $connection = $this->database($database);
        $statement = $connection->prepare('SELECT ' . implode(',', $projection) . ' FROM ' . self::identifier($table) . $where);
        foreach ($parameters as $index => $value) {
            $statement->bindValue($index + 1, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
        }
        try {
            $result = $statement->execute();
            $rows = [];
            try { while ($row = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $row; }
            finally { $result->finalize(); }
            return $rows;
        } finally { $statement->close(); }
    }

    private function rowById(string $database, string $table, array $fields, int $id): ?array
    {
        if (!isset($this->tableColumns($database, $table)['id'])) return null;
        $rows = $this->rows($database, $table, $fields, ' WHERE id=? LIMIT 1', [$id]);
        return $rows[0] ?? null;
    }

    private function devices(): array
    {
        $fields = ['id','name','ip','port','qos','type','ssl','allowSelfSigned','user','dbname','pool','pool6','pfxLength','last','created'];
        $devices = $this->rows('main', 'devices', $fields);
        $services = $this->rows('main', 'services', ['device','status']);
        $counts = [];
        foreach ($services as $service) {
            if (!in_array((int) $service['status'], [2,5,8], true)) {
                $id = (int) $service['device'];
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }
        $config = $this->config();
        $disabled = array_fill_keys(array_filter(array_map('trim', explode(',', (string) ($config['disabled_routers'] ?? '')))), true);
        foreach ($devices as &$device) {
            $device['type'] = $device['type'] ?: 'mikrotik';
            $device['users'] = $counts[(int) $device['id']] ?? 0;
            $device['status'] = null;
            $device['disabled'] = isset($disabled[(string) $device['id']]);
        }
        unset($device);
        return $devices;
    }

    private function plans(): array
    {
        $numbers = ['ratio','uploadSpeed','downloadSpeed','uploadOverride','downloadOverride','priorityUpload','priorityDownload',
            'limitUpload','limitDownload','burstUpload','burstDownload','threshUpload','threshDownload','timeUpload','timeDownload'];
        $plans = $this->rows('main', 'plans', array_merge(['id','name','last','created','priority'], $numbers));
        $defaults = ['ratio'=>1,'priorityUpload'=>8,'priorityDownload'=>8,'limitUpload'=>0,'limitDownload'=>0,
            'burstUpload'=>0,'burstDownload'=>0,'threshUpload'=>0,'threshDownload'=>0,'timeUpload'=>1,'timeDownload'=>1];
        foreach ($plans as &$plan) {
            foreach (['priorityUpload','priorityDownload'] as $field) {
                if ($plan[$field] === null && $plan['priority'] !== null) $plan[$field] = $plan['priority'];
            }
            unset($plan['priority']);
            $issues = [];
            foreach ($numbers as $field) {
                $value = $plan[$field];
                if (!in_array($field, ['uploadSpeed','downloadSpeed'], true)
                    && is_string($value) && trim($value) === '') $value = $plan[$field] = null;
                if ($value === null) {
                    if (isset($defaults[$field])) $plan[$field] = $defaults[$field];
                    continue;
                }
                $issue = $this->numberIssue($field, $value);
                if ($issue !== null) {
                    $issues[] = $issue;
                    $plan[$field] = null;
                } else {
                    $plan[$field] = is_int($value) ? $value : (float) $value;
                }
            }
            $plan['numericIssues'] = $issues;
            // Archive state cannot be established from a stored plan snapshot.
            $plan['archive'] = null;
            foreach (['Upload','Download'] as $direction) {
                $prefix = lcfirst($direction);
                $source = $prefix . 'Override';
                $invalid = array_column($issues, 'field');
                if (!in_array($source, $invalid, true) && $plan[$source] === null) $source = $prefix . 'Speed';
                $plan['effective' . $direction . 'Speed'] = in_array($source, $invalid, true) ? null : $plan[$source];
            }
        }
        unset($plan);
        return $plans;
    }

    private function numberIssue(string $field, $value): ?array
    {
        $reason = null;
        if (!is_numeric($value)) $reason = is_string($value) && trim($value) === '' ? 'empty' : 'non_numeric';
        elseif (!is_finite((float) $value)) $reason = 'non_finite';
        else {
            $number = (float) $value;
            if (in_array($field, ['ratio','priorityUpload','priorityDownload'], true) && floor($number) !== $number) $reason = 'invalid_integer';
            $minimum = in_array($field, ['burstUpload','burstDownload'], true) ? -100 :
                (in_array($field, ['priorityUpload','priorityDownload'], true) ? 1 : 0);
            $maximum = in_array($field, ['priorityUpload','priorityDownload'], true) ? 8 :
                (in_array($field, ['limitUpload','limitDownload','threshUpload','threshDownload'], true) ? 100 : null);
            if ($reason === null && ($number < $minimum || ($maximum !== null && $number > $maximum))) $reason = 'out_of_range';
            if ($reason === null && in_array($field, ['uploadSpeed','downloadSpeed','uploadOverride','downloadOverride'], true)
                && !is_finite($number * 1000000)) $reason = 'calculation_non_finite';
        }
        if ($reason === null) return null;
        $safe = is_numeric($value) && is_finite((float) $value) ? (float) $value : 'non_numeric';
        if (is_numeric($value) && !is_finite((float) $value)) $safe = (float) $value < 0 ? '-Infinity' : 'Infinity';
        elseif (is_string($value) && trim($value) === '') $safe = 'empty';
        elseif (is_string($value) && in_array(strtolower(trim($value)), ['nan','inf','+inf','-inf','infinity','+infinity','-infinity'], true)) {
            $safe = strtolower(trim($value)) === 'nan' ? 'NaN' : (strpos(trim($value), '-') === 0 ? '-Infinity' : 'Infinity');
        }
        return ['field'=>$field,'reason'=>$reason,'value'=>$safe];
    }

    private function config(): array
    {
        $defaults = json_decode(file_get_contents($this->includes . '/conf_default.json'), true, 512, JSON_THROW_ON_ERROR);
        $fields = json_decode(file_get_contents($this->includes . '/l10n/fields_en.json'), true, 512, JSON_THROW_ON_ERROR);
        $allowed = array_fill_keys(array_keys($defaults), true);
        foreach ($fields as $key => $field) {
            if (strpos((string) $key, 'config_') === 0 && is_array($field) && is_string($field['name'] ?? null)) $allowed[$field['name']] = true;
        }
        foreach (['disabled_routers','plugin_language','last_cache','next_cache','last_net','next_sites','version','cache_version','backup_cursor',
            'pppoe_caller_attr','hs_attr','hs_enable','auto_hs_user'] as $key) $allowed[$key] = true;
        $config = $defaults;
        foreach ($this->rows('main', 'config', ['key','value']) as $row) {
            $key = $row['key'];
            if (!is_string($key) || !isset($allowed[$key]) || preg_match('/token|secret|credential|authorization|password|appkey/i', $key)) continue;
            $value = $row['value'];
            if ($value === null || $value === '') $value = null;
            elseif (in_array($key, ['router_ppp_pool','auto_ppp_user','auto_hs_user','hs_enable','disable_contention','unsuspend_date_fix','debugEnable'], true)) {
                $flag = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $value = $flag === null ? $value : $flag;
            } elseif (in_array($key, ['lease_time','dhcp_lease_time','disabled_rate','unsuspend_fix_wait','backup_cursor'], true)
                && is_numeric($value) && is_finite((float) $value)) $value = $key === 'backup_cursor' ? (int) $value : (float) $value;
            $config[$key] = $value;
        }
        // Stored identifiers such as 00123 remain strings; defaults are converted only by their key.
        foreach (['router_ppp_pool','auto_ppp_user','auto_hs_user','hs_enable','disable_contention','unsuspend_date_fix','debugEnable'] as $key) {
            if (isset($config[$key])) {
                $flag = filter_var($config[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($flag !== null) $config[$key] = $flag;
            }
        }
        foreach (array_keys($config) as $key) if (preg_match('/token|secret|credential|authorization|password|appkey/i', $key)) unset($config[$key]);
        return $config;
    }

    private function messages(object $request): array
    {
        $allowed = ['es','en','pt','fr','de','it','af','sw'];
        $language = $request->data->language ?? null;
        $config = $this->config();
        if (!is_string($language) || !in_array($language, $allowed, true)) $language = $config['plugin_language'] ?? 'system';
        if (!is_string($language) || !in_array($language, $allowed, true)) {
            $language = 'en';
            $best = 0.0;
            $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en';
            foreach (explode(',', is_string($header) ? $header : '') as $candidate) {
                if (!preg_match('/^([a-z]{2})(?:-[a-z0-9]{1,8})*(?:\s*;\s*q=(0(?:\.\d{1,3})?|1(?:\.0{1,3})?))?$/iD', trim($candidate), $match)) continue;
                $primary = strtolower($match[1]);
                $quality = isset($match[2]) ? (float) $match[2] : 1.0;
                if (in_array($primary, $allowed, true) && $quality > $best) { $language = $primary; $best = $quality; }
            }
        }
        $result = [];
        foreach (['messages','fields'] as $name) {
            $path = $this->includes . '/l10n/' . $name . '_' . $language . '.json';
            if (!is_file($path)) $path = $this->includes . '/l10n/' . $name . '_en.json';
            $result[] = json_decode(file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
        }
        return $result;
    }

    private function system(): array
    {
        $config = $this->config();
        $version = defined('MY_VERSION') ? MY_VERSION : null;
        if ($version === null) {
            $manifest = json_decode(file_get_contents(dirname(__DIR__) . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            $version = $manifest['information']['version'] ?? 'unknown';
        }
        return ['app_version'=>$version,'php_version'=>phpversion(),'last_cache'=>$config['last_cache'] ?? 'never',
            'next_cache'=>$config['next_cache'] ?? 'never'];
    }

    private function backups(): array
    {
        $directory = dirname($this->paths['main']);
        if (!is_dir($directory)) return [];
        $backups = [];
        foreach (scandir($directory) as $name) {
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if (!preg_match('/^backup-([0-6])$/D', $name, $match) || !is_file($path) || is_link($path)) continue;
            $date = filemtime($path);
            $backups[] = ['id'=>$match[1],'name'=>$name,'date'=>$date === false ? null : date('c', $date)];
        }
        require_once __DIR__ . '/api_network_cleanup.php';
        return array_merge($backups, ApiNetworkCleanup::listBackups($directory));
    }

    private function jobs(): array
    {
        $columns = $this->tableColumns('jobs', 'jobs');
        $result = [];
        if (isset($columns['id'], $columns['payload'], $columns['status'])) {
            $rows = $this->rows('jobs', 'jobs', ['id','service_id','operation','payload','version','attempts','status','error','updated','next_attempt'],
                " WHERE status IN ('pending','running') ORDER BY id");
            foreach ($rows as $row) {
                $payload = json_decode((string) $row['payload'], true);
                if (!is_array($payload)) $payload = [];
                $key = 'Q' . (int) $row['id'];
                $item = $this->jobSummary($payload);
                $item['jobId'] = $key;
                $item['serviceId'] = (int) ($row['service_id'] ?? $item['id'] ?? 0);
                $item['action'] = in_array($row['operation'], ['update','delete','queues_set'], true) ? $row['operation'] : 'update';
                $item['state'] = $row['status'];
                $item['version'] = (int) ($row['version'] ?? 0);
                $item['attempts'] = (int) ($row['attempts'] ?? 0);
                $item['last'] = $row['updated'];
                $retry = filter_var($row['next_attempt'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
                $item['nextRetry'] = $retry === false ? null : date('c', $retry);
                // Arbitrary old errors can contain credentials; publish only known transport diagnostics.
                $safeErrors = ['router_tcp_connect_failed','router_tls_connect_failed','router_login_rejected',
                    'router_login_timeout','router_login_connection_closed','router_login_protocol_error',
                    'router_login_write_failed','device connect failed'];
                $item['error'] = !$row['error'] ? null : (in_array($row['error'], $safeErrors, true)
                    ? $row['error'] : 'job_error_before_recovery');
                $result[$key] = $item;
            }
            return $result;
        }
        // Reading the original legacy file must never initialize or import a durable queue.
        $legacyPath = dirname($this->paths['jobs']) . DIRECTORY_SEPARATOR . 'queue.json';
        if (!is_file($legacyPath)) return [];
        $legacy = json_decode(file_get_contents($legacyPath), true);
        if (!is_array($legacy)) throw new InvalidArgumentException('recovery_read_unavailable');
        $sequence = 0;
        foreach ($legacy as $payload) {
            if (!is_array($payload)) continue;
            $key = 'L' . ++$sequence;
            $item = $this->jobSummary($payload);
            $item['jobId'] = $key;
            $item['serviceId'] = $item['id'] ?? null;
            $item['action'] = in_array($payload['action'] ?? null, ['update','delete','queues_set'], true) ? $payload['action'] : 'update';
            $item['state'] = 'pending';
            $item['attempts'] = 0;
            $item['last'] = null;
            $item['nextRetry'] = null;
            $item['error'] = null;
            $result[$key] = $item;
        }
        return $result;
    }

    private function jobSummary(array $payload): array
    {
        $item = [];
        foreach (['id','clientId','device','planId','status'] as $field) {
            $value = filter_var($payload[$field] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
            if ($value !== false) $item[$field] = $value;
        }
        foreach (['username','mac','company','firstName','lastName'] as $field) {
            if (is_string($payload[$field] ?? null)) $item[$field] = $payload[$field];
        }
        $item['deviceId'] = $item['device'] ?? null;
        $item['blockedBy'] = null;
        $item['blockedReason'] = 'maintenance';
        $item['canDelete'] = false;
        $item['deleteBlockedReason'] = 'maintenance';
        return $item;
    }

    private function services(object $request): array
    {
        $data = $request->data ?? new stdClass();
        $deviceValue = $data->did ?? $data->id ?? $data->device ?? 0;
        $device = is_int($deviceValue) || is_string($deviceValue)
            ? filter_var($deviceValue, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]) : false;
        if ($device === false) throw new InvalidArgumentException('invalid_device_id');
        $limitValue = $request->limit ?? 100;
        $offsetValue = $request->offset ?? 0;
        $limit = is_int($limitValue) || is_string($limitValue)
            ? filter_var($limitValue, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>500]]) : false;
        $offset = is_int($offsetValue) || is_string($offsetValue)
            ? filter_var($offsetValue, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]) : false;
        if ($limit === false || $offset === false) throw new InvalidArgumentException('invalid_pagination');
        $query = $data->query ?? null;
        if ($query !== null && !is_string($query) && !is_int($query)) throw new InvalidArgumentException('invalid_search');
        $columns = $this->tableColumns('main', 'services');
        if (!isset($columns['id'], $columns['device'])) return ['count'=>0,'data'=>[]];
        $where = ' WHERE device=?';
        $parameters = [$device];
        if (isset($columns['status'])) $where .= ' AND (status IS NULL OR status NOT IN (2,5,8))';
        if ($query !== null && $query !== '' && ctype_digit((string) $query)) {
            $where .= isset($columns['clientId']) ? ' AND (id=? OR clientId=?)' : ' AND id=?';
            $parameters[] = (string) $query;
            if (isset($columns['clientId'])) $parameters[] = (string) $query;
        }
        $where .= ' ORDER BY id';
        $fields = ['id','device','clientId','planId','status','address','prefix6','username','mac','price','last','created'];
        $cachedFields = ['id','clientId','planId','status','uploadSpeed','downloadSpeed','username','mac','duid','iaid','hotspot',
            'price','totalPrice','currencyCode','callerId'];
        $result = ['count'=>0,'data'=>[]];
        foreach ($this->rows('main', 'services', $fields, $where, $parameters) as $service) {
            $id = (int) $service['id'];
            $cached = $this->rowById('cache', 'services', $cachedFields, $id) ?? [];
            $clientId = (int) ($service['clientId'] ?? $cached['clientId'] ?? 0);
            $client = $this->rowById('cache', 'clients', ['company','firstName','lastName'], $clientId) ?? [];
            $row = array_replace($cached, $service, $client);
            foreach (['username','mac','price'] as $field) if ($row[$field] === null) $row[$field] = $cached[$field] ?? null;
            $row['clientId'] = $clientId ?: null;
            $row['desiredStatus'] = $cached['status'] ?? null;
            $network = $this->rowById('main', 'network', ['address','address6'], $id);
            $cachedNetwork = $this->rowById('cache', 'network', ['address','address6'], $id) ?? [];
            $row['address'] = $network !== null ? $network['address'] : $service['address'];
            $row['address6'] = $network !== null ? $network['address6'] : $service['prefix6'];
            $row['a4'] = $cachedNetwork['address'] ?? null;
            $row['a6'] = $cachedNetwork['address6'] ?? null;
            $row['address'] ??= $row['a4'];
            $row['address6'] ??= $row['a6'];
            unset($row['prefix6']);
            $row['username'] ??= $row['mac'];
            $row['client'] = trim((string) ($row['company'] ?? '')) ?: trim((string) ($row['firstName'] ?? '') . ' ' . (string) ($row['lastName'] ?? ''));
            $plan = $this->rowById('main', 'plans', ['name'], (int) ($service['planId'] ?? 0));
            $row['plan'] = $plan['name'] ?? null;
            $row['pricef'] = is_numeric($row['price']) && is_finite((float) $row['price']) ? sprintf('%01.2f', $row['price']) : null;
            if ($query !== null && $query !== '' && !ctype_digit((string) $query)) {
                $haystack = implode(' ', array_map(static function ($value): string { return (string) $value; },
                    [$row['firstName'] ?? '',$row['lastName'] ?? '',$row['company'] ?? '',$row['username'] ?? '',$row['mac'] ?? '']));
                if (stripos($haystack, (string) $query) === false) continue;
            }
            if ($result['count'] >= $offset && count($result['data']) < $limit) $result['data'][] = $row;
            $result['count']++;
        }
        return $result;
    }
}
