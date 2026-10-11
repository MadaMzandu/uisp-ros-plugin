<?php
// MT executes its real ownership, preflight and API-word construction against an isolated RAM router.
require_once __DIR__ . '/../src/lib/api_sqlite.php';
require_once __DIR__ . '/../src/lib/api_ip.php';
require_once __DIR__ . '/../src/lib/mt.php';
require_once __DIR__ . '/../src/lib/mt_data.php';
class ReconnectLog {
    public array $entries = [];
    public bool $failProfileDiagnostic = false;
    public function Append($message, $level = 0): void {
        if ($this->failProfileDiagnostic && is_array($message) && ($message['event'] ?? null) === 'router_session_profile_error')
            throw new RuntimeException('Fixture logger unavailable');
        $this->entries[] = [$message, $level];
    }
}
function MyLog() { static $log; return $log ??= new ReconnectLog(); }
function checkReconnect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$apiSqlite = new ApiSqlite(':memory:');
$apiSqlite->exec(file_get_contents(__DIR__ . '/../src/includes/schema.sql'));
class ReconnectAPI extends RouterosAPI {
    public array $sentences = [];
    private array $pending = [];
    private $apply;
    public function __construct(callable $apply) { $this->apply = $apply; }
    public function write($word, $param2 = true) { $this->pending[] = $word; return true; }
    public function finishSentence(): void {
        $this->sentences[] = $this->pending;
        ($this->apply)($this->pending);
        $this->pending = [];
    }
    public function read($parse = true) { return []; }
    public function disconnect() { $this->connected = false; }
}
class ReconnectMT extends MT {
    public array $catalogue = [];
    public array $failReads = [];
    public array $throwReads = [];
    public array $lookups = [];
    public bool $ignoreProfileAdds = false;
    public bool $corruptCreatedProfile = false;
    public bool $failAccountAfterCreate = false;
    public bool $failReadbackAfterCreate = false;
    public bool $profileDisappearsAfterRead = false;
    public ReconnectAPI $recording;
    public function __construct() {
        parent::__construct();
        $this->recording = new ReconnectAPI(function (array $words): void {
            $path = trim(array_shift($words), '/');
            $separator = strrpos($path, '/');
            $action = substr($path, $separator + 1); $path = substr($path, 0, $separator);
            $values = [];
            foreach ($words as $word) {
                if (strpos($word, '=') !== 0) continue;
                [$key, $value] = explode('=', substr($word, 1), 2); $values[$key] = $value;
            }
            if ($action === 'set') {
                foreach ($this->catalogue[$path] ?? [] as $index => $row) {
                    if (($row['.id'] ?? null) === ($values['.id'] ?? null)) $this->catalogue[$path][$index] = array_replace($row, $values);
                }
            } elseif ($action === 'add') {
                if (in_array($path,['ppp/profile','ip/hotspot/user/profile'],true) && $this->ignoreProfileAdds) return;
                if (in_array($path,['ppp/profile','ip/hotspot/user/profile'],true) && $this->corruptCreatedProfile) $values['address-list']='wrong-list';
                $this->catalogue[$path][] = ['.id' => '*new-' . count($this->recording->sentences)] + $values;
                if (in_array($path,['ppp/profile','ip/hotspot/user/profile'],true) && $this->failAccountAfterCreate)
                    $this->throwReads[]=$path==='ppp/profile' ? 'ppp/secret' : 'ip/hotspot/user';
                if (in_array($path,['ppp/profile','ip/hotspot/user/profile'],true) && $this->failReadbackAfterCreate)
                    $this->failReads[]=$path;
            } elseif ($action === 'remove') {
                $ids = explode(',', $values['.id'] ?? '');
                $this->catalogue[$path] = array_values(array_filter($this->catalogue[$path] ?? [], static fn(array $row): bool => !in_array($row['.id'] ?? null, $ids, true)));
            }
        });
    }
    protected function api_connect(): ?RouterosAPI { $this->recording->connected = true; return $this->recording; }
    protected function read($path, $filter = null) {
        $path = trim($path, '/'); $this->lookups[] = [$path, $filter];
        if (in_array($path, $this->throwReads, true)) throw new RuntimeException('Fixture socket exception with secret-token');
        if (in_array($path, $this->failReads, true)) return null;
        if (is_string($filter)) {
            $conditions = [];
            foreach (explode(',', $filter) as $item) { [$key, $value] = explode('=', ltrim($item, '?'), 2); $conditions[$key] = $value; }
            $filter = $conditions;
        }
        $records=array_values(array_filter($this->catalogue[$path] ?? [], static function (array $row) use ($filter): bool {
            foreach ((array) $filter as $key => $value) if ((string) ($row[$key] ?? '') !== (string) $value) return false;
            return true;
        }));
        if ($records && $this->profileDisappearsAfterRead && in_array($path,['ppp/profile','ip/hotspot/user/profile'],true)) {
            $this->profileDisappearsAfterRead=false;
            $this->catalogue[$path]=[];
        }
        return $records;
    }
    public function mutations(string $path): array {
        return array_values(array_filter($this->recording->sentences, static fn(array $sentence): bool => ($sentence[0] ?? '') === $path));
    }
}
function reconnectFixture(bool $hotspot = false): array {
    $router = new ReconnectMT();
    $principal = $hotspot ? '/ip/hotspot/user' : '/ppp/secret';
    $profilePath = $hotspot ? '/ip/hotspot/user/profile' : '/ppp/profile';
    $active = $hotspot ? '/ip/hotspot/active' : '/ppp/active';
    $profile = ['path' => $profilePath, 'name' => 'Fixture plan [UISP#1]', 'rate-limit' => '5M/25M 0/0 0/0 1/1 8 0/0',
        'parent-queue' => 'none', 'address-list' => '', 'comment' => 'uisp-ros-plugin:plan:1', '_owner' => 'uisp-ros-plugin:plan:1', 'batch' => 'service-91'];
    if (!$hotspot) $profile['local-address'] = null;
    $account = ['path' => $principal, 'name' => 'fixture-user', 'password' => ' fixture password ', 'profile' => $profile['name'],
        ($hotspot ? 'address' : 'remote-address') => '192.0.2.91', 'comment' => '70 - Fixture Client - 91',
        '_service_id' => 91, '_client_id' => 70, 'batch' => 'service-91'];
    if (!$hotspot) $account['caller-id'] = null;
    $disconnect = ['path' => $active, 'name' => $account['name'], 'action' => 'remove', '_service_id' => 91,
        '_client_id' => 70, 'batch' => 'service-91', '_reconnect_required' => false];
    $router->catalogue[trim($principal, '/')] = [['.id' => '*account'] + array_diff_key($account, array_fill_keys(['path', 'batch', '_service_id', '_client_id'], true))];
    $router->catalogue[trim($profilePath, '/')] = [['.id' => '*profile', 'local-address' => '192.0.2.1']
        + array_diff_key($profile, array_fill_keys(['path', 'batch', '_owner', 'local-address'], true))];
    $router->catalogue[trim($active, '/')] = [['.id' => '*session', ($hotspot ? 'user' : 'name') => $account['name']]];
    $router->catalogue['ip/address'] = [['.id' => '*address', 'address' => '192.0.2.1/24', 'disabled' => 'false', 'dynamic' => 'false', 'invalid' => 'false']];
    return [$router, [$profile, $account, $disconnect], $active];
}
foreach ([false, true] as $hotspot) {
    [$router, $commands, $active] = reconnectFixture($hotspot);
    for ($sync = 0; $sync < 2; $sync++) {
        checkReconnect($router->do_batch((object) [], $commands) === 3, 'An unchanged synchronization did not complete');
        checkReconnect($router->mutations($active . '/remove') === [], 'An unchanged PPP/Hotspot synchronization disconnected a session');
    }
    foreach ($router->recording->sentences as $sentence) foreach ($sentence as $word) {
        checkReconnect(strpos($word, '=_reconnect_required=') !== 0, 'Reconnect metadata reached RouterOS');
    }
    if ($hotspot) checkReconnect(in_array(['ip/hotspot/active', ['user' => 'fixture-user']], $router->lookups, true), 'Hotspot active lookup used name instead of user');
}
foreach (['password', 'remote-address', 'profile', 'rate-limit', 'local-address'] as $field) {
    [$router, $commands, $active] = reconnectFixture();
    if ($field === 'password') $router->catalogue['ppp/secret'][0][$field] = 'different-password';
    elseif ($field === 'remote-address') $router->catalogue['ppp/secret'][0][$field] = '192.0.2.92';
    elseif ($field === 'profile') $router->catalogue['ppp/secret'][0][$field] = 'different-profile';
    elseif ($field === 'rate-limit') $router->catalogue['ppp/profile'][0][$field] = '1M/2M';
    else $router->catalogue['ppp/profile'][0][$field] = '192.0.2.2';
    $persisted = null;
    checkReconnect($router->do_batch((object) [], $commands, function (array $prepared) use ($router, &$persisted): void {
        checkReconnect($router->recording->sentences === [], 'A router mutation happened before reconnect persistence');
        checkReconnect($prepared[2]['_reconnect_required'] === true, 'Runtime drift did not elevate its reconnect flag');
        $persisted = $prepared;
    }) === 3, 'Runtime drift synchronization failed: ' . $field);
    checkReconnect($persisted !== null && count($router->mutations($active . '/remove')) === 1, 'Runtime drift did not disconnect exactly once: ' . $field);
    // A retry must retain the original reconnect intent even when fields now already match.
    $router->catalogue['ppp/active'] = [['.id' => '*reconnected', 'name' => 'fixture-user']];
    checkReconnect($router->do_batch((object) [], $persisted) === 3 && count($router->mutations($active . '/remove')) === 2,
        'A prepared retry lost the persisted reconnect decision: ' . $field);
}
// A job already requiring reconnection still reports a profile repair before its first mutation.
foreach ([false, true] as $hotspot) {
    [$router, $commands, $active] = reconnectFixture($hotspot);
    $profilePath = $hotspot ? 'ip/hotspot/user/profile' : 'ppp/profile';
    $router->catalogue[$profilePath][0]['rate-limit'] = '1M/2M';
    $commands[2]['_reconnect_required'] = true;
    $commands[0]['_profile_revision'] = 3; // Internal metadata must also be excluded from set/add words.
    $called = false;
    checkReconnect($router->do_batch((object) [], $commands, function (array &$prepared) use ($router, &$called): void {
        checkReconnect($router->recording->sentences === [] && $prepared[2]['_reconnect_required'] === true
            && ($prepared[2]['_profile_repair'] ?? null) === true, 'An explicit reconnect failed to report shared profile repair before writes');
        unset($prepared[2]['_profile_repair']); $prepared[2]['_profile_revision'] = 7; $called = true;
    }) === 3 && $called && count($router->mutations($active . '/remove')) === 1, 'An explicit reconnect did not persist its shared profile repair');
    foreach ($router->recording->sentences as $sentence) foreach ($sentence as $word) {
        checkReconnect(strpos($word, '=_profile_repair=') !== 0 && strpos($word, '=_profile_revision=') !== 0,
            'Shared profile repair/revision metadata reached RouterOS');
    }
}
foreach (['missing-profile', 'missing-account-with-profile-drift'] as $absence) {
    [$router, $commands, $active] = reconnectFixture(); $commands[2]['_reconnect_required'] = true;
    if ($absence === 'missing-profile') $router->catalogue['ppp/profile'] = [];
    else { $router->catalogue['ppp/secret'] = []; $router->catalogue['ppp/profile'][0]['rate-limit'] = '1M/2M'; }
    $called = false;
    checkReconnect($router->do_batch((object) [], $commands, function (array &$prepared) use (&$called): void {
        checkReconnect(($prepared[2]['_profile_repair'] ?? null) === true, 'A missing resource masked the shared profile repair');
        unset($prepared[2]['_profile_repair']); $prepared[2]['_profile_revision'] = 8; $called = true;
    }) === 3 && $called, 'Missing resource repair did not reach the durable callback: ' . $absence);
}
[$router, $commands, $active] = reconnectFixture();
$router->catalogue['ppp/profile'][0]['rate-limit'] = '5000000/25000000 0/0 0/0 1/1 8 0/0';
$router->catalogue['ppp/secret'][0]['comment'] = '70 - Renamed display label - 91';
$router->catalogue['ppp/secret'][0]['service'] = 'pppoe';
checkReconnect($router->do_batch((object) [], $commands) === 3 && $router->mutations($active . '/remove') === [],
    'Rate-unit equivalents, labels or unrelated RouterOS fields caused a reconnection');
