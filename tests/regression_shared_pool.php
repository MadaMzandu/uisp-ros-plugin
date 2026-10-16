<?php
// Real Batch/queue/data preparation with an isolated RouterOS resource catalogue.
// No network, UISP installation or real router is used.
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
function checkPool(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function poolSnapshot(string $key): array {
    $db = new SQLite3('data/jobs.db', SQLITE3_OPEN_READONLY);
    try { return json_decode($db->querySingle('SELECT payload FROM jobs WHERE id=' . (int)substr($key, 1)), true, 512, JSON_THROW_ON_ERROR); }
    finally { $db->close(); }
}
function poolCommand(array $commands, string $path): array {
    $matches = array_values(array_filter($commands, static fn(array $command): bool => trim($command['path'] ?? '', '/') === trim($path, '/')));
    checkPool(count($matches) === 1, 'Expected exactly one prepared command for ' . $path);
    return $matches[0];
}
$source = realpath(__DIR__ . '/../src'); $original = getcwd();
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-shared-pool-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
foreach (['lib', 'includes', 'data', 'vendor'] as $folder) mkdir($directory . '/' . $folder, 0700);
foreach (glob($source . '/lib/*.php') as $file) copy($file, $directory . '/lib/' . basename($file));
foreach (glob($source . '/includes/*') as $file) if (is_file($file)) copy($file, $directory . '/includes/' . basename($file));
file_put_contents($directory . '/vendor/autoload.php', <<<'PHP'
<?php
namespace Ubnt\UcrmPluginSdk\Service;
class UcrmApi {
    public static function create() { return new self(); }
    public function get($path, $options=[]) {
        if ($path==='clients/70') return ['id'=>70,'isArchived'=>false];
        if (preg_match('~^clients/services/(91|92)$~D',$path,$match))
            return ['id'=>(int)$match[1],'clientId'=>70,'status'=>1];
        throw new \RuntimeException('Network prohibited in shared pool regression');
    }
}
PHP);
file_put_contents($directory . '/lib/mt.php', <<<'PHP'
<?php
class MT {
    public static ?string $failedDuid = null;
    public static array $pools = [];
    public static array $bindings = [];
    public static array $leases = [];
    public static array $batches = [];
    public function do_batch($device, $commands): int {
        self::$batches[] = ['device' => (array)$device, 'commands' => $commands];
        foreach ($commands as $command) {
            $path = trim($command['path'], '/');
            $remove = ($command['action'] ?? '') === 'remove';
            if ($path === 'ipv6/pool') {
                $name = $command['name'];
                $existing = self::$pools[$name] ?? null;
                if ($existing && (!isset($command['_owner']) || ($existing['comment'] ?? '') !== $command['_owner']))
                    throw new RuntimeException('Fixture refused to modify an unowned IPv6 pool');
                if ($remove) unset(self::$pools[$name]);
                else self::$pools[$name] = $command;
            }
            if ($path === 'ip/dhcp-server/lease' && !$remove) self::$leases[$command['mac-address']] = $command;
            if ($path === 'ipv6/dhcp-server/binding') {
                if (($command['duid'] ?? '') === self::$failedDuid) return 0;
                if (!$remove) self::$bindings[$command['duid']] = $command;
            }
        }
        return count($commands);
    }
    public function failed(): array { return []; }
    public function success(): array { return []; }
}
PHP);
chdir($directory); set_include_path($directory . '/lib' . PATH_SEPARATOR . get_include_path());
require 'lib/api_common.php'; require 'lib/api_setup.php'; require 'lib/batch.php';
try {
    $apiSqlite = new ApiSqlite('data/data.db'); $apiSqlite->exec(file_get_contents('includes/schema.sql'));
    (new ApiSetup())->config_load(); $apiSqlite->saveConfig(['version' => MY_VERSION, 'disable_contention' => true]);
    $apiSqliteCache = new ApiSqlite('data/cache.db'); $apiSqliteCache->exec(file_get_contents('includes/cache.sql'));
    $apiJobQueue = myJobQueue();
    $oldPrefix = 'fd60:1::/48'; $newPrefix = 'fd60:2::/48';
    $oldIPv6 = 'fd60:1:0:100::/64'; $newIPv6 = 'fd60:2:0:200::/56';
    $apiSqlite->insert(['id' => 10, 'name' => 'Fixture Router', 'type' => 'mikrotik', 'ip' => '192.0.2.10', 'user' => 'fixture',
        'password' => ' frozen device 0 ', 'pool6' => $oldPrefix, 'pfxLength' => 64], 'devices');
    foreach ([1 => 'Plan A', 2 => 'Plan B'] as $id => $name)
        $apiSqlite->insert(['id' => $id, 'name' => $name, 'ratio' => 1, 'uploadSpeed' => 20, 'downloadSpeed' => 50], 'plans');
    $apiSqliteCache->insert(['id' => 70, 'firstName' => 'Pool', 'lastName' => 'Fixture'], 'clients');
    $apiSqliteCache->insert(['id' => 91, 'clientId' => 70, 'device' => 10, 'planId' => 1, 'status' => 1,
        'mac' => '02:00:00:00:00:91', 'duid' => '0001000100000091', 'iaid' => '0', 'username' => 'frozen-user-a',
        'password' => ' frozen customer 0 ', 'uploadSpeed' => 20, 'downloadSpeed' => 50], 'services');
    $apiSqliteCache->insert(['id' => 91, 'address' => '192.0.2.91', 'address6' => $oldIPv6], 'network');
    $external = ['name' => 'uisp-pool', 'prefix' => 'fd99::/48', 'prefix-length' => 60, 'comment' => 'Administrator external pool'];
    MT::$pools['uisp-pool'] = $external;
    MT::$failedDuid = '0x0001000100000091';
    checkPool(!(new Batch())->set_accounts([91]), 'A did not fail after creating its pool and lease');
    $keys = array_keys($apiJobQueue->list()); checkPool(count($keys) === 1, 'A did not remain as one prepared retry');
    $oldKey = $keys[0]; $frozen = poolSnapshot($oldKey);
    $poolA = poolCommand($frozen['_commands'], '/ipv6/pool');
    $bindingA = poolCommand($frozen['_commands'], '/ipv6/dhcp-server/binding');
    $oldName = MtData::managedPoolName(10, $oldPrefix, 64);
    checkPool($poolA['name'] === $oldName && isset(MT::$pools[$oldName]), 'Prepared A did not create its owned frozen pool');
    checkPool($bindingA['prefix-pool'] === $oldName, 'A binding did not reference its frozen pool');
    checkPool($bindingA['iaid'] === 0 && $bindingA['address'] === $oldIPv6, 'Prepared A lost its zero IAID or IPv6 address');

    $apiSqlite->edit(['id' => 10, 'pool6' => $newPrefix, 'pfxLength' => 56, 'password' => 'new device credential'], 'devices');
    $apiSqliteCache->edit(['id' => 91, 'username' => 'changed-user-a', 'password' => 'changed customer credential'], 'services');
    $apiSqliteCache->edit(['id' => 91, 'address' => '192.0.2.191', 'address6' => 'fd60:2:0:300::/56'], 'network');
    $apiSqliteCache->insert(['id' => 92, 'clientId' => 70, 'device' => 10, 'planId' => 2, 'status' => 1,
        'mac' => '02:00:00:00:00:92', 'duid' => '0001000100000092', 'iaid' => '1', 'uploadSpeed' => 20, 'downloadSpeed' => 50], 'services');
    $apiSqliteCache->insert(['id' => 92, 'address' => '192.0.2.92', 'address6' => $newIPv6], 'network');
    checkPool((new Batch())->set_accounts([92]), 'B on the second plan did not apply the revised device pool');
    $newName = MtData::managedPoolName(10, $newPrefix, 56);
    checkPool($newName !== $oldName && isset(MT::$pools[$newName]), 'Different pool revisions did not coexist');
    $poolBefore = MT::$pools[$newName]; $bindingBefore = MT::$bindings['0x0001000100000092'];
    checkPool($bindingBefore['prefix-pool'] === $newName && $bindingBefore['address'] === $newIPv6, 'B does not use the revised pool/address');
    MT::$batches = [];
    $apiJobQueue->run([$oldKey]);
    checkPool(MT::$pools[$newName] === $poolBefore, 'A retry restored its obsolete prefix into B’s pool');
    checkPool(MT::$bindings['0x0001000100000092'] === $bindingBefore, 'A retry altered B’s successful binding');
    checkPool(MT::$pools['uisp-pool'] === $external, 'A or B altered the administrator’s legacy pool');
    $retry = poolSnapshot($oldKey);
    checkPool($retry['_device'] === $frozen['_device'] && $retry['_plan'] === $frozen['_plan'] && $retry['_config'] === $frozen['_config'], 'A retry changed frozen identity or settings');
    checkPool($retry['username'] === 'frozen-user-a' && $retry['password'] === ' frozen customer 0 ', 'A retry replaced frozen customer credentials');
    $retriedBinding = poolCommand($retry['_commands'], '/ipv6/dhcp-server/binding');
    $retriedLease = poolCommand($retry['_commands'], '/ip/dhcp-server/lease');
    checkPool($retriedBinding['address'] === $oldIPv6 && $retriedBinding['prefix-pool'] === $oldName && $retriedBinding['iaid'] === 0, 'A retry changed its frozen IPv6 binding');
    checkPool($retriedLease['address'] === '192.0.2.91' && $retriedLease['mac-address'] === '02:00:00:00:00:91', 'A retry changed its frozen IPv4/MAC');
    checkPool(count(MT::$batches) === 1 && MT::$batches[0]['device']['password'] === 'new device credential', 'Credential rotation for the same router endpoint prevented its prepared retry');

    // Upgrade an old queued uisp-pool snapshot safely; never rename/remove the external object itself.
    $legacyPool = $poolA; $legacyPool['name'] = 'uisp-pool'; unset($legacyPool['_owner'], $legacyPool['comment']);
    $legacyBinding = $bindingA; $legacyBinding['prefix-pool'] = 'uisp-pool';
    $legacyCommands = [['path' => '/ipv6/pool', 'name' => 'uisp-pool', 'action' => 'remove'], $legacyPool, $legacyBinding];
    $safe = MtData::safePreparedCommands($frozen, $legacyCommands);
    $safePool = poolCommand($safe, '/ipv6/pool'); $safeBinding = poolCommand($safe, '/ipv6/dhcp-server/binding');
    checkPool($safePool['name'] === $oldName && $safePool['_owner'] === 'uisp-ros-plugin:ipv6-pool:' . $oldName, 'Legacy pool creation was not remapped to verified revision ownership');
    checkPool($safeBinding['prefix-pool'] === $oldName && $safeBinding['address'] === $bindingA['address'], 'Legacy binding was not remapped without changing its frozen address');
    (new MT())->do_batch((object)$frozen['_device'], $safe);
    checkPool(MT::$pools['uisp-pool'] === $external && MT::$pools[$newName] === $poolBefore, 'Legacy migration touched an external or newer pool');
    $unowned = $poolA; $unowned['name'] = 'manual-pool'; unset($unowned['_owner']);
    $refused = false;
    try { MtData::safePreparedCommands($frozen, [$unowned]); }
    catch (RuntimeException $error) { $refused = strpos($error->getMessage(), 'ownership') !== false; }
    checkPool($refused, 'An arbitrary queued pool without ownership was accepted');
    echo "Shared IPv6 pool regression passed: genuine prepared Batch retry, different-plan revised pools coexist, current B remains unchanged, frozen A addresses/IAID/credentials, legacy external pool untouched and unowned snapshots rejected.\n";
} finally {
    if (isset($apiJobQueue)) $apiJobQueue->close();
    if (isset($apiSqlite)) $apiSqlite->close();
    if (isset($apiSqliteCache)) $apiSqliteCache->close();
    chdir($original);
    $resolved = realpath($directory); $temporaryRoot = realpath(sys_get_temp_dir());
    if ($resolved && $temporaryRoot && strpos($resolved, $temporaryRoot . DIRECTORY_SEPARATOR . 'uisp-shared-pool-') === 0) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($resolved);
    }
}
