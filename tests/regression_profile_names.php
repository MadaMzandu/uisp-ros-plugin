<?php
// Exercise the real profile naming and RouterOS write logic without a router.
require_once __DIR__ . '/../src/lib/mt_data.php';
require_once __DIR__ . '/../src/lib/mt.php';
$apiSqlite=new ApiSqlite(':memory:');
$apiSqlite->exec(file_get_contents(__DIR__.'/../src/includes/schema.sql'));

function checkProfileNames(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
class ProfileNamesLog { public function Append($value, $level = 0): void {} }
function MyLog() { static $log; return $log ??= new ProfileNamesLog(); }

class ProfileNamesAPI extends RouterosAPI {
    public array $words = [];
    public array $sentences = [];
    public array $pending = [];
    public $afterRead;
    public function write($word, $param2 = true) { $this->words[] = $word; $this->pending[] = $word; return true; }
    public function finishSentence(): void { $this->sentences[] = $this->pending; $this->pending = []; }
    public function read($parse = true) { if ($this->afterRead) ($this->afterRead)(end($this->sentences)); return []; }
    public function disconnect() { $this->connected = false; }
}
class ProfileNamesMT extends MT {
    public array $catalogue = [];
    public ProfileNamesAPI $recording;
    public function __construct() {
        parent::__construct();
        $this->recording = new ProfileNamesAPI();
        $this->recording->afterRead = function ($words): void {
            if (!$words || !preg_match('#^/(.+)/(set|add|remove)$#D', $words[0], $match)) return;
            $values = [];
            foreach (array_slice($words, 1) as $word) {
                if (preg_match('/^=([^=]+)=(.*)$/Ds', $word, $property)) $values[$property[1]] = $property[2];
            }
            $path = $match[1];
            if ($match[2] === 'add') {
                $values['.id'] = '*added-' . (count($this->catalogue[$path] ?? []) + 1);
                $this->catalogue[$path][] = $values;
                return;
            }
            foreach ($this->catalogue[$path] ?? [] as $index => $row) {
                if (($row['.id'] ?? null) !== ($values['.id'] ?? null)) continue;
                if ($match[2] === 'remove') unset($this->catalogue[$path][$index]);
                else $this->catalogue[$path][$index] = array_replace($row, $values);
            }
            $this->catalogue[$path] = array_values($this->catalogue[$path] ?? []);
        };
    }
    protected function api_connect(): ?RouterosAPI { $this->recording->connected = true; return $this->recording; }
    protected function read($path, $filter = null) {
        $rows = $this->catalogue[trim($path, '/')] ?? [];
        if (!$filter) return $rows;
        if (is_string($filter)) {
            if (!preg_match('/^\?([^=]+)=(.*)$/Ds', $filter, $match)) throw new RuntimeException('Invalid fixture filter');
            $filter = [$match[1] => $match[2]];
        }
        return array_values(array_filter($rows, static function ($row) use ($filter): bool {
            foreach ($filter as $key => $value) if (($row[$key] ?? null) !== $value) return false;
            return true;
        }));
    }
    public function execute(array $command) { return $this->write($command); }
}

$planName = 'Plan Comercial - Premium [10Mb/2Mb]';
$readable = $planName . ' [UISP#35]';
checkProfileNames(MtData::managedProfileName(35, 1353, $planName) === $readable, 'The full UISP plan name was not retained');
checkProfileNames(MtData::managedProfileName(38, 1354, $planName) !== $readable, 'Equal plan names collide across different IDs');
checkProfileNames(MtData::managedProfileName(35, 1353) === 'uisp-plan-35', 'Nameless historical snapshots lost their stable fallback');
checkProfileNames(MtData::managedProfileName(-1, 1353, $planName) === 'uisp-service-1353-profile', 'Synthetic plans acquired shared profile identities');
checkProfileNames(MtData::managedProfileName(35, 1353, "  Fibra\nResidencial\tÁgil  ") === 'Fibra Residencial Ágil [UISP#35]', 'Readable names contain control characters or lose accented text');

$service = ['id' => 1353, 'clientId' => 42, 'planId' => 35, 'status' => 1,
    '_plan' => ['id' => 35, 'name' => $planName]];
$old = [['path' => '/ppp/profile', 'name' => 'uisp-plan-35', 'rate-limit' => '2M/10M',
    'comment' => 'uisp-ros-plugin:plan:35', '_owner' => 'uisp-ros-plugin:plan:35'],
    ['path' => '/ppp/secret', 'name' => 'test-client', 'profile' => 'uisp-plan-35', 'remote-address' => '192.0.2.53']];
$safe = MtData::safePreparedCommands($service, $old);
$profile = array_values(array_filter($safe, static fn($item) => trim($item['path'], '/') === 'ppp/profile'))[0];
$account = array_values(array_filter($safe, static fn($item) => trim($item['path'], '/') === 'ppp/secret'))[0];
checkProfileNames($profile['name'] === $readable && $account['profile'] === $readable
    && $profile['rate-limit'] === '2M/10M' && $account['remote-address'] === '192.0.2.53', 'Pending jobs lose account addresses or rate limits during the readable-name migration');
foreach ($old as &$command) $command['action'] = 'remove';
unset($command);
$deletion = MtData::safePreparedCommands($service, $old, 'delete');
$deletedProfile = array_values(array_filter($deletion, static fn($item) => trim($item['path'], '/') === 'ppp/profile'))[0];
checkProfileNames($deletedProfile['name'] === 'uisp-plan-35', 'Historical deletions were redirected to a replacement profile');

foreach (['ppp/profile', 'ip/hotspot/user/profile'] as $path) {
    $router = new ProfileNamesMT();
    $owner = 'uisp-ros-plugin:plan:35';
    $router->catalogue[$path] = [['.id' => '*owned', 'name' => 'uisp-plan-35', 'comment' => $owner],
        ['.id' => '*legacy', 'name' => $planName, 'comment' => 'administrator profile']];
    $command = ['path' => '/' . $path, 'name' => $readable, 'rate-limit' => '2M/10M', 'comment' => $owner, '_owner' => $owner];
    $router->execute($command);
    checkProfileNames(count($router->catalogue[$path]) === 2 && $router->catalogue[$path][0]['.id'] === '*owned'
        && $router->catalogue[$path][0]['name'] === $readable, 'Migration created a duplicate profile instead of renaming its existing RouterOS identity: ' . $path);
    checkProfileNames($router->catalogue[$path][1]['name'] === $planName, 'A legacy administrator profile was renamed: ' . $path);
    checkProfileNames($router->catalogue[$path][0]['comment'] === 'Plan #35', 'The visible profile comment still contains internal ownership branding');
    $router->execute($command);
    $renamed = 'Plan Comercial - Fibra 20M [UISP#35]';
    $command['name'] = $renamed;
    $router->execute($command);
    checkProfileNames(count($router->catalogue[$path]) === 2 && $router->catalogue[$path][0]['name'] === $renamed
        && !in_array('/' . $path . '/add', $router->recording->words, true), 'Retry or later plan rename created an extra profile: ' . $path);
    foreach ($router->recording->words as $word) checkProfileNames(strpos($word, '=_owner=') !== 0, 'Ownership metadata was sent to RouterOS');
    $router->recording->words = [];
    $router->execute(['path' => '/' . $path, 'name' => 'uisp-plan-35', 'action' => 'remove', '_owner' => $owner]);
    checkProfileNames($router->recording->words === [] && count($router->catalogue[$path]) === 2, 'An obsolete delete removed the newly renamed managed profile');
    $router->catalogue[$path][] = ['.id' => '*foreign', 'name' => $readable, 'comment' => 'manual'];
    $command['name'] = $readable;
    $rejected = false;
    try { $router->execute($command); } catch (Throwable $error) { $rejected = true; }
    checkProfileNames($rejected && $router->recording->words === [], 'A foreign profile occupying the destination name was overwritten');
    $router->catalogue[$path] = [['.id' => '*owned1', 'name' => 'uisp-plan-35', 'comment' => $owner],
        ['.id' => '*owned2', 'name' => 'other [UISP#35]', 'comment' => $owner]];
    $rejected = false;
    try { $router->execute($command); } catch (Throwable $error) { $rejected = true; }
    checkProfileNames($rejected && $router->recording->words === [], 'An ambiguous owner chose an arbitrary profile to rename');
    $router->catalogue[$path] = [['.id'=>'*old','name'=>'uisp-plan-35','comment'=>$owner],
        ['.id'=>'*new','name'=>'another [UISP#35]','comment'=>'Plan #35']];
    $rejected = false;
    try { $router->execute($command); } catch (Throwable $error) { $rejected = true; }
    checkProfileNames($rejected && $router->recording->words === [], 'Mixed old and readable ownership selected an arbitrary profile');
    $router->catalogue[$path] = [['.id'=>'*manual','name'=>'Manual profile','comment'=>'Plan #35']];
    $router->execute($command);
    checkProfileNames(count($router->catalogue[$path])===2 && $router->catalogue[$path][0]['name']==='Manual profile',
        'A generic administrator comment was adopted without the reserved name');
    $router->catalogue[$path] = [['.id' => '*legacy', 'name' => $planName, 'comment' => 'administrator profile']];
    $router->execute($command);
    checkProfileNames(count($router->catalogue[$path]) === 2 && $router->catalogue[$path][0]['name'] === $planName
        && in_array('/' . $path . '/add', $router->recording->words, true), 'First provisioning took ownership of an external human-readable profile');
    $unverified = new ProfileNamesMT();
    $unverified->catalogue[$path] = [['.id' => '*owned', 'name' => 'uisp-plan-35', 'comment' => $owner]];
    $unverified->recording->afterRead = static function ($words): void {};
    $command['batch'] = 'service-1353';
    $accountPath = $path === 'ppp/profile' ? '/ppp/secret' : '/ip/hotspot/user';
    $next = ['path' => $accountPath, 'name' => 'test-client', 'profile' => $readable,
        'comment' => '42 - Test Client - 1353', '_service_id' => 1353, '_client_id' => 42, 'batch' => 'service-1353'];
    checkProfileNames($unverified->do_batch((object)[], [$command, $next]) === 0
        && isset($unverified->failed()['service-1353'])
        && !in_array($accountPath . '/add', $unverified->recording->words, true), 'An unverified profile rename was marked successful or used by an account');
}

$privateService = $service;
$privateService['_plan']['_serviceQos'] = ['enabled' => true, 'serviceId' => 1353];
$privateOwner = 'uisp-ros-plugin:service-profile:1353';
$legacyPrivate = 'uisp-service-1353-profile';
$friendlyPrivate = $planName . ' [Override S#1353]';
$renamedPrivate = 'Plan Comercial - Fibra 35M [Override S#1353]';
checkProfileNames(MtData::managedProfileName(35, 1353, $planName, true) === $friendlyPrivate,
    'The service override did not keep its base plan label and immutable service marker');
foreach ([$legacyPrivate, $friendlyPrivate, $renamedPrivate] as $name)
    checkProfileNames(MtData::serviceProfileNameMatches($name, 1353), 'An owned private profile name was not recognized');
foreach (['Manual profile', ' [Override S#1353]', 'Other [Override S#1354]',
    $friendlyPrivate . ' suffix', "Unsafe\nPlan [Override S#1353]"] as $name)
    checkProfileNames(!MtData::serviceProfileNameMatches($name, 1353), 'An unrelated or unsafe profile name was accepted');
$frozenPrivate = [['path' => '/ppp/profile', 'name' => $legacyPrivate, 'rate-limit' => '10M/35M',
    'comment' => $privateOwner, '_owner' => $privateOwner],
    ['path' => '/ppp/secret', 'name' => 'private-client', 'profile' => $legacyPrivate,
        'remote-address' => '192.0.2.53', 'comment' => '42 - Test Client - 1353']];
$upgradedPrivate = MtData::safePreparedCommands($privateService, $frozenPrivate);
$upgradedProfile = array_values(array_filter($upgradedPrivate, static fn($item) => trim($item['path'], '/') === 'ppp/profile'))[0];
$upgradedAccount = array_values(array_filter($upgradedPrivate, static fn($item) => trim($item['path'], '/') === 'ppp/secret'))[0];
checkProfileNames($upgradedProfile['name'] === $friendlyPrivate && $upgradedAccount['profile'] === $friendlyPrivate
    && $upgradedProfile['rate-limit'] === '10M/35M' && $upgradedAccount['remote-address'] === '192.0.2.53'
    && $upgradedProfile['_owner'] === $privateOwner,
    'Readable private-profile migration changed frozen rates, addresses or ownership');
foreach ($frozenPrivate as &$command) $command['action'] = 'remove';
unset($command);
$deletedPrivate = MtData::safePreparedCommands($privateService, $frozenPrivate, 'delete');
$deletedProfile = array_values(array_filter($deletedPrivate, static fn($item) => trim($item['path'], '/') === 'ppp/profile'))[0];
$deletedAccount = array_values(array_filter($deletedPrivate, static fn($item) => trim($item['path'], '/') === 'ppp/secret'))[0];
checkProfileNames($deletedProfile['name'] === $legacyPrivate && $deletedAccount['profile'] === $legacyPrivate,
    'A historical private-profile deletion was redirected to the friendly replacement');

foreach (['ppp/profile', 'ip/hotspot/user/profile'] as $path) {
    $accountPath = $path === 'ppp/profile' ? 'ppp/secret' : 'ip/hotspot/user';
    foreach ([$privateOwner, 'Servicio #1353'] as $legacyComment) {
        $router = new ProfileNamesMT();
        $router->catalogue[$path] = [['.id' => '*private', 'name' => $legacyPrivate, 'comment' => $legacyComment,
            'rate-limit' => '10M/35M']];
        $command = ['path' => '/' . $path, 'name' => $friendlyPrivate, 'rate-limit' => '10M/35M',
            'comment' => $privateOwner, '_owner' => $privateOwner, '_internalFixture' => 'must-not-leak'];
        $router->execute($command);
        checkProfileNames(count($router->catalogue[$path]) === 1 && $router->catalogue[$path][0]['.id'] === '*private'
            && $router->catalogue[$path][0]['name'] === $friendlyPrivate
            && $router->catalogue[$path][0]['comment'] === 'Servicio #1353'
            && $router->catalogue[$path][0]['rate-limit'] === '10M/35M',
            'The owned private profile was duplicated, reidentified or relabelled during migration: ' . $path);
        $router->execute($command);
        $command['name'] = $renamedPrivate;
        $router->execute($command);
        checkProfileNames(count($router->catalogue[$path]) === 1 && $router->catalogue[$path][0]['.id'] === '*private'
            && $router->catalogue[$path][0]['name'] === $renamedPrivate
            && !in_array('/' . $path . '/add', $router->recording->words, true),
            'A private-profile retry or base plan rename created a different RouterOS identity: ' . $path);
        foreach ($router->recording->words as $word)
            checkProfileNames(preg_match('/^=_/', $word) !== 1, 'Internal private-profile metadata reached RouterOS');
        $router->recording->words = [];
        foreach ([$legacyPrivate, $friendlyPrivate] as $historicalName) {
            $router->execute(['path' => '/' . $path, 'name' => $historicalName, 'action' => 'remove', '_owner' => $privateOwner]);
        }
        checkProfileNames($router->recording->words === [] && count($router->catalogue[$path]) === 1,
            'A frozen private-profile deletion followed an owner to its renamed profile: ' . $path);
        $router->catalogue[$accountPath] = [['.id' => '*subscriber', 'name' => 'private-client', 'profile' => $renamedPrivate]];
        $remove = ['path' => '/' . $path, 'name' => $renamedPrivate, 'action' => 'remove', '_owner' => $privateOwner];
        $router->execute($remove);
        checkProfileNames(count($router->catalogue[$path]) === 1 && $router->recording->words === [],
            'A private profile was removed while an account still referenced it: ' . $path);
        $router->catalogue[$accountPath] = [];
        $router->execute($remove);
        checkProfileNames($router->catalogue[$path] === [] && in_array('/' . $path . '/remove', $router->recording->words, true),
            'An unused, exactly named friendly private profile could not be retired: ' . $path);
    }

    $router = new ProfileNamesMT();
    $command = ['path' => '/' . $path, 'name' => $friendlyPrivate, 'rate-limit' => '10M/35M',
        'comment' => $privateOwner, '_owner' => $privateOwner];
    $router->catalogue[$path] = [['.id' => '*private', 'name' => $legacyPrivate, 'comment' => 'Servicio #1353'],
        ['.id' => '*foreign', 'name' => $friendlyPrivate, 'comment' => 'administrator profile']];
    $rejected = false;
    try { $router->execute($command); } catch (Throwable $error) { $rejected = true; }
    checkProfileNames($rejected && $router->recording->words === [] && $router->catalogue[$path][1]['comment'] === 'administrator profile',
        'A foreign profile at the friendly destination was adopted or overwritten: ' . $path);
    $router->catalogue[$path] = [['.id' => '*private1', 'name' => $legacyPrivate, 'comment' => $privateOwner],
        ['.id' => '*private2', 'name' => $renamedPrivate, 'comment' => 'Servicio #1353']];
    $rejected = false;
    try { $router->execute($command); } catch (Throwable $error) { $rejected = true; }
    checkProfileNames($rejected && $router->recording->words === [],
        'Mixed legacy and friendly private-profile owners selected an arbitrary row: ' . $path);
    $router->catalogue[$path] = [['.id' => '*manual', 'name' => 'Manual private profile', 'comment' => 'Servicio #1353']];
    $router->execute($command);
    checkProfileNames(count($router->catalogue[$path]) === 2 && $router->catalogue[$path][0]['.id'] === '*manual'
        && $router->catalogue[$path][0]['name'] === 'Manual private profile',
        'A manual profile with the same display comment was adopted without a reserved identity: ' . $path);

    $unverified = new ProfileNamesMT();
    $unverified->catalogue[$path] = [['.id' => '*private', 'name' => $legacyPrivate, 'comment' => 'Servicio #1353']];
    $unverified->recording->afterRead = static function ($words): void {};
    $command['batch'] = 'service-1353';
    $next = ['path' => '/' . $accountPath, 'name' => 'private-client', 'profile' => $friendlyPrivate,
        'comment' => '42 - Test Client - 1353', '_service_id' => 1353, '_client_id' => 42, 'batch' => 'service-1353'];
    checkProfileNames($unverified->do_batch((object) [], [$command, $next]) === 0
        && isset($unverified->failed()['service-1353']) && !in_array('/' . $accountPath . '/add', $unverified->recording->words, true),
        'A private-profile rename without readback confirmation was used by an account: ' . $path);
}

echo "Profile naming regression passed: readable plan and service names, frozen retries, in-place owned migration, repeated renames, external collisions, dependencies and historical deletes.\n";
$apiSqlite->close();