[$router, $commands, $active] = reconnectFixture();
$router->catalogue['ppp/profile'][0]['name'] = 'Old display label';
$router->catalogue['ppp/secret'][0]['profile'] = 'Old display label';
checkReconnect($router->do_batch((object) [], $commands) === 3 && $router->mutations($active . '/remove') === [], 'Renaming the same owned profile caused a reconnection');
[$router, $commands, $active] = reconnectFixture();
$router->catalogue['ip/address'] = [];
$router->catalogue['ppp/profile'][0]['local-address'] = '169.254.12.34';
for ($sync = 0; $sync < 2; $sync++) checkReconnect($router->do_batch((object) [], $commands) === 3, 'Stable owned local fallback failed');
checkReconnect($router->mutations($active . '/remove') === [] && $router->catalogue['ppp/profile'][0]['local-address'] === '169.254.12.34',
    'An empty router address inventory randomized an existing owned local address');
[$router, $commands, $active] = reconnectFixture();
$router->catalogue['ip/address'][0]['address'] = '198.51.100.1/24';
checkReconnect($router->do_batch((object) [], $commands, function (array $prepared) use ($router): void {
    checkReconnect($prepared[2]['_reconnect_required'] === true, 'A real static local-address change was ignored');
    $router->catalogue['ip/address'][0]['address'] = '198.51.100.2/24';
}) === 3 && $router->catalogue['ppp/profile'][0]['local-address'] === '198.51.100.1',
    'Local-address preflight and the actual write resolved different values');
