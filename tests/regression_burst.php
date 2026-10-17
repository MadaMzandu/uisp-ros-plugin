<?php
// Exercise the production SQLite query and RouterOS command generation without network access.
require_once __DIR__ . '/../src/lib/batch.php';

set_error_handler(function ($severity, $message, $file, $line): void {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
});

final class BurstTestLog {
    public function Append($message, $level = 0): void {}
}
function MyLog() { static $log; return $log ??= new BurstTestLog(); }
function checkBurst(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-burst-regression-' . bin2hex(random_bytes(8));
mkdir($temporary);
mkdir($temporary . '/data');
$originalDirectory = getcwd();
$apiSqlite = new ApiSqlite($temporary . '/data/data.db');
$apiSqliteCache = new ApiSqlite($temporary . '/data/cache.db');

try {
    chdir($temporary);
    $apiSqlite->exec(file_get_contents(__DIR__ . '/../src/includes/schema.sql'));
    $apiSqliteCache->exec(file_get_contents(__DIR__ . '/../src/includes/cache.sql'));
    $apiSqlite->saveConfig([
        'router_ppp_pool' => true, 'disable_contention' => true,
        'lease_time' => 30, 'disabled_rate' => 0
    ]);
    $apiSqlite->insert(['id' => 1, 'name' => 'Fixture Router', 'type' => 'mikrotik',
        'ip' => '192.0.2.1', 'port' => 8728, 'pool' => null, 'pool6' => null, 'pfxLength' => 64], 'devices');

    $defaults = [
        'name' => 'Fixture Plan', 'ratio' => 1, 'uploadSpeed' => 2.5, 'downloadSpeed' => 8,
        'uploadOverride' => 3.75, 'downloadOverride' => 12.5,
        'priorityUpload' => 3, 'priorityDownload' => 7,
        'limitUpload' => 100, 'limitDownload' => 100,
        'burstUpload' => 50, 'burstDownload' => 50,
        'threshUpload' => 25, 'threshDownload' => 25, 'timeUpload' => 12, 'timeDownload' => 12
    ];
    $rows = [
        array_replace($defaults, ['id' => 1]),
        array_replace($defaults, ['id' => 2, 'uploadSpeed' => 1.25, 'downloadSpeed' => 6.5,
            'uploadOverride' => null, 'downloadOverride' => null,
            'priorityUpload' => null, 'priorityDownload' => null, 'timeUpload' => null, 'timeDownload' => null]),
        array_replace($defaults, ['id' => 3, 'burstUpload' => -100, 'burstDownload' => -100,
            'threshUpload' => 100, 'threshDownload' => 100, 'timeUpload' => 0, 'timeDownload' => 0]),
        array_replace($defaults, ['id' => 4, 'burstUpload' => 0, 'burstDownload' => 0,
            'threshUpload' => 0, 'threshDownload' => 0, 'limitUpload' => 0, 'limitDownload' => 0,
            'timeUpload' => 0, 'timeDownload' => 0]),
        array_replace($defaults, ['id' => 5, 'uploadSpeed' => 4, 'uploadOverride' => null, 'downloadOverride' => 10.25,
            'burstUpload' => 20, 'burstDownload' => 50, 'threshUpload' => 50, 'threshDownload' => 25,
            'limitUpload' => 100, 'limitDownload' => 25, 'timeUpload' => 1.5, 'timeDownload' => 12]),
        array_replace($defaults, ['id' => 6, 'uploadOverride' => 0, 'downloadOverride' => null])
    ];
    $apiSqlite->insert($rows, 'plans');

    // Invoke Batch's real SELECT: duplicating the SQL here would miss the original override bug.
    $findPlans = new ReflectionMethod(Batch::class, 'find_plans');
    $plans = $findPlans->invoke(new Batch());
    checkBurst(count($plans) === 6, 'The production plan query lost a fixture');
    checkBurst((float)$plans[1]['uploadSpeed'] === 3.75 && (float)$plans[1]['downloadSpeed'] === 12.5,
        'Effective nominal rates ignored decimal overrides');
    checkBurst((float)$plans[1]['burstUpload'] === 5625000.0 && (float)$plans[1]['burstDownload'] === 18750000.0,
        'Burst +50% was not calculated from the effective rates');
    checkBurst((float)$plans[1]['threshUpload'] === 2812500.0 && (float)$plans[1]['threshDownload'] === 9375000.0,
        'Threshold reduction 25% was not calculated from the effective rates');
    checkBurst((float)$plans[1]['timeUpload'] === 12.0 && (float)$plans[1]['timeDownload'] === 12.0,
        'The 12-second burst window changed');
    checkBurst((float)$plans[2]['uploadSpeed'] === 1.25 && (float)$plans[2]['downloadSpeed'] === 6.5,
        'NULL overrides did not fall back to UISP rates');
    checkBurst((float)$plans[2]['timeUpload'] === 1.0 && (int)$plans[2]['priorityUpload'] === 8,
        'Existing NULL window/priority defaults changed');
    checkBurst((float)$plans[6]['uploadSpeed'] === 0.0 && (float)$plans[6]['downloadSpeed'] === 8.0,
        'A zero override must remain distinct from a NULL override');

    $expected = [
        1 => ['rate' => '3.75M/12.5M', 'burst' => '5625000/18750000', 'threshold' => '2812500/9375000',
            'time' => '12/12', 'minimum' => '0/0', 'priority' => '3/7', 'profilePriority' => '3'],
        2 => ['rate' => '1.25M/6.5M', 'burst' => '1875000/9750000', 'threshold' => '937500/4875000',
            'time' => '1/1', 'minimum' => '0/0', 'priority' => '8/8', 'profilePriority' => '8'],
        3 => ['rate' => '3.75M/12.5M', 'burst' => '0/0', 'threshold' => '0/0',
            'time' => '0/0', 'minimum' => '0/0', 'priority' => '3/7', 'profilePriority' => '3'],
        4 => ['rate' => '3.75M/12.5M', 'burst' => '0/0', 'threshold' => '0/0',
            'time' => '0/0', 'minimum' => '0/0', 'priority' => '3/7', 'profilePriority' => '3'],
        5 => ['rate' => '4M/10.25M', 'burst' => '4800000/15375000', 'threshold' => '2000000/7687500',
            'time' => '1.5/12', 'minimum' => '0/7687500', 'priority' => '3/7', 'profilePriority' => '3'],
        6 => ['rate' => '0/8M', 'burst' => '0/12000000', 'threshold' => '0/6000000',
            'time' => '12/12', 'minimum' => '0/0', 'priority' => '3/7', 'profilePriority' => '3']
    ];
    foreach ($plans as $id => $plan) {
        $service = [
            'id' => 100 + $id, 'clientId' => 7, 'device' => 1, 'planId' => $id, 'status' => 1,
            'company' => 'Fixture ISP', 'firstName' => '', 'lastName' => '',
            'mac' => sprintf('02:00:00:00:00:%02x', $id), 'address' => '192.0.2.' . (100 + $id),
            'batch' => 'fixture-' . $id
        ];
        $commands = (new MtData())->get_data($service, $plan);
        $queue = $commands['queues'];
        checkBurst($queue['path'] === '/queue/simple', 'DHCP service did not generate a simple queue');
        foreach (['max-limit' => 'rate', 'burst-limit' => 'burst', 'burst-threshold' => 'threshold',
            'burst-time' => 'time', 'limit-at' => 'minimum', 'priority' => 'priority'] as $field => $key) {
            checkBurst($queue[$field] === $expected[$id][$key], "Plan $id: incorrect upload/download pair for $field");
        }
        unset($service['mac']);
        $service['username'] = 'ppp-fixture-' . $id;
        $service['password'] = 'fixture-only';
        $service['id'] += 100;
        $service['address'] = '192.0.2.' . (200 + $id);
        $ppp = (new MtData())->get_data($service, $plan);
        checkBurst($ppp['profiles']['path'] === '/ppp/profile', 'PPP service did not generate a PPP profile');
        $profileLimit = implode(' ', [$expected[$id]['rate'], $expected[$id]['burst'], $expected[$id]['threshold'],
            $expected[$id]['time'], $expected[$id]['profilePriority'], $expected[$id]['minimum']]);
        checkBurst($ppp['profiles']['rate-limit'] === $profileLimit, "Plan $id: PPP profile rate-limit uses inconsistent rates or direction order");
    }
    // Provisioning must not replace the original UISP speeds exposed by the list API.
    $stored = $apiSqlite->singleQuery('SELECT uploadSpeed,downloadSpeed,uploadOverride,downloadOverride FROM plans WHERE id=1', true);
    checkBurst((float)$stored['uploadSpeed'] === 2.5 && (float)$stored['downloadSpeed'] === 8.0,
        'Provisioning overwrote the stored UISP base rates');
    checkBurst((float)$stored['uploadOverride'] === 3.75 && (float)$stored['downloadOverride'] === 12.5,
        'Provisioning changed the stored overrides');
    echo "Burst regression checks passed: real SQLite query, decimal overrides, NULL/zero fallbacks, sentinels, RouterOS pairs and PPP profiles.\n";
} finally {
    $apiSqlite->close();
    $apiSqliteCache->close();
    chdir($originalDirectory);
    $resolved = realpath($temporary);
    $temporaryRoot = realpath(sys_get_temp_dir());
    if ($resolved && $temporaryRoot && dirname($resolved) === $temporaryRoot
        && strpos(basename($resolved), 'uisp-burst-regression-') === 0) {
        foreach (glob($resolved . '/data/*') as $file) unlink($file);
        rmdir($resolved . '/data');
        rmdir($resolved);
    }
    restore_error_handler();
}
