<?php
// A reconciliation without effective changes must preserve sessions; real changes still reconnect.
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
function checkReconnect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function reconnectSnapshot(string $key): array {
    $db = new SQLite3('data/jobs.db', SQLITE3_OPEN_READONLY);
    try { return json_decode($db->querySingle('SELECT payload FROM jobs WHERE id=' . (int) substr($key, 1)), true, 512, JSON_THROW_ON_ERROR); }
    finally { $db->close(); }
}
function reconnectCommands(array $commands): array {
    return array_values(array_filter($commands, static fn(array $command): bool =>
        in_array(trim($command['path'] ?? '', '/'), ['ppp/active', 'ip/hotspot/active'], true)));
}
function reconnectFlags(array $batches): array {
    $flags = [];
    foreach ($batches as $commands) foreach (reconnectCommands($commands) as $command) {
        $flags[$command['name']][] = $command['_reconnect_required'] ?? true;
    }
    return $flags;
}

$source = realpath(__DIR__ . '/../src');
$original = getcwd();
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-reconnect-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
foreach (['lib', 'includes', 'data', 'vendor'] as $folder) mkdir($directory . '/' . $folder, 0700);
foreach (glob($source . '/lib/*.php') as $file) copy($file, $directory . '/lib/' . basename($file));
// Reopen the queue through the normal factory in this isolated fixture, simulating a new process.
$queueFixture = $directory . '/lib/api_job_queue.php';
$queueCode = str_replace('static $queue = null;',
    'static $queue = null; if (isset($GLOBALS["reconnectQueueOverride"])) return $GLOBALS["reconnectQueueOverride"];',
    file_get_contents($queueFixture), $queueFactoryReplacements);
