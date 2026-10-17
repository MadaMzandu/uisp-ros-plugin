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
function diagnosticOwner(array $details, int $serviceId, ?string $family = null): array {
    foreach ($details['conflicts'] as $conflict) {
        if ($family !== null && ($conflict['family'] ?? null) !== $family) continue;
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

    // Archive verification is independent of the service endpoint. A stored
    // client remains identifiable even after its old service is unavailable.
    $responses = ['clients/10'=>['id'=>10,'firstName'=>'Current archive','lastName'=>'Fixture','isArchived'=>true]];
    $clientOnly = $api->enrich($details,false,[2],true);
    checkNetworkDiagnostic($calls===['clients/10'] && $clientOnly['client_refresh']['api_requests']===1
        && $clientOnly['client_refresh']['clients_attempted']===1 && $clientOnly['owner_refresh']['requested']===false,
        'Client-only verification looked up a service or repeated a shared client request');
    foreach ([1,2] as $id) {
        $owner = diagnosticOwner($clientOnly,$id);
        $previous = diagnosticOwner($local,$id);
        checkNetworkDiagnostic($owner['client_archived']===true && $owner['client_source']==='crm'
            && $owner['client_name']==='Current archive Fixture' && $owner['client_refresh_error']===null
            && is_string($owner['client_verified_at']) && strtotime($owner['client_verified_at'])!==false,
            'Client-only archive verification did not update every owner of its known client');
        foreach (['service_status_code','service_status','source','refresh_error'] as $field) {
            checkNetworkDiagnostic($owner[$field]===$previous[$field], 'Client-only verification changed independent service facts');
        }
    }
    $calls = [];
    $responses['clients/10'] = new RuntimeException('Unavailable fixture archive client');
    $clientFailure = diagnosticOwner($api->enrich($details,false,[2],true),2);
    checkNetworkDiagnostic($calls===['clients/10'] && $clientFailure['client_name']==='Fixture Client'
        && $clientFailure['client_archived']===null && $clientFailure['client_source']==='cache'
        && $clientFailure['client_refresh_error']==='unavailable' && $clientFailure['client_verified_at']===null
        && $clientFailure['refresh_error']===null, 'Client lookup failure erased local facts or reported a service failure');
    foreach ([['id'=>10,'isArchived'=>false],['id'=>10,'isActive'=>false],['id'=>10,'isArchived'=>'invalid'],
        ['id'=>999,'isArchived'=>true]] as $response) {
        $calls = [];
        $responses['clients/10'] = $response;
        $owner = diagnosticOwner($api->enrich($details,false,[2],true),2);
        $expectedArchive = array_key_exists('isArchived',$response) && $response['isArchived']===false ? false : null;
        checkNetworkDiagnostic($calls===['clients/10'] && $owner['client_archived']===$expectedArchive,
            'Client-only verification confused an invalid, missing or restored archive state');
        checkNetworkDiagnostic($owner['client_refresh_error']===($response['id']===999 ? 'unavailable' : null),
            'Client-only verification accepted a mismatched client identity');
    }
    $calls = [];
    $responses = ['clients/10'=>['id'=>10,'isArchived'=>true], 'clients/30'=>['id'=>30,'isArchived'=>false],
        'clients/40'=>['id'=>40,'isArchived'=>true]];
    $boundedClients = $api->enrich(diagnosticDetails(range(1,9)),false,[1,2,3,4,5,6,7],true);
    checkNetworkDiagnostic($calls===['clients/10','clients/30','clients/40']
        && $boundedClients['client_refresh']['api_requests']===3 && $boundedClients['client_refresh']['clients_attempted']===3
        && $boundedClients['client_refresh']['truncated']===true
        && diagnosticOwner($boundedClients,5)['client_source']==='missing', 'Client-only verification exceeded three distinct clients');
    $calls = [];
    $outsideClients = $api->enrich($details,false,[999,11,true,false,0,'../1'],true);
    checkNetworkDiagnostic($calls===[] && $outsideClients['client_refresh']['clients_attempted']===0,
        'Unknown service/client IDs became arbitrary client-only CRM requests');
    $clientNow = 100.0;
    $timedClientRequest = static function (string $path) use (&$calls,&$clientNow) {
        $calls[] = $path;
        $clientNow += 13.0;
        return ['id'=>10,'isArchived'=>true];
    };
    $clientClock = static function () use (&$clientNow): float { return $clientNow; };
    $timedClients = (new ApiNetworkDiagnostics($timedClientRequest,$dataPath,$cachePath,$clientClock))
        ->enrich($details,false,[1,3],true);
    checkNetworkDiagnostic($calls===['clients/10'] && $timedClients['client_refresh']['timed_out']===true
        && $timedClients['client_refresh']['api_requests']===1
        && diagnosticOwner($timedClients,3)['client_refresh_error']==='deadline'
        && diagnosticOwner($timedClients,3)['refresh_error']===null, 'Client-only launch deadline changed service errors or allowed further reads');
    rejectNetworkDiagnostic(fn()=>$api->enrich($details,true,[1],true),'invalid_diagnostic_refresh_mode');
    checkNetworkDiagnostic(diagnosticTreeSnapshot($directory)===$before, 'Client-only CRM verification changed cache, queue, databases or backups');

    foreach ([true,false,null] as $archiveState) {
        $calls = [];
        $responses = ['clients/services/1'=>['id'=>1,'clientId'=>10,'status'=>1],
            'clients/10'=>$archiveState===null ? new RuntimeException('Unavailable shared fixture client')
                : ['id'=>10,'firstName'=>'Shared live fixture','isArchived'=>$archiveState]];
        $shared = $api->enrich($details,true,[1]);
        checkNetworkDiagnostic($calls===['clients/services/1','clients/10']
            && $shared['owner_refresh']['services_attempted']===1 && $shared['owner_refresh']['api_requests']===2,
            'Shared client propagation fetched an unselected service or repeated the client read');
        $selectedOwner = diagnosticOwner($shared,1);
        $unselectedOwner = diagnosticOwner($shared,2);
        foreach (['client_source','client_name','client_archived','client_refresh_error','client_verified_at'] as $field) {
            checkNetworkDiagnostic($selectedOwner[$field]===$unselectedOwner[$field],
                'Combined service verification left another assignment with a different fact about the same client');
        }
        checkNetworkDiagnostic($unselectedOwner['client_archived']===$archiveState
            && $unselectedOwner['client_refresh_error']===($archiveState===null ? 'unavailable' : null),
            'Combined verification did not propagate a client restore, archive or failure');
        foreach (['service_status_code','service_status','source','refresh_error'] as $field) {
            checkNetworkDiagnostic($unselectedOwner[$field]===diagnosticOwner($local,2)[$field],
                'Shared client propagation changed an unselected service state or error');
        }
    }
    checkNetworkDiagnostic(diagnosticTreeSnapshot($directory)===$before, 'Shared client propagation changed persistent plugin state');

    $calls = [];
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
        && $owner['client_source']==='cache' && $owner['refresh_error']===null
        && $owner['client_refresh_error']==='unavailable', 'A mismatched live client response was accepted or reported as a service failure');

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
        && diagnosticOwner($timed,1)['refresh_error']===null && diagnosticOwner($timed,1)['client_refresh_error']==='deadline'
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

    $archiveCachePath = $directory . '/archive-cache.db';
    $archiveCache = new SQLite3($archiveCachePath);
    $archiveCache->exec('CREATE TABLE clients(id INTEGER PRIMARY KEY,firstName TEXT,isArchived INTEGER);
        INSERT INTO clients VALUES(10,\'Cached archived fixture\',1),(30,\'Cached restored fixture\',0),
            (40,\'Cached unknown fixture\',NULL)');
    $archiveCache->close();
    $archiveBefore = diagnosticTreeSnapshot($directory);
    $cachedArchive = (new ApiNetworkDiagnostics(null,$dataPath,$archiveCachePath))->enrich(diagnosticDetails([1,2,3,4]));
    checkNetworkDiagnostic(diagnosticOwner($cachedArchive,1)['client_archived']===true
        && diagnosticOwner($cachedArchive,3)['client_archived']===false
        && diagnosticOwner($cachedArchive,4)['client_archived']===null
        && diagnosticOwner($cachedArchive,1)['client_source']==='cache'
        && diagnosticOwner($cachedArchive,1)['client_verified_at']===null
        && $cachedArchive['client_refresh']['api_requests']===0
        && diagnosticTreeSnapshot($directory)===$archiveBefore,
        'Stored archive facts lost true/false/unknown provenance or caused default CRM/cache writes');

    // Address removal in CRM changes neither the service identity nor the local
    // durable reservation. Read-only diagnostics compare those independent facts.
    $addressPath = $directory . '/address-evidence.db';
    $addressDb = new SQLite3($addressPath);
    $addressDb->exec('CREATE TABLE services(id INTEGER PRIMARY KEY,clientId INTEGER,status INTEGER);
        CREATE TABLE config(key TEXT PRIMARY KEY,value TEXT);
        INSERT INTO services VALUES(1,10,3),(2,20,1),(3,30,1);
        INSERT INTO config VALUES(\'ip_addr_attr\',\'fixture_ip\'),(\'ip_addr6_attr\',\'fixture_ip6\')');
    $rawMapping = ApiNetworkAddressEvidence::mapping($addressDb);
    $catalog = [['id'=>501,'key'=>'fixture_ip','attributeType'=>'service'],
        ['id'=>502,'key'=>'fixture_ip6','attributeType'=>'service'],
        ['id'=>503,'key'=>'fixture_client','attributeType'=>'client']];
    $validated = ApiNetworkAddressEvidence::validateMapping($rawMapping,$catalog);
    checkNetworkDiagnostic($rawMapping===['address'=>'fixture_ip','address6'=>'fixture_ip6']
        && $validated===['address'=>['key'=>'fixture_ip','id'=>501],'address6'=>['key'=>'fixture_ip6','id'=>502]],
        'Address evidence lost the configured key or validated service attribute identity');
    $addressDb->close();
    $addressDetails = diagnosticDetails([1,2,3]);
    $addressDetails['conflicts'][] = ['family'=>'IPv6','address'=>'2001:db8::/64',
        'service_ids'=>[1,2,3],'service_count'=>3,'services_truncated'=>false];
    $addressDetails['duplicate_groups'] = 2;
    $addressCalls = [];
    $addressResponses = ['custom-attributes'=>$catalog,
        'clients/services/1'=>['id'=>1,'clientId'=>10,'status'=>3,'attributes'=>[
            ['key'=>'fixture_ip','customAttributeId'=>501,'value'=>' 192.0.2.99/32 '],
            ['key'=>'fixture_ip6','customAttributeId'=>502,'value'=>'2001:0DB8:0:0::1234/64']]],
        'clients/services/2'=>['id'=>2,'clientId'=>20,'status'=>1,'attributes'=>[]],
        'clients/services/3'=>['id'=>3,'clientId'=>30,'status'=>1,'attributes'=>[
            ['key'=>'fixture_ip','customAttributeId'=>501,'value'=>'192.0.2.100'],
            ['key'=>'fixture_ip6','customAttributeId'=>502,'value'=>'2001:db8:1::abcd/64']]],
        'clients/10'=>['id'=>10,'isArchived'=>true], 'clients/20'=>['id'=>20,'isArchived'=>false],
        'clients/30'=>['id'=>30,'isArchived'=>false]];
    $addressRequest = static function (string $path) use (&$addressCalls,&$addressResponses) {
        $addressCalls[] = $path;
        if (!array_key_exists($path,$addressResponses)) throw new RuntimeException('Unexpected address fixture GET');
        if ($addressResponses[$path] instanceof Throwable) throw $addressResponses[$path];
        return $addressResponses[$path];
    };
    $addressApi = new ApiNetworkDiagnostics($addressRequest,$addressPath,$cachePath);
    $addressBefore = diagnosticTreeSnapshot($directory);
    $addressLocal = $addressApi->enrich($addressDetails);
    checkNetworkDiagnostic($addressCalls===[] && diagnosticOwner($addressLocal,1)['address_comparison']==='unknown'
        && diagnosticOwner($addressLocal,1)['current_address_known']===false
        && diagnosticOwner($addressLocal,1)['saved_address']==='192.0.2.99', 'Default address comparison read CRM or inferred a current IP');
    $addressLive = $addressApi->enrich($addressDetails,true,[1,2,3]);
    checkNetworkDiagnostic($addressCalls===['clients/services/1','custom-attributes','clients/10',
        'clients/services/2','clients/20','clients/services/3','clients/30']
        && $addressLive['owner_refresh']['api_requests']===7 && $addressLive['owner_refresh']['catalog_requests']===1
        && $addressLive['owner_refresh']['api_request_limit']===7, 'Address verification repeated catalog GETs or exceeded seven reads');
    foreach (['IPv4','IPv6'] as $family) {
        $same = diagnosticOwner($addressLive,1,$family);
        $removed = diagnosticOwner($addressLive,2,$family);
        $changed = diagnosticOwner($addressLive,3,$family);
        checkNetworkDiagnostic($same['address_comparison']==='same' && $same['current_address_known']===true
            && $same['current_address']===$same['saved_address'] && $same['address_refresh_error']===null,
            'Canonical host/prefix syntax created a false address mismatch');
        checkNetworkDiagnostic($removed['address_comparison']==='removed' && $removed['current_address_known']===true
            && $removed['current_address']===null && $removed['service_status']==='Activo'
            && $removed['source']==='crm' && $removed['address_verified_at']!==null,
            'A complete empty CRM attribute list lost the existing service or retained its old address as current');
        checkNetworkDiagnostic($changed['address_comparison']==='changed' && $changed['current_address_known']===true
            && $changed['current_address']!==$changed['saved_address'], 'Changed current address was not distinguished from the saved reservation');
    }
    checkNetworkDiagnostic(diagnosticTreeSnapshot($directory)===$addressBefore,
        'Comparing removed/changed addresses altered reservations, clients, cache, backups or jobs');
    $addressCalls = [];
    $clientOnlyAddress = $addressApi->enrich($addressDetails,false,[1],true);
    checkNetworkDiagnostic($addressCalls===['clients/10'] && diagnosticOwner($clientOnlyAddress,1)['client_archived']===true
        && diagnosticOwner($clientOnlyAddress,1)['address_comparison']==='unknown'
        && diagnosticOwner($clientOnlyAddress,1)['address_verified_at']===null, 'Client-only verification fetched IP evidence or replaced service facts');
    $unknownServices = [[], ['attributes'=>null], ['attributes'=>(object)[]],
        ['attributes'=>['named'=>['key'=>'fixture_ip','value'=>'']]], ['attributes'=>[false]],
        ['attributes'=>[['key'=>'fixture_ip']]], ['attributes'=>[['key'=>'fixture_ip','value'=>false]]],
        ['attributes'=>[['key'=>'fixture_ip','value'=>['bad']]]],
        ['attributes'=>[['key'=>'fixture_ip','value'=>'192.0.2.99/24']]],
        ['attributes'=>[['key'=>'fixture_ip','customAttributeId'=>502,'value'=>'']]],
        ['attributes'=>[['key'=>'renamed_ip','customAttributeId'=>501,'value'=>'']]],
        ['attributes'=>[['key'=>'fixture_ip','value'=>''],['key'=>'fixture_ip','value'=>'192.0.2.99']]]];
    foreach ($unknownServices as $unknownService) {
        $parsed = ApiNetworkAddressEvidence::parse($unknownService,$validated);
        checkNetworkDiagnostic($parsed['address']['known']===false
            && ApiNetworkAddressEvidence::compare('192.0.2.99',$parsed['address'],'address')==='unknown',
            'Omitted, malformed, invalid or ambiguous attributes became destructive absence evidence');
    }
    foreach ([null,[],[['id'=>501,'key'=>'fixture_ip','attributeType'=>'client']],
        [['id'=>501,'key'=>'fixture_ip']], [['id'=>true,'key'=>'fixture_ip','attributeType'=>'service']],
        [['id'=>501,'key'=>'fixture_ip','attributeType'=>'service'],['id'=>504,'key'=>'fixture_ip','attributeType'=>'service']],
        [['id'=>501,'key'=>'fixture_ip','attributeType'=>'service'],['id'=>501,'key'=>'other','attributeType'=>'service']]] as $unknownCatalog) {
        $parsed = ApiNetworkAddressEvidence::parse(['attributes'=>[]],ApiNetworkAddressEvidence::validateMapping($rawMapping,$unknownCatalog));
        checkNetworkDiagnostic($parsed['address']['known']===false, 'An unavailable, renamed, client or ambiguous catalog established a removed IP');
    }
    checkNetworkDiagnostic(ApiNetworkAddressEvidence::parse(['attributes'=>[]],$rawMapping)['address']['known']===false
        && ApiNetworkAddressEvidence::parse(['attributes'=>[]],ApiNetworkAddressEvidence::validateMapping(
            ['address'=>'missing_config_key','address6'=>null],$catalog))['address']['known']===false,
        'A configured key without a validated catalog was trusted as absence evidence');
    $explicitEmpty = ApiNetworkAddressEvidence::parse(['attributes'=>[['key'=>'fixture_ip','value'=>'  ']]],$validated);
    checkNetworkDiagnostic($explicitEmpty['address']['known']===true && $explicitEmpty['address']['value']===null,
        'Explicit empty configured IP was treated as an omitted response');
    foreach (['2001:db8::1','2001:db8::/0','2001:db8::/129','2001:db8::/x'] as $invalidPrefix) {
        $parsed = ApiNetworkAddressEvidence::parse(['attributes'=>[['key'=>'fixture_ip6','value'=>$invalidPrefix]]],$validated);
        checkNetworkDiagnostic($parsed['address6']['known']===false, 'Invalid or implicit IPv6 prefix became current address evidence');
    }
    checkNetworkDiagnostic(ApiNetworkAddressEvidence::normalize('2001:db8::ffff/127','address6')==='2001:db8::fffe/127'
        && ApiNetworkAddressEvidence::normalize('2001:db8::ffff/128','address6')==='2001:db8::ffff/128'
        && ApiNetworkAddressEvidence::mappingFingerprint($validated)!==ApiNetworkAddressEvidence::mappingFingerprint(
            ['address'=>['key'=>'fixture_ip','id'=>999],'address6'=>$validated['address6']]),
        'IPv6 boundary masking or attribute definition fingerprint lost significant evidence');
    $addressResponses['custom-attributes'] = new RuntimeException('PRIVATE CATALOG FAILURE');
    $addressCalls = [];
    $catalogFailure = diagnosticOwner($addressApi->enrich($addressDetails,true,[1]),1);
    checkNetworkDiagnostic($addressCalls===['clients/services/1','custom-attributes','clients/10']
        && $catalogFailure['source']==='crm' && $catalogFailure['refresh_error']===null
        && $catalogFailure['client_archived']===true && $catalogFailure['address_comparison']==='unknown'
        && $catalogFailure['address_refresh_error']==='mapping_unknown',
        'Catalog failure erased independently verified service/client facts or confirmed the old IP');
    checkNetworkDiagnostic(diagnosticTreeSnapshot($directory)===$addressBefore
        && strpos(json_encode($addressLive,JSON_THROW_ON_ERROR),'customAttributeId')===false,
        'Address verification mutated files or exposed catalog/attribute internals');
    $addressTime = 100.0;
    $addressCalls = [];
    $deadlineAddressRequest = static function (string $path) use (&$addressCalls,&$addressTime) {
        $addressCalls[] = $path;
        $addressTime += 13.0;
        return ['id'=>1,'clientId'=>10,'status'=>3,'attributes'=>[]];
    };
    $deadlineAddressClock = static function () use (&$addressTime): float { return $addressTime; };
    $deadlineAddress = (new ApiNetworkDiagnostics($deadlineAddressRequest,$addressPath,$cachePath,$deadlineAddressClock))
        ->enrich($addressDetails,true,[1,2]);
    checkNetworkDiagnostic($addressCalls===['clients/services/1'] && $deadlineAddress['owner_refresh']['timed_out']===true
        && $deadlineAddress['owner_refresh']['catalog_requests']===0
        && diagnosticOwner($deadlineAddress,1)['source']==='crm'
        && diagnosticOwner($deadlineAddress,1)['refresh_error']===null
        && diagnosticOwner($deadlineAddress,1)['address_comparison']==='unknown',
        'An exhausted service deadline launched a catalog GET or trusted an empty address without catalog validation');

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
    rejectNetworkDiagnostic(fn()=>ApiPanelRecovery::diagnosticRequest('POST',(object) ['changeType'=>'list','target'=>'network_conflicts',
        'data'=>(object) ['refresh'=>'true','serviceIds'=>[1]]],[]),'invalid_diagnostic_refresh');
    rejectNetworkDiagnostic(fn()=>ApiPanelRecovery::diagnosticRequest('POST',(object) ['changeType'=>'list','target'=>'network_conflicts',
        'data'=>(object) ['refreshClients'=>'true','serviceIds'=>[1]]],[]),'invalid_diagnostic_refresh_clients');
    rejectNetworkDiagnostic(fn()=>ApiPanelRecovery::diagnosticRequest('POST',(object) ['changeType'=>'list','target'=>'network_conflicts',
        'data'=>(object) ['refresh'=>true,'refreshClients'=>true,'serviceIds'=>[1]]],[]),'invalid_diagnostic_refresh_mode');
    ApiPanelRecovery::diagnosticRequest('POST',(object) ['changeType'=>'list','target'=>'network_conflicts',
        'data'=>(object) ['refresh'=>true,'serviceIds'=>[9999]]],[]);
    checkNetworkDiagnostic($GLOBALS['diagnosticResponse']['data']['owner_refresh']['requested']===true
        && $GLOBALS['diagnosticResponse']['data']['owner_refresh']['api_requests']===0
        && !class_exists('ApiUcrm',false), 'Panel diagnostic fetched a requested owner outside current conflicts');
    ApiPanelRecovery::diagnosticRequest('POST',(object) ['changeType'=>'list','target'=>'network_conflicts',
        'data'=>(object) ['refreshClients'=>true,'serviceIds'=>[9999]]],[]);
    checkNetworkDiagnostic($GLOBALS['diagnosticResponse']['data']['client_refresh']['requested']===true
        && $GLOBALS['diagnosticResponse']['data']['client_refresh']['api_requests']===0
        && !class_exists('ApiUcrm',false), 'Panel client-only verification looked up owners outside current conflicts');
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
    echo "Network diagnostic checks passed: read-only files and queue isolation, local/client identity, verified status/routes, stored and current archive facts, independent client-only reads/shared ownership, unknown lifecycle, bounded and deduplicated CRM reads, strict catalog-validated per-family current/saved address comparison, canonical IPv4/IPv6, seven-read limit, invalid response/request IDs/flags, deadline, safe field projection, missing caches, 1000 owners and panel count/recovery integration.\n";
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
