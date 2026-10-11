<?php
// Legacy SQLite rows and derived rate overflow must isolate a service, never write an unlimited/invalid rate.
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
function checkNumber(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejectsNumber(callable $work, string $field, int $serviceId): void {
    try { $work(); } catch (InvalidProvisioningNumber $error) {
        checkNumber(strpos($error->getMessage(), 'field=' . $field) !== false, 'Numeric failure did not identify its field: ' . $error->getMessage());
        checkNumber(strpos($error->getMessage(), 'service=' . $serviceId) !== false, 'Numeric failure did not identify its service');
        return;
    }
    throw new RuntimeException('A corrupt or overflowing number was accepted: ' . $field);
}
$source = realpath(__DIR__ . '/../src');
$original = getcwd();
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-nonfinite-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
foreach (['lib', 'includes', 'data', 'vendor'] as $folder) mkdir($directory . '/' . $folder, 0700);
foreach (glob($source . '/lib/*.php') as $file) copy($file, $directory . '/lib/' . basename($file));
foreach (glob($source . '/includes/*') as $file) if (is_file($file)) copy($file, $directory . '/includes/' . basename($file));
file_put_contents($directory . '/vendor/autoload.php', '<?php namespace Ubnt\\UcrmPluginSdk\\Service; class UcrmApi { public static function create(){return new self();} public function get(){throw new \\RuntimeException("Network prohibited in numeric regression");} }');
file_put_contents($directory . '/lib/mt.php', <<<'PHP'
<?php
class MT {
    public static array $batches = [];
    public function do_batch($device, $commands): int {
        self::$batches[] = $commands;
        return count($commands);
    }
    public function failed(): array { return []; }
    public function success(): array { return []; }
}
PHP);
chdir($directory);
set_include_path($directory . '/lib' . PATH_SEPARATOR . get_include_path());
require 'lib/api_common.php';
require 'lib/api_setup.php';
require 'lib/api_configuration_apply.php';
require 'lib/api_update.php';
require 'lib/batch.php';
try {
    $apiSqlite = new ApiSqlite('data/data.db');
    $apiSqlite->exec(file_get_contents('includes/schema.sql'));
    (new ApiSetup())->config_load();
    $apiSqlite->saveConfig(['version' => MY_VERSION, 'disable_contention' => true]);
    $apiSqliteCache = new ApiSqlite('data/cache.db');
    $apiSqliteCache->exec(file_get_contents('includes/cache.sql'));
    $apiJobQueue = myJobQueue();
    $apiSqlite->insert(['id' => 10, 'name' => 'Fixture router', 'type' => 'mikrotik', 'ip' => '192.0.2.10',
        'user' => 'fixture', 'password' => 'fixture'], 'devices');
    foreach ([35, 38, 39] as $id) {
        $apiSqlite->insert(['id' => $id, 'name' => 'Plan ' . $id, 'ratio' => 1, 'uploadSpeed' => 4,
            'downloadSpeed' => 8, 'limitUpload' => 0, 'limitDownload' => 0, 'burstUpload' => 0,
            'burstDownload' => 0, 'threshUpload' => 0, 'threshDownload' => 0], 'plans');
    }
    // The UISP base rates can be valid while plugin-only fields still contain historical bad data.
    $legacy = ['id' => 33, 'name' => 'Legacy fixture', 'uploadSpeed' => 5, 'downloadSpeed' => 25,
        'ratio' => '', 'uploadOverride' => " \t\n", 'downloadOverride' => '', 'limitUpload' => '',
        'limitDownload' => " \r\n", 'burstUpload' => '', 'burstDownload' => "\v\f",
        'threshUpload' => '', 'threshDownload' => ' ', 'priorityUpload' => '', 'priorityDownload' => ' ',
        'timeUpload' => '', 'timeDownload' => "\t"];
    $apiSqlite->insert($legacy, 'plans');
    $storedLegacy = $apiSqlite->singleQuery('SELECT * FROM plans WHERE id=33', true);
    $normalized = Data::normalizePlanNumbers($storedLegacy);
    checkNumber($normalized['uploadOverride'] === null && $normalized['limitUpload'] === null
        && $normalized['uploadSpeed'] === 5 && $storedLegacy['limitUpload'] === '',
        'Optional normalization mutated its input or changed the UISP base rate');
    checkNumber(Data::planNumericIssues($storedLegacy) === [], 'Unset legacy optional values were classified as corrupt');
    $legacyPlan = (new ReflectionMethod(Batch::class, 'find_plans'))->invoke(new Batch())[33];
    checkNumber($legacyPlan['uploadSpeed'] === 5 && $legacyPlan['downloadSpeed'] === 25
        && $legacyPlan['limitUpload'] === 0 && $legacyPlan['limitDownload'] === 0
        && $legacyPlan['burstUpload'] === 0 && $legacyPlan['threshUpload'] === 0
        && $legacyPlan['priorityUpload'] === 8 && $legacyPlan['timeUpload'] === 1,
        'SQLite interpreted optional blank fields as a zero override or the full guaranteed rate');
    Data::validatePlanNumbers($legacyPlan, 1193);
    checkNumber($apiSqlite->singleQuery('SELECT * FROM plans WHERE id=33', true) === $storedLegacy,
        'Reading or validating legacy numeric defaults rewrote the stored plan');
    $apiSqlite->execute('UPDATE plans SET limitUpload=1e999 WHERE id=33');
    $invalidLegacy = $apiSqlite->singleQuery('SELECT * FROM plans WHERE id=33', true);
    $legacyIssues = Data::planNumericIssues($invalidLegacy);
    checkNumber($legacyIssues === [['field' => 'limitUpload', 'reason' => 'non_finite', 'value' => 'Infinity']],
        'A real Infinity with normal 5/25 Mbps base rates was hidden or reset');
    try { (new Batch())->validateStoredPlan(33); throw new RuntimeException('A real infinite minimum was accepted'); }
    catch (InvalidProvisioningNumber $error) {
        $context = $error->context();
        checkNumber($context['field'] === 'limitUpload' && $context['reason'] === 'non_finite'
            && $context['stage'] === 'source' && $context['value'] === 'Infinity',
            'Numeric diagnostics did not distinguish the persisted source from derived rates');
        json_encode($context, JSON_THROW_ON_ERROR);
    }
    checkNumber(is_infinite($apiSqlite->singleQuery('SELECT limitUpload FROM plans WHERE id=33')),
        'Diagnosis silently repaired an unknown intended minimum rate');
    $apiSqlite->execute('UPDATE plans SET limitUpload=NULL WHERE id=33');
    $nullPlan = (new ReflectionMethod(Batch::class, 'find_plans'))->invoke(new Batch())[33];
    checkNumber($nullPlan['limitUpload'] === 0 && Data::planNumericIssues($apiSqlite->singleQuery('SELECT * FROM plans WHERE id=33', true)) === [],
        'NULL and historical blank optionals have different disabled-rate semantics');
    $issueFixture = ['uploadSpeed' => '', 'downloadSpeed' => 25, 'ratio' => 1.5, 'limitUpload' => 101,
        'limitDownload' => -1, 'burstUpload' => -101, 'burstDownload' => NAN, 'threshUpload' => 'private-fixture-value',
        'priorityUpload' => 0, 'priorityDownload' => 9, 'timeUpload' => -1, 'timeDownload' => ['private-object-value']];
    $allIssues = Data::planNumericIssues($issueFixture);
    $byField = array_column($allIssues, null, 'field');
    checkNumber(count($allIssues) === 11 && $byField['uploadSpeed']['reason'] === 'empty'
        && $byField['ratio']['reason'] === 'invalid_integer' && $byField['limitUpload']['reason'] === 'out_of_range'
        && $byField['burstDownload']['value'] === 'NaN', 'Diagnosis missed one or more raw invalid fields');
    $safeDiagnostics = json_encode($allIssues, JSON_THROW_ON_ERROR);
    checkNumber(strpos($safeDiagnostics, 'private-') === false && $byField['threshUpload']['value'] === 'non_numeric',
        'Numeric diagnosis serialized arbitrary persisted text or objects');
    $overflowIssues = Data::planNumericIssues(['uploadSpeed' => 4, 'downloadSpeed' => 25, 'burstUpload' => 1e308]);
    checkNumber($overflowIssues[0]['field'] === 'burstUpload' && $overflowIssues[0]['reason'] === 'calculation_non_finite',
        'Finite raw values that overflow in the production rate calculation were not diagnosed');
    $apiSqlite->execute('UPDATE plans SET uploadOverride=1e308 WHERE id=33');
    try { (new Batch())->validateStoredPlan(33); throw new RuntimeException('An overflowing speed override was accepted'); }
    catch (InvalidProvisioningNumber $error) {
        checkNumber($error->context()['field'] === 'uploadOverride' && $error->context()['reason'] === 'calculation_non_finite',
            'An overflowing override was misidentified as the normal UISP speed');
    }
    $apiSqlite->execute('UPDATE plans SET uploadOverride=NULL WHERE id=33');
    // A finite persisted value overflows only in the production SQL percentage calculation.
    $apiSqlite->execute('UPDATE plans SET burstUpload=1e308 WHERE id=38');
    // SQLite accepts historical non-finite numeric literals even though JSON cannot serialize them.
    $apiSqlite->execute('UPDATE plans SET downloadSpeed=1e999 WHERE id=39');
    $apiSqliteCache->insert(['id' => 70, 'firstName' => 'Fixture', 'lastName' => 'Client'], 'clients');
    foreach ([91 => 38, 92 => 35, 93 => 39] as $id => $planId) {
        $apiSqliteCache->insert(['id' => $id, 'clientId' => 70, 'device' => 10, 'planId' => $planId,
            'status' => 1, 'username' => 'fixture-' . $id, 'password' => '1e999',
            'uploadSpeed' => 4, 'downloadSpeed' => 8], 'services');
        $apiSqliteCache->insert(['id' => $id, 'address' => '192.0.2.' . $id], 'network');
    }
    $query = (new ReflectionMethod(Batch::class, 'find_plans'))->invoke(new Batch());
    checkNumber(is_finite((float) $apiSqlite->singleQuery('SELECT burstUpload FROM plans WHERE id=38')),
        'Overflow fixture is not a finite input');
    checkNumber(is_infinite($query[38]['burstUpload']) && is_infinite($query[39]['downloadSpeed']),
        'The production SQL did not reproduce non-finite results');
    rejectsNumber(fn() => (new Batch())->queue_accounts([91]), 'burstUpload', 91);
    rejectsNumber(fn() => (new Batch())->queue_accounts([93]), 'downloadSpeed', 93);
    checkNumber(MT::$batches === [] && $apiJobQueue->list() === [], 'An invalid plan wrote or enqueued RouterOS work');
    $mixed = new Batch();
    checkNumber(!$mixed->set_accounts([91, 92, 93]), 'Mixed provisioning reported success despite invalid services');
    $outcomes = $mixed->outcomes();
    checkNumber($outcomes['requested'] === 3 && $outcomes['completed'] === 1 && $outcomes['failed'] === 2,
        'Bulk outcomes do not match actual service results');
    checkNumber($outcomes['failures'][0]['serviceId'] === 91 && $outcomes['failures'][0]['planId'] === 38
        && $outcomes['failures'][0]['field'] === 'burstUpload'
        && $outcomes['failures'][0]['reason'] === 'calculation_non_finite'
        && $outcomes['failures'][0]['value'] === 1e308, 'The UI summary lost the actionable numeric field or cause');
    checkNumber(!empty($apiJobQueue->appliedSnapshot(92)), 'A corrupt first plan prevented the valid next service from provisioning');
    checkNumber($apiJobQueue->appliedSnapshot(91) === [] && $apiJobQueue->appliedSnapshot(93) === [],
        'Invalid plans were marked as applied');
    foreach (MT::$batches as $commands) foreach ($commands as $command) {
        checkNumber(strpos(json_encode($command, JSON_THROW_ON_ERROR), 'INFM') === false, 'An overflowed rate reached RouterOS');
        if (trim($command['path'], '/') === 'ppp/secret') {
            checkNumber($command['name'] === 'fixture-92' && $command['password'] === '1e999',
                'Validation changed a numeric-looking credential or wrote an invalid service');
        }
    }
    $logs = '';
    foreach (glob('data/*log*') as $file) if (is_file($file)) $logs .= file_get_contents($file);
    checkNumber(strpos($logs, 'provisioning_invalid_number') !== false && strpos($logs, 'field=burstUpload') !== false,
        'The log lost actionable numeric context');
    checkNumber(strpos($logs, 'calculation_non_finite') !== false && strpos($logs, '"stage":"calculation"') !== false,
        'The log did not retain the safe reason and calculation stage');
    checkNumber(strpos($logs, 'Inf and NaN cannot be JSON encoded') === false,
        'Invalid numeric data still fails as a context-free JSON exception');
    ApiConfigurationApply::requestPlan(38);
    $pending = ApiConfigurationApply::drain();
    checkNumber($pending['remaining'] === 1 && $pending['queued'] === 0,
        'Invalid plan configuration lost its durable application request');
    rejectsNumber(fn() => Data::validateSnapshotNumbers(['_config' => ['disabled_rate' => INF]], 91, 38),
        'snapshot._config.disabled_rate', 91);
    rejectsNumber(fn() => Data::validatePlanNumbers(['id' => 38, 'burstUpload' => NAN], 91), 'burstUpload', 91);
    rejectsNumber(fn() => Data::validateSnapshotNumbers((object) ['nested' => (object) ['speed' => INF]], 91, 38),
        'snapshot.nested.speed', 91);
    foreach ([1.5, 1e308, (float) PHP_INT_MAX, (string) PHP_INT_MAX . '0'] as $invalidRatio) {
        rejectsNumber(fn() => Data::validatePlanNumbers(['id' => 38, 'ratio' => $invalidRatio], 91), 'ratio', 91);
    }
    foreach ([null, 0, '0', 1.0, '1e1', PHP_INT_MAX, (string) PHP_INT_MAX] as $validRatio) {
        Data::validatePlanNumbers(['id' => 38, 'ratio' => $validRatio], 91);
    }
    $apiSqlite->execute('UPDATE plans SET burstUpload=25 WHERE id=38');
    $resolved = ApiConfigurationApply::drain();
    checkNumber($resolved['remaining'] === 0 && $resolved['queued'] === 1,
        'Correcting the value did not resume the saved application request');
    // API edits must validate complete effective rates after a partial edit, then roll back before commit/journaling.
    $originalPlan = $apiSqlite->singleQuery('SELECT * FROM plans WHERE id=35', true);
    $jobDatabase = (new ReflectionProperty(ApiJobQueue::class, 'db'))->getValue($apiJobQueue);
    $originalJobs = (int) $jobDatabase->querySingle('SELECT count(*) FROM jobs');
    $originalApplications = (int) $apiSqlite->singleQuery('SELECT count(*) FROM configuration_apply');
    rejectsNumber(function (): void {
        $update = new ApiUpdate((object) ['action' => 'edit', 'data' => (object) ['id' => 35, 'burstUpload' => 1e308]], 'plans');
        $update->exec();
    }, 'burstUpload', 0);
    checkNumber($apiSqlite->singleQuery('SELECT * FROM plans WHERE id=35', true) === $originalPlan,
        'An invalid partial edit persisted its rates or last-modified timestamp');
    checkNumber((int) $jobDatabase->querySingle('SELECT count(*) FROM jobs') === $originalJobs
        && (int) $apiSqlite->singleQuery('SELECT count(*) FROM configuration_apply') === $originalApplications,
        'A rolled-back plan edit created provisioning work');
    rejectsNumber(function (): void {
        $update = new ApiUpdate((object) ['action' => 'insert', 'data' => (object) ['id' => 90, 'name' => 'Invalid new plan',
            'uploadSpeed' => 4, 'downloadSpeed' => 8, 'ratio' => 1, 'burstUpload' => 1e308]], 'plans');
        $update->exec();
    }, 'burstUpload', 0);
    checkNumber(!$apiSqlite->exists(90, 'plans'), 'An invalid inserted plan was not rolled back');
    // Another corrupt legacy plan (39) must not prevent correcting this otherwise valid plan.
    $validEdit = new ApiUpdate((object) ['action' => 'edit', 'data' => (object) ['id' => 35, 'burstUpload' => 50]], 'plans');
    $validEdit->exec();
    checkNumber((float) $apiSqlite->singleQuery('SELECT burstUpload FROM plans WHERE id=35') === 50.0,
        'Valid partial edit did not save, or another invalid legacy plan blocked it');
    checkNumber((int) $jobDatabase->querySingle('SELECT count(*) FROM jobs') > $originalJobs,
        'Valid plan edit did not schedule its managed service');
    $validInsert = new ApiUpdate((object) ['action' => 'insert', 'data' => (object) ['id' => 90, 'name' => 'Valid new plan',
        'uploadSpeed' => 4, 'downloadSpeed' => 8, 'ratio' => 1, 'burstUpload' => 25]], 'plans');
    $validInsert->exec();
    checkNumber($apiSqlite->exists(90, 'plans'), 'Valid inserted plan did not save');
    foreach ([1.5, 1e308] as $legacyRatio) {
        $apiSqlite->execute('UPDATE plans SET ratio=? WHERE id=38', [$legacyRatio]);
        $legacyBatch = new Batch();
        checkNumber(!$legacyBatch->set_accounts([91, 92]), 'Invalid legacy contention ratio reported success');
        $legacyOutcomes = $legacyBatch->outcomes();
        checkNumber($legacyOutcomes['completed'] === 1 && $legacyOutcomes['failed'] === 1
            && $legacyOutcomes['failures'][0]['field'] === 'ratio',
            'Invalid legacy ratio stopped the following valid service or failed without its field');
    }
    $apiSqlite->execute('UPDATE plans SET ratio=1 WHERE id=38');
    // Infinity-Infinity becomes SQLite NULL, which must not masquerade as an unset burst/minimum.
    $apiSqlite->execute('UPDATE plans SET uploadSpeed=1e308,burstUpload=-100,limitUpload=100 WHERE id=38');
    $nanPlan = (new ReflectionMethod(Batch::class, 'find_plans'))->invoke(new Batch())[38];
    checkNumber($nanPlan['burstUpload'] === null && $nanPlan['limitUpload'] === null,
        'The SQLite NaN-to-NULL fixture did not reproduce the production arithmetic');
    rejectsNumber(fn() => (new Batch())->queue_accounts([91]), 'uploadSpeed', 91);
    $nanBatch = new Batch();
    checkNumber(!$nanBatch->set_accounts([91, 92]) && $nanBatch->outcomes()['completed'] === 1
        && $nanBatch->outcomes()['failures'][0]['field'] === 'uploadSpeed',
        'A SQLite NaN-to-NULL rate wrote a service or stopped the following valid service');
    $apiSqlite->execute('UPDATE plans SET uploadSpeed=4,burstUpload=-100,limitUpload=100 WHERE id=38');
    $beforeNanEdit = $apiSqlite->singleQuery('SELECT * FROM plans WHERE id=38', true);
    $jobsBeforeNanEdit = (int) $jobDatabase->querySingle('SELECT count(*) FROM jobs');
    rejectsNumber(function (): void {
        $update = new ApiUpdate((object) ['action' => 'edit', 'data' => (object) ['id' => 38, 'uploadSpeed' => 1e308]], 'plans');
        $update->exec();
    }, 'uploadSpeed', 0);
    checkNumber($apiSqlite->singleQuery('SELECT * FROM plans WHERE id=38', true) === $beforeNanEdit
        && (int) $jobDatabase->querySingle('SELECT count(*) FROM jobs') === $jobsBeforeNanEdit,
        'A NaN-to-NULL partial edit did not roll back its plan or created work');
    $apiSqlite->execute('UPDATE plans SET uploadSpeed=0,burstUpload=1e999 WHERE id=38');
    $zeroRatePlan = (new ReflectionMethod(Batch::class, 'find_plans'))->invoke(new Batch())[38];
    checkNumber($zeroRatePlan['burstUpload'] === null && is_infinite($zeroRatePlan['_sourceBurstUpload']),
        'A corrupt percentage times a zero rate did not reproduce SQLite NaN-to-NULL');
    rejectsNumber(fn() => (new Batch())->queue_accounts([91]), 'burstUpload', 91);
    $zeroRateBatch = new Batch();
    checkNumber(!$zeroRateBatch->set_accounts([91, 92]) && $zeroRateBatch->outcomes()['completed'] === 1
        && $zeroRateBatch->outcomes()['failures'][0]['field'] === 'burstUpload',
        'A corrupt percentage at zero speed was accepted or stopped another valid service');
    rejectsNumber(function (): void {
        $update = new ApiUpdate((object) ['action' => 'edit', 'data' => (object) ['id' => 38, 'name' => 'Still corrupt']], 'plans');
        $update->exec();
    }, 'burstUpload', 0);
    $apiSqlite->execute('UPDATE plans SET uploadSpeed=4,burstUpload=25,limitUpload=0 WHERE id=38');
    // Public queue callers and identity fingerprinting must have the same useful diagnostics as Batch.
    rejectsNumber(fn() => $apiJobQueue->enqueue(['id' => 99, 'device' => 10, 'planId' => 35,
        'username' => 'fixture-99', 'iaid' => INF, '_event_id' => 'fixture-numeric-event', '_terminal' => true], 'delete'),
        'snapshot.iaid', 99);
    checkNumber((int) $jobDatabase->querySingle('SELECT count(*) FROM jobs WHERE service_id=99') === 0
        && (int) $jobDatabase->querySingle('SELECT count(*) FROM service_versions WHERE service_id=99') === 0
        && (int) $jobDatabase->querySingle('SELECT count(*) FROM service_tombstones WHERE service_id=99') === 0,
        'An invalid direct queue caller changed durable intent state');
    $freezeKey = $apiJobQueue->enqueue(['id' => 99, 'device' => 10, 'planId' => 35, 'username' => 'fixture-99'], 'update');
    $beforeFreeze = $jobDatabase->querySingle('SELECT payload FROM jobs WHERE id=' . (int) substr($freezeKey, 1));
    $freezeChecked = false;
    $apiJobQueue->run([$freezeKey], function (array $snapshot, string $operation, array $job, ApiJobQueue $queue) use (&$freezeChecked): bool {
        $snapshot['_cleanup'] = ['id' => 99, 'iaid' => INF];
        rejectsNumber(fn() => $queue->freezePrepared($job, $snapshot), 'snapshot._cleanup.iaid', 99);
        $freezeChecked = true;
        return false;
    });
    checkNumber($freezeChecked && $jobDatabase->querySingle('SELECT payload FROM jobs WHERE id=' . (int) substr($freezeKey, 1)) === $beforeFreeze,
        'A rejected prepared snapshot replaced its original durable job');
    $apiSqlite->saveConfig(['disable_contention' => false]);
    $finitePlan = (new ReflectionMethod(Batch::class, 'find_plans'))->invoke(new Batch())[35];
    $finitePlan['uploadSpeed'] = 1e302;
    $member = $apiSqliteCache->singleQuery('SELECT services.*,network.address FROM services LEFT JOIN network USING(id) WHERE services.id=92', true);
    $member['_device'] = $apiSqlite->singleQuery('SELECT * FROM devices WHERE id=10', true);
    $member['_plan'] = $finitePlan;
    $member['_config'] = (array) $apiSqlite->readConfig();
    // The initial parent builder uses per-device members; multiplication itself can overflow.
    $apiSqliteCache->insert(['id' => 95, 'clientId' => 70, 'device' => 10, 'planId' => 35,
        'status' => 1, 'username' => 'fixture-95'], 'services');
    $builder = new MtData();
    $builder->set_data($member, $finitePlan);
    rejectsNumber(fn() => $builder->parent(), 'uploadSpeed', 92);
    // Shared-resource refresh also counts aliases for one physical router, after that initial builder.
    $apiSqlite->insert(['id' => 11, 'name' => 'Router alias', 'type' => 'mikrotik', 'ip' => '192.0.2.10',
        'user' => 'fixture', 'password' => 'fixture'], 'devices');
    $apiSqliteCache->edit(['id' => 95, 'device' => 11], 'services');
    $apiSqliteCache->insert(['id' => 95, 'address' => '192.0.2.95'], 'network');
    rejectsNumber(fn() => ApiSharedResources::refresh($member, [], $finitePlan, $member['_config']), 'uploadSpeed', 92);
    echo "Non-finite regression passed: production SQL overflow, historical Infinity, field/service diagnostics, valid service isolation, credential preservation and deferred recovery.\n";
} finally {
    if (isset($apiJobQueue)) $apiJobQueue->close();
    if (isset($apiSqlite)) $apiSqlite->close();
    if (isset($apiSqliteCache)) $apiSqliteCache->close();
    chdir($original);
    $resolved = realpath($directory);
    $temporaryRoot = realpath(sys_get_temp_dir());
    if ($resolved && $temporaryRoot && dirname($resolved) === $temporaryRoot
        && strpos(basename($resolved), 'uisp-nonfinite-') === 0) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved,
            FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($resolved);
    }
    restore_error_handler();
}
