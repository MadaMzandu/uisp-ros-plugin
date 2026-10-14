<?php
// Only synthetic UISP responses and real local read-only pool validation; no router or remote network.
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line); return false;
});
function policyCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function policyError(callable $call, string $expected): void {
    try { $call(); } catch (Throwable $failure) { policyCheck($failure->getMessage() === $expected, 'Wrong policy rejection: ' . $failure->getMessage()); return; }
    throw new RuntimeException('Policy accepted ' . $expected);
}
$original = getcwd(); $include = get_include_path(); $source = realpath(__DIR__ . '/../src');
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-site-policy-' . bin2hex(random_bytes(8));
mkdir($directory, 0700); mkdir($directory . '/data', 0700); chdir($directory); set_include_path($source . '/lib' . PATH_SEPARATOR . $include);
require_once $source . '/lib/api_common.php'; require_once $source . '/lib/api_sqlite.php';
require_once $source . '/lib/api_ip.php'; require_once $source . '/lib/api_site_address_policy.php';
try {
    $schema = file_get_contents($source . '/includes/schema.sql');
    $apiSqlite = new ApiSqlite('data/data.db'); $apiSqlite->exec($schema);
    $apiSqliteCache = new ApiSqlite('data/cache.db'); $apiSqliteCache->exec($schema);
    $apiSqlite->saveConfig(['device_name_attr' => 'site', 'pppoe_user_attr' => 'account', 'ip_addr_attr' => 'ip', 'ip_addr6_attr' => 'ip6', 'router_ppp_pool' => true]);
    foreach ([1 => ['Old Site','192.0.2.0/24','2001:db8:1::/48'], 2 => ['New Site','198.51.100.0/24','2001:db8:2::/48'],
        3 => ['Third Site','203.0.113.0/24','2001:db8:3::/48']] as $id => [$name,$pool,$pool6]) {
        $apiSqlite->insert(['id' => $id, 'name' => $name, 'ip' => '192.0.2.' . $id, 'pool' => $pool, 'pool6' => $pool6, 'pfxLength' => 64,
            'user' => 'PRIVATE-ROUTER-USER', 'password' => 'PRIVATE-ROUTER-PASSWORD'], 'devices');
    }
    $apiSqlite->insert(['id' => 42, 'device' => 1, 'clientId' => 17, 'status' => 1], 'services');
    $apiSqlite->insert(['id' => 42, 'address' => '192.0.2.42', 'address6' => '2001:db8:1:42::/64'], 'network');
    $catalog = [['id' => 1,'key' => 'site','attributeType' => 'service'], ['id' => 2,'key' => 'account','attributeType' => 'service'],
        ['id' => 3,'key' => 'ip','attributeType' => 'service'], ['id' => 4,'key' => 'ip6','attributeType' => 'service']];
    $fresh = static function (int $id, string $site, ?string $ip, ?string $ip6 = null): array {
        $attributes = [['key' => 'site','customAttributeId' => 1,'value' => $site], ['key' => 'account','customAttributeId' => 2,'value' => 'fixture-account']];
        if ($ip !== null) $attributes[] = ['key' => 'ip','customAttributeId' => 3,'value' => $ip];
        if ($ip6 !== null) $attributes[] = ['key' => 'ip6','customAttributeId' => 4,'value' => $ip6];
        return ['id' => $id,'clientId' => 17,'status' => 1,'attributes' => $attributes];
    };
    $current = $fresh(42, 'New Site', '192.0.2.42'); $calls = []; $patches = []; $writeMode = 'success'; $readbackUnknown = false; $unknownAfter = 0; $beforeSecondGet = null; $reads = 0;
    $request = static function ($path, $method, $data) use (&$current,&$calls,&$patches,&$catalog,&$writeMode,&$readbackUnknown,&$unknownAfter,&$beforeSecondGet,&$reads) {
        $calls[] = [$path,$method];
        if ($path === 'custom-attributes') return $catalog;
        if ($path === 'clients/17') return ['id' => 17,'isArchived' => false];
        if ($method === 'get') {
            $reads++;
            if ($reads === 2 && $beforeSecondGet !== null) $beforeSecondGet();
            if ($readbackUnknown && count($patches) > $unknownAfter) throw new RuntimeException('fixture lost readback');
            return $current;
        }
        $patches[] = ['path' => $path,'data' => $data];
        if ($writeMode === 'rejected') throw new class extends RuntimeException {
            public function hasResponse() { return true; }
            public function getResponse() { return new class { public function getStatusCode() { return 422; } }; }
        };
        if ($writeMode !== 'nochange') {
            foreach ($data['attributes'] as $change) foreach ($current['attributes'] as &$attribute) if ($attribute['customAttributeId'] === $change['customAttributeId']) $attribute['value'] = null;
            unset($attribute);
        }
        return ['id' => $current['id']];
    };
    $preflights = 0;
    $preflight = static function ($cleared, $fields, $device) use (&$preflights): void {
        $preflights++; $configuration = mySqlite()->readConfig();
        foreach ($fields as $field) {
            $allocator = new ApiIP(true, true);
            $value = $allocator->assign($cleared['id'], $device, $field === 'address6', $configuration, $cleared['_network_reset'][$field]);
            if ($value === null) throw new RuntimeException('fixture pool full');
        }
    };
    $service = ['id' => 42,'clientId' => 17,'device' => 2,'status' => 1,'username' => 'fixture-account', 'network' => ['address' => '192.0.2.42'], 'planId' => 1];
    $call = static fn(array $value): array => ApiSiteAddressPolicy::reconcile($value, null, $request, 'data/data.db', $preflight);
    $mainHash = hash_file('sha256', 'data/data.db'); $cacheHash = hash_file('sha256', 'data/cache.db');

    // Only known foreign-Site ranges qualify; arbitrary manual addresses remain administrator choices.
    foreach (['198.51.100.42','10.44.0.42'] as $address) {
        $manual = $service; $manual['network']['address'] = $address;
        policyCheck($call($manual) === $manual && !$calls, 'A current/manual address triggered external policy writes');
    }
    $apiSqlite->saveConfig(['router_ppp_pool' => false,'ppp_pool' => '192.0.2.0/24']);
    policyCheck($call($service) === $service && !$calls, 'A valid configured global PPP pool was mistaken for another Site');
    $apiSqlite->saveConfig(['ppp_pool' => '192.0.2.0/24,2001:db8:1::/48']);
    $mixedGlobal = $service; $mixedGlobal['network']['address6'] = '2001:db8:1:42::/64';
    policyCheck($call($mixedGlobal) === $mixedGlobal && !$calls, 'A mixed global PPP pool lost its effective IPv4 or IPv6 ranges');
    $apiSqlite->saveConfig(['ppp_pool' => '2001:db8:1::/48,192.0.2.0/24']);
    policyCheck($call($mixedGlobal) === $mixedGlobal && !$calls, 'Mixed global PPP pool order caused an unnecessary CRM clearing');
    $apiSqlite->saveConfig(['ppp_pool' => '10.55.0.0/24']);
    policyCheck($call($service) === $service && !$calls, 'An inactive per-router pool was treated as a foreign Site in shared global mode');
    $apiSqlite->saveConfig(['router_ppp_pool' => true]); $mainHash = hash_file('sha256', 'data/data.db');

    $result = $call($service);
    policyCheck($result['network']['address'] === null && $result['_network_reset']['address']['previous'] === '192.0.2.42'
        && preg_match('/^[a-f0-9]{32}$/D', $result['_network_reset']['address']['generation']) === 1 && $preflights === 1,
        'A verified move did not produce a fresh allocation intent');
    policyCheck($patches === [['path' => 'clients/services/42','data' => ['attributes' => [['customAttributeId' => 3,'value' => null]]]]],
        'Policy changed attributes beyond the selected service IP');
    policyCheck(hash_file('sha256','data/data.db') === $mainHash && hash_file('sha256','data/cache.db') === $cacheHash
        && !is_file('data/jobs.db'), 'Policy changed local reservations/cache or initialized a hidden queue');
    $again = $call($service);
    policyCheck(count($patches) === 1 && $again['_network_reset'] === $result['_network_reset'], 'Confirmed readback replayed a PATCH or changed its reset generation');

    // A later legitimate second move obtains a new decision, despite the previous confirmed receipt.
    $current = $fresh(42, 'Third Site', '198.51.100.42'); $second = $service; $second['device'] = 3; $second['network']['address'] = '198.51.100.42';
    $secondResult = $call($second);
    policyCheck(count($patches) === 2 && $secondResult['_network_reset']['address']['generation'] !== $result['_network_reset']['address']['generation'],
        'A confirmed old move permanently blocked a later legitimate Site move');

    // Refuse stale identity, Site, username and changed address before any remote write.
    $basePatches = count($patches);
    $current = $fresh(43, 'New Site', '192.0.2.43'); $foreign = $service; $foreign['id'] = 43; $foreign['network']['address'] = '192.0.2.43';
    $current['clientId'] = 18; policyError(static fn() => $call($foreign), 'site_address_identity_changed');
    $current = $fresh(43, 'Old Site', '192.0.2.43'); policyError(static fn() => $call($foreign), 'site_address_site_changed');
    $current = $fresh(43, 'New Site', '192.0.2.43'); $current['attributes'][1]['value'] = 'different-account'; policyError(static fn() => $call($foreign), 'site_address_identity_changed');
    $current = $fresh(43, 'New Site', '198.51.100.43'); policyError(static fn() => $call($foreign), 'site_address_changed');
    policyCheck(count($patches) === $basePatches, 'A changed identity/Site/address was cleared from a stale snapshot');

    // Missing, invalid and exhausted destination pools fail before clearing CRM.
    foreach ([null,'not-a-pool','198.51.100.0/32'] as $pool) {
        $apiSqlite->edit(['id' => 2,'pool' => $pool], 'devices'); $current = $fresh(43, 'New Site', '192.0.2.43');
        policyError(static fn() => $call($foreign), 'site_address_pool_unavailable');
    }
    $apiSqlite->edit(['id' => 2,'pool' => '198.51.100.0/24'], 'devices');
    policyCheck(count($patches) === $basePatches, 'Unavailable destination pool caused destructive CRM clearing');

    // IPv6 follows its exact configured prefix family and clears no IPv4 field.
    $current = $fresh(44, 'New Site', '198.51.100.44', '2001:db8:1:44::/64');
    $six = $service; $six['id'] = 44; $six['network'] = ['address' => '198.51.100.44','address6' => '2001:db8:1:44::/64'];
    $sixResult = $call($six);
    policyCheck($sixResult['network']['address'] === '198.51.100.44' && $sixResult['network']['address6'] === null
        && end($patches)['data']['attributes'] === [['customAttributeId' => 4,'value' => null]], 'IPv6 policy changed IPv4 or used the wrong attribute');

    // Uncertain writes keep their receipt; later known-clear readback resolves without a second PATCH.
    $current = $fresh(45, 'New Site', '192.0.2.45'); $uncertain = $service; $uncertain['id'] = 45; $uncertain['network']['address'] = '192.0.2.45';
    $readbackUnknown = true; $patchesBefore = count($patches); $unknownAfter = $patchesBefore;
    policyError(static fn() => $call($uncertain), 'site_address_write_unconfirmed');
    $readbackUnknown = false; $recovered = $call($uncertain);
    policyCheck(count($patches) === $patchesBefore + 1 && $recovered['network']['address'] === null, 'Later confirmed clearing could not recover without replay');
    $current = $fresh(47, 'New Site', '192.0.2.47'); $uncertainOld = $service; $uncertainOld['id'] = 47; $uncertainOld['network']['address'] = '192.0.2.47';
    $writeMode = 'nochange'; $patchesBefore = count($patches);
    policyError(static fn() => $call($uncertainOld), 'site_address_write_unconfirmed');
    policyError(static fn() => $call($uncertainOld), 'site_address_write_unconfirmed');
    policyCheck(count($patches) === $patchesBefore + 1, 'An unconfirmed attempt blindly repeated its CRM PATCH');
    $writeMode = 'rejected'; $current = $fresh(46, 'New Site', '192.0.2.46'); $rejected = $service; $rejected['id'] = 46; $rejected['network']['address'] = '192.0.2.46';
    policyError(static fn() => $call($rejected), 'site_address_write_rejected');
    $private = implode('', array_map('file_get_contents', glob('data/site-address-policy-*.json')));
    policyCheck(strpos($private,'PRIVATE-') === false && strpos($private,'password') === false, 'Policy receipts contain customer/router credentials');
    echo "Site address policy passed: numeric IPv4/IPv6 Site pools, valid global/manual exceptions, fresh UISP ownership/Site/principal checks, exact IP-only clearing, real read-only replacement preflight, stable/new-move receipts and safe ambiguous/rejected writes.\n";
} finally {
    if (isset($apiSqlite)) $apiSqlite->close(); if (isset($apiSqliteCache)) $apiSqliteCache->close(); chdir($original); set_include_path($include);
    $resolved = realpath($directory); $temporaryRoot = realpath(sys_get_temp_dir());
    if ($resolved && $temporaryRoot && strpos($resolved, $temporaryRoot . DIRECTORY_SEPARATOR . 'uisp-site-policy-') === 0) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); rmdir($resolved);
    }
}
