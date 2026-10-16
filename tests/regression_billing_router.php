<?php
// Real ApiAction -> durable queue -> Batch -> MtData -> MT against an isolated RAM RouterOS adapter.
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
function billingCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$source = realpath(__DIR__ . '/../src');
$original = getcwd();
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-billing-router-' . bin2hex(random_bytes(8));
mkdir($directory,0700);
foreach (['lib','includes','data','vendor'] as $folder) mkdir($directory . '/' . $folder,0700);
foreach (glob($source . '/lib/*.php') as $file) copy($file,$directory . '/lib/' . basename($file));
foreach (glob($source . '/includes/*') as $file) if (is_file($file)) copy($file,$directory . '/includes/' . basename($file));
file_put_contents($directory . '/vendor/autoload.php', <<<'PHP'
<?php
namespace Ubnt\UcrmPluginSdk\Service;
class UcrmApi {
    public static array $requests=[];
    public static array $client=['id'=>70,'isArchived'=>false,'firstName'=>'Fixture','lastName'=>'Client'];
    public static function create() { return new self(); }
    public function get($path,$options=[]) {
        if ($path==='clients/70') return self::$client;
        if ($path==='clients/services') return array_map(fn($id)=>$this->get('clients/services/'.$id),array_keys(self::$requests));
        if (preg_match('#^clients/services/([1-9][0-9]*)$#D',$path,$match) && isset(self::$requests[(int)$match[1]])) {
            $request=self::$requests[(int)$match[1]]; $entity=clone $request->extraData->entity;
            $entity->status=['suspend'=>3,'unsuspend'=>1,'suspend_cancel'=>1,'activate'=>1,'end'=>2,'delete'=>2][$request->changeType]??$entity->status;
            return $entity;
        }
        throw new \RuntimeException('Fixture external network prohibited');
    }
    public function patch($path,$data=[]) { throw new \RuntimeException('Fixture external network prohibited'); }
}
PHP);
// Inject only the adapter constructor into the disposable Batch copy. No MT::do_batch/write/preflight is stubbed.
$batchFile=$directory . '/lib/batch.php';
$batchCode=str_replace('new MT()', 'new BillingMT()', file_get_contents($batchFile), $factoryChanges);
billingCheck($factoryChanges===1, 'The fixture MT constructor hook was not precise');
file_put_contents($batchFile,$batchCode);
chdir($directory);
set_include_path($directory . '/lib' . PATH_SEPARATOR . get_include_path());
require 'lib/api_common.php';
require 'lib/api_setup.php';
require 'lib/api_action.php';
require 'lib/api_trim.php';

