<?php
// Local schema/ownership fixtures only. No allocator, queue constructor or network request is used.
set_error_handler(function($severity,$message,$file,$line) { if (error_reporting()&$severity) throw new ErrorException($message,0,$severity,$file,$line); return false; });
function capacityCheck(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
$root=realpath(__DIR__.'/../src'); require_once $root.'/lib/api_pool_capacity.php';
$directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'uisp-pool-capacity-'.bin2hex(random_bytes(8)); mkdir($directory,0700);
$mainPath=$directory.'/data.db'; $cachePath=$directory.'/cache.db'; $jobsPath=$directory.'/jobs.db';
$main=new SQLite3($mainPath); $main->enableExceptions(true); $main->exec(file_get_contents($root.'/includes/schema.sql'));
$main->exec("INSERT INTO config(key,value) VALUES('router_ppp_pool','true'),('excl_addr','192.0.2.4,2001:db8:0:3::/64');
    INSERT INTO devices(id,name,ip,port,user,password,type,pool,pool6,pfxLength) VALUES
    (1,'Fixture East','192.0.2.10',8728,'PRIVATE-USER','PRIVATE-PASSWORD','mikrotik','192.0.2.0/29','2001:db8::/48',64),
    (2,'Fixture Shared','192.0.2.11',8728,'PRIVATE-USER','PRIVATE-PASSWORD','mikrotik','192.0.2.0/29','2001:db8::/48',56),
    (3,'Fixture Empty','198.51.100.10',8728,'PRIVATE-USER','PRIVATE-PASSWORD','mikrotik','198.51.100.0/31',NULL,NULL),
    (4,'Fixture Huge','198.51.100.11',8728,'PRIVATE-USER','PRIVATE-PASSWORD','mikrotik',NULL,'4000::/3',128);
    INSERT INTO network(id,address,address6) VALUES(1,'192.0.2.1','2001:db8::/64'),(20,'192.0.2.0',NULL),(21,'192.0.2.7',NULL)");
$cache=new SQLite3($cachePath); $cache->enableExceptions(true); $cache->exec(file_get_contents($root.'/includes/cache.sql'));
$cache->exec("INSERT INTO services(id,device,status) VALUES(1,1,1); INSERT INTO network(id,address,address6) VALUES(1,'192.0.2.1','2001:db8::1')");
$jobs=new SQLite3($jobsPath); $jobs->enableExceptions(true); $jobs->exec('CREATE TABLE jobs(service_id INTEGER,status TEXT,payload TEXT,prior_cleared INTEGER,cleanup_done INTEGER);
    CREATE TABLE service_versions(service_id INTEGER PRIMARY KEY,applied_payload TEXT);
    CREATE TABLE cancellation_cleanups(service_id INTEGER,payload TEXT);
    CREATE TABLE retained_resources(service_id INTEGER,slot TEXT,payload TEXT);
    CREATE TABLE service_tombstones(service_id INTEGER PRIMARY KEY)');
$applied=['id'=>2,'clientId'=>17,'network'=>['address'=>'192.0.2.2','address6'=>'2001:db8:0:1::/64'],'password'=>'PRIVATE-ACCOUNT',
    '_commands'=>[['path'=>'/ppp/secret','remote-address'=>'192.0.2.2','remote-ipv6-prefix'=>'2001:db8:0:1::/64','password'=>'PRIVATE-COMMAND']]];
$retained=['id'=>3,'network'=>['address'=>'192.0.2.3','address6'=>'2001:db8:0:2::/64']];
$put=static function(string $table,array $values) use($jobs):void {
    $statement=$jobs->prepare('INSERT INTO '.$table.' VALUES('.implode(',',array_fill(0,count($values),'?')).')');
    foreach ($values as $i=>$value) $statement->bindValue($i+1,is_array($value)?json_encode($value):$value,is_int($value)?SQLITE3_INTEGER:SQLITE3_TEXT);
    $statement->execute()->finalize(); $statement->close();
};
$put('service_versions',[2,$applied]); $put('retained_resources',[3,'commands',$retained]);
$api=new ApiPoolCapacity($mainPath,$cachePath,$jobsPath);
$row=static function(array $report,int $device,string $family='IPv4'):array {
    foreach ($report['items'] as $item) if ($item['device_id']===$device && $item['family']===$family) return $item;
    throw new RuntimeException('Missing capacity row '.$device.' '.$family.': '.json_encode($report));
};
try {
    $before=array_map(static fn($path)=>hash_file('sha256',$path),[$mainPath,$cachePath,$jobsPath]);
    $report=$api->read(); capacityCheck($report['available'],'Initial capacity unavailable');
    $east=$row($report,1); capacityCheck($east['total']==='6' && $east['assigned']==='2' && $east['retained']==='1'
        && $east['excluded']==='1' && $east['free']==='2' && $east['warning']==='low','IPv4 unions/categories or network/broadcast rules incorrect');
    capacityCheck($east['shared_pool'] && $row($report,2)['shared_pool'],'Shared IPv4 pools hidden');
    $v6=$row($report,1,'IPv6'); capacityCheck($v6['total']==='65536' && $v6['assigned']==='2' && $v6['retained']==='1'
        && $v6['excluded']==='1' && $v6['free']==='65532','IPv6 prefix normalization or range union incorrect');
    $wide=$row($report,2,'IPv6'); capacityCheck($wide['total']==='256' && $wide['assigned']==='1' && $wide['retained']==='0'
        && $wide['excluded']==='0' && $wide['free']==='255' && $wide['shared_pool'] && $v6['shared_pool'],'Different IPv6 delegation units overlap asymmetrically');
    $empty=$row($report,3); capacityCheck($empty['total']==='0' && $empty['free']==='0' && $empty['warning']==='exhausted','Empty /31 pool disappeared or offered endpoints');
    $huge=$row($report,4,'IPv6'); capacityCheck($huge['total']==='42535295865117307932921825928971026432' && $huge['free']===$huge['total'],'Large IPv6 capacity lost integer precision');
    capacityCheck($before===array_map(static fn($path)=>hash_file('sha256',$path),[$mainPath,$cachePath,$jobsPath]),'Capacity calculation wrote source state');
    capacityCheck(count(glob($directory.'/*'))===3,'Capacity calculation initialized files');
    foreach (['PRIVATE-USER','PRIVATE-PASSWORD','PRIVATE-ACCOUNT','PRIVATE-COMMAND','password','payload'] as $private)
        capacityCheck(strpos(json_encode($report),$private)===false,'Capacity report exposed private material');
    // Cancelled/superseded prepared commands and uncleared history remain reserved.
    $held=['id'=>4,'network'=>['address'=>'192.0.2.5'],'_prior_network'=>['address'=>'192.0.2.6'],
        '_commands'=>[['path'=>'/ppp/secret','remote-address'=>'192.0.2.5']]];
    $put('jobs',[4,'cancelled',$held,0,0]); $east=$row($api->read(),1);
    capacityCheck($east['assigned']==='2' && $east['retained']==='3' && $east['free']==='0','Cancelled prepared resources were lost or labelled applied');
    $jobs->exec('DELETE FROM jobs'); $put('jobs',[4,'superseded',$held,1,1]); $east=$row($api->read(),1);
    capacityCheck($east['retained']==='2' && $east['free']==='1','Cleared historical reservation still counted');
    // An unprepared desired IP is intent, while its uncleared prior address remains held.
    $jobs->exec('DELETE FROM jobs'); unset($held['_commands']); $put('jobs',[4,'pending',$held,0,0]); $east=$row($api->read(),1);
    capacityCheck($east['retained']==='2' && $east['free']==='1','Unprepared desired address treated as owned');
    $jobs->exec('DELETE FROM jobs'); $put('retained_resources',[4,'source',$held]); $east=$row($api->read(),1);
    capacityCheck($east['retained']==='2' && $east['free']==='1','Discarded source desired address treated as owned');
    $jobs->exec('DELETE FROM retained_resources WHERE service_id=4');
    $held=['id'=>4,'_cleanup'=>['id'=>4,'network'=>['address'=>'192.0.2.5']]]; $put('jobs',[4,'pending',$held,1,0]);
    capacityCheck($row($api->read(),1)['free']==='1','Unfinished old-router cleanup address not held');
    $jobs->exec('UPDATE jobs SET cleanup_done=1'); capacityCheck($row($api->read(),1)['free']==='2','Completed cleanup address never released'); $jobs->exec('DELETE FROM jobs');
    $held=['id'=>4,'_commands'=>[['path'=>'/queue/simple','name'=>'parent','comment'=>'do not delete','target'=>'0.0.0.0/0']],
        '_previous_addresses'=>['192.0.2.5']]; $put('jobs',[4,'pending',$held,1,1]);
    capacityCheck($row($api->read(),1)['free']==='1','Shared queue target consumed pool or prior address disappeared'); $jobs->exec('DELETE FROM jobs');
    // Overlapping/duplicate pool entries and exclusions must not double-count.
    $main->exec("UPDATE devices SET pool='192.0.2.0/29,192.0.2.0/29,192.0.2.0/30' WHERE id=1; UPDATE config SET value='192.0.2.4-192.0.2.5,192.0.2.5,2001:db8:0:3::/64' WHERE key='excl_addr'");
    $east=$row($api->read(),1); capacityCheck($east['total']==='6' && $east['excluded']==='2' && $east['free']==='1','Overlapping pools/exclusions double-counted');
    $main->exec("UPDATE devices SET pool='192.0.2.0/29' WHERE id=1; UPDATE devices SET pool='198.51.100.1/32' WHERE id=3");
    capacityCheck($row($api->read(),3)['total']==='0','/32 allocation endpoints counted');
    $cache->exec("UPDATE network SET address6='2001:db8::/64' WHERE id=1");
    $main->exec("UPDATE devices SET pfxLength='64x' WHERE id=1"); capacityCheck(!$row($api->read(),1,'IPv6')['available'],'Malformed delegation size silently cast to a valid size');
    $main->exec('UPDATE devices SET pfxLength=NULL WHERE id=1'); capacityCheck($row($api->read(),1,'IPv6')['unit_prefix']===64,'Missing delegation size did not use documented default');
    $main->exec("INSERT INTO network(id,address) VALUES(30,'INVALID-PEER-ADDRESS')"); $report=$api->read();
    capacityCheck($report['invalid_records']===1 && strpos(json_encode($report),'INVALID-PEER-ADDRESS')===false,'Invalid literal hidden or copied to report'); $main->exec('DELETE FROM network WHERE id=30');
    $jobs->exec('INSERT INTO service_tombstones VALUES(1)'); $east=$row($api->read(),1);
    capacityCheck($east['assigned']==='1' && $east['retained']==='2','Terminated desired cache was counted as an active assignment'); $jobs->exec('DELETE FROM service_tombstones');
    $main->exec("UPDATE config SET value='false' WHERE key='router_ppp_pool'; INSERT INTO config(key,value) VALUES('ppp_pool','198.18.0.0/29,fd10:9900::/48')");
    $report=$api->read(); $global=array_values(array_filter($report['items'],static fn($item)=>$item['scope']==='global_ppp'));
    capacityCheck(count($global)===2 && $global[0]['family']==='IPv4' && $global[0]['total']==='6'
        && $global[1]['family']==='IPv6' && $global[1]['total']==='65536','Mixed global PPP capacity wrong');
    $main->exec("UPDATE config SET value='invalid' WHERE key='router_ppp_pool'"); capacityCheck(!$api->read()['available'],'Unknown allocation mode guessed');
    $main->exec("UPDATE config SET value='true' WHERE key='router_ppp_pool'");
    $put('jobs',[4,'pending','{broken',0,0]); capacityCheck(!$api->read()['available'],'Corrupt durable ownership guessed free'); $jobs->exec('DELETE FROM jobs');
    $put('jobs',[4,'unknown',['id'=>4,'network'=>['address'=>'192.0.2.5']],0,0]); capacityCheck(!$api->read()['available'],'Unknown job state ignored'); $jobs->exec('DELETE FROM jobs');
    $put('service_versions',[99,['id'=>100,'address'=>'192.0.2.5']]); capacityCheck(!$api->read()['available'],'Mismatched ownership service ID trusted'); $jobs->exec('DELETE FROM service_versions WHERE service_id=99');
    file_put_contents($directory.'/queue.json','[{"service":4}]'); capacityCheck(!$api->read()['available'],'Legacy hidden queue ignored'); unlink($directory.'/queue.json');
    $missing=$directory.'/missing.db'; capacityCheck(!(new ApiPoolCapacity($mainPath,$missing,$jobsPath))->read()['available'] && !file_exists($missing),'Missing cache was created or assumed empty');
    $jobs->exec('ALTER TABLE jobs RENAME TO saved_jobs'); capacityCheck(!$api->read()['available'],'Missing job schema treated as empty');
    $jobs->exec('ALTER TABLE saved_jobs RENAME TO jobs; ALTER TABLE retained_resources RENAME TO saved_retained; CREATE VIEW retained_resources AS SELECT * FROM saved_retained');
    capacityCheck(!$api->read()['available'],'Ownership view silently trusted');
    echo "Pool capacity regression passed\n";
} finally {
    $jobs->close(); $cache->close(); $main->close(); foreach (glob($directory.'/*')?:[] as $path) if (is_file($path)) unlink($path); rmdir($directory);
}
