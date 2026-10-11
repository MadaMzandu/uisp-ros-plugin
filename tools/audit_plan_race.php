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
file_put_contents($directory.'/lib/mt.php', '<?php class MT { public function do_batch($device,$commands): int{return count($commands);} public function failed(): array{return [];} public function success(): array{return [];} }');
chdir($directory);
set_include_path($directory . '/lib' . PATH_SEPARATOR . get_include_path());
require 'lib/api_common.php';
require 'lib/api_setup.php';
require 'lib/api_update.php';
require 'lib/api_configuration_apply.php';
class RacingCache extends ApiSqlite {
    public static $afterRead=null;
    public function selectCustom($sql,array $params=[]): ?array {
        $rows=parent::selectCustom($sql,$params);
        if (self::$afterRead!==null && strpos($sql,'SELECT services.*')===0) {
            $callback=self::$afterRead; self::$afterRead=null; $callback($rows);
        }
        return $rows;
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
    RacingCache::$afterRead=function(array $rows) use($apiSqliteCache,$apiJobQueue): void {
        $suspended=array_replace($rows[0],['status'=>3]);
        $apiJobQueue->withServiceLock((int)$suspended['id'],function() use($suspended,$apiSqliteCache,$apiJobQueue):void {
            $apiSqliteCache->edit(['id'=>$suspended['id'],'status'=>3],'services');
            $method=new ReflectionMethod(Batch::class,'snapshot');
            $snapshot=$method->invoke(new Batch(),$suspended,'update');
            $key=$apiJobQueue->enqueue($snapshot);
            $apiJobQueue->run([$key],fn():bool=>true);
            echo 'Billing applied suspension: '.$apiJobQueue->appliedSnapshot((int)$suspended['id'])['status'].PHP_EOL;
        });
    };
    (new ApiUpdate((object)['action'=>'edit','data'=>(object)['id'=>1,'uploadOverride'=>30]],'plans'))->exec();
    echo 'Cached billing status: '.$apiSqliteCache->singleQuery('SELECT status FROM services WHERE id=1').PHP_EOL;
    echo 'Final applied status: '.$apiJobQueue->appliedSnapshot(1)['status'].PHP_EOL;
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
