<?php
// Recovery reads must work against old schemas without opening any operational write path.
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
require_once __DIR__ . '/../src/lib/api_recovery_reads.php';

function checkRecoveryRead(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function rejectedRecoveryRead(callable $callback, string $message): void {
    try { $callback(); }
    catch (InvalidArgumentException $error) {
        checkRecoveryRead($error->getMessage() === $message, 'Recovery rejected a request with the wrong reason: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Recovery accepted an unsupported or malformed request');
}
function recoveryRequest(string $target, array $data = [], array $extra = []): object {
    return (object) array_merge(['changeType'=>'list','target'=>$target,'action'=>'list','data'=>(object) $data], $extra);
}
function recoveryTreeSnapshot(string $directory): array {
    $result = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $entry) {
        $name = substr($entry->getPathname(), strlen($directory) + 1);
        $result[$name] = $entry->isDir() ? 'directory' : hash_file('sha256', $entry->getPathname());
    }
    ksort($result);
    return $result;
}
function recoveryFixtureDatabase(string $path, string $schema): SQLite3 {
    $database = new SQLite3($path);
    $database->enableExceptions(true);
    $database->exec($schema);
    return $database;
}
function forbiddenRecoveryOperation(): void { throw new RuntimeException('Recovery reached an operational write path'); }
function mySqlite() { forbiddenRecoveryOperation(); }
function myCache() { forbiddenRecoveryOperation(); }
function myJobQueue() { forbiddenRecoveryOperation(); }
function cache_setup() { forbiddenRecoveryOperation(); }
function cache_sync() { forbiddenRecoveryOperation(); }
function run_setup() { forbiddenRecoveryOperation(); }

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-recovery-reads-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$reader = null;
$empty = null;
try {
    $mainPath = $directory . '/data.db';
    $cachePath = $directory . '/cache.db';
    $jobsPath = $directory . '/jobs.db';
    $main = recoveryFixtureDatabase($mainPath, <<<'SQL'
CREATE TABLE devices(id INTEGER PRIMARY KEY,name TEXT,ip TEXT,user TEXT,password TEXT);
CREATE TABLE services(id INTEGER PRIMARY KEY,device INTEGER,clientId INTEGER,planId INTEGER,status INTEGER,address TEXT,prefix6 TEXT);
CREATE TABLE plans(id INTEGER PRIMARY KEY,name TEXT,priority INTEGER,ratio TEXT,uploadSpeed REAL,downloadSpeed REAL,uploadOverride TEXT,burstUpload TEXT,password TEXT);
CREATE TABLE config(key TEXT PRIMARY KEY,value TEXT);
INSERT INTO devices VALUES(1,'Router preservado','192.0.2.1','00123','PRIVATE-ROUTER-PASSWORD');
INSERT INTO services VALUES(100,1,10,1,1,'198.51.100.20','2001:db8:20::/64');
INSERT INTO services VALUES(101,1,11,1,3,'198.51.100.20','2001:db8:21::/64');
INSERT INTO services VALUES(102,1,12,1,5,'198.51.100.20',NULL);
INSERT INTO services VALUES(103,2,10,1,1,'198.51.100.23',NULL);
INSERT INTO plans VALUES(1,'Plan conservado',4,'2',6.5,40,'  ','25','PRIVATE-PLAN-PASSWORD');
INSERT INTO plans VALUES(2,'Plan para revisar',4,'oops',8,20,'1e999','private_field_value','PRIVATE-PLAN-PASSWORD');
INSERT INTO config VALUES('version','2.0.2');
INSERT INTO config VALUES('active_list','00123');
INSERT INTO config VALUES('disabled_routers','1,2');
INSERT INTO config VALUES('plugin_language','es');
INSERT INTO config VALUES('webhookSecret','PRIVATE-WEBHOOK-SECRET');
INSERT INTO config VALUES('unmsToken','PRIVATE-NETWORK-TOKEN');
INSERT INTO config VALUES('password','PRIVATE-CONFIG-PASSWORD');
INSERT INTO config VALUES('arbitrary_private_key','PRIVATE-UNKNOWN-CONFIG');
SQL
    );
    $main->close();
    $cache = recoveryFixtureDatabase($cachePath, <<<'SQL'
CREATE TABLE clients(id INTEGER PRIMARY KEY,company TEXT,firstName TEXT,lastName TEXT,password TEXT);
CREATE TABLE services(id INTEGER PRIMARY KEY,clientId INTEGER,status INTEGER,username TEXT,mac TEXT,price REAL,password TEXT);
CREATE TABLE network(id INTEGER PRIMARY KEY,address TEXT,address6 TEXT);
INSERT INTO clients VALUES(10,'Cliente Uno',NULL,NULL,'PRIVATE-CLIENT-PASSWORD');
INSERT INTO clients VALUES(11,NULL,'Nombre','Apellido','PRIVATE-CLIENT-PASSWORD');
INSERT INTO services VALUES(100,10,3,'user-one','00:11:22:33:44:55',25.5,'PRIVATE-SERVICE-PASSWORD');
INSERT INTO services VALUES(101,11,1,'user-two','00:11:22:33:44:66',40,'PRIVATE-SERVICE-PASSWORD');
INSERT INTO network VALUES(100,'198.51.100.99','2001:db8:99::/64');
SQL
    );
    $cache->close();
    $jobs = recoveryFixtureDatabase($jobsPath, <<<'SQL'
CREATE TABLE jobs(id INTEGER PRIMARY KEY,service_id INTEGER,operation TEXT,payload TEXT,status TEXT,error TEXT,updated TEXT);
SQL
    );
    $payload = ['id'=>100,'device'=>1,'username'=>'user-one','clientId'=>10,'company'=>'Cliente Uno',
        'password'=>'PRIVATE-PAYLOAD-PASSWORD','token'=>'PRIVATE-PAYLOAD-TOKEN','_device'=>['password'=>'PRIVATE-FROZEN-PASSWORD'],
        '_commands'=>['password'=>'PRIVATE-COMMAND-PASSWORD'],'network'=>['secret'=>'PRIVATE-NESTED-SECRET'],
        'arbitrary'=>'PRIVATE-ARBITRARY-PAYLOAD'];
    $statement = $jobs->prepare('INSERT INTO jobs VALUES(?,?,?,?,?,?,?)');
    foreach ([[1,100,'update',$payload,'pending','private_error_secret','2026-10-14T01:00:00Z'],
        [2,101,'delete',['id'=>101,'device'=>1,'mac'=>'00:11:22:33:44:66'],'running','router_login_rejected','2026-10-14T01:01:00Z'],
        [3,102,'delete',$payload,'succeeded',null,'2026-10-14T01:02:00Z']] as $row) {
        $row[3] = json_encode($row[3], JSON_THROW_ON_ERROR);
        foreach ($row as $index=>$value) $statement->bindValue($index+1,$value,is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
        $statement->execute()->finalize();
    }
    $statement->close();
    $jobs->close();
    file_put_contents($directory . '/queue.json', json_encode(['legacy-private-key'=>$payload], JSON_THROW_ON_ERROR));
    file_put_contents($directory . '/state.json', '{"backup":"2000-01-01","cleanup":"2000-01-01"}');
    file_put_contents($directory . '/backup-0', 'Existing recovery snapshot');
    file_put_contents($directory . '/backup-6', 'Existing final recovery snapshot');
    file_put_contents($directory . '/backup-7', 'Outside the public backup allowlist');
    $cleanupBackup = 'network-cleanup-20261014T120000Z-' . str_repeat('a', 32) . '.sqlite';
    copy($mainPath, $directory . '/' . $cleanupBackup);
    file_put_contents($directory . '/network-cleanup-20261014T120000Z-' . str_repeat('b', 32) . '.sqlite', 'Invalid SQLite snapshot');
    $before = recoveryTreeSnapshot($directory);
    $reader = new ApiRecoveryReads($mainPath, $cachePath, $jobsPath);

    for ($attempt=0; $attempt<3; $attempt++) {
        $devices = $reader->list(recoveryRequest('devices'));
        checkRecoveryRead(count($devices)===1 && $devices[0]['id']===1 && $devices[0]['users']===2
            && $devices[0]['status']===null && $devices[0]['type']==='mikrotik' && $devices[0]['user']==='00123'
            && $devices[0]['disabled']===true && !array_key_exists('password',$devices[0]),
            'Legacy devices lost their stored data, secret filtering, inactive count or unchecked state');
        $plans = $reader->list(recoveryRequest('plans'));
        checkRecoveryRead(count($plans)===2 && $plans[0]['priorityUpload']===4 && $plans[0]['priorityDownload']===4
            && $plans[0]['effectiveUploadSpeed']===6.5 && $plans[0]['numericIssues']===[]
            && $plans[0]['archive']===null && !array_key_exists('password',$plans[0]),
            'Legacy plans did not project priority, optional blanks or the stored speed without an archive guess');
        checkRecoveryRead($plans[1]['ratio']===null && $plans[1]['uploadOverride']===null
            && count($plans[1]['numericIssues'])===3 && $plans[1]['effectiveUploadSpeed']===null,
            'Corrupted numeric values were disguised by read defaults or serialized as invalid numbers');
        $config = $reader->list(recoveryRequest('config'));
        checkRecoveryRead(is_object($config) && $config->active_list==='00123' && $config->disable_contention===true
            && $config->router_ppp_pool===true && $config->version==='2.0.2' && $config->pppoe_pass_attr==='password'
            && !isset($config->webhookSecret) && !isset($config->unmsToken) && !isset($config->password)
            && !isset($config->arbitrary_private_key), 'Recovery configuration lost typed defaults or disclosed private keys');
        $messages = $reader->list(recoveryRequest('messages', ['language'=>'es']));
        checkRecoveryRead(count($messages)===2 && is_object($messages[0]) && is_object($messages[1])
            && isset($messages[1]->config_active_list), 'Recovery language/field data lost its normal target shape');
        $spanish = json_encode($messages, JSON_THROW_ON_ERROR);
        checkRecoveryRead(json_encode($reader->list(recoveryRequest('lang')), JSON_THROW_ON_ERROR)===$spanish,
            'Recovery language did not follow stored preferences');
        $system = $reader->list(recoveryRequest('sys', [], ['action'=>'get']));
        checkRecoveryRead(is_string($system['app_version']) && is_string($system['php_version'])
            && isset($system['last_cache'],$system['next_cache']), 'Recovery system information lost its contract');
        $backups = $reader->list(recoveryRequest('backups'));
        checkRecoveryRead(array_column($backups,'name')===['backup-0','backup-6',$cleanupBackup] && is_string($backups[0]['date']),
            'Recovery exposed unsupported backup filenames or did not list existing snapshots');
        $queue = $reader->list(recoveryRequest('jobs'));
        checkRecoveryRead(array_keys($queue)===['Q1','Q2'] && $queue['Q1']['serviceId']===100
            && $queue['Q1']['deviceId']===1 && $queue['Q1']['state']==='pending' && $queue['Q2']['state']==='running'
            && $queue['Q1']['canDelete']===false && $queue['Q1']['blockedReason']==='maintenance'
            && $queue['Q1']['error']==='job_error_before_recovery' && $queue['Q2']['error']==='router_login_rejected',
            'Recovery jobs lost their display contract, diagnostics or paused-action status');
        $services = $reader->list(recoveryRequest('services', ['did'=>1]));
        checkRecoveryRead($services['count']===2 && count($services['data'])===2
            && $services['data'][0]['client']==='Cliente Uno' && $services['data'][1]['client']==='Nombre Apellido'
            && $services['data'][0]['address']==='198.51.100.20' && $services['data'][0]['address6']==='2001:db8:20::/64'
            && $services['data'][0]['desiredStatus']===3 && $services['data'][0]['status']===1
            && $services['data'][0]['pricef']==='25.50' && $services['data'][0]['plan']==='Plan conservado',
            'Recovery services lost legacy addresses, cached names, desired/applied states or price formatting');
        $page = $reader->list(recoveryRequest('services', ['did'=>1], ['limit'=>1,'offset'=>1]));
        checkRecoveryRead($page['count']===2 && count($page['data'])===1 && $page['data'][0]['id']===101,
            'Recovery service pagination lost total counts or the requested page');
        $search = $reader->list(recoveryRequest('services', ['did'=>1,'query'=>'Cliente Uno']));
        checkRecoveryRead($search['count']===1 && $search['data'][0]['id']===100, 'Recovery client-name search did not filter services');
        $numericSearch = $reader->list(recoveryRequest('services', ['did'=>1,'query'=>'10']));
        checkRecoveryRead($numericSearch['count']===1 && $numericSearch['data'][0]['id']===100, 'Recovery numeric search did not match a client ID');
        $allOutput = json_encode([$devices,$plans,$config,$messages,$system,$backups,$queue,$services], JSON_THROW_ON_ERROR);
        foreach (['PRIVATE-','private_error_secret','private_field_value','"payload":','"_commands":','"_device":','arbitrary_private_key'] as $secret) {
            checkRecoveryRead(strpos($allOutput,$secret)===false, 'Recovery serialized a private field or value: ' . $secret);
        }
        checkRecoveryRead(recoveryTreeSnapshot($directory)===$before, 'Repeated recovery reads changed databases, jobs, legacy queue, state, backups or file inventory');
    }
    foreach ([recoveryRequest('orphans'),recoveryRequest('attrs'),recoveryRequest('system'),
        recoveryRequest('devices', [], ['action'=>'get']),recoveryRequest('devices', [], ['changeType'=>'update']),
        recoveryRequest('devices', [], ['entity'=>'service']),recoveryRequest('../config'),
        recoveryRequest('services', ['did'=>[]]),recoveryRequest('services', ['did'=>-1]),
        recoveryRequest('services', ['did'=>1,'query'=>[]]),recoveryRequest('services', [], ['limit'=>0]),
        recoveryRequest('services', [], ['limit'=>501]),recoveryRequest('services', [], ['offset'=>-1]),
        recoveryRequest('services', [], ['limit'=>[]]),recoveryRequest('services', [], ['limit'=>true]),
        recoveryRequest('services', [], ['offset'=>false]),recoveryRequest('services', [], ['limit'=>1.5]),
        recoveryRequest('services', ['did'=>true])] as $request) {
        $expected = 'recovery_read_unavailable';
        if ($request->target==='services') {
            $expected = isset($request->data->did) ? (isset($request->data->query) ? 'invalid_search' : 'invalid_device_id') : 'invalid_pagination';
        }
        rejectedRecoveryRead(fn()=>$reader->list($request), $expected);
    }
    checkRecoveryRead(recoveryTreeSnapshot($directory)===$before, 'Rejected recovery requests changed persistent state');
    checkRecoveryRead(!class_exists('ApiSqlite', false) && !class_exists('ApiJobQueue', false)
        && !class_exists('ApiRouter', false) && !class_exists('ApiSetup', false), 'Recovery loaded an operational database/queue/router/setup dependency');

    $reader->close();
    // Existing durable queues win. Without one, inspect the legacy file without importing it.
    unlink($jobsPath);
    $legacyBefore = recoveryTreeSnapshot($directory);
    $legacyQueue = $reader->list(recoveryRequest('jobs'));
    checkRecoveryRead(array_keys($legacyQueue)===['L1'] && $legacyQueue['L1']['serviceId']===100
        && $legacyQueue['L1']['username']==='user-one' && $legacyQueue['L1']['canDelete']===false
        && strpos(json_encode($legacyQueue,JSON_THROW_ON_ERROR),'PRIVATE-')===false,
        'Legacy queue recovery lost its summary or leaked a payload credential');
    checkRecoveryRead(!is_file($jobsPath) && recoveryTreeSnapshot($directory)===$legacyBefore, 'Legacy queue read created/imported or changed queue files');
    $reader->close();
    // Newer network rows, including an explicitly empty row, retain precedence by service ID.
    $main = new SQLite3($mainPath);
    $main->enableExceptions(true);
    $main->exec("CREATE TABLE network(id INTEGER PRIMARY KEY,address TEXT,address6 TEXT); INSERT INTO network VALUES(100,'203.0.113.100','2001:db8:100::/64'); INSERT INTO network VALUES(101,NULL,NULL)");
    $main->close();
    $networkBefore = recoveryTreeSnapshot($directory);
    $networkServices = $reader->list(recoveryRequest('services', ['did'=>1]));
    checkRecoveryRead($networkServices['data'][0]['address']==='203.0.113.100'
        && $networkServices['data'][0]['address6']==='2001:db8:100::/64' && $networkServices['data'][1]['address']===null,
        'Recovery network projection overrode an authoritative network row with a legacy address');
    checkRecoveryRead(recoveryTreeSnapshot($directory)===$networkBefore, 'Reading the network schema changed source data');
    $reader->close();
    // Corrupted public numeric columns must not make the entire recovery JSON unreadable.
    $cache = new SQLite3($cachePath);
    $cache->enableExceptions(true);
    $cache->exec('UPDATE services SET price=1e999 WHERE id=100');
    $cache->close();
    $corruptBefore = recoveryTreeSnapshot($directory);
    $corruptServices = $reader->list(recoveryRequest('services', ['did'=>1]));
    checkRecoveryRead($corruptServices['data'][0]['price']===null && $corruptServices['data'][0]['pricef']===null
        && is_string(json_encode($corruptServices, JSON_THROW_ON_ERROR)), 'Corrupted stored numbers made recovery JSON unreadable or invented a numeric value');
    checkRecoveryRead(recoveryTreeSnapshot($directory)===$corruptBefore, 'Sanitizing a response changed the original corrupted number');
    $reader->close();
    // Missing caches/queues/databases are represented without creating either files or directories.
    $missingDirectory = $directory . '/never-created';
    $empty = new ApiRecoveryReads($missingDirectory . '/data.db',$missingDirectory . '/cache.db',$missingDirectory . '/jobs.db');
    foreach (['devices','plans','jobs','backups'] as $target) checkRecoveryRead($empty->list(recoveryRequest($target))===[], 'Missing recovery source did not return an empty collection: ' . $target);
    checkRecoveryRead($empty->list(recoveryRequest('services'))===['count'=>0,'data'=>[]]
        && $empty->list(recoveryRequest('config'))->disable_contention===true && !is_dir($missingDirectory),
        'Missing source recovery failed its memory-only defaults or created operational directories');
    echo "Recovery read checks passed: all panel target contracts, old schemas and missing columns/files, secret-safe snapshots, exact service pagination/search, persistent-state preservation, unimported legacy jobs and strict read allowlists.\n";
} finally {
    if ($reader !== null) $reader->close();
    if ($empty !== null) $empty->close();
    $resolved = realpath($directory);
    $temporaryRoot = realpath(sys_get_temp_dir());
    if ($resolved && $temporaryRoot && strpos($resolved, $temporaryRoot . DIRECTORY_SEPARATOR . 'uisp-recovery-reads-')===0) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($resolved);
    }
}
