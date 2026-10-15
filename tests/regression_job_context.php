<?php
require_once __DIR__.'/../src/lib/api_job_context.php';
function contextCheck(bool $value,string $message): void { if (!$value) throw new RuntimeException($message); }
$directory=sys_get_temp_dir().'/uisp-job-context-'.bin2hex(random_bytes(8)); mkdir($directory,0700);
try {
    $data=new SQLite3($directory.'/data.db'); $cache=new SQLite3($directory.'/cache.db'); $jobs=new SQLite3($directory.'/jobs.db');
    foreach ([$data,$cache] as $db) $db->exec('CREATE TABLE services(id INTEGER PRIMARY KEY,clientId INTEGER)');
    $data->exec('INSERT INTO services VALUES(7279,48781)'); $cache->exec('INSERT INTO services VALUES(9807,29)');
    $jobs->exec('CREATE TABLE service_versions(service_id INTEGER PRIMARY KEY,applied_payload TEXT);CREATE TABLE jobs(service_id INTEGER,payload TEXT,status TEXT,error TEXT)');
    $stmt=$jobs->prepare('INSERT INTO service_versions VALUES(?,?)'); $stmt->bindValue(1,9807); $stmt->bindValue(2,json_encode(['id'=>9807,'clientId'=>29,'password'=>'PRIVATE-PASSWORD'])); $stmt->execute()->finalize(); $stmt->close();
    $before=[]; foreach (['data','cache','jobs'] as $name) $before[$name]=hash_file('sha256',$directory.'/'.$name.'.db');
    $job=['id'=>7279,'username'=>'customer@48781','error'=>'network_address_conflict: requested_service=7279 blocking_service=9807 source=reserved family=IPv4'];
    $make=static fn()=>new ApiJobContext($directory.'/data.db',$directory.'/cache.db',$directory.'/jobs.db');
    $result=$make()->enrich(['Q989'=>$job]);
    contextCheck($result['Q989']['relatedServices']===[['serviceId'=>7279,'clientId'=>48781,'identitySource'=>'stored'],['serviceId'=>9807,'clientId'=>29,'identitySource'=>'stored']], 'Blocker client was inferred from requested username instead of its persisted service identity');
    contextCheck(!str_contains(json_encode($result),'PRIVATE'),'Related-client context leaked a snapshot secret');
    foreach ($before as $name=>$hash) contextCheck(hash_file('sha256',$directory.'/'.$name.'.db')===$hash,'Navigation lookup modified local state');
    $data->exec('INSERT INTO services VALUES(9807,99)');
    contextCheck($make()->enrich([$job])[0]['relatedServices'][1]['clientId']===null,'Conflicting stored owners were presented as verified navigation');
    $unknown=$make()->enrich([['id'=>31,'username'=>'customer@48781','error'=>'network_address_conflict: requested_service=31 blocking_service=98 source=reserved family=IPv4']]);
    contextCheck($unknown[0]['relatedServices'][1]['clientId']===null,'Missing service owner was guessed from a username');
    $bad=$job; $bad['error']='network_address_conflict: requested_service=100 blocking_service=9807 source=reserved family=IPv4';
    contextCheck(count($make()->enrich([$bad])[0]['relatedServices'])===1,'Unrelated error identity created a client link');
    $insert=$jobs->prepare('INSERT INTO jobs(service_id,status,error) VALUES(?,?,?)');
    foreach ([[7279,'pending',$job['error']],[100,'completed','network_address_conflict: requested_service=100 blocking_service=99 source=reserved family=IPv4'],
        [101,'pending','network_address_conflict: requested_service=102 blocking_service=98 source=reserved family=IPv4']] as $row) {
        foreach ($row as $index=>$value) $insert->bindValue($index+1,$value); $insert->execute()->finalize(); $insert->reset();
    }
    $insert->close(); $beforeReview=hash_file('sha256',$directory.'/jobs.db');
    contextCheck($make()->isBlockingReservation(9807),'A proven pending reservation blocker could not be reviewed');
    foreach ([7279,99,98,48781] as $sid) contextCheck(!$make()->isBlockingReservation($sid),'An unrelated service could enter blocker reservation review');
    contextCheck(hash_file('sha256',$directory.'/jobs.db')===$beforeReview,'Blocker eligibility check modified queue state');
    (new ApiJobContext($directory.'/absent1.db',$directory.'/absent2.db',$directory.'/absent3.db'))->enrich([$job]);
    contextCheck(!file_exists($directory.'/absent1.db')&&!file_exists($directory.'/absent2.db')&&!file_exists($directory.'/absent3.db'),'Navigation initialized missing storage');
    echo "Job context passed: distinct blocker/requester clients, missing and conflicting identity, no username inference, private read-only local navigation.\n";
} finally {
    foreach ([$data??null,$cache??null,$jobs??null] as $db) if ($db instanceof SQLite3) $db->close();
    foreach (glob($directory.'/*')?:[] as $path) unlink($path); rmdir($directory);
}
