<?php
// Standalone regression suite. All data and SDK responses are isolated temporary fixtures.
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
function checkBackend(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function rejectedBackend(callable $operation, string $message): void {
    try { $operation(); } catch (Throwable $error) { return; }
    throw new RuntimeException($message);
}
function rejectedVersionBackend(callable $operation, string $context, string $storedVersion): array {
    try { $operation(); }
    catch (Throwable $error) {
        checkBackend($error instanceof ApiVersionMismatchException, 'Version rejection did not identify its specific cause');
        checkBackend($error->getMessage()===($context==='backup' ? 'backup_version_is_newer_than_plugin' : 'database_version_is_newer_than_plugin'),
            'Version rejection confused the live database with a restored backup');
        $versions = $error->getVersions();
        $manifest = json_decode(file_get_contents('manifest.json'),true);
        checkBackend(($versions['database_version'] ?? null)===$storedVersion, 'Version diagnostic altered the raw stored version');
        checkBackend(($versions['runtime_version'] ?? null)===MY_VERSION, 'Version diagnostic omitted the executing PHP version');
        checkBackend(($versions['package_version'] ?? null)===$manifest['information']['version'], 'Version diagnostic omitted the installed package version');
        return $versions;
    }
    throw new RuntimeException('An unsupported newer version was accepted');
}
function versionStateBackend(): array {
    $backups = [];
    foreach (glob('data/backup-*') as $file) if (preg_match('/^backup-[0-6]$/',basename($file))) $backups[basename($file)] = hash_file('sha256',$file);
    ksort($backups);
    return [
        'database' => hash_file('sha256','data/data.db'),
        'backups' => $backups,
        'state' => is_file('data/state.json') ? file_get_contents('data/state.json') : null,
        'cursor' => mySqlite()->singleQuery('SELECT value FROM config WHERE key=?',false,['backup_cursor']),
        'jobs' => myJobQueue()->list()
    ];
}
function publicGetBackend(string $directory, array $user, array $query): array {
    static $sequence = 0;
    $script = $directory . '/public-version-check-' . ++$sequence . '.php';
    $code = '<?php $GLOBALS["testUser"]=json_decode(' . var_export(json_encode($user),true)
        . '); $_SERVER=["REQUEST_METHOD"=>"GET","REMOTE_ADDR"=>"192.0.2.10"]; $_GET=json_decode('
        . var_export(json_encode($query),true) . ',true); register_shutdown_function(function(){echo "\nHTTP=".http_response_code();}); require '
        . var_export($directory . '/public.php',true) . ';';
    file_put_contents($script,$code);
    $pipes=[];
    $process=proc_open([PHP_BINARY,'-n','-d','extension_dir='.ini_get('extension_dir'),'-d','extension=sqlite3',$script],
        [1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $output=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($process);
    checkBackend($exit===0 && $stderr==='', 'The authenticated public version request did not finish cleanly');
    $split=strrpos($output,"\nHTTP=");
    checkBackend($split!==false, 'The public response did not report its HTTP status');
    return ['http'=>(int)substr($output,$split+6),'response'=>json_decode(substr($output,0,$split),true)];
}
$originalDirectory = getcwd();
$source = realpath(__DIR__ . '/../src');
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-backend-test-' . bin2hex(random_bytes(8));
mkdir($directory);
foreach (['lib','includes','includes/l10n','data','vendor','public'] as $folder) mkdir($directory . '/' . $folder, 0700);
foreach (glob($source . '/lib/*.php') as $file) copy($file, $directory . '/lib/' . basename($file));
// Queue delivery can be replaced only in this disposable fixture; production keeps its normal factory.
$queueFixture = $directory . '/lib/api_job_queue.php';
$queueCode = file_get_contents($queueFixture);
$queueCode = str_replace('static $queue = null;', 'static $queue = null; if (isset($GLOBALS["backendQueueOverride"])) return $GLOBALS["backendQueueOverride"];', $queueCode, $queueFactoryReplacements);
checkBackend($queueFactoryReplacements===1, 'The isolated queue-delivery hook no longer matches its factory');
file_put_contents($queueFixture, $queueCode);
foreach (glob($source . '/includes/*') as $file) if (is_file($file)) copy($file, $directory . '/includes/' . basename($file));
copy($source . '/public.php', $directory . '/public.php');
copy($source . '/manifest.json', $directory . '/manifest.json');
file_put_contents($directory . '/vendor/autoload.php', <<<'PHP'
<?php
namespace Ubnt\UcrmPluginSdk\Service;
class UcrmApi {
    public static function create($token=null) { return new static(); }
    public function __call($method, $arguments) {
        return ($GLOBALS['testApi'])($method, $arguments[0], $arguments[1] ?? []);
    }
}
class UnmsApi extends UcrmApi {}
class UcrmSecurity {
    public static function create() { return new static(); }
    public function getUser() { return $GLOBALS['testUser'] ?? null; }
}
PHP
);
chdir($directory);
require_once 'lib/api_sqlite.php';
require_once 'lib/api_common.php';
require_once 'lib/api_setup.php';
require_once 'lib/api_security.php';
require_once 'lib/api_cache.php';
require_once 'lib/api_update.php';
require_once 'lib/api_list.php';
try {
    $memory = new ApiSqlite(':memory:');
    $memory->exec('CREATE TABLE credentials(id INTEGER PRIMARY KEY,username TEXT,password TEXT,name TEXT); CREATE TABLE config(key TEXT PRIMARY KEY,value TEXT,last TEXT,created TEXT)');
    $memory->insert(['id'=>1,'username'=>'00123','password'=>'0001','name'=>'O\'Connor "ACME"'], 'credentials');
    $row = $memory->singleQuery('SELECT * FROM credentials WHERE id=?', true, [1]);
    checkBackend($row['username']==='00123' && $row['password']==='0001' && $row['name']==='O\'Connor "ACME"', 'SQL changed string credentials or quoted names');
    $attack = '"); DELETE FROM credentials; --';
    $memory->edit(['id'=>1,'name'=>$attack], 'credentials');
    checkBackend($memory->singleQuery('SELECT name FROM credentials')===$attack, 'SQL payload was interpreted as code');
    $memory->saveConfig(['test'=>"'); DELETE FROM credentials; --"]);
    checkBackend($memory->singleQuery('SELECT count(*) FROM credentials')===1, 'Config value executed SQL');
    $memory->saveConfig(['active_list'=>'00123','disabled_profile'=>'0007','pppoe_user_attr'=>'0',
        'pppoe_pass_attr'=>'true','version'=>'2.40','lease_time'=>'0.5','disabled_rate'=>'0','backup_cursor'=>'-1']);
    $typedConfig = $memory->readConfig();
    checkBackend($typedConfig->active_list==='00123' && $typedConfig->disabled_profile==='0007'
        && $typedConfig->pppoe_user_attr==='0' && $typedConfig->pppoe_pass_attr==='true'
        && $typedConfig->version==='2.40', 'Configuration changed numeric or boolean-looking text identifiers');
    checkBackend($typedConfig->lease_time===0.5 && $typedConfig->disabled_rate===0.0 && $typedConfig->backup_cursor===-1,
        'Declared numeric settings lost their types or valid zero');
    foreach (['true'=>true,'false'=>false,'1'=>true,'0'=>false,'on'=>true,'off'=>false,'yes'=>true,'no'=>false] as $flag=>$expected) {
        $memory->saveConfig(['disable_contention'=>(string) $flag]);
        checkBackend($memory->readConfig()->disable_contention===$expected, 'Legacy boolean setting decoded incorrectly: ' . $flag);
    }
    rejectedBackend(fn()=>$memory->deleteAll('credentials; DROP TABLE config'), 'Unsafe identifier accepted');
    rejectedBackend(function () use ($memory) {
        $memory->transaction(function () use ($memory) {
            $memory->edit(['id'=>1,'password'=>'changed'], 'credentials');
            throw new RuntimeException('fixture rollback');
        });
    }, 'Transaction exception lost');
    checkBackend($memory->singleQuery('SELECT password FROM credentials')==='0001', 'Nested transaction did not roll back');
    $memory->close();

    $schema = file_get_contents('includes/schema.sql');
    $legacySchema = preg_replace('/^.*(?:"ssl"|"allowSelfSigned"|CREATE UNIQUE INDEX).*\n/m', '', $schema);
    $apiSqlite = new ApiSqlite('data/data.db');
    $apiSqlite->exec($legacySchema);
    $apiSqlite->saveConfig(['version'=>'2.3.1','device_name_attr'=>'device','pppoe_user_attr'=>'username','pppoe_pass_attr'=>'password']);
    $apiSqlite->insert(['id'=>1,'name'=>'Plan','uploadOverride'=>21,'downloadOverride'=>51,'priorityUpload'=>3,'priorityDownload'=>4], 'plans');
    $apiSqlite->insert(['id'=>1,'name'=>'Router','ip'=>'192.0.2.1','user'=>'fixture','password'=>'00123'], 'devices');
    (new ApiSetup())->migrate();
    $row = $apiSqlite->singleQuery('SELECT * FROM plans WHERE id=1', true);
    checkBackend([$row['uploadOverride'],$row['downloadOverride'],$row['priorityUpload'],$row['priorityDownload']] === [21,51,3,4], 'Migration reordered plan columns');
    checkBackend($apiSqlite->selectDeviceById(1)->type==='mikrotik', 'Existing panel device with null type was not repaired');
    checkBackend(!(new ApiSetup())->needs_cols(), 'Migration omitted current columns');
    (new ApiSetup())->migrate();
    checkBackend($apiSqlite->singleQuery('SELECT uploadOverride FROM plans WHERE id=1')===21, 'Repeated migration changed plan');

    $legacy = new ApiSqlite(':memory:');
    $legacy->exec('CREATE TABLE services(id INTEGER PRIMARY KEY,device INTEGER,clientId INTEGER,planId INTEGER,status INTEGER,address TEXT,prefix6 TEXT); CREATE TABLE plans(id INTEGER PRIMARY KEY); CREATE TABLE devices(id INTEGER PRIMARY KEY,name TEXT,ip TEXT,user TEXT,password TEXT); CREATE TABLE config(key TEXT PRIMARY KEY,value TEXT,last TEXT,created TEXT)');
    $legacy->insert(['id'=>2,'address'=>'192.0.2.20','prefix6'=>'2001:db8:20::/64'], 'services');
    $legacy->saveConfig(['version'=>'2.3.1']);
    (new ApiSetup($legacy))->migrate();
    checkBackend($legacy->selectAddress(2)==='192.0.2.20', 'Migration lost legacy embedded network address');
    $legacy->close();

    $versioned = new ApiSqlite(':memory:');
    $versioned->exec($legacySchema);
    $versioned->saveConfig(['version'=>'2.0.10']);
    $versioned->insert(['id'=>1,'uploadSpeed'=>100,'downloadSpeed'=>100,'limitUpload'=>20,'limitDownload'=>20], 'plans');
    (new ApiSetup($versioned))->migrate();
    checkBackend($versioned->singleQuery('SELECT limitUpload FROM plans')===20, 'Version 2.0.10 was incorrectly converted as older than 2.0.2');
    $versioned->close();

    $previous = new ApiSqlite(':memory:');
    $previous->exec($schema);
    $previous->saveConfig(['version'=>'2.4.3']);
    $previous->insert(['id'=>1,'name'=>'Previous candidate','uploadSpeed'=>2.5,'downloadSpeed'=>8,
        'uploadOverride'=>3.75,'downloadOverride'=>12.5,'burstUpload'=>37.5,'threshUpload'=>22.5,'timeUpload'=>9.5], 'plans');
    $previous->insert(['id'=>1,'name'=>'Previous router','ip'=>'192.0.2.30','user'=>'fixture','password'=>'000123'], 'devices');
    (new ApiSetup($previous))->migrate();
    $previousPlan = $previous->singleQuery('SELECT uploadOverride,downloadOverride,burstUpload,threshUpload,timeUpload FROM plans WHERE id=1',true);
    checkBackend(array_values($previousPlan)===[3.75,12.5,37.5,22.5,9.5], 'Updating the previous candidate changed decimal plan settings');
    checkBackend($previous->singleQuery('SELECT password FROM devices WHERE id=1')==='000123', 'Updating the previous candidate changed a password with leading zeros');
    checkBackend($previous->singleQuery('SELECT value FROM config WHERE key=?',false,['version'])===MY_VERSION, 'Compatible migration did not record the current runtime version');
    $previous->close();

    // A live version mismatch must be rejected before rotating backups or touching billing/queue state.
    ApiBackup::create('backup-0');
    ApiBackup::create('backup-6');
    file_put_contents('data/state.json',json_encode(['version'=>'1.0.0','backup'=>'2020-01-01','backups'=>0,'cleanup'=>'2020-01-01']));
    $pendingVersionJob = myJobQueue()->enqueue(['id'=>90,'device'=>1,'username'=>'pending-version-fixture','status'=>3]);
    $jobsBeforeVersions = myJobQueue()->list();
    foreach (['9.9.9','2.40'] as $futureVersion) {
        $apiSqlite->saveConfig(['version'=>$futureVersion,'backup_cursor'=>2]);
        checkBackend($apiSqlite->singleQuery('SELECT value FROM config WHERE key=?',false,['version'])===$futureVersion,
            'The future version fixture did not preserve its raw text');
        $beforeRejectedSetup = versionStateBackend();
        rejectedVersionBackend(fn()=>(new ApiSetup())->run(),'database',$futureVersion);
        checkBackend(versionStateBackend()===$beforeRejectedSetup, 'Rejected live setup changed the database, backup rotation, state or jobs');
        rejectedVersionBackend(fn()=>(new ApiSetup())->migrate(),'database',$futureVersion);
        checkBackend(versionStateBackend()===$beforeRejectedSetup, 'Rejected migration wrote to the live database');
        $publicVersion = publicGetBackend($directory,['isClient'=>false,'permissions'=>['system/plugins'=>'edit']],['page'=>'panel']);
        checkBackend($publicVersion['http']===400 && ($publicVersion['response']['error'] ?? null)===true
            && ($publicVersion['response']['message'] ?? null)==='database_version_is_newer_than_plugin',
            'An authenticated panel GET misidentified its newer live database');
        $diagnostic=$publicVersion['response']['data'] ?? [];
        $package=json_decode(file_get_contents('manifest.json'),true)['information']['version'];
        checkBackend(($diagnostic['database_version'] ?? null)===$futureVersion && ($diagnostic['runtime_version'] ?? null)===MY_VERSION
            && ($diagnostic['package_version'] ?? null)===$package, 'The public version error omitted safe raw/runtime/package diagnostics');
        checkBackend(versionStateBackend()===$beforeRejectedSetup, 'Rejected authenticated GET rotated a backup or changed database/state/jobs');
    }
    // The version on disk and the version executing in PHP can differ after an incomplete/cached update.
    $originalManifest=file_get_contents('manifest.json');
    $mismatchedManifest=json_decode($originalManifest,true);
    $mismatchedManifest['information']['version']='2.4.99';
    file_put_contents('manifest.json',json_encode($mismatchedManifest));
    try {
        $beforePackageDiagnostic=versionStateBackend();
        $mixedVersions=rejectedVersionBackend(fn()=>(new ApiSetup())->assertCompatible(),'database','2.40');
        checkBackend($mixedVersions['runtime_version']!==$mixedVersions['package_version'], 'Version diagnosis collapsed the loaded runtime into the installed package');
        $mixedPublic=publicGetBackend($directory,['isClient'=>false,'permissions'=>['system/plugins'=>'edit']],['page'=>'panel']);
        checkBackend($mixedPublic['http']===400 && ($mixedPublic['response']['data']['runtime_version'] ?? null)===MY_VERSION
            && ($mixedPublic['response']['data']['package_version'] ?? null)==='2.4.99', 'Public diagnosis concealed a package/runtime mismatch');
        checkBackend(versionStateBackend()===$beforePackageDiagnostic, 'Package/runtime diagnosis modified persistent state');
    } finally { file_put_contents('manifest.json',$originalManifest); }
    $apiSqlite->saveConfig(['version'=>'2.4.3','backup_cursor'=>-1]);
    (new ApiSetup())->migrate();
    checkBackend($apiSqlite->singleQuery('SELECT uploadOverride FROM plans WHERE id=1')===21
        && $apiSqlite->singleQuery('SELECT password FROM devices WHERE id=1')==='00123', 'Compatible live upgrade changed plan values or router credentials');
    checkBackend(myJobQueue()->list()===$jobsBeforeVersions && isset(myJobQueue()->list()[$pendingVersionJob]), 'Compatible migration discarded pending work');

    $apiSqliteCache = new ApiSqlite('data/cache.db');
    $apiSqliteCache->exec(file_get_contents('includes/cache.sql'));
    $apiSqliteCache->insert(['id'=>77,'status'=>3,'device'=>1,'clientId'=>77,'username'=>'preserved','password'=>'private'], 'services');
    $apiSqliteCache->insert(['id'=>77,'firstName'=>'Old'], 'clients');
    $testApi = function ($method, $path, $params) {
        if ($path==='clients' && ($params['offset'] ?? 0)===0) return [['id'=>1,'firstName'=>'Downloaded']];
        if ($path==='clients' && ($params['offset'] ?? 0)>0) throw new RuntimeException('fixture page failure');
        return [];
    };
    rejectedBackend(fn()=>(new ApiCache())->sync(true), 'Failed download was reported successful');
    checkBackend($apiSqliteCache->singleQuery('SELECT status FROM services WHERE id=77')===3 && $apiSqliteCache->singleQuery('SELECT firstName FROM clients WHERE id=77')==='Old', 'Failed download replaced valid cache');
    $beforeNext = $apiSqlite->readConfig()->next_cache ?? null;
    $testApi = function ($method, $path, $params) use ($directory) {
        if ($path==='clients' && ($params['offset'] ?? 0)===0) {
            $writer = new ApiSqlite($directory . '/data/cache.db');
            $writer->execute('UPDATE services SET status=4 WHERE id=77');
            $writer->close();
            return [['id'=>1,'firstName'=>'Stale']];
        }
        return [];
    };
    rejectedBackend(fn()=>(new ApiCache())->sync(true), 'Concurrent webhook update was overwritten by cache publication');
    checkBackend($apiSqliteCache->singleQuery('SELECT status FROM services WHERE id=77')===4, 'Cache refresh resurrected stale service status');
    checkBackend(($apiSqlite->readConfig()->next_cache ?? null)===$beforeNext, 'Failed cache scheduled a week-long retry delay');
    $testApi = fn() => [];
    (new ApiCache())->sync(true);
    checkBackend($apiSqliteCache->singleQuery('SELECT count(*) FROM services')===0, 'Valid empty cache did not publish');

    // Exercise the real normalizer + action + Batch snapshot together. Only delivery is deliberately deferred.
    require_once 'lib/api_action.php';
    class BackendDeferredQueue extends ApiJobQueue {
        public array $submitted = [];
        public function run(?array $keys = null, ?callable $executor = null): array {
            if ($executor !== null) return parent::run($keys, $executor);
            return parent::run($keys, function ($snapshot) {
                $this->submitted[] = $snapshot;
                throw new RuntimeException('fixture deferred delivery; no router network');
            });
        }
    }
    $queueBeforeNormalization = myJobQueue();
    $apiJobQueue = new BackendDeferredQueue(':memory:', null);
    $GLOBALS['backendQueueOverride'] = $apiJobQueue;
    $apiAttributes = null;
    $apiSqlite->insert(['id'=>178,'name'=>'Literal router','ip'=>'192.0.2.178','user'=>'fixture','password'=>'0','type'=>'mikrotik'], 'devices');
    $apiSqlite->insert(['id'=>177,'name'=>'Preserved plan','ratio'=>5,'uploadSpeed'=>20,'downloadSpeed'=>50,
        'uploadOverride'=>3.75,'burstUpload'=>50,'burstDownload'=>50,'threshUpload'=>25,'threshDownload'=>25,
        'timeUpload'=>12,'timeDownload'=>12,'priorityUpload'=>8,'priorityDownload'=>8,'limitUpload'=>100,'limitDownload'=>100], 'plans');
    $apiSqliteCache->insert(['id'=>178,'firstName'=>'Old','lastName'=>'Surname','company'=>'ACME'], 'clients');
    $apiSqliteCache->insert(['id'=>178,'clientId'=>178,'planId'=>177,'device'=>178,'status'=>1,
        'username'=>'0','password'=>' secret ','uploadSpeed'=>20,'downloadSpeed'=>50], 'services');
    $apiSqliteCache->insert(['id'=>178,'address'=>'192.0.2.178'], 'network');
    $lifecycle = function ($action, $uuid, $fields): object {
        return (object)['entity'=>'service','entityId'=>178,'changeType'=>$action,'uuid'=>$uuid,
            'extraData'=>(object)['entity'=>(object)array_replace(['id'=>178,'clientId'=>178], $fields)]];
    };
    $trimmed = myTrimmer()->trim('service', $lifecycle('suspend','real-thin-suspend',[]));
    checkBackend(!array_key_exists('planId',$trimmed['entity']) && !array_key_exists('username',$trimmed['entity']),
        'Normalizer confused omitted plan/attributes with an explicit clear');
    (new ApiAction($lifecycle('suspend','real-thin-suspend',[])))->exec();
    $suspended = end($apiJobQueue->submitted);
    checkBackend($apiSqliteCache->singleQuery('SELECT planId FROM services WHERE id=178')===177
        && $suspended['_plan']['id']===177 && $suspended['_plan']['uploadSpeed']===3.75,
        'A thin suspension lost the configured plan/override in the real Batch path');
    (new ApiAction($lifecycle('unsuspend','real-thin-resume',[])))->exec();
    $resumed = end($apiJobQueue->submitted);
    checkBackend($resumed['status']===1 && $resumed['_plan']['id']===177 && (float)$resumed['_plan']['burstUpload']===5625000.0
        && $resumed['username']==='0' && $resumed['password']===' secret ', 'Thin reactivation changed plan, burst or literal credentials');
    $nullPlan = myTrimmer()->trim('service',$lifecycle('edit','explicit-null-plan',['servicePlanId'=>null]));
    checkBackend(array_key_exists('planId',$nullPlan['entity']) && $nullPlan['entity']['planId']===null,
        'An explicitly cleared service plan was confused with an omitted property');
    (new ApiAction($lifecycle('edit','explicit-null-plan',['servicePlanId'=>null])))->exec();
    checkBackend($apiSqliteCache->singleQuery('SELECT planId FROM services WHERE id=178')===null,
        'An explicit null plan did not clear the prior plan in the real action path');
    $apiSqliteCache->edit(['id'=>178,'planId'=>177], 'services');
    $partialPrevious = $lifecycle('edit','partial-previous-preserved',['price'=>5]);
    $partialPrevious->extraData->entityBeforeEdit = (object)['id'=>178,'clientId'=>178,'price'=>0];
    (new ApiAction($partialPrevious))->exec();
    checkBackend($apiSqliteCache->singleQuery('SELECT planId FROM services WHERE id=178')===177
        && $apiSqliteCache->singleQuery('SELECT password FROM services WHERE id=178')===' secret ',
        'A partial before-edit entity cleared identity or plan fields');
    $appliedSeed = end($apiJobQueue->submitted);
    $appliedSeed['_event_id'] = 'real-applied-seed';
    $appliedKey = $apiJobQueue->enqueue($appliedSeed);
    $apiJobQueue->run([$appliedKey], fn()=>true);
    $apiSqliteCache->delete(178,'services'); $apiSqliteCache->delete(178,'network');
    (new ApiAction($lifecycle('unsuspend','real-thin-from-applied',[])))->exec();
    $fromApplied = end($apiJobQueue->submitted);
    checkBackend($fromApplied['planId']===177 && $fromApplied['_plan']['uploadSpeed']===3.75
        && $fromApplied['password']===' secret ' && $apiSqliteCache->exists(178,'services')===178,
        'An omitted plan/identity was not recovered from the applied snapshot after a cache loss');
    // A cache refresh can see a billing state before its thin edit notification is handled.
    // Comparing that desired cache with itself must not hide the router's older applied state.
    $apiSqliteCache->edit(['id'=>178,'status'=>3], 'services');
    $beforeCacheAheadSuspend = count($apiJobQueue->submitted);
    $cacheAheadSuspend = $lifecycle('edit','cache-ahead-suspend',['status'=>3]);
    (new ApiAction($cacheAheadSuspend))->exec();
    checkBackend(count($apiJobQueue->submitted)>$beforeCacheAheadSuspend
        && end($apiJobQueue->submitted)['status']===3 && $apiJobQueue->appliedSnapshot(178)['status']===1,
        'A desired cache already suspended suppressed a thin edit while RouterOS applied state was active');
    $apiJobQueue->run($apiJobQueue->eventKeys('cache-ahead-suspend'), fn()=>true);
    $apiSqliteCache->edit(['id'=>178,'status'=>1], 'services');
    $beforeCacheAheadResume = count($apiJobQueue->submitted);
    (new ApiAction($lifecycle('edit','cache-ahead-resume',['status'=>1])))->exec();
    checkBackend(count($apiJobQueue->submitted)>$beforeCacheAheadResume && end($apiJobQueue->submitted)['status']===1,
        'A desired cache already active suppressed a thin reactivation while RouterOS applied state was suspended');
    $apiJobQueue->run($apiJobQueue->eventKeys('cache-ahead-resume'), fn()=>true);
    $beforeOldSuspensionReplay = count($apiJobQueue->submitted);
    (new ApiAction($cacheAheadSuspend))->exec();
    checkBackend(count($apiJobQueue->submitted)===$beforeOldSuspensionReplay
        && $apiSqliteCache->singleQuery('SELECT status FROM services WHERE id=178')===1,
        'Replaying an older completed suspension UUID overwrote a newer reactivation');
    $apiSqliteCache->edit(['id'=>178,'status'=>3], 'services');
    $sameBefore = $lifecycle('edit','cache-ahead-equal-before',['status'=>3]);
    $sameBefore->extraData->entityBeforeEdit = (object)['id'=>178,'clientId'=>178,'status'=>3];
    $beforeEqualSnapshot = count($apiJobQueue->submitted);
    (new ApiAction($sameBefore))->exec();
    checkBackend(count($apiJobQueue->submitted)>$beforeEqualSnapshot && end($apiJobQueue->submitted)['status']===3,
        'A provided before-edit snapshot equal to the advanced cache suppressed pending applied-state reconciliation');
    $apiJobQueue->run($apiJobQueue->eventKeys('cache-ahead-equal-before'), fn()=>true);
    foreach (['00123', '123'] as $password) {
        $apiSqliteCache->edit(['id'=>178,'password'=>$password], 'services');
        $beforeCredentialReconcile = count($apiJobQueue->submitted);
        $credentialEvent = 'cache-ahead-credential-' . $password;
        (new ApiAction($lifecycle('edit',$credentialEvent,['status'=>3])))->exec();
        checkBackend(count($apiJobQueue->submitted)>$beforeCredentialReconcile
            && end($apiJobQueue->submitted)['password']===$password && end($apiJobQueue->submitted)['username']==='0',
            'Advanced cache credentials were ignored or numeric-looking literal passwords were compared numerically');
        $apiJobQueue->run($apiJobQueue->eventKeys($credentialEvent), fn()=>true);
    }
    // An identity change visible first in the cache still deletes the actual frozen old destination first.
    $apiSqliteCache->edit(['id'=>178,'device'=>1], 'services');
    (new ApiAction($lifecycle('edit','cache-ahead-router',['status'=>3])))->exec();
    $migrationKeys = $apiJobQueue->eventKeys('cache-ahead-router');
    checkBackend(count($migrationKeys)===2, 'A cache-ahead router identity did not create its old-account cleanup barrier');
    $normalizationJobs = (new ReflectionProperty(ApiJobQueue::class, 'db'))->getValue($apiJobQueue);
    $oldMigration = json_decode($normalizationJobs->querySingle('SELECT payload FROM jobs WHERE id='.(int)substr($migrationKeys[0],1)),true);
    $newMigration = json_decode($normalizationJobs->querySingle('SELECT payload FROM jobs WHERE id='.(int)substr($migrationKeys[1],1)),true);
    checkBackend($oldMigration['action']==='delete' && $oldMigration['device']===178 && $oldMigration['_device']['ip']==='192.0.2.178'
        && $newMigration['action']==='update' && $newMigration['device']===1 && $newMigration['password']==='123',
        'Applied identity cleanup was redirected to the newly cached router or lost frozen credentials');
    $apiJobQueue->run($migrationKeys, fn()=>true);
    $terminalSnapshot = $apiJobQueue->appliedSnapshot(178);
    $terminalSnapshot['_event_id'] = 'cache-ahead-terminal';
    $terminalSnapshot['_terminal'] = true;
    $terminalSnapshot['status'] = 2;
    $terminalKey = $apiJobQueue->enqueue($terminalSnapshot,'delete');
    $apiSqliteCache->edit(['id'=>178,'status'=>1], 'services'); // Simulate a stale cache refresh while ending remains pending.
    $beforeMetadataWhileTerminal = count($apiJobQueue->submitted);
    (new ApiAction($lifecycle('edit','metadata-after-terminal',['price'=>17])))->exec();
    checkBackend($apiJobQueue->isServiceBlocked(178) && count($apiJobQueue->submitted)===$beforeMetadataWhileTerminal
        && $apiJobQueue->eventKeys('metadata-after-terminal')===[],
        'A metadata-only thin edit revoked a pending terminal billing intent');
    (new ApiAction($lifecycle('edit','explicit-state-after-terminal',['status'=>1])))->exec();
    checkBackend(!$apiJobQueue->isServiceBlocked(178) && count($apiJobQueue->eventKeys('explicit-state-after-terminal'))===1,
        'A fresh explicit active billing state could not queue reactivation after terminal cleanup');
    $apiJobQueue->run([$terminalKey], fn()=>true);
    $apiJobQueue->run($apiJobQueue->eventKeys('explicit-state-after-terminal'), fn()=>true);
    $apiSqliteCache->edit(['id'=>178,'device'=>178,'password'=>' secret '], 'services');
    $attributes = myAttr()->extract([(object)['key'=>'device','value'=>'Literal router'],
        (object)['key'=>'username','value'=>'0'],(object)['key'=>'password','value'=>' secret '],(object)['key'=>'iaid','value'=>'0']]);
    checkBackend($attributes['device']===178 && $attributes['username']==='0' && $attributes['password']===' secret '
        && $attributes['iaid']==='0', 'Real attribute extraction altered zero or credential whitespace');
    $zeroPassword = myAttr()->extract([(object)['key'=>'password','value'=>'0']]);
    checkBackend($zeroPassword['password']==='0', 'A valid literal zero password disappeared');
    $clientEvent = (object)['entity'=>'client','changeType'=>'edit','extraData'=>(object)['entity'=>(object)['id'=>178,'firstName'=>'New']]];
    (new ApiAction($clientEvent))->exec();
    $client = $apiSqliteCache->singleQuery('SELECT * FROM clients WHERE id=178',true);
    checkBackend($client['firstName']==='New' && $client['lastName']==='Surname' && $client['company']==='ACME',
        'Partial client event erased omitted surname/company');
    $clientEvent->extraData->entity->lastName = null;
    (new ApiAction($clientEvent))->exec();
    checkBackend($apiSqliteCache->singleQuery('SELECT lastName FROM clients WHERE id=178')===null
        && $apiSqliteCache->singleQuery('SELECT company FROM clients WHERE id=178')==='ACME', 'Explicit client null failed or cleared an unrelated field');
    $hydrationReads = 0;
    $testApi = function ($method, $path, $params) use (&$hydrationReads) {
        if ($path==='clients/services/179') {
            $hydrationReads++;
            return ['id'=>179,'clientId'=>178,'servicePlanId'=>177,'status'=>1,'uploadSpeed'=>20,'downloadSpeed'=>50,
                'attributes'=>[['key'=>'device','value'=>'Literal router'],['key'=>'username','value'=>'new-user'],['key'=>'password','value'=>'0']]];
        }
        throw new RuntimeException('fixture service unavailable');
    };
    $newThin = (object)['entity'=>'service','entityId'=>179,'changeType'=>'suspend','uuid'=>'new-thin-hydrated',
        'extraData'=>(object)['entity'=>(object)['id'=>179]]];
    (new ApiAction($newThin))->exec();
    $hydrated = end($apiJobQueue->submitted);
    checkBackend($hydrationReads===1 && $hydrated['clientId']===178 && $hydrated['planId']===177 && $hydrated['status']===3
        && $hydrated['username']==='new-user' && $hydrated['password']==='0', 'New thin event was not safely completed from UISP before provisioning');
    $newThin->entityId=180; $newThin->extraData->entity->id=180; $newThin->uuid='new-thin-unavailable';
    $jobsBeforeUnavailable = $apiJobQueue->list();
    rejectedBackend(fn()=>(new ApiAction($newThin))->exec(), 'Unavailable thin event was provisioned with invented fallback settings');
    checkBackend($apiJobQueue->list()===$jobsBeforeUnavailable && !$apiSqliteCache->exists(180,'services'),
        'Failed hydration changed cached desired state or scheduled unsafe work');
    $apiJobQueue->close(); $apiJobQueue=$queueBeforeNormalization; unset($GLOBALS['backendQueueOverride']);
    foreach (['services','network','clients'] as $table) { $apiSqliteCache->delete(178,$table); $apiSqliteCache->delete(179,$table); }
    $apiSqlite->delete(177,'plans'); $apiSqlite->delete(178,'devices');
    $apiAttributes = null;
    $testApi = fn() => [];

    $readUser = (object)['isClient'=>false,'permissions'=>['system/plugins'=>'view']];
    $editUser = (object)['isClient'=>false,'permissions'=>['system/plugins'=>'edit']];
    checkBackend(ApiSecurity::isAdmin($readUser) && !ApiSecurity::isAdmin($readUser,true) && ApiSecurity::isAdmin($editUser,true), 'Role permissions are incorrect');
    checkBackend(!ApiSecurity::isAdmin((object)['isClient'=>true,'permissions'=>['system/plugins'=>'edit']],true), 'Client received plugin privileges');
    $event = (object)['entity'=>'service','changeType'=>'edit','entityId'=>77,'uuid'=>'fixture-event-0123456789'];
    $secret = str_repeat('a',32);
    checkBackend(ApiSecurity::webhookAuthorized($event,['HTTP_X_WEBHOOK_TOKEN'=>$secret],[],['webhookSecret'=>$secret]), 'Valid webhook token rejected');
    checkBackend(!ApiSecurity::webhookAuthorized($event,['REMOTE_ADDR'=>'172.18.0.1'],[],['webhookSecret'=>$secret]), 'Private proxy bypassed webhook token');
    checkBackend(!ApiSecurity::webhookAuthorized((object)['changeType'=>'update','target'=>'system'],['HTTP_X_WEBHOOK_TOKEN'=>$secret],[],['webhookSecret'=>$secret]), 'Webhook token authorized admin operation');
    checkBackend(!ApiSecurity::sameOrigin(['HTTP_HOST'=>'crm.example']), 'Missing Origin accepted for session write');
    checkBackend(!ApiSecurity::sameOrigin(['HTTP_HOST'=>'crm.example','HTTPS'=>'on','HTTP_ORIGIN'=>'http://crm.example']), 'Downgraded origin accepted');
    checkBackend(ApiSecurity::sameOrigin(['HTTP_HOST'=>'internal:8080','HTTP_ORIGIN'=>'https://crm.example'], 'https://crm.example/crm/'), 'Valid public proxy origin rejected');

    $updater = new ApiUpdate((object)['data'=>(object)['qos'=>1]], 'devices');
    $validate = new ReflectionMethod(ApiUpdate::class,'validateRow');
    if (PHP_VERSION_ID < 80100) $validate->setAccessible(true);
    $validated = $validate->invoke($updater,['name'=>'New','ip'=>'192.0.2.2','user'=>'fixture','password'=>'0001','qos'=>1],false);
    checkBackend($validated['type']==='mikrotik' && $validated['password']==='0001', 'Device validation altered credentials/default type');
    $qos = new ReflectionMethod(ApiUpdate::class,'qos_change');
    if (PHP_VERSION_ID < 80100) $qos->setAccessible(true);
    checkBackend($qos->invoke($updater)===1, 'New router requires an existing ID');
    $planUpdater = new ApiUpdate(null,'plans');
    $validated = $validate->invoke($planUpdater,['uploadOverride'=>3.75,'downloadOverride'=>null,'burstUpload'=>-100,'priorityUpload'=>8],true);
    checkBackend($validated['uploadOverride']===3.75 && $validated['downloadOverride']===null && $validated['burstUpload']===-100.0, 'Plan contract rejected decimals/null/disabled burst');
    $blankValidated = $validate->invoke($planUpdater,['limitUpload'=>" \t\r\n",'uploadOverride'=>''],true);
    checkBackend($blankValidated['limitUpload']===null && $blankValidated['uploadOverride']===null,
        'Optional blank plan fields were not normalized to unset values');

    // UISP base speeds can be correct while a plugin-only percentage is corrupt.
    $apiSqlite->insert(['id'=>333,'name'=>'Archived 25/5 fixture','uploadSpeed'=>5,'downloadSpeed'=>25,
        'ratio'=>1,'priorityUpload'=>8,'priorityDownload'=>8,'limitUpload'=>0], 'plans');
    $apiSqlite->execute('UPDATE plans SET limitUpload=1e999 WHERE id=333');
    $beforePlanRead = $apiSqlite->singleQuery('SELECT * FROM plans WHERE id=333',true);
    $listPlanMethod = new ReflectionMethod(ApiList::class,'find_db_plans');
    if (PHP_VERSION_ID < 80100) $listPlanMethod->setAccessible(true);
    $listedPlans = array_column($listPlanMethod->invoke(new ApiList(null,'plans')),null,'id');
    $diagnosedPlan = $listedPlans[333];
    checkBackend($diagnosedPlan['uploadSpeed']===5 && $diagnosedPlan['downloadSpeed']===25
        && $diagnosedPlan['effectiveUploadSpeed']===5 && $diagnosedPlan['limitUpload']===null,
        'Listing disguised a corrupt percentage as zero or changed the valid UISP speeds');
    checkBackend(($diagnosedPlan['numericIssues'][0]['field'] ?? null)==='limitUpload'
        && ($diagnosedPlan['numericIssues'][0]['reason'] ?? null)==='non_finite'
        && ($diagnosedPlan['numericIssues'][0]['value'] ?? null)==='Infinity',
        'Listing omitted the safe original numeric diagnosis');
    json_encode($listedPlans,JSON_THROW_ON_ERROR);
    checkBackend($apiSqlite->singleQuery('SELECT * FROM plans WHERE id=333',true)===$beforePlanRead,
        'Reviewing a corrupt plan silently repaired its stored configuration');
    $apiSqlite->execute('UPDATE plans SET limitUpload=?,uploadOverride=? WHERE id=333',[" \t",'']);
    $listedPlans = array_column($listPlanMethod->invoke(new ApiList(null,'plans')),null,'id');
    checkBackend($listedPlans[333]['numericIssues']===[] && $listedPlans[333]['limitUpload']===0
        && $listedPlans[333]['effectiveUploadSpeed']===5,
        'A legacy optional blank was diagnosed as corruption or hid the inherited UISP speed');
    $apiSqlite->execute('DELETE FROM plans WHERE id=333');

    // Disabled contention must allow burst edits without rewriting a legacy ratio of zero.
    $apiSqlite->insert(['id'=>55,'name'=>'Legacy zero ratio','ratio'=>0,'uploadSpeed'=>4,'downloadSpeed'=>8,
        'uploadOverride'=>null,'downloadOverride'=>null,'priorityUpload'=>3,'priorityDownload'=>4], 'plans');
    $apiSqlite->saveConfig(['disable_contention'=>true]);
    $configBeforePlanEdit = $apiSqlite->selectAllFromTable('config');
    $legacyPlanBeforeEdit = $apiSqlite->singleQuery('SELECT * FROM plans WHERE id=55',true);
    $jobsBeforePlanEdit = myJobQueue()->list();
    $burstEdit = new ApiUpdate(['action'=>'edit','data'=>['id'=>55,'burstUpload'=>33.5,'burstDownload'=>45.5,
        'threshUpload'=>15.25,'threshDownload'=>20.5,'timeUpload'=>12.5,'timeDownload'=>9.5]],'plans');
    $burstEdit->exec();
    $legacyPlanAfterEdit = $apiSqlite->singleQuery('SELECT * FROM plans WHERE id=55',true);
    checkBackend($legacyPlanAfterEdit['ratio']===0 && ($burstEdit->result()['ratio'] ?? null)===0,
        'Partial burst edit replaced or rejected a legacy ratio while contention was disabled');
    $changedBurstFields = ['burstUpload'=>33.5,'burstDownload'=>45.5,'threshUpload'=>15.25,
        'threshDownload'=>20.5,'timeUpload'=>12.5,'timeDownload'=>9.5];
    foreach ($changedBurstFields as $field=>$expected) checkBackend($legacyPlanAfterEdit[$field]===$expected,
        'Partial burst edit did not persist the supplied decimal field: ' . $field);
    $unchangedFields = array_fill_keys(array_merge(array_keys($changedBurstFields),['last']),true);
    checkBackend(array_diff_key($legacyPlanAfterEdit,$unchangedFields)===array_diff_key($legacyPlanBeforeEdit,$unchangedFields),
        'Partial burst edit altered omitted plan settings');
    checkBackend($apiSqlite->selectAllFromTable('config')===$configBeforePlanEdit
        && $apiSqlite->readConfig()->disable_contention===true, 'Partial plan edit changed global contention settings');
    checkBackend(myJobQueue()->list()===$jobsBeforePlanEdit, 'A plan without services scheduled router work');

    // Explicitly edited ratios retain their validation when contention is enabled.
    $apiSqlite->saveConfig(['disable_contention'=>false]);
    $enabledConfigBeforeEdit = $apiSqlite->selectAllFromTable('config');
    $invalidRatioRejected = false;
    try { (new ApiUpdate(['action'=>'edit','data'=>['id'=>55,'ratio'=>0,'burstDownload'=>80]],'plans'))->exec(); }
    catch (InvalidArgumentException $error) {
        $invalidRatioRejected = $error->getMessage()==='invalid_plan_priority_or_ratio';
    }
    checkBackend($invalidRatioRejected, 'Enabled contention accepted an explicitly supplied ratio of zero');
    checkBackend($apiSqlite->singleQuery('SELECT * FROM plans WHERE id=55',true)===$legacyPlanAfterEdit,
        'Rejected ratio edit partially changed the plan');
    checkBackend($apiSqlite->selectAllFromTable('config')===$enabledConfigBeforeEdit
        && $apiSqlite->readConfig()->disable_contention===false, 'Rejected ratio edit changed global contention settings');
    $apiSqlite->saveConfig(['disable_contention'=>true]);

    $apiSqliteCache->insert(['id'=>77,'device'=>1,'clientId'=>77,'status'=>1,'planId'=>1,'username'=>'00123','password'=>'private','price'=>0], 'services');
    $apiSqliteCache->insert(['id'=>77,'firstName'=>"O'Connor",'lastName'=>'ACME'], 'clients');
    $apiSqlite->insert(['id'=>77,'device'=>1,'clientId'=>77,'planId'=>1,'status'=>1], 'services');
    $list = new ApiList((object)['data'=>(object)['did'=>1,'query'=>"O'Connor"]], 'services');
    $result = $list->list();
    checkBackend($result['count']===1 && count($result['data'])===1 && !isset($result['data'][0]['password']), 'Service search/joins/secret filtering failed');
    rejectedBackend(fn()=>(new ApiList((object)['data'=>(object)['did'=>'1 OR 1=1']], 'services'))->list(), 'Injected device filter accepted');

    ApiBackup::create('backup-6');
    $apiSqlite->edit(['id'=>1,'name'=>'Changed'], 'plans');
    $openHandle = new ApiSqlite('data/data.db');
    ApiBackup::restore('data/backup-6');
    checkBackend($apiSqlite->singleQuery('SELECT name FROM plans WHERE id=1')==='Plan', 'Restoring backup-6 used the newly rotated backup instead of selected snapshot');
    checkBackend($openHandle->singleQuery('SELECT name FROM plans WHERE id=1')==='Plan', 'Restore stranded an open database handle');
    $openHandle->close();
    file_put_contents('data/invalid-backup', '{"not":"sqlite"}');
    rejectedBackend(fn()=>ApiBackup::restore('data/invalid-backup'), 'Invalid upload replaced database');
    rejectedBackend(fn()=>ApiBackup::path('../main.php'), 'Backup path traversal accepted');
    $futureBackup = new ApiSqlite('data/future-backup.db');
    $futureBackup->exec($schema);
    $futureBackup->insert(['id'=>999,'name'=>'Must not replace live plan'], 'plans');
    foreach (['9.9.9','2.40'] as $futureVersion) {
        $futureBackup->saveConfig(['version'=>$futureVersion]);
        $sourceHash = hash_file('sha256','data/future-backup.db');
        $beforeRejectedRestore = versionStateBackend();
        rejectedVersionBackend(fn()=>ApiBackup::restore('data/future-backup.db'),'backup',$futureVersion);
        checkBackend(versionStateBackend()===$beforeRejectedRestore, 'Rejected future backup changed live data, backup-6, rotation or pending jobs');
        checkBackend(hash_file('sha256','data/future-backup.db')===$sourceHash, 'Rejected restoration modified the selected backup');
        checkBackend(glob('data/restore-*.db')===[], 'Rejected future backup left a staging database');
    }
    $futureBackup->close();
    $duplicate = new ApiSqlite('data/duplicate.db');
    $duplicate->exec($legacySchema);
    $duplicate->saveConfig(['version'=>'2.3.1']);
    $duplicate->insert([['id'=>1,'address'=>'192.0.2.99'],['id'=>2,'address'=>'192.0.2.99']], 'network');
    $duplicate->close();
    rejectedBackend(fn()=>ApiBackup::restore('data/duplicate.db'), 'Duplicate address backup was installed');
    checkBackend($apiSqlite->singleQuery('SELECT name FROM plans WHERE id=1')==='Plan', 'Failed candidate migration replaced live database');

    $rotation = [ApiBackup::rotate(),ApiBackup::rotate(),ApiBackup::rotate()];
    checkBackend(count(array_unique($rotation))===3, 'Fast backups reused a rotation slot');
    file_put_contents('data/queue.json', '{}');
    file_put_contents('public/backup-1', 'published fixture');
    file_put_contents('public/config.json', 'secret fixture');
    (new ApiSetup())->run();
    checkBackend(file_get_contents('data/queue.json')==='{}', 'Setup cleanup deleted pending legacy jobs');
    checkBackend(!is_file('public/backup-1') && !is_file('public/config.json'), 'Published secrets survived setup');

    $syncCalls = 0;
    $testApi = function ($method,$path,$data) use (&$syncCalls) {
        $syncCalls++;
        if ($path==='custom-attributes') return [['id'=>1,'key'=>'ipAddress','attributeType'=>'service']];
        throw new RuntimeException('fixture sync failure');
    };
    file_put_contents('data/config.json',json_encode(['syncAttrHour'=>'']));
    require_once 'lib/api_ip_mgmt.php';
    checkBackend($syncCalls===0, 'Empty sync hour did not disable the job');
    $apiSqlite->insert(['id'=>77,'address'=>'203.0.113.77'], 'network', INSERT_REPLACE);
    file_put_contents('data/config.json',json_encode(['syncAttrHour'=>(int)date('G')]));
    sync_ip_attributes();
    checkBackend(($apiSqlite->readConfig()->sync_attr_date ?? null)===null, 'Failed IP PATCH was marked completed');
    MyLog()->Append(['password'=>'fixture-password-secret','attributes'=>[['key'=>'password','value'=>'fixture-attribute-secret']]],LOG_ERR);
    $log = file_get_contents('data/plugin.log');
    checkBackend(strpos($log,'fixture-password-secret')===false && strpos($log,'fixture-attribute-secret')===false && strpos($log,'[redacted]')!==false, 'Error log exposed credentials or suppressed errors');

    // Verify real public.php rejects private proxies and client sessions before creating data files.
    foreach ([null,(object)['isClient'=>true,'permissions'=>['system/plugins'=>'edit']],$readUser] as $index=>$user) {
        $script = $directory . '/public-check-' . $index . '.php';
        $query = $index===2 ? ['download'=>'backup-0'] : ['sync'=>1];
        $code = '<?php $GLOBALS["testUser"]=json_decode(' . var_export(json_encode($user),true) . '); $_SERVER=["REQUEST_METHOD"=>"GET","REMOTE_ADDR"=>"172.18.0.1"]; $_GET=json_decode(' . var_export(json_encode($query),true) . ',true); register_shutdown_function(function(){echo "\nHTTP=".http_response_code();}); require ' . var_export($directory . '/public.php',true) . ';';
        file_put_contents($script,$code);
        $pipes=[];
        $process=proc_open([PHP_BINARY,'-n','-d','extension_dir='.ini_get('extension_dir'),'-d','extension=sqlite3',$script],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $output=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]); $exit=proc_close($process);
        checkBackend($exit===0 && $stderr==='' && strpos($output,'HTTP=403')!==false, 'Public auth did not preserve HTTP 403 for proxy/client/read-only backup');
    }
    echo "Backend regression checks passed: prepared SQL, typed configuration, literal credentials, real partial-event normalization/Batch snapshots and UISP hydration, migration, raw version diagnostics/preflight, safe rejected restores, cache failure/concurrency, permissions, partial burst edits and panel contracts.\n";
} finally {
    if (isset($GLOBALS['backendQueueOverride'])) { $GLOBALS['backendQueueOverride']->close(); unset($GLOBALS['backendQueueOverride']); }
    foreach ([$apiSqlite ?? null,$apiSqliteCache ?? null,$previous ?? null,$futureBackup ?? null] as $database) if ($database) $database->close();
    if (is_file($directory . '/data/jobs.db')) myJobQueue()->close();
    chdir($originalDirectory);
    // Delete only this generated temporary fixture; never traverse the workspace.
    if (strpos(realpath($directory),realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'uisp-backend-test-')===0) {
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($directory);
    }
}
