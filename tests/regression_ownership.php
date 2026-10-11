<?php
// Real MT lookup/write logic with an in-memory RouterOS response catalogue; no sockets or credentials.
require_once __DIR__ . '/../src/lib/api_sqlite.php';
require_once __DIR__ . '/../src/lib/api_ip.php';
require_once __DIR__ . '/../src/lib/mt.php';
require_once __DIR__ . '/../src/lib/mt_data.php';
class OwnershipLog { public function Append($message, $level=0): void {} }
function MyLog() { static $log; return $log ??= new OwnershipLog(); }
function checkOwnership(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
class OwnershipAPI extends RouterosAPI {
    public array $words=[];
    public $afterResponse=null;
    public function write($word,$param2=true) { $this->words[]=$word; return true; }
    public function finishSentence(): void { $this->words[]='<end>'; }
    public function read($parse=true) {
        if ($this->afterResponse) ($this->afterResponse)($this->words);
        return [];
    }
    public function disconnect() { $this->connected=false; }
}
class CatalogueMT extends MT {
    public array $catalogue=[];
    public OwnershipAPI $recording;
    public function __construct() { parent::__construct(); $this->recording=new OwnershipAPI(); }
    protected function api_connect(): ?RouterosAPI { $this->recording->connected=true; return $this->recording; }
    protected function read($path,$filter=null) {
        $rows=$this->catalogue[trim($path,'/')] ?? [];
        if (!$filter) return $rows;
        return array_values(array_filter($rows,static function($row) use($filter): bool {
            foreach((array)$filter as $key=>$value) if(($row[$key] ?? null)!=$value) return false;
            return true;
        }));
    }
    public function execute(array $command) { return $this->write($command); }
}
$apiSqlite=new ApiSqlite(':memory:');
$apiSqlite->exec(file_get_contents(__DIR__.'/../src/includes/schema.sql'));
$service=['id'=>91,'clientId'=>70,'planId'=>1,'status'=>1];
$commands=MtData::safePreparedCommands($service,[['path'=>'/ppp/secret','name'=>'shared-user','password'=>'fixture',
    'profile'=>'uisp-plan-1','comment'=>'70 - Fresh Client - 91','batch'=>'service-91']]);
$account=array_values(array_filter($commands,static fn($command)=>trim($command['path'],'/')==='ppp/secret'))[0];
$router=new CatalogueMT();
$router->catalogue['ppp/secret']=[['.id'=>'*foreign','name'=>'shared-user','comment'=>'69 - Other Client - 90']];
$rejected=false;
try { $router->execute($account); } catch(Throwable $error) { $rejected=true; }
checkOwnership($rejected,'Another service account was overwritten merely because its username matched');
checkOwnership(!in_array('/ppp/secret/set',$router->recording->words,true),'Ownership rejection occurred after the account was changed');
function guardedOwnership(array $command): array {
    global $service;
    $commands=MtData::safePreparedCommands($service,[$command]);
    return array_values(array_filter($commands,static fn($item)=>$item['path']===$command['path']))[0];
}
foreach ([
    '/ppp/secret'=>['name'=>'shared-user'],
    '/ip/hotspot/user'=>['name'=>'shared-user'],
    '/ip/dhcp-server/lease'=>['mac-address'=>'02:00:00:00:00:91'],
    '/ipv6/dhcp-server/binding'=>['duid'=>'0x00030001020000000091','iaid'=>0],
    '/queue/simple'=>['name'=>'Fresh Client - 91'],
] as $path=>$identity) {
    foreach (['manual record','69 - Other Client - 90','70 - Fresh Client - 191'] as $foreign) {
        $command=guardedOwnership(['path'=>$path,'comment'=>'70 - Fresh Client - 91','batch'=>'service-91']+$identity);
        $router=new CatalogueMT(); $router->catalogue[trim($path,'/')]=[['.id'=>'*record','dynamic'=>'false','comment'=>$foreign]+$identity];
        $rejected=false; try {$router->execute($command);} catch(Throwable $error) {$rejected=true;}
        checkOwnership($rejected && $router->recording->words===[], 'A foreign account/lease/queue was mutated: '.$path);
    }
    $command=guardedOwnership(['path'=>$path,'comment'=>'70 - Renamed Client - 91','batch'=>'service-91']+$identity);
    $router=new CatalogueMT(); $router->catalogue[trim($path,'/')]=[['.id'=>'*owned','dynamic'=>'false','comment'=>'70 - Original Client - 91']+$identity];
    $router->execute($command);
    checkOwnership(in_array('/'.trim($path,'/').'/set',$router->recording->words,true),'An exactly owned legacy record was blocked: '.$path);
    foreach($router->recording->words as $word) checkOwnership(strpos($word,'=_service_id=')!==0 && strpos($word,'=_client_id=')!==0,'Ownership metadata was sent to RouterOS');
}
foreach (['/ip/dhcp-server/lease'=>['mac-address'=>'02:00:00:00:00:91'],
    '/ipv6/dhcp-server/binding'=>['duid'=>'0x00030001020000000091','iaid'=>0]] as $path=>$identity) {
    $command=guardedOwnership(['path'=>$path,'comment'=>'70 - Fresh Client - 91','batch'=>'service-91']+$identity);
    $router=new CatalogueMT(); $router->catalogue[trim($path,'/')]=[['.id'=>'*dynamic','dynamic'=>'true','comment'=>'']+$identity];
    $router->execute($command);
    checkOwnership(in_array('/'.trim($path,'/').'/make-static',$router->recording->words,true),'An unclaimed dynamic lease was not adopted: '.$path);
    foreach(['','69 - Other Client - 90'] as $comment) {
        $command['action']='remove';
        $router=new CatalogueMT(); $router->catalogue[trim($path,'/')]=[['.id'=>'*dynamic','dynamic'=>'true','comment'=>$comment]+$identity];
        $rejected=false; try{$router->execute($command);}catch(Throwable $error){$rejected=true;}
        checkOwnership($rejected && $router->recording->words===[],'A delete adopted or removed a dynamic foreign lease: '.$path);
    }
}
foreach (['/ppp/active'=>'ppp/secret','/ip/hotspot/active'=>'ip/hotspot/user'] as $path=>$principal) {
    $command=guardedOwnership(['path'=>$path,'name'=>'shared-user','action'=>'remove','batch'=>'service-91']);
    $activeIdentity=trim($path,'/')==='ip/hotspot/active' ? 'user' : 'name';
    $router=new CatalogueMT(); $router->catalogue[trim($path,'/')]=[['.id'=>'*session',$activeIdentity=>'shared-user']];
    $router->execute($command);
    checkOwnership($router->recording->words===[],'An active session was disconnected while its principal was absent');
    $router=new CatalogueMT(); $router->catalogue[$principal]=[['.id'=>'*principal','name'=>'shared-user','comment'=>'69 - Other Client - 90']];
    $router->catalogue[trim($path,'/')]=[['.id'=>'*session',$activeIdentity=>'shared-user']];
    $rejected=false; try{$router->execute($command);}catch(Throwable $error){$rejected=true;}
    checkOwnership($rejected && $router->recording->words===[],'An active session belonging to a foreign principal was disconnected');
    $router=new CatalogueMT(); $router->catalogue[$principal]=[['.id'=>'*principal','name'=>'shared-user','comment'=>'70 - Fresh Client - 91']];
    $router->catalogue[trim($path,'/')]=[['.id'=>'*session',$activeIdentity=>'shared-user']];
    $router->execute($command);
    checkOwnership(in_array('/'.trim($path,'/').'/remove',$router->recording->words,true),'An owned session was not disconnected');
    $router=new CatalogueMT(); $router->catalogue[$principal]=[['.id'=>'*principal','name'=>'shared-user','comment'=>'70 - Fresh Client - 91']];
    $router->catalogue[trim($path,'/')]=[['.id'=>'*session',$activeIdentity=>'shared-user']];
    $remove=guardedOwnership(['path'=>'/'.$principal,'name'=>'shared-user','action'=>'remove','batch'=>'service-91']);
    $router->recording->afterResponse=static function($words) use($router,$principal): void {
        if(in_array('/'.$principal.'/remove',$words,true)) $router->catalogue[$principal]=[];
    };
    checkOwnership($router->do_batch((object)[],[$remove,$command])===2
        && in_array('/'.trim($path,'/').'/remove',$router->recording->words,true),
        'Deleting an owned principal lost the authorization to disconnect its session');
}
$router=new CatalogueMT();
$router->catalogue['ppp/secret']=[['.id'=>'*foreign','name'=>'shared-user','comment'=>'69 - Other Client - 90']];
$cap=['path'=>'/queue/simple','name'=>'uisp-service-91-suspension','target'=>'192.0.2.91','max-limit'=>'1M/1M',
    'comment'=>'uisp-ros-plugin:suspension:91','_owner'=>'uisp-ros-plugin:suspension:91','batch'=>'service-91'];
checkOwnership($router->do_batch((object)[],[$cap,$account])===0 && $router->recording->words===[],
    'The suspension cap was installed before the batch discovered its foreign account');
$legacyParents=MtData::safePreparedCommands($service,[
    ['path'=>'/queue/simple','name'=>'servicePlan-1-parent','target'=>'192.0.2.91','max-limit'=>'20M/50M','comment'=>'do not delete'],
    ['path'=>'/ppp/profile','name'=>'legacy-profile','parent-queue'=>'servicePlan-1-parent'],
]);
checkOwnership($legacyParents[0]['name']==='uisp-plan-1-parent'
    && $legacyParents[0]['_owner']==='uisp-ros-plugin:plan-parent:1'
    && $legacyParents[1]['parent-queue']==='uisp-plan-1-parent',
    'A legacy shared parent was not migrated to a verifiably owned name');
$legacyRemove=MtData::safePreparedCommands($service,[['path'=>'/queue/simple','name'=>'servicePlan-1-parent','action'=>'remove']], 'delete');
checkOwnership($legacyRemove===[],'A legacy shared parent was removed without proof of ownership');
$router=new CatalogueMT();
$router->catalogue['queue/simple']=[['.id'=>'*foreign-parent','name'=>'uisp-plan-1-parent','comment'=>'manual queue']];
$rejected=false; try{$router->execute($legacyParents[0]);}catch(Throwable $error){$rejected=true;}
checkOwnership($rejected && $router->recording->words===[],'A manual queue using the new reserved parent name was overwritten');
$newParent=MtData::safePreparedCommands($service,[['path'=>'/queue/simple','name'=>'uisp-plan-1-parent','target'=>'192.0.2.91']])[0];
checkOwnership(!isset($newParent['_service_id']) && $newParent['_owner']==='uisp-ros-plugin:plan-parent:1',
    'A shared parent was assigned the ownership of only one of its clients');
echo "Ownership regression passed: PPP/Hotspot/DHCP/DHCPv6/queue conflicts, exact legacy ownership, dynamic adoption, session principals and complete preflight.\n";
$apiSqlite->close();
