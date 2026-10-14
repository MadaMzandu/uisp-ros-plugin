<?php
// Isolated migration/recovery fixtures. HTTP probes use loopback; no UISP/router access is permitted.
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
function checkMigrationConflict(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function rejectedMigrationConflict(callable $operation): array {
    try { $operation(); }
    catch (Throwable $error) {
        checkMigrationConflict($error instanceof ApiNetworkAddressConflictException,
            'Address collision did not report the specific recovery exception: ' . get_class($error) . ': ' . $error->getMessage());
        checkMigrationConflict($error instanceof InvalidArgumentException,
            'Address collision no longer has the public validation-error contract');
        checkMigrationConflict($error->getMessage()==='duplicate_network_addresses_resolve_before_upgrade',
            'Address collision lost its actionable message');
        $details = $error->getDetails();
        checkMigrationConflict(is_int($details['duplicate_groups'] ?? null) && $details['duplicate_groups']>0,
            'Address diagnostic omitted the typed duplicate group total');
        checkMigrationConflict(is_array($details['conflicts'] ?? null) && count($details['conflicts'])<=20,
            'Address diagnostic omitted conflicts or exceeded its group limit');
        checkMigrationConflict(is_bool($details['conflicts_truncated'] ?? null),
            'Address diagnostic omitted its typed group truncation flag');
        foreach ($details['conflicts'] as $conflict) {
            checkMigrationConflict(in_array($conflict['family'] ?? null, ['IPv4','IPv6'], true)
                && is_string($conflict['address'] ?? null) && $conflict['address']!=='',
                'Address diagnostic omitted its family or literal address');
            checkMigrationConflict(is_int($conflict['service_count'] ?? null) && $conflict['service_count']>1
                && is_bool($conflict['services_truncated'] ?? null)
                && is_array($conflict['service_ids'] ?? null) && count($conflict['service_ids'])<=50,
                'Address diagnostic lost typed or bounded service information');
            foreach ($conflict['service_ids'] as $serviceId) {
                checkMigrationConflict(is_int($serviceId), 'Address diagnostic changed service IDs to strings');
            }
            checkMigrationConflict(count(array_unique($conflict['service_ids']))===count($conflict['service_ids'])
                && $conflict['service_count']>=count($conflict['service_ids']),
                'Address diagnostic repeated a service or undercounted a conflict');
        }
        return $details;
    }
    throw new RuntimeException('A conflicting address migration was accepted');
}
function conflictByAddress(array $details, string $family, string $address): array {
    foreach ($details['conflicts'] as $conflict) {
        if ($conflict['family']===$family && $conflict['address']===$address) return $conflict;
    }
    throw new RuntimeException('Diagnostic did not identify ' . $family . ' ' . $address);
}
function migrationDatabaseSnapshot(ApiSqlite $database): array {
    $schema = $database->selectCustom("SELECT type,name,tbl_name,sql FROM sqlite_master ORDER BY type,name");
    $data = [];
    foreach ($schema as $entry) {
        if ($entry['type']==='table') {
            $data[$entry['name']] = $database->selectCustom('SELECT * FROM ' . ApiSqlite::identifier($entry['name']) . ' ORDER BY rowid');
        }
    }
    return ['schema'=>$schema,'data'=>$data];
}
function migrationPersistentSnapshot(): array {
    $backups = [];
    foreach (glob('data/backup-*') as $file) {
        if (preg_match('/^backup-[0-6]$/D', basename($file))) $backups[basename($file)] = hash_file('sha256', $file);
    }
    ksort($backups);
    return [
        'database_hash'=>hash_file('sha256','data/data.db'),
        'database'=>migrationDatabaseSnapshot(mySqlite()),
        'backups'=>$backups,
        'state'=>is_file('data/state.json') ? file_get_contents('data/state.json') : null,
        'cursor'=>mySqlite()->singleQuery('SELECT value FROM config WHERE key=?', false, ['backup_cursor']),
        'jobs'=>myJobQueue()->list(),
        'log'=>is_file('data/plugin.log') ? file_get_contents('data/plugin.log') : null,
    ];
}
function conflictFixture(string $schema, bool $embedded = false): ApiSqlite {
    $database = new ApiSqlite(':memory:');
    $database->exec($schema);
    if ($embedded) $database->exec('ALTER TABLE services ADD COLUMN address TEXT; ALTER TABLE services ADD COLUMN prefix6 TEXT');
    $database->saveConfig(['version'=>'2.3.1']);
    return $database;
}
function unchangedRejectedMigration(ApiSqlite $database): array {
    $before = migrationDatabaseSnapshot($database);
    $setup = new ApiSetup($database);
    $preflight = rejectedMigrationConflict(fn()=>$setup->assertNetworkAddressesUnique());
    checkMigrationConflict(migrationDatabaseSnapshot($database)===$before, 'Read-only address preflight changed source data');
    $migrated = rejectedMigrationConflict(fn()=>$setup->migrate());
    checkMigrationConflict($migrated===$preflight, 'Migration diagnosis differs from the projected address preflight');
    checkMigrationConflict(migrationDatabaseSnapshot($database)===$before,
        'Rejected migration changed schema, addresses, settings, credentials, plans or stored version');
    return $migrated;
}
function publicMigrationRequest(string $directory, $user, string $method, array $query): array {
    static $sequence = 0;
    $script = $directory . '/public-conflict-check-' . ++$sequence . '.php';
    $server = ['REQUEST_METHOD'=>$method,'REMOTE_ADDR'=>'192.0.2.10','CONTENT_TYPE'=>'application/json',
        'HTTP_HOST'=>'migration-fixture.example','HTTP_ORIGIN'=>'http://migration-fixture.example'];
    $code = '<?php $GLOBALS["testUser"]=json_decode(' . var_export(json_encode($user), true)
        . '); $_SERVER=json_decode(' . var_export(json_encode($server), true) . ',true); $_GET=json_decode('
        . var_export(json_encode($query), true) . ',true); register_shutdown_function(function(){echo "\\nHTTP=".http_response_code();}); require '
        . var_export($directory . '/public.php', true) . ';';
    file_put_contents($script, $code);
    $pipes = [];
    $process = proc_open([PHP_BINARY,'-n','-d','extension_dir=' . ini_get('extension_dir'),'-d','extension=sqlite3',$script],
        [1=>['pipe','w'],2=>['pipe','w']], $pipes);
    $output = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    checkMigrationConflict($exit===0 && $stderr==='', 'Public conflict request did not finish cleanly: ' . $stderr);
    $split = strrpos($output, "\nHTTP=");
    checkMigrationConflict($split!==false, 'Public conflict request did not report its HTTP status');
    return ['http'=>(int)substr($output,$split+6),'body'=>substr($output,0,$split)];
}
function publicMigrationSnapshot(): array {
    $snapshot = migrationPersistentSnapshot();
    // Requests deliberately append diagnostics; they must preserve all recovery/operational state.
    unset($snapshot['log']);
    return $snapshot;
}
function checkWebhookMigrationPrivacy(string $directory): void {
    $process = null;
    $priorConfig = is_file('data/config.json') ? file_get_contents('data/config.json') : null;
    file_put_contents($directory . '/webhook-router.php',
        '<?php set_include_path(__DIR__ . "/lib" . PATH_SEPARATOR . get_include_path()); require __DIR__ . "/public.php";');
    try {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        checkMigrationConflict($listener!==false, 'Could not allocate a loopback webhook privacy fixture');
        $address = stream_socket_get_name($listener, false); fclose($listener);
        $process = proc_open([PHP_BINARY,'-n','-d','extension_dir=' . ini_get('extension_dir'),'-d','extension=sqlite3',
            '-S',$address,'-t',$directory,$directory . '/webhook-router.php'],
            [0=>['pipe','r'],1=>['file',$directory . '/webhook-server-out.log','a'],
                2=>['file',$directory . '/webhook-server-error.log','a']], $pipes, $directory, null,
            ['bypass_shell'=>true,'create_no_window'=>true]);
        checkMigrationConflict(is_resource($process), 'Could not start loopback webhook privacy server');
        fclose($pipes[0]);
        $ready = false;
        for ($attempt=0; $attempt<50; $attempt++) {
            $socket = @stream_socket_client('tcp://' . $address, $errorNumber, $errorMessage, 0.05);
            if ($socket!==false) { fclose($socket); $ready = true; break; }
            usleep(20000);
        }
        checkMigrationConflict($ready, 'Loopback webhook privacy server did not become ready: '
            . file_get_contents($directory . '/webhook-server-error.log'));
        $request = static function (array $event, array $headers = []) use ($address): array {
            $httpHeaders = ['Content-Type: application/json'];
            foreach ($headers as $name=>$value) $httpHeaders[] = $name . ': ' . $value;
            $context = stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$httpHeaders),
                'content'=>json_encode($event,JSON_THROW_ON_ERROR),'ignore_errors'=>true,'timeout'=>3]]);
            $body = file_get_contents('http://' . $address . '/public.php', false, $context);
            $status = (int)(preg_match('/^HTTP\/\S+ (\d+)/',$http_response_header[0] ?? '',$match) ? $match[1] : 0);
            return ['http'=>$status,'body'=>$body,'response'=>json_decode($body,true,512,JSON_THROW_ON_ERROR)];
        };
        $secret = 'MIGRATION-PRIVACY-FIXTURE-SECRET-0123456789';
        $before = publicMigrationSnapshot();
        foreach ([[['webhookAllowWithoutSecret'=>true],[]],
            [['webhookSecret'=>$secret],['X-Webhook-Token'=>$secret]],
            [['webhookSecret'=>$secret],['Authorization'=>'Bearer ' . $secret]]] as [$settings,$headers]) {
            file_put_contents('data/config.json',json_encode($settings));
            foreach (['service','client'] as $entity) {
                $event = ['entity'=>$entity,'entityId'=>$entity==='service' ? 7 : 70,'changeType'=>'edit',
                    'uuid'=>'MIGRATION-PRIVACY-FIXTURE-UUID-0001'];
                $response = $request($event,$headers);
                checkMigrationConflict($response['http']===400 && ($response['response']['error'] ?? null)===true
                    && ($response['response']['message'] ?? null)==='duplicate_network_addresses_resolve_before_upgrade'
                    && ($response['response']['data'] ?? null)===['duplicate_groups'=>2],
                    'Webhook conflict response exposed network addresses or service IDs beyond its total');
                checkMigrationConflict(strpos($response['body'],'203.0.113.7')===false
                    && strpos($response['body'],'onerror')===false && strpos($response['body'],'service_ids')===false
                    && strpos($response['body'],'conflicts')===false,
                    'Compatibility/authenticated webhook disclosed private conflict diagnostics');
                checkMigrationConflict(publicMigrationSnapshot()===$before,
                    'Rejected webhook migration changed persistent recovery or operational state');
            }
        }
        file_put_contents('data/config.json',json_encode(['webhookSecret'=>$secret]));
        $unauthorized = $request(['entity'=>'service','entityId'=>7,'changeType'=>'edit',
            'uuid'=>'MIGRATION-PRIVACY-FIXTURE-UUID-0001']);
        checkMigrationConflict($unauthorized['http']===403
            && ($unauthorized['response']['message'] ?? null)==='unauthorized'
            && ($unauthorized['response']['data'] ?? null)===[], 'Unauthenticated webhook exposed conflict totals or assignments');
        checkMigrationConflict(publicMigrationSnapshot()===$before, 'Unauthorized webhook changed persistent recovery state');
    } finally {
        if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        if ($priorConfig===null) { if (is_file('data/config.json')) unlink('data/config.json'); }
        else file_put_contents('data/config.json',$priorConfig);
    }
}

