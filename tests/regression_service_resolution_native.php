<?php
// Native RouterOS binary replies over isolated loopback sockets; no router or UISP is contacted.
set_error_handler(static function($severity,$message,$file,$line){if(error_reporting()&$severity)throw new ErrorException($message,0,$severity,$file,$line);return false;});
$source=realpath(__DIR__.'/../src');$original=getcwd();$oldInclude=get_include_path();
$directory=sys_get_temp_dir().'/uisp-resolution-native-'.bin2hex(random_bytes(8));mkdir($directory,0700);mkdir($directory.'/data',0700);mkdir($directory.'/vendor',0700);
file_put_contents($directory.'/vendor/autoload.php','<?php');require_once$source.'/vendor/autoload.php';
set_include_path($source.'/lib'.PATH_SEPARATOR.$oldInclude);chdir($directory);
require_once$source.'/lib/api_service_resolution.php';require_once$source.'/lib/routeros_api.class.php';
function nativeCheck(bool$ok,string$message):void{if(!$ok)throw new RuntimeException($message);}
function nativeRemove(string$path):void{foreach(new FilesystemIterator($path,FilesystemIterator::SKIP_DOTS)as$entry){if($entry->isDir()&&!$entry->isLink())nativeRemove($entry->getPathname());else unlink($entry->getPathname());}rmdir($path);}
// Do not replace RouterosAPI::read: the protocol reader must see actual length-prefixed words.
function nativePrint(ApiServiceResolution$service,string$path,array$filter,array$fields,array$words,bool$closeReply=false):array
{
    $server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
    if(!$server)throw new RuntimeException('Cannot create isolated RouterOS protocol fixture');
    $peer=stream_socket_client('tcp://'.stream_socket_get_name($server,false),$errno,$error,2);
    $socket=stream_socket_accept($server,2);fclose($server);
    if(!$peer||!$socket)throw new RuntimeException('Cannot connect isolated RouterOS protocol fixture');
    $api=new RouterosAPI();$api->socket=$socket;$api->connected=true;stream_set_timeout($socket,2);
    try{
        $frame='';$previous=null;
        foreach($words as$word){
            // Valid replies terminate every !re/!empty sentence before !done.
            // Content after !done intentionally stays in its sentence so tests
            // can still present a malformed duplicate done or trailing property.
            if($frame!==''&&$previous!=='!done'&&in_array($word,['!re','!empty','!done'],true))$frame.=chr(0);
            $frame.=$api->encodeLength(strlen($word)).$word;$previous=$word;
        }
        fwrite($peer,$frame.chr(0));
        if($closeReply){fclose($peer);$peer=null;}
        $native=new ReflectionMethod(ApiServiceResolution::class,'nativeRead');if(PHP_VERSION_ID<80100)$native->setAccessible(true);
        return$native->invoke($service,$api,$path,$filter,$fields);
    }finally{$api->disconnect();if(is_resource($peer))fclose($peer);}
}
$db=null;
try{
    $db=new ApiSqlite('data/data.db');$db->exec(file_get_contents($source.'/includes/schema.sql'));
    $db->saveConfig(['device_name_attr'=>'site','pppoe_user_attr'=>'account','pppoe_pass_attr'=>'password','ip_addr_attr'=>'ipv4']);
    $db->insert(['id'=>1,'name'=>'Fixture Site','type'=>'mikrotik','ip'=>'192.0.2.1','port'=>8728,
        'user'=>'PRIVATE-USER','password'=>'PRIVATE-ROUTER','pool'=>'192.0.2.0/24'],'devices');
    $attributes=[];foreach(['site','account','password','ipv4']as$i=>$key)$attributes[]=['id'=>$i+1,'key'=>$key,'attributeType'=>'service'];
    $raw=['id'=>42,'clientId'=>17,'status'=>1,'servicePlanId'=>9,'attributes'=>[
        ['key'=>'site','value'=>'Fixture Site'],['key'=>'account','value'=>'fixture-account'],
        ['key'=>'password','value'=>'PRIVATE-ACCOUNT'],['key'=>'ipv4','value'=>'192.0.2.42']]];
    $request=static function(string$path,array$params=[])use($raw,$attributes){
        if($path==='custom-attributes')return$attributes;
        if($path==='clients/services/42')return$raw;
        if($path==='clients/17')return['id'=>17,'isArchived'=>false,'firstName'=>'Fixture','lastName'=>'Customer'];
        if($path==='clients/services')return array_slice([$raw],(int)$params['offset'],(int)$params['limit']);
        throw new RuntimeException('PRIVATE-HTTP-FAILURE');
    };
    $probe=new ApiServiceResolution($request);$fields=['.id','name'];
    foreach([['!done'],['!empty','!done']]as$reply)
        nativeCheck(nativePrint($probe,'ppp/secret',['name'=>'fixture-account'],$fields,$reply)===[],
            'A complete native empty response was not accepted as an absent account');
    $decoded=nativePrint($probe,'ppp/secret',['name'=>'fixture-account'],$fields,
        ['!re','=.id=*A1','=name=fixture-account','=password=PRIVATE-EXTRA','!done']);
    nativeCheck($decoded===[['.id'=>'*A1','name'=>'fixture-account']],'Native decoding retained unrequested fields');
    foreach([['!empty','!done','=name=extra'],['!trap','=message=PRIVATE-ERROR','!done'],['!empty','=message=PRIVATE-ERROR','!done'],
        ['!re','=.id=*A1','!empty','!done'],['!empty','!empty','!done'],['!done','!done']]as$reply){
        try{nativePrint($probe,'ppp/secret',['name'=>'fixture-account'],$fields,$reply);throw new LogicException('Malformed native print accepted');}
        catch(RuntimeException$error){nativeCheck($error->getMessage()==='resolution_router_unavailable','Native print exposed a raw router detail');}
    }
    foreach([['!empty'],['!re','=.id=*A1','=name=fixture-account']]as$incomplete){
        $rejected=false;
        try{nativePrint($probe,'ppp/secret',['name'=>'fixture-account'],$fields,$incomplete,true);}
        catch(Throwable$error){$rejected=true;}
        nativeCheck($rejected,'An incomplete native response without !done was accepted');
    }
    $base=['.id'=>'*A1','name'=>'fixture-account','comment'=>'17 - Fixture Customer - 42','remote-address'=>'192.0.2.42'];
    $rows=[$base];$replyOverride=[];$calls=[];
    $reader=static function(array$device,string$path,array$selector,array$fields)use($probe,&$rows,&$replyOverride,&$calls):array{
        $calls[]=$path;
        if(isset($replyOverride[$path]))return nativePrint($probe,$path,$selector,$fields,$replyOverride[$path]);
        $found=$path==='ppp/secret'?$rows:[];$words=[];
        foreach($found as$row){$words[]='!re';foreach($row as$key=>$value)$words[]='='.$key.'='.$value;}
        if(!$found)$words[]='!empty';$words[]='!done';
        return nativePrint($probe,$path,$selector,$fields,$words);
    };
    $resolution=new ApiServiceResolution($request,$reader);
    $hash=hash_file('sha256','data/data.db');$preview=$resolution->preview(42);
    nativeCheck($preview['eligible']&&$preview['autoApply']&&$calls===['ppp/secret','queue/simple'],
        'An existing principal was blocked by the modern empty derived-queue response: '.json_encode($preview));
    $rows=[];$preview=$resolution->preview(42);
    nativeCheck($preview['eligible']&&$preview['autoApply']&&$preview['candidates']===[],
        'An absent principal could not reconcile after the modern empty response: '.json_encode($preview));
    nativeCheck(hash_file('sha256','data/data.db')===$hash&&!is_file('data/jobs.db')&&!is_file('data/cache.db'),
        'Native review mutated business databases or created queue state');
    $rows=[$base];
    foreach(['ppp/secret','queue/simple']as$failedPath){
        $replyOverride=[$failedPath=>['!trap','=message=PRIVATE-ERROR','!done']];$preview=$resolution->preview(42);
        nativeCheck(!$preview['eligible']&&$preview['previewToken']===null&&!str_contains(json_encode($preview),'PRIVATE'),
            'A failed native observation offered a resolution token or disclosed raw details');
    }
    echo "Native service resolution regression checks passed (modern empty replies, existing/absent principal, derived queues and malformed replies).\n";
}finally{
    if($db)$db->close();chdir($original);set_include_path($oldInclude);nativeRemove($directory);
}