[$router, $commands, $active] = reconnectFixture();
$router->catalogue['ppp/secret'][0]['password'] = 'different-password';
checkReconnect($router->do_batch((object) [], $commands, static function (array $prepared): void { throw new RuntimeException('Persistence fixture failed'); }) === 0
    && $router->recording->sentences === [], 'A persistence failure allowed a router mutation');
foreach (['ppp/secret', 'ppp/profile', 'ip/address'] as $failedPath) {
    [$router, $commands, $active] = reconnectFixture(); $router->failReads = [$failedPath];
    checkReconnect($router->do_batch((object) [], $commands) === 0 && $router->recording->sentences === [], 'A failed preflight read allowed writes: ' . $failedPath);
}
[$router, $commands, $active] = reconnectFixture();
$router->catalogue['ppp/secret'][0]['comment'] = '69 - Foreign Client - 90';
checkReconnect($router->do_batch((object) [], $commands) === 0 && $router->recording->sentences === [], 'A skipped reconnection bypassed account ownership');
[$router, $commands, $active] = reconnectFixture();
checkReconnect($router->do_batch((object) [], [$commands[0], $commands[2]]) === 0 && $router->recording->sentences === [], 'A missing desired account was treated as unchanged');
[$router, $commands, $active] = reconnectFixture();
unset($commands[2]['_reconnect_required']);
checkReconnect($router->do_batch((object) [], $commands) === 3 && count($router->mutations($active . '/remove')) === 1,
    'An unmarked legacy update stopped reconnecting conservatively');
