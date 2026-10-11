<?php
// Client rename and billing race regression. The transport is an in-memory fake; no network I/O.
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
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-client-queue-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
foreach (['lib','includes','data','vendor'] as $folder) mkdir($directory . '/' . $folder, 0700);
foreach (glob($source . '/lib/*.php') as $file) copy($file, $directory . '/lib/' . basename($file));
foreach (glob($source . '/includes/*') as $file) if (is_file($file)) copy($file, $directory . '/includes/' . basename($file));
file_put_contents($directory . '/vendor/autoload.php', '<?php namespace Ubnt\\UcrmPluginSdk\\Service; class UcrmApi { public static function create(){return new self();} public function get(){throw new \\RuntimeException("Network prohibited in stability tests");} }');
file_put_contents($directory.'/lib/mt.php', <<<'PHP'
<?php
class MT {
    public static array $queues=[];
    public static bool $arm=false;
    public function do_batch($device,$commands): int {
        foreach($commands as $command) {
            if(trim($command['path'] ?? '', '/')!=='queue/simple') continue;
            $name=$command['name'];
            if(($command['action'] ?? '')==='remove') {
                unset(self::$queues[$name]);
                if(self::$arm && $name==='Dummy Client - 1') { RacingCache::$armed=true; self::$arm=false; }
            } else self::$queues[$name]=$command;
        }
        return count($commands);
    }
    public function failed(): array{return [];}
    public function success(): array{return [];}
}
PHP);
chdir($directory);
set_include_path($directory . '/lib' . PATH_SEPARATOR . get_include_path());
require 'lib/api_common.php';
require 'lib/api_setup.php';
require 'lib/api_update.php';
require 'lib/api_configuration_apply.php';
class RacingCache extends ApiSqlite {
    public static bool $armed=false;
    public static $beforeTransaction=null;
    public function transaction(callable $callback) {
        if (self::$armed && self::$beforeTransaction!==null) {
            $work=self::$beforeTransaction; self::$beforeTransaction=null; self::$armed=false; $work();
        }
        return parent::transaction($callback);
    }
}
try {
    $apiSqlite = new ApiSqlite('data/data.db');
    $apiSqlite->exec(file_get_contents('includes/schema.sql'));
    (new ApiSetup())->config_load();
    $apiSqlite->saveConfig(['version'=>MY_VERSION]);
    $apiSqliteCache = new RacingCache('data/cache.db');
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
    require_once 'lib/batch.php';
    require_once 'lib/api_action.php';
    require_once 'lib/api_trim.php';
    $apiSqliteCache->edit(['id'=>1,'mac'=>'02:00:00:00:00:01'],'services');
    (new Batch())->set_accounts([1]);
    assertStable(array_keys(MT::$queues)===['Dummy Client - 1'],'Initial DHCP queue was not established');
    $suspend=function() use($apiSqliteCache,$apiJobQueue):void {
        $apiJobQueue->withServiceLock(1,function() use($apiSqliteCache):void {
            $apiSqliteCache->edit(['id'=>1,'status'=>3],'services');
            $suspended=$apiSqliteCache->singleQuery('SELECT services.*,clients.company,clients.firstName,clients.lastName,network.address,network.address6
                FROM services LEFT JOIN clients ON services.clientId=clients.id LEFT JOIN network ON services.id=network.id WHERE services.id=1',true);
            (new Batch())->set_accounts([$suspended]);
        });

    };
    $billingBlocked=false;
    RacingCache::$beforeTransaction=function() use($suspend,&$billingBlocked):void {
        try { $suspend(); }
        catch(RuntimeException $error) { $billingBlocked=true; }
    };
    MT::$arm=true;
    $request=(object)['entity'=>'client','entityId'=>1,'changeType'=>'edit','uuid'=>'client-name-event-1',
        'extraData'=>(object)['entity'=>(object)['id'=>1,'firstName'=>'Renamed']]];
    $action=new ApiAction($request); $action->exec();
    assertStable($billingBlocked,'Billing event interleaved between removing and recreating a client queue');
    assertStable(!$action->status()->error,'Client update failed while owning its own service gates');
    assertStable(array_keys(MT::$queues)===['Renamed Client - 1'],'Client rename retained or recreated the old DHCP queue');
    assertStable($apiSqliteCache->singleQuery('SELECT lastName FROM clients WHERE id=1')==='Client',
        'A partial client rename lost the omitted last name');
    // The postponed billing event is retried after the complete client mutation.
    $suspend();
    assertStable(array_keys(MT::$queues)===['Renamed Client - 1'],'Retried billing event recreated an obsolete client queue');
    assertStable((int)$apiSqliteCache->singleQuery('SELECT status FROM services WHERE id=1')===3
        && $apiJobQueue->appliedSnapshot(1)['status']===3,'Retried billing event lost the suspended state');
    echo "Client queue regression passed: client/service gates, atomic rename, partial names and billing retry.\n";
} finally {
    if (isset($apiJobQueue)) $apiJobQueue->close();
    if (isset($apiSqlite)) $apiSqlite->close();
    if (isset($apiSqliteCache)) $apiSqliteCache->close();
    chdir($original);
    $resolved=realpath($directory); $temporaryRoot=realpath(sys_get_temp_dir());
    if ($resolved && $temporaryRoot && strpos($resolved, $temporaryRoot . DIRECTORY_SEPARATOR . 'uisp-client-queue-')===0) {
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
        rmdir($resolved);
    }
}
