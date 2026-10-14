<?php
// Synthetic, isolated CRM fixtures. No UISP or RouterOS network access is used.
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
function checkNetworkDiagnostic(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function diagnosticTreeSnapshot(string $directory): array {
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $entry) {
        if ($entry->isFile()) $files[substr($entry->getPathname(), strlen($directory) + 1)] = hash_file('sha256', $entry->getPathname());
    }
    ksort($files);
    return $files;
}
function diagnosticDetails(array $ids): array {
    return ['duplicate_groups'=>1,'conflicts'=>[['family'=>'IPv4','address'=>'192.0.2.99',
        'service_ids'=>$ids,'service_count'=>count($ids),'services_truncated'=>false]],'conflicts_truncated'=>false];
}
function diagnosticOwner(array $details, int $serviceId): array {
    foreach ($details['conflicts'] as $conflict) {
        foreach ($conflict['owners'] as $owner) if ($owner['service_id']===$serviceId) return $owner;
    }
    throw new RuntimeException('Diagnostic did not return the expected service owner');
}
function rejectNetworkDiagnostic(callable $operation, string $message): void {
    try { $operation(); }
    catch (InvalidArgumentException $error) {
        checkNetworkDiagnostic($error->getMessage()===$message, 'Diagnostic request reported the wrong validation error');
        return;
    }
    throw new RuntimeException('Invalid diagnostic request was accepted');
}
// ApiPanelRecovery's response shape is captured without invoking a live entry
// point, authentication service, logger, cache setup, migrations, or workers.
function respond($message, $error=false, $data=[], array $metadata=[]): void {
    $GLOBALS['diagnosticResponse'] = ['message'=>$message,'error'=>$error,'data'=>$data,'metadata'=>$metadata];
}

