<?php
// The real exporter and queue run against isolated SQLite and an in-process UISP fixture.
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
function exportCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$original = getcwd(); $source = realpath(__DIR__ . '/../src');
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-ip-export-' . bin2hex(random_bytes(8));
mkdir($directory, 0700); foreach (['data','lib','vendor'] as $folder) mkdir($directory . '/' . $folder, 0700);
foreach (glob($source . '/lib/*.php') as $file) copy($file, $directory . '/lib/' . basename($file));
file_put_contents($directory . '/vendor/autoload.php', <<<'PHP'
<?php
namespace Ubnt\UcrmPluginSdk\Service;
class UcrmApi {
    public static function create($token = null) { return new static(); }
    public function __call($method, $arguments) { return ($GLOBALS['exportFixture'])($method, $arguments[0], $arguments[1] ?? []); }
}
class UnmsApi extends UcrmApi {}
PHP
);
chdir($directory); set_include_path($directory . '/lib' . PATH_SEPARATOR . get_include_path());
require_once 'lib/api_common.php'; require_once 'lib/api_sqlite.php'; require_once 'lib/api_job_queue.php';
try {
    $apiSqlite = new ApiSqlite('data/data.db'); $apiSqlite->exec(file_get_contents($source . '/includes/schema.sql'));
    $apiSqlite->saveConfig(['ip_addr_attr' => 'fixtureIp', 'device_name_attr' => 'fixtureSite', 'pppoe_user_attr' => 'fixtureUser']);
    $apiSqlite->insert(['id' => 1, 'name' => 'Fixture Site', 'ip' => '192.0.2.1'], 'devices');
    file_put_contents('data/config.json', json_encode(['syncAttrHour' => '']));
    require_once 'lib/api_ip_mgmt.php';
    $queue = new ApiJobQueue('data/jobs.db', null); $patches = []; $gets = []; $responses = []; $beforeGet = null; $patchThrows = false;
    $catalog = [['id' => 5, 'key' => 'fixtureIp', 'attributeType' => 'service'],
        ['id' => 6, 'key' => 'fixtureSite', 'attributeType' => 'service'], ['id' => 7, 'key' => 'fixtureUser', 'attributeType' => 'service']];
    $exportFixture = static function ($method, $path, $data) use (&$patches, &$gets, &$responses, &$catalog, &$beforeGet, &$patchThrows) {
        if ($method === 'get' && $path === 'custom-attributes') return $catalog;
        if ($method === 'get' && preg_match('~^clients/services/([1-9][0-9]*)$~D', $path, $match)) {
            $id = (int) $match[1]; $gets[] = $id;
            if ($beforeGet !== null) { $operation = $beforeGet; $beforeGet = null; $operation($id); }
            $value = $responses[$id] ?? null;
            if ($value instanceof Throwable) throw $value;
            return $value;
        }
        if ($method === 'patch') {
            $patches[] = ['path' => $path, 'data' => $data];
            if ($patchThrows) throw new RuntimeException('fixture ambiguous network response');
            return ['id' => (int) substr($path, strrpos($path, '/') + 1)];
        }
        throw new RuntimeException('Unexpected fixture request');
    };
    $service = static function (int $id, $value, bool $attributes = true): array {
        $record = ['id' => $id, 'clientId' => 17, 'status' => 1];
        if ($attributes) {
            $record['attributes'] = [['key' => 'fixtureSite', 'customAttributeId' => 6, 'value' => 'Fixture Site'],
                ['key' => 'fixtureUser', 'customAttributeId' => 7, 'value' => 'fixture-account-' . $id]];
            if ($value !== null) $record['attributes'][] = ['key' => 'fixtureIp', 'customAttributeId' => 5, 'value' => $value];
        }
        return $record;
    };
    $snapshot = static function (int $id, string $address, ?array $proof): array {
        $record = ['id' => $id, 'clientId' => 17, 'device' => 1, 'status' => 1, 'username' => 'fixture-account-' . $id,
            'network' => ['address' => null], '_event_id' => bin2hex(random_bytes(16)),
            '_device' => ['id' => 1, 'ip' => '192.0.2.1', 'user' => 'PRIVATE-ROUTER-USER', 'password' => 'PRIVATE-ROUTER-PASSWORD'],
            '_commands' => [['path' => '/ppp/secret', 'name' => 'fixture-account-' . $id, 'remote-address' => $address,
                'comment' => '17 - Fixture - ' . $id, '_service_id' => $id, '_client_id' => 17, 'password' => 'PRIVATE-ACCOUNT-PASSWORD']]];
        if ($proof !== null) $record['_pool_allocations'] = ['address' => $proof];
        return $record;
    };
    $seed = static function (int $id, string $address, ?array $proof) use ($apiSqlite, $queue, $snapshot): string {
        $apiSqlite->insert(['id' => $id, 'clientId' => 17, 'device' => 1, 'status' => 1], 'services', INSERT_REPLACE);
        $apiSqlite->insert(['id' => $id, 'address' => $address], 'network', INSERT_REPLACE);
        $key = $queue->enqueue($snapshot($id, $address, $proof));
        $queue->run([$key], static fn(): bool => true);
        exportCheck($queue->completed([$key]), 'Fixture did not publish its applied allocation');
        return $key;
    };
    $run = static function () use ($apiSqlite): void {
        $apiSqlite->saveConfig(['sync_attr_date' => null]);
        file_put_contents('data/config.json', json_encode(['syncAttrHour' => (int) gmdate('G'), 'syncTimezone' => 'UTC']));
        sync_ip_attributes();
    };
    $proof = static fn(string $value, ?string $previous = null): array => ['value' => $value, 'previous' => $previous, 'generation' => bin2hex(random_bytes(16))];

    // Legacy reservations cannot restore manually cleared or replaced UISP values.
    $seed(42, '192.0.2.42', null); $responses[42] = $service(42, null); $run();
    exportCheck(!$patches && $gets === [42], 'A legacy local IP restored an explicitly empty UISP field or skipped the fresh GET');
    $responses[42] = $service(42, '192.0.2.99'); $run();
    exportCheck(!$patches, 'A local reservation overwrote a newer explicit UISP address');
    $responses[42] = $service(42, null, false); $run();
    exportCheck(!$patches && ($apiSqlite->readConfig()->sync_attr_date ?? null) === null, 'Unknown UISP attributes were treated as writable emptiness');
    $responses[42] = array_replace($service(42, null), ['clientId' => 18]); $run();
    exportCheck(!$patches, 'A stale local client overwrote another current owner');
    $responses[42] = array_replace($service(42, null), ['status' => 2]); $run();
    exportCheck(!$patches, 'An ended UISP service received an automatic address update');

    // Actual completed new allocation can populate the empty attribute once.
    $seed(42, '192.0.2.43', $proof('192.0.2.43', '192.0.2.42')); $responses[42] = $service(42, null);
    $responses[42]['attributes'][0]['value'] = 'Another Site'; $run();
    exportCheck(!$patches, 'An allocation from the former router overwrote UISP after a Site change');
    $responses[42] = $service(42, null); $responses[42]['attributes'][1]['value'] = 'changed-account'; $run();
    exportCheck(!$patches, 'An allocation from the former account overwrote UISP after a username change');
    $responses[42] = $service(42, null);
    $queueHash = hash_file('sha256', 'data/jobs.db'); $run();
    exportCheck(count($patches) === 1 && $patches[0] === ['path' => 'clients/services/42',
        'data' => ['attributes' => [['customAttributeId' => 5, 'value' => '192.0.2.43']]]], 'A confirmed pool replacement did not export the new address exclusively');
    exportCheck(hash_file('sha256', 'data/jobs.db') === $queueHash, 'The exporter initialized, recovered or changed the job queue');
    exportCheck(is_file('data/ip-attribute-export-42-address.json'), 'Export lacked a durable allocation receipt');
    $run(); exportCheck(count($patches) === 1, 'A later clear replayed an already exported allocation');

    // A fresh explicit value retires an unused export right, including a subsequent clear.
    $seed(42, '192.0.2.44', $proof('192.0.2.44', '192.0.2.43')); $responses[42] = $service(42, '192.0.2.98'); $run();
    $responses[42] = $service(42, null); $run();
    exportCheck(count($patches) === 1, 'An allocation superseded by an explicit UISP address was exported after its later clear');

    // A second fresh read immediately before PATCH detects edits made after the first GET.
    $seed(42, '192.0.2.144', $proof('192.0.2.144', '192.0.2.44')); $responses[42] = $service(42, null);
    $beforeGet = static function () use (&$beforeGet, &$responses, $service): void {
        $beforeGet = static function () use (&$responses, $service): void { $responses[42] = $service(42, '192.0.2.97'); };
    };
    $run(); $responses[42] = $service(42, null); $run();
    exportCheck(count($patches) === 1, 'A change between the two GETs was overwritten or its superseded proof replayed');
    $seed(42, '192.0.2.145', $proof('192.0.2.145', '192.0.2.144')); $responses[42] = $service(42, null);
    $beforeGet = static function () use (&$beforeGet, &$responses): void {
        $beforeGet = static function () use (&$responses): void { $responses[42]['attributes'][0]['value'] = 'Another Site'; };
    };
    $run(); exportCheck(count($patches) === 1, 'A Site move between the two GETs received an allocation from its former router');
    $responses[42] = $service(42, null);

    // Newer pending work and a changed local row invalidate old allocation evidence.
    $seed(42, '192.0.2.45', $proof('192.0.2.45', '192.0.2.44'));
    $pending = $queue->enqueue($snapshot(42, '192.0.2.46', $proof('192.0.2.46', '192.0.2.45'))); $run();
    exportCheck(count($patches) === 1, 'Pending replacement work let an earlier allocation overwrite UISP');
    $queue->run([$pending], static fn(): bool => true);
    $apiSqlite->insert(['id' => 42, 'address' => '192.0.2.46'], 'network', INSERT_REPLACE);
    $beforeGet = static function () use ($apiSqlite): void { $apiSqlite->insert(['id' => 42, 'address' => '192.0.2.47'], 'network', INSERT_REPLACE); };
    $run(); exportCheck(count($patches) === 1, 'A concurrently changed reservation was exported from a stale row');

    // An ambiguous PATCH is never retried blindly, while other services continue.
    $seed(42, '192.0.2.48', $proof('192.0.2.48', '192.0.2.47'));
    $queue->withServiceLock(42, $run);
    exportCheck(count($patches) === 1, 'An in-flight service edit allowed an older export decision');
    $patchThrows = true; $run(); exportCheck(count($patches) === 2 && ($apiSqlite->readConfig()->sync_attr_date ?? null) === null, 'Ambiguous export was marked complete');
    $patchThrows = false; $run(); exportCheck(count($patches) === 2, 'An uncertain PATCH replayed an old allocation');
    $seed(43, '192.0.2.49', $proof('192.0.2.49')); $responses[42] = new RuntimeException('fixture first service unavailable'); $responses[43] = $service(43, null);
    $run(); exportCheck(count($patches) === 3 && $patches[2]['path'] === 'clients/services/43', 'One unavailable service prevented another confirmed allocation from exporting');
    $log = file_get_contents('data/plugin.log');
    foreach (['PRIVATE-ROUTER-USER','PRIVATE-ROUTER-PASSWORD','PRIVATE-ACCOUNT-PASSWORD'] as $secret) exportCheck(strpos($log, $secret) === false, 'Exporter diagnostics disclosed credentials');
    echo "IP attribute export passed: fresh UISP identity/evidence, manual clear/change protection, confirmed new pool allocation only, one-use durable receipts, pending/concurrent guards, ambiguous-write isolation and no queue/router changes.\n";
} finally {
    if (isset($queue)) $queue->close(); if (isset($apiSqlite)) $apiSqlite->close(); chdir($original);
    $resolved = realpath($directory); $temporaryRoot = realpath(sys_get_temp_dir());
    if ($resolved && $temporaryRoot && strpos($resolved, $temporaryRoot . DIRECTORY_SEPARATOR . 'uisp-ip-export-') === 0) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); rmdir($resolved);
    }
}
