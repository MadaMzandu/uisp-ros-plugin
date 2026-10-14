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
        'cache_hash'=>is_file('data/cache.db') ? hash_file('sha256','data/cache.db') : null,
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
function recoveryDiskSnapshot(string $dataDirectory = 'data'): array {
    $snapshot = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dataDirectory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $file) {
        // Authentication settings and the private request log are intentionally changed by these fixtures.
        if (in_array($file->getFilename(), ['config.json','plugin.log'], true)) continue;
        $snapshot[$file->getPathname()] = $file->isDir() ? 'directory' : hash_file('sha256',$file->getPathname());
    }
    ksort($snapshot);
    return $snapshot;
}
function publicMigrationCleanupChecks(string $directory, callable $request): void {
    // Keep the original recovery fixture intact for the remaining setup/restore checks.
    $isolated = $directory . '/cleanup-recovery';
    mkdir($isolated,0700);
    foreach (['lib','includes','includes/l10n','data','vendor','public','public/panel_v2'] as $folder) {
        mkdir($isolated . '/' . $folder,0700);
    }
    foreach (glob($directory . '/lib/*.php') as $file) copy($file,$isolated . '/lib/' . basename($file));
    foreach (glob($directory . '/includes/*') as $file) if (is_file($file)) copy($file,$isolated . '/includes/' . basename($file));
    foreach (glob($directory . '/includes/l10n/*.json') as $file) copy($file,$isolated . '/includes/l10n/' . basename($file));
    foreach (['public.php','manifest.json','vendor/autoload.php','public/panel_v2/index.php'] as $file) {
        copy($directory . '/' . $file,$isolated . '/' . $file);
    }
    checkMigrationConflict(mySqlite()->backupTo($isolated . '/data/data.db'), 'Could not isolate cleanup HTTP database');
    foreach (['state.json','queue.json','backup-0'] as $file) copy($directory . '/data/' . $file,$isolated . '/data/' . $file);
    $mode = ['X-Fixture-Mode'=>'cleanup-recovery'];
    $admin = $mode + ['X-Fixture-Admin'=>'edit'];
    $reader = $mode + ['X-Fixture-Admin'=>'view'];
    $database = new ApiSqlite($isolated . '/data/data.db');
    try {
        $stored = migrationDatabaseSnapshot($database);
        $storedBytes = hash_file('sha256',$isolated . '/data/data.db');
        $operational = static function () use ($isolated): array {
            $result = [];
            foreach (['data.db','state.json','queue.json','backup-0','jobs.db','cache.db'] as $name) {
                $path = $isolated . '/data/' . $name;
                $result[$name] = is_file($path) ? hash_file('sha256',$path) : null;
            }
            return $result;
        };
        $before = $operational();
        $preview = ['changeType'=>'cleanup','target'=>'network_conflicts','action'=>'preview','data'=>['serviceIds'=>[8]]];
        $apply = ['changeType'=>'cleanup','target'=>'network_conflicts','action'=>'apply',
            'data'=>['previewToken'=>str_repeat('a',64),'serviceIds'=>[8]]];
        foreach ([$preview,$apply] as $payload) {
            foreach ([$mode,$reader] as $headers) {
                $rejected = $request($payload,$headers);
                checkMigrationConflict($rejected['http']===403 && ($rejected['response']['message'] ?? null)==='unauthorized'
                    && ($rejected['response']['data'] ?? null)===[], 'Cleanup bypassed full edit permission');
            }
            $originRejected = $request($payload,$admin + ['Origin'=>'https://untrusted-fixture.example']);
            checkMigrationConflict($originRejected['http']===403
                && ($originRejected['response']['message'] ?? null)==='invalid_origin', 'Cleanup bypassed same-origin protection');
        }
        checkMigrationConflict(!is_file($isolated . '/sdk-reads.jsonl') && $operational()===$before,
            'Rejected cleanup called CRM or changed a persistent operational file');
        foreach ([['changeType'=>'cleanup','target'=>'network_conflicts','action'=>'unexpected','data'=>['serviceIds'=>[8]]],
            ['changeType'=>'cleanup','target'=>'network_conflicts','action'=>'preview','data'=>['serviceIds'=>'8']],
            ['changeType'=>'cleanup','target'=>'network_conflicts','action'=>'preview','data'=>['serviceIds'=>[8,7,21,22]]],
            ['changeType'=>'list','target'=>'network_conflicts','action'=>'apply','data'=>['serviceIds'=>[8]]],
            ['changeType'=>'cleanup','target'=>'network_conflicts','action'=>'apply','data'=>['serviceIds'=>[8]]]] as $invalid) {
            $rejected = $request($invalid,$admin);
            checkMigrationConflict($rejected['http']===400 && ($rejected['response']['error'] ?? null)===true,
                'Cleanup accepted an invalid action, selection or unconfirmed application');
            checkMigrationConflict($operational()===$before, 'Invalid cleanup modified operational state');
        }

        // An accepted billing webhook still cannot turn its secret into an administrator cleanup grant.
        file_put_contents($isolated . '/data/config.json','{"webhookAllowWithoutSecret":true}');
        $event = ['entity'=>'service','entityId'=>8,'changeType'=>'edit','uuid'=>'CLEANUP-HTTP-WEBHOOK-FIXTURE-0001',
            'target'=>'network_conflicts','action'=>'apply','data'=>['previewToken'=>str_repeat('a',64),'serviceIds'=>[8]]];
        $webhook = $request($event,$mode);
        checkMigrationConflict($webhook['http']===400
            && ($webhook['response']['data'] ?? null)===['duplicate_groups'=>2]
            && strpos($webhook['body'],'203.0.113.7')===false && strpos($webhook['body'],'candidates')===false,
            'Webhook metadata bypassed cleanup routing or disclosed private reservations');
        checkMigrationConflict($operational()===$before, 'Webhook cleanup attempt changed operational state');

        $result = $request($preview,$admin);
        $details = $result['response']['data'] ?? [];
        checkMigrationConflict($result['http']===200 && ($result['response']['message'] ?? null)==='network_cleanup_preview'
            && array_column($details['candidates'] ?? [],'service_id')===[8]
            && preg_match('/^[a-f0-9]{64}$/D',$details['previewToken'] ?? '')===1,
            'Authorized cleanup preview failed to identify the confirmed absent reservation: ' . $result['body']);
        checkMigrationConflict($operational()===$before && migrationDatabaseSnapshot($database)===$stored
            && hash_file('sha256',$isolated . '/data/data.db')===$storedBytes,
            'Cleanup preview migrated, changed assignments, initialized jobs/cache or rotated a backup');
        $livePreview = $request(['changeType'=>'cleanup','target'=>'network_conflicts','action'=>'preview','data'=>['serviceIds'=>[7]]],$admin);
        checkMigrationConflict($livePreview['http']===200 && ($livePreview['response']['data']['candidates'] ?? null)===[]
            && array_column($livePreview['response']['data']['blocked'] ?? [],'service_id')===[7]
            && $operational()===$before, 'Cleanup offered a currently existing ended service for deletion');

        $token = $details['previewToken'];
        $forged = $request(['changeType'=>'cleanup','target'=>'network_conflicts','action'=>'apply',
            'data'=>['previewToken'=>$token,'serviceIds'=>[7]]],$admin);
        checkMigrationConflict($forged['http']===400 && ($forged['response']['error'] ?? null)===true && $operational()===$before,
            'A preview token could be changed to remove a current service reservation');
        $applied = $request(['changeType'=>'cleanup','target'=>'network_conflicts','action'=>'apply',
            'data'=>['previewToken'=>$token,'serviceIds'=>[8]]],$admin);
        $appliedData = $applied['response']['data'] ?? [];
        checkMigrationConflict($applied['http']===200 && ($applied['response']['message'] ?? null)==='network_cleanup_applied'
            && ($appliedData['removed_service_ids'] ?? null)===[8] && ($appliedData['duplicate_groups'] ?? null)===1
            && ($appliedData['requires_reload'] ?? null)===true, 'Cleanup application did not return its exact result: ' . $applied['body']);
        $after = migrationDatabaseSnapshot($database);
        $expected = $stored;
        $expected['data']['network'] = array_values(array_filter($expected['data']['network'],static fn($row)=>(int)$row['id']!==8));
        checkMigrationConflict($after===$expected, 'HTTP cleanup altered anything beyond the selected obsolete network reservation');
        $afterFiles = $operational();
        foreach (['state.json','queue.json','backup-0','jobs.db','cache.db'] as $name) {
            checkMigrationConflict($afterFiles[$name]===$before[$name], 'HTTP cleanup changed unrelated operational file: ' . $name);
        }
        $backupName = $appliedData['backup_name'] ?? '';
        checkMigrationConflict(is_string($backupName) && preg_match('/^network-cleanup-\d{8}T\d{6}Z-[a-f0-9]{32}\.sqlite$/D',$backupName)===1,
            'Cleanup did not return an allowlisted private recovery backup name');
        foreach ([$mode,$reader] as $headers) {
            $download = $request([],$headers,'GET','?download=' . rawurlencode($backupName));
            checkMigrationConflict($download['http']===403 && ($download['response']['data'] ?? null)===[],
                'Cleanup recovery backup bypassed administrator edit permission');
        }
        $download = $request([],$admin,'GET','?download=' . rawurlencode($backupName));
        checkMigrationConflict($download['http']===200 && hash('sha256',$download['body'])===$storedBytes,
            'Cleanup backup download was unavailable during recovery or differed from the pre-cleanup SQLite snapshot');
        $privateBackupPath = null;
        $backups = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($isolated . '/data',FilesystemIterator::SKIP_DOTS));
        foreach ($backups as $file) if ($file->getFilename()===$backupName) $privateBackupPath = $file->getPathname();
        checkMigrationConflict(is_string($privateBackupPath) && is_file($privateBackupPath), 'Cleanup backup was not stored privately under data');
        $invalidSchemaName = 'network-cleanup-20261014T000000Z-' . str_repeat('c',32) . '.sqlite';
        $invalidSchema = new SQLite3(dirname($privateBackupPath) . '/' . $invalidSchemaName);
        $invalidSchema->exec('CREATE TABLE unrelated(value TEXT)');
        $invalidSchema->close();
        $badSchema = $request([],$admin,'GET','?download=' . rawurlencode($invalidSchemaName));
        checkMigrationConflict($badSchema['http']===400 && ($badSchema['response']['error'] ?? null)===true,
            'Cleanup backup download accepted an unrelated SQLite database');
        $linkName = 'network-cleanup-20261014T000000Z-' . str_repeat('d',32) . '.sqlite';
        $linkPath = dirname($privateBackupPath) . '/' . $linkName;
        // Windows installations without symlink privilege still run every other path check.
        if (@symlink($privateBackupPath,$linkPath)) {
            $linked = $request([],$admin,'GET','?download=' . rawurlencode($linkName));
            checkMigrationConflict($linked['http']===400 && ($linked['response']['error'] ?? null)===true,
                'Cleanup backup download followed a symlink');
            unlink($linkPath);
        }
        $beforeDownload = $operational();
        $listedBackups = $request(['changeType'=>'list','target'=>'backups','action'=>'list','data'=>new stdClass()],$reader);
        $listedNames = array_column($listedBackups['response']['data'] ?? [],'name');
        checkMigrationConflict($listedBackups['http']===200 && in_array($backupName,$listedNames,true)
            && !in_array($invalidSchemaName,$listedNames,true) && $operational()===$beforeDownload,
            'Read-only recovery backup list omitted the cleanup snapshot, included a foreign schema or changed stored data');
        foreach (['../' . $backupName,'network-cleanup-..sqlite','network-cleanup-20261014T000000Z-' . str_repeat('b',32) . '.sqlite'] as $invalidName) {
            $download = $request([],$admin,'GET','?download=' . rawurlencode($invalidName));
            checkMigrationConflict($download['http']===400 && ($download['response']['error'] ?? null)===true,
                'Cleanup backup download accepted traversal, malformed or missing files');
        }
        checkMigrationConflict($operational()===$beforeDownload, 'Backup access initialized jobs/cache or changed an operational file');
        $replay = $request(['changeType'=>'cleanup','target'=>'network_conflicts','action'=>'apply',
            'data'=>['previewToken'=>$token,'serviceIds'=>[8]]],$admin);
        checkMigrationConflict($replay['http']===400 && ($replay['response']['error'] ?? null)===true
            && $operational()===$beforeDownload, 'Cleanup application replay changed stored data');
    } finally { $database->close(); }
}
function publicMigrationWebhookChecks(string $directory): void {
    $process = null;
    $priorConfig = is_file('data/config.json') ? file_get_contents('data/config.json') : null;
    file_put_contents($directory . '/webhook-router.php', <<<'PHP'
<?php
set_include_path(__DIR__ . '/lib' . PATH_SEPARATOR . get_include_path());
// This disposable loopback server alone owns this synthetic authentication header.
$fixturePermission = $_SERVER['HTTP_X_FIXTURE_ADMIN'] ?? null;
if (in_array($fixturePermission, ['edit','view'], true)) {
    $GLOBALS['testUser'] = (object)['isClient'=>false,'permissions'=>['system/plugins'=>$fixturePermission]];
}
if (($_SERVER['HTTP_X_FIXTURE_MODE'] ?? '')==='restore-after-setup') {
    set_include_path(__DIR__ . '/clean-restore/lib' . PATH_SEPARATOR . get_include_path());
    require __DIR__ . '/clean-restore/public.php';
    exit;
}
if (($_SERVER['HTTP_X_FIXTURE_MODE'] ?? '')==='cleanup-recovery') {
    set_include_path(__DIR__ . '/cleanup-recovery/lib' . PATH_SEPARATOR . get_include_path());
    require __DIR__ . '/cleanup-recovery/public.php';
    exit;
}
require __DIR__ . '/public.php';
PHP
    );
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
        $request = static function ($event, array $headers = [], string $method = 'POST', string $query = '',
            string $contentType = 'application/json') use ($address): array {
            $headerValues = ['Content-Type'=>$contentType,'Origin'=>'http://' . $address];
            foreach ($headers as $name=>$value) $headerValues[$name] = $value;
            $httpHeaders = [];
            foreach ($headerValues as $name=>$value) $httpHeaders[] = $name . ': ' . $value;
            $payload = $method==='GET' ? '' : (is_string($event) ? $event : json_encode($event,JSON_THROW_ON_ERROR));
            $context = stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$httpHeaders),
                'content'=>$payload,'ignore_errors'=>true,'timeout'=>3]]);
            $body = file_get_contents('http://' . $address . '/public.php' . $query, false, $context);
            $status = (int)(preg_match('/^HTTP\/\S+ (\d+)/',$http_response_header[0] ?? '',$match) ? $match[1] : 0);
            $decoded = strpos(ltrim($body),'{')===0 ? json_decode($body,true,512,JSON_THROW_ON_ERROR) : null;
            return ['http'=>$status,'body'=>$body,'response'=>$decoded];
        };
        $admin = ['X-Fixture-Admin'=>'edit'];
        $reader = ['X-Fixture-Admin'=>'view'];
        $before = recoveryDiskSnapshot();
        checkMigrationConflict(!is_file('data/jobs.db') && !is_file('data/cache.db'),
            'HTTP recovery no-initialization fixtures were not isolated before queue/cache creation');
        foreach (['devices','plans','config','jobs','messages','lang','sys','backups','services'] as $target) {
            $data = $target==='services' ? ['did'=>1] : ($target==='messages' ? ['language'=>'es'] : new stdClass());
            $response = $request(['changeType'=>'list','target'=>$target,'action'=>'list','data'=>$data],$reader);
            checkMigrationConflict($response['http']===200 && ($response['response']['error'] ?? null)===false
                && ($response['response']['message'] ?? null)==='recovery_read_only'
                && ($response['response']['recovery']['readOnly'] ?? null)===true
                && array_key_exists('data',$response['response']), 'Recovery list failed for ' . $target . ': ' . $response['body']);
            $listed = $response['response']['data'];
            if ($target==='devices') checkMigrationConflict(count($listed)===1 && $listed[0]['id']===1
                && !array_key_exists('password',$listed[0]), 'Recovery devices lost stored rows or exposed a password');
            if ($target==='plans') checkMigrationConflict(count($listed)===1 && $listed[0]['uploadOverride']===2.5,
                'Recovery plan list changed its saved decimal speed');
            if ($target==='config') checkMigrationConflict(($listed['active_list'] ?? null)==='00123'
                && ($listed['version'] ?? null)==='2.3.1', 'Recovery configuration altered text identifiers or version');
            if ($target==='jobs') checkMigrationConflict(count($listed)===1 && ($listed['L1']['serviceId'] ?? null)===90,
                'Recovery jobs could not read the original legacy queue without importing it');
            if ($target==='messages' || $target==='lang') checkMigrationConflict(count($listed)===2,
                'Recovery localization omitted its message or field dictionary');
            if ($target==='sys') checkMigrationConflict(($listed['app_version'] ?? null)===MY_VERSION,
                'Recovery system list omitted the executing version');
            if ($target==='backups') checkMigrationConflict(count($listed)===7, 'Recovery backup list omitted stored recovery snapshots');
            if ($target==='services') checkMigrationConflict(($listed['count'] ?? null)===2
                && array_column($listed['data'],'id')===[8,22], 'Recovery services list lost active services or included terminal ones');
            checkMigrationConflict(recoveryDiskSnapshot()===$before,
                'Recovery list initialized/imported jobs or cache, migrated, or changed stored data: ' . $target);
        }
        $systemGet = $request(['changeType'=>'list','target'=>'sys','action'=>'get'],$reader);
        checkMigrationConflict($systemGet['http']===200 && ($systemGet['response']['error'] ?? null)===false,
            'Read-only system GET action became unavailable during recovery');
        $localDetails = $request(['changeType'=>'list','target'=>'network_conflicts','data'=>new stdClass()],$reader);
        checkMigrationConflict($localDetails['http']===200 && ($localDetails['response']['message'] ?? null)==='network_diagnostics'
            && ($localDetails['response']['data']['duplicate_groups'] ?? null)===2
            && ($localDetails['response']['recovery']['readOnly'] ?? null)===true,
            'Administrative local conflict refresh did not return diagnostics');
        checkMigrationConflict(!is_file($directory . '/sdk-reads.jsonl') && recoveryDiskSnapshot()===$before,
            'Default recovery reads called UISP or changed stored state');

        // The CRM monitor is an independent administrative snapshot, even when
        // legacy reservations keep the rest of the plugin in recovery mode.
        $monitor = $request(['changeType'=>'list','target'=>'crm_network_conflicts','action'=>'list','data'=>new stdClass()],$reader);
        checkMigrationConflict($monitor['http']===200 && ($monitor['response']['error'] ?? true)===false
            && ($monitor['response']['data']['warning_only'] ?? false)===true
            && ($monitor['response']['data']['available'] ?? true)===false
            && !isset($monitor['response']['recovery'])
            && !is_file($directory . '/data/crm-network-monitor.db')
            && !is_file($directory . '/data/crm-network-monitor.db.lock')
            && !is_file($directory . '/sdk-reads.jsonl') && recoveryDiskSnapshot()===$before,
            'Polling CRM warnings read the API, created state, or activated a migration lock');
        $invalidMonitor = $request(['changeType'=>'list','target'=>'crm_network_conflicts','action'=>'list','data'=>['refresh'=>true]],$reader);
        checkMigrationConflict($invalidMonitor['http']===400
            && ($invalidMonitor['response']['message'] ?? null)==='invalid_crm_monitor_request'
            && recoveryDiskSnapshot()===$before, 'A monitor poll accepted a request to trigger CRM reads');

        // Correction endpoints keep their edit/origin boundary before recovery
        // setup. Invalid selections and reviews must not initialize state or
        // call CRM merely because a local migration is currently blocked.
        $crmVerify = ['changeType'=>'list','target'=>'crm_network_resolution','action'=>'verify','data'=>['serviceIds'=>[999]]];
        $crmPreview = ['changeType'=>'resolve','target'=>'crm_network_conflicts','action'=>'preview',
            'data'=>['serviceId'=>999,'family'=>'IPv4','operation'=>'remove']];
        $crmApply = ['changeType'=>'resolve','target'=>'crm_network_conflicts','action'=>'apply',
            'data'=>['previewToken'=>str_repeat('a',64)]];
        foreach ([$crmVerify,$crmPreview,$crmApply] as $correction) {
            foreach ([[],['Origin'=>'https://other-fixture.invalid','X-Fixture-Admin'=>'edit']] as $headers) {
                $rejected = $request($correction,$headers);
                checkMigrationConflict($rejected['http']===403 && ($rejected['response']['error'] ?? null)===true
                    && ($rejected['response']['data'] ?? null)===[], 'CRM correction bypassed administrative authentication or origin');
            }
        }
        foreach ([$crmPreview,$crmApply] as $correction) {
            $rejected = $request($correction,$reader);
            checkMigrationConflict($rejected['http']===403 && ($rejected['response']['message'] ?? null)==='unauthorized'
                && ($rejected['response']['data'] ?? null)===[], 'A read-only administrator obtained a CRM mutation review or applied one');
        }
        foreach ([$admin,$reader] as $headers) {
            $unselected = $request($crmVerify,$headers);
            checkMigrationConflict($unselected['http']===400
                && ($unselected['response']['message'] ?? null)==='crm_resolution_service_not_found'
                && !isset($unselected['response']['recovery']), 'Explicit CRM verification used migration setup or allowed an arbitrary service');
        }
        foreach ([[$crmPreview,'crm_resolution_service_not_found'],[$crmApply,'invalid_crm_resolution_token'],
            [['changeType'=>'resolve','target'=>'devices','action'=>'preview','data'=>new stdClass()],'invalid_crm_resolution_target'],
            [['changeType'=>'list','target'=>'crm_network_resolution','action'=>'apply','data'=>['serviceIds'=>[7]]],'invalid_crm_resolution_request'],
            [['changeType'=>'resolve','target'=>'crm_network_conflicts','action'=>'preview',
                'data'=>['serviceId'=>7,'family'=>'IPv4','operation'=>'remove','address'=>'203.0.113.7']],'invalid_crm_resolution_request'],
            [['changeType'=>'resolve','target'=>'crm_network_conflicts','action'=>'preview',
                'data'=>['serviceId'=>7,'family'=>'IPv4','operation'=>'history','address'=>'203.0.113.7']],'invalid_crm_resolution_request'],
            [['changeType'=>'resolve','target'=>'crm_network_conflicts','action'=>'apply',
                'data'=>['previewToken'=>str_repeat('a',64),'serviceId'=>7]],'invalid_crm_resolution_request']] as [$invalid,$message]) {
            $rejected = $request($invalid,$admin);
            checkMigrationConflict($rejected['http']===400 && ($rejected['response']['message'] ?? null)===$message
                && !isset($rejected['response']['recovery']), 'CRM correction accepted malformed or cross-target mutation data');
        }
        checkMigrationConflict(!is_file($directory . '/sdk-reads.jsonl') && recoveryDiskSnapshot()===$before,
            'Rejected CRM correction called UISP, created a review/hold/queue, or changed recovery data');

        // An explicit bounded refresh may consult CRM, retaining all local databases and queue files.
        $refreshed = $request(['changeType'=>'list','target'=>'network_conflicts','data'=>['refresh'=>true,'serviceIds'=>[7,999]]],$reader);
        $ownerGroup = conflictByAddress($refreshed['response']['data'],'IPv4','203.0.113.7');
        $owner = $ownerGroup['owners'][0];
        checkMigrationConflict($refreshed['http']===200 && $owner['service_id']===7 && $owner['client_id']===70
            && $owner['service_status_code']===2 && $owner['service_status']==='Terminado'
            && $owner['client_name']==='Archived fixture client' && $owner['client_archived']===true
            && $owner['source']==='crm' && $owner['client_source']==='crm'
            && $owner['client_deleted']===null && $owner['refresh_error']===null
            && $owner['service_url']==='/crm/client/service/7' && $owner['client_url']==='/crm/client/70',
            'Explicit refresh omitted accurate ended-service/archived-client metadata or usable CRM routes');
        $sdkReads = array_map(static fn($line)=>json_decode($line,true),file($directory . '/sdk-reads.jsonl',FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        checkMigrationConflict($sdkReads===['clients/services/7','clients/70']
            && ($refreshed['response']['data']['owner_refresh']['services_attempted'] ?? null)===1,
            'Owner refresh read beyond selected conflict services or used unexpected CRM paths');
        checkMigrationConflict(recoveryDiskSnapshot()===$before, 'Explicit owner refresh published cache data or changed provisioning state');

        // The client archive check must remain available even when a legacy
        // service no longer has a CRM page. It consults only the saved owner,
        // never the service endpoint, and does not turn the recovery screen
        // into a general-purpose client lookup or initialize setup/cache.
        $clientRequest = ['changeType'=>'list','target'=>'network_conflicts',
            'data'=>['refreshClients'=>true,'serviceIds'=>[7,8,999]]];
        foreach ([[], $reader + ['Origin'=>'https://untrusted-fixture.example']] as $headers) {
            $rejected = $request($clientRequest,$headers);
            checkMigrationConflict($rejected['http']===403 && ($rejected['response']['data'] ?? null)===[],
                'Client-only archive lookup bypassed administrative authentication or origin protection');
            $readsAfterRejection = array_map(static fn($line)=>json_decode($line,true),
                file($directory . '/sdk-reads.jsonl',FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
            checkMigrationConflict($readsAfterRejection===$sdkReads && recoveryDiskSnapshot()===$before,
                'Rejected client-only lookup called CRM or changed recovery state');
        }
        $clientRefreshed = $request($clientRequest,$reader);
        $clientGroup = conflictByAddress($clientRefreshed['response']['data'],'IPv4','203.0.113.7');
        $archivedOwner = $clientGroup['owners'][0];
        $currentOwner = $clientGroup['owners'][1];
        checkMigrationConflict($clientRefreshed['http']===200 && ($clientRefreshed['response']['message'] ?? null)==='network_diagnostics'
            && ($clientRefreshed['response']['recovery']['readOnly'] ?? null)===true
            && $archivedOwner['service_id']===7 && $archivedOwner['client_id']===70
            && $archivedOwner['client_archived']===true && $archivedOwner['client_source']==='crm'
            && $archivedOwner['source']==='database' && $archivedOwner['service_status_code']===2
            && $archivedOwner['refresh_error']===null && $archivedOwner['client_refresh_error']===null
            && is_string($archivedOwner['client_verified_at'])
            && $currentOwner['service_id']===8 && $currentOwner['client_id']===80
            && $currentOwner['client_archived']===false && $currentOwner['client_source']==='crm'
            && $currentOwner['source']==='database' && $currentOwner['service_status_code']===1
            && $currentOwner['refresh_error']===null && $currentOwner['client_refresh_error']===null,
            'Client-only lookup changed local service facts, failed on a missing service or lost confirmed archive state');
        $clientReads = array_map(static fn($line)=>json_decode($line,true),
            file($directory . '/sdk-reads.jsonl',FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        checkMigrationConflict(array_slice($clientReads,count($sdkReads))===['clients/70','clients/80']
            && ($clientRefreshed['response']['data']['owner_refresh']['services_attempted'] ?? null)===0
            && ($clientRefreshed['response']['data']['owner_refresh']['api_requests'] ?? null)===0
            && ($clientRefreshed['response']['data']['client_refresh']['clients_attempted'] ?? null)===2
            && ($clientRefreshed['response']['data']['client_refresh']['api_requests'] ?? null)===2,
            'Client-only archive lookup called a service, unknown client, or broad collection endpoint');
        checkMigrationConflict(recoveryDiskSnapshot()===$before,
            'Client-only lookup initialized setup/cache/queue, rotated backups or wrote provisioning state');

        foreach ([['changeType'=>'list','target'=>'unknown'],['changeType'=>'list','target'=>'devices','action'=>'edit'],
            ['changeType'=>'list','target'=>'devices','data'=>[]],['changeType'=>'list','target'=>'devices','entity'=>''],
            ['changeType'=>'list','target'=>'services','data'=>['did'=>'1 OR 1=1']],
            ['changeType'=>'list','target'=>'network_conflicts','action'=>'edit'],
            ['changeType'=>'list','target'=>'network_conflicts','data'=>['serviceIds'=>'7']],
            ['changeType'=>'list','target'=>'network_conflicts','data'=>['refresh'=>true,'refreshClients'=>true,'serviceIds'=>[7]]],
            ['changeType'=>'list','target'=>'network_conflicts','data'=>['refreshClients'=>'true','serviceIds'=>[7]]],
            '{"malformed":'] as $invalid) {
            $response = $request($invalid,$admin);
            checkMigrationConflict($response['http']===400 && ($response['response']['error'] ?? null)===true,
                'Recovery accepted malformed, unallowed or write-like list request');
            checkMigrationConflict(recoveryDiskSnapshot()===$before, 'Invalid recovery request changed stored data');
        }
        foreach ([['changeType'=>'update','target'=>'devices','action'=>'edit','data'=>['id'=>1,'name'=>'Must not persist']],
            ['changeType'=>'update','target'=>'plans','action'=>'edit','data'=>['id'=>1,'uploadOverride'=>99]],
            ['changeType'=>'update','target'=>'jobs','action'=>'exec'],['changeType'=>'update','target'=>'jobs','action'=>'retry'],
            ['changeType'=>'update','target'=>'jobs','action'=>'cancel'],['changeType'=>'update','target'=>'backups','action'=>'restore','data'=>['name'=>'backup-0']],
            ['changeType'=>'update','target'=>'config','action'=>'edit','data'=>['lease_time'=>99]]] as $mutation) {
            $response = $request($mutation,$admin);
            checkMigrationConflict($response['http']===400 && ($response['response']['message'] ?? null)==='duplicate_network_addresses_resolve_before_upgrade'
                && ($response['response']['recovery']['readOnly'] ?? null)===true,
                'Recovery allowed a provisioning, configuration, job or restore write');
            checkMigrationConflict(recoveryDiskSnapshot()===$before, 'Blocked recovery write changed stored data');
        }
        $boundary = 'MigrationFixtureBoundary0001';
        $upload = '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"backup\"; filename=\"fixture.sqlite\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n" . file_get_contents('data/backup-0') . "\r\n--" . $boundary . "--\r\n";
        $uploadResponse = $request($upload,$admin,'POST','','multipart/form-data; boundary=' . $boundary);
        checkMigrationConflict($uploadResponse['http']===400
            && ($uploadResponse['response']['message'] ?? null)==='duplicate_network_addresses_resolve_before_upgrade'
            && ($uploadResponse['response']['recovery']['readOnly'] ?? null)===true
            && recoveryDiskSnapshot()===$before, 'Recovery upload bypassed the live-database write guard');
        foreach ([['GET','?page=panel'],['GET','?download=network-conflicts']] as [$method,$query]) {
            $response = $request([],[],$method,$query);
            checkMigrationConflict($response['http']===403 && ($response['response']['data'] ?? null)===[],
                'Unauthenticated recovery read disclosed private assignments');
        }
        foreach (['network-conflicts','backup-0'] as $download) {
            $response = $request([],$reader,'GET','?download=' . $download);
            checkMigrationConflict($response['http']===403 && ($response['response']['data'] ?? null)===[],
                'Read-only administrator bypassed existing download edit permission');
        }
        $secret = 'MIGRATION-PRIVACY-FIXTURE-SECRET-0123456789';
        foreach ([[['webhookAllowWithoutSecret'=>true],[]],
            [['webhookSecret'=>$secret],['X-Webhook-Token'=>$secret]],
            [['webhookSecret'=>$secret],['Authorization'=>'Bearer ' . $secret]]] as [$settings,$headers]) {
            file_put_contents('data/config.json',json_encode($settings));
            foreach (['service','client'] as $entity) {
                $event = ['entity'=>$entity,'entityId'=>$entity==='service' ? 7 : 70,'changeType'=>'edit',
                    'uuid'=>'MIGRATION-PRIVACY-FIXTURE-UUID-0001','target'=>'network_conflicts',
                    'action'=>'list','data'=>['refreshClients'=>true,'serviceIds'=>[7]]];
                $readsBeforeWebhook = file_get_contents($directory . '/sdk-reads.jsonl');
                $response = $request($event,$headers);
                checkMigrationConflict($response['http']===400 && ($response['response']['error'] ?? null)===true
                    && ($response['response']['message'] ?? null)==='duplicate_network_addresses_resolve_before_upgrade'
                    && ($response['response']['data'] ?? null)===['duplicate_groups'=>2],
                    'Webhook conflict response exposed network addresses or service IDs beyond its total');
                checkMigrationConflict(strpos($response['body'],'203.0.113.7')===false
                    && strpos($response['body'],'onerror')===false && strpos($response['body'],'service_ids')===false
                    && strpos($response['body'],'conflicts')===false,
                    'Compatibility/authenticated webhook disclosed private conflict diagnostics');
                checkMigrationConflict(recoveryDiskSnapshot()===$before,
                    'Rejected webhook migration changed persistent recovery or operational state');
                checkMigrationConflict(file_get_contents($directory . '/sdk-reads.jsonl')===$readsBeforeWebhook,
                    'A webhook diagnostic hint called CRM without an administrative read grant');
            }
        }
        file_put_contents('data/config.json',json_encode(['webhookSecret'=>$secret]));
        $unauthorized = $request(['entity'=>'service','entityId'=>7,'changeType'=>'edit',
            'uuid'=>'MIGRATION-PRIVACY-FIXTURE-UUID-0001']);
        checkMigrationConflict($unauthorized['http']===403
            && ($unauthorized['response']['message'] ?? null)==='unauthorized'
            && ($unauthorized['response']['data'] ?? null)===[], 'Unauthenticated webhook exposed conflict totals or assignments');
        checkMigrationConflict(recoveryDiskSnapshot()===$before, 'Unauthorized webhook changed persistent recovery state');

        publicMigrationCleanupChecks($directory,$request);
        checkMigrationConflict(recoveryDiskSnapshot()===$before, 'Isolated cleanup changed the original blocked database');

        // A rejected candidate backup after successful setup must not lock a clean live panel.
        $cleanDirectory = $directory . '/clean-restore';
        mkdir($cleanDirectory,0700);
        foreach (['lib','includes','includes/l10n','data','vendor','public','public/panel_v2'] as $folder) {
            mkdir($cleanDirectory . '/' . $folder,0700);
        }
        foreach (glob($directory . '/lib/*.php') as $file) copy($file,$cleanDirectory . '/lib/' . basename($file));
        foreach (glob($directory . '/includes/*') as $file) if (is_file($file)) copy($file,$cleanDirectory . '/includes/' . basename($file));
        foreach (glob($directory . '/includes/l10n/*.json') as $file) copy($file,$cleanDirectory . '/includes/l10n/' . basename($file));
        foreach (['public.php','manifest.json','vendor/autoload.php','public/panel_v2/index.php'] as $file) {
            copy($directory . '/' . $file,$cleanDirectory . '/' . $file);
        }
        $cleanLive = new ApiSqlite($cleanDirectory . '/data/data.db');
        $cleanLive->exec(file_get_contents('includes/schema.sql'));
        (new ApiSetup($cleanLive))->config_load();
        $cleanLive->saveConfig(['version'=>MY_VERSION,'cache_version'=>'1.8.11','next_cache'=>'2100-01-01','next_sites'=>'2100-01-01']);
        $cleanLive->insert(['id'=>123,'name'=>'Clean live plan'], 'plans');
        $cleanLive->close();
        $cleanCache = new ApiSqlite($cleanDirectory . '/data/cache.db');
        $cleanCache->exec(file_get_contents('includes/cache.sql')); $cleanCache->close();
        file_put_contents($cleanDirectory . '/data/state.json','{"version":"1.0.0","backup":"2100-01-01","cleanup":"2100-01-01"}');
        copy('data/backup-0',$cleanDirectory . '/data/backup-0');
        $cleanHeaders = $admin + ['X-Fixture-Mode'=>'restore-after-setup'];
        $normal = $request([],$cleanHeaders,'GET','?page=panel');
        checkMigrationConflict($normal['http']===200 && strpos($normal['body'],'id="network-recovery-state"')===false,
            'Clean live panel unexpectedly entered recovery: ' . $normal['http'] . ' ' . substr($normal['body'],0,500)
            . ' ' . substr(file_get_contents($directory . '/webhook-server-error.log'),-1800));
        $cleanBeforeRestore = recoveryDiskSnapshot($cleanDirectory . '/data');
        $cleanJobs = new ApiSqlite($cleanDirectory . '/data/jobs.db');
        $cleanQueueBefore = migrationDatabaseSnapshot($cleanJobs); $cleanJobs->close();
        $rejectedCandidate = $request(['changeType'=>'update','target'=>'backups','action'=>'restore','data'=>['name'=>'backup-0']],$cleanHeaders);
        checkMigrationConflict($rejectedCandidate['http']===400
            && ($rejectedCandidate['response']['message'] ?? null)==='duplicate_network_addresses_resolve_before_upgrade'
            && !array_key_exists('recovery',$rejectedCandidate['response'])
            && ($rejectedCandidate['response']['data']['duplicate_groups'] ?? null)===2,
            'Rejected backup candidate falsely switched a healthy live panel to read-only recovery');
        $cleanAfterRestore = recoveryDiskSnapshot($cleanDirectory . '/data');
        // An authorized restore acquires/removes the queue's durable maintenance gate.
        // Its SQLite pages can change while all jobs and queue metadata remain identical.
        foreach (array_keys($cleanBeforeRestore) as $path) if (basename($path)==='jobs.db') unset($cleanBeforeRestore[$path]);
        foreach (array_keys($cleanAfterRestore) as $path) if (basename($path)==='jobs.db') unset($cleanAfterRestore[$path]);
        $cleanJobs = new ApiSqlite($cleanDirectory . '/data/jobs.db');
        $cleanQueueAfter = migrationDatabaseSnapshot($cleanJobs); $cleanJobs->close();
        checkMigrationConflict($cleanAfterRestore===$cleanBeforeRestore,
            'Rejected candidate after successful setup changed healthy live data or recovery snapshots: '
            . json_encode(array_keys(array_diff_assoc($cleanAfterRestore,$cleanBeforeRestore))));
        checkMigrationConflict($cleanQueueAfter===$cleanQueueBefore, 'Rejected candidate changed healthy pending work or queue metadata');
        $stillNormal = $request([],$cleanHeaders,'GET','?page=panel');
        checkMigrationConflict($stillNormal['http']===200 && strpos($stillNormal['body'],'id="network-recovery-state"')===false,
            'Candidate conflict persisted a false read-only state on the clean live panel');
        checkMigrationConflict(recoveryDiskSnapshot()===$before, 'Separate clean-live restore fixture changed the blocked original database');
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
mkdir($directory . '/includes/l10n', 0700);
foreach (glob($source . '/lib/*.php') as $file) copy($file, $directory . '/lib/' . basename($file));
foreach (glob($source . '/includes/*') as $file) if (is_file($file)) copy($file, $directory . '/includes/' . basename($file));
foreach (glob($source . '/includes/l10n/*.json') as $file) copy($file,$directory . '/includes/l10n/' . basename($file));
copy($source . '/manifest.json', $directory . '/manifest.json');
copy($source . '/public.php', $directory . '/public.php');
copy($source . '/public/panel_v2/index.php', $directory . '/public/panel_v2/index.php');
file_put_contents($directory . '/vendor/autoload.php', <<<'PHP'
<?php
namespace GuzzleHttp\Exception;
class RequestException extends \RuntimeException {
    private $response;
    public function __construct(int $status) {
        parent::__construct('Synthetic CRM response', $status);
        $this->response = new class($status) {
            private int $status;
            public function __construct(int $status) { $this->status = $status; }
            public function getStatusCode(): int { return $this->status; }
        };
    }
    public function hasResponse(): bool { return true; }
    public function getResponse() { return $this->response; }
}
namespace Ubnt\UcrmPluginSdk\Service;
class UcrmApi {
    public static function create($token=null) { return new static(); }
    public function __call($method, $arguments) {
        if ($method!=='get') throw new \RuntimeException('Network writes prohibited in migration-conflict tests');
        $path = $arguments[0];
        file_put_contents(__DIR__ . '/../sdk-reads.jsonl',json_encode($path) . "\n",FILE_APPEND);
        if ($path==='clients/services/7') return ['id'=>7,'clientId'=>70,'status'=>2];
        if ($path==='clients/services/8') throw new \GuzzleHttp\Exception\RequestException(404);
        if ($path==='clients/70') return ['id'=>70,'companyName'=>'Archived fixture client','isArchived'=>true];
        if ($path==='clients/80') return ['id'=>80,'companyName'=>'Current fixture client','isArchived'=>false];
        throw new \RuntimeException('Unexpected CRM read in migration-conflict tests');
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
    $apiSqlite->insert([['id'=>7,'clientId'=>70,'device'=>1,'planId'=>1,'status'=>2],
        ['id'=>8,'clientId'=>80,'device'=>1,'planId'=>1,'status'=>1],
        ['id'=>21,'clientId'=>210,'device'=>1,'planId'=>1,'status'=>8],
        ['id'=>22,'clientId'=>220,'device'=>1,'planId'=>1,'status'=>3]], 'services');
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
    publicMigrationWebhookChecks($directory);
    $cacheFixture = $fixtures[] = new ApiSqlite('data/cache.db');
    $cacheFixture->exec(file_get_contents('includes/cache.sql'));
    $cacheFixture->insert([['id'=>70,'company'=>'Cached archived client','isArchived'=>1],
        ['id'=>80,'firstName'=>'Cached','lastName'=>'Active client','isArchived'=>0]],'clients');
    $cacheFixture->insert([['id'=>7,'clientId'=>70,'status'=>2],['id'=>8,'clientId'=>80,'status'=>1]],'services');
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

    // The normal panel shell bootstraps safe JSON and keeps administrative reads available.
    $apiSqlite->saveConfig(['version'=>'2.3.1']);
    $admin = ['isClient'=>false,'permissions'=>['system/plugins'=>'edit']];
    $beforePublic = publicMigrationSnapshot();
    $panel = publicMigrationRequest($directory, $admin, 'GET', ['page'=>'panel']);
    checkMigrationConflict($panel['http']===200 && preg_match('~<script type="application/json" id="network-recovery-state">(.*?)</script>~s',
        $panel['body'],$bootstrap)===1 && strpos($panel['body'],$maliciousAddress)===false,
        'Authenticated recovery panel omitted safe JSON bootstrap or changed normal shell status');
    checkMigrationConflict(str_replace($bootstrap[0],'',$panel['body'])===file_get_contents('public/panel_v2/index.php'),
        'Recovery panel replaced or altered the normal static shell');
    $panelState = json_decode($bootstrap[1],true,512,JSON_THROW_ON_ERROR);
    checkMigrationConflict(($panelState['readOnly'] ?? null)===true && ($panelState['details']['duplicate_groups'] ?? null)===2,
        'Recovery bootstrap did not enable read-only mode with current conflict totals');
    $panelRawConflict = conflictByAddress($panelState['details'],'IPv4',$maliciousAddress);
    checkMigrationConflict($panelRawConflict['service_ids']===[21,22], 'Safe panel bootstrap altered raw addresses or service IDs');
    $cachedOwner = conflictByAddress($panelState['details'],'IPv4','203.0.113.7')['owners'][0];
    checkMigrationConflict($cachedOwner['client_id']===70 && $cachedOwner['client_name']==='Cached archived client'
        && $cachedOwner['client_archived']===true && $cachedOwner['service_status_code']===2
        && $cachedOwner['source']==='database' && $cachedOwner['client_source']==='cache'
        && $cachedOwner['client_url']==='/crm/client/70' && $cachedOwner['service_url']==='/crm/client/service/7',
        'Local bootstrap omitted cached client names, archival state or usable client/service routes');
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
    foreach ([['GET',['download'=>'network-conflicts']],['GET',['page'=>'panel','download'=>'network-conflicts']]] as $request) {
        $response = publicMigrationRequest($directory, $admin, $request[0], $request[1]);
        $body = json_decode($response['body'], true);
        checkMigrationConflict($response['http']===200 && ($body['error'] ?? null)===false
            && ($body['message'] ?? null)==='network_diagnostics' && ($body['recovery']['readOnly'] ?? null)===true
            && ($body['data']['duplicate_groups'] ?? null)===2
            && is_bool($body['data']['conflicts_truncated'] ?? null),
            'Authenticated diagnostic download lost its enriched read-only JSON response');
        $rawConflict = conflictByAddress($body['data'], 'IPv4', $maliciousAddress);
        checkMigrationConflict($rawConflict['service_ids']===[21,22], 'JSON diagnostics altered raw addresses or typed service IDs');
        checkMigrationConflict(publicMigrationSnapshot()===$beforePublic, 'JSON recovery request changed persistent recovery or operational state');
    }
    $backupDownload = publicMigrationRequest($directory,$admin,'GET',['download'=>'backup-0']);
    checkMigrationConflict($backupDownload['http']===200 && $backupDownload['body']===file_get_contents('data/backup-0'),
        'Recovery backup download did not return the existing SQLite snapshot unchanged');
    $emptyPost = publicMigrationRequest($directory,$admin,'POST',[]);
    $emptyPostBody = json_decode($emptyPost['body'],true);
    checkMigrationConflict($emptyPost['http']===400 && ($emptyPostBody['message'] ?? null)==='duplicate_network_addresses_resolve_before_upgrade'
        && ($emptyPostBody['recovery']['readOnly'] ?? null)===true,
        'Non-read administrative POST lost the live-database conflict write guard');
    checkMigrationConflict(publicMigrationSnapshot()===$beforePublic, 'Read-only diagnostic or backup download changed stored recovery state');

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
    echo "Migration conflict checks passed: bounded diagnostics, preserved setup/restores, read-only panel recovery, explicit owner refresh, authenticated obsolete-reservation preview/apply and private backups, blocked writes/uploads and private webhooks.\n";
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
