<?php
// Full preview -> apply contract with isolated SQLite journals. No external I/O.
namespace GuzzleHttp\Exception {
    class RequestException extends \RuntimeException {
        private int $status;
        public function __construct(int $status) { parent::__construct('PRIVATE synthetic transport response'); $this->status=$status; }
        public function hasResponse(): bool { return true; }
        public function getResponse(): object { return new class($this->status) {
            private int $status; public function __construct(int $status) { $this->status=$status; }
            public function getStatusCode(): int { return $this->status; }
        }; }
    }
}
namespace {
set_error_handler(static function ($severity,$message,$file,$line) {
    if (error_reporting() & $severity) throw new \ErrorException($message,0,$severity,$file,$line);
    return false;
});
function lifecyclePreviewCheck(bool $condition,string $message): void { if (!$condition) throw new \RuntimeException($message); }
$source=realpath(__DIR__.'/../src');
require $source.'/lib/api_job_queue.php'; require $source.'/lib/api_service_lifecycle.php';
$directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'uisp-lifecycle-preview-'.bin2hex(random_bytes(8)); mkdir($directory,0700);
$queues=[];
try {
    $fixture=static function (string $name,bool $legacy=false,bool $cached=false,bool $device=true) use($directory,$source,&$queues): array {
        $path=$directory.'/'.$name; mkdir($path,0700);
        $data=$path.'/data.db'; $cache=$path.'/cache.db'; $jobs=$path.'/jobs.db';
        $db=new \SQLite3($data); $db->enableExceptions(true); $db->exec(file_get_contents($source.'/includes/schema.sql'));
        if ($device) $db->exec("INSERT INTO devices(id,name,type,ip,user,password) VALUES(1,'Synthetic Site','mikrotik','192.0.2.1','synthetic-api','synthetic-private')");
        if ($legacy) $db->exec('INSERT INTO services(id,device,clientId,planId,status) VALUES(71001,1,72001,1,1)');
        $db->exec("INSERT INTO network(id,address) VALUES(71001,'192.0.2.10')"); $db->close();
        $db=new \SQLite3($cache); $db->enableExceptions(true); $db->exec(file_get_contents($source.'/includes/cache.sql'));
        if ($cached) $db->exec("INSERT INTO services(id,device,clientId,planId,status,username) VALUES(71001,1,72001,1,1,'synthetic-user')");
        $db->close();
        $queue=new \ApiJobQueue($jobs,null); $queues[]=$queue;
        $service=['id'=>71001,'device'=>1,'clientId'=>72001,'planId'=>1,'status'=>1,'username'=>'synthetic-user',
            'network'=>['address'=>'192.0.2.10'],'_device'=>['id'=>1,'type'=>'mikrotik','ip'=>'192.0.2.1']];
        $key=$queue->enqueue($service);
        $request=static function (string $path) { throw new \GuzzleHttp\Exception\RequestException(404); };
        $routerReader=static function (): array { return []; };
        return ['data'=>$data,'cache'=>$cache,'jobs'=>$jobs,'queue'=>$queue,'key'=>$key,'service'=>$service,
            'request'=>$request,'api'=>new \ApiServiceLifecycle($request,$data,$cache,$jobs,null,null,$routerReader)];
    };

    // A never-attempted intent has no physical effect and can be retired even
    // after the service disappeared, without guessing any router identity.
    $clean=$fixture('unapplied'); $preview=$clean['api']->preview(71001);
    lifecyclePreviewCheck($preview['eligible'] && $preview['missing'] && $preview['state']==='retired','A clean missing service cannot be reviewed');
    $result=$clean['api']->apply($preview['previewToken'],$clean['queue']);
    lifecyclePreviewCheck($result['retired'] && $result['released'] && $result['queued']===0 && !$clean['queue']->pending([$clean['key']]),
        'The complete missing-service preview/apply flow failed to retire only obsolete intent');
    try { $clean['api']->apply($preview['previewToken'],$clean['queue']); throw new \RuntimeException('Consumed token was accepted'); }
    catch (\InvalidArgumentException $error) { lifecyclePreviewCheck($error->getMessage()==='lifecycle_invalid_token','Consumed token returned an unexpected code'); }

    // A legacy applied row proves a physical effect, but a newer desired name
    // cannot supply its missing applied username after a cache refresh.
    $purged=$fixture('purged-cache',true); $before=$purged['queue']->list(); $preview=$purged['api']->preview(71001);
    lifecyclePreviewCheck(!$preview['eligible'] && $preview['reason']==='lifecycle_router_identity_unknown'
        && $preview['previewToken']===null && $preview['expiresAt']===null && $preview['missing'],
        'An unresolvable purged-cache owner was offered an actionable retirement token');
    lifecyclePreviewCheck($purged['queue']->list()===$before,'Review superseded or changed unverified legacy work');

    $transport=$fixture('private-router-error',true);
    $transport['api']=new \ApiServiceLifecycle($transport['request'],$transport['data'],$transport['cache'],$transport['jobs'],null,null,
        static function () { throw new \RuntimeException('PRIVATE synthetic router socket response'); });
    $preview=$transport['api']->preview(71001);
    lifecyclePreviewCheck(!$preview['eligible'] && $preview['reason']==='lifecycle_resources_unconfirmed'
        && $preview['state']==='retired' && strpos(json_encode($preview),'PRIVATE')===false,
        'A private router transport error was misreported as billing authority or escaped the public proof');

    $archived=$fixture('archived-client',false,true);
    $archived['api']=new \ApiServiceLifecycle(static function (string $path) {
        if ($path==='clients/services/71001') return ['id'=>71001,'clientId'=>72001,'status'=>1];
        if ($path==='clients/72001') return ['id'=>72001,'isArchived'=>true];
        throw new \RuntimeException('Unexpected synthetic CRM read');
    },$archived['data'],$archived['cache'],$archived['jobs']);
    $preview=$archived['api']->preview(71001);
    lifecyclePreviewCheck($preview['eligible'] && $preview['reason']==='client_archived' && !$preview['missing'],
        'A current archived-client proof was lost during preview preflight');
    $result=$archived['api']->apply($preview['previewToken'],$archived['queue']);
    lifecyclePreviewCheck($result['retired'] && !$archived['queue']->pending([$archived['key']]),'Archived-client preview/apply failed to retire unapplied intent');

    // The prior physical account may have a completely different name from
    // the last desired payload. Only its exact router ownership is authority.
    $recovered=$fixture('recovered-cache',true); $reads=[];
    $catalog=['ppp/secret'=>[
        ['.id'=>'*A','name'=>'previous-physical-user','comment'=>'72001 - Synthetic Applied Account - 71001','remote-address'=>'192.0.2.10'],
        ['.id'=>'*B','name'=>'unrelated-current-user','comment'=>'72002 - Synthetic Current Account - 71002','remote-address'=>'192.0.2.11']],
        'ppp/active'=>[['.id'=>'*C','name'=>'previous-physical-user']]];
    $routerReader=static function ($device,$path,$filter,$fields) use(&$reads,$catalog): array {
        lifecyclePreviewCheck(!in_array('password',$fields,true),'Recovery requested private account credentials');
        $reads[]=[$path,$filter]; $rows=$catalog[$path]??[];
        return array_values(array_filter($rows,static function ($row) use($filter): bool {
            foreach($filter as $key=>$value) if(($row[$key]??null)!==$value) return false;
            return true;
        }));
    };
    $recovered['api']=new \ApiServiceLifecycle($recovered['request'],$recovered['data'],$recovered['cache'],$recovered['jobs'],null,null,$routerReader);
    $preview=$recovered['api']->preview(71001);
    lifecyclePreviewCheck($preview['eligible'] && $preview['reason']==='service_not_found','Strict router ownership could not recover a purged legacy identity');
    $firstReads=count($reads); $result=$recovered['api']->apply($preview['previewToken'],$recovered['queue']);
    lifecyclePreviewCheck($result['retired'] && !$result['released'] && $result['queued']===1 && count($reads)>$firstReads,
        'Apply did not freshly recover physical ownership before scheduling cleanup');
    $cleanup=$recovered['queue']->list(); $payload=$cleanup[$result['jobKeys'][0]]??[];
    lifecyclePreviewCheck(($payload['username']??null)==='previous-physical-user' && ($payload['action']??null)==='delete',
        'Purged-cache recovery used an unattempted desired username as the physical owner');
    $frozen=$recovered['queue']->lifecycleSnapshots(71001); $target=null;
    foreach ($frozen as $snapshot) if (($snapshot['username']??null)==='previous-physical-user' && isset($snapshot['_commands'])) $target=$snapshot;
    lifecyclePreviewCheck(is_array($target),'Recovered cleanup did not retain a frozen physical journal');
    $priorInclude=get_include_path(); set_include_path($source.'/lib'.PATH_SEPARATOR.$priorInclude);
    require_once $source.'/lib/mt_data.php'; set_include_path($priorInclude);
    $commands=\MtData::safePreparedCommands($target,$target['_commands'],'delete'); $accounts=[];
    foreach ($commands as $command) if (in_array(trim($command['path']??'','/'),['ppp/secret','ppp/active'],true)) $accounts[]=$command;
    lifecyclePreviewCheck(count($accounts)===2,'Safe command upgrade lost the recovered account or guarded session cleanup');
    foreach ($accounts as $command) lifecyclePreviewCheck(($command['name']??null)==='previous-physical-user'
        && ($command['_service_id']??null)===71001 && ($command['_client_id']??null)===72001 && ($command['action']??null)==='remove',
        'Recovered commands escaped exact service ownership or targeted a current account');

    $disconnected=$fixture('router-offline-after-preview',true); $routerOffline=false;
    $changingReader=static function (...$arguments) use(&$routerOffline,$routerReader): array {
        if ($routerOffline) throw new \RuntimeException('PRIVATE synthetic router disconnected during read');
        return $routerReader(...$arguments);
    };
    $disconnected['api']=new \ApiServiceLifecycle($disconnected['request'],$disconnected['data'],$disconnected['cache'],$disconnected['jobs'],null,null,$changingReader);
    $preview=$disconnected['api']->preview(71001); lifecyclePreviewCheck($preview['eligible'],'Could not seed the transport-race preview');
    $routerOffline=true; $failure=null;
    try { $disconnected['api']->apply($preview['previewToken'],$disconnected['queue']); }
    catch (\RuntimeException $error) { $failure=$error->getMessage(); }
    lifecyclePreviewCheck($failure==='lifecycle_resources_unconfirmed' && $disconnected['queue']->pending([$disconnected['key']]),
        'A router transport failure during apply escaped the safe failure code or retired unconfirmed work');

    // Immutable applied evidence hydrates the legacy row independently of
    // the desired cache and remains the exact target of the queued cleanup.
    $applied=$fixture('applied-journal',true);
    $applied['queue']->runSelected([$applied['key']],static fn()=>true);
    $next=$applied['service']; $next['username']='different-desired-user'; $nextKey=$applied['queue']->enqueue($next);
    $preview=$applied['api']->preview(71001); lifecyclePreviewCheck($preview['eligible'],'Applied ownership was lost when the cache disappeared');
    $result=$applied['api']->apply($preview['previewToken'],$applied['queue']);
    lifecyclePreviewCheck($result['retired'] && !$result['released'] && $result['queued']===1
        && !$applied['queue']->pending([$nextKey]),'Applied-journal retirement did not create guarded cleanup');
    $cleanup=$applied['queue']->list(); $payload=$cleanup[$result['jobKeys'][0]]??[];
    lifecyclePreviewCheck(($payload['username']??null)==='synthetic-user' && ($payload['action']??null)==='delete',
        'The pending desired username replaced immutable physical ownership');

    $known=$fixture('known-cache',true,true); $preview=$known['api']->preview(71001);
    lifecyclePreviewCheck($preview['eligible'],'A complete legacy owner was blocked');
    $result=$known['api']->apply($preview['previewToken'],$known['queue']);
    lifecyclePreviewCheck($result['retired'] && $result['queued']===1 && !$result['released'],'A known legacy owner skipped physical cleanup');

    $missingDevice=$fixture('missing-device',true,true,false); $preview=$missingDevice['api']->preview(71001);
    lifecyclePreviewCheck(!$preview['eligible'] && $preview['reason']==='lifecycle_router_identity_unknown',
        'A missing router was offered a cleanup that cannot execute');

    $busy=$fixture('running-job'); $db=new \SQLite3($busy['jobs']); $db->exec("UPDATE jobs SET status='running'"); $db->close();
    $preview=$busy['api']->preview(71001);
    lifecyclePreviewCheck(!$preview['eligible'] && $preview['reason']==='lifecycle_service_busy' && $busy['queue']->pending([$busy['key']]),
        'A running service was offered a retirement token or changed during review');

    $busyApply=$fixture('busy-after-preview'); $preview=$busyApply['api']->preview(71001);
    $holder=new \ApiJobQueue($busyApply['jobs'],null); $queues[]=$holder; $busyCode=null;
    $holder->withServiceLock(71001,static function () use($busyApply,$preview,&$busyCode): void {
        try { $busyApply['api']->apply($preview['previewToken'],$busyApply['queue']); }
        catch (\RuntimeException $error) { $busyCode=$error->getMessage(); }
    });
    lifecyclePreviewCheck($busyCode==='lifecycle_service_busy' && $busyApply['queue']->pending([$busyApply['key']]),
        'A concurrent service gate escaped the allowlisted retirement failure contract');

    $changed=$fixture('changed-during-preview'); $crmReads=0;
    $changed['api']=new \ApiServiceLifecycle(static function (string $path) use(&$crmReads) {
        if ($path==='clients/72001') return ['id'=>72001,'isArchived'=>false];
        if (++$crmReads===1) throw new \GuzzleHttp\Exception\RequestException(404);
        return ['id'=>71001,'clientId'=>72001,'status'=>1];
    },$changed['data'],$changed['cache'],$changed['jobs']);
    $preview=$changed['api']->preview(71001);
    lifecyclePreviewCheck(!$preview['eligible'] && $preview['reason']==='lifecycle_changed' && $changed['queue']->pending([$changed['key']]),
        'A lifecycle change during preflight left an actionable retirement token');

    $expired=$fixture('expired'); $now=1000;
    $expired['api']=new \ApiServiceLifecycle($expired['request'],$expired['data'],$expired['cache'],$expired['jobs'],static function () use(&$now){ return $now; });
    $preview=$expired['api']->preview(71001); $now=1601;
    try { $expired['api']->apply($preview['previewToken'],$expired['queue']); throw new \RuntimeException('Expired token was accepted'); }
    catch (\InvalidArgumentException $error) { lifecyclePreviewCheck($error->getMessage()==='lifecycle_invalid_token','Expired token returned an unexpected code'); }
    lifecyclePreviewCheck($expired['queue']->pending([$expired['key']]),'An expired preview changed queued intent');

    $unknown=$fixture('unknown'); $unknown['api']=new \ApiServiceLifecycle(static function () { throw new \RuntimeException('Synthetic text mentioning 404'); },$unknown['data'],$unknown['cache'],$unknown['jobs']);
    $preview=$unknown['api']->preview(71001); lifecyclePreviewCheck(!$preview['eligible'] && $preview['state']==='unknown','A text-only 404 authorized retirement');
    lifecyclePreviewCheck(strpos(json_encode([$preview,$result]),'PRIVATE')===false,'A transport diagnostic escaped the safe public proof');

    echo "Service lifecycle preview checks passed: missing-service/archived-client apply, consumed/expired tokens, purged-cache preflight, strict router recovery with fresh apply, immutable applied recovery, safe frozen cleanup identity, complete legacy review, missing router, running work, service-gate race, fresh-state change and unavailable authority.\n";
} finally {
    foreach ($queues as $queue) $queue->close();
    $resolved=realpath($directory); $temporaryRoot=realpath(sys_get_temp_dir());
    if ($resolved && $temporaryRoot && strpos($resolved,$temporaryRoot.DIRECTORY_SEPARATOR.'uisp-lifecycle-preview-')===0) {
        $files=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($resolved,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { if ($file->isDir()&&!$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
        rmdir($resolved);
    }
    restore_error_handler();
}
}
