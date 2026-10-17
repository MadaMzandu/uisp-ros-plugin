<?php
// Real ApiAction -> durable queue -> Batch -> MtData -> MT against an isolated RAM RouterOS adapter.
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
function archiveCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function archiveDiagnostics(): array {
    $records=[];
    foreach (is_file('data/plugin.log') ? file('data/plugin.log',FILE_IGNORE_NEW_LINES) : [] as $line) {
        $start=strpos($line,'{');
        if ($start===false) continue;
        $record=json_decode(substr($line,$start),true);
        if (($record['event']??null)==='plan_metadata_failed') $records[]=$record;
    }
    return $records;
}
function archiveRejectedMetadata(array $item,string $reason,string $field): void {
    $before=count(archiveDiagnostics());
    try { ApiPlanCatalog::normalize($item,77); throw new LogicException('Invalid metadata accepted'); }
    catch (InvalidArgumentException $expected) {}
    $records=archiveDiagnostics();
    archiveCheck(count($records)===$before+1 && end($records)===['event'=>'plan_metadata_failed','planId'=>77,
        'reason'=>$reason,'field'=>$field], 'Metadata rejection did not emit one safe, attributable diagnostic');
}
$source = realpath(__DIR__ . '/../src');
$original = getcwd();
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-archive-router-' . bin2hex(random_bytes(8));
mkdir($directory,0700);
foreach (['lib','includes','data','vendor'] as $folder) mkdir($directory . '/' . $folder,0700);
foreach (glob($source . '/lib/*.php') as $file) copy($file,$directory . '/lib/' . basename($file));
foreach (glob($source . '/includes/*') as $file) if (is_file($file)) copy($file,$directory . '/includes/' . basename($file));
file_put_contents($directory . '/vendor/autoload.php', <<<'PHP'
<?php
namespace Ubnt\UcrmPluginSdk\Service;
class UcrmApi {
    public static function create() { return new self(); }
    public function get($path,$options=[]) {
        $GLOBALS['archiveRequests'][]=[$path,$options];
        if (!array_key_exists($path,$GLOBALS['archiveResponses'])) throw new \RuntimeException('Fixture missing response');
        $response=$GLOBALS['archiveResponses'][$path];
        if ($response instanceof \Throwable) throw $response;
        return $response;
    }
    public function patch($path,$data=[]) { throw new \RuntimeException('Fixture external network prohibited'); }
}
PHP);
// Inject only the adapter constructor into the disposable Batch copy. No MT::do_batch/write/preflight is stubbed.
$batchFile=$directory . '/lib/batch.php';
$batchCode=str_replace('new MT()', 'new ArchiveMT()', file_get_contents($batchFile), $factoryChanges);
archiveCheck($factoryChanges===1, 'The fixture MT constructor hook was not precise');
file_put_contents($batchFile,$batchCode);
chdir($directory);
set_include_path($directory . '/lib' . PATH_SEPARATOR . get_include_path());
require 'lib/api_common.php';
require 'lib/api_setup.php';
require 'lib/api_action.php';
require 'lib/api_trim.php';

