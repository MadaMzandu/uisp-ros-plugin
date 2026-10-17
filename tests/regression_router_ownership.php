<?php
// Synthetic UISP responses and local metadata only: no production accounts or router connections.
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line); return false;
});
function ownershipCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function ownershipReject(callable $operation, string $reason): void {
    try { $operation(); } catch (RouterOwnershipConflict $failure) {
        ownershipCheck($failure->context['reason'] === $reason, 'Unexpected refusal: ' . $failure->getMessage());
        ownershipCheck(preg_match('/^router_ownership_conflict: current_service=[0-9]+ previous_service=[0-9]+ reason=[a-z_]+$/D', $failure->getMessage()) === 1,
            'Failure includes unsanitized remote data'); return;
    }
    throw new RuntimeException('Unsafe authorization accepted: ' . $reason);
}
$root = realpath(__DIR__ . '/../src'); $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-router-ownership-' . bin2hex(random_bytes(8));
mkdir($directory, 0700); mkdir($directory . '/data', 0700);
require_once $root . '/vendor/autoload.php'; require_once $root . '/lib/api_router_ownership.php';
$include = get_include_path(); set_include_path($root . '/lib' . PATH_SEPARATOR . $include);
require_once $root . '/lib/mt.php';
function MyLog() { static $log; return $log ?? ($log = new class { public function Append($entry, $level = 0): void {} }); }
final class OwnershipIntegrationAPI extends RouterosAPI
{
    public array $account = [];
    public array $mutations = [];
    public int $reads = 0;
    public $onRead = null;
    private array $sentence = [];
    public function write($word, $param2 = true) { $this->sentence[] = $word; return true; }
    public function finishSentence(): void {}
    public function disconnect() { $this->connected = false; }
    public function read($parse = true) {
        $words = $this->sentence; $this->sentence = []; $path = array_shift($words);
        if ($path === '/ppp/secret/print') {
            $this->reads++; if ($this->onRead !== null) ($this->onRead)($this);
            foreach ($words as $word) if (strpos($word, '?name=') === 0 && substr($word, 6) !== ($this->account['name'] ?? null)) return [];
            return $this->account ? [$this->account] : [];
        }
        $this->mutations[] = $path;
        ownershipCheck($path === '/ppp/secret/set', 'Integration attempted creation, removal, session or shared-profile mutation');
        $values = [];
        foreach ($words as $word) {
            ownershipCheck(strpos($word, '=') === 0, 'Unexpected RouterOS command word');
            [$key,$value] = explode('=',substr($word,1),2); $values[$key] = $value;
        }
        ownershipCheck(($values['.id'] ?? null) === ($this->account['.id'] ?? null), 'Account set changed RouterOS identity');
        $this->account = array_replace($this->account,$values); return [];
    }
}
final class OwnershipIntegrationMT extends MT
{
    public OwnershipIntegrationAPI $transport;
    public function __construct(OwnershipIntegrationAPI $transport) { $this->transport = $transport; parent::__construct(); }
    protected function load_config(): void { $this->conf = (object) ['fixture' => true]; }
    protected function api_connect(): ?RouterosAPI { $this->transport->connected = true; return $this->transport; }
}
$database = new SQLite3($directory . '/data/data.db'); $database->enableExceptions(true);
try {
    $database->exec(file_get_contents($root . '/includes/schema.sql'));
    $database->exec("INSERT INTO config(key,value) VALUES ('device_name_attr','site'),('pppoe_user_attr','account')");
    $database->exec("INSERT INTO devices(id,name,ip,port,user,password,ssl) VALUES
        (1,'Fixture East','192.0.2.1',8728,'ROUTER-USER-PRIVATE','ROUTER-PASSWORD-PRIVATE',0),
        (2,'Fixture West','192.0.2.2',8728,'ROUTER-USER-PRIVATE','ROUTER-PASSWORD-PRIVATE',0)");
    $device = $database->querySingle('SELECT * FROM devices WHERE id=1', true);
    $service = ['id' => 42, 'clientId' => 17, 'device' => 1, 'username' => 'fixture-user', 'status' => 1];
    $command = ['path' => '/ppp/secret', 'action' => 'set', '_service_id' => 42, '_client_id' => 17,
        'name' => 'fixture-user', 'comment' => '17 - Fixture customer - 42', 'password' => 'ACCOUNT-PASSWORD-PRIVATE', 'profile' => 'fixture-profile'];
    $row = ['.id' => '*A1', 'name' => 'fixture-user', 'comment' => '17 - Former customer label - 41', 'password' => 'OLD-PASSWORD-PRIVATE'];
    $catalog = [['id' => 1, 'key' => 'site', 'attributeType' => 'service'], ['id' => 2, 'key' => 'account', 'attributeType' => 'service']];
    $entity = static fn(int $sid, int $cid = 17, int $status = 1, string $site = 'Fixture East', string $user = 'fixture-user'): array =>
        ['id' => $sid, 'clientId' => $cid, 'status' => $status, 'attributes' => [
            ['key' => 'site', 'customAttributeId' => 1, 'value' => $site], ['key' => 'account', 'customAttributeId' => 2, 'value' => $user]]];
    $target = $entity(42); $previous = $entity(41, 17, 2); $collection = [$target, $previous];
    $calls = []; $getHook = null; $targetReads = 0; $oldReads = 0; $client = ['id' => 17, 'isArchived' => false];
    $transport = static function ($path, $method, $parameters) use (&$calls,&$catalog,&$target,&$previous,&$collection,&$targetReads,&$oldReads,&$getHook,&$client) {
        ownershipCheck($method === 'get', 'Ownership helper attempted a UISP mutation'); $calls[] = [$path,$parameters];
        if ($getHook !== null) $getHook($path);
        if ($path === 'custom-attributes') return $catalog;
        if ($path === 'clients/17') return $client;
        if ($path === 'clients/services/42') { $targetReads++; return $target; }
        if ($path === 'clients/services/41') {
            $oldReads++;
            if ($previous instanceof Throwable) throw $previous;
            return $previous;
        }
        if ($path === 'clients/services') {
            ownershipCheck(($parameters['customAttributeKey'] ?? null) === 'account' && ($parameters['customAttributeValue'] ?? null) === 'fixture-user',
                'Collection was not restricted to the exact configured account attribute');
            return array_slice($collection, $parameters['offset'], $parameters['limit']);
        }
        throw new RuntimeException('Unexpected synthetic request');
    };
    $make = static fn() => new ApiRouterOwnership($service, $device, $transport, $directory . '/data/data.db');
    $hash = hash_file('sha256', $directory . '/data/data.db');
    $verifier = $make(); $proof = $verifier->authorize($command, $row);
    ownershipCheck($proof['current_service'] === 42 && $proof['previous_service'] === 41
        && $verifier->matches($proof, $command, $row, $device), 'Verified obsolete same-client account did not receive narrowly bound permission');
    ownershipCheck(!$make()->matches($proof, $command, $row, $device), 'Permission escaped its request instance');
    ownershipCheck(hash_file('sha256', $directory . '/data/data.db') === $hash && !is_file($directory . '/data/jobs.db'), 'Authorization mutated local allocations or queue');
    $backups = glob($directory . '/data/router-ownership/*.json');
    ownershipCheck(count($backups) === 1, 'Authorization must preserve old ownership metadata first');
    $backup = file_get_contents($backups[0]);
    foreach (['ROUTER-USER-PRIVATE','ROUTER-PASSWORD-PRIVATE','ACCOUNT-PASSWORD-PRIVATE','OLD-PASSWORD-PRIVATE'] as $secret)
        ownershipCheck(strpos($backup, $secret) === false, 'Backup exposed credentials');
    $state = json_decode($backup, true, 32, JSON_THROW_ON_ERROR);
    ownershipCheck($state['account']['row_id'] === '*A1' && $state['account']['old_comment'] === $row['comment']
        && $state['previous_status'] === '2', 'Backup omitted exact former ownership');
    foreach (['.id' => '*A2', 'name' => 'different-user', 'comment' => '17 - Different record - 41'] as $key => $value) {
        $changed = $row; $changed[$key] = $value;
        ownershipCheck(!$verifier->matches($proof, $command, $changed, $device), 'Proof accepted changed router row ' . $key);
    }
    $changed = $command; $changed['profile'] = 'different-profile';
    ownershipCheck(!$verifier->matches($proof, $changed, $row, $device), 'Proof permitted another command');
    $changedDevice = $device; $changedDevice['ip'] = '192.0.2.2';
    ownershipCheck(!$verifier->matches($proof, $command, $row, $changedDevice), 'Proof accepted another router');
    $database->exec("UPDATE config SET value='other-account' WHERE key='pppoe_user_attr'");
    ownershipCheck(!$verifier->matches($proof, $command, $row, $device), 'Proof survived changed routing/attribute configuration');
    $database->exec("UPDATE config SET value='account' WHERE key='pppoe_user_attr'");

    foreach (['/ppp/active','/ip/hotspot/active','/queue/simple','/ppp/profile','/ip/dhcp-server/lease'] as $path) {
        $unsafe = $command; $unsafe['path'] = $path;
        ownershipReject(static fn() => $make()->authorize($unsafe, $row), 'identity_unverified');
    }
    $unsafe = $command; $unsafe['action'] = 'remove';
    ownershipReject(static fn() => $make()->authorize($unsafe, $row), 'identity_unverified');
    foreach (['18 - Another customer - 41', 'manually configured', '', '17 - Same service - 42'] as $comment) {
        $unsafe = $row; $unsafe['comment'] = $comment;
        ownershipReject(static fn() => $make()->authorize($command, $unsafe), 'identity_unverified');
    }
    $unsafe = $row; $unsafe['name'] = 'Fixture-User';
    ownershipReject(static fn() => $make()->authorize($command, $unsafe), 'identity_unverified');
    $unsafe = $row; $unsafe['.id'] = 'arbitrary id';
    ownershipReject(static fn() => $make()->authorize($command, $unsafe), 'identity_unverified');
    $oldSafe = $previous;
    foreach ([0,1,3,4,6,7] as $status) {
        $previous = $entity(41,17,$status); $collection = [$target,$previous];
        ownershipReject(static fn() => $make()->authorize($command,$row), 'previous_active');
    }
    $previous = $entity(41,18,2); $collection = [$target,$previous];
    ownershipReject(static fn() => $make()->authorize($command,$row), 'marker_unverified');
    $previous = $oldSafe; $collection = [$target,$previous,$entity(43)];
    ownershipReject(static fn() => $make()->authorize($command,$row), 'account_claimed');
    $collection[2] = $entity(43,99);
    ownershipReject(static fn() => $make()->authorize($command,$row), 'account_claimed');
    $collection[2] = $entity(43,99,1,'Fixture West');
    $allowed = $make(); ownershipCheck($allowed->matches($allowed->authorize($command,$row),$command,$row,$device), 'Unrelated account on another physical router prevented repair');
    $database->exec("UPDATE devices SET ip='192.0.2.1' WHERE id=2");
    ownershipReject(static fn() => $make()->authorize($command,$row), 'account_claimed');
    $database->exec("UPDATE devices SET ip='192.0.2.2' WHERE id=2");
    $collection[2] = $entity(43,99,1,'Unknown Site');
    ownershipReject(static fn() => $make()->authorize($command,$row), 'peer_site_unverified');
    $collection = [$target,$previous];

    // Exact authenticated HTTP404 is absence; strings, empty results and other HTTP errors are not.
    $http = static fn(int $code) => new GuzzleHttp\Exception\RequestException('PRIVATE FAILURE BODY',
        new GuzzleHttp\Psr7\Request('GET','https://example.invalid/clients/services/41'), new GuzzleHttp\Psr7\Response($code));
    $previous = $http(404); $collection = [$target];
    $allowed = $make(); ownershipCheck($allowed->matches($allowed->authorize($command,$row),$command,$row,$device), 'Exact absent former service cannot be repaired');
    foreach ([403,429,500] as $status) {
        $previous = $http($status);
        ownershipReject(static fn() => $make()->authorize($command,$row), 'verification_unavailable');
    }
    $previous = new RuntimeException('404 PRIVATE RESPONSE');
    ownershipReject(static fn() => $make()->authorize($command,$row), 'verification_unavailable');
    $previous = null;
    ownershipReject(static fn() => $make()->authorize($command,$row), 'previous_unverified');
    $previous = $oldSafe; $collection = [$target,$previous];

    $targetSafe = $target;
    $target = $entity(42,18);
    ownershipReject(static fn() => $make()->authorize($command,$row), 'target_changed');
    $target = $entity(42,17,1,'Fixture West');
    ownershipReject(static fn() => $make()->authorize($command,$row), 'target_changed');
    $target = $entity(42,17,1,'Fixture East','changed-user');
    ownershipReject(static fn() => $make()->authorize($command,$row), 'target_changed');
    $target = $entity(42,17,3);
    ownershipReject(static fn() => $make()->authorize($command,$row), 'target_changed');
    $target = $targetSafe; $collection = [$target,$previous,$target];
    ownershipReject(static fn() => $make()->authorize($command,$row), 'catalog_incomplete');
    $collection = [$previous];
    ownershipReject(static fn() => $make()->authorize($command,$row), 'catalog_incomplete');
    $collection = [$target,$previous]; $oldReads = 0;
    $getHook = static function ($path) use (&$previous,&$oldReads,$entity): void {
        if ($path === 'clients/services/41' && $oldReads === 1) $previous = $entity(41,17,1);
    };
    ownershipReject(static fn() => $make()->authorize($command,$row), 'previous_active');
    $getHook = null; $previous = $oldSafe; $targetReads = 0;
    $getHook = static function ($path) use (&$target,&$targetReads,$entity): void {
        if ($path === 'clients/services/42' && $targetReads === 1) $target = $entity(42,17,1,'Fixture West');
    };
    ownershipReject(static fn() => $make()->authorize($command,$row), 'target_changed');
    $getHook = null; $target = $targetSafe;
    $client['isArchived'] = true;
    ownershipReject(static fn() => $make()->authorize($command,$row), 'client_unverified');
    $client['isArchived'] = false;
    $catalog[1]['attributeType'] = 'client';
    ownershipReject(static fn() => $make()->authorize($command,$row), 'mapping_user_unverified');
    $catalog[1]['attributeType'] = 'service';

    // The retired service may no longer carry this account attribute, so it need not appear in the filtered catalog.
    $collection = [$target];
    $allowed = $make(); ownershipCheck($allowed->matches($allowed->authorize($command,$row),$command,$row,$device), 'Separate verified retired owner was incorrectly required in username filter');
    $collection = [$target,$previous];
    for ($id = 1000; $id < 1499; $id++) $collection[] = $entity($id,99,1,'Fixture West');
    $calls = []; $allowed = $make(); $allowed->authorize($command,$row);
    $pages = array_values(array_filter($calls,static fn($call) => $call[0] === 'clients/services'));
    ownershipCheck(count($pages) === 2 && $pages[0][1]['offset'] === 0 && $pages[1][1]['offset'] === 500, 'Filtered collection truncated a full first page');
    $collection = [$target,$previous];

    // Old executable work must not race the reassociation. Informative history does not block it.
    $jobs = new SQLite3($directory . '/data/jobs.db'); $jobs->enableExceptions(true);
    $jobs->exec('CREATE TABLE jobs(service_id INTEGER,status TEXT,payload TEXT); CREATE TABLE cancellation_cleanups(service_id INTEGER,payload TEXT);
        CREATE TABLE retained_resources(service_id INTEGER,payload TEXT)');
    $insert = static function ($sid,$status,$snapshot) use ($jobs): void {
        $statement = $jobs->prepare('INSERT INTO jobs VALUES (?,?,?)'); $statement->bindValue(1,$sid,SQLITE3_INTEGER);
        $statement->bindValue(2,$status,SQLITE3_TEXT); $statement->bindValue(3,json_encode($snapshot,JSON_THROW_ON_ERROR),SQLITE3_TEXT); $statement->execute();
    };
    $insert(42,'running',$service); $insert(41,'cancelled',['id'=>41]);
    $jobs->exec("INSERT INTO retained_resources VALUES(41,'{}')");
    $allowed = $make(); $jobProof = $allowed->authorize($command,$row);
    ownershipCheck($allowed->matches($jobProof,$command,$row,$device), 'Current job or informative historical ownership blocked repair');
    $insert(41,'pending',['id'=>41]);
    ownershipCheck(!$allowed->matches($jobProof,$command,$row,$device), 'New old-service work did not revoke proof');
    ownershipReject(static fn() => $make()->authorize($command,$row), 'previous_job_pending');
    $jobs->exec("DELETE FROM jobs WHERE status='pending'");
    $insert(43,'pending',['id'=>43,'device'=>1,'username'=>'fixture-user']);
    ownershipReject(static fn() => $make()->authorize($command,$row), 'previous_job_pending');
    $jobs->exec("DELETE FROM jobs WHERE status='pending'");
    $insert(43,'pending',['id'=>43,'device'=>2,'username'=>'fixture-user']);
    $allowed = $make(); ownershipCheck($allowed->matches($allowed->authorize($command,$row),$command,$row,$device), 'Work for another router blocked reassociation');
    $jobs->exec("INSERT INTO cancellation_cleanups VALUES(41,'{}')");
    ownershipReject(static fn() => $make()->authorize($command,$row), 'previous_job_pending');
    $jobs->exec('DELETE FROM cancellation_cleanups');
    $insert(42,'pending',['id'=>42,'_cleanup'=>['id'=>41]]);
    ownershipReject(static fn() => $make()->authorize($command,$row), 'previous_job_pending');
    $jobs->exec("DELETE FROM jobs WHERE service_id=42 AND status='pending'");
    $jobs->exec("INSERT INTO jobs VALUES(43,'pending','broken-json')");
    ownershipReject(static fn() => $make()->authorize($command,$row), 'queue_unverified');
    $jobs->exec("DELETE FROM jobs WHERE payload='broken-json'");
    $jobs->close();

    // Hotspot uses the same exact named-account guard; DHCP and active sessions never do.
    $hotspot = $command; $hotspot['path'] = '/ip/hotspot/user';
    ownershipReject(static fn() => $make()->authorize($hotspot,$row), 'identity_unverified');
    $before = hash_file('sha256',$directory . '/data/data.db'); $make()->authorize($command,$row);
    ownershipCheck(hash_file('sha256',$directory . '/data/data.db') === $before, 'Repair verification changed stored service data');
    $database->exec("INSERT INTO config(key,value) VALUES('hs_attr','access_mode')");
    $catalog[] = ['id'=>3,'key'=>'access_mode','attributeType'=>'service'];
    $target['attributes'][] = ['key'=>'access_mode','customAttributeId'=>3,'value'=>'1']; $collection = [$target,$previous];
    $hotspotService = $service; $hotspotService['hotspot'] = '1';
    $allowed = new ApiRouterOwnership($hotspotService,$device,$transport,$directory . '/data/data.db');
    ownershipCheck($allowed->matches($allowed->authorize($hotspot,$row),$hotspot,$row,$device), 'Named Hotspot account failed equivalent verification');
    $target['attributes'][2]['value'] = '0';
    ownershipReject(static fn() => (new ApiRouterOwnership($hotspotService,$device,$transport,$directory . '/data/data.db'))->authorize($hotspot,$row), 'target_changed');

    // Exercise the actual MT do_batch preflight, repeated ownership lookups and final set with this helper.
    $target = $targetSafe; $previous = $oldSafe; $collection = [$target,$previous];
    $update = $command + ['batch'=>'fixture-update','remote-address'=>'198.51.100.42'];
    $oldAccount = $row + ['remote-address'=>'192.0.2.42','profile'=>'old-fixture-profile'];
    $router = new OwnershipIntegrationAPI(); $router->account = $oldAccount; $mt = new OwnershipIntegrationMT($router);
    $liveVerifier = $make(); $liveProof = null; $resolverCalls = 0;
    $resolver = static function ($post,$record) use ($liveVerifier,&$liveProof,&$resolverCalls,$device): bool {
        $resolverCalls++; if ($liveProof === null) $liveProof = $liveVerifier->authorize($post,$record);
        if (!$liveVerifier->matches($liveProof,$post,$record,$device)) throw new RuntimeException('router_ownership_record_changed');
        return true;
    };
    $writes = $mt->withOwnershipResolver($resolver,static fn() => $mt->do_batch((object)$device,[$update]));
    ownershipCheck($writes === 1 && !$mt->failed() && $router->mutations === ['/ppp/secret/set'] && $resolverCalls === 4
        && $router->reads === 4 && $router->account['.id'] === '*A1' && $router->account['comment'] === $update['comment']
        && $router->account['remote-address'] === '198.51.100.42' && $router->account['profile'] === 'fixture-profile',
        'Actual MT batch did not preserve and update the verified historical account in place');
    foreach (array_keys($router->account) as $field) ownershipCheck(strpos($field,'_') !== 0, 'Private permission metadata reached RouterOS');

    $router = new OwnershipIntegrationAPI(); $router->account = $oldAccount; $mt = new OwnershipIntegrationMT($router);
    $previous = $entity(41,17,1); $collection = [$target,$previous]; $deniedVerifier = $make();
    $writes = $mt->withOwnershipResolver(static function ($post,$record) use ($deniedVerifier,$device): bool {
        $proof = $deniedVerifier->authorize($post,$record); return $deniedVerifier->matches($proof,$post,$record,$device);
    },static fn() => $mt->do_batch((object)$device,[$update]));
    ownershipCheck($writes === 0 && !$router->mutations && $router->account === $oldAccount
        && strpos($mt->failed()['fixture-update'] ?? '', 'reason=previous_active') !== false,
        'Actual MT preflight wrote to an account whose previous UISP service is active');
    $previous = $oldSafe; $collection = [$target,$previous];

    $router = new OwnershipIntegrationAPI(); $router->account = array_replace($oldAccount,['name'=>'different-account','.id'=>'*B1']);
    $mt = new OwnershipIntegrationMT($router);
    $different = array_replace($update,['name'=>'different-account']);
    $writes = $mt->withOwnershipResolver($resolver,static fn() => $mt->do_batch((object)$device,[$different]));
    ownershipCheck($writes === 0 && !$router->mutations && $router->account['.id'] === '*B1', 'Actual MT batch reused permission for another account');
    $router->account = $oldAccount; $router->reads = 0;
    ownershipCheck($mt->do_batch((object)$device,[$update]) === 0 && !$router->mutations,
        'Scoped resolver remained installed after the caller finished');

    $router = new OwnershipIntegrationAPI(); $router->account = $oldAccount; $mt = new OwnershipIntegrationMT($router);
    $router->onRead = static function ($api): void { if ($api->reads === 2) $api->account['.id'] = '*A2'; };
    $freshVerifier = $make(); $freshProof = null;
    $writes = $mt->withOwnershipResolver(static function ($post,$record) use ($freshVerifier,&$freshProof,$device): bool {
        if ($freshProof === null) $freshProof = $freshVerifier->authorize($post,$record);
        return $freshVerifier->matches($freshProof,$post,$record,$device);
    },static fn() => $mt->do_batch((object)$device,[$update]));
    ownershipCheck($writes === 0 && !$router->mutations && $router->account['.id'] === '*A2', 'Changed RouterOS identity was detected only after mutation');

    // Optional Hotspot mapping absent from a valid catalog must not block a PPP repair.
    $target = $targetSafe; $previous = $oldSafe; $collection = [$target,$previous];
    $catalog = array_slice($catalog,0,2);
    $optional = $make(); ownershipCheck($optional->matches($optional->authorize($command,$row),$command,$row,$device),
        'Configured but absent optional Hotspot descriptor blocked a PPP account');
    $target['attributes'][] = ['key'=>'access_mode','customAttributeId'=>3,'value'=>'1']; $collection = [$target,$previous];
    ownershipReject(static fn() => $make()->authorize($command,$row), 'mapping_hotspot_unverified');
    $target = $targetSafe; $collection = [$target,$previous];
    ownershipReject(static fn() => (new ApiRouterOwnership($hotspotService,$device,$transport,$directory . '/data/data.db'))->authorize($hotspot,$row), 'mapping_hotspot_unverified');
    $catalog[] = ['id'=>3,'key'=>'access_mode','attributeType'=>'client'];
    ownershipReject(static fn() => $make()->authorize($command,$row), 'mapping_hotspot_unverified');
    $catalog[2]['attributeType']='service'; $catalog[]=['id'=>4,'key'=>'access_mode','attributeType'=>'service'];
    ownershipReject(static fn() => $make()->authorize($command,$row), 'mapping_unverified');
    $catalog = array_slice($catalog,0,2); $catalog[]=['id'=>2,'key'=>'unused','attributeType'=>'service'];
    ownershipReject(static fn() => $make()->authorize($command,$row), 'mapping_unverified');
    $catalog = array_slice($catalog,0,2); $catalog[0]['key']='renamed-site';
    ownershipReject(static fn() => $make()->authorize($command,$row), 'mapping_site_unverified');
    $catalog[0]['key']='site'; $catalog[1]['key']='renamed-account';
    ownershipReject(static fn() => $make()->authorize($command,$row), 'mapping_user_unverified');
    $catalog[1]['key']='account';

    // A contradictory marker combines the target client with another client's service ID.
    // Require disjoint fresh identity/router and a literal target IPv4 matching the actual account.
    $database->exec("INSERT INTO config(key,value) VALUES('ip_addr_attr','ipv4')");
    $catalog[]=['id'=>4,'key'=>'ipv4','attributeType'=>'service'];
    $target=$entity(42); $target['attributes'][]=['key'=>'ipv4','customAttributeId'=>4,'value'=>'198.51.100.42'];
    $previous=$entity(41,99,1,'Fixture West','other-fixture-account');
    $previous['attributes'][]=['key'=>'ipv4','customAttributeId'=>4,'value'=>'203.0.113.41'];
    $mixedTarget=$target; $mixedPrevious=$previous; $collection=[$target];
    $mixedRow=array_replace($row,['remote-address'=>'198.51.100.42']);
    $mixedCommand=$command+['batch'=>'mixed-marker','remote-address'=>'198.51.100.42'];
    $jobs=new SQLite3($directory . '/data/jobs.db'); $jobs->enableExceptions(true);
    $jobs->exec('DELETE FROM jobs; DELETE FROM cancellation_cleanups; CREATE TABLE service_versions(service_id INTEGER,applied_payload TEXT)');
    $jobInsert=static function(int $sid,array $snapshot,string $table='jobs') use($jobs): void {
        $query=$table==='jobs' ? "INSERT INTO jobs(service_id,status,payload) VALUES(?,'pending',?)"
            : ($table==='service_versions' ? 'INSERT INTO service_versions(service_id,applied_payload) VALUES(?,?)' : 'INSERT INTO cancellation_cleanups(service_id,payload) VALUES(?,?)');
        $statement=$jobs->prepare($query); $statement->bindValue(1,$sid,SQLITE3_INTEGER);
        $statement->bindValue(2,json_encode($snapshot,JSON_THROW_ON_ERROR),SQLITE3_TEXT); $statement->execute();
    };
    $otherSnapshot=['id'=>41,'clientId'=>99,'device'=>2,'username'=>'other-fixture-account','hotspot'=>false,
        '_commands'=>[['path'=>'/ppp/secret','name'=>'other-fixture-account','_service_id'=>41,'_client_id'=>99]]];
    $jobInsert(42,$service); $jobInsert(41,$otherSnapshot); $jobInsert(41,$otherSnapshot,'service_versions');
    $jobHash=hash_file('sha256',$directory . '/data/jobs.db'); $mainHash=hash_file('sha256',$directory . '/data/data.db');
    $mixedVerifier=$make(); $mixedProof=$mixedVerifier->authorize($mixedCommand,$mixedRow);
    ownershipCheck($mixedVerifier->matches($mixedProof,$mixedCommand,$mixedRow,$device),'Exclusive, contradictory marker could not be corrected');
    ownershipCheck(hash_file('sha256',$directory . '/data/jobs.db')===$jobHash && hash_file('sha256',$directory . '/data/data.db')===$mainHash,
        'Marker verification altered the other client queue, cache or current service data');
    $backup=json_decode(file_get_contents($directory . '/data/router-ownership/42-' . substr($mixedProof['token'],0,32) . '.json'),true,32,JSON_THROW_ON_ERROR);
    ownershipCheck($backup['evidence']['kind']==='contradictory_service_marker' && $backup['evidence']['previous_client']===99
        && $backup['evidence']['previous_username']==='other-fixture-account' && $backup['evidence']['target_address']==='198.51.100.42',
        'Contradictory marker backup omitted the evidence that protects the other service');
    $driftRow=$mixedRow; $driftRow['remote-address']='198.51.100.99';
    ownershipCheck(!$mixedVerifier->matches($mixedProof,$mixedCommand,$driftRow,$device),'Changed router address reused an IP-based ownership proof');
    foreach (['198.51.100.99','named-router-pool',null,'198.51.100.42/32'] as $address) {
        $unsafeRow=$mixedRow; $unsafeRow['remote-address']=$address;
        ownershipReject(static fn() => $make()->authorize($mixedCommand,$unsafeRow),'marker_unverified');
        $unsafeCommand=$mixedCommand; $unsafeCommand['remote-address']=$address;
        ownershipReject(static fn() => $make()->authorize($unsafeCommand,$mixedRow),'marker_unverified');
    }
    $target['attributes'][2]['value']='198.51.100.99'; $collection=[$target];
    ownershipReject(static fn() => $make()->authorize($mixedCommand,$mixedRow),'marker_unverified');
    $target=$mixedTarget; $collection=[$target]; $previous['attributes'][1]['value']='fixture-user';
    ownershipReject(static fn() => $make()->authorize($mixedCommand,$mixedRow),'marker_unverified');
    $previous=$mixedPrevious; $previous['attributes'][0]['value']='Fixture East';
    ownershipReject(static fn() => $make()->authorize($mixedCommand,$mixedRow),'marker_unverified');
    $previous=$mixedPrevious; $database->exec("UPDATE devices SET ip='192.0.2.1' WHERE id=2");
    ownershipReject(static fn() => $make()->authorize($mixedCommand,$mixedRow),'marker_unverified');
    $database->exec("UPDATE devices SET ip='192.0.2.2' WHERE id=2");
    $previous['attributes'][2]['value']='198.51.100.42';
    ownershipReject(static fn() => $make()->authorize($mixedCommand,$mixedRow),'marker_unverified');
    $previous=$mixedPrevious; $previous['attributes'][2]['value']=null;
    ownershipReject(static fn() => $make()->authorize($mixedCommand,$mixedRow),'marker_unverified');
    $previous=$mixedPrevious; $previous['clientId']=17;
    ownershipReject(static fn() => $make()->authorize($mixedCommand,$mixedRow),'previous_active');
    $previous=$mixedPrevious;

    // Every relevant old-service fact is reread, including facts that remain disjoint but changed meanwhile.
    foreach (['client','status','username','site','address'] as $change) {
        $oldReads=0; $previous=$mixedPrevious;
        $getHook=static function($path) use(&$previous,&$oldReads,$change): void {
            if($path!=='clients/services/41' || $oldReads!==1) return;
            if($change==='client') $previous['clientId']=100;
            if($change==='status') $previous['status']=3;
            if($change==='username') $previous['attributes'][1]['value']='changed-other-account';
            if($change==='site') $previous['attributes'][0]['value']='fixture west';
            if($change==='address') $previous['attributes'][2]['value']='203.0.113.99';
        };
        ownershipReject(static fn() => $make()->authorize($mixedCommand,$mixedRow),'target_changed');
    }
    $getHook=null; $previous=$mixedPrevious;
    $targetReads=0;
    $getHook=static function($path) use(&$target,&$targetReads): void {
        if($path==='clients/services/42' && $targetReads===1) $target['attributes'][2]['value']='198.51.100.99';
    };
    ownershipReject(static fn() => $make()->authorize($mixedCommand,$mixedRow),'marker_unverified');
    $getHook=null; $target=$mixedTarget; $collection=[$target];

    // Applied or queued snapshots are checked by actual account and endpoint, not an unrelated numeric ID.
    $claim=['id'=>41,'clientId'=>99,'device'=>1,'username'=>'fixture-user','_commands'=>[['path'=>'/ppp/secret','name'=>'fixture-user']]];
    $jobs->exec('DELETE FROM service_versions'); $jobInsert(41,$claim,'service_versions');
    ownershipReject(static fn() => $make()->authorize($mixedCommand,$mixedRow),'account_claimed');
    $jobs->exec('DELETE FROM service_versions'); $jobInsert(43,array_replace($claim,['id'=>43]),'service_versions');
    ownershipReject(static fn() => $make()->authorize($mixedCommand,$mixedRow),'account_claimed');
    $jobs->exec('DELETE FROM service_versions'); $jobInsert(41,['id'=>41],'service_versions');
    ownershipReject(static fn() => $make()->authorize($mixedCommand,$mixedRow),'queue_unverified');
    $jobs->exec('DELETE FROM service_versions'); $jobInsert(41,array_replace($otherSnapshot,['clientId'=>100]),'service_versions');
    ownershipReject(static fn() => $make()->authorize($mixedCommand,$mixedRow),'queue_unverified');
    $jobs->exec('DELETE FROM service_versions'); $jobInsert(41,$claim);
    ownershipReject(static fn() => $make()->authorize($mixedCommand,$mixedRow),'previous_job_pending');
    $jobs->exec('DELETE FROM jobs WHERE service_id=41'); $jobInsert(41,['id'=>41,'clientId'=>99,'username'=>'other-fixture-account']);
    ownershipReject(static fn() => $make()->authorize($mixedCommand,$mixedRow),'queue_unverified');
    $jobs->exec('DELETE FROM jobs WHERE service_id=41'); $jobInsert(41,$otherSnapshot);
    $jobInsert(42,array_replace($service,['_cleanup'=>$claim]));
    ownershipReject(static fn() => $make()->authorize($mixedCommand,$mixedRow),'previous_job_pending');
    $jobs->exec('DELETE FROM jobs WHERE service_id=42'); $jobInsert(42,$service);
    $jobInsert(41,$otherSnapshot,'cancellation_cleanups');
    $cleared=$make(); ownershipCheck($cleared->matches($cleared->authorize($mixedCommand,$mixedRow),$mixedCommand,$mixedRow,$device),
        'Unrelated other-client cleanup blocked correction of an independent marker');
    $dhcpSnapshot=['id'=>43,'clientId'=>100,'device'=>1,'username'=>'fixture-user','mac'=>'02:00:00:00:00:43',
        '_commands'=>[['path'=>'/ip/dhcp-server/lease','mac-address'=>'02:00:00:00:00:43']]];
    $jobInsert(43,$dhcpSnapshot,'service_versions');
    $dhcpUnrelated=$make(); ownershipCheck($dhcpUnrelated->matches($dhcpUnrelated->authorize($mixedCommand,$mixedRow),$mixedCommand,$mixedRow,$device),
        'DHCP service with an unused matching username falsely claimed the PPP account');
    $jobs->exec('DELETE FROM service_versions');
    $dhcpSnapshot['_commands'][]=['path'=>'/ppp/secret','name'=>'fixture-user'];
    $jobInsert(43,$dhcpSnapshot,'service_versions');
    ownershipReject(static fn() => $make()->authorize($mixedCommand,$mixedRow),'account_claimed');
    $jobs->exec('DELETE FROM service_versions');

    // Real MT batch corrects only the account whose client, username, Site and IPv4 are proven.
    $router=new OwnershipIntegrationAPI(); $router->account=$mixedRow; $mt=new OwnershipIntegrationMT($router);
    $actualVerifier=$make(); $actualProof=null; $beforeOtherQueue=hash_file('sha256',$directory . '/data/jobs.db');
    $writes=$mt->withOwnershipResolver(static function($post,$record) use($actualVerifier,&$actualProof,$device): bool {
        if($actualProof===null) $actualProof=$actualVerifier->authorize($post,$record);
        return $actualVerifier->matches($actualProof,$post,$record,$device);
    },static fn()=>$mt->do_batch((object)$device,[$mixedCommand]));
    ownershipCheck($writes===1 && $router->mutations===['/ppp/secret/set'] && $router->account['.id']==='*A1'
        && $router->account['comment']==='17 - Fixture customer - 42' && $router->account['name']==='fixture-user'
        && hash_file('sha256',$directory . '/data/jobs.db')===$beforeOtherQueue,
        'Real MT correction recreated an account or changed the other service state');
    $jobs->close();
    echo "Router ownership regression checks passed\n";
} finally {
    $database->close();
    set_include_path($include);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) { if ($entry->isDir()) rmdir($entry->getPathname()); else unlink($entry->getPathname()); }
    rmdir($directory);
}