$originalDirectory = getcwd();
$source = realpath(__DIR__ . '/../src');
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-migration-conflicts-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
foreach (['lib','includes','data','vendor','public'] as $folder) mkdir($directory . '/' . $folder, 0700);
mkdir($directory . '/public/panel_v2', 0700);
foreach (glob($source . '/lib/*.php') as $file) copy($file, $directory . '/lib/' . basename($file));
foreach (glob($source . '/includes/*') as $file) if (is_file($file)) copy($file, $directory . '/includes/' . basename($file));
copy($source . '/manifest.json', $directory . '/manifest.json');
copy($source . '/public.php', $directory . '/public.php');
copy($source . '/public/panel_v2/network_conflicts.php', $directory . '/public/panel_v2/network_conflicts.php');
file_put_contents($directory . '/vendor/autoload.php', <<<'PHP'
<?php
namespace Ubnt\UcrmPluginSdk\Service;
class UcrmApi {
    public static function create($token=null) { return new static(); }
    public function __call($method, $arguments) { throw new \RuntimeException('Network prohibited in migration-conflict tests'); }
}
class UnmsApi extends UcrmApi {}
class UcrmSecurity {
    public static function create() { return new static(); }
    public function getUser() { return $GLOBALS['testUser'] ?? null; }
}
PHP
);
chdir($directory);
set_include_path($directory . '/lib' . PATH_SEPARATOR . get_include_path());
require_once 'lib/api_common.php';
require_once 'lib/api_setup.php';
require_once 'lib/api_backup.php';
$fixtures = [];
try {
    $schema = file_get_contents('includes/schema.sql');
    $withoutUnique = preg_replace('/^CREATE UNIQUE INDEX[^\r\n]*\R?/m', '', $schema);
    checkMigrationConflict($withoutUnique!==$schema, 'Collision fixture did not remove address uniqueness indexes');

    // Fresh installs and clean upgrades still create all current tables, defaults and unique indexes.
    $fresh = $fixtures[] = new ApiSqlite(':memory:');
    (new ApiSetup($fresh))->assertNetworkAddressesUnique();
    (new ApiSetup($fresh))->migrate();
    checkMigrationConflict(!(new ApiSetup($fresh))->needs_cols()
        && $fresh->singleQuery('SELECT value FROM config WHERE key=?', false, ['version'])===MY_VERSION,
        'Fresh migration did not publish the current schema and version');
    $clean = $fixtures[] = conflictFixture($schema);
    $clean->insert(['id'=>1,'name'=>'Preserved plan','uploadOverride'=>3.75,'downloadOverride'=>12.5,
        'burstUpload'=>37.5,'timeUpload'=>9.5,'limitUpload'=>20], 'plans');
    $clean->insert(['id'=>1,'name'=>'Preserved router','ip'=>'192.0.2.1','user'=>'00123','password'=>'00001'], 'devices');
    $clean->saveConfig(['active_list'=>'00123','disable_contention'=>true,'lease_time'=>61]);
    $cleanPlan = $clean->singleQuery('SELECT * FROM plans WHERE id=1', true);
    (new ApiSetup($clean))->migrate();
    checkMigrationConflict($clean->singleQuery('SELECT * FROM plans WHERE id=1', true)===$cleanPlan,
        'Clean upgrade changed decimal plan settings');
    checkMigrationConflict($clean->singleQuery('SELECT user,password,type FROM devices WHERE id=1', true)
        ===['user'=>'00123','password'=>'00001','type'=>'mikrotik'], 'Clean upgrade changed credentials or omitted device repair');
    checkMigrationConflict($clean->readConfig()->active_list==='00123' && $clean->readConfig()->disable_contention===true
        && $clean->readConfig()->lease_time===61.0 && $clean->readConfig()->version===MY_VERSION,
        'Clean upgrade changed saved settings or omitted its version');

    // Existing IPv4/IPv6 collisions reject before adding columns or running legacy conversions.
    foreach (['IPv4'=>['address','192.0.2.99'],'IPv6'=>['address6','2001:db8:99::/64']] as $family=>$case) {
        $duplicate = $fixtures[] = conflictFixture($withoutUnique);
        $duplicate->exec('ALTER TABLE plans ADD COLUMN priority INTEGER');
        $duplicate->saveConfig(['version'=>'2.0.2','active_list'=>'0007']);
        $duplicate->insert(['id'=>1,'uploadSpeed'=>100,'downloadSpeed'=>100,'limitUpload'=>20,'limitDownload'=>20,'priority'=>4], 'plans');
        $duplicate->insert([['id'=>8,$case[0]=>$case[1]],['id'=>3,$case[0]=>$case[1]]], 'network');
        $details = unchangedRejectedMigration($duplicate);
        $conflict = conflictByAddress($details, $family, $case[1]);
        $ids = $conflict['service_ids']; sort($ids);
        checkMigrationConflict($details['duplicate_groups']===1 && !$details['conflicts_truncated']
            && $ids===[3,8] && $conflict['service_count']===2 && !$conflict['services_truncated'],
            'Small duplicate group omitted IDs or reported incorrect truncation');
    }

    // Partial unique indexes deliberately permit multiple null and empty values in both families.
    $empty = $fixtures[] = conflictFixture($withoutUnique);
    $empty->insert([['id'=>1,'address'=>null,'address6'=>null],['id'=>2,'address'=>null,'address6'=>null],
        ['id'=>3,'address'=>'','address6'=>''],['id'=>4,'address'=>'','address6'=>'']], 'network');
    (new ApiSetup($empty))->assertNetworkAddressesUnique();
    (new ApiSetup($empty))->migrate();
    checkMigrationConflict($empty->singleQuery('SELECT count(*) FROM network')===4,
        'Migration rejected or discarded allowed empty network rows');
    foreach (['unique_address','unique_address6'] as $name) {
        checkMigrationConflict($empty->singleQuery("SELECT name FROM sqlite_master WHERE type='index' AND name=?", false, [$name])===$name,
            'Clean migration omitted a unique address index');
    }

    // A missing network table still projects the embedded service addresses before creating it.
    $legacy = $fixtures[] = new ApiSqlite(':memory:');
    $legacy->exec('CREATE TABLE services(id INTEGER PRIMARY KEY,device INTEGER,clientId INTEGER,planId INTEGER,status INTEGER,address TEXT,prefix6 TEXT);'
        . 'CREATE TABLE plans(id INTEGER PRIMARY KEY); CREATE TABLE devices(id INTEGER PRIMARY KEY,name TEXT,ip TEXT,user TEXT,password TEXT);'
        . 'CREATE TABLE config(key TEXT PRIMARY KEY,value TEXT,last TEXT,created TEXT)');
    $legacy->saveConfig(['version'=>'2.3.1']);
    $legacy->insert([['id'=>10,'address'=>'198.51.100.10','prefix6'=>'2001:db8:10::/64'],
        ['id'=>11,'address'=>'198.51.100.10','prefix6'=>'2001:db8:10::/64']], 'services');
    $legacyDetails = unchangedRejectedMigration($legacy);
    checkMigrationConflict($legacyDetails['duplicate_groups']===2
        && conflictByAddress($legacyDetails,'IPv4','198.51.100.10')['service_count']===2
        && conflictByAddress($legacyDetails,'IPv6','2001:db8:10::/64')['service_count']===2,
        'Embedded service projection missed a family before network-table creation');

    // Existing indexes would make INSERT OR IGNORE silently discard these legacy service rows.
    foreach (['IPv4'=>['address','192.0.2.50'],'IPv6'=>['address6','2001:db8:50::/64']] as $family=>$case) {
        $projected = $fixtures[] = conflictFixture($schema, true);
        $projected->insert(['id'=>1,$case[0]=>$case[1]], 'network');
        $serviceColumn = $family==='IPv4' ? 'address' : 'prefix6';
        $projected->insert(['id'=>2,$serviceColumn=>$case[1]], 'services');
        $conflict = conflictByAddress(unchangedRejectedMigration($projected), $family, $case[1]);
        $ids = $conflict['service_ids']; sort($ids);
        checkMigrationConflict($ids===[1,2], 'Projected collision missed an existing or embedded service ID');
    }
    $embeddedIndexed = $fixtures[] = conflictFixture($schema, true);
    $embeddedIndexed->insert([['id'=>1,'address'=>'203.0.113.1'],['id'=>2,'address'=>'203.0.113.1']], 'services');
    unchangedRejectedMigration($embeddedIndexed);

    // A network row takes precedence by service ID, including an intentionally empty row.
    $precedence = $fixtures[] = conflictFixture($schema, true);
    $precedence->insert([['id'=>1,'address'=>'192.0.2.101','address6'=>'2001:db8:101::/64'],
        ['id'=>3,'address'=>null,'address6'=>null]], 'network');
    $precedence->insert([['id'=>1,'address'=>'192.0.2.102','prefix6'=>'2001:db8:102::/64'],
        ['id'=>2,'address'=>'192.0.2.102','prefix6'=>'2001:db8:102::/64'],
        ['id'=>3,'address'=>'192.0.2.102','prefix6'=>'2001:db8:102::/64']], 'services');
    (new ApiSetup($precedence))->assertNetworkAddressesUnique();
    (new ApiSetup($precedence))->migrate();
    checkMigrationConflict($precedence->selectCustom('SELECT id,address,address6 FROM network ORDER BY id')===
        [['id'=>1,'address'=>'192.0.2.101','address6'=>'2001:db8:101::/64'],
         ['id'=>2,'address'=>'192.0.2.102','address6'=>'2001:db8:102::/64'],['id'=>3,'address'=>null,'address6'=>null]],
        'Embedded legacy values overrode authoritative network rows');

    // Diagnostics retain full counts while exposing bounded service-ID samples and group lists.
    $manyServices = $fixtures[] = conflictFixture($withoutUnique);
    for ($id=1; $id<=55; $id++) $manyServices->insert(['id'=>$id,'address'=>'192.0.2.200'], 'network');
    $serviceDetails = unchangedRejectedMigration($manyServices);
    $serviceConflict = conflictByAddress($serviceDetails, 'IPv4', '192.0.2.200');
    checkMigrationConflict($serviceDetails['duplicate_groups']===1 && !$serviceDetails['conflicts_truncated']
        && $serviceConflict['service_count']===55 && count($serviceConflict['service_ids'])===50
        && $serviceConflict['services_truncated'], 'Large conflict diagnostic lost its full count or 50-ID sample limit');
    $manyGroups = $fixtures[] = conflictFixture($withoutUnique);
    for ($group=1; $group<=23; $group++) {
        $manyGroups->insert([['id'=>$group*2,'address'=>'198.51.100.' . $group],
            ['id'=>$group*2+1,'address'=>'198.51.100.' . $group]], 'network');
    }
    $groupDetails = unchangedRejectedMigration($manyGroups);
    checkMigrationConflict($groupDetails['duplicate_groups']===23 && count($groupDetails['conflicts'])===20
        && $groupDetails['conflicts_truncated'], 'Large diagnostic lost its total or 20-group sample limit');

    // Repeated rejected setup must leave every recovery snapshot and operational state intact.
    $apiSqlite = new ApiSqlite('data/data.db');
    $apiSqlite->exec($withoutUnique);
    $apiSqlite->saveConfig(['version'=>'2.3.1','backup_cursor'=>2,'active_list'=>'00123']);
    $apiSqlite->insert(['id'=>1,'name'=>'Live preserved plan','uploadOverride'=>2.5], 'plans');
    $apiSqlite->insert(['id'=>1,'name'=>'Live preserved router','ip'=>'192.0.2.1','password'=>'000123'], 'devices');
    $apiSqlite->insert([['id'=>7,'address'=>'203.0.113.7'],['id'=>8,'address'=>'203.0.113.7']], 'network');
    $maliciousAddress = '<img src=x onerror=alert("fixture")>';
    $apiSqlite->insert([['id'=>21,'address'=>$maliciousAddress],['id'=>22,'address'=>$maliciousAddress]], 'network');
    for ($slot=0; $slot<=6; $slot++) ApiBackup::create('backup-' . $slot);
    file_put_contents('data/state.json', '{"version":"1.0.0","backup":"2020-01-01","backups":0,"cleanup":"2020-01-01"}');
    file_put_contents('data/plugin.log', 'Preserve this diagnostic evidence.');
    $legacyQueue = json_encode(['fixture-legacy'=>['id'=>90,'device'=>1,'status'=>3,'username'=>'legacy-pending-fixture']]);
    file_put_contents('data/queue.json', $legacyQueue);
    $beforeQueueSetup = migrationDatabaseSnapshot($apiSqlite);
    rejectedMigrationConflict(fn()=>(new ApiSetup())->run());
    checkMigrationConflict(!is_file('data/jobs.db') && file_get_contents('data/queue.json')===$legacyQueue
        && migrationDatabaseSnapshot($apiSqlite)===$beforeQueueSetup
        && glob('data/.uisp-queue-locks-*')===[],
        'Rejected setup initialized/imported the queue before reporting address conflicts');
    $pending = myJobQueue()->enqueue(['id'=>7,'device'=>1,'status'=>3,'username'=>'pending-fixture','password'=>'private-fixture']);
    checkMigrationConflict(isset(myJobQueue()->list()[$pending]), 'Pending-work preservation fixture was not queued');
    foreach (['2.3.1',MY_VERSION] as $version) {
        $apiSqlite->saveConfig(['version'=>$version]);
        $beforeSetup = migrationPersistentSnapshot();
        for ($attempt=0; $attempt<3; $attempt++) {
            rejectedMigrationConflict(fn()=>(new ApiSetup())->run());
            checkMigrationConflict(migrationPersistentSnapshot()===$beforeSetup,
                'Rejected setup rotated backups or changed live SQL, state, cursor, jobs or diagnostic evidence');
        }
        rejectedMigrationConflict(fn()=>(new ApiSetup())->migrate());
        checkMigrationConflict(migrationPersistentSnapshot()===$beforeSetup, 'Rejected direct migration changed live recovery state');
    }

    // The authenticated panel renders escaped recovery HTML; other routes retain typed JSON.
    $apiSqlite->saveConfig(['version'=>'2.3.1']);
    $admin = ['isClient'=>false,'permissions'=>['system/plugins'=>'edit']];
    $beforePublic = publicMigrationSnapshot();
    $panel = publicMigrationRequest($directory, $admin, 'GET', ['page'=>'panel']);
    checkMigrationConflict($panel['http']===400 && strpos($panel['body'], '<!DOCTYPE html>')!==false
        && strpos($panel['body'], 'Hay direcciones IP duplicadas')!==false
        && strpos($panel['body'], htmlspecialchars($maliciousAddress, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))!==false
        && strpos($panel['body'], $maliciousAddress)===false
        && strpos($panel['body'], '#21')!==false && strpos($panel['body'], '#22')!==false,
        'Authenticated recovery panel omitted conflict IDs, changed its status, or rendered unescaped address data');
    checkMigrationConflict(publicMigrationSnapshot()===$beforePublic, 'Recovery panel changed persistent recovery or operational state');
    foreach ([null,['isClient'=>true,'permissions'=>['system/plugins'=>'edit']]] as $unauthorized) {
        $response = publicMigrationRequest($directory, $unauthorized, 'GET', ['page'=>'panel']);
        $body = json_decode($response['body'], true);
        checkMigrationConflict($response['http']===403 && ($body['message'] ?? null)==='unauthorized'
            && ($body['data'] ?? null)===[] && strpos($response['body'], '203.0.113.7')===false
            && strpos($response['body'], 'duplicate_groups')===false,
            'Unauthorized request disclosed address-conflict data');
        checkMigrationConflict(publicMigrationSnapshot()===$beforePublic, 'Unauthorized recovery request modified persistent state');
    }
    foreach ([['GET',['download'=>'network-conflicts']],['GET',['download'=>'backup-0']],
        ['GET',['page'=>'panel','download'=>'network-conflicts']],['POST',[]]] as $request) {
        $response = publicMigrationRequest($directory, $admin, $request[0], $request[1]);
        $body = json_decode($response['body'], true);
        checkMigrationConflict($response['http']===400 && ($body['error'] ?? null)===true
            && ($body['message'] ?? null)==='duplicate_network_addresses_resolve_before_upgrade'
            && ($body['data']['duplicate_groups'] ?? null)===2
            && is_bool($body['data']['conflicts_truncated'] ?? null),
            'Authenticated non-panel request lost controlled typed JSON conflict details');
        $rawConflict = conflictByAddress($body['data'], 'IPv4', $maliciousAddress);
        checkMigrationConflict($rawConflict['service_ids']===[21,22], 'JSON diagnostics altered raw addresses or typed service IDs');
        checkMigrationConflict(publicMigrationSnapshot()===$beforePublic, 'JSON recovery request changed persistent recovery or operational state');
    }
    checkWebhookMigrationPrivacy($directory);

    // Restore rejects both explicit and projected collisions before replacing live data or backup-6.
    foreach (['existing','projected'] as $kind) {
        $path = 'data/conflicting-' . $kind . '.db';
        $candidate = $fixtures[] = new ApiSqlite($path);
        $candidate->exec($kind==='existing' ? $withoutUnique : $schema);
        $candidate->saveConfig(['version'=>'2.3.1']);
        $candidate->insert(['id'=>999,'name'=>'Must not replace live plan'], 'plans');
        if ($kind==='existing') {
            $candidate->insert([['id'=>1,'address6'=>'2001:db8:900::/64'],['id'=>2,'address6'=>'2001:db8:900::/64']], 'network');
        } else {
            $candidate->exec('ALTER TABLE services ADD COLUMN address TEXT; ALTER TABLE services ADD COLUMN prefix6 TEXT');
            $candidate->insert(['id'=>1,'address'=>'192.0.2.222'], 'network');
            $candidate->insert(['id'=>2,'address'=>'192.0.2.222'], 'services');
        }
        $candidateBefore = migrationDatabaseSnapshot($candidate);
        $candidateHash = hash_file('sha256', $path);
        $beforeRestore = migrationPersistentSnapshot();
        rejectedMigrationConflict(fn()=>ApiBackup::restore($path));
        checkMigrationConflict(migrationPersistentSnapshot()===$beforeRestore,
            'Rejected restore changed live data, recovery backups, state, cursor, jobs or diagnostic evidence');
        checkMigrationConflict(hash_file('sha256', $path)===$candidateHash
            && migrationDatabaseSnapshot($candidate)===$candidateBefore, 'Rejected restore modified the selected source');
        checkMigrationConflict(glob('data/restore-*.db')===[], 'Rejected restore left a staging database');
    }
    echo "Migration conflict checks passed: typed bounded IPv4/IPv6 diagnostics, effective legacy projection, network precedence, null/empty addresses, clean migrations, unchanged repeated setup/rejected restores, authenticated escaped HTML/JSON recovery and loopback webhook privacy.\n";
} finally {
    foreach ($fixtures as $fixture) $fixture->close();
    if (isset($apiSqlite) && $apiSqlite instanceof ApiSqlite) $apiSqlite->close();
    if (is_file('data/jobs.db')) myJobQueue()->close();
    chdir($originalDirectory);
    // Remove only this resolved, uniquely named temporary fixture.
    $resolved = realpath($directory); $temporaryRoot = realpath(sys_get_temp_dir());
    if ($resolved && $temporaryRoot && strpos($resolved, $temporaryRoot . DIRECTORY_SEPARATOR . 'uisp-migration-conflicts-')===0) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($resolved);
    }
}
