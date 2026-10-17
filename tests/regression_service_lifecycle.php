<?php
// Fresh lifecycle decisions, real queue/allocator/Batch and RouterOS preflight through a RAM transport.
set_error_handler(static function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
function lifecycleCheck(bool $condition, string $message): void
{ if (!$condition) throw new RuntimeException($message); }
$bulkCount = in_array('--quick', $argv ?? [], true) ? 4 : 200;
$source = realpath(__DIR__ . '/../src');
$original = getcwd(); $originalInclude = get_include_path();
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-service-lifecycle-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
foreach (['lib','includes','data','vendor'] as $folder) mkdir($directory . '/' . $folder, 0700);
foreach (glob($source . '/lib/*.php') as $file) copy($file, $directory . '/lib/' . basename($file));
foreach (glob($source . '/includes/*') as $file) if (is_file($file)) copy($file, $directory . '/includes/' . basename($file));
file_put_contents($directory . '/vendor/autoload.php', <<<'PHP'
<?php
namespace Ubnt\UcrmPluginSdk\Service;
class UcrmApi {
    public static array $responses = [];
    public static array $reads = [];
    public static array $patches = [];
    public static function create() { return new self(); }
    public function get($path, $options = []) {
        self::$reads[] = [$path, $options];
        if (!array_key_exists($path, self::$responses)) throw new \RuntimeException('Fixture external network prohibited: ' . $path);
        $response = self::$responses[$path];
        if ($response instanceof \Throwable) throw $response;
        return is_callable($response) ? $response($path, $options) : $response;
    }
    public function patch($path, $data = []) {
        self::$patches[] = [$path, $data];
        throw new \RuntimeException('Fixture external writes prohibited');
    }
}
PHP);
$batchFile = $directory . '/lib/batch.php';
$batchCode = str_replace('new MT()', 'new LifecycleMT()', file_get_contents($batchFile), $factoryChanges);
lifecycleCheck($factoryChanges === 1, 'The fixture RouterOS adapter hook changed');
file_put_contents($batchFile, $batchCode);
chdir($directory); set_include_path($directory . '/lib' . PATH_SEPARATOR . $originalInclude);
require_once 'vendor/autoload.php'; require 'lib/api_common.php'; require 'lib/api_setup.php'; require 'lib/api_action.php';

class LifecycleRouterAPI extends RouterosAPI {
    private array $pending = [];
    private array $response = [];
    private $apply;
    public function __construct(callable $apply) { $this->apply = $apply; }
    public function write($word, $param2 = true) { $this->pending[] = $word; return true; }
    public function finishSentence(): void {
        $this->response = ($this->apply)($this->pending); $this->pending = [];
    }
    public function read($parse = true) { $response = $this->response; $this->response = []; return $response; }
    public function disconnect() { $this->connected = false; }
}
class LifecycleMT extends MT {
    private LifecycleRouterAPI $recording;
    public function __construct() {
        parent::__construct();
        $this->recording = new LifecycleRouterAPI(static function (array $words): array {
            $full = trim(array_shift($words), '/'); $separator = strrpos($full, '/');
            $action = substr($full, $separator + 1); $path = substr($full, 0, $separator);
            $values = [];
            foreach ($words as $word) {
                if (strpos($word, '=') !== 0) continue;
                [$key, $value] = explode('=', substr($word, 1), 2); $values[$key] = $value;
            }
            $GLOBALS['lifecycleWrites'][] = [$path, $action, $values];
            $catalogue =& $GLOBALS['lifecycleRouter'];
            if ($path === 'ppp/secret' && $action !== 'remove' && isset($values['profile'])) {
                $found = array_filter($catalogue['ppp/profile'] ?? [], static fn($row) => ($row['name'] ?? null) === $values['profile']);
                if (!$found) return ['!trap' => [['message' => 'profile missing']]];
            }
            if ($action === 'set') {
                foreach ($catalogue[$path] ?? [] as $index => $row)
                    if (($row['.id'] ?? null) === ($values['.id'] ?? null)) $catalogue[$path][$index] = array_replace($row, $values);
            } elseif ($action === 'add') {
                $GLOBALS['lifecycleRouterNext'] = ($GLOBALS['lifecycleRouterNext'] ?? 0) + 1;
                $catalogue[$path][] = ['.id' => '*fixture-' . $GLOBALS['lifecycleRouterNext']] + $values;
            } elseif ($action === 'remove') {
                $ids = explode(',', $values['.id'] ?? '');
                $catalogue[$path] = array_values(array_filter($catalogue[$path] ?? [], static fn($row) => !in_array($row['.id'] ?? null, $ids, true)));
            }
            return [];
        });
    }
    protected function api_connect(): ?RouterosAPI { $this->recording->connected = true; return $this->recording; }
    protected function read($path, $filter = null) {
        if (!empty($GLOBALS['lifecycleRouterOffline'])) return null;
        if (is_string($filter)) {
            $conditions = [];
            foreach (explode(',', $filter) as $item) {
                [$key, $value] = explode('=', ltrim($item, '?'), 2); $conditions[$key] = $value;
            }
            $filter = $conditions;
        }
        if (isset($GLOBALS['lifecycleOnRead'])) ($GLOBALS['lifecycleOnRead'])(trim($path, '/'), $filter);
        return array_values(array_filter($GLOBALS['lifecycleRouter'][trim($path, '/')] ?? [], static function ($row) use ($filter): bool {
            foreach ((array) $filter as $key => $value) if ((string) ($row[$key] ?? '') !== (string) $value) return false;
            return true;
        }));
    }
}
try {
    $apiSqlite = new ApiSqlite('data/data.db'); $apiSqlite->exec(file_get_contents('includes/schema.sql'));
    (new ApiSetup())->config_load();
    $apiSqlite->saveConfig(['version' => MY_VERSION, 'disable_contention' => true, 'auto_ppp_user' => false,
        'device_name_attr' => 'site', 'pppoe_user_attr' => 'user', 'pppoe_pass_attr' => 'pass',
        'ip_addr_attr' => 'ip', 'ip_addr6_attr' => 'ip6', 'router_ppp_pool' => true,
        'disabled_profile' => 'fixture-paused', 'disabled_list' => 'fixture-paused-list', 'disabled_rate' => 0]);
    file_put_contents('data/config.json', json_encode(['debugEnable' => false]));
    $apiSqliteCache = new ApiSqlite('data/cache.db'); $apiSqliteCache->exec(file_get_contents('includes/cache.sql'));
    $queue = myJobQueue();
    $apiSqlite->insert(['id' => 10, 'name' => 'Fixture Router', 'type' => 'mikrotik', 'ip' => '192.0.2.10',
        'user' => 'fixture-api', 'password' => 'PRIVATE-ROUTER', 'pool' => '10.42.0.0/24',
        'pool6' => '2001:db8:42::/56', 'pfxLength' => 64], 'devices');
    $apiSqlite->insert(['id' => 1, 'name' => 'Fixture Plan', 'ratio' => 1, 'uploadSpeed' => 7, 'downloadSpeed' => 21], 'plans');
    $GLOBALS['lifecycleRouter'] = [
        'ip/address' => [['.id' => '*lan', 'address' => '192.0.2.1/24', 'disabled' => 'false', 'dynamic' => 'false', 'invalid' => 'false']],
        'ppp/profile' => [['.id' => '*paused', 'name' => 'fixture-paused', 'address-list' => 'fixture-paused-list', 'rate-limit' => '64k/64k']]
    ];
    $GLOBALS['lifecycleWrites'] = [];
    $sdk = \Ubnt\UcrmPluginSdk\Service\UcrmApi::class;
    $sdk::$responses['custom-attributes'] = [
        ['id' => 1, 'key' => 'site', 'attributeType' => 'service'], ['id' => 2, 'key' => 'user', 'attributeType' => 'service'],
        ['id' => 3, 'key' => 'pass', 'attributeType' => 'service'], ['id' => 4, 'key' => 'ip', 'attributeType' => 'service'],
        ['id' => 5, 'key' => 'ip6', 'attributeType' => 'service']
    ];
    $freshService = static function (int $sid, int $cid, int $status, string $user, ?string $ip = null, ?string $ip6 = null): object {
        $attributes = [['key' => 'site', 'customAttributeId' => 1, 'value' => 'Fixture Router'],
            ['key' => 'user', 'customAttributeId' => 2, 'value' => $user],
            ['key' => 'pass', 'customAttributeId' => 3, 'value' => 'PRIVATE-ACCOUNT'],
            ['key' => 'ip', 'customAttributeId' => 4, 'value' => $ip],
            ['key' => 'ip6', 'customAttributeId' => 5, 'value' => $ip6]];
        return (object) ['id' => $sid, 'clientId' => $cid, 'status' => $status, 'servicePlanId' => 1,
            'uploadSpeed' => 7, 'downloadSpeed' => 21, 'attributes' => array_map(static fn($value) => (object) $value, $attributes)];
    };
    $entity = static function (int $sid, int $cid, int $status, string $user, string $ip, string $ip6): array {
        return ['id' => $sid, 'clientId' => $cid, 'device' => 10, 'planId' => 1, 'status' => $status,
            'company' => null, 'firstName' => 'Fixture', 'lastName' => 'Service',
            'username' => $user, 'password' => 'PRIVATE-ACCOUNT', 'uploadSpeed' => 7, 'downloadSpeed' => 21,
            'network' => ['address' => $ip, 'address6' => $ip6]];
    };
    $cache = static function (array $service) use ($apiSqliteCache): void {
        $network = $service['network'] ?? []; unset($service['network']);
        unset($service['company'], $service['firstName'], $service['lastName']);
        foreach (array_keys($service) as $field) if (strpos($field, '_') === 0) unset($service[$field]);
        $apiSqliteCache->insert($service, 'services', INSERT_REPLACE);
        if ($network) $apiSqliteCache->insert(['id' => $service['id']] + $network, 'network', INSERT_REPLACE);
    };
    require 'lib/api_service_lifecycle.php'; require 'lib/api_pool_capacity.php';
    $lifecycle = static fn() => new ApiServiceLifecycle();
    $jobsDatabase = new SQLite3('data/jobs.db'); $jobsDatabase->enableExceptions(true);
    // This scale test checks ordering and ownership, not crash durability. Keep
    // all real transactions while avoiding hundreds of filesystem flushes in a
    // synchronized Windows workspace; the durable queue suites test recovery.
    $apiSqlite->exec('PRAGMA synchronous=OFF'); $apiSqliteCache->exec('PRAGMA synchronous=OFF');
    $queueDatabase = new ReflectionProperty(ApiJobQueue::class, 'db');
    if (PHP_VERSION_ID < 80100) $queueDatabase->setAccessible(true);
    $queueDatabase->getValue($queue)->exec('PRAGMA synchronous=OFF');
    $due = static function () use ($jobsDatabase): void { $jobsDatabase->exec('UPDATE jobs SET next_attempt=0'); };
    $capacity = static function (string $family): array {
        $report = (new ApiPoolCapacity())->read();
        lifecycleCheck($report['available'], 'Capacity report became unavailable');
        foreach ($report['items'] as $row) if ($row['device_id'] === 10 && $row['family'] === $family) return $row;
        throw new RuntimeException('Missing capacity family');
    };
    // The user's production scenario: every address in a retired group is reused
    // by a different current client, while old jobs and applied snapshots still exist.
    $oldKeys = []; $newServices = []; $oldLifecycleStates = [];
    for ($index = 0; $index < $bulkCount; $index++) {
        $oldId = 10000 + $index; $oldClient = 20000 + $index;
        $newId = 30000 + $index; $newClient = 40000 + $index;
        $ip = '10.42.0.' . ($index + 1); $ip6 = ApiIP::normalize('2001:db8:42:' . dechex($index) . '::/64', true);
        $old = $entity($oldId, $oldClient, 1, 'old-' . $oldId, $ip, $ip6);
        $cache($old);
        $apiSqlite->insert(['id' => $oldId, 'clientId' => $oldClient, 'device' => 10, 'planId' => 1, 'status' => 1], 'services');
        $apiSqlite->insert(['id' => $oldId, 'address' => $ip, 'address6' => $ip6], 'network');
        $archived = $index % 4 === 3;
        $status = $archived ? 1 : [2,5,8][$index % 3];
        $apiSqliteCache->insert(['id' => $oldClient, 'firstName' => 'Old fixture', 'isArchived' => 0], 'clients');
        $sdk::$responses['clients/services/' . $oldId] = $freshService($oldId, $oldClient, 1, $old['username'], $ip, $ip6);
        $sdk::$responses['clients/' . $oldClient] = ['id' => $oldClient, 'isArchived' => false];
        $oldLifecycleStates[$oldId] = ['status' => $status, 'clientId' => $oldClient, 'archived' => $archived];
        if ($index > 0) $oldKeys[] = $queue->enqueue($old);
        $new = $entity($newId, $newClient, 1, 'new-' . $newId, $ip, $ip6); $newServices[] = $new;
        $apiSqliteCache->insert(['id' => $newClient, 'firstName' => 'Current fixture', 'isArchived' => 0], 'clients');
        $sdk::$responses['clients/services/' . $newId] = $freshService($newId, $newClient, 1, $new['username'], $ip, $ip6);
        $sdk::$responses['clients/' . $newClient] = ['id' => $newClient, 'isArchived' => false];
    }
    $queue->run($oldKeys, static fn() => true);
    lifecycleCheck($queue->completed($oldKeys), 'Could not seed durable applied owners');
    // At least one retired account physically exists, so passing this test cannot
    // simply discard every old reservation while ignoring its router resource.
    $seedOld = $entity(10000, 20000, 1, 'old-10000', '10.42.0.1', '2001:db8:42:0::/64');
    lifecycleCheck((new Batch())->set_accounts([$seedOld]), 'Could not seed the physical retired account: ' . json_encode($queue->list()));
    foreach ($oldLifecycleStates as $sid => $state) {
        $sdk::$responses['clients/services/' . $sid]->status = $state['status'];
        $sdk::$responses['clients/' . $state['clientId']]['isArchived'] = $state['archived'];
        if ($state['archived']) $apiSqliteCache->edit(['id' => $state['clientId'], 'isArchived' => 1], 'clients');
    }
    foreach ($newServices as $new) $cache($new);
    $heldBefore = $capacity('IPv4');
    lifecycleCheck((int) $heldBefore['free'] <= 254 - $bulkCount, 'Applied retired fixtures did not initially hold pool addresses');
    $cleanupKeys = [];
    for ($index = 0; $index < $bulkCount; $index++) {
        $review = $lifecycle()->review(10000 + $index, $queue);
        lifecycleCheck($review['state'] === 'retired' && $review['retired'] === true,
            'Fresh terminal/archive owner did not retire: ' . (10000 + $index) . ' ' . json_encode($review));
        $cleanupKeys = array_merge($cleanupKeys, $review['jobKeys']);
    }
    lifecycleCheck(count($cleanupKeys) >= $bulkCount, 'Applied retirement skipped the ordinary cleanup queue');
    $due(); $queue->runSelected($cleanupKeys);
    lifecycleCheck($queue->completed($cleanupKeys), 'Old cleanup jobs remained blocked by their prior desired state: ' . json_encode($queue->list()));
    lifecycleCheck(count($GLOBALS['lifecycleRouter']['ppp/secret'] ?? []) === 0, 'The retired physical account was not removed');
    foreach ($newServices as $new) {
        lifecycleCheck((new Batch())->set_accounts([$new]), 'A current client was blocked by retired ownership: ' . $new['id'] . ' ' . json_encode($queue->list()));
        lifecycleCheck($apiSqlite->selectAddress($new['id']) === $new['network']['address']
            && $apiSqlite->selectAddress($new['id'], true) === $new['network']['address6'], 'Transferred IPv4/IPv6 allocation changed');
    }
    lifecycleCheck(count($GLOBALS['lifecycleRouter']['ppp/secret'] ?? []) === $bulkCount && $queue->list() === [],
        'Two hundred current accounts did not complete without a hidden pending blocker');
    foreach ($queue->reservedAddresses() as $reservation) lifecycleCheck((int) $reservation['id'] >= 30000, 'Retired IPv4 leaked back into the allocator');
    foreach ($queue->reservedAddresses(true) as $reservation) lifecycleCheck((int) $reservation['id'] >= 30000, 'Retired IPv6 leaked back into the allocator');
    lifecycleCheck($capacity('IPv4')['free'] === (string) (254 - $bulkCount) && $capacity('IPv6')['free'] === (string) (256 - $bulkCount),
        'Transferred ownership was double counted in pool capacity');

    // Q989's direction matters: #7279 is the archived requester, #9807 is current.
    $active = $entity(9807, 61930, 1, 'current-9807', '10.42.0.220', '2001:db8:42:dc::/64');
    $cache($active); $apiSqliteCache->insert(['id' => 61930, 'firstName' => 'Current', 'isArchived' => 0], 'clients');
    $sdk::$responses['clients/services/9807'] = $freshService(9807, 61930, 1, $active['username'], '10.42.0.220', '2001:db8:42:dc::/64');
    $sdk::$responses['clients/61930'] = ['id' => 61930, 'isArchived' => false];
    lifecycleCheck((new Batch())->set_accounts([$active]), 'Could not seed current owner #9807');
    $old = $entity(7279, 2286, 1, 'archived-account@77777', '10.42.0.220', '2001:db8:42:dc::/64');
    $cache($old); $apiSqliteCache->insert(['id' => 2286, 'firstName' => 'Archived', 'isArchived' => 1], 'clients');
    $sdk::$responses['clients/services/7279'] = $freshService(7279, 2286, 1, $old['username'], '10.42.0.220', '2001:db8:42:dc::/64');
    $sdk::$responses['clients/2286'] = ['id' => 2286, 'isArchived' => true];
    $oldKey = $queue->enqueue($old);
    $jobsDatabase->exec("UPDATE jobs SET attempts=8,error='network_address_conflict: requested_service=7279 blocking_service=9807 source=reserved family=IPv4' WHERE id=" . (int) substr($oldKey, 1));
    $beforeWrites = count($GLOBALS['lifecycleWrites']);
    $review = $lifecycle()->review(7279, $queue); $due();
    if ($review['jobKeys']) $queue->runSelected($review['jobKeys']);
    lifecycleCheck(!$queue->pending([$oldKey]) && $apiSqlite->selectAddress(9807) === '10.42.0.220'
        && $queue->appliedSnapshot(9807)['username'] === 'current-9807', 'Archived requester remained pending or released current owner #9807');
    foreach (array_slice($GLOBALS['lifecycleWrites'], $beforeWrites) as [$path, $action, $values])
        lifecycleCheck(!($path === 'ppp/secret' && ($values['name'] ?? '') === $old['username'] && $action !== 'remove'),
            'Retirement replayed the archived requester account');
    lifecycleCheck($lifecycle()->inspect(9807)['state'] === 'active', 'Live reserved owner was classified as retired');

    // Suspension is a continuing service: its addresses cannot be recycled.
    $sdk::$responses['clients/services/9807']->status = 3;
    $before = [$apiSqlite->selectAddress(9807), $queue->appliedSnapshot(9807), $queue->list()];
    $suspended = $lifecycle()->review(9807, $queue);
    lifecycleCheck($suspended['state'] === 'active' && $suspended['retired'] === false
        && $before === [$apiSqlite->selectAddress(9807), $queue->appliedSnapshot(9807), $queue->list()],
        'A suspended service lost its IP or became a termination');
    $sdk::$responses['clients/services/9807']->status = 1;

    // Unknown authority is never a lifecycle proof; an exception mentioning 404
    // is distinct from an authenticated 404 response.
    eval('namespace GuzzleHttp\\Exception; class RequestException extends \\RuntimeException {
        private int $status; public function __construct(int $status, ?\\Throwable $previous=null) { parent::__construct("PRIVATE transport failure",0,$previous); $this->status=$status; }
        public function hasResponse(): bool { return $this->status>0; }
        public function getResponse(): object { return new class($this->status) {
            private int $status; public function __construct(int $status) {$this->status=$status;}
            public function getStatusCode(): int {return $this->status;}
        }; }
    }');
    foreach ([new RuntimeException('PRIVATE transport 404'),
        new \GuzzleHttp\Exception\RequestException(401), new \GuzzleHttp\Exception\RequestException(403),
        new \GuzzleHttp\Exception\RequestException(429), new \GuzzleHttp\Exception\RequestException(500), null, false,
        new \GuzzleHttp\Exception\RequestException(0, new \GuzzleHttp\Exception\RequestException(404)),
        new \GuzzleHttp\Exception\RequestException(500, new \GuzzleHttp\Exception\RequestException(404)),
        ['id' => 9808, 'clientId' => 61930, 'status' => 2], ['id' => 9807, 'status' => 2],
        ['id' => 9807, 'clientId' => 61930, 'status' => true]] as $unknown) {
        $request = static function (string $path) use ($unknown, $sdk) {
            if ($path === 'clients/services/9807') {
                if ($unknown instanceof Throwable) throw $unknown;
                return $unknown;
            }
            if (!array_key_exists($path, $sdk::$responses)) throw new RuntimeException('Unexpected fixture lookup');
            return $sdk::$responses[$path];
        };
        $result = (new ApiServiceLifecycle($request))->review(9807, $queue);
        lifecycleCheck($result['state'] === 'unknown' && !$result['retired']
            && $apiSqlite->selectAddress(9807) === '10.42.0.220' && $queue->list() === [],
            'Unavailable/malformed fresh state released a current reservation');
    }

    // Revalidation must defeat a retirement preview once UISP reactivates it.
    $reactivated = $entity(9820, 61931, 1, 'reactivated-9820', '10.42.0.223', '2001:db8:42:df::/64');
    $cache($reactivated); $apiSqliteCache->insert(['id' => 61931, 'firstName' => 'Reactivated', 'isArchived' => 0], 'clients');
    $apiSqlite->insert(['id' => 9820, 'address' => '10.42.0.223', 'address6' => '2001:db8:42:df::/64'], 'network');
    $sdk::$responses['clients/services/9820'] = $freshService(9820, 61931, 2, 'reactivated-9820', '10.42.0.223', '2001:db8:42:df::/64');
    $sdk::$responses['clients/61931'] = ['id' => 61931, 'isArchived' => false];
    $preview = $lifecycle()->preview(9820);
    lifecycleCheck($preview['eligible'] === true && is_string($preview['previewToken']), 'Terminal owner could not be reviewed');
    $sdk::$responses['clients/services/9820']->status = 1;
    try { $reapplied = $lifecycle()->apply($preview['previewToken'], $queue); }
    catch (InvalidArgumentException $expected) { $reapplied = ['retired' => false]; }
    lifecycleCheck(empty($reapplied['retired']) && $apiSqlite->selectAddress(9820) === '10.42.0.223'
        && $queue->list() === [], 'An old terminal preview overrode current reactivation');

    // Historical ownership remains protected while the physical retirement has
    // not succeeded, and becomes informational after the guarded cleanup succeeds.
    $retained = $entity(9850, 61932, 1, 'retained-9850', '10.42.0.224', '2001:db8:42:e0::/64');
    $cache($retained); $apiSqliteCache->insert(['id' => 61932, 'firstName' => 'Retained', 'isArchived' => 0], 'clients');
    $sdk::$responses['clients/services/9850'] = $freshService(9850, 61932, 1, 'retained-9850', '10.42.0.224', '2001:db8:42:e0::/64');
    $sdk::$responses['clients/61932'] = ['id' => 61932, 'isArchived' => false];
    lifecycleCheck((new Batch())->set_accounts([$retained]), 'Could not seed a real retained account');
    $historical = $queue->appliedSnapshot(9850);
    $identity = new ReflectionMethod(ApiJobQueue::class, 'identity');
    if (PHP_VERSION_ID < 80100) $identity->setAccessible(true);
    $statement = $jobsDatabase->prepare('INSERT INTO retained_resources(source_job_id,service_id,source_version,slot,payload,identity,retained_at) VALUES(999999,9850,1,?,?,?,?)');
    foreach (['commands', json_encode($historical), $identity->invoke($queue, $historical), date('c')] as $offset => $value)
        $statement->bindValue($offset + 1, $value, SQLITE3_TEXT);
    $statement->execute()->finalize(); $statement->close();
    $sdk::$responses['clients/services/9850']->status = 2;
    $review = $lifecycle()->review(9850, $queue); $GLOBALS['lifecycleRouterOffline'] = true;
    $due(); $queue->runSelected($review['jobKeys']);
    lifecycleCheck($queue->pending($review['jobKeys']), 'Offline retirement unexpectedly completed');
    $collision = false;
    try { (new ApiIP())->set(9851, '10.42.0.224'); }
    catch (NetworkAddressConflict $expected) { $collision = true; }
    lifecycleCheck($collision, 'An unremoved router resource released its address before cleanup succeeded');
    $GLOBALS['lifecycleRouterOffline'] = false; $due(); $queue->runSelected($review['jobKeys']);
    lifecycleCheck($queue->completed($review['jobKeys']), 'A retry did not finish the guarded retirement');
    (new ApiIP())->set(9851, '10.42.0.224');
    lifecycleCheck($apiSqlite->selectAddress(9851) === '10.42.0.224', 'Retired retained resources continued blocking IPv4');
    (new ApiIP())->set(9851, '2001:db8:42:e0::/64', true);
    lifecycleCheck($apiSqlite->selectAddress(9851, true) === '2001:db8:42:e0::/64', 'Retired retained resources continued blocking IPv6');

    // A new assignment must recover a proven retired local blocker through the
    // normal worker, without requiring the operator to open a repair modal.
    $old = $entity(9860, 61933, 1, 'automatic-old', '10.42.0.225', '2001:db8:42:e1::/64'); $cache($old);
    $apiSqlite->insert(['id' => 9860, 'address' => '10.42.0.225', 'address6' => '2001:db8:42:e1::/64'], 'network');
    $apiSqliteCache->insert(['id' => 61933, 'firstName' => 'Automatic old', 'isArchived' => 0], 'clients');
    $sdk::$responses['clients/services/9860'] = $freshService(9860, 61933, 2, 'automatic-old', '10.42.0.225', '2001:db8:42:e1::/64');
    $sdk::$responses['clients/61933'] = ['id' => 61933, 'isArchived' => false];
    $new = $entity(9861, 61934, 1, 'automatic-current', '10.42.0.225', '2001:db8:42:e1::/64'); $cache($new);
    $apiSqliteCache->insert(['id' => 61934, 'firstName' => 'Automatic current', 'isArchived' => 0], 'clients');
    $sdk::$responses['clients/services/9861'] = $freshService(9861, 61934, 1, 'automatic-current', '10.42.0.225', '2001:db8:42:e1::/64');
    $sdk::$responses['clients/61934'] = ['id' => 61934, 'isArchived' => false];
    lifecycleCheck((new Batch())->set_accounts([$new]) && $apiSqlite->selectAddress(9861) === '10.42.0.225'
        && $apiSqlite->selectAddress(9860) === null, 'A new assignment did not automatically retire its proven obsolete blocker: ' . json_encode($queue->list()));

    // A router row already transferred to another explicit client/service is
    // preserved, including its active session, by a delayed old terminal cleanup.
    $old = $entity(9870, 61935, 1, 'identity-reused', '10.42.0.226', '2001:db8:42:e2::/64'); $cache($old);
    $apiSqliteCache->insert(['id' => 61935, 'firstName' => 'Previous', 'isArchived' => 0], 'clients');
    $sdk::$responses['clients/services/9870'] = $freshService(9870, 61935, 1, 'identity-reused', '10.42.0.226', '2001:db8:42:e2::/64');
    $sdk::$responses['clients/61935'] = ['id' => 61935, 'isArchived' => false];
    lifecycleCheck((new Batch())->set_accounts([$old]), 'Could not seed a reusable account identity');
    foreach ($GLOBALS['lifecycleRouter']['ppp/secret'] as &$row)
        if (($row['name'] ?? '') === 'identity-reused') $row['comment'] = '61936 - Current fixture - 9871';
    unset($row);
    $GLOBALS['lifecycleRouter']['ppp/active'][] = ['.id' => '*reused-session', 'name' => 'identity-reused'];
    $new = $entity(9871, 61936, 1, 'identity-reused', '10.42.0.226', '2001:db8:42:e2::/64'); $cache($new);
    $apiSqliteCache->insert(['id' => 61936, 'firstName' => 'Current', 'isArchived' => 0], 'clients');
    $sdk::$responses['clients/services/9871'] = $freshService(9871, 61936, 1, 'identity-reused', '10.42.0.226', '2001:db8:42:e2::/64');
    $sdk::$responses['clients/61936'] = ['id' => 61936, 'isArchived' => false];
    $sdk::$responses['clients/services/9870']->status = 2;
    $review = $lifecycle()->review(9870, $queue); $due(); $queue->runSelected($review['jobKeys']);
    $reused = array_values(array_filter($GLOBALS['lifecycleRouter']['ppp/secret'], static fn($row) => ($row['name'] ?? '') === 'identity-reused'));
    lifecycleCheck($queue->completed($review['jobKeys']) && count($reused) === 1
        && $reused[0]['comment'] === '61936 - Current fixture - 9871'
        && in_array('*reused-session', array_column($GLOBALS['lifecycleRouter']['ppp/active'] ?? [], '.id'), true),
        'Old cleanup deleted or disconnected the account already owned by another current service');
    lifecycleCheck((new Batch())->set_accounts([$new]) && $apiSqlite->selectAddress(9871) === '10.42.0.226',
        'An explicitly transferred account could not complete its current assignment');

    // A manual row has no transfer proof. Keep it intact and keep that specific
    // address held, while allowing unrelated work to continue normally.
    $manual = $entity(9880, 61937, 1, 'manual-identity', '10.42.0.227', '2001:db8:42:e3::/64'); $cache($manual);
    $apiSqliteCache->insert(['id' => 61937, 'firstName' => 'Manual fixture', 'isArchived' => 0], 'clients');
    $sdk::$responses['clients/services/9880'] = $freshService(9880, 61937, 1, 'manual-identity', '10.42.0.227', '2001:db8:42:e3::/64');
    $sdk::$responses['clients/61937'] = ['id' => 61937, 'isArchived' => false];
    lifecycleCheck((new Batch())->set_accounts([$manual]), 'Could not seed the manual identity safety fixture');
    foreach ($GLOBALS['lifecycleRouter']['ppp/secret'] as &$row)
        if (($row['name'] ?? '') === 'manual-identity') $row['comment'] = 'Operator maintained account';
    unset($row);
    $sdk::$responses['clients/services/9880']->status = 2;
    $review = $lifecycle()->review(9880, $queue); $due(); $queue->runSelected($review['jobKeys']);
    lifecycleCheck($queue->pending($review['jobKeys']) && $apiSqlite->selectAddress(9880) === '10.42.0.227',
        'An unowned manual account was removed or its potentially occupied address released');
    $new['status'] = 3;
    lifecycleCheck((new Batch())->set_accounts([$new]), 'An ambiguous retired account blocked an unrelated current service');

    // A retained source can contain only prior-network evidence. If there are
    // no router commands, cleanup or applied resources, the prior holder still
    // needs an absence check before this slot stops reserving its IPv4/IPv6.
    $sourceOnly = $entity(9890, 61938, 1, 'source-history', '10.42.0.228', '2001:db8:42:e4::/64');
    $sourceOnly['_prior_network'] = $sourceOnly['network']; $sourceOnly['network'] = ['address' => null, 'address6' => null];
    $cache($sourceOnly);
    $apiSqliteCache->insert(['id' => 61938, 'firstName' => 'Source history', 'isArchived' => 0], 'clients');
    $sdk::$responses['clients/services/9890'] = $freshService(9890, 61938, 2, 'source-history', null, null);
    $sdk::$responses['clients/61938'] = ['id' => 61938, 'isArchived' => false];
    $statement = $jobsDatabase->prepare("INSERT INTO retained_resources(source_job_id,service_id,source_version,slot,payload,identity,retained_at) VALUES(999998,9890,1,'source',?,?,?)");
    foreach ([json_encode($sourceOnly), $identity->invoke($queue, $sourceOnly), date('c')] as $offset => $value)
        $statement->bindValue($offset + 1, $value, SQLITE3_TEXT);
    $statement->execute()->finalize(); $statement->close();
    $before4 = $capacity('IPv4'); $before6 = $capacity('IPv6');
    $review = $lifecycle()->review(9890, $queue);
    lifecycleCheck($review['retired'] && !$review['released'] && $review['jobKeys'] !== [],
        'Source-only prior ownership was released without a physical absence check');
    $due(); $queue->runSelected($review['jobKeys']);
    lifecycleCheck($queue->completed($review['jobKeys']), 'Source-only prior ownership could not complete its absence check');
    foreach ([false, true] as $ipv6) foreach ($queue->reservedAddresses($ipv6) as $reservation)
        lifecycleCheck((int) $reservation['id'] !== 9890, 'Source-only prior network continued reserving an address after retirement');
    lifecycleCheck((int) $capacity('IPv4')['free'] === (int) $before4['free'] + 1
        && (int) $capacity('IPv6')['free'] === (int) $before6['free'] + 1, 'Source-only retirement was missing from pool capacity');
    $apiSqliteCache->delete(9890, 'network');
    $new = $entity(9891, 61939, 1, 'source-replacement', '10.42.0.228', '2001:db8:42:e4::/64'); $cache($new);
    $apiSqliteCache->insert(['id' => 61939, 'firstName' => 'Source replacement', 'isArchived' => 0], 'clients');
    $sdk::$responses['clients/services/9891'] = $freshService(9891, 61939, 1, 'source-replacement', '10.42.0.228', '2001:db8:42:e4::/64');
    $sdk::$responses['clients/61939'] = ['id' => 61939, 'isArchived' => false];
    lifecycleCheck((new Batch())->set_accounts([$new]) && $apiSqlite->selectAddress(9891) === '10.42.0.228'
        && $apiSqlite->selectAddress(9891, true) === '2001:db8:42:e4::/64', 'Source-only history blocked its current replacement');

    $managedFixture = static function (int $sid, int $cid, int $status, string $username, int $host) use ($entity, $cache, $freshService, $sdk, $apiSqliteCache): array {
        $ip = '10.42.0.' . $host; $ip6 = '2001:db8:42:' . dechex($host) . '::/64';
        $service = $entity($sid, $cid, $status, $username, $ip, $ip6); $cache($service);
        $apiSqliteCache->insert(['id' => $cid, 'firstName' => 'Safety fixture', 'isArchived' => 0], 'clients');
        $sdk::$responses['clients/services/' . $sid] = $freshService($sid, $cid, $status, $username, $ip, $ip6);
        $sdk::$responses['clients/' . $cid] = ['id' => $cid, 'isArchived' => false];
        return $service;
    };

    // An ownership transfer between preflight and the removal lookup must also
    // invalidate the cached permission to disconnect that account's session.
    $racy = $managedFixture(9900, 61940, 1, 'transfer-during-read', 229);
    lifecycleCheck((new Batch())->set_accounts([$racy]), 'Could not seed the concurrent ownership fixture');
    $GLOBALS['lifecycleRouter']['ppp/active'][] = ['.id' => '*racy-session', 'name' => 'transfer-during-read'];
    $sdk::$responses['clients/services/9900']->status = 2;
    $review = $lifecycle()->review(9900, $queue); $principalReads = 0;
    $GLOBALS['lifecycleOnRead'] = static function (string $path, $filter) use (&$principalReads): void {
        if ($path !== 'ppp/secret' || ($filter['name'] ?? null) !== 'transfer-during-read') return;
        $principalReads++;
        if ($principalReads !== 2) return;
        foreach ($GLOBALS['lifecycleRouter']['ppp/secret'] as &$row)
            if (($row['name'] ?? '') === 'transfer-during-read') $row['comment'] = '61941 - Current fixture - 9901';
        unset($row);
    };
    $due(); $queue->runSelected($review['jobKeys']); unset($GLOBALS['lifecycleOnRead']);
    $racyRows = array_values(array_filter($GLOBALS['lifecycleRouter']['ppp/secret'], static fn($row) => ($row['name'] ?? '') === 'transfer-during-read'));
    lifecycleCheck($principalReads >= 2 && count($racyRows) === 1 && $racyRows[0]['comment'] === '61941 - Current fixture - 9901'
        && in_array('*racy-session', array_column($GLOBALS['lifecycleRouter']['ppp/active'] ?? [], '.id'), true),
        'A transfer after preflight deleted or disconnected a new owner through cached session ownership');

    // After a restart the secret can already be gone while its established PPP
    // session remains. That session's absence still needs to be established.
    $orphanSession = $managedFixture(9920, 61942, 1, 'missing-principal-live-session', 230);
    lifecycleCheck((new Batch())->set_accounts([$orphanSession]), 'Could not seed the principal/session fixture');
    $GLOBALS['lifecycleRouter']['ppp/secret'] = array_values(array_filter($GLOBALS['lifecycleRouter']['ppp/secret'],
        static fn($row) => ($row['name'] ?? '') !== 'missing-principal-live-session'));
    $GLOBALS['lifecycleRouter']['ppp/active'][] = ['.id' => '*orphan-session', 'name' => 'missing-principal-live-session'];
    $sdk::$responses['clients/services/9920']->status = 2;
    $review = $lifecycle()->review(9920, $queue); $due(); $queue->runSelected($review['jobKeys']);
    lifecycleCheck($queue->pending($review['jobKeys']) && $apiSqlite->selectAddress(9920) === '10.42.0.230'
        && in_array('*orphan-session', array_column($GLOBALS['lifecycleRouter']['ppp/active'] ?? [], '.id'), true),
        'A surviving session was treated as absent because its principal account had already disappeared');
    $GLOBALS['lifecycleRouter']['ppp/active'] = array_values(array_filter($GLOBALS['lifecycleRouter']['ppp/active'],
        static fn($row) => ($row['.id'] ?? '') !== '*orphan-session'));
    $due(); $queue->runSelected($review['jobKeys']);
    lifecycleCheck($queue->completed($review['jobKeys']) && $apiSqlite->selectAddress(9920) === null,
        'The expired orphan session did not allow its own address to be released');

    // An exclusive suspension cap may remain because another queue depends on
    // it. Its target continues occupying the IP until that exact cap is absent.
    $apiSqlite->saveConfig(['disabled_rate' => 64]);
    $capped = $managedFixture(9940, 61944, 3, 'dependent-suspension-cap', 231);
    lifecycleCheck((new Batch())->set_accounts([$capped]), 'Could not seed a suspension cap');
    $caps = array_values(array_filter($GLOBALS['lifecycleRouter']['queue/simple'] ?? [], static fn($row) => strpos($row['comment'] ?? '', ':9940') !== false));
    lifecycleCheck(count($caps) === 1, 'The suspension cap fixture did not produce one exclusive queue');
    $capName = $caps[0]['name'];
    $GLOBALS['lifecycleRouter']['queue/simple'][] = ['.id' => '*cap-child', 'name' => 'fixture-child', 'parent' => $capName,
        'target' => '192.0.2.250/32', 'comment' => 'Operator dependency'];
    $sdk::$responses['clients/services/9940']->status = 2;
    $review = $lifecycle()->review(9940, $queue); $due(); $queue->runSelected($review['jobKeys']);
    lifecycleCheck($queue->pending($review['jobKeys']) && $apiSqlite->selectAddress(9940) === '10.42.0.231'
        && in_array($capName, array_column($GLOBALS['lifecycleRouter']['queue/simple'] ?? [], 'name'), true),
        'A suspension cap still targeting the address was omitted from retirement readback');
    $GLOBALS['lifecycleRouter']['queue/simple'] = array_values(array_filter($GLOBALS['lifecycleRouter']['queue/simple'],
        static fn($row) => ($row['.id'] ?? '') !== '*cap-child'));
    $due(); $queue->runSelected($review['jobKeys']);
    lifecycleCheck($queue->completed($review['jobKeys']) && $apiSqlite->selectAddress(9940) === null
        && !in_array($capName, array_column($GLOBALS['lifecycleRouter']['queue/simple'] ?? [], 'name'), true),
        'Removing the last cap dependency did not allow retirement to complete');
    $apiSqlite->saveConfig(['disabled_rate' => 0]);

    // A legacy terminal delete may outlive its billing event. Fresh active state
    // with a valid Site preserves the account and hands review to the fresh inbox.
    $resumed = $managedFixture(9950, 61950, 1, 'legacy-terminal-reactivated', 232);
    lifecycleCheck((new Batch())->set_accounts([$resumed]), 'Could not seed the legacy terminal fixture');
    $delete = $queue->appliedSnapshot(9950); $delete['_terminal'] = true; $delete['action'] = 'delete';
    $terminalKey = $queue->enqueue($delete, 'delete'); $beforeWrites = count($GLOBALS['lifecycleWrites']);
    $due(); $queue->runSelected([$terminalKey]);
    lifecycleCheck(!$queue->pending([$terminalKey]) && $queue->hasFreshReview(9950)
        && $apiSqlite->selectAddress(9950) === '10.42.0.232' && count($GLOBALS['lifecycleWrites']) === $beforeWrites
        && in_array('legacy-terminal-reactivated', array_column($GLOBALS['lifecycleRouter']['ppp/secret'] ?? [], 'name'), true)
        && $queue->hasUnreconciled(9950), 'An obsolete generic terminal delete erased a reactivated account or discarded its historical debt');

    // Empty Site is a deliberate detach only with the configured service
    // attribute confirmed by its catalog and ID; a mismatched ID is not absence.
    $detached = $managedFixture(9960, 61960, 1, 'deliberate-site-detach', 233);
    lifecycleCheck((new Batch())->set_accounts([$detached]), 'Could not seed the Site detach fixture');
    $delete = $queue->appliedSnapshot(9960); $delete['_terminal'] = true; $delete['action'] = 'delete';
    $terminalKey = $queue->enqueue($delete, 'delete');
    $sdk::$responses['clients/services/9960']->attributes[0]->value = null;
    $sdk::$responses['clients/services/9960']->attributes[0]->customAttributeId = 999;
    $beforeWrites = count($GLOBALS['lifecycleWrites']); $due(); $queue->runSelected([$terminalKey]);
    lifecycleCheck($queue->pending([$terminalKey]) && $apiSqlite->selectAddress(9960) === '10.42.0.233'
        && count($GLOBALS['lifecycleWrites']) === $beforeWrites, 'A mismatched Site attribute ID authorized a detach');
    $sdk::$responses['clients/services/9960']->attributes[0]->customAttributeId = 1;
    $due(); $queue->runSelected([$terminalKey]);
    lifecycleCheck($queue->completed([$terminalKey]) && $apiSqlite->selectAddress(9960) === null
        && !in_array('deliberate-site-detach', array_column($GLOBALS['lifecycleRouter']['ppp/secret'] ?? [], 'name'), true),
        'A confirmed empty Site no longer retired the previously provisioned router account');

    // A new, explicitly marked billing deletion cannot be reinterpreted as a
    // Site detach after UISP has reactivated the service in the meantime.
    $billingResumed = $managedFixture(9970, 61970, 1, 'billing-terminal-reactivated', 234);
    lifecycleCheck((new Batch())->set_accounts([$billingResumed]), 'Could not seed the marked billing fixture');
    $delete = $queue->appliedSnapshot(9970); $delete['_terminal'] = true; $delete['_lifecycle_billing'] = true; $delete['action'] = 'delete';
    $terminalKey = $queue->enqueue($delete, 'delete');
    $sdk::$responses['clients/services/9970']->attributes[0]->value = null;
    $beforeWrites = count($GLOBALS['lifecycleWrites']); $due(); $queue->runSelected([$terminalKey]);
    lifecycleCheck(!$queue->pending([$terminalKey]) && $queue->hasFreshReview(9970)
        && $apiSqlite->selectAddress(9970) === '10.42.0.234' && count($GLOBALS['lifecycleWrites']) === $beforeWrites
        && in_array('billing-terminal-reactivated', array_column($GLOBALS['lifecycleRouter']['ppp/secret'] ?? [], 'name'), true),
        'A reactivated billing deletion was reinterpreted as permission to perform another cleanup');

    // Reopening the durable queue and refreshing desired rows cannot resurrect
    // retired occupancy or executable cleanup behind a current client's work.
    $pendingBeforeRestart = array_keys($queue->list());
    $queue->close(); $apiJobQueue = $queue = new ApiJobQueue('data/jobs.db', 'data/queue.json');
    $queueDatabase->getValue($queue)->exec('PRAGMA synchronous=OFF');
    $apiSqliteCache->insert(['id' => 10000, 'status' => 2], 'services', INSERT_REPLACE);
    foreach ($queue->reservedAddresses() as $reservation)
        lifecycleCheck((int) $reservation['id'] < 10000 || (int) $reservation['id'] >= 30000, 'Retired IPv4 returned after restart');
    lifecycleCheck(array_keys($queue->list()) === $pendingBeforeRestart && $apiSqlite->selectAddress(30000) === '10.42.0.1'
        && $apiSqlite->selectAddress(9807) === '10.42.0.220', 'Restart disturbed current service ownership');
    $report = (new ApiPoolCapacity())->read();
    lifecycleCheck($report['available'] && strpos(json_encode($report), 'PRIVATE-') === false, 'Lifecycle capacity leaked private evidence');
    lifecycleCheck($sdk::$patches === [], 'Lifecycle retirement wrote UISP instead of using its current state');

    echo "Service lifecycle regression passed: {$bulkCount} IPv4/IPv6 transfers, terminal/archive cleanup, Q989 isolation, suspension/unknown protection, reactivation, retained retry, automatic conflict repair, transferred/manual account guards, concurrent transfer, surviving session, dependent cap, legacy/billing terminal review, validated Site detach, restart and capacity.\n";
} finally {
    if (isset($jobsDatabase)) $jobsDatabase->close();
    if (isset($queue)) $queue->close(); if (isset($apiJobQueue) && $apiJobQueue !== ($queue ?? null)) $apiJobQueue->close();
    if (isset($apiSqlite)) $apiSqlite->close(); if (isset($apiSqliteCache)) $apiSqliteCache->close();
    chdir($original); set_include_path($originalInclude);
    $resolved = realpath($directory); $temporaryRoot = realpath(sys_get_temp_dir());
    if ($resolved && $temporaryRoot && strpos($resolved, $temporaryRoot . DIRECTORY_SEPARATOR . 'uisp-service-lifecycle-') === 0) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($resolved);
    }
}
