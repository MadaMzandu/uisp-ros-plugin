<?php
set_error_handler(static function($severity,$message,$file,$line){if(error_reporting()&$severity)throw new ErrorException($message,0,$severity,$file,$line);return false;});
$source=realpath(__DIR__.'/../src');$original=getcwd();$oldInclude=get_include_path();
$directory=sys_get_temp_dir().'/uisp-resolution-evidence-'.bin2hex(random_bytes(8));mkdir($directory,0700);mkdir($directory.'/data',0700);mkdir($directory.'/vendor',0700);
file_put_contents($directory.'/vendor/autoload.php','<?php');require_once$source.'/vendor/autoload.php';
set_include_path($source.'/lib'.PATH_SEPARATOR.$oldInclude);chdir($directory);require_once$source.'/lib/api_service_resolution_evidence.php';
function evidenceCheck(bool$ok,string$message):void{if(!$ok)throw new RuntimeException($message);}
function evidenceRemove(string$path):void{foreach(new FilesystemIterator($path,FilesystemIterator::SKIP_DOTS)as$entry){if($entry->isDir()&&!$entry->isLink())evidenceRemove($entry->getPathname());else unlink($entry->getPathname());}rmdir($path);}
function evidenceHttp(int$status):Throwable{return new \GuzzleHttp\Exception\RequestException('PRIVATE-FAILURE',new \GuzzleHttp\Psr7\Request('GET','https://example.invalid/'),new \GuzzleHttp\Psr7\Response($status));}
$db=null;$jobs=null;
try{
    $db=new ApiSqlite('data/data.db');$db->exec(file_get_contents($source.'/includes/schema.sql'));
    $db->saveConfig(['device_name_attr'=>'site','pppoe_user_attr'=>'account','pppoe_pass_attr'=>'password','mac_addr_attr'=>'mac',
        'dhcp6_duid_attr'=>'duid','dhcp6_iaid_attr'=>'iaid','hs_attr'=>'hotspot','ip_addr_attr'=>'ipv4','ip_addr6_attr'=>'ipv6']);
    foreach([1=>'Selected',2=>'Other']as$id=>$name)$db->insert(['id'=>$id,'name'=>$name,'type'=>'mikrotik','ip'=>'192.0.2.'.$id,
        'port'=>8728,'user'=>'PRIVATE-USER','password'=>'PRIVATE-ROUTER','pool'=>'192.0.2.0/24'],'devices');
    $attributeKeys=['site','account','password','mac','duid','iaid','hotspot','ipv4','ipv6'];$attributes=[];
    foreach($attributeKeys as$index=>$key)$attributes[]=['id'=>$index+1,'key'=>$key,'attributeType'=>'service'];
    $entity=static function(int$sid,int$cid=17,int$status=1,string$site='Selected',string$user='fixture-user',string$ip='192.0.2.7')use($attributeKeys):array{
        $values=['site'=>$site,'account'=>$user,'password'=>'PRIVATE-ACCOUNT','ipv4'=>$ip];$attrs=[];
        foreach($values as$key=>$value)$attrs[]=['key'=>$key,'customAttributeId'=>array_search($key,$attributeKeys,true)+1,'value'=>$value];
        return['id'=>$sid,'clientId'=>$cid,'status'=>$status,'servicePlanId'=>1,'attributes'=>$attrs];};
    $target=$entity(42);$services=[42=>$target,41=>$entity(41,99,2)];
    $clients=[17=>['id'=>17,'isArchived'=>false,'firstName'=>'Fresh','lastName'=>'Customer'],99=>['id'=>99,'isArchived'=>false]];
    $catalog=[$target];$rows=[['.id'=>'*A','name'=>'fixture-user','comment'=>'99 - Former customer - 41','remote-address'=>'192.0.2.143','profile'=>'old','disabled'=>'false','password'=>'PRIVATE-ROW']];
    $queueRows=[];$calls=[];$reads=[];$hook=null;
    $request=static function(string$path,array$params=[])use(&$attributes,&$services,&$clients,&$catalog,&$calls){
        $calls[]=[$path,$params];if($path==='custom-attributes')return$attributes;if($path==='clients/services')return array_slice($catalog,$params['offset'],$params['limit']);
        if(preg_match('~^clients/services/(\d+)$~',$path,$match)){$value=$services[(int)$match[1]]??evidenceHttp(404);if($value instanceof Throwable)throw$value;return$value;}
        if(preg_match('~^clients/(\d+)$~',$path,$match)){$value=$clients[(int)$match[1]]??null;if($value instanceof Throwable)throw$value;return$value;}
        throw new RuntimeException('PRIVATE-UNKNOWN-URL');};
    $reader=static function(array$device,string$path,array$selector,array$fields)use(&$rows,&$queueRows,&$reads,&$hook):array{
        $reads[]=[$device['id'],$path,$selector,$fields];if($hook!==null)$hook();return$path==='queue/simple'?$queueRows:$rows;};
    $api=new ApiServiceResolutionEvidence($request,$reader);
    $hash=hash_file('sha256','data/data.db');$proof=$api->inspect(42);
    evidenceCheck($proof['eligible']&&$proof['autoApply']&&$proof['mode']==='reconcile'&&$proof['defaultRowId']==='*A','Different previous CID and changed IP prevented verified retired-owner repair: '.json_encode($proof['public']));
    evidenceCheck($proof['choices'][0]['removeRowIds']===[]&&$proof['retiredServiceIds']===[41]&&$proof['targetComment']==='17 - Fresh Customer - 42','Private repair scope/owner facts were missing');
    evidenceCheck($proof['public']['candidates'][0]['address']==='192.0.2.143'&&$proof['public']['changes'][1]['after']==='192.0.2.7','IP mismatch was not shown as a reviewed change');
    evidenceCheck(!str_contains(json_encode($proof['public']),'PRIVATE')&&!array_key_exists('password',$proof['rows'][0]),'Public evidence or private allowed row fields disclosed passwords');
    evidenceCheck(hash_file('sha256','data/data.db')===$hash&&!is_file('data/jobs.db')&&!is_file('data/cache.db'),'Read-only evidence initialized or changed operational stores');
    $optional=$attributes;unset($attributes[6]);$attributes=array_values($attributes);evidenceCheck($api->inspect(42)['eligible'],'Unused Hotspot mapping prevented a PPP repair');$attributes=$optional;
    $attributes[1]['attributeType']='client';evidenceCheck(!$api->inspect(42)['eligible'],'A client-level username mapping authorized service repair');$attributes[1]['attributeType']='service';
    $minimalOld=$services[41];$services[41]=['id'=>41,'clientId'=>99,'status'=>2];evidenceCheck($api->inspect(42)['eligible'],'Confirmed retired owner required irrelevant credential/plan fields');$services[41]=$minimalOld;
    $catalog[0]['collectionOnlyProperty']='collection metadata';evidenceCheck($api->inspect(42)['eligible'],'Benign collection-specific metadata prevented exact target verification');$catalog=[$target];
    $fingerprint=$proof['fingerprint'];$rows[0]['remote-address']='192.0.2.144';$changed=$api->inspect(42);
    evidenceCheck($changed['fingerprint']!==$fingerprint&&$changed['eligible'],'Exact row change was not bound into fresh proof');$rows[0]['remote-address']='192.0.2.143';
    // An obsolete marker referencing a different active principal/router is also provably contradictory.
    $services[41]=$entity(41,99,1,'Other','unrelated-user','198.51.100.41');$disjoint=$api->inspect(42);
    evidenceCheck($disjoint['eligible']&&$disjoint['referencedOwners'][41]['state']==='disjoint','Disjoint current old owner remained an automatic blocker');
    $services[41]=$entity(41,99,1);$catalog=[$target,$services[41]];$claimed=$api->inspect(42);
    evidenceCheck(!$claimed['eligible']&&$claimed['reason']==='resolution_owner_active','A current other service lost its account to an explicit repair');
    $clients[99]['isArchived']=true;$archived=$api->inspect(42);evidenceCheck($archived['eligible']&&$archived['referencedOwners'][41]['state']==='retired','Archived current client remained a live ownership claim');$clients[99]['isArchived']=false;
    $services[41]=evidenceHttp(404);$catalog=[$target];evidenceCheck($api->inspect(42)['eligible'],'Authenticated exact404 did not allow obsolete marker repair');
    foreach([403,429,500]as$status){$services[41]=evidenceHttp($status);evidenceCheck(!$api->inspect(42)['eligible'],'Unknown old service API status permitted stealing');}
    $services[41]=new RuntimeException('404 PRIVATE-TEXT');evidenceCheck(!$api->inspect(42)['eligible'],'Text404 was accepted as authenticated absence');$services[41]=$entity(41,99,2);
    // A manual single row needs explicit selection; a manual duplicate is never automatically removed.
    $rows[0]['comment']='Manually created';$manual=$api->inspect(42);
    evidenceCheck($manual['eligible']&&!$manual['autoApply']&&$manual['mode']==='select_account'&&$manual['choices'][0]['selectable'],'Manual singleton did not require explicit account selection');
    $rows[]=['.id'=>'*B','name'=>'fixture-user','comment'=>'17 - Fresh Customer - 42','remote-address'=>'192.0.2.7'];
    $mixed=$api->inspect(42);evidenceCheck($mixed['choices'][0]['selectable']&&$mixed['choices'][0]['removeRowIds']===['*B']&&!$mixed['choices'][1]['selectable'],
        'Manual duplicate was removed as an extra or safe selected/manual plan was lost');
    $rows[0]['comment']='17 - Fresh Customer - 42';$duplicates=$api->inspect(42);
    evidenceCheck($duplicates['mode']==='select_account'&&!$duplicates['autoApply']&&count(array_filter($duplicates['choices'],static fn($choice)=>$choice['selectable']))===2,
        'Duplicate owned rows were silently reduced to the first match');
    $rows[0]['comment']='999 - Wrong client marker - 42';$sameSid=$api->inspect(42);
    evidenceCheck(!$sameSid['eligible'],'Matching SID alone authorized a contradictory current-client marker');
    $manualDuplicates=$rows;$manualDuplicates[0]['comment']='manual A';$manualDuplicates[1]['comment']='manual B';$rows=$manualDuplicates;
    evidenceCheck(!$api->inspect(42)['eligible'],'Two unproven manual duplicates were offered for automatic extra removal');
    $rows=[['.id'=>'*A','name'=>'fixture-user','comment'=>'99 - Former customer - 41','remote-address'=>'192.0.2.143']];
    // Local stale snapshots are checked against canonical billing and routing, not treated as immortal ownership.
    $jobs=new SQLite3('data/jobs.db');$jobs->exec('CREATE TABLE jobs(service_id INTEGER,status TEXT,payload TEXT);CREATE TABLE cancellation_cleanups(service_id INTEGER,payload TEXT);CREATE TABLE service_versions(service_id INTEGER,applied_payload TEXT)');
    $insert=$jobs->prepare('INSERT INTO jobs VALUES(?,?,?)');$insert->bindValue(1,43,SQLITE3_INTEGER);$insert->bindValue(2,'pending');
    $insert->bindValue(3,json_encode(['id'=>43,'clientId'=>99,'device'=>1,'username'=>'fixture-user']));$insert->execute()->finalize();$insert->close();
    $services[43]=$entity(43,99,2);$stale=$api->inspect(42);evidenceCheck($stale['eligible']&&in_array(43,$stale['retiredServiceIds'],true),'Retired local frozen claim permanently blocked reuse');
    $services[43]=$entity(43,99,1);evidenceCheck(!$api->inspect(42)['eligible'],'Active foreign local claim was ignored');
    $services[43]=$entity(43,99,1,'Other','different-account');evidenceCheck($api->inspect(42)['eligible'],'Canonically disjoint old job was mistaken for a current claim');
    // Clearing a mistaken live account is current evidence; frozen queue history must not restore its claim.
    $oldRows=$rows;$rows=[];$localSnapshot=json_encode(['id'=>43,'clientId'=>99,'device'=>1,'username'=>'fixture-user']);
    foreach(['jobs','cancellation_cleanups','service_versions']as$table){
        $jobs->exec('DELETE FROM jobs;DELETE FROM cancellation_cleanups;DELETE FROM service_versions');
        if($table==='jobs')$statement=$jobs->prepare("INSERT INTO jobs(service_id,status,payload) VALUES(43,'pending',?)");
        elseif($table==='cancellation_cleanups')$statement=$jobs->prepare('INSERT INTO cancellation_cleanups(service_id,payload) VALUES(43,?)');
        else$statement=$jobs->prepare('INSERT INTO service_versions(service_id,applied_payload) VALUES(43,?)');
        $statement->bindValue(1,$localSnapshot);$statement->execute()->finalize();$statement->close();
        $cleared=$entity(43,99,1);foreach($cleared['attributes']as&$attribute)if($attribute['key']==='account')$attribute['value']=null;
        unset($attribute);$services[43]=$cleared;$catalog=[$target,$cleared];$detached=$api->inspect(42);
        evidenceCheck($detached['eligible']&&$detached['autoApply'],'Clearing only the mistaken username retained a frozen '.$table.' claim');
        foreach(['removed','null','empty']as$clearing){
            $cleared=$entity(43,99,1);
            if($clearing==='removed')$cleared['attributes']=[];
            else foreach($cleared['attributes']as&$attribute)$attribute['value']=$clearing==='null'?null:'';
            unset($attribute);$services[43]=$cleared;$catalog=[$target,$cleared];$detached=$api->inspect(42);
            evidenceCheck($detached['eligible']&&$detached['autoApply']&&$detached['mode']==='reconcile'
                &&($detached['referencedOwners'][43]['state']??null)==='disjoint',
                'Cleared canonical principal remained blocked by '.$table.' '.$clearing.': '.json_encode($detached['public']));
        }
    }
    // Omitted or invalid canonical data does not establish that an old identity was cleared.
    $cleared=$entity(43,99,1);unset($cleared['attributes']);$services[43]=$cleared;$catalog=[$target];
    evidenceCheck(!$api->inspect(42)['eligible'],'Partial old owner payload was mistaken for an intentionally cleared identity');
    $cleared=$entity(43,99,1);$cleared['attributes']=[['key'=>'account','value'=>['fixture-user']]];$services[43]=$cleared;
    evidenceCheck(!$api->inspect(42)['eligible'],'Malformed old owner principal was mistaken for a cleared identity');
    $cleared=$entity(43,99,1);$cleared['attributes']=array_values(array_filter($cleared['attributes'],static fn($attribute)=>$attribute['key']!=='site'));
    $services[43]=$cleared;$catalog=[$target,$cleared];evidenceCheck(!$api->inspect(42)['eligible'],'Removing only the Site erased a still-matching current username claim');
    $cleared=$entity(43,99,1);foreach($cleared['attributes']as&$attribute)if($attribute['key']==='account')$attribute['value']="invalid\naccount";
    unset($attribute);$services[43]=$cleared;$catalog=[$target];evidenceCheck(!$api->inspect(42)['eligible'],'Nonempty invalid username was classified as an intentionally cleared account');
    $cleared=$entity(43,99,1);$cleared['attributes']=array_values(array_filter($cleared['attributes'],static fn($attribute)=>$attribute['key']!=='account'));
    $cleared['attributes'][]=['key'=>'mac','value'=>'invalid-mac','customAttributeId'=>4];$services[43]=$cleared;
    evidenceCheck(!$api->inspect(42)['eligible'],'Malformed nonempty connection identity was classified as cleared');
    $cleared=$entity(43,99,1);$cleared['attributes']=[];$cleared['attributes'][]=['key'=>'duid','value'=>null,'customAttributeId'=>5];$services[43]=$cleared;$attributes[4]['attributeType']='client';
    $mapping=$api->inspect(42);evidenceCheck(!$mapping['eligible']&&$mapping['reason']==='resolution_mapping_unverified','Non-service old identity mapping authorized detached ownership');$attributes[4]['attributeType']='service';
    $services[43]=evidenceHttp(500);$catalog=[$target];evidenceCheck(!$api->inspect(42)['eligible'],'Unreadable canonical owner authorized reuse of its local account snapshot');
    $jobs->exec('DELETE FROM jobs;DELETE FROM cancellation_cleanups;DELETE FROM service_versions');$jobs->close();$jobs=null;unlink('data/jobs.db');
    $cleared=$entity(43,99,1);$cleared['attributes']=[];$services[43]=$cleared;$catalog=[$target,$cleared];
    $rows=[['.id'=>'*C','name'=>'fixture-user','comment'=>'99 - Former customer - 43','remote-address'=>'192.0.2.143']];$detachedMarker=$api->inspect(42);
    evidenceCheck($detachedMarker['eligible']&&$detachedMarker['autoApply']&&$detachedMarker['referencedOwners'][43]['state']==='disjoint','Cleared exact owner did not release its obsolete RouterOS marker');
    $hook=static function():void{throw new RuntimeException('resolution_router_unavailable');};$routerFailure=$api->inspect(42);$hook=null;
    evidenceCheck(!$routerFailure['eligible']&&$routerFailure['reason']==='resolution_router_unavailable','Router read failure was collapsed into a generic resolution failure');
    $rows=$oldRows;$catalog=[$target];
    // Full catalog pagination discovers competing services beyond page1, even for an uncached installation.
    $catalog=[$target];for($sid=1000;$sid<1499;$sid++)$catalog[]=$entity($sid,99,2,'Other','irrelevant');$catalog[]=$entity(2000,99,1);
    $services[2000]=$catalog[500];$paged=$api->inspect(42);evidenceCheck(!$paged['eligible'],'Late catalog page active claimant was omitted');
    $pages=array_values(array_filter($calls,static fn($call)=>$call[0]==='clients/services'));evidenceCheck(end($pages)[1]['offset']===500,'Complete ownership catalog was not paginated');$catalog=[$target];
    $db->execute('UPDATE devices SET ip=? WHERE id=1',['router.fixture.invalid']);
    $db->execute('UPDATE devices SET ip=? WHERE id=2',['ROUTER.fixture.invalid.']);
    $services[43]=$entity(43,99,1,'Other','fixture-user');$catalog=[$target,$services[43]];
    $alias=$api->inspect(42);evidenceCheck(!$alias['eligible']&&$alias['reason']==='resolution_owner_active',
        'A trailing DNS dot or hostname casing hid a current owner on the same locked endpoint');
    $db->execute('UPDATE devices SET ip=? WHERE id=1',['192.0.2.1']);$db->execute('UPDATE devices SET ip=? WHERE id=2',['192.0.2.2']);$catalog=[$target];
    // DHCP duplicates on different servers are not the same account and cannot be collapsed.
    $target['attributes'][]=['key'=>'mac','value'=>'02:00:00:00:00:42','customAttributeId'=>4];$services[42]=$target;$catalog=[$target];
    $rows=[['.id'=>'*A','mac-address'=>'02:00:00:00:00:42','comment'=>'17 - Fresh Customer - 42','server'=>'lan-a','address'=>'192.0.2.7'],
        ['.id'=>'*B','mac-address'=>'02:00:00:00:00:42','comment'=>'17 - Fresh Customer - 42','server'=>'lan-b','address'=>'192.0.2.8']];
    $servers=$api->inspect(42);evidenceCheck(!$servers['eligible']&&$servers['reason']==='resolution_dhcp_server_ambiguous','Different DHCP servers were merged into one identity');
    $rows[1]['server']='lan-a';evidenceCheck($api->inspect(42)['eligible'],'Exact same-server owned DHCP duplicates could not be reviewed');
    $rows=array_slice($rows,0,1);$queueRows=[['.id'=>'*C','name'=>'Fresh Customer - 42','comment'=>'17 - Fresh Customer - 42','target'=>'192.0.2.7/32'],
        ['.id'=>'*D','name'=>'Fresh Customer - 42','comment'=>'17 - Fresh Customer - 42','target'=>'192.0.2.8/32']];
    $queues=$api->inspect(42);evidenceCheck($queues['eligible']&&$queues['derivedRepairs'][0]['removeRowIds']===['*D']&&$queues['derivedRepairs'][0]['keepRowId']==='*C',
        'Canonical same-owner DHCP queue duplicates were not included in reviewed repair');
    $queueRows[1]['comment']='Another customer';$foreignQueue=$api->inspect(42);evidenceCheck(!$foreignQueue['eligible']&&$foreignQueue['reason']==='resolution_derived_ownership_unverified','Manual/foreign queue was offered for duplicate removal');$queueRows=[];
    // Config/transport change during observation invalidates the complete decision.
    $hook=static function()use($db):void{$db->execute('UPDATE devices SET port=8729 WHERE id=1');};$changed=$api->inspect(42);
    evidenceCheck(!$changed['eligible']&&$changed['reason']==='resolution_configuration_changed','Endpoint changed during observation retained repair permission');$hook=null;$db->execute('UPDATE devices SET port=8728 WHERE id=1');
    // Competing identities must use exactly the same normalization as provisioning.
    $target=$entity(42);$target['attributes'][]=['key'=>'mac','value'=>'02:00:00:00:00:42','customAttributeId'=>4];$services[42]=$target;
    $peer=$entity(43,99,1,'Selected','different-user');$peer['attributes'][]=['key'=>'mac','value'=>' 02:00:00:00:00:42 ','customAttributeId'=>4];
    $services[43]=$peer;$catalog=[$target,$peer];$rows=[['.id'=>'*A','mac-address'=>'02:00:00:00:00:42','comment'=>'Manual account','server'=>'lan-a','address'=>'192.0.2.7']];
    $normalizedClaim=$api->inspect(42);evidenceCheck(!$normalizedClaim['eligible']&&$normalizedClaim['reason']==='resolution_owner_active',
        'Whitespace-padded current MAC claim allowed another service to adopt its manual router account');
    $target=$entity(42);$services[42]=$target;$peer=$entity(43,99);$peer['attributes'][]=['key'=>'hotspot','value'=>' 0 ','customAttributeId'=>7];
    $services[43]=$peer;$catalog=[$target,$peer];$rows=[['.id'=>'*A','name'=>'fixture-user','comment'=>'Manual account','remote-address'=>'192.0.2.7']];
    $paddedFlag=$api->inspect(42);evidenceCheck(!$paddedFlag['eligible']&&$paddedFlag['reason']==='resolution_owner_active',
        'A padded false Hotspot flag hid a current competing PPP claim');
    $peer=$entity(43,99,1,'Selected',' fixture-user ');$services[43]=$peer;$catalog=[$target,$peer];
    evidenceCheck($api->inspect(42)['eligible'],'Literal username whitespace was incorrectly trimmed into a competing account');
    $catalog=[$target];
    // Hotspot and exact DUID+IAID select their own native table instead of borrowing PPP/MAC identity.
    $target=$entity(42);$target['attributes'][]=['key'=>'hotspot','value'=>'1','customAttributeId'=>7];$services[42]=$target;$catalog=[$target];
    $rows=[['.id'=>'*A','name'=>'fixture-user','comment'=>'17 - Fresh Customer - 42','address'=>'192.0.2.7']];
    $hotspot=$api->inspect(42);evidenceCheck($hotspot['eligible']&&$hotspot['path']==='ip/hotspot/user'&&$hotspot['selector']===['name'=>'fixture-user'],'Hotspot account reused PPP evidence');
    $target=$entity(42);$target['attributes']=array_values(array_filter($target['attributes'],static fn($attribute)=>!in_array($attribute['key'],['account','password'],true)));
    $target['attributes'][]=['key'=>'duid','value'=>'00:01:00:01:AA:BB:CC:DD','customAttributeId'=>5];$target['attributes'][]=['key'=>'iaid','value'=>'0x01','customAttributeId'=>6];
    $services[42]=$target;$catalog=[$target];$rows=[['.id'=>'*A','duid'=>'00010001aabbccdd','iaid'=>'1','comment'=>'17 - Fresh Customer - 42','server'=>'v6-lan','address'=>'2001:db8::/64']];
    $v6=$api->inspect(42);evidenceCheck($v6['eligible']&&$v6['path']==='ipv6/dhcp-server/binding'&&$v6['selector']===['duid'=>'00010001aabbccdd','iaid'=>'1'],'DUID+IAID binding selector was not exact/canonical');
    $rows[0]['iaid']='2';evidenceCheck(!$api->inspect(42)['eligible'],'Binding from a different IAID was accepted');$rows[0]['iaid']='1';
    $clients[17]['isArchived']=true;evidenceCheck(!$api->inspect(42)['eligible'],'Archived target client received new provisioning');$clients[17]['isArchived']=false;
    $services[42]['status']=3;$catalog=[$services[42]];evidenceCheck($api->inspect(42)['eligible'],'Suspended target could not be reconciled without reactivation');
    $emptyRows=$rows;$rows=[];evidenceCheck($api->inspect(42)['autoApply'],'Absent account did not use ordinary immediate reconciliation');$rows=$emptyRows;
    echo "Service resolution evidence passed: fresh exact target/client, exclusive complete catalog, changed IP/CID markers, retired/disjoint/cleared old claims, explicit row selection, protected DHCP servers/queue duplicates, private fingerprints and read-only bounded observation.\n";
}finally{if($jobs instanceof SQLite3)$jobs->close();if($db instanceof ApiSqlite)$db->close();chdir($original);set_include_path($oldInclude);evidenceRemove($directory);}