[$router, $commands, $active] = reconnectFixture();
$commands[1]['action'] = 'remove'; unset($commands[2]['_reconnect_required']);
checkReconnect($router->do_batch((object) [], [$commands[1], $commands[2]]) === 2 && count($router->mutations($active . '/remove')) === 1,
    'Deleting a principal lost its mandatory session disconnection');
[$router, $commands, $active] = reconnectFixture(); $commands[2]['_reconnect_required'] = true;
checkReconnect($router->do_batch((object) [], [$commands[2]]) === 1 && count($router->mutations($active . '/remove')) === 1,
    'An explicitly required active-only cleanup lost its owned session disconnection');
[$router, $commands, $active] = reconnectFixture(); $commands[2]['_reconnect_required'] = true;
$router->catalogue['ppp/secret'][0]['comment'] = '69 - Foreign Client - 90';
checkReconnect($router->do_batch((object) [], [$commands[2]]) === 0 && $router->recording->sentences === [],
    'An active-only required cleanup disconnected a foreign principal');
// A shared profile drift must be detected for every principal before the first profile write.
[$router, $commands, $active] = reconnectFixture();
$router->catalogue['ppp/profile'][0]['rate-limit'] = '1M/2M';
$secondAccount = $commands[1]; $secondAccount['name'] = 'second-user'; $secondAccount['batch'] = 'service-92';
$secondAccount['_service_id'] = 92; $secondAccount['comment'] = '70 - Fixture Client - 92';
$secondDisconnect = $commands[2]; $secondDisconnect['name'] = 'second-user'; $secondDisconnect['batch'] = 'service-92'; $secondDisconnect['_service_id'] = 92;
$router->catalogue['ppp/secret'][] = ['.id' => '*second'] + array_diff_key($secondAccount, array_fill_keys(['path', 'batch', '_service_id', '_client_id'], true));
$router->catalogue['ppp/active'][] = ['.id' => '*second-session', 'name' => 'second-user'];
$both = array_merge($commands, [$secondAccount, $secondDisconnect]);
checkReconnect($router->do_batch((object) [], $both, static function (array $prepared): void {
    checkReconnect($prepared[2]['_reconnect_required'] === true && $prepared[4]['_reconnect_required'] === true,
        'Shared profile drift did not elevate every account before writes');
}) === 5 && count($router->mutations($active . '/remove')) === 2, 'Shared profile changes left an unchanged-looking session connected');

