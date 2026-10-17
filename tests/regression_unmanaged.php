<?php
// Real normalization, webhook lifecycle and durable queue with isolated RouterOS/UCRM adapters.
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
function unmanagedCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$source = realpath(__DIR__ . '/../src');
$original = getcwd();
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-unmanaged-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
foreach (['lib', 'includes', 'data', 'vendor'] as $folder) mkdir($directory . '/' . $folder, 0700);
foreach (glob($source . '/lib/*.php') as $file) copy($file, $directory . '/lib/' . basename($file));
foreach (glob($source . '/includes/*') as $file) if (is_file($file)) copy($file, $directory . '/includes/' . basename($file));
file_put_contents($directory . '/vendor/autoload.php', <<<'PHP'
<?php
namespace Ubnt\UcrmPluginSdk\Service;
class UcrmApi {
    public static array $calls = [];
    public static array $services = [];
    public static function create() { return new self(); }
    public function get($path, $options = []) {
        self::$calls[] = ['get', $path];
        if (preg_match('#^clients/services/([1-9][0-9]*)$#D',$path,$match) && isset(self::$services[(int)$match[1]]))
            return json_decode(json_encode(self::$services[(int)$match[1]]));
        if ($path === 'clients/70') return ['id'=>70,'isArchived'=>false];
        if ($path === 'custom-attributes') return [
            ['id'=>1,'key'=>'device','attributeType'=>'service'],
            ['id'=>2,'key'=>'username','attributeType'=>'service'],
            ['id'=>3,'key'=>'password','attributeType'=>'service'],
            ['id'=>4,'key'=>'address','attributeType'=>'service']];
        throw new \RuntimeException('Network prohibited: ' . $path);
    }
    public function patch($path, $data = []) { self::$calls[] = ['patch', $path]; throw new \RuntimeException('Network prohibited: ' . $path); }
}
PHP);
file_put_contents($directory . '/lib/mt.php', <<<'PHP'
<?php
class MT {
    public static bool $failDelete = false;
    public static bool $failSet = false;
    public static ?string $failDeleteUser = null;
    public static array $writes = [];
    public function do_batch($device, $commands): int {
        foreach ($commands as $command) {
            self::$writes[] = $command;
            if (self::$failDelete && trim($command['path'], '/') === 'ppp/secret' && ($command['action'] ?? '') === 'remove') return 0;
            if (self::$failDeleteUser !== null && trim($command['path'], '/') === 'ppp/secret' && ($command['action'] ?? '') === 'remove' && ($command['name'] ?? null) === self::$failDeleteUser) return 0;
            if (self::$failSet && ($command['action'] ?? '') !== 'remove') return 0;
        }
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
require 'lib/api_action.php';
require 'lib/api_trim.php';
try {
    $apiSqlite = new ApiSqlite('data/data.db');
    $apiSqlite->exec(file_get_contents('includes/schema.sql'));
    (new ApiSetup())->config_load();
    $apiSqlite->saveConfig(['version' => MY_VERSION, 'disable_contention' => true, 'auto_ppp_user' => true,
        'device_name_attr' => 'device', 'pppoe_user_attr' => 'username', 'pppoe_pass_attr' => 'password', 'ip_addr_attr' => 'address']);
    $apiSqliteCache = new ApiSqlite('data/cache.db');
    $apiSqliteCache->exec(file_get_contents('includes/cache.sql'));
    $apiJobQueue = myJobQueue();
    $apiSqlite->insert(['id' => 10, 'name' => 'Fixture router', 'type' => 'mikrotik', 'ip' => '192.0.2.10', 'user' => 'fixture', 'password' => 'fixture'], 'devices');
    $apiSqlite->insert(['id' => 1, 'name' => 'Fixture plan', 'ratio' => 1, 'uploadSpeed' => 20, 'downloadSpeed' => 50], 'plans');
    $apiSqliteCache->insert(['id' => 70, 'firstName' => 'Fixture', 'lastName' => 'Client'], 'clients');
    $attributes = static function (?string $site, ?string $user, ?string $ip): array {
        return [(object) ['key' => 'device', 'value' => $site], (object) ['key' => 'username', 'value' => $user],
            (object) ['key' => 'password', 'value' => 'documentation'], (object) ['key' => 'address', 'value' => $ip]];
    };
    $sequence = 0;
    $event = static function (int $id, string $change, array $fields, ?object $previous = null) use (&$sequence): ApiAction {
        // Delivery fields may be partial. The live fixture independently represents a full current UISP service.
        $current=(array)(\Ubnt\UcrmPluginSdk\Service\UcrmApi::$services[$id]??['id'=>$id,'clientId'=>70,'status'=>1,'servicePlanId'=>1,'attributes'=>[]]);
        $current=array_replace($current,$fields,['id'=>$id,'clientId'=>70]);
        $current['status']=['suspend'=>3,'unsuspend'=>1,'activate'=>1,'end'=>2,'delete'=>2][$change]??$current['status'];
        \Ubnt\UcrmPluginSdk\Service\UcrmApi::$services[$id]=(object)$current;
        $request = (object) ['entity' => 'service', 'entityId' => $id, 'changeType' => $change, 'uuid' => 'unmanaged-event-' . (++$sequence),
            'extraData' => (object) ['entity' => (object) array_replace(['id' => $id, 'clientId' => 70], $fields)]];
        if ($previous !== null) $request->extraData->entityBeforeEdit = $previous;
        $action = new ApiAction($request); $action->exec(); return $action;
    };
    $rejectSite = static function (callable $operation): void {
        try { $operation(); }
        catch (InvalidArgumentException $error) {
            unmanagedCheck($error->getMessage() === 'service_site_not_configured', 'Site validation exposed an unexpected error');
            return;
        }
        throw new RuntimeException('An unknown or ambiguous selected Site was accepted as documentary');
    };
    $cached = static fn(int $id): array => $apiSqliteCache->singleQuery('SELECT * FROM services WHERE id=:id', true, ['id' => $id]) ?: [];
    $network = static fn(int $id): array => $apiSqliteCache->singleQuery('SELECT * FROM network WHERE id=:id', true, ['id' => $id]) ?: [];
    $doc = ['status' => 1, 'servicePlanId' => 1, 'uploadSpeed' => 20, 'downloadSpeed' => 50,
        'attributes' => $attributes(null, 'external-documentation', '10.32.5.38 /30')];
    foreach (['insert', 'edit', 'suspend', 'unsuspend', 'activate'] as $change) {
        $action = $event(1353, $change, $doc);
        unmanagedCheck(!$action->status()->error, 'Documentary service failed on ' . $change);
    }
    unmanagedCheck($cached(1353)['device'] === null && $cached(1353)['username'] === 'external-documentation'
        && $network(1353)['address'] === '10.32.5.38 /30', 'Documentary attributes were rewritten');
    unmanagedCheck($apiJobQueue->list() === [] && MT::$writes === []
        && array_filter(\Ubnt\UcrmPluginSdk\Service\UcrmApi::$calls,static fn(array $call):bool=>$call!==['get','clients/services/1353']) === [], 'A documentary service provisioned or rewrote UISP');
    $empty = $doc; $empty['attributes'] = $attributes(null, null, null);
    unmanagedCheck(!$event(1354, 'insert', $empty)->status()->error, 'A Site-less service with no credentials failed');
    unmanagedCheck(array_filter(\Ubnt\UcrmPluginSdk\Service\UcrmApi::$calls,static fn(array $call):bool=>$call[0]!=='get'||!in_array($call[1],['clients/services/1353','clients/services/1354'],true)) === [], 'Site-less service generated credentials');
    unmanagedCheck(!$event(1354, 'end', [])->status()->error && (int) $cached(1354)['status'] === 2, 'Documentary termination failed');
    unmanagedCheck(!$event(1354, 'delete', [])->status()->error && $cached(1354) === [] && $network(1354) === [], 'Documentary deletion left cached data');
    unmanagedCheck($apiJobQueue->list() === [], 'Documentary terminal events enqueued RouterOS jobs');
    // Documentary addresses must not occupy the plugin's pools, whether valid hosts or CIDR notes.
    $validDoc = $doc; $validDoc['attributes'] = $attributes(null, 'external-host-note', '10.32.5.37');
    unmanagedCheck(!$event(1355, 'insert', $validDoc)->status()->error, 'A valid documentary IP was not cached');
    $ip = new ApiIP();
    unmanagedCheck($ip->assign(1400, (object) ['pool' => '10.32.5.36/30'], false) === '10.32.5.37', 'Documentary IPs consumed the managed pool');
    unmanagedCheck(!is_file('data/plugin.log') || strpos(file_get_contents('data/plugin.log'), 'invalid_cached_address') === false,
        'A documentary CIDR emitted invalid_cached_address');
    unmanagedCheck($network(1353)['address'] === '10.32.5.38 /30', 'Pool allocation rewrote documentary CIDR');
    // A managed IPv6 note without a prefix occupies the router's configured client prefix length.
    $apiSqlite->edit(['id' => 10, 'pfxLength' => 56, 'pool6' => '2001:db8:4000::/48'], 'devices');
    $apiSqliteCache->insert(['id' => 1410, 'clientId' => 70, 'device' => 10, 'planId' => 1, 'status' => 1,
        'username' => 'managed-ipv6-cache', 'password' => 'fixture'], 'services');
    $apiSqliteCache->insert(['id' => 1410, 'address6' => '2001:db8:4000::abcd'], 'network');
    $apiSqliteCache->edit(['id' => 1355, 'address6' => '2001:db8:4000:100::abcd'], 'network');
    unmanagedCheck((new ApiIP())->assign(1411, (object) ['pool6' => '2001:db8:4000::/48', 'pfxLength' => 56], true)
        === '2001:db8:4000:100::/56', 'Cached managed IPv6 did not reserve its whole router /56 or documentary IPv6 consumed the pool');
    $apiSqlite->edit(['id' => 10, 'pfxLength' => 64, 'pool6' => null], 'devices');
    // Adding a legitimate Site must not create a fictitious deletion against router 0.
    $assigned = $doc; $assigned['attributes'] = $attributes('Fixture router', 'external-documentation', '192.0.2.135');
    unmanagedCheck(!$event(1353, 'edit', $assigned)->status()->error, 'Assigning a Site to a documentary service failed');
    $property = new ReflectionProperty(ApiJobQueue::class, 'db');
    if (PHP_VERSION_ID < 80100) $property->setAccessible(true);
    $queueDatabase = $property->getValue($apiJobQueue);
    unmanagedCheck((int) $queueDatabase->querySingle('SELECT count(*) FROM jobs') === 1
        && $queueDatabase->querySingle('SELECT operation FROM jobs') === 'update'
        && (int) $apiJobQueue->appliedSnapshot(1353)['device'] === 10, 'Site assignment created a fictitious previous deletion');
    unmanagedCheck($apiSqlite->selectAddress(1353, false) === '192.0.2.135', 'Assigned service did not reserve its address');
    // A non-empty unmatched Site must not masquerade as explicit removal of Site.
    $beforeUnknown = [$cached(1353), $network(1353), $apiJobQueue->appliedSnapshot(1353), MT::$writes,
        $queueDatabase->querySingle('SELECT count(*) FROM jobs')];
    foreach (['Unknown fixture Site', '10'] as $site) {
        $unknown = $assigned; $unknown['attributes'] = $attributes($site, 'changed-but-invalid', '192.0.2.199');
        $rejectSite(static fn() => $event(1353, 'edit', $unknown));
        unmanagedCheck([$cached(1353), $network(1353), $apiJobQueue->appliedSnapshot(1353), MT::$writes,
            $queueDatabase->querySingle('SELECT count(*) FROM jobs')] === $beforeUnknown,
            'An unmatched Site mutated the current cache, reservation, applied account or router queue');
    }
    $unknownNew = $assigned; $unknownNew['attributes'] = $attributes('Unknown fixture Site', 'new-invalid', null);
    $rejectSite(static fn() => $event(1390, 'insert', $unknownNew));
    unmanagedCheck($cached(1390) === [] && $network(1390) === [] && MT::$writes === $beforeUnknown[3],
        'An unknown Site was cached or provisioned for a new service');
    $apiSqlite->insert(['id' => 11, 'name' => '  FIXTURE ROUTER  ', 'type' => 'mikrotik', 'ip' => '192.0.2.11',
        'user' => 'fixture', 'password' => 'fixture'], 'devices');
    $apiAttributes = new ApiAttributes();
    $rejectSite(static fn() => $event(1353, 'edit', $assigned));
    unmanagedCheck([$cached(1353), $network(1353), $apiJobQueue->appliedSnapshot(1353), MT::$writes,
        $queueDatabase->querySingle('SELECT count(*) FROM jobs')] === $beforeUnknown,
        'Normalized duplicate router names selected a device or detached the existing service');
    $apiSqlite->delete(11, 'devices');
    $apiAttributes = new ApiAttributes();
    // Attribute labels that are not configured are not inferred as a selected Site.
    $apiSqlite->saveConfig(['device_name_attr' => null]);
    $apiAttributes = new ApiAttributes();
    unmanagedCheck(myAttr()->extract($attributes('Unknown fixture Site', 'documentation-only', null))['device'] === null,
        'An unconfigured attribute was inferred as a routing selection');
    $apiSqlite->saveConfig(['device_name_attr' => 'device']);
    $apiAttributes = new ApiAttributes();
    // Removing an actual Site must retain the old reservation while RouterOS cleanup is offline.
    MT::$failDelete = true;
    $detached = $assigned; $detached['attributes'] = $attributes(null, 'external-documentation', '192.0.2.135');
    unmanagedCheck($event(1353, 'edit', $detached)->status()->error, 'Offline detach falsely reported successful cleanup');
    unmanagedCheck($cached(1353)['device'] === null && $apiSqlite->selectAddress(1353, false) === '192.0.2.135'
        && $apiJobQueue->isServiceBlocked(1353), 'Detach released resources before confirmed cleanup');
    MT::$failDelete = false;
    $apiJobQueue->run(array_keys($apiJobQueue->list()));
    unmanagedCheck($apiJobQueue->appliedSnapshot(1353) === [] && $apiSqlite->selectAddress(1353, false) === null
        && $cached(1353)['device'] === null && $cached(1353)['username'] === 'external-documentation',
        'Confirmed detach did not clean old resources while preserving documentation');
    unmanagedCheck(!$event(1353, 'edit', $assigned)->status()->error && !$apiJobQueue->isServiceBlocked(1353), 'A fresh Site assignment could not clear cleanup intent');
    // A managed manual IPv4 is a host only; reject a mask without rewriting it or blocking other services.
    $badManual = $assigned; $badManual['attributes'] = $attributes('Fixture router', 'bad-manual', '198.51.100.10 /30');
    unmanagedCheck($event(1359, 'insert', $badManual)->status()->error, 'A managed manual CIDR was accepted');
    unmanagedCheck($apiSqlite->selectAddress(1359, false) === null && $network(1359)['address'] === '198.51.100.10 /30',
        'Rejected managed CIDR was normalized or reserved');
    $goodManual = $assigned; $goodManual['attributes'] = $attributes('Fixture router', 'good-manual', '198.51.100.11');
    unmanagedCheck(!$event(1360, 'insert', $goodManual)->status()->error && $apiSqlite->selectAddress(1360, false) === '198.51.100.11',
        'A rejected manual CIDR stopped another managed service');
    $apiSqlite->edit(['id' => 10, 'pool' => '10.32.6.0/30'], 'devices');
    $poolService = $assigned; $poolService['attributes'] = $attributes('  fIXTURE rOUTER  ', 'site-pool-client', null);
    unmanagedCheck(!$event(1358, 'insert', $poolService)->status()->error && $apiSqlite->selectAddress(1358, false) === '10.32.6.1',
        'Empty manual IPv4 did not use the configured Site pool');
    // An obsolete previous Site label may be recovered only from this service's confirmed
    // applied identity. Explicit desired status/plan/credentials still take precedence.
    $apiSqlite->insert(['id' => 20, 'name' => 'Old fixture router', 'type' => 'mikrotik', 'ip' => '192.0.2.20',
        'user' => 'fixture', 'password' => 'fixture'], 'devices');
    $apiSqlite->insert(['id' => 2, 'name' => 'New fixture plan', 'ratio' => 1, 'uploadSpeed' => 30, 'downloadSpeed' => 60], 'plans');
    $apiAttributes = new ApiAttributes();
    $oldFields = $assigned; $oldFields['attributes'] = $attributes('Old fixture router', 'old-route-account', '192.0.2.210');
    unmanagedCheck(!$event(1380, 'insert', $oldFields)->status()->error, 'Old Site fixture did not provision');
    $apiSqlite->edit(['id' => 20, 'name' => 'Renamed fixture router'], 'devices');
    $apiAttributes = new ApiAttributes();
    $newFields = $assigned;
    $newFields['status'] = 3; $newFields['servicePlanId'] = 2; $newFields['uploadSpeed'] = 30; $newFields['downloadSpeed'] = 60;
    $newFields['attributes'] = $attributes('Fixture router', 'new-route-account', '192.0.2.211');
    $newFields['attributes'][2]->value = 'new route password';
    $oldEntity = (object) array_replace(['id' => 1380, 'clientId' => 70], $oldFields);
    $beforeMove = count(MT::$writes);
    unmanagedCheck(!$event(1380, 'edit', $newFields, $oldEntity)->status()->error, 'A renamed previous Site blocked a valid managed move');
    $moved = $apiJobQueue->appliedSnapshot(1380);
    unmanagedCheck((int) $moved['device'] === 10 && $moved['username'] === 'new-route-account'
        && $moved['password'] === 'new route password' && (int) $moved['planId'] === 2 && (int) $moved['status'] === 3
        && $cached(1380)['username'] === 'new-route-account' && (int) $cached(1380)['status'] === 3,
        'Applied fallback replaced explicit desired routing, plan, billing or credentials');
    $moveDeletes = array_filter(array_slice(MT::$writes, $beforeMove), static fn(array $command): bool =>
        trim($command['path'], '/') === 'ppp/secret' && ($command['action'] ?? '') === 'remove');
    unmanagedCheck(count($moveDeletes) === 1 && reset($moveDeletes)['name'] === 'old-route-account',
        'Obsolete Site recovery deleted a different account than the confirmed previous identity');
    $deletedFields = $assigned;
    $deletedFields['attributes'] = $attributes('Renamed fixture router', 'deleted-site-account', '192.0.2.212');
    unmanagedCheck(!$event(1382, 'insert', $deletedFields)->status()->error, 'Deleted Site fixture did not provision');
    $apiSqlite->delete(20, 'devices');
    $apiAttributes = new ApiAttributes();
    $replacementFields = $assigned;
    $replacementFields['attributes'] = $attributes('Fixture router', 'replacement-site-account', '192.0.2.213');
    $deletedPrevious = (object) array_replace(['id' => 1382, 'clientId' => 70], $deletedFields);
    $beforeDeletedMove = count(MT::$writes);
    unmanagedCheck($event(1382, 'edit', $replacementFields, $deletedPrevious)->status()->error
        && (int) $cached(1382)['device'] === 10 && (int) $apiJobQueue->appliedSnapshot(1382)['device'] === 20
        && count(MT::$writes) === $beforeDeletedMove,
        'A removed previous Site bypassed router retirement checks or lost the desired/applied identities');
    $deletedPending = [];
    foreach ($apiJobQueue->list() as $key => $job) if ((int) $job['id'] === 1382) $deletedPending[] = $key;
    $deletedPayload = json_decode($queueDatabase->querySingle("SELECT payload FROM jobs WHERE service_id=1382 AND operation='delete' ORDER BY id DESC LIMIT 1"), true);
    unmanagedCheck(count($deletedPending) === 2 && ($deletedPayload['_device']['ip'] ?? null) === '192.0.2.20'
        && ($deletedPayload['username'] ?? null) === 'deleted-site-account',
        'An obsolete previous Site did not preserve the recorded endpoint in the durable migration');
    // Restoring the retired router permits the original migration to finish without
    // resolving the obsolete UISP label again or substituting the new router as its target.
    $apiSqlite->insert(['id' => 20, 'name' => 'Restored fixture router', 'type' => 'mikrotik', 'ip' => '192.0.2.20',
        'user' => 'fixture', 'password' => 'fixture'], 'devices');
    $apiAttributes = new ApiAttributes();
    $apiJobQueue->run($deletedPending);
    unmanagedCheck($apiJobQueue->completed($deletedPending) && (int) $apiJobQueue->appliedSnapshot(1382)['device'] === 10,
        'Restoring the known old endpoint did not resume its pending migration');
    $deletedMoveCommands = array_filter(array_slice(MT::$writes, $beforeDeletedMove), static fn(array $command): bool =>
        trim($command['path'], '/') === 'ppp/secret' && ($command['action'] ?? '') === 'remove');
    unmanagedCheck(count($deletedMoveCommands) === 1 && reset($deletedMoveCommands)['name'] === 'deleted-site-account',
        'Migration from a removed Site guessed a different account to delete');
    // An untrusted old delivery label cannot supply a cleanup target; the valid fresh target remains authoritative.
    $missingPrevious = clone $oldEntity; $missingPrevious->id = 1381;
    $beforeMissingPrevious = count(MT::$writes);
    $freshIndependent=$newFields;$freshIndependent['attributes']=$attributes('Fixture router','independent-fresh-account','192.0.2.214');
    unmanagedCheck(!$event(1381,'edit',$freshIndependent,$missingPrevious)->status()->error
        && ($apiJobQueue->appliedSnapshot(1381)['username']??null)==='independent-fresh-account'
        && count(array_filter(array_slice(MT::$writes,$beforeMissingPrevious),static fn(array $command):bool=>trim($command['path'],'/')==='ppp/secret'&&($command['action']??'')==='remove'))===0,
        'An unknown previous Site overrode fresh UISP intent or generated a guessed cleanup');
    // A partial provisioning write can survive a cache refresh that already removed Site.
    $partial = $assigned; $partial['attributes'] = $attributes('Fixture router', 'partial-account', '192.0.2.136');
    MT::$failSet = true;
    unmanagedCheck($event(1356, 'insert', $partial)->status()->error, 'Partial provisioning fixture unexpectedly succeeded');
    unmanagedCheck($apiJobQueue->appliedSnapshot(1356) === [], 'Partial fixture published applied state');
    $apiSqliteCache->edit(['id' => 1356, 'device' => null], 'services');
    // A confirmed detach supersedes the old set. Only a failed removal may
    // keep its possible physical effects and reservation pending.
    MT::$failDelete = true;
    $beforeCleanup = count(MT::$writes);
    unmanagedCheck($event(1356, 'edit', ['attributes'=>$attributes(null,'partial-account','192.0.2.136')])->status()->error && $apiJobQueue->isServiceBlocked(1356),
        'A failed retirement did not retain its physical cleanup and reservation');
    $pendingDelete = $queueDatabase->querySingle("SELECT payload FROM jobs WHERE service_id=1356 AND operation='delete' ORDER BY id DESC LIMIT 1");
    unmanagedCheck(is_string($pendingDelete), 'Partially provisioned resources were silently ignored');
    foreach (json_decode($pendingDelete, true)['_commands'] as $command) unmanagedCheck(($command['action'] ?? '') === 'remove', 'Cleanup retained a prepared set command');
    unmanagedCheck($apiSqlite->selectAddress(1356, false) === '192.0.2.136',
        'A blocked detach released its potentially provisioned address before cleanup');
    MT::$failSet = false;
    MT::$failDelete = false;
    $apiJobQueue->run(array_keys($apiJobQueue->list()));
    $cleanupWrites = array_values(array_filter(array_slice(MT::$writes, $beforeCleanup), static function (array $command): bool {
        return ($command['action'] ?? '') === 'remove' && ($command['_service_id'] ?? null) === 1356;
    }));
    unmanagedCheck($cleanupWrites !== [], 'The worker did not remove partial account resources');
    unmanagedCheck($cached(1356)['device'] === null && $apiJobQueue->appliedSnapshot(1356) === [], 'Partial cleanup replaced desired documentation');
    // If the router recovered before Site removal, the same ordered cleanup completes immediately.
    $recoveredPartial = $assigned;
    $recoveredPartial['attributes'] = $attributes('Fixture router', 'recovered-partial-account', '192.0.2.137');
    MT::$failSet = true;
    unmanagedCheck($event(1357, 'insert', $recoveredPartial)->status()->error
        && $apiJobQueue->appliedSnapshot(1357) === [] && $apiSqlite->selectAddress(1357, false) === '192.0.2.137',
        'Recovered-detach fixture did not retain its failed prepared identity/address');
    $apiSqliteCache->edit(['id' => 1357, 'device' => null], 'services');
    MT::$failSet = false; $beforeRecoveredCleanup = count(MT::$writes);
    $recoveredDetach = $event(1357, 'edit', ['attributes'=>$attributes(null,'recovered-partial-account','192.0.2.137')]);
    $recoveredAccountWrites = array_values(array_filter(array_slice(MT::$writes, $beforeRecoveredCleanup),
        static fn(array $command): bool => trim($command['path'], '/') === 'ppp/secret'
            && ($command['_service_id'] ?? null) === 1357));
    unmanagedCheck(!$recoveredDetach->status()->error && $recoveredAccountWrites !== []
        && count(array_filter($recoveredAccountWrites, static fn(array $item):bool=>($item['action']??'')!=='remove'
            || ($item['name']??'')!=='recovered-partial-account'))===0
        && $apiJobQueue->completed($recoveredDetach->result()['jobKeys'])
        && $apiJobQueue->isServiceBlocked(1357) && $cached(1357)['device'] === null
        && $apiJobQueue->appliedSnapshot(1357) === [] && $apiSqlite->selectAddress(1357, false) === null,
        'Recovered immediate detach lost identity/order, left applied resources, or restored the documentary Site: ' . json_encode([
            'error'=>$recoveredDetach->status()->error, 'writes'=>array_map(static fn(array $item):array=>[$item['path'],$item['action']??'set',$item['name']??null],$recoveredAccountWrites),
            'completed'=>$apiJobQueue->completed($recoveredDetach->result()['jobKeys']), 'blocked'=>$apiJobQueue->isServiceBlocked(1357),
            'cachedDevice'=>$cached(1357)['device'], 'applied'=>$apiJobQueue->appliedSnapshot(1357)!==[], 'address'=>$apiSqlite->selectAddress(1357,false)]));
    // An old applied account and a different prepared account must both be retired on Site removal.
    $oldAccount = $assigned; $oldAccount['attributes'] = $attributes('Fixture router', 'old-account-1370', '192.0.2.170');
    unmanagedCheck(!$event(1370, 'insert', $oldAccount)->status()->error, 'Old identity fixture was not provisioned');
    $preparedNew = $apiJobQueue->appliedSnapshot(1370);
    $preparedNew['username'] = 'new-account-1370';
    $preparedNew['address'] = '192.0.2.171';
    $preparedNew['network'] = ['address' => '192.0.2.171'];
    $preparedNew['_prior_network'] = ['address' => '192.0.2.170', 'address6' => null];
    $preparedNew['_event_id'] = 'legacy-prepared-new-account-1370';
    foreach ($preparedNew['_commands'] as &$command) {
        if (($command['name'] ?? null) === 'old-account-1370') $command['name'] = 'new-account-1370';
        if (isset($command['remote-address'])) $command['remote-address'] = '192.0.2.171';
        if (isset($command['target'])) $command['target'] = '192.0.2.171/32';
    }
    unset($command);
    (new ApiIP())->set(1370, '192.0.2.171'); // Address reservation precedes freezing/writing a newly prepared job.
    MT::$failSet = true;
    $preparedKey = $apiJobQueue->enqueue($preparedNew);
    $apiJobQueue->run([$preparedKey]);
    unmanagedCheck(($apiJobQueue->appliedSnapshot(1370)['username'] ?? null) === 'old-account-1370', 'Prepared fixture lost old applied state');
    $apiSqliteCache->edit(['id' => 1370, 'device' => null, 'username' => 'new-account-1370'], 'services');
    $apiSqliteCache->edit(['id' => 1370, 'address' => '192.0.2.171'], 'network');
    MT::$failDeleteUser = 'new-account-1370';
    unmanagedCheck($event(1370, 'edit', ['attributes'=>$attributes(null,'new-account-1370','192.0.2.171')])->status()->error, 'Prepared detach bypassed its outstanding barrier');
    unmanagedCheck((int) $queueDatabase->querySingle("SELECT count(*) FROM jobs WHERE service_id=1370 AND operation='delete' AND status='pending'") === 2,
        'Detaching multiple identities omitted an old or prepared account');
    MT::$failSet = false; MT::$failDeleteUser = 'new-account-1370';
    $beforeMultiCleanup = count(MT::$writes);
    $apiJobQueue->run(array_keys($apiJobQueue->list()));
    $stillReserved = false;
    try { (new ApiIP())->set(1399, '192.0.2.171'); }
    catch (NetworkAddressConflict $conflict) { $stillReserved = ($conflict->context()['blockingServiceId'] ?? null) === 1370; }
    unmanagedCheck(($apiJobQueue->appliedSnapshot(1370)['username'] ?? null) !== 'new-account-1370'
        && $stillReserved, 'The first cleanup released the other identity before its confirmation: ' . json_encode([
            'applied' => $apiJobQueue->appliedSnapshot(1370)['username'] ?? null, 'address' => $apiSqlite->selectAddress(1370, false),
            'pending' => array_map(static fn(array $item): array => [$item['id'], $item['action'], $item['error']], $apiJobQueue->list())]));
    MT::$failDeleteUser = null;
    $apiJobQueue->run(array_keys($apiJobQueue->list()));
    $removedAccounts = [];
    foreach (array_slice(MT::$writes, $beforeMultiCleanup) as $command) {
        if (trim($command['path'], '/') === 'ppp/secret' && ($command['action'] ?? '') === 'remove') $removedAccounts[] = $command['name'];
    }
    unmanagedCheck(in_array('old-account-1370', $removedAccounts, true) && in_array('new-account-1370', $removedAccounts, true)
        && $apiJobQueue->appliedSnapshot(1370) === [] && $apiSqlite->selectAddress(1370, false) === null,
        'Site removal left a prepared account or released its reservation prematurely');
    (new ApiIP())->set(1399, '192.0.2.171');
    unmanagedCheck($apiSqlite->selectAddress(1399, false) === '192.0.2.171', 'Completed retirement retained the prepared address');
    // The same multi-identity cleanup is required for a terminal webhook whose Site is still assigned.
    $terminalOld = $assigned; $terminalOld['attributes'] = $attributes('Fixture router', 'old-account-1371', '192.0.2.180');
    unmanagedCheck(!$event(1371, 'insert', $terminalOld)->status()->error, 'Terminal old identity fixture was not provisioned');
    $terminalNew = $apiJobQueue->appliedSnapshot(1371);
    $terminalNew['username'] = 'new-account-1371';
    $terminalNew['address'] = '192.0.2.181';
    $terminalNew['network'] = ['address' => '192.0.2.181'];
    $terminalNew['_prior_network'] = ['address' => '192.0.2.180', 'address6' => null];
    $terminalNew['_event_id'] = 'legacy-prepared-new-account-1371';
    foreach ($terminalNew['_commands'] as &$command) {
        if (($command['name'] ?? null) === 'old-account-1371') $command['name'] = 'new-account-1371';
        if (isset($command['remote-address'])) $command['remote-address'] = '192.0.2.181';
        if (isset($command['target'])) $command['target'] = '192.0.2.181/32';
    }
    unset($command);
    (new ApiIP())->set(1371, '192.0.2.181');
    MT::$failSet = true;
    $terminalPreparedKey = $apiJobQueue->enqueue($terminalNew);
    $apiJobQueue->run([$terminalPreparedKey]);
    $apiSqliteCache->edit(['id' => 1371, 'username' => 'new-account-1371'], 'services');
    $apiSqliteCache->edit(['id' => 1371, 'address' => '192.0.2.181'], 'network');
    MT::$failDelete = true;
    unmanagedCheck($event(1371, 'delete', [])->status()->error && $apiJobQueue->isServiceBlocked(1371),
        'Failed terminal cleanup released a prepared identity');
    unmanagedCheck((int) $queueDatabase->querySingle("SELECT count(*) FROM jobs WHERE service_id=1371 AND operation='delete' AND status='pending'") === 2,
        'Terminal deletion omitted the old or prepared identity');
    MT::$failSet = false; MT::$failDelete = false; $beforeTerminalCleanup = count(MT::$writes);
    $apiJobQueue->run(array_keys($apiJobQueue->list()));
    $terminalRemoved = [];
    foreach (array_slice(MT::$writes, $beforeTerminalCleanup) as $command) {
        if (trim($command['path'], '/') === 'ppp/secret' && ($command['action'] ?? '') === 'remove') $terminalRemoved[] = $command['name'];
    }
    unmanagedCheck(in_array('old-account-1371', $terminalRemoved, true) && in_array('new-account-1371', $terminalRemoved, true)
        && $apiJobQueue->appliedSnapshot(1371) === [] && $apiSqlite->selectAddress(1371, false) === null,
        'Terminal deletion left an applied/prepared account or reservation');
    echo "Unmanaged lifecycle checks passed: documentary events/IPs, unknown/ambiguous Site rejection before writes, safe migration from renamed/deleted Sites, no credential generation, host-only manual IPv4, Site pools, fresh Site assignment, durable detach, reservation retention and partial cleanup.\n";
} finally {
    if (isset($apiJobQueue)) $apiJobQueue->close();
    if (isset($apiSqlite)) $apiSqlite->close();
    if (isset($apiSqliteCache)) $apiSqliteCache->close();
    chdir($original);
    $resolved = realpath($directory); $temporaryRoot = realpath(sys_get_temp_dir());
    if ($resolved && $temporaryRoot && strpos($resolved, $temporaryRoot . DIRECTORY_SEPARATOR . 'uisp-unmanaged-') === 0) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($resolved);
    }
}