checkReconnect($queueFactoryReplacements === 1, 'The isolated queue-restart hook no longer matches its factory');
file_put_contents($queueFixture, $queueCode);
foreach (glob($source . '/includes/*') as $file) if (is_file($file)) copy($file, $directory . '/includes/' . basename($file));
file_put_contents($directory . '/vendor/autoload.php', <<<'PHP'
<?php
namespace Ubnt\UcrmPluginSdk\Service;
class UcrmApi {
    public static function create(){return new self();}
    public function get($path,$options=[]){
        // Retry lifecycle reads are local authority; RouterOS state remains in its separate adapter.
        if(preg_match('~^clients/services/([1-9][0-9]*)$~D',$path,$match))
            return ['id'=>(int)$match[1],'clientId'=>70,'status'=>1];
        if($path==='clients/70')return ['id'=>70,'isArchived'=>false];
        throw new \RuntimeException('Network prohibited in reconnect regression');
    }
}
PHP);
file_put_contents($directory . '/lib/mt.php', <<<'PHP'
<?php
class MT {
    public static array $batches = [];
    public static array $cuts = [];
    public static array $catalogue = [];
    public static ?string $driftUser = null;
    public static bool $sharedProfileDrift = false;
    public static ?string $failResetUser = null;
    public static ?string $failPreflightUser = null;
    public static int $noopResets = 0;
    public static int $writes = 0;
    public static int $durableEscalations = 0;
    private array $errors = [];
    public function do_batch($device, $commands, $beforeWrite = null): int {
        $this->errors = [];
        foreach ($commands as $command) {
            if (in_array(trim($command['path'], '/'), ['ppp/secret', 'ip/hotspot/user'], true)
                && ($command['name'] ?? '') === self::$failPreflightUser) {
                $this->errors[$command['batch'] ?? 'fixture'] = 'Simulated ownership preflight failure';
                return 0;
            }
        }
        $upgraded = false;
        $upgradedUsers = [];
        foreach ($commands as &$command) {
            if (in_array(trim($command['path'], '/'), ['ppp/active', 'ip/hotspot/active'], true)
                && ($command['name'] ?? '') === self::$driftUser && ($command['_reconnect_required'] ?? true) === false) {
                $command['_reconnect_required'] = true;
                $upgraded = true;
                $upgradedUsers[] = $command['name'];
            }
            if (trim($command['path'], '/') === 'ppp/active' && self::$sharedProfileDrift) {
                // MT observes a real change to the shared profile before writing it.
                // Other services will not observe this drift once the first job fixes it.
                $command['_profile_repair'] = true;
                $command['_reconnect_required'] = true;
                $upgraded = true;
                $upgradedUsers[] = $command['name'];
            }
        }
        unset($command);
        if ($upgraded) {
            $writesBefore = self::$writes;
            checkReconnect(is_callable($beforeWrite), 'Router drift cannot be persisted before the first write');
            $beforeWrite($commands);
            checkReconnect(self::$writes === $writesBefore, 'The drift callback ran after router writes');
            $persistedUpgrades = 0;
            foreach (myJobQueue()->list() as $key => $job) {
                if (!in_array($job['username'] ?? '', $upgradedUsers, true)) continue;
                $persisted = reconnectCommands(reconnectSnapshot($key)['_commands'] ?? []);
                checkReconnect(count($persisted) === 1 && ($persisted[0]['_reconnect_required'] ?? false) === true,
                    'A required reconnect was not durable before the first router write');
                if (self::$sharedProfileDrift) {
                    checkReconnect(is_int($persisted[0]['_profile_revision'] ?? null) && $persisted[0]['_profile_revision'] > 0,
                        'A shared profile repair has no durable revision before the first write');
                    $effective = reconnectCommands($commands);
                    checkReconnect($effective[0]['_profile_revision'] === $persisted[0]['_profile_revision']
                        && !isset($effective[0]['_profile_repair']) && !isset($persisted[0]['_profile_repair']),
                        'The repair callback did not replace its transient marker with a revision');
                }
                self::$durableEscalations++;
                $persistedUpgrades++;
            }
            checkReconnect($persistedUpgrades === count(array_unique($upgradedUsers)), 'The drift callback missed a durable job to update');
            self::$driftUser = null;
            self::$sharedProfileDrift = false;
        }
        self::$batches[] = $commands;
        $completed = 0;
        foreach ($commands as $command) {
            $path = trim($command['path'], '/');
            $name = $command['name'] ?? '';
            if (in_array($path, ['ppp/active', 'ip/hotspot/active'], true)) {
                if (($command['_reconnect_required'] ?? true) === false) {
                    self::$noopResets++;
                    $completed++;
                    continue;
                }
                if ($name === self::$failResetUser) {
                    self::$failResetUser = null;
                    $this->errors[$command['batch'] ?? 'fixture'] = 'Simulated failure after account write, before session reset';
                    return $completed;
                }
                self::$cuts[$name] = (self::$cuts[$name] ?? 0) + 1;
            } elseif (($command['action'] ?? '') === 'remove') {
                unset(self::$catalogue[$path][$name]);
            } else {
                self::$catalogue[$path][$name] = $command;
            }
            self::$writes++;
            $completed++;
        }
        return $completed;
    }
    public function failed(): array { return $this->errors; }
    public function success(): array { return []; }
}
PHP);
chdir($directory);
set_include_path($directory . '/lib' . PATH_SEPARATOR . get_include_path());
require 'lib/api_common.php';
require 'lib/api_setup.php';
require 'lib/batch.php';
try {
    $apiSqlite = new ApiSqlite('data/data.db');
    $apiSqlite->exec(file_get_contents('includes/schema.sql'));
    (new ApiSetup())->config_load();
    $apiSqlite->saveConfig(['version' => MY_VERSION, 'disable_contention' => true, 'hs_enable' => true]);
    $apiSqliteCache = new ApiSqlite('data/cache.db');
    $apiSqliteCache->exec(file_get_contents('includes/cache.sql'));
    $apiJobQueue = myJobQueue();
    $apiSqlite->insert(['id' => 10, 'name' => 'Fixture Router', 'type' => 'mikrotik', 'ip' => '192.0.2.10',
        'user' => 'fixture', 'password' => 'fixture'], 'devices');
    $apiSqlite->insert(['id' => 1, 'name' => 'Shared fixture plan', 'ratio' => 1, 'uploadSpeed' => 20, 'downloadSpeed' => 50], 'plans');
    $apiSqliteCache->insert(['id' => 70, 'firstName' => 'Fixture', 'lastName' => 'Client'], 'clients');
    foreach ([91, 92, 93] as $id) {
        $apiSqliteCache->insert(['id' => $id, 'clientId' => 70, 'device' => 10, 'planId' => 1, 'status' => 1,
            'username' => 'fixture-' . $id, 'password' => 'fixture secret', 'hotspot' => $id === 93 ? 1 : 0,
            'uploadSpeed' => 20, 'downloadSpeed' => 50], 'services');
        $apiSqliteCache->insert(['id' => $id, 'address' => '192.0.2.' . $id], 'network');
    }
    $foreign = ['name' => 'external-account', 'comment' => 'Configured by an administrator', 'profile' => 'external-profile'];
    MT::$catalogue['ppp/secret']['external-account'] = $foreign;
    MT::$catalogue['ppp/profile']['external-profile'] = ['name' => 'external-profile', 'rate-limit' => '7M/9M'];
    $foreignProfile = MT::$catalogue['ppp/profile']['external-profile'];
    checkReconnect((new Batch())->set_accounts([91, 92, 93]), 'Initial provisioning failed');
    checkReconnect(reconnectFlags(MT::$batches) === ['fixture-91' => [true], 'fixture-92' => [true], 'fixture-93' => [true]],
        'First provisioning without a confirmed baseline did not require reconnect');

    MT::$batches = [];
    $firstCuts = MT::$cuts;
    $repeat = new Batch();
    checkReconnect($repeat->set_accounts([91, 92, 93]), 'A repeat synchronization was not counted as successful');
    checkReconnect(reconnectFlags(MT::$batches) === ['fixture-91' => [false], 'fixture-92' => [false], 'fixture-93' => [false]],
        'An identical synchronization still requests PPP or Hotspot session removal');
    checkReconnect(MT::$cuts === $firstCuts && MT::$noopResets === 3 && $repeat->outcomes()['completed'] === 3
        && $apiJobQueue->list() === [], 'No-op reconnections cut sessions or left unsuccessful jobs');

    // Comments and metadata are not effective connection settings.
    $apiSqliteCache->edit(['id' => 70, 'firstName' => 'Renamed', 'lastName' => 'Subscriber'], 'clients');
    MT::$batches = [];
    checkReconnect((new Batch())->set_accounts([91, 92, 93]), 'A comment-only change failed');
    checkReconnect(MT::$cuts === $firstCuts && reconnectFlags(MT::$batches)['fixture-91'] === [false]
        && strpos(MT::$catalogue['ppp/secret']['fixture-91']['comment'], 'Renamed') !== false,
        'A comment-only account refresh disconnected the subscriber or skipped the comment update');
    $eventOnly = $apiSqliteCache->singleQuery('SELECT services.*,clients.company,clients.firstName,clients.lastName,network.address FROM services LEFT JOIN clients ON clients.id=services.clientId LEFT JOIN network ON network.id=services.id WHERE services.id=91', true);
    $eventOnly['_event_id'] = 'fixture-event-with-identical-effective-settings';
    MT::$batches = [];
    $eventSuccess = (new Batch())->set_accounts([$eventOnly]);
    checkReconnect($eventSuccess && MT::$cuts === $firstCuts
        && reconnectFlags(MT::$batches)['fixture-91'] === [false], 'An event ID or batch metadata forced a reconnect: '
            . json_encode(['success' => $eventSuccess, 'flags' => reconnectFlags(MT::$batches), 'jobs' => array_column($apiJobQueue->list(), 'error')], JSON_THROW_ON_ERROR));

    // Renaming the same owned profile is presentation only, including through the real Batch comparison.
    $apiSqlite->edit(['id' => 1, 'name' => 'Renamed shared fixture plan'], 'plans');
    MT::$batches = [];
    checkReconnect((new Batch())->set_accounts([91, 92, 93]) && MT::$cuts === $firstCuts,
        'Renaming a plan disconnected PPP/Hotspot customers despite unchanged effective settings');
    checkReconnect(reconnectFlags(MT::$batches) === ['fixture-91' => [false], 'fixture-92' => [false], 'fixture-93' => [false]]
        && MT::$catalogue['ppp/secret']['fixture-91']['profile'] === 'Renamed shared fixture plan [UISP#1]',
        'A display-only plan rename forced a reconnect or failed to update the readable reference');

    // Every account remembers its own applied profile, even after another member updated the shared router profile.
    $apiSqlite->edit(['id' => 1, 'uploadSpeed' => 30], 'plans');
    MT::$batches = [];
    checkReconnect((new Batch())->set_accounts([91, 92]), 'A shared plan rate change failed');
    checkReconnect(reconnectFlags(MT::$batches) === ['fixture-91' => [true], 'fixture-92' => [true]],
        'The second member of a shared profile missed the required reconnect after a rate change');
    checkReconnect(MT::$cuts['fixture-91'] === $firstCuts['fixture-91'] + 1
        && MT::$cuts['fixture-92'] === $firstCuts['fixture-92'] + 1, 'A rate change did not reconnect both affected subscribers');
    MT::$batches = [];
    $stableCuts = MT::$cuts;
    checkReconnect((new Batch())->set_accounts([91, 92]) && MT::$cuts === $stableCuts,
        'A rate change remained sticky in a new synchronization after confirmed success');

    foreach (['password' => 'updated fixture secret', 'address' => '192.0.2.191'] as $field => $value) {
        $apiSqliteCache->edit(['id' => 91, $field => $value], $field === 'address' ? 'network' : 'services');
        MT::$batches = [];
        $before = MT::$cuts['fixture-91'];
        checkReconnect((new Batch())->set_accounts([91]) && MT::$cuts['fixture-91'] === $before + 1
            && reconnectFlags(MT::$batches)['fixture-91'] === [true], 'An effective ' . $field . ' change did not reconnect PPP');
        MT::$batches = [];
        checkReconnect((new Batch())->set_accounts([91]) && MT::$cuts['fixture-91'] === $before + 1
            && reconnectFlags(MT::$batches)['fixture-91'] === [false], 'An applied ' . $field . ' change disconnected PPP again');
    }
    $apiSqliteCache->edit(['id' => 93, 'password' => 'updated hotspot secret'], 'services');
    MT::$batches = [];
    $beforeHotspot = MT::$cuts['fixture-93'];
    checkReconnect((new Batch())->set_accounts([93]) && MT::$cuts['fixture-93'] === $beforeHotspot + 1
        && reconnectFlags(MT::$batches)['fixture-93'] === [true], 'Effective Hotspot changes did not reconnect');
    MT::$batches = [];
    checkReconnect((new Batch())->set_accounts([93]) && MT::$cuts['fixture-93'] === $beforeHotspot + 1
        && reconnectFlags(MT::$batches)['fixture-93'] === [false], 'An unchanged Hotspot account disconnected again');

    // Drift discovered by RouterOS preflight must survive partial success and the absence of drift on retry.
    MT::$driftUser = MT::$failResetUser = 'fixture-91';
    MT::$batches = [];
    $beforeDrift = MT::$cuts['fixture-91'];
    $beforeApplied = $apiJobQueue->appliedSnapshot(91);
    checkReconnect(!(new Batch())->set_accounts([91]), 'The simulated partial router failure was reported successful');
    $pendingKeys = [];
    foreach ($apiJobQueue->list() as $key => $job) if ($job['id'] === 91) $pendingKeys[] = $key;
    checkReconnect(count($pendingKeys) === 1 && MT::$durableEscalations === 1, 'The partial drift update lost its durable job');
    $frozen = reconnectSnapshot($pendingKeys[0]);
    $frozenReset = reconnectCommands($frozen['_commands']);
    checkReconnect(($frozenReset[0]['_reconnect_required'] ?? false) === true && MT::$cuts['fixture-91'] === $beforeDrift
        && $apiJobQueue->appliedSnapshot(91) === $beforeApplied, 'Partial success cleared the required reconnect or marked it applied');
    MT::$batches = [];
    $apiJobQueue->run($pendingKeys);
    checkReconnect($apiJobQueue->completed($pendingKeys) && MT::$cuts['fixture-91'] === $beforeDrift + 1
        && reconnectFlags(MT::$batches)['fixture-91'] === [true], 'A drift retry lost its required reconnect after the earlier account write');
    MT::$batches = [];
    checkReconnect((new Batch())->set_accounts([91]) && MT::$cuts['fixture-91'] === $beforeDrift + 1
        && reconnectFlags(MT::$batches)['fixture-91'] === [false], 'Confirmed drift recovery forced reconnect on later clean sync');

    // Batch submits one service at a time. A fixed shared profile must still invalidate the
    // next customer's older session, whose preflight will now see the already-correct profile.
    MT::$sharedProfileDrift = true;
    MT::$batches = [];
    $beforeShared = MT::$cuts;
    checkReconnect((new Batch())->set_accounts([91]), 'The first customer could not repair shared profile drift');
    $firstRevision = reconnectCommands($apiJobQueue->appliedSnapshot(91)['_commands'])[0]['_profile_revision'] ?? 0;
    checkReconnect($firstRevision > 0 && MT::$cuts['fixture-91'] === $beforeShared['fixture-91'] + 1,
        'A shared repair did not record the first customer reconnect');
    checkReconnect(!MT::$sharedProfileDrift, 'The mock still exposes drift after the first repair');
    MT::$batches = [];
    checkReconnect((new Batch())->set_accounts([92]) && reconnectFlags(MT::$batches)['fixture-92'] === [true]
        && MT::$cuts['fixture-92'] === $beforeShared['fixture-92'] + 1,
        'The next sequential customer missed the reconnect because another job already fixed the shared profile');
    checkReconnect((reconnectCommands($apiJobQueue->appliedSnapshot(92)['_commands'])[0]['_profile_revision'] ?? 0) === $firstRevision,
        'The next customer did not acknowledge the repaired profile revision');
    MT::$batches = [];
    $sharedStableCuts = MT::$cuts;
    checkReconnect((new Batch())->set_accounts([91, 92, 93]) && MT::$cuts === $sharedStableCuts
        && reconnectFlags(MT::$batches) === ['fixture-91' => [false], 'fixture-92' => [false], 'fixture-93' => [false]],
        'An acknowledged repair remained sticky, or a PPP repair invalidated an unrelated Hotspot profile');

    // A second repair can finish the profile write but fail before reset. Persist the
    // revision across queue restart, apply it to B, then retry A without creating a new revision.
    MT::$sharedProfileDrift = true;
    MT::$failResetUser = 'fixture-91';
    MT::$batches = [];
    $beforePartialShared = MT::$cuts;
    checkReconnect(!(new Batch())->set_accounts([91]), 'The partial shared repair was reported complete');
    $sharedPendingKeys = [];
    foreach ($apiJobQueue->list() as $key => $job) if ($job['id'] === 91) $sharedPendingKeys[] = $key;
    checkReconnect(count($sharedPendingKeys) === 1, 'The partial shared repair lost its retry');
    $secondRevision = reconnectCommands(reconnectSnapshot($sharedPendingKeys[0])['_commands'])[0]['_profile_revision'] ?? 0;
    checkReconnect($secondRevision > $firstRevision && MT::$cuts['fixture-91'] === $beforePartialShared['fixture-91']
        && (reconnectCommands($apiJobQueue->appliedSnapshot(91)['_commands'])[0]['_profile_revision'] ?? 0) === $firstRevision,
        'Partial profile repair lost the new revision or marked the old session as reconnected');
    $apiJobQueue->close();
    $apiJobQueue = new ApiJobQueue('data/jobs.db');
    $GLOBALS['reconnectQueueOverride'] = $apiJobQueue;
    checkReconnect((reconnectCommands(reconnectSnapshot($sharedPendingKeys[0])['_commands'])[0]['_profile_revision'] ?? 0) === $secondRevision,
        'Queue restart discarded the prepared shared revision');
    MT::$batches = [];
    checkReconnect((new Batch())->set_accounts([92]) && reconnectFlags(MT::$batches)['fixture-92'] === [true]
        && (reconnectCommands($apiJobQueue->appliedSnapshot(92)['_commands'])[0]['_profile_revision'] ?? 0) === $secondRevision,
        'Queue restart hid the first job repair from the next sequential customer');
    MT::$batches = [];
    MT::$failResetUser = 'fixture-91';
    $apiJobQueue->run($sharedPendingKeys);
    checkReconnect(!$apiJobQueue->completed($sharedPendingKeys) && reconnectFlags(MT::$batches)['fixture-91'] === [true]
        && MT::$cuts['fixture-91'] === $beforePartialShared['fixture-91']
        && (reconnectCommands(reconnectSnapshot($sharedPendingKeys[0])['_commands'])[0]['_profile_revision'] ?? 0) === $secondRevision,
        'Retrying an already-correct profile lost its required reset or created an unnecessary shared revision');

    // New actual profile drift may occur while the SAME job is still pending. B has already
    // acknowledged the earlier repair, so observing a fresh repair on A must invalidate B again.
    MT::$sharedProfileDrift = true;
    MT::$batches = [];
    $apiJobQueue->run($sharedPendingKeys);
    $thirdRevision = reconnectCommands($apiJobQueue->appliedSnapshot(91)['_commands'])[0]['_profile_revision'] ?? 0;
    checkReconnect($apiJobQueue->completed($sharedPendingKeys) && reconnectFlags(MT::$batches)['fixture-91'] === [true]
        && MT::$cuts['fixture-91'] === $beforePartialShared['fixture-91'] + 1 && $thirdRevision > $secondRevision
        && (reconnectCommands($apiJobQueue->appliedSnapshot(92)['_commands'])[0]['_profile_revision'] ?? 0) === $secondRevision,
        'A newly observed repair in the same retry did not advance the shared revision independently of job identity');
    MT::$batches = [];
    $beforeSecondInvalidation = MT::$cuts['fixture-92'];
    checkReconnect((new Batch())->set_accounts([92]) && reconnectFlags(MT::$batches)['fixture-92'] === [true]
        && MT::$cuts['fixture-92'] === $beforeSecondInvalidation + 1
        && (reconnectCommands($apiJobQueue->appliedSnapshot(92)['_commands'])[0]['_profile_revision'] ?? 0) === $thirdRevision,
        'The next customer kept the older profile revision after a fresh repair in the first customer retry');
    MT::$batches = [];
    $afterSharedRecovery = MT::$cuts;
    checkReconnect((new Batch())->set_accounts([91, 92, 93]) && MT::$cuts === $afterSharedRecovery,
        'A recovered partial shared repair kept disconnecting later clean synchronizations');

    // Explicit manual synchronization reconnects every selected managed PPP/Hotspot
    // account each time; it does not permanently change ordinary reconciliation.
    $manual = new Batch();
    $beforeManual = MT::$cuts;
    foreach ([1, 2] as $attempt) {
        MT::$batches = [];
        checkReconnect($manual->set_accounts([91, 93], true)
            && reconnectFlags(MT::$batches) === ['fixture-91' => [true], 'fixture-93' => [true]]
            && MT::$cuts['fixture-91'] === $beforeManual['fixture-91'] + $attempt
            && MT::$cuts['fixture-93'] === $beforeManual['fixture-93'] + $attempt
            && MT::$cuts['fixture-92'] === $beforeManual['fixture-92'],
            'Repeated explicit synchronization did not force just the selected PPP/Hotspot reconnections');
        foreach ([91, 93] as $id) checkReconnect(!array_key_exists('_force_reconnect', $apiJobQueue->appliedSnapshot($id)),
            'A one-time reconnect request leaked into successful applied state');
    }
    $invalidRequestFailed = false;
    try { $manual->set_accounts([0], true); }
    catch (InvalidArgumentException $error) { $invalidRequestFailed = true; }
    checkReconnect($invalidRequestFailed, 'The invalid forced request fixture did not exercise exception cleanup');
    MT::$batches = [];
    $afterManual = MT::$cuts;
    checkReconnect($manual->set_accounts([91, 92, 93]) && MT::$cuts === $afterManual
        && reconnectFlags(MT::$batches) === ['fixture-91' => [false], 'fixture-92' => [false], 'fixture-93' => [false]],
        'The forced-call flag leaked into a later ordinary synchronization on the same Batch instance');
    // Billing actions supply the same explicit marker on their immutable service snapshot.
    $forcedEvent = $apiSqliteCache->singleQuery('SELECT services.*,clients.company,clients.firstName,clients.lastName,network.address FROM services LEFT JOIN clients ON clients.id=services.clientId LEFT JOIN network ON network.id=services.id WHERE services.id=92', true);
    $forcedEvent['_force_reconnect'] = true;
    $forcedEvent['_event_id'] = 'explicit-billing-reconnect';
    MT::$batches = [];
    $beforeForcedEvent = MT::$cuts['fixture-92'];
    checkReconnect((new Batch())->set_accounts([$forcedEvent]) && reconnectFlags(MT::$batches)['fixture-92'] === [true]
        && MT::$cuts['fixture-92'] === $beforeForcedEvent + 1 && !isset($apiJobQueue->appliedSnapshot(92)['_force_reconnect']),
        'An explicit billing snapshot lost its one-time forced reconnect');
    $forcedEvent['_event_id'] = 'forced-intent-before-command-preparation';
    $unpreparedKeys = (new Batch())->queue_accounts([$forcedEvent]);
    checkReconnect(count($unpreparedKeys) === 1 && reconnectSnapshot($unpreparedKeys[0])['_force_reconnect'] === true
        && !isset(reconnectSnapshot($unpreparedKeys[0])['_commands']),
        'The forced intent was not durable until after commands had been prepared');
    $apiJobQueue->close();
    $apiJobQueue = new ApiJobQueue('data/jobs.db');
    $GLOBALS['reconnectQueueOverride'] = $apiJobQueue;
    MT::$batches = [];
    $beforeUnpreparedRetry = MT::$cuts['fixture-92'];
    $apiJobQueue->run($unpreparedKeys);
    checkReconnect($apiJobQueue->completed($unpreparedKeys) && reconnectFlags(MT::$batches)['fixture-92'] === [true]
        && MT::$cuts['fixture-92'] === $beforeUnpreparedRetry + 1,
        'Restart before command preparation lost the explicit reconnect request');

    // Failure cannot erase the requested disconnect or bypass ownership preflight.
    MT::$failPreflightUser = 'fixture-91';
    $beforePreflightWrites = MT::$writes; $beforePreflightCuts = MT::$cuts;
    $beforePreflightApplied = $apiJobQueue->appliedSnapshot(91);
    checkReconnect(!$manual->set_accounts([91], true) && MT::$writes === $beforePreflightWrites
        && MT::$cuts === $beforePreflightCuts && $apiJobQueue->appliedSnapshot(91) === $beforePreflightApplied,
        'Forced reconnect bypassed a failed ownership check or published unconfirmed applied state');
    $forcedPending = [];
    foreach ($apiJobQueue->list() as $key => $job) if ((int) $job['id'] === 91) $forcedPending[] = $key;
    checkReconnect(count($forcedPending) === 1 && reconnectSnapshot($forcedPending[0])['_force_reconnect'] === true
        && reconnectCommands(reconnectSnapshot($forcedPending[0])['_commands'])[0]['_reconnect_required'] === true,
        'A failed forced synchronization lost its durable reconnect intent');
    // Restore the transport, but fail after writes and before reset once more.
    MT::$failPreflightUser = null; MT::$failResetUser = 'fixture-91';
    $apiJobQueue->run($forcedPending);
    checkReconnect(!$apiJobQueue->completed($forcedPending) && MT::$cuts === $beforePreflightCuts
        && reconnectSnapshot($forcedPending[0])['_force_reconnect'] === true,
        'A partial router write consumed the reconnect request before the session was reset');
    $apiJobQueue->close();
    $apiJobQueue = new ApiJobQueue('data/jobs.db');
    $GLOBALS['reconnectQueueOverride'] = $apiJobQueue;
    MT::$batches = [];
    $apiJobQueue->run($forcedPending);
    checkReconnect($apiJobQueue->completed($forcedPending) && reconnectFlags(MT::$batches)['fixture-91'] === [true]
        && MT::$cuts['fixture-91'] === $beforePreflightCuts['fixture-91'] + 1
        && !array_key_exists('_force_reconnect', $apiJobQueue->appliedSnapshot(91)),
        'Queue restart lost the forced reconnect or retained it after confirmed recovery');
    MT::$batches = [];
    $afterForcedRecovery = MT::$cuts;
    checkReconnect($manual->set_accounts([91, 92, 93]) && MT::$cuts === $afterForcedRecovery
        && reconnectFlags(MT::$batches) === ['fixture-91' => [false], 'fixture-92' => [false], 'fixture-93' => [false]],
        'A forced attempt or its failure contaminated a later ordinary reconciliation');

    // An older/incomplete applied snapshot cannot establish that effective profile settings are unchanged.
    $jobDatabase = (new ReflectionProperty(ApiJobQueue::class, 'db'))->getValue($apiJobQueue);
    $incomplete = $apiJobQueue->appliedSnapshot(92);
    unset($incomplete['_commands']);
    $statement = $jobDatabase->prepare('UPDATE service_versions SET applied_payload=? WHERE service_id=92');
    $statement->bindValue(1, json_encode($incomplete, JSON_THROW_ON_ERROR), SQLITE3_TEXT);
    $statement->execute();
    $statement->close();
    MT::$batches = [];
    checkReconnect((new Batch())->set_accounts([92]) && reconnectFlags(MT::$batches)['fixture-92'] === [true],
        'An incomplete historical applied snapshot suppressed the required reconnect');

    // Deletion must override false copied from the most recent successful no-op snapshot.
    MT::$batches = [];
    $beforeDelete = MT::$cuts;
    checkReconnect((new Batch())->del_accounts([91, 93]), 'Deleting managed PPP/Hotspot accounts failed');
    checkReconnect(reconnectFlags(MT::$batches) === ['fixture-91' => [true], 'fixture-93' => [true]]
        && MT::$cuts['fixture-91'] === $beforeDelete['fixture-91'] + 1 && MT::$cuts['fixture-93'] === $beforeDelete['fixture-93'] + 1,
        'Deleting an account reused the no-reconnect flag from its previous update');
    checkReconnect(MT::$catalogue['ppp/secret']['external-account'] === $foreign
        && MT::$catalogue['ppp/profile']['external-profile'] === $foreignProfile && !isset(MT::$cuts['external-account']),
        'Reconciliation or deletion changed an unrelated account/profile');
    echo "Reconnect regression passed: unchanged PPP/Hotspot sessions, explicit manual/billing reconnection, ownership failure and restart recovery, harmless metadata/profile renames, effective changes, shared repair revisions, durable drift retries and forced deletion cleanup.\n";
} finally {
    if (isset($apiJobQueue)) $apiJobQueue->close();
    unset($GLOBALS['reconnectQueueOverride']);
    if (isset($apiSqlite)) $apiSqlite->close();
    if (isset($apiSqliteCache)) $apiSqliteCache->close();
    chdir($original);
    $resolved = realpath($directory);
    $temporaryRoot = realpath(sys_get_temp_dir());
    if ($resolved && $temporaryRoot && dirname($resolved) === $temporaryRoot && strpos(basename($resolved), 'uisp-reconnect-') === 0) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($resolved);
    }
    restore_error_handler();
}