// External profiles remain administrator-owned. Their exact protocol, name and lookup failure are diagnosable.
function externalProfileFixture(bool $hotspot = false, string $name = 'disabled'): array {
    [$router, $commands, $active] = reconnectFixture($hotspot);
    $commands[1]['profile'] = $name;
    $commands[2]['_reconnect_required'] = true;
    return [$router, [$commands[1], $commands[2]], $active, $hotspot ? 'ip/hotspot/user/profile' : 'ppp/profile'];
}
foreach ([false, true] as $hotspot) {
    [$router, $commands, $active, $profilePath] = externalProfileFixture($hotspot);
    $externalProfile = ['.id' => '*external', 'name' => 'disabled', 'comment' => 'Administrator profile'];
    $router->catalogue[$profilePath][] = $externalProfile;
    MyLog()->entries = [];
    checkReconnect($router->do_batch((object) ['id' => 1], $commands) === 2 && count($router->mutations($active . '/remove')) === 1,
        'A valid existing external profile was rejected: ' . $profilePath);
    checkReconnect(in_array([$profilePath, ['name' => 'disabled']], $router->lookups, true), 'The external profile used the wrong protocol path');
    checkReconnect($router->catalogue[$profilePath][1] === $externalProfile, 'The administrator-owned external profile was modified');
    checkReconnect(MyLog()->entries === [], 'A valid external profile generated a failure diagnostic');
}
foreach (['missing', 'wrong_protocol', 'trailing_space', 'lookup_failed', 'lookup_throw', 'ambiguous', 'invalid_identity', 'empty_identity', 'numeric_identity'] as $case) {
    $hotspot = $case === 'wrong_protocol';
    $profileName = $case === 'trailing_space' ? 'disabled ' : 'disabled';
    [$router, $commands, $active, $profilePath] = externalProfileFixture($hotspot, $profileName);
    $reason = 'missing';
    if ($case === 'wrong_protocol') $router->catalogue['ppp/profile'] = [['.id' => '*external', 'name' => 'disabled']];
    if ($case === 'trailing_space') $router->catalogue[$profilePath][] = ['.id' => '*external', 'name' => 'disabled'];
    if ($case === 'lookup_failed') { $router->failReads = [$profilePath]; $reason = 'lookup_failed'; }
    if ($case === 'lookup_throw') { $router->throwReads = [$profilePath]; $reason = 'lookup_failed'; }
    if ($case === 'ambiguous') {
        $router->catalogue[$profilePath] = [['.id' => '*one', 'name' => 'disabled'], ['.id' => '*two', 'name' => 'disabled']];
        $reason = 'ambiguous';
    }
    if (in_array($case, ['invalid_identity', 'empty_identity', 'numeric_identity'], true)) {
        $row = ['name' => 'disabled'];
        if ($case === 'empty_identity') $row['.id'] = '';
        if ($case === 'numeric_identity') $row['.id'] = 42;
        $router->catalogue[$profilePath] = [$row]; $reason = 'invalid_identity';
    }
    $before = $router->catalogue; MyLog()->entries = [];
    checkReconnect($router->do_batch((object) ['id' => 1], $commands) === 0 && $router->recording->sentences === []
        && $router->catalogue === $before, 'An unverifiable external profile allowed a mutation: ' . $case);
    $failure = $router->failed()['service-91'] ?? '';
    checkReconnect(strpos($failure, 'router_session_profile_' . $reason . ':') === 0
        && strpos($failure, 'profilePath=/' . $profilePath) !== false
        && strpos($failure, 'profileName="' . $profileName . '"') !== false, 'The job lost its precise external-profile diagnostic: ' . $case);
    $expected = ['event' => 'router_session_profile_error', 'serviceId' => 91, 'deviceId' => 1, 'protocol' => $hotspot ? 'hotspot' : 'ppp',
        'profilePath' => '/' . $profilePath, 'profileName' => $profileName, 'reason' => $reason];
    checkReconnect(MyLog()->entries === [[$expected, LOG_ERR]], 'The external-profile log lost safe structured context: ' . $case);
    foreach (['fixture-user', 'fixture password', '192.0.2.91', 'secret-token'] as $secret)
        checkReconnect(strpos($failure . json_encode(MyLog()->entries), $secret) === false, 'An external-profile diagnostic exposed account/exception data');
}
foreach (["disabled\r\n\x1b" . str_repeat('x', 400), "disabled\u{2028}\u{202E}", "disabled\xFF"] as $unsafeName) {
    [$router, $commands] = externalProfileFixture(false, $unsafeName); MyLog()->entries = [];
    checkReconnect($router->do_batch((object) ['id' => 1], $commands) === 0 && $router->recording->sentences === [],
        'A malformed profile name permitted writes');
    $failure = $router->failed()['service-91'] ?? '';
    $diagnostic = MyLog()->entries[0][0] ?? [];
    $safeName = $diagnostic['profileName'] ?? '';
    checkReconnect(strpos($failure, 'router_session_profile_missing:') === 0 && $safeName !== '' && strlen($safeName) <= 163
        && preg_match('/[\p{C}\x{2028}\x{2029}]/u', $safeName) === 0
        && preg_match('/[\p{C}\x{2028}\x{2029}]/u', $failure) === 0
        && json_encode($diagnostic) !== false, 'An unsafe profile name reached the job/log without bounded sanitization');
    checkReconnect(in_array(['ppp/profile', ['name' => $unsafeName]], $router->lookups, true), 'Diagnostic sanitization changed the actual profile lookup');
}
[$router, $commands] = externalProfileFixture(); MyLog()->failProfileDiagnostic = true;
checkReconnect($router->do_batch((object) ['id' => 1], $commands) === 0 && $router->recording->sentences === []
    && strpos($router->failed()['service-91'] ?? '', 'router_session_profile_missing:') === 0,
    'A logger failure replaced the stable profile failure or allowed a write');