$source = realpath(__DIR__ . '/../src');
$original = getcwd();
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-network-diagnostics-' . bin2hex(random_bytes(8));
mkdir($directory,0700);
mkdir($directory . '/data',0700);
$dataPath = $directory . '/data/data.db';
$cachePath = $directory . '/data/cache.db';
$apiSqlite = null;
try {
    require $source . '/lib/api_network_diagnostics.php';
    $db = new SQLite3($dataPath);
    $db->enableExceptions(true);
    $db->exec('CREATE TABLE services(id INTEGER PRIMARY KEY,clientId INTEGER,status INTEGER,privateFixture TEXT);
        CREATE TABLE network(id INTEGER PRIMARY KEY,address TEXT,address6 TEXT);
        CREATE TABLE config(key TEXT PRIMARY KEY,value TEXT);
        INSERT INTO network VALUES(1,\'192.0.2.99\',NULL),(2,\'192.0.2.99\',NULL)');
    $statement = $db->prepare('INSERT INTO services VALUES(?,?,?,?)');
    for ($id=1; $id<=9; $id++) {
        $statement->bindValue(1,$id,SQLITE3_INTEGER);
        $statement->bindValue(2,$id<=2 ? 10 : $id*10,SQLITE3_INTEGER);
        $statement->bindValue(3,$id===1 ? 1 : ($id===2 ? 2 : 8),SQLITE3_INTEGER);
        $statement->bindValue(4,'PRIVATE-LOCAL-FIELD',SQLITE3_TEXT);
        $statement->execute()->finalize();
    }
    $statement->close();
    $db->close();
    $db = new SQLite3($cachePath);
    $db->enableExceptions(true);
    $db->exec('CREATE TABLE services(id INTEGER PRIMARY KEY,clientId INTEGER,status INTEGER,privateFixture TEXT);
        CREATE TABLE clients(id INTEGER PRIMARY KEY,company TEXT,firstName TEXT,lastName TEXT,privateFixture TEXT);
        INSERT INTO services VALUES(1,999,3,\'PRIVATE-CACHE-FIELD\'),(10,100,0,\'PRIVATE-CACHE-FIELD\');
        INSERT INTO clients VALUES(10,\'\',\'Fixture\',\'Client\',\'PRIVATE-CLIENT-FIELD\'),
            (30,\'Fixture Company\',\'\',\'\',\'PRIVATE-CLIENT-FIELD\'),
            (100,NULL,\'Cache\',\'Only\',\'PRIVATE-CLIENT-FIELD\')');
    $db->close();
    $jobs = new SQLite3($directory . '/data/jobs.db');
    $jobs->exec('CREATE TABLE jobs(id INTEGER PRIMARY KEY,payload TEXT,status TEXT);
        INSERT INTO jobs VALUES(1,\'PRIVATE-QUEUE-PAYLOAD\',\'pending\')');
    $jobs->close();
    file_put_contents($directory . '/data/queue.json','{"L1":{"privateFixture":"PRIVATE-LEGACY-PAYLOAD"}}');
    file_put_contents($directory . '/data/state.json','{"last":"fixture-state"}');
    file_put_contents($directory . '/data/backup-0','PRIVATE-BACKUP-FIXTURE');
    $calls = [];
    $responses = [];
    $request = static function (string $path) use (&$calls,&$responses) {
        $calls[] = $path;
        if (!array_key_exists($path,$responses)) throw new RuntimeException('Unexpected fixture API lookup');
        if ($responses[$path] instanceof Throwable) throw $responses[$path];
        return $responses[$path];
    };
    $api = new ApiNetworkDiagnostics($request,$dataPath,$cachePath);
    $details = diagnosticDetails([1,2,3,10,11]);
    $before = diagnosticTreeSnapshot($directory);
    $local = $api->enrich($details);
    checkNetworkDiagnostic($calls===[] && $local['owner_refresh']['api_requests']===0
        && $local['owner_refresh']['requested']===false, 'Default diagnostic called CRM');
    $owner = diagnosticOwner($local,1);
    checkNetworkDiagnostic($owner['client_id']===10 && $owner['client_name']==='Fixture Client'
        && $owner['service_status_code']===1 && $owner['source']==='database' && $owner['client_source']==='cache',
        'Main database identity did not win over an unrelated cached owner');
    checkNetworkDiagnostic($owner['service_url']==='/crm/client/service/1' && $owner['client_url']==='/crm/client/10',
        'Diagnostic lost the verified relative service/client routes');
    checkNetworkDiagnostic($owner['client_archived']===null && $owner['client_deleted']===null,
        'A missing cache lifecycle field was interpreted as current or deleted');
    checkNetworkDiagnostic(diagnosticOwner($local,2)['service_status']==='Terminado'
        && diagnosticOwner($local,3)['service_status']==='Inactivo'
        && diagnosticOwner($local,3)['client_name']==='Fixture Company', 'Ended/inactive service or company name was omitted');
    checkNetworkDiagnostic(diagnosticOwner($local,10)['client_name']==='Cache Only'
        && diagnosticOwner($local,10)['source']==='cache', 'A cache-only service could not identify its client');
    checkNetworkDiagnostic(diagnosticOwner($local,11)['client_name']===null
        && diagnosticOwner($local,11)['client_id']===null && diagnosticOwner($local,11)['client_url']===null
        && diagnosticOwner($local,11)['source']==='missing', 'A missing service acquired an invented client identity');
    checkNetworkDiagnostic(!class_exists('ApiUcrm',false) && !class_exists('ApiSqlite',false)
        && !class_exists('ApiJobQueue',false) && !class_exists('ApiSetup',false), 'Local diagnostic loaded operational dependencies');
    checkNetworkDiagnostic(diagnosticTreeSnapshot($directory)===$before, 'Default diagnostic changed persistent files');

    $responses = ['clients/services/1'=>['id'=>1,'clientId'=>10,'status'=>1,'privateFixture'=>'PRIVATE-LIVE-SERVICE'],
        'clients/services/2'=>new RuntimeException('HTTP 404 PRIVATE-TRANSPORT-DETAIL'),
        'clients/services/3'=>['id'=>3,'clientId'=>30,'status'=>8],
        'clients/10'=>['id'=>10,'firstName'=>'Current','lastName'=>'Fixture','isArchived'=>true,'isActive'=>true,
            'privateFixture'=>'PRIVATE-LIVE-CLIENT'],
        'clients/30'=>['id'=>999,'companyName'=>'Wrong fixture entity','isArchived'=>true]];
    $live = $api->enrich($details,true,[1,2,3,1]);
    checkNetworkDiagnostic($calls===['clients/services/1','clients/10','clients/services/2','clients/services/3','clients/30']
        && $live['owner_refresh']['services_attempted']===3 && $live['owner_refresh']['api_requests']===5,
        'Explicit live refresh failed to deduplicate service/client reads');
    $owner = diagnosticOwner($live,1);
    checkNetworkDiagnostic($owner['client_name']==='Current Fixture' && $owner['client_archived']===true
        && $owner['source']==='crm' && $owner['client_source']==='crm', 'Live client archive state or source was omitted');
    $owner = diagnosticOwner($live,2);
    checkNetworkDiagnostic($owner['refresh_error']==='unavailable' && $owner['client_deleted']===null
        && $owner['service_status']==='Terminado' && $owner['client_name']==='Current Fixture' && $owner['source']==='database',
        'An API 404 inferred deletion or erased usable local service facts');
    $owner = diagnosticOwner($live,3);
    checkNetworkDiagnostic($owner['client_name']==='Fixture Company' && $owner['client_archived']===null
        && $owner['client_source']==='cache' && $owner['refresh_error']==='unavailable', 'A mismatched live client response was accepted');

    $calls = [];
    $responses['clients/10'] = ['id'=>10,'firstName'=>'Fixture','lastName'=>'Client','isActive'=>false];
    $withoutArchive = diagnosticOwner($api->enrich($details,true,[1]),1);
    checkNetworkDiagnostic($withoutArchive['client_archived']===null && $withoutArchive['client_deleted']===null,
        'Client Zone access (isActive) was interpreted as archive/delete state');
    $responses['clients/10']['isArchived'] = false;
    checkNetworkDiagnostic(diagnosticOwner($api->enrich($details,true,[1]),1)['client_archived']===false,
        'Explicit current CRM archive=false was lost');

    $maliciousName = '<img src=x onerror="alert(1)"><script>fixture()</script>';
    $responses['clients/10'] = ['id'=>10,'companyName'=>$maliciousName,'firstName'=>['bad-name'],
        'isArchived'=>'unexpected','isDeleted'=>true,'privateFixture'=>'PRIVATE-LIVE-CLIENT'];
    $malicious = $api->enrich($details,true,[1]);
    checkNetworkDiagnostic(diagnosticOwner($malicious,1)['client_name']===$maliciousName
        && diagnosticOwner($malicious,1)['client_archived']===null && diagnosticOwner($malicious,1)['client_deleted']===null,
        'Diagnostic did not preserve the literal name or accepted an undocumented lifecycle value');
    $json = json_encode([$local,$live,$malicious],JSON_THROW_ON_ERROR);
    foreach (['PRIVATE-','privateFixture','isActive','isDeleted','"payload":'] as $private) {
        checkNetworkDiagnostic(strpos($json,$private)===false, 'Diagnostic serialized a private or unknown field');
    }
    checkNetworkDiagnostic(diagnosticTreeSnapshot($directory)===$before, 'Explicit CRM reads changed databases, queues, state or backups');

    foreach ([['id'=>888,'clientId'=>12345,'status'=>1],['id'=>1,'clientId'=>'../../arbitrary-path','status'=>1],
        ['id'=>1,'clientId'=>true,'status'=>1],['id'=>1,'clientId'=>0,'status'=>1],['id'=>1,'status'=>1],
        ['id'=>1,'clientId'=>[10],'status'=>1]] as $badService) {
        $calls = [];
        $responses = ['clients/services/1'=>$badService,'clients/10'=>new RuntimeException('Unavailable fixture client')];
        $rejected = diagnosticOwner($api->enrich($details,true,[1]),1);
        checkNetworkDiagnostic($calls===['clients/services/1','clients/10'] && $rejected['refresh_error']==='unavailable'
            && $rejected['client_id']===10 && $rejected['client_name']==='Fixture Client' && $rejected['source']==='database',
            'Invalid live service identity created an arbitrary path or erased known local identity');
    }
    $calls = [];
    $outside = $api->enrich($details,true,[999,true,false,'../1',0,-1,1.2,'9999999999999999999999999']);
    checkNetworkDiagnostic($calls===[] && $outside['owner_refresh']['services_attempted']===0,
        'Requested IDs outside the conflicts became general CRM lookups');

    $calls = [];
    $responses = ['clients/services/1'=>['id'=>1,'clientId'=>10,'status'=>0],
        'clients/services/2'=>['id'=>2,'clientId'=>20,'status'=>1],
        'clients/services/3'=>['id'=>3,'clientId'=>30,'status'=>2],
        'clients/10'=>new RuntimeException('Unavailable fixture client'),
        'clients/20'=>['id'=>20,'firstName'=>'Fixture','lastName'=>'Twenty','isArchived'=>false],
        'clients/30'=>['id'=>30,'firstName'=>'Fixture','lastName'=>'Thirty','isArchived'=>false]];
    $bounded = $api->enrich(diagnosticDetails(range(1,9)),true);
    checkNetworkDiagnostic($bounded['owner_refresh']['truncated']===true
        && $bounded['owner_refresh']['services_attempted']===3 && $bounded['owner_refresh']['api_requests']===6
        && count($calls)===6 && diagnosticOwner($bounded,4)['source']==='database', 'Live refresh exceeded three owners/six calls');
    $calls = [];
    $now = 100.0;
    $timedRequest = static function (string $path) use (&$calls,&$now) {
        $calls[] = $path;
        $now += 13.0;
        return ['id'=>1,'clientId'=>10,'status'=>1];
    };
    $clock = static function () use (&$now): float { return $now; };
    $timed = (new ApiNetworkDiagnostics($timedRequest,$dataPath,$cachePath,$clock))->enrich($details,true,[1,2,3]);
    checkNetworkDiagnostic($calls===['clients/services/1'] && $timed['owner_refresh']['timed_out']===true
        && $timed['owner_refresh']['api_requests']===1 && $timed['owner_refresh']['services_attempted']===1
        && diagnosticOwner($timed,1)['refresh_error']==='deadline'
        && diagnosticOwner($timed,2)['refresh_error']==='deadline', 'The launch deadline allowed further CRM reads');

    $missing = $directory . '/never-created';
    $empty = (new ApiNetworkDiagnostics($request,$missing . '/data.db',$missing . '/cache.db'))->enrich($details);
    checkNetworkDiagnostic(!is_dir($missing) && diagnosticOwner($empty,1)['client_name']===null
        && diagnosticOwner($empty,1)['client_archived']===null && diagnosticOwner($empty,1)['client_deleted']===null,
        'Missing databases/caches were created or inferred current client state');
    $noCache = (new ApiNetworkDiagnostics($request,$dataPath,$missing . '/cache.db'))->enrich($details);
    checkNetworkDiagnostic(!is_dir($missing) && diagnosticOwner($noCache,1)['client_id']===10
        && diagnosticOwner($noCache,1)['client_name']===null && diagnosticOwner($noCache,1)['client_archived']===null,
        'An absent client cache erased known service identity or created a cache');

    $largePath = $directory . '/large.db';
    $largeCache = $directory . '/large-cache.db';
    $db = new SQLite3($largePath);
    $db->exec('CREATE TABLE services(id INTEGER PRIMARY KEY,clientId INTEGER,status INTEGER); BEGIN');
    for ($id=1; $id<=1000; $id++) $db->exec('INSERT INTO services VALUES(' . $id . ',' . $id . ',1)');
    $db->exec('COMMIT');
    $db->close();
    $db = new SQLite3($largeCache);
    $db->exec('CREATE TABLE clients(id INTEGER PRIMARY KEY,company TEXT); BEGIN');
    for ($id=1; $id<=1000; $id++) $db->exec('INSERT INTO clients VALUES(' . $id . ',\'Fixture ' . $id . '\')');
    $db->exec('COMMIT');
    $db->close();
    $groups = [];
    for ($group=0; $group<20; $group++) $groups[] = ['service_ids'=>range($group*50+1,$group*50+50)];
    $largeBefore = diagnosticTreeSnapshot($directory);
    $large = (new ApiNetworkDiagnostics(null,$largePath,$largeCache))->enrich(['conflicts'=>$groups]);
    $ownerCount = 0;
    foreach ($large['conflicts'] as $group) {
        foreach ($group['owners'] as $owner) {
            checkNetworkDiagnostic($owner['client_id']===$owner['service_id']
                && $owner['client_name']==='Fixture ' . $owner['service_id'], 'SQLite binding chunks lost owner/client identity');
            $ownerCount++;
        }
    }
    checkNetworkDiagnostic($ownerCount===1000 && diagnosticTreeSnapshot($directory)===$largeBefore,
        'The maximum diagnostic dropped owners or changed SQLite files');

    // Integration: the endpoint reports the fresh conflict count and consults
    // existing records before setup/cache initialization, including after repair.
    chdir($directory);
    set_include_path($source . '/lib' . PATH_SEPARATOR . get_include_path());
    require $source . '/lib/api_panel_recovery.php';
    $db = new SQLite3($dataPath);
    $db->exec('INSERT INTO config VALUES(\'version\',\'' . MY_VERSION . '\')');
    $db->close();
    $integrationBefore = diagnosticTreeSnapshot($directory);
    $diagnosticRequest = (object) ['changeType'=>'list','target'=>'network_conflicts','data'=>(object) []];
    checkNetworkDiagnostic(ApiPanelRecovery::diagnosticRequest('POST',$diagnosticRequest,[]), 'Panel helper did not recognize diagnostic reads');
    checkNetworkDiagnostic($GLOBALS['diagnosticResponse']['data']['duplicate_groups']===1
        && $GLOBALS['diagnosticResponse']['metadata']['recovery']['readOnly']===true
        && $GLOBALS['diagnosticResponse']['data']['owner_refresh']['api_requests']===0,
        'Panel diagnostic lost read-only status, current count or no-CRM default');
    checkNetworkDiagnostic(ApiPanelRecovery::diagnosticRequest('GET',null,['download'=>'network-conflicts'])
        && $GLOBALS['diagnosticResponse']['data']['duplicate_groups']===1,
        'Diagnostic download did not use the read-only request path');
    ApiPanelRecovery::diagnosticRequest('POST',(object) ['changeType'=>'list','target'=>'network_conflicts',
        'data'=>(object) ['refresh'=>'true','serviceIds'=>[1]]],[]);
    checkNetworkDiagnostic($GLOBALS['diagnosticResponse']['data']['owner_refresh']['requested']===false,
        'A non-boolean refresh field triggered live CRM reads');
    ApiPanelRecovery::diagnosticRequest('POST',(object) ['changeType'=>'list','target'=>'network_conflicts',
        'data'=>(object) ['refresh'=>true,'serviceIds'=>[9999]]],[]);
    checkNetworkDiagnostic($GLOBALS['diagnosticResponse']['data']['owner_refresh']['requested']===true
        && $GLOBALS['diagnosticResponse']['data']['owner_refresh']['api_requests']===0
        && !class_exists('ApiUcrm',false), 'Panel diagnostic fetched a requested owner outside current conflicts');
    checkNetworkDiagnostic(diagnosticTreeSnapshot($directory)===$integrationBefore && !class_exists('ApiJobQueue',false),
        'Panel diagnostic initialized/mutated persistent state or workers');
    rejectNetworkDiagnostic(fn()=>ApiPanelRecovery::diagnosticRequest('POST',(object) ['changeType'=>'list',
        'target'=>'network_conflicts','action'=>'update','data'=>(object) []],[]),'invalid_diagnostic_action');
    rejectNetworkDiagnostic(fn()=>ApiPanelRecovery::diagnosticRequest('POST',(object) ['changeType'=>'list',
        'target'=>'network_conflicts','data'=>'invalid'],[]),'invalid_diagnostic_data');
    rejectNetworkDiagnostic(fn()=>ApiPanelRecovery::diagnosticRequest('POST',(object) ['changeType'=>'list',
        'target'=>'network_conflicts','data'=>(object) ['serviceIds'=>'invalid']],[]),'invalid_diagnostic_service_ids');
    checkNetworkDiagnostic(!ApiPanelRecovery::diagnosticRequest('POST',(object) ['changeType'=>'update','target'=>'network_conflicts'],[])
        && !ApiPanelRecovery::diagnosticRequest('POST',(object) ['changeType'=>'list','target'=>'network_conflicts','entity'=>'service'],[]),
        'Panel diagnostic accepted a mutation or entity webhook as an administrative list');
    if ($apiSqlite !== null) { $apiSqlite->close(); $apiSqlite = null; }
    $db = new SQLite3($dataPath);
    $db->exec('UPDATE network SET address=\'192.0.2.100\' WHERE id=2');
    $db->close();
    $repairedBefore = diagnosticTreeSnapshot($directory);
    ApiPanelRecovery::diagnosticRequest('POST',$diagnosticRequest,[]);
    checkNetworkDiagnostic($GLOBALS['diagnosticResponse']['data']['duplicate_groups']===0
        && $GLOBALS['diagnosticResponse']['metadata']['recovery']['readOnly']===false
        && diagnosticTreeSnapshot($directory)===$repairedBefore, 'Recheck reused a stale conflict count or changed repaired data');
    echo "Network diagnostic checks passed: read-only files and queue isolation, local/client identity, verified status/routes, explicit archive facts, unknown lifecycle, bounded and deduplicated CRM reads, invalid response/request IDs, deadline, safe field projection, missing caches, 1000 owners and panel count/recovery integration.\n";
} finally {
    if ($apiSqlite !== null) $apiSqlite->close();
    chdir($original);
    $resolved = realpath($directory);
    $temporaryRoot = realpath(sys_get_temp_dir());
    if ($resolved && $temporaryRoot && strpos($resolved,$temporaryRoot . DIRECTORY_SEPARATOR . 'uisp-network-diagnostics-')===0) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($resolved);
    }
}
