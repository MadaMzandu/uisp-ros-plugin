<?php
// Isolated synthetic SQLite stores and a GET-only CRM transport. No external network or RouterOS access.
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
function repairCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function repairReject(callable $work, string $reason): void {
    try { $work(); } catch (InvalidArgumentException $error) {
        repairCheck($error->getMessage() === $reason, 'Unexpected repair rejection: ' . $error->getMessage()); return;
    }
    throw new RuntimeException('Unsafe repair accepted: ' . $reason);
}
function repairSql(string $path, string $sql): void {
    $database = new SQLite3($path); $database->enableExceptions(true);
    try { $database->exec($sql); } finally { $database->close(); }
}
function repairRows(string $path): array {
    $database = new SQLite3($path, SQLITE3_OPEN_READONLY); $database->enableExceptions(true);
    try {
        $rows = [];
        foreach (['network','services','config','devices','plans'] as $table) {
            $result = $database->query('SELECT * FROM "' . $table . '" ORDER BY rowid'); $rows[$table] = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) $rows[$table][] = $row;
            $result->finalize();
        }
        return $rows;
    } finally { $database->close(); }
}
function repairFixture(string $root, string $name): array {
    $directory = $root . DIRECTORY_SEPARATOR . $name; mkdir($directory,0700);
    $data = $directory . '/data.db'; $jobs = $directory . '/jobs.db'; $cache = $directory . '/cache.db';
    repairSql($data, "CREATE TABLE network(id INTEGER PRIMARY KEY,address TEXT,address6 TEXT,last TEXT);
        CREATE TABLE services(id INTEGER PRIMARY KEY,clientId INTEGER,status INTEGER,device INTEGER,address TEXT,prefix6 TEXT,last TEXT);
        CREATE TABLE config(key TEXT PRIMARY KEY,value TEXT);
        CREATE TABLE devices(id INTEGER PRIMARY KEY,password TEXT);
        CREATE TABLE plans(id INTEGER PRIMARY KEY,uploadSpeed INTEGER,downloadSpeed INTEGER);
        INSERT INTO config VALUES('ip_addr_attr','fixture_ip'),('ip_addr6_attr','fixture_ip6');
        INSERT INTO devices VALUES(1,'PRIVATE-ROUTER-PASSWORD'); INSERT INTO plans VALUES(1,7,21);
        INSERT INTO services VALUES(11,111,1,1,'PRIVATE-INVALID-ONE','2001:db8:11::/64','service11'),
        (12,112,3,1,'PRIVATE-INVALID-TWO',NULL,'service12'),(13,113,1,1,'198.51.100.0/24',NULL,'range13'),
        (14,114,2,1,NULL,'PRIVATE-INVALID-V6','ended14');
        INSERT INTO network VALUES(11,'PRIVATE-INVALID-ONE','2001:db8:11::/64','network11'),
        (12,'PRIVATE-INVALID-TWO',NULL,'network12'),(13,'198.51.100.0/24',NULL,'network13'),
        (14,NULL,'PRIVATE-INVALID-V6','network14');");
    repairSql($cache, 'CREATE TABLE network(id INTEGER PRIMARY KEY,address TEXT,address6 TEXT); CREATE TABLE services(id INTEGER PRIMARY KEY,clientId INTEGER);');
    repairSql($jobs, "CREATE TABLE jobs(id INTEGER PRIMARY KEY,service_id INTEGER,status TEXT,operation TEXT,payload TEXT,prior_cleared INTEGER,cleanup_done INTEGER);
        CREATE TABLE service_versions(service_id INTEGER PRIMARY KEY,applied_payload TEXT,applied_identity TEXT);
        CREATE TABLE queue_meta(key TEXT PRIMARY KEY,value TEXT);
        CREATE TABLE service_event_locks(service_id INTEGER PRIMARY KEY);
        CREATE TABLE cancellation_cleanups(id INTEGER PRIMARY KEY,service_id INTEGER,payload TEXT);");
    file_put_contents($directory . '/queue.json', '{}');
    return ['directory' => $directory, 'data' => $data, 'jobs' => $jobs, 'cache' => $cache, 'legacy' => $directory . '/queue.json'];
}
function repairJob(array $fixture, int $sid, array $payload, string $operation = 'update'): void {
    $database = new SQLite3($fixture['jobs']);
    try {
        $statement = $database->prepare("INSERT INTO jobs(service_id,status,operation,payload,prior_cleared,cleanup_done) VALUES(?, 'pending', ?, ?, 0, 0)");
        $statement->bindValue(1,$sid,SQLITE3_INTEGER); $statement->bindValue(2,$operation,SQLITE3_TEXT);
        $statement->bindValue(3,json_encode($payload,JSON_THROW_ON_ERROR),SQLITE3_TEXT); $statement->execute()->finalize(); $statement->close();
    } finally { $database->close(); }
}
function repairBlocked(ApiNetworkRepair $api, array $selection, string $reason): void {
    $result = $api->preview($selection);
    repairCheck($result['candidates'] === [] && $result['previewToken'] === null && ($result['blocked'][0]['reason'] ?? '') === $reason,
        'Unexpected preview result for ' . $reason . ': ' . json_encode($result));
}

$source = realpath(__DIR__ . '/../src'); require $source . '/vendor/autoload.php';
require $source . '/lib/api_network_repair.php'; require $source . '/lib/api_network_cleanup.php';
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-network-repair-' . bin2hex(random_bytes(8)); mkdir($root,0700);
$time = 1800000000.0; $calls = []; $responses = []; $collection = []; $mutate = null;
$service = static function (int $id, ?string $address, ?string $address6, int $status = 1): array {
    return ['id' => $id, 'clientId' => 100 + $id, 'status' => $status, 'attributes' => [
        ['key' => 'fixture_ip', 'customAttributeId' => 1, 'value' => $address],
        ['key' => 'fixture_ip6', 'customAttributeId' => 2, 'value' => $address6]]];
};
$reset = static function () use (&$responses,&$collection,$service,&$calls,&$mutate): void {
    $responses = ['custom-attributes' => [['id'=>1,'key'=>'fixture_ip','attributeType'=>'service'], ['id'=>2,'key'=>'fixture_ip6','attributeType'=>'service']],
        'clients/services/11' => $service(11,'192.0.2.11','2001:db8:11::/64'), 'clients/111' => ['id'=>111,'isArchived'=>false],
        'clients/services/12' => $service(12,null,null,3), 'clients/112' => ['id'=>112,'isArchived'=>false],
        'clients/services/14' => $service(14,null,'2001:db8:14::/64',2), 'clients/114' => ['id'=>114,'isArchived'=>true]];
    $collection = [$responses['clients/services/11'],$responses['clients/services/12'],$responses['clients/services/14']];
    $calls = []; $mutate = null;
};
$request = static function (string $path, array $parameters) use (&$responses,&$collection,&$calls,&$mutate) {
    repairCheck($path === 'custom-attributes' || $path === 'clients/services' || preg_match('~^clients/(?:services/)?[1-9][0-9]*$~D',$path), 'Unexpected repair endpoint');
    $calls[] = [$path,$parameters];
    if ($mutate !== null) $mutate($path);
    if ($path === 'clients/services') return array_slice($collection,$parameters['offset'],$parameters['limit']);
    if (!array_key_exists($path,$responses)) throw new RuntimeException('Missing synthetic response');
    if ($responses[$path] instanceof Throwable) throw $responses[$path];
    return $responses[$path];
};
$factory = static function (array $fixture, ?callable $backup = null) use ($request,&$time): ApiNetworkRepair {
    return new ApiNetworkRepair($request,$fixture['data'],$fixture['jobs'],$fixture['legacy'],static function () use (&$time): float { return $time; },$backup,$fixture['cache']);
};
$choose11 = [['serviceId'=>11,'fields'=>['address']]];
$choose12 = [['serviceId'=>12,'fields'=>['address']]];
$choose14 = [['serviceId'=>14,'fields'=>['address6']]];
try {
    $reset(); $fixture = repairFixture($root,'baseline'); $api = $factory($fixture);
    $before = repairRows($fixture['data']); $jobsHash = hash_file('sha256',$fixture['jobs']); $cacheHash = hash_file('sha256',$fixture['cache']);
    $list = $api->listing();
    repairCheck($list['total'] === 3 && !$list['truncated'] && array_column($list['rows'],'service_id') === [11,12,14] && $calls === [],
        'Local inventory contacted CRM or selected valid occupied CIDR');
    repairJob($fixture,11,['id'=>11,'clientId'=>111,'address'=>'192.0.2.11','_prior_network'=>['address'=>'PRIVATE-INVALID-ONE']]);
    repairJob($fixture,12,['id'=>12,'clientId'=>112,'_prior_network'=>['address'=>'PRIVATE-INVALID-TWO']]);
    $jobsHash = hash_file('sha256',$fixture['jobs']);
    $preview = $api->preview($choose11);
    repairCheck(count($preview['candidates']) === 1 && $preview['candidates'][0]['pending_jobs'] === 1
        && $preview['candidates'][0]['fields'][0]['current_address'] === '192.0.2.11'
        && count($preview['candidates'][0]['fields'][0]['saved']) === 2, 'Safe unprepared pending update was blocked or comparison incomplete');
    repairCheck(repairRows($fixture['data']) === $before && hash_file('sha256',$fixture['jobs']) === $jobsHash, 'Preview mutated reservations or jobs');
    $applied = $api->apply($preview['previewToken']);
    $after = repairRows($fixture['data']); $expected = $before;
    $expected['network'][0]['address'] = '192.0.2.11'; $expected['services'][0]['address'] = '192.0.2.11';
    repairCheck($after === $expected && hash_file('sha256',$fixture['jobs']) === $jobsHash && hash_file('sha256',$fixture['cache']) === $cacheHash
        && $applied['jobs_preserved'] && !$applied['requires_sync'] && $applied['requires_reload'], 'Repair changed another family/status/plan/cache/queue');
    repairCheck(ApiNetworkCleanup::backupName($applied['backup_name']) && repairRows($fixture['directory'].'/'.$applied['backup_name']) === $before,
        'Private coherent pre-change backup missing or incompatible');
    repairReject(fn()=>$api->apply($preview['previewToken']),'invalid_repair_token');
    $second = $api->preview($choose12); $secondResult = $api->apply($second['previewToken']);
    repairCheck($secondResult['repaired'][0]['fields'][0]['action'] === 'clear' && repairRows($fixture['data'])['services'][1]['status'] === 3
        && hash_file('sha256',$fixture['jobs']) === $jobsHash, 'Explicit no-IP repair freed service identity or modified waiting jobs');
    $ended = $api->preview($choose14); $api->apply($ended['previewToken']);
    repairCheck(repairRows($fixture['data'])['services'][3]['status'] === 2 && repairRows($fixture['data'])['network'][3]['address6'] === '2001:db8:14::/64',
        'Ended-service malformed repair reactivated/deleted service or lost IPv6 identity');
    repairBlocked($api,$choose11,'no_invalid_reservation');

    // Failed initial provisioning may persist the reservation before saving
    // the service row. Fresh exact UISP identity can repair only that malformed
    // field; it must never create a service or alter a conflicting owner.
    foreach (['missing-service','null-local-client','cached-client'] as $identityCase) {
        $reset(); $case = repairFixture($root,$identityCase); $caseApi = $factory($case);
        if ($identityCase === 'missing-service') repairSql($case['data'],'DELETE FROM services WHERE id=11');
        else repairSql($case['data'],'UPDATE services SET clientId=NULL WHERE id=11');
        if ($identityCase === 'cached-client') repairSql($case['cache'],'INSERT INTO services VALUES(11,111)');
        $beforeIdentity = repairRows($case['data']);
        $identityPreview = $caseApi->preview($choose11);
        repairCheck(count($identityPreview['candidates']) === 1 && $identityPreview['candidates'][0]['client_id'] === 111
            && $identityPreview['candidates'][0]['identity_source'] === ($identityCase === 'cached-client' ? 'cache' : 'uisp'),
            'Missing local identity stranded a freshly verified exact UISP service');
        $caseApi->apply($identityPreview['previewToken']);
        $identityAfter = repairRows($case['data']);
        repairCheck($identityAfter['network'][0]['address'] === '192.0.2.11'
            && count($identityAfter['services']) === count($beforeIdentity['services']), 'Identity recovery created/deleted a service');
        if ($identityCase !== 'missing-service') repairCheck($identityAfter['services'][0]['clientId'] === null,
            'Reservation repair rewrote the service identity');
    }
    $reset(); $case = repairFixture($root,'identity-cache-mismatch');
    repairSql($case['data'],'DELETE FROM services WHERE id=11');
    repairSql($case['cache'],'INSERT INTO services VALUES(11,999)');
    $caseApi=$factory($case); $blockedIdentity=$caseApi->preview($choose11);
    repairCheck(($blockedIdentity['blocked'][0]['reason'] ?? '') === 'cached_identity_changed'
        && $blockedIdentity['blocked'][0]['diagnostics']['local_service_present'] === false
        && $blockedIdentity['blocked'][0]['diagnostics']['cached_client_id'] === 999
        && $blockedIdentity['blocked'][0]['diagnostics']['crm_client_id'] === 111,
        'Identity mismatch was hidden or allowed');
    $reset(); $case = repairFixture($root,'identity-missing-client-get');
    repairSql($case['data'],'DELETE FROM services WHERE id=11'); $responses['clients/111']=['id'=>999];
    repairBlocked($factory($case),$choose11,'identity_unknown');
    $reset(); $case = repairFixture($root,'identity-null-pending-mismatch');
    repairSql($case['data'],'UPDATE services SET clientId=NULL WHERE id=11');
    repairJob($case,11,['id'=>11,'clientId'=>999,'address'=>'192.0.2.11']);
    repairBlocked($factory($case),$choose11,'pending_identity_changed');
    $reset(); $case = repairFixture($root,'identity-local-invalid');
    repairSql($case['data'],"UPDATE services SET clientId='unknown' WHERE id=11");
    repairBlocked($factory($case),$choose11,'local_identity_invalid');
    $reset(); $case = repairFixture($root,'identity-cache-drift');
    repairSql($case['data'],'DELETE FROM services WHERE id=11'); $caseApi=$factory($case);
    $identityPreview=$caseApi->preview($choose11);
    repairSql($case['cache'],'INSERT INTO services VALUES(11,999)');
    repairReject(fn()=>$caseApi->apply($identityPreview['previewToken']),'repair_local_changed');

    // Cancelling a failed, unprepared update must not strand the malformed
    // reservation. Cancelled/superseded rows stay immutable and are not counted
    // as executable jobs; frozen resources or any cancellation debt still block.
    foreach (['cancelled','superseded'] as $terminalState) {
        $reset(); $case=repairFixture($root,'unprepared-' . $terminalState); $caseApi=$factory($case);
        repairJob($case,11,['id'=>11,'clientId'=>111,'address'=>'192.0.2.11',
            '_prior_network'=>['address'=>'PRIVATE-INVALID-ONE']]);
        repairSql($case['jobs'],"UPDATE jobs SET status='" . $terminalState . "'");
        $queueBefore=hash_file('sha256',$case['jobs']); $terminalPreview=$caseApi->preview($choose11);
        repairCheck(count($terminalPreview['candidates'])===1 && $terminalPreview['candidates'][0]['pending_jobs']===0,
            'Safe unprepared ' . $terminalState . ' update was blocked or counted as pending');
        $caseApi->apply($terminalPreview['previewToken']);
        repairCheck(hash_file('sha256',$case['jobs'])===$queueBefore && repairRows($case['data'])['network'][0]['address']==='192.0.2.11',
            'Repair changed canceled history or failed to repair its malformed local reservation');
        foreach (['_commands','_cleanup','_delete_frozen'] as $preparedFlag) {
            $reset(); $blockedCase=repairFixture($root,$terminalState . '-' . $preparedFlag);
            repairJob($blockedCase,11,['id'=>11,'clientId'=>111,'address'=>'192.0.2.11',$preparedFlag=>[]]);
            repairSql($blockedCase['jobs'],"UPDATE jobs SET status='" . $terminalState . "'");
            repairBlocked($factory($blockedCase),$choose11,'queue_recovery');
        }
        $reset(); $blockedCase=repairFixture($root,$terminalState . '-debt');
        repairJob($blockedCase,11,['id'=>11,'clientId'=>111,'address'=>'192.0.2.11']);
        repairSql($blockedCase['jobs'],"UPDATE jobs SET status='" . $terminalState . "'; INSERT INTO cancellation_cleanups VALUES(1,11,'{}')");
        repairBlocked($factory($blockedCase),$choose11,'queue_recovery');
        $reset(); $blockedCase=repairFixture($root,$terminalState . '-identity');
        repairJob($blockedCase,11,['id'=>11,'clientId'=>999,'address'=>'192.0.2.11']);
        repairSql($blockedCase['jobs'],"UPDATE jobs SET status='" . $terminalState . "'");
        repairBlocked($factory($blockedCase),$choose11,'pending_identity_changed');
        $reset(); $blockedCase=repairFixture($root,$terminalState . '-desired');
        repairJob($blockedCase,11,['id'=>11,'clientId'=>111,'address'=>'192.0.2.99']);
        repairSql($blockedCase['jobs'],"UPDATE jobs SET status='" . $terminalState . "'");
        repairBlocked($factory($blockedCase),$choose11,'pending_address_changed');
    }

    foreach (['address_in_use','valid_local_reservation','pending_address_changed','pending_identity_changed','queue_recovery','applied_ownership',
        'queue_busy','queue_unknown','crm_address_unknown','identity_changed','crm_ownership_unknown'] as $reason) {
        $reset(); $case = repairFixture($root,$reason); $caseApi = $factory($case); $baseline = repairRows($case['data']);
        switch ($reason) {
            case 'address_in_use': repairSql($case['cache'],"INSERT INTO network VALUES(90,'192.0.2.11',NULL)"); break;
            case 'valid_local_reservation': repairSql($case['data'],"UPDATE services SET address='192.0.2.99' WHERE id=11"); break;
            case 'pending_address_changed': repairJob($case,11,['id'=>11,'clientId'=>111,'address'=>'192.0.2.99']); break;
            case 'pending_identity_changed': repairJob($case,11,['id'=>11,'clientId'=>999]); break;
            case 'queue_recovery': repairJob($case,11,['id'=>11,'clientId'=>111,'_commands'=>[]]); break;
            case 'applied_ownership': repairSql($case['jobs'],"INSERT INTO service_versions VALUES(11,'{}','fixture-identity')"); break;
            case 'queue_busy': repairSql($case['jobs'],"INSERT INTO service_event_locks VALUES(90)"); break;
            case 'queue_unknown': repairSql($case['jobs'],"INSERT INTO jobs VALUES(1,90,NULL,'update','{}',0,0)"); break;
            case 'crm_address_unknown': unset($responses['clients/services/11']['attributes']); break;
            case 'identity_changed': $responses['clients/services/11']['clientId'] = 999; break;
            case 'crm_ownership_unknown': unset($collection[1]['attributes']); break;
        }
        $baseline = repairRows($case['data']); $hash = hash_file('sha256',$case['jobs']);
        repairBlocked($caseApi,$choose11,$reason);
        repairCheck(repairRows($case['data']) === $baseline && hash_file('sha256',$case['jobs']) === $hash, 'Blocked repair changed operational state');
    }
    $reset(); $case = repairFixture($root,'crm-collision'); $collection[] = $service(91,'192.0.2.11',null);
    repairBlocked($factory($case),$choose11,'address_in_use');
    $reset(); $case = repairFixture($root,'reviewed-ended-owner'); $peer=$service(91,'192.0.2.11',null,2); $collection[]=$peer;
    $caseApi=$factory($case); repairBlocked($caseApi,$choose11,'address_in_use');
    $descriptor=['key'=>'fixture_ip','id'=>1];
    $marker=ApiCrmNetworkHistory::markerPath(91,'address',$case['directory']);
    file_put_contents($marker,json_encode(['service_id'=>91,'client_id'=>191,'status'=>2,'field'=>'address',
        'attribute'=>$descriptor,'value'=>'192.0.2.11','fingerprint'=>ApiCrmNetworkHistory::fingerprint($peer,$descriptor,'address'),
        'created_at'=>(int)$time],JSON_THROW_ON_ERROR)); chmod($marker,0600);
    $historyPreview=$caseApi->preview($choose11);
    repairCheck(count($historyPreview['candidates'])===1,'Exact reviewed ended history blocked reuse of a released IP');
    $collection[3]['status']=1;
    repairBlocked($caseApi,$choose11,'address_in_use');
    repairReject(fn()=>$caseApi->apply($historyPreview['previewToken']),'repair_verification_failed');
    $collection[3]=$peer; $collection[3]['clientId']=199;
    repairBlocked($caseApi,$choose11,'address_in_use');
    $collection[3]=$peer; $collection[3]['attributes'][0]['value']='192.0.2.11/32';
    repairBlocked($caseApi,$choose11,'address_in_use');
    $collection[3]=$peer; $responses['custom-attributes'][0]['id']=3;
    foreach($collection as &$entry) $entry['attributes'][0]['customAttributeId']=3; unset($entry);
    $responses['clients/services/11']['attributes'][0]['customAttributeId']=3;
    repairBlocked($caseApi,$choose11,'address_in_use');
    $responses['custom-attributes'][0]['id']=1;
    foreach($collection as &$entry) $entry['attributes'][0]['customAttributeId']=1; unset($entry);
    $responses['clients/services/11']['attributes'][0]['customAttributeId']=1;
    $historyApply=$caseApi->apply($historyPreview['previewToken']);
    repairCheck($historyApply['repaired'][0]['fields'][0]['current_address']==='192.0.2.11' && $collection[3]===$peer,
        'Reviewed history reuse modified the ended CRM service');
    $reset(); $case = repairFixture($root,'ipv6-alias-collision'); $collection[] = $service(91,null,'2001:0db8:0014:0000::/63');
    repairBlocked($factory($case),$choose14,'address_in_use');
    $reset(); $case = repairFixture($root,'cached-ipv6-default-length');
    repairSql($case['data'],'ALTER TABLE devices ADD COLUMN pfxLength INTEGER; UPDATE devices SET pfxLength=64 WHERE id=1');
    repairSql($case['cache'],"ALTER TABLE services ADD COLUMN device INTEGER; INSERT INTO services VALUES(91,191,1);
        INSERT INTO network VALUES(91,NULL,'2001:db8:14::1')");
    $responses['clients/services/14']=$service(14,null,'2001:db8:14::2/128',2); $collection[2]=$responses['clients/services/14'];
    repairBlocked($factory($case),$choose14,'address_in_use');
    $reset(); $case = repairFixture($root,'durable-collision');
    repairSql($case['jobs'],"INSERT INTO service_versions VALUES(91,'{\"address\":\"192.0.2.11\"}','fixture')");
    repairBlocked($factory($case),$choose11,'address_in_use');
    $reset(); $case = repairFixture($root,'debt-prior-collision');
    repairSql($case['jobs'],"INSERT INTO cancellation_cleanups VALUES(1,91,'{\"_prior_network\":{\"address\":\"192.0.2.0/24\"}}')");
    repairBlocked($factory($case),$choose11,'address_in_use');
    $reset(); $case = repairFixture($root,'identity-without-address');
    repairSql($case['jobs'],"INSERT INTO service_versions VALUES(91,NULL,'fixture')");
    repairBlocked($factory($case),$choose11,'applied_address_unknown');
    $reset(); $case = repairFixture($root,'invalid-queue-flag');
    repairSql($case['jobs'],"INSERT INTO jobs VALUES(1,91,'pending','update','{}','unknown',0)");
    repairBlocked($factory($case),$choose11,'queue_unknown');
    $reset(); $case = repairFixture($root,'complete-collection-limit');
    $collection=[];
    for ($id=1; $id<=10000; $id++) $collection[]=$service($id,null,null);
    repairBlocked($factory($case),$choose11,'crm_ownership_limit');
    $reset(); $case = repairFixture($root,'deadline');
    $mutate=static function (string $path) use (&$time): void { if ($path==='clients/services/11') $time+=13; };
    repairBlocked($factory($case),$choose11,'deadline');
    $reset(); $case = repairFixture($root,'missing'); $responses['clients/services/11'] = new GuzzleHttp\Exception\RequestException('fixture',
        new GuzzleHttp\Psr7\Request('GET','https://fixture.invalid'),new GuzzleHttp\Psr7\Response(404));
    $caseApi = $factory($case); $preview = $caseApi->preview($choose11);
    repairCheck($preview['candidates'][0]['fields'][0]['reason'] === 'crm_not_found' && count($calls) === 1,'Only exact404 should authorize malformed-only absence repair');
    $caseApi->apply($preview['previewToken']); repairCheck(repairRows($case['data'])['services'][0]['status'] === 1,'404 repair changed service');
    $reset(); $case = repairFixture($root,'forbidden'); $responses['clients/services/11'] = new GuzzleHttp\Exception\RequestException('fixture',
        new GuzzleHttp\Psr7\Request('GET','https://fixture.invalid'),new GuzzleHttp\Psr7\Response(403));
    repairBlocked($factory($case),$choose11,'crm_unavailable');
    $reset(); $case = repairFixture($root,'changed-local'); $caseApi = $factory($case); $preview = $caseApi->preview($choose11);
    repairSql($case['data'],"UPDATE services SET status=3 WHERE id=11");
    repairReject(fn()=>$caseApi->apply($preview['previewToken']),'repair_local_changed');
    $reset(); $case = repairFixture($root,'changed-crm'); $caseApi = $factory($case); $preview = $caseApi->preview($choose11);
    $responses['clients/services/11'] = $service(11,'192.0.2.19','2001:db8:11::/64'); $collection[0] = $responses['clients/services/11'];
    repairReject(fn()=>$caseApi->apply($preview['previewToken']),'repair_verification_failed');
    $reset(); $case = repairFixture($root,'changed-config'); $caseApi = $factory($case); $preview = $caseApi->preview($choose11);
    repairSql($case['data'],"UPDATE config SET value='other_attribute' WHERE key='ip_addr_attr'");
    repairReject(fn()=>$caseApi->apply($preview['previewToken']),'repair_local_changed');
    $reset(); $case = repairFixture($root,'expire'); $caseApi = $factory($case); $preview = $caseApi->preview($choose11); $time += 601;
    repairReject(fn()=>$caseApi->apply($preview['previewToken']),'repair_preview_expired');
    $reset(); $case = repairFixture($root,'backup-failure'); $caseApi = $factory($case,static fn()=>false); $preview = $caseApi->preview($choose11); $before = repairRows($case['data']);
    repairReject(fn()=>$caseApi->apply($preview['previewToken']),'repair_backup_failed'); repairCheck(repairRows($case['data']) === $before,'Failed backup permitted mutation');
    $reset(); $case = repairFixture($root,'atomic-failure');
    repairSql($case['data'],"ALTER TABLE network ADD COLUMN protected TEXT CHECK(address<>'192.0.2.11')");
    $caseApi = $factory($case); $preview = $caseApi->preview([['serviceId'=>12,'fields'=>['address']],['serviceId'=>11,'fields'=>['address']]]); $before = repairRows($case['data']);
    repairReject(fn()=>$caseApi->apply($preview['previewToken']),'repair_failed'); repairCheck(repairRows($case['data']) === $before,'Multi-selection repair did not roll back atomically');
    $reset(); $case = repairFixture($root,'during-read-race'); $caseApi = $factory($case); $preview = $caseApi->preview($choose11);
    $changed = false;
    $mutate = static function (string $path) use (&$changed,$case): void {
        if ($path === 'clients/services' && !$changed) { $changed = true; repairSql($case['data'],"UPDATE network SET address='192.0.2.99' WHERE id=11"); }
    };
    repairReject(fn()=>$caseApi->apply($preview['previewToken']),'repair_local_changed');
    $reset(); $case = repairFixture($root,'list-limit');
    for ($id = 100; $id < 155; $id++) repairSql($case['data'],"INSERT INTO network VALUES($id,'invalid','invalid6','fixture')");
    $list = $factory($case)->listing(); repairCheck($list['total'] === 58 && count($list['rows']) === 50 && $list['truncated'],'Local listing truncation counted family duplicates');
    $reset(); $case = repairFixture($root,'lock'); $caseApi = $factory($case); $preview = $caseApi->preview($choose11);
    $lock = fopen($case['directory'].'/ip-sync.lock','c'); flock($lock,LOCK_EX);
    repairReject(fn()=>$caseApi->apply($preview['previewToken']),'repair_busy'); flock($lock,LOCK_UN); fclose($lock);
    $reset(); $case = repairFixture($root,'schema'); repairSql($case['data'],"CREATE TRIGGER hidden_change AFTER UPDATE ON network BEGIN UPDATE plans SET downloadSpeed=1; END");
    repairReject(fn()=>$factory($case)->listing(),'repair_schema_unsupported');
    repairReject(fn()=>$factory($case)->preview([['serviceId'=>11,'fields'=>[new stdClass()]]]),'invalid_repair_selection');
    echo "Network repair passed: bounded local listing, fresh canonical ownership, pending update isolation, exact-family correction/clear, coherent private backup, no operational side effects, guarded races and atomic rollback.\n";
} finally {
    $resolved = realpath($root); $temporary = realpath(sys_get_temp_dir());
    if ($resolved && $temporary && strpos($resolved,$temporary . DIRECTORY_SEPARATOR . 'uisp-network-repair-') === 0) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); rmdir($resolved);
    }
}