MyLog()->failProfileDiagnostic = false;
// Missing suspension profiles are bootstrapped from frozen configuration, not adopted or overwritten later.
function suspensionBootstrapFixture(bool $hotspot=false,array $configuration=[]): array {
    [$router,$commands,$active,$profilePath]=externalProfileFixture($hotspot);
    $service=['id'=>91,'clientId'=>70,'device'=>1,'planId'=>1,'status'=>3,'username'=>'fixture-user',
        'hotspot'=>$hotspot ? 1 : null,'batch'=>'service-91','_plan'=>['id'=>1,'name'=>'Fixture plan'],
        '_config'=>array_replace(['disabled_profile'=>'disabled','disabled_list'=>'cut-list','disabled_rate'=>0],$configuration)];
    $router->catalogue[$profilePath]=[];
    $prepared=MtData::safePreparedCommands($service,$commands);
    foreach($prepared as &$command) if(trim($command['path'],'/')===trim($active,'/')) $command['_reconnect_required']=false;
    unset($command);
    return [$router,$prepared,$active,$profilePath,$service];
}
foreach ([false,true] as $hotspot) {
    [$router,$commands,$active,$profilePath,$service]=suspensionBootstrapFixture($hotspot);
    $frozen=null;
    checkReconnect($router->do_batch((object)['id'=>1],$commands,function(array $prepared) use ($router,&$frozen,$hotspot): void {
        checkReconnect($router->recording->sentences===[], 'Suspension bootstrap wrote before prepared-state persistence');
        checkReconnect(($prepared[0]['_bootstrap_required'] ?? false)===true, 'Missing profile was not marked as a durable bootstrap debt');
        if (!$hotspot) checkReconnect(($prepared[0]['local-address'] ?? null)==='192.0.2.1', 'PPP local address was not frozen before creation');
        $frozen=$prepared;
    })===count($commands), 'An empty suspension profile catalogue could not be bootstrapped');
    $profile=$router->catalogue[$profilePath][0] ?? [];
    checkReconnect(($profile['name'] ?? null)==='disabled' && ($profile['address-list'] ?? null)==='cut-list'
        && ($profile['comment'] ?? null)==='Suspendidos', 'Bootstrap profile omitted its configured dynamic list or friendly comment');
    checkReconnect(($router->recording->sentences[0][0] ?? null)==='/' . $profilePath . '/add'
        && count($router->mutations($active . '/remove'))===1, 'Profile creation did not precede the account and reconnect');
    if ($hotspot) checkReconnect(!isset($profile['local-address'],$profile['parent-queue']) && $profile['address-pool']==='none', 'Hotspot bootstrap used PPP-only fields');
    else checkReconnect($profile['local-address']==='192.0.2.1' && $profile['parent-queue']==='none', 'PPP bootstrap did not provide a usable local address');
    foreach($router->recording->sentences as $sentence) foreach($sentence as $word)
        checkReconnect(strpos($word,'=_')!==0 && ($sentence[0] ?? '')!=='/ip/firewall/address-list/add', 'Bootstrap sent private metadata or a placeholder list address');
    $profileAdds=count($router->mutations('/' . $profilePath . '/add'));
    checkReconnect($frozen!==null && $router->do_batch((object)['id'=>1],$frozen)===count($frozen)
        && count($router->mutations('/' . $profilePath . '/add'))===$profileAdds && $router->catalogue[$profilePath][0]===$profile,
        'Retry duplicated or modified a verified suspension profile');
    // A preexisting custom profile wins even when plugin bootstrap settings differ.
    [$router,$commands,$active,$profilePath]=suspensionBootstrapFixture($hotspot,['disabled_list'=>'','disabled_rate'=>0]);
    $custom=['.id'=>'*admin','name'=>'disabled','address-list'=>'admin-list','rate-limit'=>'9M/9M','comment'=>'Custom administrator'];
    $router->catalogue[$profilePath]=[$custom];
    checkReconnect($router->do_batch((object)['id'=>1],$commands)===count($commands)
        && $router->catalogue[$profilePath]===[$custom] && $router->mutations('/' . $profilePath . '/add')===[],
        'Bootstrap altered an administrator-owned profile or rejected its independently configured policy');
    [$router,$commands,$active,$profilePath]=suspensionBootstrapFixture($hotspot);
    $router->failReads=[$profilePath];
    checkReconnect($router->do_batch((object)['id'=>1],$commands)===0 && $router->recording->sentences===[],
        'A failed external profile lookup was interpreted as permission to create');
}
foreach ([['disabled_list'=>'','disabled_rate'=>0],['disabled_profile'=>'default'],['disabled_profile'=>'default-encryption']] as $unsafeConfig) {
    [$router,$commands]=suspensionBootstrapFixture(false,$unsafeConfig);
    if (isset($unsafeConfig['disabled_profile'])) foreach($commands as &$command)
        if(trim($command['path'],'/')==='ppp/secret') $command['profile']=$unsafeConfig['disabled_profile'];
    unset($command);
    checkReconnect($router->do_batch((object)['id'=>1],$commands)===0 && $router->recording->sentences===[],
        'Bootstrap created a reserved or policy-empty suspension profile');
}
foreach (['corrupt','lost_readback','lost_account'] as $fault) {
    [$router,$commands,$active,$profilePath,$service]=suspensionBootstrapFixture(); $frozen=null;
    $router->corruptCreatedProfile=$fault==='corrupt';
    $router->failReadbackAfterCreate=$fault==='lost_readback';
    $router->failAccountAfterCreate=$fault==='lost_account';
    checkReconnect($router->do_batch((object)['id'=>1],$commands,function(array $prepared) use (&$frozen): void {$frozen=$prepared;})<count($commands)
        && count($router->catalogue[$profilePath])===1 && $router->mutations($active . '/remove')===[],
        'Partial bootstrap failure was acknowledged or disconnected the account');
    checkReconnect($frozen!==null && ($frozen[0]['_bootstrap_required'] ?? false)===true, 'Partial bootstrap lost its durable debt');
    $router->corruptCreatedProfile=$router->failReadbackAfterCreate=$router->failAccountAfterCreate=false;
    $router->failReads=$router->throwReads=[];
    $router->catalogue['ip/address'][0]['address']='198.51.100.1/24';
    $retry=MtData::safePreparedCommands($service,$frozen);
    checkReconnect(($retry[0]['_bootstrap_required'] ?? false)===true && $retry[0]['local-address']==='192.0.2.1',
        'Command migration regenerated unresolved local configuration or lost bootstrap debt');
    if ($fault==='corrupt') {
        $before=count($router->recording->sentences);
        checkReconnect($router->do_batch((object)['id'=>1],$retry)===0 && count($router->recording->sentences)===$before,
            'A partially created but incorrect profile was adopted on retry');
        $router->catalogue[$profilePath][0]['address-list']='cut-list';
    }
    checkReconnect($router->do_batch((object)['id'=>1],$retry)===count($retry)
        && count($router->mutations('/' . $profilePath . '/add'))===1
        && $router->catalogue[$profilePath][0]['local-address']==='192.0.2.1'
        && count($router->mutations($active . '/remove'))===1,
        'Verified partial bootstrap could not recover without duplicating its profile or changing frozen local address');
}
[$router,$commands,$active,$profilePath,$service]=suspensionBootstrapFixture(); $frozen=null;
$router->catalogue[$profilePath]=[['.id'=>'*admin','name'=>'disabled','address-list'=>'cut-list']];
$router->profileDisappearsAfterRead=true;
checkReconnect($router->do_batch((object)['id'=>1],$commands,function(array $prepared) use (&$frozen): void {$frozen=$prepared;})===0
    && $router->recording->sentences===[] && $router->catalogue[$profilePath]===[],
    'A profile disappearing after preflight was created without a durable decision');
