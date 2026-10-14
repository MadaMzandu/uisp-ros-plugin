<?php
// Isolated synthetic CRM transport and databases. Never accesses a live CRM.
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
function checkResolution(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejectResolution(callable $operation, string $message): void {
    try { $operation(); } catch (InvalidArgumentException $error) {
        checkResolution($error->getMessage() === $message, 'Unexpected resolution rejection: ' . $error->getMessage()); return;
    }
    throw new RuntimeException('Unsafe resolution accepted: ' . $message);
}
function resolutionService(int $id, ?string $address, int $status = 1): array {
    $attributes = [['key' => 'credential', 'customAttributeId' => 3, 'value' => 'PRIVATE-PPPOE-PASSWORD']];
    if ($address !== null) $attributes[] = ['key' => 'fixture-ip', 'customAttributeId' => 1, 'value' => $address];
    $attributes[] = ['key' => 'fixture-ipv6', 'customAttributeId' => 2, 'value' => '2001:db8:' . $id . '::/64'];
    return ['id' => $id, 'clientId' => 100 + $id, 'status' => $status, 'attributes' => $attributes, 'password' => 'PRIVATE-SERVICE-PASSWORD'];
}
function resolutionHttp(int $status): Throwable {
    return new GuzzleHttp\Exception\RequestException('PRIVATE-HTTP-ERROR',
        new GuzzleHttp\Psr7\Request('GET', 'https://resolution-fixture.invalid/api'), new GuzzleHttp\Psr7\Response($status));
}
$source = realpath(__DIR__ . '/../src');
$directory = sys_get_temp_dir() . '/uisp-crm-network-resolution-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$dataPath = $directory . '/data.db'; $statePath = $directory . '/crm-network-monitor.db';
$now = 1800000000.0;
try {
    require $source . '/vendor/autoload.php';
    require $source . '/lib/api_crm_network_resolution.php';
    require $source . '/lib/api_network_cleanup.php';
    $db = new SQLite3($dataPath);
    $db->enableExceptions(true);
    $db->exec("CREATE TABLE config(key TEXT PRIMARY KEY,value TEXT);
        INSERT INTO config VALUES('ip_addr_attr','fixture-ip'),('ip_addr6_attr','fixture-ipv6');
        CREATE TABLE network(id INTEGER PRIMARY KEY,address TEXT,address6 TEXT);
        INSERT INTO network VALUES(1,'192.0.2.99','2001:db8:1::/64'),(2,'192.0.2.98','2001:db8:2::/64'),(3,'192.0.2.97',NULL);
        CREATE TABLE services(id INTEGER PRIMARY KEY,clientId INTEGER,status INTEGER,address TEXT,prefix6 TEXT,privateData TEXT);
        INSERT INTO services VALUES(1,101,2,'192.0.2.99','2001:db8:1::/64','PRIVATE-LOCAL-PASSWORD'),
            (2,102,1,'192.0.2.98','2001:db8:2::/64','PRIVATE-LOCAL-PASSWORD'),(3,103,1,'192.0.2.97',NULL,'PRIVATE-LOCAL-PASSWORD')");
    $db->close();
    file_put_contents($directory . '/queue.json', '{}');
    file_put_contents($directory . '/cache.db', 'PRIVATE-CACHE-CONTENT');
    $catalog = [['id' => 1, 'key' => 'fixture-ip', 'attributeType' => 'service'],
        ['id' => 2, 'key' => 'fixture-ipv6', 'attributeType' => 'service'], ['id' => 3, 'key' => 'credential', 'attributeType' => 'service']];
    $services = [1 => resolutionService(1, '192.0.2.9', 2), 2 => resolutionService(2, '192.0.2.9'),
        3 => resolutionService(3, 'not-an-address'), 4 => resolutionService(4, '192.0.2.50')];
    $clients = [101 => ['id' => 101, 'firstName' => 'Archived', 'lastName' => 'Fixture', 'isArchived' => true],
        102 => ['id' => 102, 'companyName' => 'Active fixture', 'isArchived' => false], 103 => ['id' => 103, 'firstName' => 'Issue', 'isArchived' => false]];
    $calls = []; $mode = 'normal'; $patches = [];
    $clock = static function () use (&$now): float { return $now; };
    $monitor = new ApiCrmNetworkMonitor(static function ($path, $options) use (&$catalog, &$services) {
        return $path === 'custom-attributes' ? $catalog : array_slice(array_values($services), $options['offset'], $options['limit']);
    }, $dataPath, $statePath, $clock);
    $monitor->tick();
    $baseline = [hash_file('sha256', $dataPath), hash_file('sha256', $statePath), hash_file('sha256', $directory . '/cache.db'), hash_file('sha256', $directory . '/queue.json')];
    $transport = static function (string $path, string $method, array $data) use (&$calls, &$patches, &$services, &$clients, &$catalog, &$mode, $directory, $dataPath) {
        $calls[] = [$path, $method, $data];
        if ($method === 'patch') {
            $id = (int) substr($path, strlen('clients/services/'));
            checkResolution(array_keys($data) === ['attributes'] && count($data['attributes']) === 1
                && array_keys($data['attributes'][0]) === ['customAttributeId', 'value'], 'PATCH overwrote unrelated service properties or attributes');
            $field = $data['attributes'][0]['customAttributeId'] === 1 ? 'address' : 'address6';
            checkResolution(is_file($directory . '/crm-network-correction-' . $id . '-' . $field . '.json'), 'PATCH ran before daily-export correction hold was installed');
            $patches[] = [$path, $data];
            if ($mode === 'patch_timeout') throw new RuntimeException('PRIVATE-PATCH-TIMEOUT');
            $attributes = [];
            foreach ($services[$id]['attributes'] as $attribute) if ($attribute['customAttributeId'] !== $data['attributes'][0]['customAttributeId']) $attributes[] = $attribute;
            if ($data['attributes'][0]['value'] !== null) $attributes[] = ['key' => $field === 'address' ? 'fixture-ip' : 'fixture-ipv6',
                'customAttributeId' => $data['attributes'][0]['customAttributeId'], 'value' => $data['attributes'][0]['value']];
            $services[$id]['attributes'] = $attributes;
            if ($mode === 'patch_applied_reply_timeout') throw new RuntimeException('PRIVATE-REPLY-TIMEOUT');
            if ($mode === 'followup_unavailable') $mode = 'service_unavailable';
            return $services[$id];
        }
        checkResolution($method === 'get', 'Resolver invoked an unexpected HTTP method');
        if ($path === 'custom-attributes') return $catalog;
        if ($path === 'clients/services') {
            checkResolution(array_keys($data) === ['customAttributeKey', 'customAttributeValue', 'limit', 'offset']
                && $data['limit'] === 50 && $data['offset'] === 0, 'Collision search was unbounded or filtered out lifecycle statuses');
            if ($mode === 'search_failed') throw new RuntimeException('PRIVATE-SEARCH-ERROR');
            if ($mode === 'mapping_during_search') {
                $db = new SQLite3($dataPath); $db->exec("UPDATE config SET value='remapped' WHERE key='ip_addr_attr'"); $db->close();
            }
            $matches = [];
            foreach ($services as $service) {
                if ($service instanceof Throwable) continue;
                foreach ($service['attributes'] as $attribute) if ($attribute['key'] === $data['customAttributeKey'] && $attribute['value'] === $data['customAttributeValue']) $matches[] = $service;
            }
            return $mode === 'search_truncated' ? array_fill(0, 50, $services[4]) : $matches;
        }
        if (preg_match('~^clients/services/([1-9][0-9]*)$~D', $path, $match)) {
            if ($mode === 'service_unavailable') throw new RuntimeException('PRIVATE-SERVICE-ERROR');
            $service = $services[(int) $match[1]] ?? resolutionHttp(404);
            if ($service instanceof Throwable) throw $service;
            return $service;
        }
        if (preg_match('~^clients/([1-9][0-9]*)$~D', $path, $match)) return $clients[(int) $match[1]] ?? [];
        throw new RuntimeException('Unapproved synthetic CRM route');
    };
    $api = new ApiCrmNetworkResolution($transport, $dataPath, $statePath, $clock);
    $verified = $api->verify([1, 2]);
    checkResolution($verified['owners'][0]['client_name'] === 'Archived Fixture' && $verified['owners'][0]['client_archived'] === true
        && $verified['owners'][0]['service_status'] === 'Terminado' && $verified['owners'][1]['client_name'] === 'Active fixture'
        && $verified['groups'][0]['state'] === 'duplicate' && count($calls) === 5 && $verified['awaiting_full_scan'], 'Fresh selection did not identify archived/active owners and current duplicate');
    $calls = [];
    rejectResolution(fn() => $api->verify([999]), 'crm_resolution_service_not_found');
    rejectResolution(fn() => $api->verify([1, 2, 3, 4]), 'crm_resolution_service_limit');
    checkResolution(!$calls, 'Invalid selection contacted CRM');
    $preview = $api->preview(1, 'IPv4', 'remove');
    $tokenState = file_get_contents($directory . '/crm-network-resolution-preview-' . $preview['previewToken'] . '.json');
    checkResolution($preview['current_address'] === '192.0.2.9' && $preview['new_address'] === null
        && $preview['client_archived'] && !$preview['uniqueness_checked'] && !$patches
        && strpos($tokenState, 'PRIVATE-') === false && strpos($tokenState, 'attributes') === false, 'Preview wrote CRM or retained raw private service fields');
    $removed = $api->apply($preview['previewToken']);
    checkResolution($removed['applied'] && !$removed['uncertain'] && $removed['current_address_known'] && $removed['current_address'] === null
        && $removed['local_cleanup_required'] && $removed['requires_sync'] && count($patches) === 1
        && $patches[0][1]['attributes'] === [['customAttributeId' => 1, 'value' => null]], 'Explicit removal was not a confirmed targeted-attribute-only PATCH');
    rejectResolution(fn() => $api->apply($preview['previewToken']), 'invalid_crm_resolution_token');
    checkResolution([hash_file('sha256', $dataPath), hash_file('sha256', $statePath), hash_file('sha256', $directory . '/cache.db'), hash_file('sha256', $directory . '/queue.json')] === $baseline,
        'Correction changed allocation/cache/jobs/queue/automatic monitor state');
    $fresh = $api->verify([1, 2]);
    checkResolution($fresh['groups'][0]['state'] === 'resolved' && $monitor->read()['duplicate_groups'] === 1,
        'Selected recheck retained obsolete duplicate or falsely replaced the complete monitor snapshot');
    $descriptor = ['key' => 'fixture-ip', 'id' => 1];
    checkResolution(ApiCrmNetworkResolution::skipAttributeExport(1, 'address', '192.0.2.99', $directory, $descriptor, 101)
        && !ApiCrmNetworkResolution::skipAttributeExport(2, 'address', '192.0.2.98', $directory, $descriptor, 102)
        && !ApiCrmNetworkResolution::skipAttributeExport(1, 'address', '192.0.2.99', $directory, ['key' => 'other-ip', 'id' => 5], 101)
        && ApiCrmNetworkResolution::skipAttributeExport(1, 'address', '192.0.2.99', $directory, $descriptor, 888), 'Correction hold restored removed IP, affected unrelated services, or was bypassed by a stale local client identity');
    rejectResolution(fn() => $api->preview(3, 'IPv4', 'replace', '192.0.2.50'), 'crm_resolution_address_in_use');
    rejectResolution(fn() => $api->preview(3, 'IPv4', 'replace', '192.0.2.9'), 'crm_resolution_address_in_use');
    rejectResolution(fn() => $api->preview(3, 'IPv4', 'replace', '192.0.2.64/26'), 'invalid_crm_resolution_address');
    rejectResolution(fn() => $api->preview(3, 'IPv6', 'replace', '2001:db8::'), 'invalid_crm_resolution_address');
    $services[4] = resolutionService(4, '192.0.2.60/32');
    rejectResolution(fn() => $api->preview(3, 'IPv4', 'replace', '192.0.2.60'), 'crm_resolution_address_in_use');
    $mode = 'search_failed'; rejectResolution(fn() => $api->preview(3, 'IPv4', 'replace', '192.0.2.61'), 'crm_resolution_collision_check_failed');
    $mode = 'search_truncated'; rejectResolution(fn() => $api->preview(3, 'IPv4', 'replace', '192.0.2.61'), 'crm_resolution_collision_check_failed');
    $mode = 'normal';
    $fixed = $api->preview(3, 'IPv4', 'replace', '192.0.2.61/32');
    checkResolution(!$fixed['current_address_known'] && $fixed['current_address'] === null && $fixed['new_address'] === '192.0.2.61', 'Invalid desired attribute could not be corrected safely');
    $patchBefore = count($patches);
    $services[3]['clientId'] = 777;
    rejectResolution(fn() => $api->apply($fixed['previewToken']), 'crm_resolution_changed');
    $services[3]['clientId'] = 103; $services[3]['status'] = 2;
    rejectResolution(fn() => $api->apply($fixed['previewToken']), 'crm_resolution_changed');
    $services[3]['status'] = 1; $catalog[0]['id'] = 8;
    rejectResolution(fn() => $api->apply($fixed['previewToken']), 'crm_resolution_address_unknown');
    $catalog[0]['id'] = 1;
    checkResolution(count($patches) === $patchBefore, 'Changed identity/status/catalog allowed PATCH');
    $lock = fopen($directory . '/ip-sync.lock', 'c+b'); flock($lock, LOCK_EX);
    rejectResolution(fn() => $api->apply($fixed['previewToken']), 'crm_resolution_busy');
    flock($lock, LOCK_UN); fclose($lock);
    $identity = realpath($directory) . DIRECTORY_SEPARATOR . 'jobs.db'; if (PHP_OS_FAMILY === 'Windows') $identity = strtolower($identity);
    $maintenancePath = $directory . '/.uisp-queue-locks-' . hash('sha256', $identity) . '/maintenance.lock';
    $lock = fopen($maintenancePath, 'c+b'); flock($lock, LOCK_EX);
    rejectResolution(fn() => $api->apply($fixed['previewToken']), 'crm_resolution_busy');
    flock($lock, LOCK_UN); fclose($lock);
    $mode = 'mapping_during_search';
    rejectResolution(fn() => $api->apply($fixed['previewToken']), 'crm_resolution_changed');
    $mode = 'normal'; $db = new SQLite3($dataPath); $db->exec("UPDATE config SET value='fixture-ip' WHERE key='ip_addr_attr'"); $db->close();
    $mode = 'patch_timeout'; $uncertain = $api->apply($fixed['previewToken']);
    checkResolution(!$uncertain['applied'] && $uncertain['uncertain'] && ApiCrmNetworkResolution::skipAttributeExport(3, 'address', '192.0.2.97', $directory, $descriptor, 103), 'Ambiguous PATCH incorrectly reported success or lost exporter hold');
    rejectResolution(fn() => $api->apply($fixed['previewToken']), 'invalid_crm_resolution_token');
    $mode = 'normal'; $fixed = $api->preview(3, 'IPv4', 'replace', '192.0.2.61');
    $mode = 'patch_applied_reply_timeout'; $confirmed = $api->apply($fixed['previewToken']);
    checkResolution($confirmed['applied'] && !$confirmed['uncertain'], 'Post-PATCH GET did not confirm applied correction after ambiguous reply');
    $mode = 'normal';
    checkResolution(!ApiCrmNetworkResolution::skipAttributeExport(3, 'address', '192.0.2.61', $directory, $descriptor, 103)
        && !is_file($directory . '/crm-network-correction-3-address.json'), 'Aligned local reservation did not release daily-export hold');
    $changed = $api->preview(3, 'IPv4', 'replace', '192.0.2.62'); $mode = 'followup_unavailable';
    $result = $api->apply($changed['previewToken']);
    checkResolution($result['uncertain'] && !$result['current_address_known'] && !$result['applied'], 'Failed follow-up GET fabricated confirmation');
    $mode = 'normal';
    $services[1] = resolutionHttp(404);
    $missing = $api->verify([1, 2]);
    checkResolution($missing['owners'][0]['service_exists'] === false && $missing['owners'][0]['client_name'] === 'Archived Fixture'
        && $missing['groups'][0]['state'] === 'resolved', 'Exact missing service lost known archived client identity or selected resolution');
    $services[1] = resolutionHttp(403);
    $unavailable = $api->verify([1, 2]);
    checkResolution($unavailable['owners'][0]['service_exists'] === null && $unavailable['groups'][0]['state'] === 'unknown', 'Permission failure falsely became missing/resolved');
    $services[1] = resolutionService(1, '192.0.2.9', 2);
    $db = new SQLite3($dataPath); $db->exec("UPDATE network SET address='192.0.2.9' WHERE id=1; UPDATE services SET address='192.0.2.9' WHERE id=1"); $db->close();
    rejectResolution(fn() => $api->preview(2, 'IPv4', 'history'), 'crm_resolution_history_unavailable');
    $historical = $api->preview(1, 'IPv4', 'history');
    checkResolution($historical['historical'] && $historical['preserves_crm_history'] && !$historical['requires_sync']
        && $historical['current_address'] === $historical['new_address'], 'History review proposed a CRM mutation or service activation');
    $patchBefore = count($patches);
    $services[1]['status'] = 1;
    rejectResolution(fn() => $api->apply($historical['previewToken']), 'crm_resolution_changed');
    $services[1]['status'] = 2;
    $resolvedHistory = $api->apply($historical['previewToken']);
    checkResolution($resolvedHistory['applied'] && $resolvedHistory['historical'] && !$resolvedHistory['requires_sync']
        && !$resolvedHistory['crm_webhook_possible'] && count($patches) === $patchBefore
        && ApiCrmNetworkHistory::reviewed($services[1], $descriptor, 'address', $directory), 'Reviewed ended history changed CRM or did not bind the exact reviewed service');
    rejectResolution(fn() => $api->apply($historical['previewToken']), 'invalid_crm_resolution_token');
    $historicalProof = $api->verify([1, 2]);
    checkResolution($historicalProof['owners'][0]['historical_families'] === ['IPv4']
        && $historicalProof['owners'][0]['address_evidence']['address']['value'] === '192.0.2.9'
        && $historicalProof['groups'][0]['state'] === 'resolved', 'Historical proof hid the actual address or kept the reviewed ended owner actionable');
    $now += 301; $scan = $monitor->tick();
    checkResolution($scan['duplicate_groups'] === 0 && $scan['historical_assignments_count'] === 1
        && $scan['historical_assignments'][0]['service_id'] === 1 && $scan['historical_assignments'][0]['address'] === '192.0.2.9', 'Full monitor did not separate reviewed ended history from current duplicate findings');
    $services[1]['status'] = 1; $now += 301; $scan = $monitor->tick();
    checkResolution($scan['duplicate_groups'] === 1 && $scan['historical_assignments_count'] === 0, 'Reactivated service remained silently ignored');
    $services[1]['status'] = 2; $services[1]['clientId'] = 999; $now += 301; $scan = $monitor->tick();
    checkResolution($scan['duplicate_groups'] === 1 && $scan['historical_assignments_count'] === 0, 'Reassigned service retained another client historical exception');
    $services[1]['clientId'] = 101;
    foreach ($services[1]['attributes'] as &$attribute) if ($attribute['key'] === 'fixture-ip') $attribute['value'] = '192.0.2.9/32'; unset($attribute);
    $now += 301; $scan = $monitor->tick();
    checkResolution($scan['duplicate_groups'] === 1 && $scan['historical_assignments_count'] === 0, 'Changed raw attribute reused an obsolete historical approval');
    $services[1] = resolutionService(1, '192.0.2.9', 2); $now += 301; $scan = $monitor->tick();
    checkResolution($scan['historical_assignments_count'] === 1 && $api->canReviewCleanup([1]), 'Reviewed history could not be revisited after full conflict scan resolved');
    $cleanup = new ApiNetworkCleanup(static function ($path) use (&$services, &$catalog) {
        if ($path === 'custom-attributes') return $catalog;
        $id = (int) substr($path, strlen('clients/services/')); $service = $services[$id];
        if ($service instanceof Throwable) throw $service;
        return $service;
    }, $dataPath, $directory . '/jobs.db', $directory . '/queue.json', $clock);
    $historyReview = $cleanup->preview([1], true);
    checkResolution(count($historyReview['candidates']) === 1 && $historyReview['candidates'][0]['historical_reviewed']
        && $historyReview['candidates'][0]['evidence'][0]['state'] === 'historical', 'Matching ended CRM/local historical IP could not be safely reviewed for local release');
    $services[1]['status'] = 1;
    rejectResolution(fn() => $cleanup->apply($historyReview['previewToken'], [1], true), 'cleanup_verification_failed');
    $services[1]['status'] = 2;
    $historyCleaned = $cleanup->apply($historyReview['previewToken'], [1], true);
    $db = new SQLite3($dataPath);
    checkResolution($db->querySingle('SELECT address FROM network WHERE id=1') === null
        && $db->querySingle('SELECT address6 FROM network WHERE id=1') === '2001:db8:1::/64'
        && $services[1]['attributes'][1]['value'] === '192.0.2.9', 'Historical cleanup altered preserved CRM history or the opposite local family');
    $db->exec("UPDATE network SET address='192.0.2.99' WHERE id=1; UPDATE services SET address='192.0.2.99' WHERE id=1"); $db->close();
    $services[1] = resolutionService(1, null, 2);
    $blocked = $cleanup->preview([1]);
    checkResolution($blocked['previewToken'] === null && $blocked['blocked'][0]['reason'] === 'not_in_conflict', 'Legacy cleanup unexpectedly accepted nonduplicate reservation');
    $review = $cleanup->preview([1], true);
    checkResolution(count($review['candidates']) === 1 && $review['candidates'][0]['reason'] === 'stale_address'
        && $review['candidates'][0]['fields'] === ['address'] && $review['candidates'][0]['witness_service_ids'] === [], 'CRM cleanup could not review a directly proven obsolete family without a saved duplicate');
    rejectResolution(fn() => $cleanup->apply($review['previewToken'], [1], false), 'invalid_cleanup_token');
    $services[1] = resolutionService(1, '192.0.2.99', 2);
    rejectResolution(fn() => $cleanup->apply($review['previewToken'], [1], true), 'cleanup_verification_failed');
    $services[1] = resolutionService(1, null, 2);
    $cleaned = $cleanup->apply($review['previewToken'], [1], true);
    $db = new SQLite3($dataPath, SQLITE3_OPEN_READONLY);
    checkResolution($db->querySingle('SELECT address FROM network WHERE id=1') === null
        && $db->querySingle('SELECT address6 FROM network WHERE id=1') === '2001:db8:1::/64'
        && $db->querySingle('SELECT COUNT(*) FROM services WHERE id=1') === 1
        && is_file($directory . '/' . $cleaned['backup_name']), 'CRM cleanup changed current service/opposite family or omitted coherent backup');
    $db->close();
    $queue = new SQLite3($directory . '/jobs.db');
    $queue->exec("CREATE TABLE jobs(id INTEGER PRIMARY KEY,service_id INTEGER,status TEXT); CREATE TABLE service_versions(service_id INTEGER PRIMARY KEY,applied_payload TEXT,applied_identity TEXT);
        INSERT INTO service_versions VALUES(2,'PRIVATE-APPLIED-PAYLOAD',NULL)"); $queue->close();
    $services[2] = resolutionService(2, null);
    $held = $cleanup->preview([2], true);
    checkResolution($held['previewToken'] === null && $held['blocked'][0]['reason'] === 'queue_busy', 'CRM cleanup released confirmed current router ownership');
    $services[1] = resolutionService(1, '192.0.2.9', 2);
    $db = new SQLite3($dataPath); $db->exec("UPDATE network SET address='192.0.2.9' WHERE id=1"); $db->close();
    $queue = new SQLite3($directory . '/jobs.db'); $queue->exec("INSERT INTO service_versions VALUES(1,'PRIVATE-APPLIED-HISTORY-PAYLOAD',NULL)"); $queue->close();
    $historyHeld = $cleanup->preview([1], true);
    checkResolution($historyHeld['previewToken'] === null && $historyHeld['blocked'][0]['reason'] === 'queue_busy', 'Ended-history exception released applied router ownership');
    checkResolution(strpos(json_encode([$removed, $fresh, $uncertain, $review, $cleaned]), 'PRIVATE-') === false, 'Resolution responses exposed credentials, raw service payloads, or transport data');
    echo "CRM network resolution regression checks passed (fresh names/archive, targeted preview/apply, ownership/mapping/lock checks, collision evidence, ambiguous writes, exporter holds, exact ended history, independent safe local cleanup).\n";
} finally {
    $remove = static function (string $path) use (&$remove): void {
        if (is_dir($path) && !is_link($path)) { foreach (scandir($path) as $item) if ($item !== '.' && $item !== '..') $remove($path . DIRECTORY_SEPARATOR . $item); rmdir($path); }
        elseif (file_exists($path) || is_link($path)) unlink($path);
    };
    $remove($directory);
}
