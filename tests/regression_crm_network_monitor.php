<?php
// Synthetic fixtures only. No CRM, router, cache refresh, or production worker runs.
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
function checkCrmMonitor(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function monitorFixtureService(int $id, ?string $address = null, ?string $address6 = null): array {
    $attributes = [['key' => 'fixture-secret', 'value' => 'PRIVATE-ATTRIBUTE-CREDENTIAL', 'customAttributeId' => 3]];
    if ($address !== null) $attributes[] = ['key' => 'fixture-ip', 'value' => $address, 'customAttributeId' => 1];
    if ($address6 !== null) $attributes[] = ['key' => 'fixture-ipv6', 'value' => $address6, 'customAttributeId' => 2];
    return ['id' => $id, 'clientId' => $id + 1000, 'status' => $id % 9, 'attributes' => $attributes,
        'name' => 'PRIVATE-SERVICE-NAME', 'password' => 'PRIVATE-SERVICE-PASSWORD'];
}
function monitorFileHashes(string $directory): array {
    $result = [];
    foreach (['data.db', 'cache.db', 'jobs.db', 'queue.json', 'state.json'] as $name) $result[$name] = hash_file('sha256', $directory . '/' . $name);
    return $result;
}

$source = realpath(__DIR__ . '/../src');
$directory = sys_get_temp_dir() . '/uisp-crm-network-monitor-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$dataPath = $directory . '/data.db';
$statePath = $directory . '/crm-network-monitor.db';
try {
    require $source . '/lib/api_crm_network_monitor.php';
    $db = new SQLite3($dataPath);
    $db->enableExceptions(true);
    $db->exec("CREATE TABLE config(key TEXT PRIMARY KEY,value TEXT);
        INSERT INTO config VALUES('ip_addr_attr','fixture-ip'),('ip_addr6_attr','fixture-ipv6');
        CREATE TABLE network(id INTEGER PRIMARY KEY,address TEXT,address6 TEXT);
        INSERT INTO network VALUES(1,'192.0.2.77',NULL);
        CREATE TABLE services(id INTEGER PRIMARY KEY,privateData TEXT);
        INSERT INTO services VALUES(1,'PRIVATE-LOCAL-SERVICE')");
    $db->close();
    $db = new SQLite3($directory . '/cache.db');
    $db->exec("CREATE TABLE services(id INTEGER PRIMARY KEY); INSERT INTO services VALUES(999)"); $db->close();
    $db = new SQLite3($directory . '/jobs.db');
    $db->exec("CREATE TABLE jobs(id INTEGER PRIMARY KEY,status TEXT,payload TEXT); INSERT INTO jobs VALUES(1,'pending','PRIVATE-QUEUE-PAYLOAD')"); $db->close();
    file_put_contents($directory . '/queue.json', '{"PRIVATE-LEGACY-QUEUE":true}');
    file_put_contents($directory . '/state.json', '{"PRIVATE-MAIN-STATE":true}');
    $before = monitorFileHashes($directory);
    $catalog = [['id' => 1, 'key' => 'fixture-ip', 'attributeType' => 'service'], ['id' => 2, 'key' => 'fixture-ipv6', 'attributeType' => 'service'], ['id' => 3, 'key' => 'fixture-secret', 'attributeType' => 'service']];
    $services = [];
    for ($id = 1; $id <= 1001; $id++) $services[] = monitorFixtureService($id, $id <= 2 ? '192.0.2.9' : null, $id <= 2 ? '2001:db8::' . $id . '/64' : null);
    $calls = [];
    $now = 1800000000.0;
    $mode = 'normal';
    $request = static function (string $path, array $options) use (&$catalog, &$services, &$calls, &$mode, &$now, $dataPath) {
        $calls[] = [$path, $options];
        if ($mode === 'transport_error') throw new RuntimeException('SECRET-TRANSPORT-DATA');
        if ($mode === 'catalog_slow' && $path === 'custom-attributes') $now += 12.1;
        if ($path === 'custom-attributes') return $catalog;
        checkCrmMonitor($path === 'clients/services' && array_keys($options) === ['offset', 'limit'] && $options['limit'] === ApiCrmNetworkMonitor::PAGE_SIZE,
            'Monitor sent status filtering, an unexpected path, or unbounded page options');
        if ($mode === 'bad_page') return ['private' => 'PRIVATE-INVALID-PAGE'];
        if ($mode === 'oversized') return array_fill(0, ApiCrmNetworkMonitor::PAGE_SIZE + 1, monitorFixtureService(1));
        if ($mode === 'repeat_page') return array_slice($services, 0, ApiCrmNetworkMonitor::PAGE_SIZE);
        if ($mode === 'mapping_mid_page') {
            $db = new SQLite3($dataPath);
            $db->exec("UPDATE config SET value='fixture-ip-renamed' WHERE key='ip_addr_attr'"); $db->close();
        }
        return array_slice($services, $options['offset'], $options['limit']);
    };
    $monitor = new ApiCrmNetworkMonitor($request, $dataPath, $statePath, static function () use (&$now): float { return $now; });
    $initial = $monitor->read();
    checkCrmMonitor(!$initial['available'] && $calls === [] && !file_exists($statePath) && !file_exists($statePath . '.lock'), 'read() created state or invoked CRM');
    $partial = $monitor->tick();
    checkCrmMonitor(count($calls) === 3 && !$partial['available'] && $partial['scan_in_progress'] && $partial['services_scanned'] === 1000,
        'First tick exceeded request limits or exposed an incomplete snapshot');
    $calls = [];
    $first = $monitor->tick();
    checkCrmMonitor(count($calls) === 2 && $calls[1][1]['offset'] === 1000 && $first['available'] && !$first['scan_in_progress']
        && $first['duplicate_groups'] === 2 && !$first['stale'] && $first['services_scanned'] === 1001
        && $first['families'] === ['IPv4', 'IPv6'], 'Incremental full cycle did not publish canonical duplicate groups');
    checkCrmMonitor($first['conflicts'][0]['address'] === '192.0.2.9' && $first['conflicts'][1]['address'] === '2001:db8::/64'
        && $first['conflicts'][0]['owners'][0]['client_url'] === '/crm/client/1001'
        && $first['conflicts'][0]['owners'][0]['source'] === 'crm_snapshot', 'Monitor did not retain validated live owner identities');
    checkCrmMonitor(monitorFileHashes($directory) === $before, 'Monitor modified main data, cache, jobs, queue, or operational state');
    $databaseBytes = file_get_contents($statePath);
    checkCrmMonitor(strpos($databaseBytes, 'PRIVATE-') === false && strpos(json_encode($first), 'PRIVATE-') === false, 'Monitor retained raw attributes, credentials, or service names');
    $stateHash = hash_file('sha256', $statePath);
    $calls = [];
    checkCrmMonitor($monitor->read() === $first && $calls === [] && hash_file('sha256', $statePath) === $stateHash, 'Panel report performed network reads or state writes');
    $monitor->tick();
    checkCrmMonitor($calls === [], 'Monitor rescanned before its five-minute interval');
    $now += 301;
    $services = [monitorFixtureService(1, '192.0.2.9')];
    $calls = [];
    $clean = $monitor->tick();
    checkCrmMonitor($clean['available'] && $clean['duplicate_groups'] === 0 && !$clean['stale'] && count($calls) === 2,
        'Completed scan did not clear a CRM IP that was removed');
    $cleanDate = $clean['checked_at'];
    foreach (['bad_page' => 'invalid_service_page', 'oversized' => 'invalid_service_page', 'transport_error' => 'crm_unavailable'] as $fixtureMode => $reason) {
        $mode = $fixtureMode; $now += 301; $calls = [];
        $failure = $monitor->tick();
        checkCrmMonitor($failure['available'] && $failure['checked_at'] === $cleanDate && $failure['stale'] && $failure['last_error'] === $reason
            && count($calls) <= 3 && strpos(json_encode($failure), 'SECRET') === false, 'Failed scan replaced the last whole report or leaked transport details');
        $calls = []; $monitor->tick();
        checkCrmMonitor($calls === [], 'Failed scan retried repeatedly before the interval');
    }
    $mode = 'normal'; $now += 301;
    $services = [monitorFixtureService(1), monitorFixtureService(2)];
    unset($services[1]['attributes']);
    $unknown = $monitor->tick();
    checkCrmMonitor($unknown['available'] && $unknown['last_error'] === null && $unknown['checked_at'] !== $cleanDate && $unknown['stale']
        && !$unknown['coverage_complete'] && $unknown['unknown_address_count'] === 2 && $unknown['address_issues'][0]['reason'] === 'attributes_unknown',
        'Missing attributes aborted positive detection or were treated as proof of a clean scan');
    $now += 301;
    $services = [monitorFixtureService(1, 'invalid-IP')];
    $invalidAddress = $monitor->tick();
    checkCrmMonitor($invalidAddress['available'] && $invalidAddress['stale'] && $invalidAddress['unknown_address_count'] === 1
        && $invalidAddress['address_issues'][0]['reason'] === 'value_invalid' && strpos(file_get_contents($statePath), 'invalid-IP') === false,
        'Malformed address aborted monitoring, was published as a clean report, or was retained raw');
    $cleanDate = $invalidAddress['checked_at'];
    $now += 301;
    $services = [monitorFixtureService(1, '192.0.2.9'), monitorFixtureService(1, '192.0.2.9')];
    checkCrmMonitor($monitor->tick()['last_error'] === 'invalid_service_page', 'Duplicate service identities within one page were accepted');
    $now += 301; $mode = 'repeat_page';
    $services = [];
    for ($id = 1; $id <= 1001; $id++) $services[] = monitorFixtureService($id);
    $repeated = $monitor->tick();
    checkCrmMonitor($repeated['last_error'] === 'invalid_service_page' && $repeated['checked_at'] === $cleanDate, 'Repeated pagination overwrote a completed snapshot');
    $now += 301; $mode = 'catalog_slow'; $calls = [];
    $budget = $monitor->tick();
    checkCrmMonitor(count($calls) === 1 && $budget['scan_in_progress'] && $budget['checked_at'] === $cleanDate, 'Soft deadline started another CRM read after budget exhaustion');
    $mode = 'normal'; $calls = [];
    $continued = $monitor->tick();
    checkCrmMonitor(count($calls) === 3 && $continued['scan_in_progress'] && $continued['services_scanned'] === 1000
        && $continued['snapshot_services_count'] === 1,
        'Incremental scan did not distinguish current scan progress and previous snapshot count');
    // A busy monitor never waits on worker locks and makes no API reads.
    $lock = fopen($statePath . '.lock', 'c+b'); flock($lock, LOCK_EX | LOCK_NB);
    $calls = []; $locked = $monitor->tick();
    checkCrmMonitor($calls === [] && $locked['checked_at'] === $cleanDate, 'Busy monitor performed reads or discarded the report');
    flock($lock, LOCK_UN); fclose($lock);
    // Different catalog identities invalidate staging and restart at offset zero.
    $catalog[0]['id'] = 4;
    foreach ($services as &$service) foreach ($service['attributes'] as &$attribute) if ($attribute['key'] === 'fixture-ip') $attribute['customAttributeId'] = 4;
    unset($service, $attribute);
    $calls = []; $monitor->tick();
    checkCrmMonitor($calls[1][1]['offset'] === 0, 'Changed catalog mapping mixed old and new staging rows');
    $mode = 'mapping_mid_page'; $calls = [];
    $changed = $monitor->tick();
    checkCrmMonitor(!$changed['available'] && $changed['conflicts'] === [] && $changed['last_error'] === 'mapping_changed', 'Mapping changed mid-page published or exposed an old-mapping report');
    $db = new SQLite3($dataPath);
    $db->exec("UPDATE config SET value='fixture-ip' WHERE key='ip_addr_attr'; UPDATE config SET value='' WHERE key='ip_addr6_attr'"); $db->close();
    $mode = 'normal'; $services = [monitorFixtureService(1, '192.0.2.19'), monitorFixtureService(2, '192.0.2.19')];
    foreach ($services as &$service) foreach ($service['attributes'] as &$attribute) if ($attribute['key'] === 'fixture-ip') $attribute['customAttributeId'] = 4;
    unset($service, $attribute);
    $ipv4Only = $monitor->tick();
    checkCrmMonitor($ipv4Only['available'] && $ipv4Only['families'] === ['IPv4'] && $ipv4Only['duplicate_groups'] === 1,
        'Legitimate unconfigured IPv6 prevented an IPv4 scan');
    $db = new SQLite3($dataPath); $db->exec("UPDATE config SET value='unused-default-ip6-key' WHERE key='ip_addr6_attr'"); $db->close();
    $limited = $monitor->tick();
    checkCrmMonitor($limited['available'] && $limited['duplicate_groups'] === 1 && $limited['families'] === ['IPv4']
        && $limited['unavailable_families'] === ['IPv6'] && !$limited['coverage_complete'] && $limited['unknown_address_count'] === 0
        && $limited['address_issues'] === [] && !$limited['stale'],
        'Absent default IPv6 attribute disabled IPv4 monitoring or claimed full coverage');
    // Positive warnings survive failed and incomplete follow-up scans.
    $now += 301; $mode = 'transport_error';
    $positiveFailure = $monitor->tick();
    checkCrmMonitor($positiveFailure['available'] && $positiveFailure['duplicate_groups'] === 1 && $positiveFailure['stale']
        && $positiveFailure['checked_at'] === $limited['checked_at'], 'Failed scan silently cleared a known duplicate warning');
    $mode = 'normal'; $now += 301;
    $services = [];
    for ($id = 1; $id <= 55; $id++) $services[] = monitorFixtureService($id, '192.0.2.20');
    for ($group = 0; $group < 21; $group++) {
        $services[] = monitorFixtureService(100 + $group * 2, '198.51.100.' . ($group + 1));
        $services[] = monitorFixtureService(101 + $group * 2, '198.51.100.' . ($group + 1));
    }
    foreach ($services as &$service) foreach ($service['attributes'] as &$attribute) if ($attribute['key'] === 'fixture-ip') $attribute['customAttributeId'] = 4;
    unset($service, $attribute);
    $bounded = $monitor->tick();
    checkCrmMonitor($bounded['duplicate_groups'] === 22 && count($bounded['conflicts']) === 20 && $bounded['conflicts_truncated']
        && count($bounded['conflicts'][0]['owners']) === 50 && $bounded['conflicts'][0]['service_count'] === 55
        && $bounded['conflicts'][0]['services_truncated'], 'Duplicate report did not bound groups and owner counts while retaining totals');
    $now += 301;
    $services = [monitorFixtureService(1, 'SYNTHETIC-BAD-ADDRESS-PRIVATE'), monitorFixtureService(2, '192.0.2.20'),
        monitorFixtureService(300, '198.51.100.99'), monitorFixtureService(301, '198.51.100.99')];
    foreach ($services as &$service) foreach ($service['attributes'] as &$attribute) if ($attribute['key'] === 'fixture-ip') $attribute['customAttributeId'] = 4;
    unset($service, $attribute);
    $partialEvidence = $monitor->tick();
    checkCrmMonitor($partialEvidence['available'] && $partialEvidence['duplicate_groups'] === 2 && $partialEvidence['stale']
        && !$partialEvidence['coverage_complete'] && !$partialEvidence['conflicts'][0]['current_confirmed']
        && $partialEvidence['conflicts'][0]['stale'] && $partialEvidence['conflicts'][1]['current_confirmed']
        && !$partialEvidence['conflicts'][1]['stale'], 'Unknown old owner concealed a new confirmed duplicate or cleared its old warning');
    $unknownOwner = $partialEvidence['conflicts'][0]['owners'][0];
    checkCrmMonitor($unknownOwner['service_id'] === 1 && !$unknownOwner['current_address_known'] && $unknownOwner['current_address'] === null
        && $unknownOwner['saved_address'] === '192.0.2.20' && $unknownOwner['address_refresh_error'] === 'value_invalid'
        && $partialEvidence['address_issues'][0]['service_id'] === 1 && $partialEvidence['address_issues'][0]['reason'] === 'value_invalid'
        && strpos(file_get_contents($statePath), 'SYNTHETIC-BAD-ADDRESS-PRIVATE') === false,
        'Unknown family evidence was exposed as a current address, retained raw, or hidden by catalog issues');
    $now += 301;
    $services[0] = monitorFixtureService(1); // Complete attributes list explicitly clears the old address.
    $resolvedOld = $monitor->tick();
    checkCrmMonitor($resolvedOld['duplicate_groups'] === 1 && $resolvedOld['conflicts'][0]['address'] === '198.51.100.99'
        && $resolvedOld['conflicts'][0]['current_confirmed'], 'Known subsequent empty address did not resolve only its old reservation warning');
    $db = new SQLite3($dataPath); $db->exec("UPDATE config SET value='' WHERE key='ip_addr6_attr'"); $db->close();
    $services = [monitorFixtureService(300), monitorFixtureService(301)];
    $resolvedAll = $monitor->tick();
    checkCrmMonitor($resolvedAll['available'] && $resolvedAll['duplicate_groups'] === 0 && $resolvedAll['coverage_complete']
        && $resolvedAll['unknown_address_count'] === 0 && !$resolvedAll['stale'] && $resolvedAll['snapshot_services_count'] === 2,
        'Removed services or known empty families left stale duplicates in a completed snapshot');
    // Losing a previously supported catalog family retains only actual old
    // evidence as pending, rather than inventing an issue for every customer.
    $db = new SQLite3($dataPath); $db->exec("UPDATE config SET value='fixture-ipv6' WHERE key='ip_addr6_attr'"); $db->close();
    $services = [monitorFixtureService(7, '192.0.2.27', '2001:db8:7::1/64'), monitorFixtureService(8, '192.0.2.27', '2001:db8:7::2/64')];
    foreach ($services as &$service) foreach ($service['attributes'] as &$attribute) if ($attribute['key'] === 'fixture-ip') $attribute['customAttributeId'] = 4;
    unset($service, $attribute);
    $secondary = new ApiCrmNetworkMonitor($request, $dataPath, $directory . '/secondary-monitor.db', static function () use (&$now): float { return $now; });
    checkCrmMonitor($secondary->tick()['duplicate_groups'] === 2, 'Dual-family historical evidence fixture did not publish');
    $catalog = array_values(array_filter($catalog, static function (array $attribute): bool { return $attribute['id'] !== 2; }));
    $now += 301;
    $lostFamily = $secondary->tick();
    checkCrmMonitor($lostFamily['duplicate_groups'] === 2 && $lostFamily['unknown_address_count'] === 2 && $lostFamily['stale']
        && $lostFamily['conflicts'][0]['current_confirmed'] && !$lostFamily['conflicts'][1]['current_confirmed']
        && $lostFamily['address_issues'][0]['reason'] === 'mapping_unknown', 'Lost catalog family cleared old warnings or failed to retain pending per-service evidence');
    $now += 301;
    $repeatedLoss = $secondary->tick();
    checkCrmMonitor($repeatedLoss['duplicate_groups'] === 2 && $repeatedLoss['unknown_address_count'] === 2
        && $repeatedLoss['conflicts'][1]['address'] === '2001:db8:7::/64' && !$repeatedLoss['conflicts'][1]['current_confirmed'],
        'Repeated same-key catalog loss dropped previously retained pending address provenance');
    $catalog[] = ['id' => 5, 'key' => 'fixture-ip-new', 'attributeType' => 'service'];
    $db = new SQLite3($dataPath); $db->exec("UPDATE config SET value='fixture-ip-new' WHERE key='ip_addr_attr'"); $db->close();
    foreach ($services as &$service) $service['attributes'][] = ['key' => 'fixture-ip-new', 'customAttributeId' => 5, 'value' => 'INVALID-NEW-KEY-PRIVATE'];
    unset($service);
    $newKey = $secondary->tick();
    checkCrmMonitor($newKey['available'] && $newKey['duplicate_groups'] === 1 && $newKey['conflicts'][0]['family'] === 'IPv6'
        && !$newKey['conflicts'][0]['current_confirmed'] && $newKey['unknown_address_count'] === 4
        && strpos(file_get_contents($directory . '/secondary-monitor.db'), 'INVALID-NEW-KEY-PRIVATE') === false,
        'New configured key inherited the old key address or changing IPv4 discarded unaffected pending IPv6');
    // A replacement catalog identity under the same name is also distinct
    // provenance; unknown values cannot inherit the former attribute's IP.
    $services = [];
    foreach ([7, 8] as $id) $services[] = ['id' => $id, 'clientId' => $id + 1000, 'status' => 1,
        'attributes' => [['key' => 'fixture-ip-new', 'customAttributeId' => 5, 'value' => '192.0.2.35']]];
    $now += 301;
    checkCrmMonitor($secondary->tick()['duplicate_groups'] === 2, 'Replacement identity provenance fixture did not establish the current IPv4 duplicate');
    foreach ($catalog as &$attribute) if ($attribute['key'] === 'fixture-ip-new') $attribute['id'] = 6;
    unset($attribute);
    foreach ($services as &$service) $service['attributes'][0] = ['key' => 'fixture-ip-new', 'customAttributeId' => 6, 'value' => 'INVALID-REPLACED-ID-PRIVATE'];
    unset($service);
    $now += 301;
    $replacedId = $secondary->tick();
    checkCrmMonitor($replacedId['duplicate_groups'] === 1 && $replacedId['conflicts'][0]['family'] === 'IPv6'
        && !$replacedId['conflicts'][0]['current_confirmed'] && $replacedId['unknown_address_count'] === 4,
        'Same-name replacement catalog ID inherited the former attribute address');
    $db = new SQLite3($dataPath); $db->exec("UPDATE config SET value='' WHERE key IN ('ip_addr_attr','ip_addr6_attr')"); $db->close();
    $calls = []; $unmapped = $monitor->tick();
    checkCrmMonitor(!$unmapped['available'] && $calls === [], 'Unconfigured address families produced a clean report or called CRM');
    // An unrelated or malicious state database is never migrated or changed.
    $badPath = $directory . '/foreign.db';
    $db = new SQLite3($badPath); $db->exec('CREATE TABLE private_table(secret TEXT)'); $db->close();
    $hash = hash_file('sha256', $badPath);
    $foreign = new ApiCrmNetworkMonitor($request, $dataPath, $badPath);
    checkCrmMonitor($foreign->tick()['last_error'] === 'state_unavailable' && hash_file('sha256', $badPath) === $hash, 'Monitor modified an unrecognized state schema');
    echo "CRM network monitor regressions passed.\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) { if ($entry->isDir()) rmdir($entry->getPathname()); else unlink($entry->getPathname()); }
    rmdir($directory);
}