$retry=MtData::safePreparedCommands($service,$frozen ?? $commands); $router->corruptCreatedProfile=true;
checkReconnect($router->do_batch((object)['id'=>1],$retry,function(array $prepared) use (&$frozen): void {$frozen=$prepared;})===0
    && ($frozen[0]['_bootstrap_required'] ?? false)===true
    && count($router->mutations('/' . $profilePath . '/add'))===1
    && $router->mutations('/ppp/secret/set')===[] && $router->mutations($active . '/remove')===[],
    'Retry after a profile disappeared did not freeze creation debt before a partial add');
$router->corruptCreatedProfile=false; $before=count($router->recording->sentences);
checkReconnect($router->do_batch((object)['id'=>1],MtData::safePreparedCommands($service,$frozen))===0
    && count($router->recording->sentences)===$before,
    'A partial profile from the disappearance retry was adopted without verification');
[$router,$commands,$active,$profilePath]=suspensionBootstrapFixture(); $router->ignoreProfileAdds=true;
checkReconnect($router->do_batch((object)['id'=>1],$commands)===0 && $router->mutations('/ppp/secret/set')===[]
    && $router->mutations($active . '/remove')===[], 'A successful API acknowledgement without profile creation was trusted');
echo "Reconnect RouterOS regression passed: repeated unchanged sync, drift preflight, durable callback, conservative retries/deletes, PPP/Hotspot ownership, shared profiles, stable local addresses and safe external-profile diagnostics.\n";
$apiSqlite->close();
