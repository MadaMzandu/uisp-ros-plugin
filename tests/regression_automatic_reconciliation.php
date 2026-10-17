<?php
// Bounded scheduler orchestration with injected read-only transports and mutation APIs.
require_once __DIR__ . '/../src/lib/api_automatic_reconciliation.php';
require_once __DIR__ . '/../src/lib/api_job_recovery_observation.php';
set_error_handler(static function($severity,$message,$file,$line) {
    if (error_reporting() & $severity) throw new ErrorException($message,0,$severity,$file,$line); return false;
});
function automaticCheck(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
class AutomaticFixtureApi {
    public string $kind; public array $inventory; public array $previews=[]; public array $applies=[]; public array $listings=[];
    public array $blocked=[]; public bool $fail=false; public bool $unsafe=false; public $request; public $advance;
    public function __construct(string $kind,array $inventory) { $this->kind=$kind; $this->inventory=$inventory; }
    public function listing(int $from=0): array {
        $this->listings[]=$from;
        if ($this->kind==='repair') {
            $rows=[]; foreach($this->inventory as $sid=>$fields) if ($sid>=$from) $rows[]=['service_id'=>$sid,'fields'=>array_map(static fn($field)=>['field'=>$field],$fields)];
            return ['rows'=>array_slice($rows,0,2),'total'=>count($rows)];
        }
        $rows=[]; foreach($this->inventory as $source) if ($source>=$from) $rows[]=['source_job_id'=>$source];
        return ['available'=>true,'groups'=>array_slice($rows,0,100)];
    }
    public function preview($selected,string $mode='resolve'): array {
        automaticCheck($mode==='resolve','Automatic pass selected an explicit preserve/discard mode');
        $key=$this->kind==='repair' ? $selected[0]['serviceId'] . ':' . $selected[0]['fields'][0] : (string)$selected;
        $this->previews[]=$key;
        if ($this->fail) throw new RuntimeException('PRIVATE_CUSTOMER_OR_CREDENTIAL');
        if (is_callable($this->request)) ($this->request)('clients/services/42',[]);
        if (is_callable($this->advance)) ($this->advance)();
        if (in_array($key,$this->blocked,true)) return ['previewToken'=>null,'candidates'=>[],'candidate'=>null];
        return $this->kind==='repair'
            ? ['previewToken'=>$key,'candidates'=>[['service_id'=>$selected[0]['serviceId'],'fields'=>[['field'=>$selected[0]['fields'][0]]]]]]
            : ['previewToken'=>$key,'candidate'=>['source_job_id'=>$selected,'resolution'=>$this->unsafe?'retained_resources':'redundant_current_resources']];
    }
    public function apply(string $token): array {
        $this->applies[]=$token;
        if ($this->kind==='repair') {
            [$sid,$field]=explode(':',$token);$sid=(int)$sid;
            $this->inventory[$sid]=array_values(array_diff($this->inventory[$sid],[$field]));
            if (!$this->inventory[$sid]) unset($this->inventory[$sid]);
            return ['repaired'=>[['service_id'=>$sid]],'jobs_preserved'=>true,'requires_sync'=>false];
        }
        $source=(int)$token;$this->inventory=array_values(array_diff($this->inventory,[$source]));
        return ['resolved'=>[['source_job_id'=>$source]],'router_changes'=>false,'crm_changes'=>false,'resolution'=>'redundant_current_resources'];
    }
}
$directory=sys_get_temp_dir() . '/uisp-automatic-reconciliation-' . bin2hex(random_bytes(8));mkdir($directory,0700);
$statePath=$directory . '/automatic-reconciliation.json';$now=1800000000.0;$calls=[];
$clock=static function() use (&$now): float { return $now; };
$request=static function(string $path,array $parameters=[]) use (&$calls): array { $calls[]=[$path,$parameters];return ['id'=>42]; };
$repair=new AutomaticFixtureApi('repair',[42=>['address','address6'],43=>['address']]);
$recovery=new AutomaticFixtureApi('recovery',[7,8]);
$factory=static function(string $kind,callable $read,$providedClock,float $deadline) use (&$repair,&$recovery,&$now) {
    automaticCheck($deadline===$now+12.0,'Scheduler did not share a bounded absolute deadline');
    $api=$kind==='repair'?$repair:$recovery;$api->request=$read;return $api;
};
$runner=new ApiAutomaticReconciliation($request,$directory.'/data.db',$directory.'/jobs.db',$directory.'/queue.json',$clock,$factory);
$reset=static function() use($statePath): void { if (is_file($statePath)) unlink($statePath); };
try {
    $repair->blocked=['42:address'];$recovery->blocked=['7'];
    $result=$runner->tick();
    automaticCheck($result['blocked']===2 && $repair->previews===['42:address'] && $recovery->previews===['7']
        && !$repair->applies && !$recovery->applies,'Blocked first entries were modified or more than one record was examined');
    $result=$runner->tick();
    automaticCheck($result['repaired']===1 && $result['resolved']===1 && $repair->previews===['42:address','42:address6']
        && $recovery->previews===['7','8'],'Persistent cursors failed to reach another family/recovery after a blocked record');
    $result=$runner->tick();
    automaticCheck($repair->previews[2]==='43:address' && count($repair->applies)===2,'Blocked first service starved a later customer');
    $state=file_get_contents($statePath);
    automaticCheck(strpos($state,'PRIVATE')===false && strpos(json_encode($result),'PRIVATE')===false,'Automatic state leaked transport/customer data');

    // Lower-bound inventories permit progress beyond the visible first page.
    $repair=new AutomaticFixtureApi('repair',array_fill_keys(range(1,60),['address']));
    $recovery=new AutomaticFixtureApi('recovery',range(1,110));
    file_put_contents($statePath,json_encode(['version'=>1,'next_kind'=>'repair','repair'=>['service_id'=>50,'field'=>'address6'],'recovery_source_id'=>100]));
    $result=$runner->tick();
    automaticCheck($repair->previews===['51:address'] && $recovery->previews===['101']
        && $repair->listings[0]===50 && $recovery->listings[0]===100,'Cursor was restricted to the first inventory page');

    // A slow first category cannot starve the other category on the next tick.
    $reset();$repair=new AutomaticFixtureApi('repair',[42=>['address']]);$recovery=new AutomaticFixtureApi('recovery',[7]);
    $repair->advance=static function() use (&$now): void { $now+=12.0; };
    $result=$runner->tick();
    automaticCheck($result['budget_exhausted'] && !$repair->applies && !$recovery->previews,'Work continued or mutation started after the shared deadline');
    $repair->advance=null;$result=$runner->tick();
    automaticCheck($recovery->previews===['7'] && $result['resolved']===1,'Budget exhaustion starved recovery instead of alternating work order');

    // API failures return to the scheduler and do not abort the other category.
    $reset();$repair=new AutomaticFixtureApi('repair',[42=>['address']]);$repair->fail=true;$recovery=new AutomaticFixtureApi('recovery',[7]);
    $result=$runner->tick();
    automaticCheck($result['failed']===1 && $result['resolved']===1 && strpos(json_encode($result),'PRIVATE')===false,
        'One maintenance failure aborted other work or exposed its exception');
    $reset();$repair=new AutomaticFixtureApi('repair',[]);$recovery=new AutomaticFixtureApi('recovery',[7]);$recovery->unsafe=true;
    $result=$runner->tick();automaticCheck(!$recovery->applies && $result['blocked']===1,'Incomplete history was automatically preserved/dismissed');

    // An overlapping scheduler cannot duplicate preview/apply work.
    $reset();$lock=fopen($statePath,'c+b');flock($lock,LOCK_EX|LOCK_NB);
    $before=count($recovery->previews);$result=$runner->tick();
    automaticCheck($result['busy'] && count($recovery->previews)===$before,'Concurrent scheduler entered the same maintenance pass');
    flock($lock,LOCK_UN);fclose($lock);

    // The absolute deadline reaches RouterOS observation, not only CRM reads.
    $device=['id'=>7,'ip'=>'192.0.2.7','port'=>8728,'user'=>'fixture-api','password'=>'PRIVATE_PASSWORD'];
    $data=new SQLite3($directory.'/data.db');$data->exec("CREATE TABLE devices(id INTEGER PRIMARY KEY,ip TEXT,port INTEGER,user TEXT,password TEXT);
        INSERT INTO devices VALUES(7,'192.0.2.7',8728,'fixture-api','PRIVATE_PASSWORD')");$data->close();
    $snapshot=['id'=>42,'clientId'=>17,'device'=>7,'username'=>'fixture-account','_device'=>$device,
        '_commands'=>[['path'=>'/ppp/secret','name'=>'fixture-account']]];
    $observerCalls=0;$read=static function() use (&$observerCalls,&$now): array { $observerCalls++;$now+=1;return []; };
    $expired=new ApiJobRecoveryObservation($directory.'/data.db',$read,$clock,$now);
    $observed=$expired->inspect([$snapshot]);
    automaticCheck(!$observed['safe'] && $observed['reason']==='router_observation_limit' && $observerCalls===0,'An expired automatic observation contacted a router');
    $partial=new ApiJobRecoveryObservation($directory.'/data.db',$read,$clock,$now+0.5);
    $observed=$partial->inspect([$snapshot]);
    automaticCheck(!$observed['safe'] && $observed['reason']==='router_observation_limit' && $observerCalls===1,
        'Router observation started a second read after the shared deadline');

    $main=file_get_contents(__DIR__.'/../src/main.php');
    automaticCheck(strpos($main,'ApiAutomaticReconciliation())->tick()')<strpos($main,'ApiConfigurationApply::drain()')
        && strpos($main,'ApiAutomaticReconciliation())->tick()')<strpos($main,'(new ApiJobs())->run()'),
        'Automatic reconciliation moved inside/after the worker maintenance gates');
    echo "OK: bounded pre-worker checks, persistent fair cursors beyond visible pages, alternating categories, GET-only requests, no automatic preserve/discard, isolated failure and scheduler overlap\n";
} finally {
    foreach(glob($directory.'/*')?:[] as $file) if(is_file($file))unlink($file);rmdir($directory);
}