class BillingRouterAPI extends RouterosAPI {
    public array $sentences=[];
    private array $pending=[];
    private array $response=[];
    private $apply;
    public function __construct(callable $apply) { $this->apply=$apply; }
    public function write($word,$param2=true) { $this->pending[]=$word; return true; }
    public function finishSentence(): void {
        $this->sentences[]=$this->pending;
        $this->response=($this->apply)($this->pending);
        $this->pending=[];
    }
    public function read($parse=true) { $response=$this->response; $this->response=[]; return $response; }
    public function disconnect() { $this->connected=false; }
}
class BillingMT extends MT {
    public array $catalogue=[];
    public BillingRouterAPI $recording;
    public function __construct() {
        parent::__construct();
        $this->catalogue =& $GLOBALS['billingCatalogue'];
        $GLOBALS['billingAdapters'][]=$this;
        $this->recording=new BillingRouterAPI(function (array $words): array {
            $path=trim(array_shift($words),'/');
            $separator=strrpos($path,'/');
            $action=substr($path,$separator+1); $path=substr($path,0,$separator);
            $values=[];
            foreach ($words as $word) {
                if (strpos($word,'=')!==0) continue;
                [$key,$value]=explode('=',substr($word,1),2); $values[$key]=$value;
            }
            if ($path==='ppp/active' && $action==='remove' && !empty($GLOBALS['billingFailReset']))
                return ['!trap'=>[['message'=>'fixture failure after account set before reset']]];
            // RouterOS rejects a PPP secret's reference to an absent external profile.
            if ($path==='ppp/secret' && $action!=='remove' && isset($values['profile'])) {
                $matches=array_filter($this->catalogue['ppp/profile'] ?? [],static fn($row)=>($row['name']??null)===$values['profile']);
                if (!$matches) return ['!trap'=>[['message'=>'input does not match any value of profile']]];
            }
            if ($action==='set') {
                foreach ($this->catalogue[$path] ?? [] as $index=>$row)
                    if (($row['.id']??null)===($values['.id']??null)) $this->catalogue[$path][$index]=array_replace($row,$values);
            } elseif ($action==='add') {
                $GLOBALS['billingNextRouterId']=($GLOBALS['billingNextRouterId']??0)+1;
                $this->catalogue[$path][]=['.id'=>'*ram-' . $GLOBALS['billingNextRouterId']] + $values;
            } elseif ($action==='remove') {
                $ids=explode(',',$values['.id']??'');
                $this->catalogue[$path]=array_values(array_filter($this->catalogue[$path]??[],static fn($row)=>!in_array($row['.id']??null,$ids,true)));
            }
            return [];
        });
    }
    protected function api_connect(): ?RouterosAPI { $this->recording->connected=true; return $this->recording; }
    protected function read($path,$filter=null) {
        $path=trim($path,'/');
        if ($path==='ppp/profile' && !empty($GLOBALS['billingFailProfileRead'])) return null;
        if (is_string($filter)) {
            $conditions=[];
            foreach (explode(',',$filter) as $item) { [$key,$value]=explode('=',ltrim($item,'?'),2); $conditions[$key]=$value; }
            $filter=$conditions;
        }
        return array_values(array_filter($this->catalogue[$path]??[],static function ($row) use ($filter): bool {
            foreach ((array)$filter as $key=>$value) if ((string)($row[$key]??'')!==(string)$value) return false;
            return true;
        }));
    }
}
try {
    $apiSqlite=new ApiSqlite('data/data.db');
    $apiSqlite->exec(file_get_contents('includes/schema.sql'));
    (new ApiSetup())->config_load();
    $apiSqlite->saveConfig(['version'=>MY_VERSION,'disable_contention'=>true,'auto_ppp_user'=>false,
        'device_name_attr'=>'site','pppoe_user_attr'=>'user','pppoe_pass_attr'=>'pass',
        'disabled_profile'=>'paused-fixture','disabled_list'=>'paused-list-fixture','disabled_rate'=>0]);
    file_put_contents('data/config.json',json_encode(['debugEnable'=>false]));
    $apiSqliteCache=new ApiSqlite('data/cache.db');
    $apiSqliteCache->exec(file_get_contents('includes/cache.sql'));
    $queue=myJobQueue();
    $apiSqlite->insert(['id'=>10,'name'=>'RAM Router','type'=>'mikrotik','ip'=>'192.0.2.10',
        'user'=>'fixture','password'=>'fixture','pool'=>'10.42.0.0/24'],'devices');
    $apiSqlite->insert(['id'=>1,'name'=>'RAM Plan','ratio'=>1,'uploadSpeed'=>7,'downloadSpeed'=>21],'plans');
    $apiSqliteCache->insert(['id'=>70,'firstName'=>'Fixture','lastName'=>'Client'],'clients');
    $GLOBALS['billingCatalogue']=[
        'ip/address'=>[['.id'=>'*lan','address'=>'192.0.2.1/24','disabled'=>'false','dynamic'=>'false','invalid'=>'false']],
        'ppp/profile'=>[['.id'=>'*paused','name'=>'paused-fixture','address-list'=>'paused-list-fixture','rate-limit'=>'64k/64k']],
    ];
    $GLOBALS['billingAdapters']=[];
    $makeRequest=static function (string $action,int $status,?int $before=null): object {
        static $sequence=0;
        $entity=(object)['id'=>91,'clientId'=>70,'status'=>$status,'servicePlanId'=>1,'uploadSpeed'=>7,'downloadSpeed'=>21,
            'attributes'=>[(object)['key'=>'site','value'=>'RAM Router'],(object)['key'=>'user','value'=>'ram-user'],
                (object)['key'=>'pass','value'=>'ram-pass']]];
        $extra=(object)['entity'=>$entity];
        if ($before!==null) { $extra->entityBeforeEdit=clone $entity; $extra->entityBeforeEdit->status=$before; }
        $request=(object)['entity'=>'service','entityId'=>'91','changeType'=>$action,
            'uuid'=>'billing-router-fixture-' . ++$sequence,'extraData'=>$extra];
        \Ubnt\UcrmPluginSdk\Service\UcrmApi::$requests[91]=$request;
        return $request;
    };
    $transition=static function (array $step,bool $shouldDisconnect,bool $shouldWrite=true) use ($makeRequest,$queue): void {
        $GLOBALS['billingCatalogue']['ppp/active']=[['.id'=>'*session','name'=>'ram-user']];
        $start=count($GLOBALS['billingAdapters']);
        $action=new ApiAction($makeRequest(...$step)); $action->exec();
        $applied=$queue->appliedSnapshot(91); $words=[];
        foreach (array_slice($GLOBALS['billingAdapters'],$start) as $router) $words=array_merge($words,$router->recording->sentences);
        $state=$step[0]==='suspend' ? 3 : ($step[0]==='unsuspend' ? 1 : $step[1]);
        $expectedProfile=$state===3 ? 'paused-fixture' : 'RAM Plan [UISP#1]';
        $accounts=array_values(array_filter($GLOBALS['billingCatalogue']['ppp/secret']??[],static fn($row)=>($row['name']??null)==='ram-user'));
        $account=$accounts[0]??[];
        billingCheck(!$action->status()->error && $action->result()['provisioning']===($shouldWrite ? 'applied' : 'already_applied')
            && $action->result()['desiredStatus']===$state && ($applied['status']??null)===$state,
            'Billing state was lost between ApiAction and its applied snapshot');
        billingCheck(($account['profile']??null)===$expectedProfile, 'Actual RAM RouterOS profile differs from billing state');
        $accountWrites=[]; $disconnects=[];
        foreach ($words as $sentence) {
            $path=$sentence[0]??'';
            billingCheck(strpos($path,'/ip/hotspot/')!==0 && strpos($path,'/ip/dhcp-server/')!==0,
                'A PPP billing event changed a different account protocol');
            if (in_array($path,['/ppp/secret/add','/ppp/secret/set'],true)) $accountWrites[]=$sentence;
            if ($path==='/ppp/active/remove') $disconnects[]=$sentence;
        }
        billingCheck(count($accountWrites)===($shouldWrite ? 1 : 0)
            && (!$shouldWrite || in_array('=profile=' . $expectedProfile,$accountWrites[0],true)),
            'MT repeated a paired edit or did not send the expected PPP profile write');
        billingCheck(count($disconnects)===($shouldDisconnect ? 1 : 0), 'Billing transition lost or repeated its session disconnect');
        billingCheck($queue->completed($action->result()['jobKeys']) && $action->result()['jobCounts']['pending']===0,
            'Billing mutation did not finish its actual queue job');
    };
    $transition(['insert',1,null],false);
    for ($cycle=0; $cycle<2; $cycle++) {
        $transition(['suspend',1,null],true);
        $transition(['edit',3,1],false,false);
        $transition(['unsuspend',3,null],true);
        $transition(['edit',1,3],false,false);
    }
    // A new explicit billing event forces its own reconnect even if its effective state already matches.
    $transition(['unsuspend',3,null],true);
    $transition(['suspend_cancel',1,null],true);
    $transition(['activate',1,null],true);
    $explicitRequest=$makeRequest('unsuspend',1);
    $GLOBALS['billingCatalogue']['ppp/active']=[['.id'=>'*explicit-session','name'=>'ram-user']];
    $explicit=new ApiAction($explicitRequest); $explicit->exec();
    billingCheck(!$explicit->status()->error && $GLOBALS['billingCatalogue']['ppp/active']===[],
        'A distinct same-state explicit event did not reconnect');
    $GLOBALS['billingCatalogue']['ppp/active']=[['.id'=>'*duplicate-session','name'=>'ram-user']];
    $adapterCount=count($GLOBALS['billingAdapters']);
    $duplicate=new ApiAction($explicitRequest); $duplicate->exec();
    billingCheck($duplicate->result()['provisioning']==='duplicate_completed'
        && count($GLOBALS['billingAdapters'])===$adapterCount && count($GLOBALS['billingCatalogue']['ppp/active'])===1,
        'Replaying the same completed UUID repeated its forced billing disconnect');
    billingCheck(!isset($queue->appliedSnapshot(91)['_force_reconnect']), 'A one-time explicit force leaked into the applied baseline');
    // A partially completed explicit event remains a debt even if its billing state already matched the baseline.
    $GLOBALS['billingCatalogue']['ppp/active']=[['.id'=>'*partial-session','name'=>'ram-user']];
    $GLOBALS['billingFailReset']=true;
    $partial=new ApiAction($makeRequest('unsuspend',1)); $partial->exec();
    billingCheck($partial->status()->error && $partial->result()['provisioning']==='pending'
        && $partial->result()['appliedStatus']===1, 'Partial explicit reconnect was falsely completed');
    $paired=new ApiAction($makeRequest('edit',1,3)); $paired->exec();
    billingCheck($paired->result()['provisioning']!=='already_applied' && $paired->result()['jobCounts']['known']>0,
        'Paired edit suppressed a still-pending explicit reconnect');
    $GLOBALS['billingFailReset']=false;
    $property=new ReflectionProperty(ApiJobQueue::class,'db');
    if (PHP_VERSION_ID<80100) $property->setAccessible(true);
    $queueDb=$property->getValue($queue); $queueDb->exec('UPDATE jobs SET next_attempt=0');
    $queue->run();
    billingCheck($queue->list()===[] && $GLOBALS['billingCatalogue']['ppp/active']===[],
        'Partial billing work lost its reconnect or left its following edit blocked');
    // The actual manual router API forces this router's sessions on every invocation and excludes other routers.
    $apiSqlite->insert(['id'=>11,'name'=>'Other RAM Router','type'=>'mikrotik','ip'=>'192.0.2.20',
        'user'=>'fixture','password'=>'fixture','pool'=>'10.43.0.0/24'],'devices');
    $apiSqliteCache->insert(['id'=>94,'clientId'=>70,'device'=>11,'planId'=>1,'status'=>1,
        'username'=>'other-ram-user','password'=>'other-ram-pass','uploadSpeed'=>7,'downloadSpeed'=>21],'services');
    $apiSqliteCache->insert(['id'=>94,'address'=>'10.43.0.94'],'network');
    $GLOBALS['billingCatalogue']['ppp/secret'][]=['.id'=>'*other','name'=>'other-ram-user','password'=>'other-ram-pass',
        'remote-address'=>'10.43.0.94','profile'=>'RAM Plan [UISP#1]','comment'=>'70 - Fixture Client - 94','caller-id'=>''];
    $GLOBALS['billingCatalogue']['ppp/active']=[['.id'=>'*other-baseline','name'=>'other-ram-user']];
    billingCheck((new Batch())->set_accounts([94]), 'Other router baseline did not initialize');
    require 'lib/api_update.php';
    for ($manual=0;$manual<2;$manual++) {
        $GLOBALS['billingCatalogue']['ppp/active']=[['.id'=>'*manual-session','name'=>'ram-user'],
            ['.id'=>'*other-session','name'=>'other-ram-user']];
        $start=count($GLOBALS['billingAdapters']);
        $update=new ApiUpdate((object)['action'=>'insert','data'=>(object)['type'=>'device','id'=>10]],'system'); $update->exec();
        $accountWrites=0; $disconnects=0;
        foreach (array_slice($GLOBALS['billingAdapters'],$start) as $router) foreach ($router->recording->sentences as $sentence) {
            if (($sentence[0]??'')==='/ppp/secret/set') {
                billingCheck(in_array('=name=ram-user',$sentence,true), 'Manual router sync wrote an account of another router');
                $accountWrites++;
            }
            if (($sentence[0]??'')==='/ppp/active/remove') $disconnects++;
        }
        billingCheck(!($update->status()->error??false) && $accountWrites===1 && $disconnects===1
            && $GLOBALS['billingCatalogue']['ppp/active']===[['.id'=>'*other-session','name'=>'other-ram-user']],
            'Manual router sync failed to force exactly its own confirmed session');
        billingCheck(!isset($queue->appliedSnapshot(91)['_force_reconnect']), 'Manual force persisted into the applied baseline');
    }
    $GLOBALS['billingCatalogue']['ppp/active']=[['.id'=>'*general-session','name'=>'ram-user'],
        ['.id'=>'*other-general','name'=>'other-ram-user']];
    $update=new ApiUpdate((object)['action'=>'insert','data'=>(object)['type'=>'all']],'system'); $update->exec();
    billingCheck(!($update->status()->error??false) && count($GLOBALS['billingCatalogue']['ppp/active'])===2,
        'A later general sync inherited one-time manual force');
    // A failed lookup must never be treated as evidence that a suspension profile may be created.
    $GLOBALS['billingCatalogue']['ppp/profile']=array_values(array_filter($GLOBALS['billingCatalogue']['ppp/profile'],
        static fn($row)=>($row['name']??null)!=='paused-fixture'));
    $GLOBALS['billingCatalogue']['ppp/active']=[['.id'=>'*last-session','name'=>'ram-user']];
    $GLOBALS['billingFailProfileRead']=true;
    $missing=new ApiAction($makeRequest('suspend',1)); $missing->exec();
    billingCheck($missing->status()->error && $missing->result()['provisioning']==='pending'
        && $missing->result()['desiredStatus']===3 && $missing->result()['appliedStatus']===1,
        'An unreadable suspension profile was falsely reported applied');
    billingCheck(($GLOBALS['billingCatalogue']['ppp/secret'][0]['profile']??null)==='RAM Plan [UISP#1]'
        && count($GLOBALS['billingCatalogue']['ppp/active'])===1,
        'The failed profile reference changed the account or disconnected its active session');
    $manualFailure=new ApiUpdate((object)['action'=>'insert','data'=>(object)['type'=>'device','id'=>10]],'system');
    $manualFailure->exec();
    $summaries=[];
    foreach (file('data/plugin.log',FILE_IGNORE_NEW_LINES) as $line) {
        $start=strpos($line,'{');
        if ($start===false) continue;
        $entry=json_decode(substr($line,$start),true);
        if (($entry['event']??null)==='provisioning_result') $summaries[]=$entry;
    }
    billingCheck(count($summaries)===4, 'Manual/general sync summaries were missing with debugging disabled');
    for ($index=0;$index<2;$index++) billingCheck($summaries[$index]===['event'=>'provisioning_result',
        'scope'=>'device','scopeId'=>10,'forceReconnect'=>true,'requested'=>1,'completed'=>1,'pendingOrFailed'=>0],
        'The manual synchronization log lost its selected router or completion counts');
    billingCheck($summaries[2]===['event'=>'provisioning_result','scope'=>'all','scopeId'=>null,
        'forceReconnect'=>false,'requested'=>2,'completed'=>2,'pendingOrFailed'=>0],
        'The general synchronization log claimed forced reconnection or incorrect counts');
    billingCheck($manualFailure->status()->error && $summaries[3]===['event'=>'provisioning_result',
        'scope'=>'device','scopeId'=>10,'forceReconnect'=>true,'requested'=>1,'completed'=>0,'pendingOrFailed'=>1],
        'A partial manual synchronization was absent from the log or falsely reported complete');
    // Exercise the actual administrative API, including partial deletion and retry of a newer blocked job.
    $oldKey=$missing->result()['jobKeys'][0];
    $pending=array_keys($queue->list()); $newKey=end($pending);
    billingCheck($newKey!==$oldKey && $queue->selectionStatus([$newKey])[$newKey]['blockedBy']===$oldKey,
        'A new sync did not retain its earlier prepared prerequisite');
    $delete=new ApiUpdate((object)['action'=>'delete','data'=>[$newKey]],'jobs'); $delete->exec();
    $deleted=$delete->result();
    billingCheck(!($delete->status()->error ?? false) && $deleted['operation']==='delete'
        && $deleted['results'][$newKey]===true
        && $deleted['summary']['deleted']===1 && $deleted['summary']['refused']===0,
        'Cancelling the unstarted successor changed or misreported its prepared prerequisite');
    $again=new ApiUpdate((object)['action'=>'insert','data'=>(object)['type'=>'device','id'=>10]],'system'); $again->exec();
    $pending=array_keys($queue->list()); $newKey=end($pending);
    $retry=new ApiUpdate((object)['action'=>'run','data'=>[$newKey]],'jobs'); $retry->exec();
    $failed=$retry->result();
    billingCheck($retry->status()->error && $failed['operation']==='run'
        && $failed['summary']['requested']===1 && $failed['summary']['attempted']===1
        && $failed['summary']['failed']===1 && $failed['summary']['pending']===1
        && $failed['results'][$oldKey]['attempted']===true && $failed['results'][$newKey]['attempted']===false
        && $failed['results'][$newKey]['error']===null && $failed['jobs'][$newKey]['blockedBy']===$oldKey,
        'Manual retry hid the prerequisite failure or counted a blocked job as attempted');
    $GLOBALS['billingFailProfileRead']=false;
    $start=count($GLOBALS['billingAdapters']);
    $retry=new ApiUpdate((object)['action'=>'run','data'=>[$newKey]],'jobs'); $retry->exec();
    $recovered=$retry->result();
    billingCheck(!($retry->status()->error??false) && $recovered['summary']['attempted']===2
        && $recovered['summary']['succeeded']===2 && $recovered['summary']['pending']===0
        && $queue->completed([$oldKey,$newKey]), 'A newer selected job did not recover its prerequisite and itself');
    $profiles=array_values(array_filter($GLOBALS['billingCatalogue']['ppp/profile'],static fn($row)=>($row['name']??null)==='paused-fixture'));
    $accounts=array_values(array_filter($GLOBALS['billingCatalogue']['ppp/secret']??[],static fn($row)=>($row['name']??null)==='ram-user'));
    billingCheck(count($profiles)===1 && ($profiles[0]['address-list']??null)==='paused-list-fixture'
        && !empty($profiles[0]['local-address'])
        && count($accounts)===1 && ($accounts[0]['profile']??null)==='paused-fixture'
        && $GLOBALS['billingCatalogue']['ppp/active']===[],
        'Suspension did not recreate its configured profile/list before assigning the account and reconnecting');
    $creates=0;
    foreach (array_slice($GLOBALS['billingAdapters'],$start) as $router) foreach($router->recording->sentences as $sentence) {
        if (($sentence[0]??'')==='/ppp/profile/add' && in_array('=name=paused-fixture',$sentence,true)) $creates++;
        billingCheck(($sentence[0]??'')!=='/ip/firewall/address-list/add', 'Bootstrap created a placeholder firewall address');
    }
    billingCheck($creates===1, 'Recovery duplicated or failed to create the missing suspension profile');
    $beforeProfile=$profiles[0];
    $GLOBALS['billingCatalogue']['ppp/active']=[['.id'=>'*unchanged-suspended','name'=>'ram-user']];
    billingCheck((new Batch())->set_accounts([91]) && count($GLOBALS['billingCatalogue']['ppp/active'])===1,
        'An ordinary unchanged suspension update repeated a bootstrap-driven disconnect');
    $transition(['unsuspend',3,null],true);
    $profiles=array_values(array_filter($GLOBALS['billingCatalogue']['ppp/profile'],static fn($row)=>($row['name']??null)==='paused-fixture'));
    billingCheck($profiles===[$beforeProfile], 'Reactivation overwrote the shared suspension profile: ' . json_encode(['before'=>$beforeProfile,'after'=>$profiles]));
    $actions=[];
    foreach(file('data/plugin.log',FILE_IGNORE_NEW_LINES) as $line) {
        $offset=strpos($line,'{'); if ($offset===false) continue;
        $entry=json_decode(substr($line,$offset),true);
        if (($entry['event']??null)==='job_action_result') $actions[]=$entry;
    }
    billingCheck(count($actions)===3 && strpos(json_encode($actions),'ram-pass')===false,
        'Administrative action diagnostics were missing or exposed account credentials');

    // New immediate webhook work must retry its own failed prerequisite without a manual queue action.
    $accountSentences=static function (int $start): array {
        $sentences=[];
        foreach (array_slice($GLOBALS['billingAdapters'],$start) as $adapter) foreach ($adapter->recording->sentences as $sentence) {
            if (in_array($sentence[0]??'', ['/ppp/secret/add','/ppp/secret/set','/ppp/secret/remove'],true)) $sentences[]=$sentence;
        }
        return $sentences;
    };
    $profileSequence=static function (array $sentences): array {
        $profiles=[];
        foreach ($sentences as $sentence) foreach ($sentence as $word) {
            if (strpos($word,'=profile=')===0) $profiles[]=substr($word,9);
        }
        return $profiles;
    };
    $foreignSnapshot=$queue->appliedSnapshot(94); $foreignSnapshot['_event_id']='foreign-unselected-debt';
    $foreignKey=$queue->enqueue($foreignSnapshot);
    $GLOBALS['billingFailProfileRead']=true;
    $oldWebhook=new ApiAction($makeRequest('suspend',1)); $oldWebhook->exec();
    $oldWebhookKey=$oldWebhook->result()['jobKeys'][0];
    billingCheck($oldWebhook->status()->error && $queue->selectionStatus([$oldWebhookKey])[$oldWebhookKey]['attempts']===1,
        'The automatic prerequisite fixture was not a previously attempted failure');
    $GLOBALS['billingFailProfileRead']=false;
    $start=count($GLOBALS['billingAdapters']);
    $newRequest=$makeRequest('edit',1,3);
    $newWebhook=new ApiAction($newRequest); $newWebhook->exec();
    $sentences=$accountSentences($start);
    billingCheck(!$newWebhook->status()->error && $newWebhook->result()['provisioning']==='applied'
        && $queue->completed(array_merge([$oldWebhookKey],$newWebhook->result()['jobKeys']))
        && $profileSequence($sentences)===['paused-fixture','RAM Plan [UISP#1]']
        && count($sentences)===2 && $queue->appliedSnapshot(91)['status']===1
        && $queue->selectionStatus([$foreignKey])[$foreignKey]['attempts']===0,
        'A recovered router still needed manual execution, lost prerequisite order, or ran another service');
    $start=count($GLOBALS['billingAdapters']);
    $completedDuplicate=new ApiAction($newRequest); $completedDuplicate->exec();
    billingCheck($completedDuplicate->result()['provisioning']==='duplicate_completed'
        && count($GLOBALS['billingAdapters'])===$start, 'Completed UUID delivery retried unrelated queue work');

    // While the earlier failure persists, do not attempt the newer state or claim it completed.
    $GLOBALS['billingFailProfileRead']=true;
    $oldWebhook=new ApiAction($makeRequest('suspend',1)); $oldWebhook->exec();
    $oldWebhookKey=$oldWebhook->result()['jobKeys'][0];
    $start=count($GLOBALS['billingAdapters']);
    $blockedRequest=$makeRequest('edit',1,3);
    $blockedWebhook=new ApiAction($blockedRequest); $blockedWebhook->exec();
    $blockedKey=$blockedWebhook->result()['jobKeys'][0];
    $status=$queue->selectionStatus([$oldWebhookKey,$blockedKey,$foreignKey]);
    billingCheck($blockedWebhook->status()->error && $blockedWebhook->result()['provisioning']==='pending'
        && $status[$oldWebhookKey]['attempts']===2 && $status[$blockedKey]['attempts']===0
        && $status[$blockedKey]['blockedBy']===$oldWebhookKey && $accountSentences($start)===[]
        && $status[$foreignKey]['attempts']===0,
        'A failed prerequisite was skipped, retried repeatedly, or allowed a newer webhook to write');
    // A duplicate pending event retries only up to its own version, even if later work already exists.
    $laterSnapshot=$queue->appliedSnapshot(91); $laterSnapshot['_event_id']='later-unselected-debt';
    $laterKey=$queue->enqueue($laterSnapshot,'queues_set');
    $GLOBALS['billingFailProfileRead']=false;
    $start=count($GLOBALS['billingAdapters']);
    $recoveredDuplicate=new ApiAction($blockedRequest); $recoveredDuplicate->exec();
    $status=$queue->selectionStatus([$oldWebhookKey,$blockedKey,$laterKey,$foreignKey]);
    billingCheck(!$recoveredDuplicate->status()->error && $recoveredDuplicate->result()['provisioning']==='duplicate_completed'
        && $queue->completed([$oldWebhookKey,$blockedKey])
        && $profileSequence($accountSentences($start))===['paused-fixture','RAM Plan [UISP#1]']
        && $status[$laterKey]['attempts']===0 && $status[$foreignKey]['attempts']===0,
        'Pending duplicate delivery did not recover its ordered prerequisites or ran later/unrelated work');
    $start=count($GLOBALS['billingAdapters']);
    $againCompleted=new ApiAction($blockedRequest); $againCompleted->exec();
    billingCheck($againCompleted->result()['provisioning']==='duplicate_completed'
        && count($GLOBALS['billingAdapters'])===$start && $queue->selectionStatus([$laterKey])[$laterKey]['attempts']===0,
        'A completed UUID retried later work for the same service');
    billingCheck($queue->run([$laterKey])[$laterKey]['success'], 'Could not settle the isolated later-version fixture');

    // Identity migration still completes the older snapshot, deletes that identity, then creates its replacement.
    $GLOBALS['billingFailProfileRead']=true;
    $oldWebhook=new ApiAction($makeRequest('suspend',1)); $oldWebhook->exec();
    $oldWebhookKey=$oldWebhook->result()['jobKeys'][0];
    $GLOBALS['billingFailProfileRead']=false;
    $migrationRequest=$makeRequest('edit',1,3);
    $migrationRequest->extraData->entity->attributes=array_map(static fn($attribute)=>clone $attribute,
        $migrationRequest->extraData->entity->attributes);
    $migrationRequest->extraData->entity->attributes[1]->value='ram-renamed';
    $start=count($GLOBALS['billingAdapters']);
    $migrated=new ApiAction($migrationRequest); $migrated->exec();
    $migrationSentences=$accountSentences($start);
    $names=array_column($GLOBALS['billingCatalogue']['ppp/secret'],'name');
    billingCheck(!$migrated->status()->error && $queue->completed(array_merge([$oldWebhookKey],$migrated->result()['jobKeys']))
        && array_column($migrationSentences,0)===['/ppp/secret/set','/ppp/secret/remove','/ppp/secret/add']
        && !in_array('ram-user',$names,true) && in_array('ram-renamed',$names,true)
        && $queue->appliedSnapshot(91)['username']==='ram-renamed'
        && $queue->selectionStatus([$foreignKey])[$foreignKey]['attempts']===0,
        'Immediate migration bypassed its previous failure/cleanup or mutated another service');
    // A fresh immediate manual force is distinct from a no-op edit, including a completed paired billing edit.
    $makeRequest('edit',1)->extraData->entity->attributes[1]->value='ram-renamed';
    $forceFresh=static function(string $message,?callable $names=null) use($queue):array {
        $GLOBALS['billingCatalogue']['ppp/active']=[['.id'=>'*fresh-force','name'=>'ram-renamed'],['.id'=>'*fresh-other','name'=>'other-ram-user']];
        $start=count($GLOBALS['billingAdapters']);$action=new ApiAction();
        $result=$action->reconcileService(91,false,true,null,null,$names);$writes=[];$disconnects=[];
        foreach(array_slice($GLOBALS['billingAdapters'],$start)as$adapter)foreach($adapter->recording->sentences as$sentence){
            if(in_array($sentence[0]??'', ['/ppp/secret/add','/ppp/secret/set'],true))$writes[]=$sentence;
            if(($sentence[0]??'')==='/ppp/active/remove')$disconnects[]=$sentence;
        }
        billingCheck(!$action->status()->error&&$result['provisioning']==='applied'&&count($writes)===1&&$writes[0][0]==='/ppp/secret/set'
            &&count($disconnects)===1&&$queue->completed($result['jobKeys'])
            &&$GLOBALS['billingCatalogue']['ppp/active']===[['.id'=>'*fresh-other','name'=>'other-ram-user']],$message);
        billingCheck(!isset($queue->appliedSnapshot(91)['_force_reconnect']),'Fresh force persisted into its applied baseline');
        return$result;
    };
    $forceFresh('An unchanged freshly reviewed immediate force did not write and reconnect exactly once');
    $apiSqliteCache->execute('UPDATE services SET status=3 WHERE id=91');
    $forceFresh('A completed paired billing edit suppressed an explicit freshly reviewed force');
    $apiSqliteCache->execute('UPDATE clients SET firstName=?,lastName=? WHERE id=70',['Obsolete','Cache']);
    $names=static fn(int$cid):?array=>$cid===70?['company'=>null,'firstName'=>'Current','lastName'=>'Customer','username'=>'must-not-replace-identity']:null;
    $forceFresh('Fresh client names prevented a reviewed immediate force from applying',$names);
    $account=array_values(array_filter($GLOBALS['billingCatalogue']['ppp/secret'],static fn($row)=>($row['name']??null)==='ram-renamed'))[0];
    billingCheck($account['comment']==='70 - Current Customer - 91'&&!in_array('must-not-replace-identity',array_column($GLOBALS['billingCatalogue']['ppp/secret'],'name'),true),
        'Fresh client names did not reach RouterOS or replaced provisioning identity fields');
    // The new device-sync endpoint uses its actual default ApiAction/Batch/MT applier, not an injected enqueue stub.
    require 'lib/api_device_sync.php';
    \Ubnt\UcrmPluginSdk\Service\UcrmApi::$client=['id'=>70,'isArchived'=>false,'firstName'=>'Current','lastName'=>'Customer'];
    $GLOBALS['billingCatalogue']['ppp/active']=[['.id'=>'*device-force','name'=>'ram-renamed'],['.id'=>'*device-other','name'=>'other-ram-user']];
    $start=count($GLOBALS['billingAdapters']);$deviceSync=new ApiDeviceSync();$sync=$deviceSync->begin(10);
    for($pass=0;!$sync['done']&&$pass<5;$pass++)$sync=$deviceSync->step($sync['syncToken']);
    $writes=$accountSentences($start);$disconnects=[];
    foreach(array_slice($GLOBALS['billingAdapters'],$start)as$adapter)foreach($adapter->recording->sentences as$sentence)
        if(($sentence[0]??'')==='/ppp/active/remove')$disconnects[]=$sentence;
    billingCheck($sync['done']&&$sync['requested']===1&&$sync['completed']===1&&$sync['failed']===0
        &&count($writes)===1&&$writes[0][0]==='/ppp/secret/set'&&in_array('=comment=70 - Current Customer - 91',$writes[0],true)
        &&count($disconnects)===1&&$GLOBALS['billingCatalogue']['ppp/active']===[['.id'=>'*device-other','name'=>'other-ram-user']]
        &&$queue->selectionStatus([$foreignKey])[$foreignKey]['attempts']===0,
        'Default device synchronization failed to apply fresh names and scoped reconnect or ran another router');
    $start=count($GLOBALS['billingAdapters']);$deviceSync->step($sync['syncToken']);
    billingCheck(count($GLOBALS['billingAdapters'])===$start,'A completed device-sync cursor repeated router writes');
    echo "Billing RouterOS checks passed: real pipeline, billing transitions, scoped reconnects, protected deletion, ordered immediate/duplicate webhook recovery, failed prerequisite barriers, bounded versions, migration cleanup, suspension bootstrap and reactivation.\n";
} finally {
    if (isset($queue)) $queue->close();
    if (isset($apiSqlite)) $apiSqlite->close();
    if (isset($apiSqliteCache)) $apiSqliteCache->close();
    chdir($original);
    $resolved=realpath($directory); $temporaryRoot=realpath(sys_get_temp_dir());
    if ($resolved && $temporaryRoot && strpos($resolved,$temporaryRoot . DIRECTORY_SEPARATOR . 'uisp-billing-router-')===0) {
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($resolved);
    }
}