class ArchiveRouterAPI extends RouterosAPI {
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
class ArchiveMT extends MT {
    public array $catalogue=[];
    public ArchiveRouterAPI $recording;
    public function __construct() {
        parent::__construct();
        $this->catalogue =& $GLOBALS['archiveCatalogue'];
        $GLOBALS['archiveAdapters'][]=$this;
        $this->recording=new ArchiveRouterAPI(function (array $words): array {
            $path=trim(array_shift($words),'/');
            $separator=strrpos($path,'/');
            $action=substr($path,$separator+1); $path=substr($path,0,$separator);
            $values=[];
            foreach ($words as $word) {
                if (strpos($word,'=')!==0) continue;
                [$key,$value]=explode('=',substr($word,1),2); $values[$key]=$value;
            }
            if ($path==='ppp/active' && $action==='remove' && !empty($GLOBALS['archiveFailReset']))
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
                $GLOBALS['archiveNextRouterId']=($GLOBALS['archiveNextRouterId']??0)+1;
                $this->catalogue[$path][]=['.id'=>'*ram-' . $GLOBALS['archiveNextRouterId']] + $values;
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
        if ($path==='ppp/profile' && !empty($GLOBALS['archiveFailProfileRead'])) return null;
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
    require 'lib/api_list.php';
    $apiSqlite=new ApiSqlite('data/data.db');
    $apiSqlite->exec(file_get_contents('includes/schema.sql'));
    (new ApiSetup())->config_load();
    $apiSqlite->saveConfig(['version'=>MY_VERSION,'disable_contention'=>true,'disabled_profile'=>'paused-fixture',
        'disabled_list'=>'paused-list','disabled_rate'=>0,'auto_ppp_user'=>false]);
    $apiSqliteCache=new ApiSqlite('data/cache.db');
    $apiSqliteCache->exec(file_get_contents('includes/cache.sql'));
    $queue=myJobQueue();
    $apiSqlite->insert(['id'=>10,'name'=>'RAM Router','type'=>'mikrotik','ip'=>'192.0.2.10',
        'user'=>'fixture','password'=>'fixture','pool'=>'10.42.0.0/24'],'devices');
    $apiSqliteCache->insert(['id'=>70,'firstName'=>'Fixture','lastName'=>'Client'],'clients');
    $plan=['id'=>38,'name'=>'Plan Comercial - Ultra [25Mb/5Mb]','uploadSpeed'=>5,'downloadSpeed'=>25,
        'servicePlanType'=>'Internet','archived'=>true];
    $active=['id'=>1,'name'=>'Active plan','uploadSpeed'=>2,'downloadSpeed'=>10,'servicePlanType'=>'Internet','archived'=>false];
    $GLOBALS['archiveResponses']=['service-plans'=>[$active],'service-plans/38'=>$plan];
    $GLOBALS['archiveRequests']=[];
    $GLOBALS['archiveAdapters']=[];
    $GLOBALS['archiveCatalogue']=[
        'ip/address'=>[['.id'=>'*lan','address'=>'192.0.2.1/24','disabled'=>'false','dynamic'=>'false','invalid'=>'false']],
        'ppp/profile'=>[['.id'=>'*old38','name'=>'uisp-plan-38','comment'=>'Plan #38','rate-limit'=>'0/0']],
    ];
    $service=['id'=>138,'clientId'=>70,'device'=>10,'planId'=>38,'status'=>1,'username'=>'archive-user',
        'password'=>'PRIVATE-FIXTURE','uploadSpeed'=>0,'downloadSpeed'=>0];
    $apiSqliteCache->insert($service,'services');
    $apiSqlite->insert(['id'=>38,'name'=>null,'uploadSpeed'=>0,'downloadSpeed'=>0,'created'=>'2000-01-01'],'plans');
    $batch=new Batch();
    archiveCheck($batch->set_accounts([138],true), 'Direct sync did not recover the archived plan');
    $profiles=array_values(array_filter($GLOBALS['archiveCatalogue']['ppp/profile'],static fn($row)=>($row['.id']??'')==='*old38'));
    archiveCheck(count($profiles)===1 && $profiles[0]['name']===$plan['name'].' [UISP#38]'
        && strpos($profiles[0]['rate-limit'],'5M/25M')===0, 'Archived profile was duplicated or retained fallback name/rates');
    $accounts=$GLOBALS['archiveCatalogue']['ppp/secret'];
    archiveCheck(count($accounts)===1 && $accounts[0]['profile']===$profiles[0]['name']
        && array_column($GLOBALS['archiveRequests'],0)===['service-plans/38'], 'Sync required opening Plans or repeated a successful lookup');
    $address=$accounts[0]['remote-address'];
    $apiSqlite->edit(['id'=>38,'name'=>'','uploadOverride'=>3,'downloadOverride'=>10,'ratio'=>4,
        'priorityUpload'=>6,'limitUpload'=>40,'burstUpload'=>20],'plans');
    archiveCheck((new Batch())->set_accounts([138],true),'Plan recovery with overrides failed');
    $stored=$apiSqlite->singleQuery('SELECT * FROM plans WHERE id=38',true);
    archiveCheck($stored['uploadSpeed']==5 && $stored['downloadSpeed']==25 && $stored['uploadOverride']==3
        && $stored['downloadOverride']==10 && $stored['ratio']==4 && $stored['limitUpload']==40
        && $stored['burstUpload']==20 && $stored['created']==='2000-01-01','Metadata recovery overwrote local shaping settings');
    archiveCheck($GLOBALS['archiveCatalogue']['ppp/secret'][0]['remote-address']===$address,'Plan repair changed the account address');
    $list=new ApiList(null,'plans'); $list->exec();
    $listed=array_column($list->result(),null,'id');
    archiveCheck($listed[38]['archive']===true && $listed[1]['archive']===false
        && $listed[38]['name']===$plan['name'], 'Referenced archive was omitted or marked active');
    // Some CRM collections include archived plans; use their documented flag without an extra ID lookup.
    $GLOBALS['archiveResponses']['service-plans']=[$active,$plan];
    $requests=count($GLOBALS['archiveRequests']);
    $list=new ApiList(null,'plans'); $list->exec();
    $listed=array_column($list->result(),null,'id');
    archiveCheck($listed[38]['archive']===true && $listed[1]['archive']===false
        && array_column(array_slice($GLOBALS['archiveRequests'],$requests),0)===['service-plans'],
        'A collection-provided archived plan lost its flag or required a redundant ID lookup');
    $GLOBALS['archiveResponses']['service-plans']=[$active];
    $before=$apiSqlite->selectAllFromTable('plans');
    $GLOBALS['archiveResponses']['service-plans']=[array_replace($active,['name'=>'Should not publish'])];
    $GLOBALS['archiveResponses']['service-plans/38']=new RuntimeException('PRIVATE-ERROR');
    $list=new ApiList(null,'plans'); $list->exec();
    archiveCheck($apiSqlite->selectAllFromTable('plans')===$before,'A failed archive lookup partially published the active catalogue');
    $GLOBALS['archiveResponses']['service-plans']=['invalid-response'];
    $list=new ApiList(null,'plans'); $list->exec();
    archiveCheck($apiSqlite->selectAllFromTable('plans')===$before,'An invalid collection damaged local plans');
    $GLOBALS['archiveResponses']['service-plans/38']=$plan;
    $GLOBALS['archiveResponses']['service-plans']=[$active];

    // An old prepared job repairs shared metadata while preserving frozen account data.
    $prepared=$queue->appliedSnapshot(138);
    $prepared['_plan']['name']=null;
    foreach ($prepared['_commands'] as &$command) {
        if (trim($command['path'],'/')==='ppp/secret') $command['password']='FROZEN-RETRY-PASSWORD';
    }
    unset($command);
    $apiSqlite->edit(['id'=>38,'name'=>null],'plans');
    $key=$queue->enqueue($prepared);
    $queue->runSelected([$key]);
    archiveCheck($queue->completed([$key]) && $GLOBALS['archiveCatalogue']['ppp/secret'][0]['password']==='FROZEN-RETRY-PASSWORD'
        && $GLOBALS['archiveCatalogue']['ppp/secret'][0]['remote-address']===$address
        && $GLOBALS['archiveCatalogue']['ppp/secret'][0]['profile']===$plan['name'].' [UISP#38]',
        'Prepared recovery lost a frozen account or failed to refresh its shared profile');

    // Missing/invalid metadata is a per-service failure, never a fallback unlimited profile.
    $GLOBALS['archiveResponses']['service-plans/77']=array_replace($plan,['id'=>78]);
    $apiSqliteCache->insert(array_replace($service,['id'=>139,'planId'=>77,'username'=>'missing-one']),'services');
    $apiSqliteCache->insert(array_replace($service,['id'=>140,'planId'=>77,'username'=>'missing-two']),'services');
    $requests=count($GLOBALS['archiveRequests']); $adapters=count($GLOBALS['archiveAdapters']);
    $batch=new Batch();
    archiveCheck(!$batch->set_accounts([139,140]) && $batch->outcomes()['failed']===2
        && count($GLOBALS['archiveRequests'])===$requests+1 && count($GLOBALS['archiveAdapters'])===$adapters,
        'An unresolved positive plan wrote fallback router commands or retried its lookup per service');
    // API response enums are title case, unlike the collection filter. Preserve older field-less responses too.
    $typedPlan=array_replace($plan,['id'=>77,'name'=>'PRIVATE-RESPONSE-NAME']);
    foreach (['Internet','internet'] as $type) {
        $normalized=ApiPlanCatalog::normalize(array_replace($typedPlan,['servicePlanType'=>$type]),77);
        archiveCheck($normalized['id']===77 && $normalized['uploadSpeed']===5 && $normalized['downloadSpeed']===25,
            'A supported Internet service plan spelling was rejected');
    }
    $withoutType=$typedPlan; unset($withoutType['servicePlanType']);
    archiveCheck(ApiPlanCatalog::normalize($withoutType,77)['id']===77,'A response without a type lost compatibility');
    foreach (['General','general',null,true,12,[],'PRIVATE-RESPONSE-TYPE'] as $type)
        archiveRejectedMetadata(array_replace($typedPlan,['servicePlanType'=>$type]),'unsupported_type','servicePlanType');
    archiveRejectedMetadata(array_replace($typedPlan,['downloadSpeed'=>'PRIVATE-RESPONSE-SPEED']),
        'invalid_speed','downloadSpeed');
    foreach ([null,INF,-1,'PRIVATE-RESPONSE-SPEED'] as $bad) {
        archiveRejectedMetadata(array_replace($typedPlan,['uploadSpeed'=>$bad]),'invalid_speed','uploadSpeed');
        $diagnostics=count(archiveDiagnostics());
        $GLOBALS['archiveResponses']['service-plans/77']=array_replace($plan,['id'=>77,'uploadSpeed'=>$bad]);
        try { ApiPlanCatalog::ensure(77); throw new LogicException('Invalid source speed accepted'); }
        catch (InvalidArgumentException $expected) {}
        $records=archiveDiagnostics();
        // The SDK wrapper's JSON conversion rejects INF before the catalogue sees an object.
        $expectedReason=is_float($bad) && !is_finite($bad) ? 'response_not_object' : 'invalid_speed';
        $expectedField=$expectedReason==='response_not_object' ? 'response' : 'uploadSpeed';
        archiveCheck(count($records)===$diagnostics+1 && end($records)===['event'=>'plan_metadata_failed','planId'=>77,
            'reason'=>$expectedReason,'field'=>$expectedField],'Invalid speed was not diagnosed with its plan and field');
    }
    archiveCheck(in_array(['event'=>'plan_metadata_failed','planId'=>38,'reason'=>'request_failed','field'=>'request'],
        archiveDiagnostics(),true),'A failed archived-plan request was not diagnosed');
    $logs=file_get_contents('data/plugin.log');
    foreach (['PRIVATE-RESPONSE-NAME','PRIVATE-RESPONSE-TYPE','PRIVATE-RESPONSE-SPEED','PRIVATE-ERROR'] as $secret)
        archiveCheck(strpos($logs,$secret)===false,'Plan diagnostics exposed raw API response or exception data');
    archiveCheck(!$apiSqlite->singleQuery('SELECT id FROM plans WHERE id=77'),'Invalid remote metadata was stored');
    archiveCheck(!ApiPlanCatalog::needsHydration(['id'=>1,'name'=>'Explicit unlimited','uploadSpeed'=>0,'downloadSpeed'=>0])
        && !ApiPlanCatalog::needsHydration(['id'=>1,'name'=>'Local overrides','uploadOverride'=>3,'downloadOverride'=>10]),
        'Explicit zero or local overrides were mistaken for missing metadata');

    // Removing an account must not require CRM plan metadata.
    $apiSqlite->edit(['id'=>38,'name'=>null],'plans');
    $GLOBALS['archiveResponses']['service-plans/38']=new RuntimeException('Unavailable');
    $requests=count($GLOBALS['archiveRequests']);
    archiveCheck((new Batch())->del_accounts([138]) && count($GLOBALS['archiveRequests'])===$requests,
        'Deletion depended on a remote plan refresh');
    echo "Archived plans passed: Internet API casing, safe failure diagnostics, ID lookup without a Plans visit, owned profile rename/rates, local overrides, atomic catalogue retention, archive flags, prepared identity preservation, bounded failures and deletion independence.\n";
} finally {
    if (isset($queue)) $queue->close();
    if (isset($apiSqlite)) $apiSqlite->close();
    if (isset($apiSqliteCache)) $apiSqliteCache->close();
    chdir($original);
    $resolved=realpath($directory); $temporaryRoot=realpath(sys_get_temp_dir());
    if ($resolved && $temporaryRoot && strpos($resolved,$temporaryRoot . DIRECTORY_SEPARATOR . 'uisp-archive-router-')===0) {
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($resolved);
    }
}

