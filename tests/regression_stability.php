<?php
// Integration checks for saved configuration, router retirement and crash recovery. No network I/O.
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
function assertStable(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejectsStable(callable $work, string $message): void {
    try { $work(); } catch (Throwable $error) { return; }
    throw new RuntimeException($message);
}
$source = realpath(__DIR__ . '/../src');
$original = getcwd();
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-stability-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
foreach (['lib','includes','data','vendor'] as $folder) mkdir($directory . '/' . $folder, 0700);
foreach (glob($source . '/lib/*.php') as $file) copy($file, $directory . '/lib/' . basename($file));
foreach (glob($source . '/includes/*') as $file) if (is_file($file)) copy($file, $directory . '/includes/' . basename($file));
file_put_contents($directory . '/vendor/autoload.php', '<?php namespace Ubnt\\UcrmPluginSdk\\Service; class UcrmApi { public static function create(){return new self();} public function get(){throw new \\RuntimeException("Network prohibited in stability tests");} }');
chdir($directory);
set_include_path($directory . '/lib' . PATH_SEPARATOR . get_include_path());
require 'lib/api_common.php';
require 'lib/api_setup.php';
require 'lib/api_update.php';
require 'lib/api_configuration_apply.php';
try {
    $apiSqlite = new ApiSqlite('data/data.db');
    $apiSqlite->exec(file_get_contents('includes/schema.sql'));
    (new ApiSetup())->config_load();
    $apiSqlite->saveConfig(['version'=>MY_VERSION]);
    $apiSqliteCache = new ApiSqlite('data/cache.db');
    $apiSqliteCache->exec(file_get_contents('includes/cache.sql'));
    $apiJobQueue = myJobQueue();
    $apiSqlite->insert(['id'=>10,'name'=>'Router','type'=>'mikrotik','ip'=>'192.0.2.10','user'=>'fixture','password'=>'fixture'], 'devices');
    $apiSqlite->insert(['id'=>11,'name'=>'Empty router','type'=>'mikrotik','ip'=>'192.0.2.11','user'=>'fixture','password'=>'fixture'], 'devices');
    $apiSqlite->insert(['id'=>1,'name'=>'Shared readable name','uploadSpeed'=>20,'downloadSpeed'=>50,'ratio'=>5], 'plans');
    $apiSqliteCache->insert(['id'=>1,'firstName'=>'Dummy','lastName'=>'Client'], 'clients');
    foreach ([1=>1,2=>3] as $id=>$status) {
        $apiSqliteCache->insert(['id'=>$id,'clientId'=>1,'device'=>10,'planId'=>1,'status'=>$status,
            'username'=>'fixture-'.$id,'password'=>'0','uploadSpeed'=>20,'downloadSpeed'=>50], 'services');
        $apiSqliteCache->insert(['id'=>$id,'address'=>'192.0.2.'.(100+$id)], 'network');
    }
    $edit = function (array $data): ApiUpdate {
        $update = new ApiUpdate((object)['action'=>'edit','data'=>(object)$data], 'config');
        $update->exec(); return $update;
    };
    // Saving during an in-flight billing event must retain a durable request instead of racing its state.
    $apiJobQueue->withServiceLock(1, function () use ($edit) { $edit(['disable_contention'=>false,'lease_time'=>61]); });
    assertStable((int)$apiSqlite->singleQuery('SELECT count(*) FROM configuration_apply')===1, 'Busy billing event lost its configuration request');
    assertStable(count($apiJobQueue->list())===1, 'Saved settings did not enqueue the available service');
    $result = ApiConfigurationApply::drain();
    assertStable($result['queued']===1 && $result['remaining']===0, 'Deferred settings did not enqueue after the billing event ended');
    $connection = (new ReflectionProperty(ApiJobQueue::class, 'db'))->getValue($apiJobQueue);
    $rows = $connection->query('SELECT payload FROM jobs ORDER BY id');
    $snapshots=[]; while ($row=$rows->fetchArray(SQLITE3_ASSOC)) $snapshots[]=json_decode($row['payload'], true);
    $rows->finalize();
    assertStable(count($snapshots)===2 && $snapshots[0]['status']===3 && $snapshots[1]['status']===1,
        'Applying configuration changed the billing status');
    foreach ($snapshots as $snapshot) assertStable($snapshot['_config']['disable_contention']===false
        && (int)$snapshot['_config']['lease_time']===61, 'Settings were not frozen with the scheduled operation');
    $before = count($apiJobQueue->list()); $edit(['disable_contention'=>false,'lease_time'=>61]);
    assertStable(count($apiJobQueue->list())===$before, 'Saving unchanged settings created duplicate work');
    rejectsStable(fn()=>$edit(['disabled_profile'=>'uisp-plan-1']), 'A reserved active profile was accepted as the suspension profile');
    assertStable($apiSqlite->readConfig()->disabled_profile==='disabled', 'Rejected profile collision changed configuration');
    $apiJobQueue->withMaintenance(function () use ($edit) {
        rejectsStable(fn()=>$edit(['lease_time'=>99]), 'Configuration wrote during restore maintenance');
    });
    assertStable((int)$apiSqlite->readConfig()->lease_time===61, 'Maintenance rejection changed the stored settings');
    // A plan edit racing a billing event must use the billing state present when its deferred request gets the gate.
    $apiJobQueue->withServiceLock(1, function () use ($apiSqliteCache) {
        (new ApiUpdate((object)['action'=>'edit','data'=>(object)['id'=>1,'uploadOverride'=>3.75]], 'plans'))->exec();
        $apiSqliteCache->edit(['id'=>1,'status'=>3], 'services');
    });
    assertStable((int)$apiSqlite->singleQuery('SELECT count(*) FROM configuration_apply')===1, 'Busy plan application lost its durable request');
    ApiConfigurationApply::drain();
    $rows=$connection->query("SELECT payload FROM jobs WHERE status='pending' AND service_id=1 ORDER BY id DESC LIMIT 1");
    $planIntent=json_decode($rows->fetchArray(SQLITE3_ASSOC)['payload'],true); $rows->finalize();
    assertStable($planIntent['status']===3 && $planIntent['_plan']['uploadSpeed']===3.75,
        'Deferred plan application reactivated a suspended customer or lost the saved override');
    $edit(['active_list'=>'00123']);
    $lastId=(int)$connection->querySingle('SELECT max(id) FROM jobs');
    $edit(['active_list'=>'123']);
    assertStable((int)$connection->querySingle('SELECT max(id) FROM jobs')>$lastId,
        'Distinct numeric-looking address-list names did not schedule application');
    assertStable($apiSqlite->readConfig()->active_list==='123', 'A text address-list name became a number');
    $delete = function (int $id): void {
        (new ApiUpdate((object)['action'=>'delete','data'=>(object)['id'=>$id]], 'devices'))->exec();
    };
    rejectsStable(fn()=>$delete(10), 'A router with cached services and queued jobs was deleted');
    assertStable((int)$apiSqlite->exists(10,'devices')===10, 'A rejected router deletion modified the database');
    $delete(11); assertStable($apiSqlite->exists(11,'devices')===null, 'An empty router could not be retired without UISP I/O');
    // Legacy corrupted data must never reconnect using credentials alone after device deletion.
    $key=$apiJobQueue->enqueue(['id'=>99,'device'=>99,'username'=>'0','password'=>'0',
        '_device'=>['id'=>99,'type'=>'mikrotik','ip'=>'192.0.2.99','user'=>'fixture','password'=>'fixture']]);
    $results=$apiJobQueue->run([$key]);
    assertStable($results[$key]['success']===false && strpos($results[$key]['error'], 'ya no existe')!==false,
        'A legacy snapshot reconnected to an absent router');
    // Recover only auxiliary state; retain corrupt evidence and existing recovery snapshots.
    ApiBackup::create('backup-0'); $backupHash=hash_file('sha256','data/backup-0');
    file_put_contents('data/state.json','{"backup":');
    $state=(new ReflectionMethod(ApiSetup::class,'state'))->invoke(new ApiSetup());
    assertStable($state instanceof stdClass && json_decode(file_get_contents('data/state.json')) instanceof stdClass,
        'Corrupt auxiliary state did not recover');
    assertStable(count(glob('data/state-corrupt-*.json'))===1 && hash_file('sha256','data/backup-0')===$backupHash,
        'State recovery discarded corrupt evidence or rotated a backup');
    assertStable(glob('data/state.json.tmp-*')===[], 'Atomic state publication left a temporary file');
    echo "Stability integration checks passed: durable setting application, billing serialization, frozen configuration, retirement guards, legacy destinations, maintenance and auxiliary-state recovery.\n";
} finally {
    if (isset($apiJobQueue)) $apiJobQueue->close();
    if (isset($apiSqlite)) $apiSqlite->close();
    if (isset($apiSqliteCache)) $apiSqliteCache->close();
    chdir($original);
    $resolved=realpath($directory); $temporaryRoot=realpath(sys_get_temp_dir());
    if ($resolved && $temporaryRoot && strpos($resolved, $temporaryRoot . DIRECTORY_SEPARATOR . 'uisp-stability-')===0) {
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
        rmdir($resolved);
    }
}
