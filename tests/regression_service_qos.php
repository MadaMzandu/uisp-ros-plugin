<?php
// Service overrides isolate shaping while preserving their commercial plan.
set_error_handler(static function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
require_once __DIR__ . '/../src/lib/mt_data.php';

function checkServiceQos(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
class ServiceQosAddress extends ApiIP
{
    public function beginService(int $serviceId): void {}
}
class ServiceQosBuilder extends MtData
{
    protected function ipapi(): ApiIP { static $addresses; return $addresses ??= new ServiceQosAddress(true); }
    protected function ip($ip6 = false): ?string { return $ip6 ? null : '192.0.2.71'; }
    public function planForTest(): array { return $this->plan; }
}

$base = ['id' => 9, 'name' => 'Fixture Plan', 'ratio' => 2,
    'uploadSpeed' => 5, 'downloadSpeed' => 20, 'priorityUpload' => 4, 'priorityDownload' => 5,
    'timeUpload' => 8, 'timeDownload' => 10,
    '_sourceUploadSpeed' => 4, '_sourceDownloadSpeed' => 15,
    '_sourceUploadOverride' => 5, '_sourceDownloadOverride' => 20,
    '_sourceLimitUpload' => 50, '_sourceLimitDownload' => 25,
    '_sourceBurstUpload' => 100, '_sourceBurstDownload' => 50,
    '_sourceThreshUpload' => 20, '_sourceThreshDownload' => 40,
    'limitUpload' => 2500000, 'limitDownload' => 15000000,
    'burstUpload' => 10000000, 'burstDownload' => 30000000,
    'threshUpload' => 4000000, 'threshDownload' => 12000000];
$service = ['id' => 71, 'clientId' => 31, 'planId' => 9, 'status' => 1, 'username' => 'fixture-qos',
    'company' => null, 'firstName' => 'Fixture', 'lastName' => 'Client',
    'password' => 'fixture-only', 'uploadSpeed' => 4, 'downloadSpeed' => 15,
    'uploadSpeedOverride' => 10, 'downloadSpeedOverride' => 35,
    'trafficShapingOverrideEnabled' => true,
    '_config' => ['disable_contention' => true, 'active_list' => 'fixture-active', 'disabled_profile' => 'fixture-disabled']];

$effective = ApiServiceQos::effectivePlan($service, $base);
checkServiceQos($effective['id'] === 9 && $effective['_sourceDownloadSpeed'] === 15
    && $effective['_sourceDownloadOverride'] === 20, 'Service override changed the commercial plan or its source revision');
checkServiceQos($base['uploadSpeed'] === 5 && $base['downloadSpeed'] === 20, 'Shared base was mutated');
checkServiceQos($effective['uploadSpeed'] === 10 && $effective['downloadSpeed'] === 35
    && $effective['ratio'] === 2 && $effective['timeDownload'] === 10, 'Service rates did not win or inherited QoS settings changed');
checkServiceQos($effective['limitUpload'] == 5000000 && $effective['limitDownload'] == 26250000
    && $effective['burstUpload'] == 20000000 && $effective['burstDownload'] == 52500000
    && $effective['threshUpload'] == 8000000 && $effective['threshDownload'] == 21000000,
    'Burst, threshold or guaranteed rate did not scale from original percentages');
checkServiceQos(ApiServiceQos::effectivePlan($service, $effective) === $effective, 'Repeated calculation compounded the override');
$unlimited = ApiServiceQos::effectivePlan(array_replace($service, ['uploadSpeedOverride' => null]), $effective);
checkServiceQos($unlimited['uploadSpeed'] === 0 && $unlimited['downloadSpeed'] === 35
    && $unlimited['_serviceQos']['uploadUnlimited'] && $unlimited['limitUpload'] == 0 && $unlimited['burstUpload'] == 0,
    'Directional NULL override did not produce unlimited service shaping');
foreach (['uploadSpeedOverride', 'downloadSpeedOverride'] as $missingField) {
    $incomplete = $service; unset($incomplete[$missingField]); $rejected = false;
    try { ApiServiceQos::effectivePlan($incomplete, $base); }
    catch (InvalidArgumentException $error) { $rejected = $error->getMessage() === 'invalid_service_shaping_override'; }
    checkServiceQos($rejected, 'An omitted override direction silently removed a service cap');
}
foreach (['yes', 'false', 2, [], 1.0] as $invalidFlag) {
    $rejected = false;
    try { ApiServiceQos::effectivePlan(array_replace($service, ['trafficShapingOverrideEnabled' => $invalidFlag]), $base); }
    catch (InvalidArgumentException $error) { $rejected = $error->getMessage() === 'invalid_service_shaping_override'; }
    checkServiceQos($rejected, 'A malformed override flag silently changed shaping policy');
}
$removed = ApiServiceQos::effectivePlan(array_replace($service, ['trafficShapingOverrideEnabled' => false]), $effective);
checkServiceQos($removed === $base, 'Removing override did not restore the saved base policy');
$legacy = $service; unset($legacy['trafficShapingOverrideEnabled']);
checkServiceQos(ApiServiceQos::effectivePlan($legacy, $base) === $base, 'Legacy services inferred an override from ordinary cached speeds');
$zero = ApiServiceQos::effectivePlan(array_replace($service, ['uploadSpeedOverride' => 0]), $base);
checkServiceQos($zero['uploadSpeed'] === 0 && $zero['burstUpload'] == 0, 'Explicit zero was replaced by the shared plan speed');
$historical = $base;
foreach (array_keys($historical) as $key) if (str_starts_with($key, '_source')) unset($historical[$key]);
$scaled = ApiServiceQos::effectivePlan($service, $historical);
checkServiceQos($scaled['burstDownload'] == 52500000 && $scaled['limitUpload'] == 5000000,
    'A historical prepared policy without percentage metadata lost its proportional shaping');
foreach ([-1, 'broken', INF, NAN, 1.0e308] as $invalid) {
    $rejected = false;
    try { ApiServiceQos::effectivePlan(array_replace($service, ['uploadSpeedOverride' => $invalid]), $base); }
    catch (InvalidProvisioningNumber $error) { $rejected = true; }
    checkServiceQos($rejected, 'Unsafe service speed was accepted');
}

$builder = new ServiceQosBuilder(); $builder->set_data($service, $base);
$profile = $builder->profile(); $account = $builder->account();
checkServiceQos($profile['name'] === 'Fixture Plan [Override S#71]' && $account['profile'] === $profile['name']
    && $profile['_owner'] === 'uisp-ros-plugin:service-profile:71', 'Override profile was not isolated by service');
checkServiceQos($profile['rate-limit'] === '10M/35M 20000000/52500000 8000000/21000000 8/10 4 5000000/26250000',
    'Actual PPP profile did not include effective normal/burst/threshold/time/priority/guaranteed rates');
$snapshot = $service + ['_plan' => $effective];
$prepared = MtData::safePreparedCommands($snapshot, [$profile, $account]);
$retryProfile = array_values(array_filter($prepared, static fn($row) => trim($row['path'], '/') === 'ppp/profile'))[0];
$retryAccount = array_values(array_filter($prepared, static fn($row) => trim($row['path'], '/') === 'ppp/secret'))[0];
checkServiceQos($retryProfile['name'] === $profile['name'] && $retryAccount['profile'] === $profile['name']
    && $retryProfile['_owner'] === $profile['_owner'], 'Retry migration restored a shared profile over a private one');
$oldProfile = array_replace($profile, ['name' => 'uisp-service-71-profile']);
$oldAccount = array_replace($account, ['profile' => $oldProfile['name']]);
$legacyPrepared = MtData::safePreparedCommands($snapshot, [$oldProfile, $oldAccount]);
$migratedProfile = array_values(array_filter($legacyPrepared, static fn($row) => trim($row['path'], '/') === 'ppp/profile'))[0];
$migratedAccount = array_values(array_filter($legacyPrepared, static fn($row) => trim($row['path'], '/') === 'ppp/secret'))[0];
checkServiceQos($migratedProfile['name'] === $profile['name'] && $migratedAccount['profile'] === $profile['name']
    && $migratedProfile['_owner'] === $oldProfile['_owner']
    && $migratedProfile['rate-limit'] === $oldProfile['rate-limit'],
    'An old private-profile retry lost frozen rates or failed to adopt its readable service name');
$legacyCleanup = MtData::safePreparedCommands($snapshot, [array_replace($oldProfile, ['action' => 'remove']),
    array_replace($oldAccount, ['action' => 'remove'])], 'delete');
$legacyCleanupProfile = array_values(array_filter($legacyCleanup, static fn($row) => trim($row['path'], '/') === 'ppp/profile'))[0];
$legacyCleanupAccount = array_values(array_filter($legacyCleanup, static fn($row) => trim($row['path'], '/') === 'ppp/secret'))[0];
checkServiceQos($legacyCleanupProfile['name'] === 'uisp-service-71-profile'
    && $legacyCleanupAccount['profile'] === 'uisp-service-71-profile',
    'Legacy cleanup rewrote an exact stored profile receipt to the new display name');
checkServiceQos(MtData::serviceProfileNameMatches('uisp-service-71-profile', 71)
    && MtData::serviceProfileNameMatches('Fixture Plan [Override S#71]', 71)
    && !MtData::serviceProfileNameMatches('Fixture Plan [Override S#72]', 71)
    && !MtData::serviceProfileNameMatches('Fixture Plan [Override S#71] extra', 71),
    'Readable or legacy private profile matching accepted a different service or trailing text');
$identity = $snapshot + ['device' => 10, '_device' => ['ip' => '192.0.2.10', 'port' => 8728]];
$legacyApplied = array_replace($identity, ['_commands' => [$oldProfile, $oldAccount]]);
$migrated = MtData::retireUnusedServiceProfiles($identity, [$profile, $account], $legacyApplied);
checkServiceQos($migrated === [$profile, $account],
    'Readable migration tried to delete the same owned profile that RouterOS renames in place');
$foreignApplied = array_replace($legacyApplied, ['_commands' => [array_replace($oldProfile,
    ['name' => 'Another Plan [Override S#72]'])]]);
checkServiceQos(MtData::retireUnusedServiceProfiles($identity, [$profile, $account], $foreignApplied) === [$profile, $account],
    'Private migration accepted a friendly profile name for another service');
$builder->set_data(array_replace($service, ['id' => 72]), $base);
checkServiceQos($builder->profile()['name'] === 'Fixture Plan [Override S#72]', 'Services with equal overrides shared mutable profile identity');
$builder->set_data(array_replace($service, ['trafficShapingOverrideEnabled' => false]), $effective);
checkServiceQos($builder->profile()['name'] === 'Fixture Plan [UISP#9]'
    && str_starts_with($builder->profile()['rate-limit'], '5M/20M '), 'Disabling override did not return actual commands to the normal shared profile');
$ordinaryCommands = [$builder->profile(), $builder->account()];
$legacyRetired = MtData::retireUnusedServiceProfiles($identity, $ordinaryCommands, $legacyApplied);
checkServiceQos(count($legacyRetired) === 3 && $legacyRetired[2]['name'] === 'uisp-service-71-profile'
    && $legacyRetired[2]['action'] === 'remove' && $legacyRetired[2]['_owner'] === $oldProfile['_owner'],
    'Removing a legacy override did not retire its exact stored private-profile receipt');
$friendlyApplied = array_replace($identity, ['_commands' => [$profile, $account]]);
$friendlyRetired = MtData::retireUnusedServiceProfiles($identity, $ordinaryCommands, $friendlyApplied);
checkServiceQos(count($friendlyRetired) === 3 && $friendlyRetired[2]['name'] === $profile['name']
    && $friendlyRetired[2]['action'] === 'remove' && $friendlyRetired[2]['_owner'] === $profile['_owner'],
    'Removing a readable override did not retire its exact stored private-profile receipt');
checkServiceQos(MtData::retireUnusedServiceProfiles($identity, $ordinaryCommands, $foreignApplied) === $ordinaryCommands,
    'Override removal trusted an owner marker paired with a different service display name');
$builder->set_data(array_replace($service, ['hotspot' => true]), $base);
checkServiceQos($builder->profile()['path'] === '/ip/hotspot/user/profile/'
    && $builder->account()['profile'] === 'Fixture Plan [Override S#71]', 'Hotspot did not use the service override');
$builder->set_data(array_replace($service, ['mac' => '02:00:00:00:00:71']), $base);
$queue = $builder->queue();
checkServiceQos($builder->profile() === null && $queue['max-limit'] === '10M/35M'
    && $queue['burst-limit'] === '20000000/52500000' && $queue['limit-at'] === '5000000/26250000',
    'DHCP individual queue did not receive the same effective service shaping');
$builder->set_data(array_replace($service, ['status' => 3]), $base);
checkServiceQos($builder->account()['profile'] === 'fixture-disabled', 'An override bypassed suspension');

echo "Service QoS regression passed: isolated profiles, precedence, directional unlimited, effective burst/threshold/minimum, idempotent retries, restoration, invalid speeds, PPP, Hotspot, DHCP and suspension.\n";
