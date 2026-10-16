<?php
require_once __DIR__.'/api_service_resolution_evidence.php';
require_once __DIR__.'/api_job_queue.php';
require_once __DIR__.'/api_shared_resources.php';
require_once __DIR__.'/api_provisioning_verification.php';

/** Explicit repair of a reviewed principal. Tokens never carry credentials or permission to alter another account. */
final class ApiServiceResolution
{
    private $request;
    private $reader;
    private $writer;
    private $applier;
    private $clock;
    private string $dataPath;
    private string $cachePath;
    private string $jobsPath;
    private float $deadline=0;
    private const PATHS=[
        'ppp/secret'=>'.id name comment profile remote-address remote-ipv6-prefix local-address caller-id service disabled password routes ipv6-routes limit-bytes-in limit-bytes-out limit-bytes-total',
        'ip/hotspot/user'=>'.id name comment profile address mac-address disabled password server limit-uptime limit-bytes-in limit-bytes-out limit-bytes-total email',
        'ip/dhcp-server/lease'=>'.id address mac-address client-id comment address-lists lease-time server disabled dynamic always-broadcast use-src-mac allow-dual-stack-queue block-access dhcp-option dhcp-option-set insert-queue-before parent-queue queue-type rate-limit',
        'ipv6/dhcp-server/binding'=>'.id address duid iaid comment address-lists life-time server disabled dynamic prefix-pool',
        'queue/simple'=>'.id name comment target max-limit limit-at parent burst-limit burst-threshold burst-time priority queue disabled packet-marks interface total-max-limit total-limit-at total-queue',
        'ppp/profile'=>'.id name parent-queue',
        'ip/hotspot/user/profile'=>'.id name parent-queue',
    ];

    public function __construct(?callable $request=null,?callable $reader=null,?callable $writer=null,?callable $applier=null,
        string $dataPath='data/data.db',string $cachePath='data/cache.db',string $jobsPath='data/jobs.db',?callable $clock=null)
    {
        $this->request=$request;$this->reader=$reader;$this->writer=$writer;$this->applier=$applier;
        $this->dataPath=$dataPath;$this->cachePath=$cachePath;$this->jobsPath=$jobsPath;$this->clock=$clock;
    }

    public function preview(int $sid): array
    {
        if($sid<1)throw new InvalidArgumentException('resolution_invalid_request');
        $this->deadline=microtime(true)+20;$evidence=$this->inspect($sid);$public=$evidence['public'];$mapping=[];
        foreach($evidence['choices']??[] as$choice){
            $candidate=bin2hex(random_bytes(16));$mapping[$candidate]=(string)$choice['rowId'];
            foreach($public['candidates']??[] as$index=>$row)if(($row['rowId']??null)===$choice['rowId'])$public['candidates'][$index]['candidateId']=$candidate;
        }
        foreach($public['candidates']??[]as$index=>$row)if(!isset($row['candidateId']))$public['candidates'][$index]['candidateId']=bin2hex(random_bytes(16));
        $token=null;$expires=null;
        if(($evidence['eligible']??false)===true){
            $token=bin2hex(random_bytes(32));$expires=$this->now()+600;
            $directory=$this->directory();$guard=@fopen($directory.DIRECTORY_SEPARATOR.'create.lock','c+b');
            if(!$guard||!flock($guard,LOCK_EX|LOCK_NB)){if($guard)fclose($guard);throw new RuntimeException('resolution_busy');}
            try{chmod($directory.DIRECTORY_SEPARATOR.'create.lock',0600);$this->prune();
                $this->privateWrite($this->tokenPath($token),['schema'=>1,'serviceId'=>$sid,'expires'=>$expires,
                    'fingerprint'=>$evidence['fingerprint'],'configurationHash'=>$evidence['configurationHash'],
                    'intentHash'=>$evidence['intentHash'],'candidates'=>$mapping,'paths'=>$this->pathsHash()]);
            }finally{flock($guard,LOCK_UN);fclose($guard);}
        }
        return array_replace($public,['serviceId'=>$sid,'mode'=>$evidence['mode'],'eligible'=>$evidence['eligible'],
            'autoApply'=>$evidence['autoApply'],'reason'=>$evidence['reason'],'previewToken'=>$token,
            'expiresAt'=>$expires===null?null:gmdate('c',$expires)]);
    }

    public function apply(string $token,array $selection=[]): array
    {
        if(array_keys($selection)!==array_keys(array_values($selection))||count($selection)>1
            ||($selection&&(!is_string($selection[0])||!preg_match('/^[a-f0-9]{32}$/D',$selection[0]))))
            throw new InvalidArgumentException('resolution_invalid_request');
        $state=$this->consume($token);$sid=$state['serviceId'];$this->deadline=microtime(true)+50;
        $result=['serviceId'=>$sid,'state'=>'blocked','reason'=>'resolution_changed','jobKeys'=>[],'verificationState'=>null,'backupName'=>null];
        $queue=$this->queue();$touched=false;$backup=null;
        try {
            return $queue->withAdministrativeLock(function()use($state,$selection,$sid,$queue,&$result,&$touched,&$backup):array{
                $first=$this->inspect($sid);$this->samePreview($state,$first);
                $lockIds=$this->ownerIds($first);
                $prepared=$queue->withServiceLocks($lockIds,function()use($first,$state,$selection,$sid,$queue,&$touched,&$backup):array{
                    return $queue->withResourceLock(ApiSharedResources::endpointIdentity((object)$first['device']),
                        function()use($state,$selection,$sid,$queue,&$touched,&$backup):array{
                            $evidence=$this->inspect($sid);$this->samePreview($state,$evidence);
                            $choice=$this->choose($state,$selection,$evidence);$cancel=$this->pendingUpdates($queue,$sid,$this->ownerIds($evidence));
                            $path=$evidence['path'];$device=$evidence['device'];$rows=[];
                            if($choice!==null){
                                $ids=array_merge([$choice['rowId']],$choice['removeRowIds']??[]);
                                foreach(array_unique($ids)as$rowId){
                                    $expected=$this->row($evidence['rows'],$rowId);
                                    $full=$this->read($device,$path,['.id'=>$rowId],explode(' ',self::PATHS[$path]));
                                    if(count($full)!==1||!$this->sameRow($expected,$full[0]))throw new RuntimeException('resolution_changed');
                                    if(in_array($rowId,$choice['removeRowIds']??[],true)&&in_array($path,['ppp/secret','ip/hotspot/user'],true)
                                        &&(!array_key_exists('password',$full[0])||!is_string($full[0]['password'])))
                                        throw new RuntimeException('resolution_backup_unavailable');
                                    $rows[$rowId]=$full[0];
                                }
                            }
                            $derived=[];
                            foreach($evidence['derivedRepairs']??[]as$repair){
                                if(($repair['path']??null)!=='queue/simple')throw new RuntimeException('resolution_changed');
                                $this->assertDerivedDependencies($device,$repair);
                                foreach($repair['rows']as$row){
                                    $full=$this->read($device,'queue/simple',['.id'=>$row['.id']],explode(' ',self::PATHS['queue/simple']));
                                    if(count($full)!==1||!$this->sameRow($row,$full[0]))throw new RuntimeException('resolution_changed');
                                    $derived[$row['.id']]=$full[0];
                                }
                            }
                            $backup=$this->backup($evidence,$rows,$choice,$derived);
                            if($choice!==null){
                                $selected=$rows[$choice['rowId']];
                                if(($selected['comment']??'')!==$evidence['targetComment']){
                                    $this->assertIntent($evidence);$touched=true;
                                    $this->write($device,$path,'set',$choice['rowId'],['comment'=>$evidence['targetComment']],$selected);
                                }
                                foreach($choice['removeRowIds']??[]as$rowId){
                                    $this->assertIntent($evidence);$touched=true;
                                    $this->write($device,$path,'remove',$rowId,[],$rows[$rowId]);
                                }
                            }
                            foreach($evidence['derivedRepairs']??[]as$repair)foreach($repair['removeRowIds']as$rowId){
                                $this->assertIntent($evidence);$touched=true;
                                $this->assertDerivedDependencies($device,$repair);
                                $this->write($device,'queue/simple','remove',$rowId,[],$derived[$rowId]);
                            }
                            $this->assertIntent($evidence);
                            if($cancel){
                                $touched=true;$cancelled=$queue->delete($cancel);
                                foreach($cancel as$key)if(($cancelled[$key]??false)!==true)throw new RuntimeException('resolution_queue_changed');
                            }
                            return$evidence;
                        });
                });
                // The ordinary action takes its own service and physical-resource gates.
                $names=$prepared['clientNames'];$guard=function(array$fresh,array$applied)use($prepared):bool{
                    $current=$this->inspect((int)$prepared['serviceId']);
                    if(($current['eligible']??false)!==true||$current['configurationHash']!==$prepared['configurationHash']
                        ||$current['intentHash']!==$prepared['intentHash']||$current['targetComment']!==$prepared['targetComment'])return false;
                    foreach(['id','clientId','device','status','planId','username','password','mac','hotspot','duid','iaid','callerId','network','uploadSpeed','downloadSpeed']as$field)
                        if(($fresh[$field]??null)!=($prepared['service'][$field]??null))return false;
                    return true;
                };
                $nameReader=static fn(int$cid):?array=>$cid===(int)$prepared['clientId']?$names:null;
                $beforeVersion=$this->version($sid);
                $touched=true;
                if($this->applier!==null)$applied=($this->applier)($sid,$guard,$nameReader,$queue);
                else{
                    require_once __DIR__.'/api_action.php';require_once __DIR__.'/api_webhook_freshness.php';
                    $freshness=new ApiWebhookFreshness(fn(string$path)=>$this->get($path));
                    $action=new ApiAction(null,$freshness);$applied=$action->reconcileService($sid,false,true,$guard,null,$nameReader);
                }
                $keys=$this->keys(is_array($applied)?($applied['jobKeys']??[]):[]);$result['backupName']=$backup;
                $receipt=(new ApiProvisioningVerification(null,$this->dataPath,$this->jobsPath))->read($sid);
                // Immediate fresh reviews have no delivery UUID and may omit completed keys.
                // Accept only a newer current update receipt from this exact service/device.
                if(!$keys&&($applied['provisioning']??null)==='applied'&&($receipt['current']??false)===true
                    &&is_int($receipt['version']??null)&&$receipt['version']>$beforeVersion
                    &&($receipt['service_id']??null)===$sid&&($receipt['device_id']??null)===(int)$prepared['deviceId']
                    &&$this->receiptUpdate($sid,(int)$prepared['clientId'],$receipt['version'],$receipt['job_id']??null))$keys=[$receipt['job_id']];
                $result['jobKeys']=$keys;
                $result['verificationState']=in_array($receipt['state']??null,['verified','mismatch','unconfirmed'],true)?$receipt['state']:null;
                if(!$keys||!$queue->completed($keys)||!in_array($applied['provisioning']??null,['applied','already_applied'],true)){
                    $result['state']='pending';$result['reason']='resolution_pending';
                }elseif(($receipt['current']??false)===true&&($receipt['state']??null)==='verified'
                    &&in_array($receipt['job_id']??null,$keys,true)){
                    $final=$this->inspect($sid);
                    if(($final['eligible']??false)===true&&count($final['rows'])===1&&empty($final['derivedRepairs'])
                        &&$final['configurationHash']===$prepared['configurationHash']&&$final['intentHash']===$prepared['intentHash']
                        &&$final['targetComment']===$prepared['targetComment']){
                        $result['state']='resolved';$result['reason']='resolution_verified';
                    }else{$result['state']='unconfirmed';$result['reason']='resolution_verification_unconfirmed';}
                }else{$result['state']='unconfirmed';$result['reason']='resolution_verification_unconfirmed';}
                return$result;
            });
        }catch(Throwable$error){
            $result['state']=$touched?'unconfirmed':'blocked';$result['reason']=$this->reason($error);$result['backupName']=$backup;
            return$result;
        }finally{if(!function_exists('myJobQueue')||$this->jobsPath!=='data/jobs.db')$queue->close();}
    }

    private function inspect(int$sid):array
    {
        if(microtime(true)>=$this->deadline)throw new RuntimeException('resolution_timeout');
        return(new ApiServiceResolutionEvidence(fn(string$p,array$params=[])=>$this->get($p,$params),fn(array$d,string$p,array$f,array$fields):array=>$this->read($d,$p,$f,$fields),
            $this->dataPath,$this->cachePath,$this->jobsPath,$this->deadline))->inspect($sid);
    }
    private function samePreview(array$state,array$evidence):void
    {
        if(($evidence['eligible']??false)!==true||!is_string($evidence['fingerprint']??null)
            ||!hash_equals($state['fingerprint'],$evidence['fingerprint']))throw new RuntimeException('resolution_changed');
    }
    private function assertIntent(array$expected):void
    {
        $current=$this->inspect((int)$expected['serviceId']);
        if(($current['eligible']??false)!==true||$current['configurationHash']!==$expected['configurationHash']
            ||$current['intentHash']!==$expected['intentHash']||$current['targetComment']!==$expected['targetComment'])throw new RuntimeException('resolution_changed');
    }
    private function choose(array$state,array$selection,array$evidence):?array
    {
        if(!$selection){
            if(($evidence['autoApply']??false)!==true)throw new RuntimeException('resolution_selection_required');
            $rowId=$evidence['defaultRowId']??null;if($rowId===null){if($evidence['rows'])throw new RuntimeException('resolution_selection_required');return null;}
        }else{$rowId=$state['candidates'][$selection[0]]??null;if($rowId===null)throw new RuntimeException('resolution_invalid_selection');}
        foreach($evidence['choices']??[]as$choice)if($choice['rowId']===$rowId&&($choice['selectable']??false)===true)return$choice;
        throw new RuntimeException('resolution_invalid_selection');
    }
    private function ownerIds(array$evidence):array
    {
        $ids=[(int)$evidence['serviceId']];
        foreach($evidence['referencedOwners']??[]as$key=>$owner){
            $raw=is_array($owner)?($owner['serviceId']??$owner['service_id']??$owner['id']??null):$owner;
            if((is_int($raw)||is_string($raw))&&filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])!==false)$ids[]=(int)$raw;
        }
        foreach($evidence['retiredServiceIds']??[]as$id)if(is_int($id)&&$id>0)$ids[]=$id;
        return array_values(array_unique($ids));
    }
    private function pendingUpdates(ApiJobQueue$queue,int$sid,array$owners):array
    {
        $cancel=[];
        foreach($queue->list()as$key=>$job){
            $owner=(int)($job['serviceId']??$job['id']??0);
            if(!in_array($owner,$owners,true))continue;
            if(($job['state']??null)==='running')throw new RuntimeException('resolution_running');
            if($owner!==$sid)continue;
            if(($job['action']??null)!=='update')throw new RuntimeException('resolution_pending_cleanup');
            $cancel[]=$key;
        }
        return$cancel;
    }
    private function queue():ApiJobQueue
    {return$this->jobsPath==='data/jobs.db'&&function_exists('myJobQueue')?myJobQueue():new ApiJobQueue($this->jobsPath,null);}
    private function version(int$sid):int
    {
        $db=new SQLite3($this->jobsPath,SQLITE3_OPEN_READONLY);$db->enableExceptions(true);
        try{return(int)$db->querySingle('SELECT version FROM service_versions WHERE service_id='.$sid);}finally{$db->close();}
    }
    private function receiptUpdate(int$sid,int$cid,int$version,$key):bool
    {
        if(!is_string($key)||!preg_match('/^Q([1-9][0-9]*)$/D',$key,$match))return false;
        $db=new SQLite3($this->jobsPath,SQLITE3_OPEN_READONLY);$db->enableExceptions(true);
        try{$row=$db->querySingle('SELECT service_id,operation,status,version,payload FROM jobs WHERE id='.(int)$match[1],true);
            $payload=$row?json_decode($row['payload'],true):null;
            return$row&&((int)$row['service_id']===$sid)&&$row['operation']==='update'&&$row['status']==='succeeded'
                &&(int)$row['version']===$version&&is_array($payload)&&($payload['clientId']??null)===$cid;
        }finally{$db->close();}
    }
    private function row(array$rows,string$id):array
    {foreach($rows as$row)if(($row['.id']??null)===$id)return$row;throw new RuntimeException('resolution_changed');}
    private function sameRow(array$expected,array$actual):bool
    {
        foreach($expected as$key=>$value){if(!array_key_exists($key,$actual)||(string)$actual[$key]!== (string)$value)return false;}return true;
    }
    private function read(array$device,string$path,array$filter,array$fields):array
    {
        $this->validateRead($path,$filter,$fields);
        if(microtime(true)>=$this->deadline)throw new RuntimeException('resolution_timeout');
        if($this->reader!==null)$rows=($this->reader)($device,$path,$filter,$fields);
        else$rows=$this->connection($device,fn(RouterosAPI$api):array=>$this->nativeRead($api,$path,$filter,$fields));
        if(!is_array($rows)||array_keys($rows)!==array_keys(array_values($rows))||count($rows)>20||microtime(true)>=$this->deadline)
            throw new RuntimeException('resolution_router_unavailable');
        foreach($rows as&$row){
            if(!is_array($row)||!is_string($row['.id']??null)||!preg_match('/^\*[a-fA-F0-9]+$/D',$row['.id']))throw new RuntimeException('resolution_router_unavailable');
            $row=array_intersect_key($row,array_fill_keys($fields,true));
            foreach($row as$value)if(!is_scalar($value)||strlen((string)$value)>16384)throw new RuntimeException('resolution_router_unavailable');
            foreach($filter as$key=>$value)if(!isset($row[$key])||(string)$row[$key]!== (string)$value){
                if($key==='mac-address'&&isset($row[$key])&&strcasecmp((string)$row[$key],(string)$value)===0)continue;
                if($key==='duid'&&isset($row[$key])&&$this->duid($row[$key])===$this->duid($value))continue;
                if($key==='iaid'&&isset($row[$key])&&(string)(int)$row[$key]===(string)(int)$value)continue;
                throw new RuntimeException('resolution_router_unavailable');
            }
        }unset($row);return$rows;
    }
    private function validateRead(string$path,array$filter,array$fields):void
    {
        $allowed=explode(' ',implode(' ',self::PATHS));
        if(!isset(self::PATHS[$path])||!$filter||count($filter)>3||!$fields||array_diff($fields,$allowed))
            throw new RuntimeException('resolution_router_unavailable');
        foreach($filter as$key=>$value)if(!in_array($key,['.id','name','mac-address','duid','iaid','parent','parent-queue'],true)||!is_scalar($value)
            ||strlen((string)$value)>256||preg_match('/[\x00-\x1f\x7f]/',(string)$value))throw new RuntimeException('resolution_router_unavailable');
    }
    private function nativeRead(RouterosAPI$api,string$path,array$filter,array$fields):array
    {
        $api->write('/'.$path.'/print',false);$api->write('=.proplist='.implode(',',$fields),false);
        foreach($filter as$key=>$value)$api->write('?'.$key.'='.$value,false);$api->finishSentence();$words=$api->read(false);
        if(!is_array($words)||count($words)>8192||!$words||end($words)!=='!done'||count(array_keys($words,'!done',true))!==1)
            throw new RuntimeException('resolution_router_unavailable');
        $rows=[];$row=null;
        foreach($words as$word){
            if($word==='!re'){if($row!==null)$rows[]=$row;$row=[];continue;}
            if($word==='!done'){if($row!==null)$rows[]=$row;continue;}
            if(!is_string($word)||strlen($word)>16448||$row===null||!preg_match('/^=([^=]+)=(.*)$/sD',$word,$match))throw new RuntimeException('resolution_router_unavailable');
            if(in_array($match[1],$fields,true)){if(array_key_exists($match[1],$row))throw new RuntimeException('resolution_router_unavailable');$row[$match[1]]=$match[2];}
        }return$rows;
    }
    private function connection(array$device,callable$operation)
    {
        require_once __DIR__.'/routeros_api.class.php';$api=new RouterosAPI();$api->timeout=2;$api->attempts=1;$api->debug=false;
        $api->port=(int)($device['port']??8728);$api->ssl=filter_var($device['ssl']??($api->port===8729),FILTER_VALIDATE_BOOLEAN);
        $api->allow_self_signed=filter_var($device['allowSelfSigned']??false,FILTER_VALIDATE_BOOLEAN);
        try{return$api->withReadDeadline($this->deadline,function()use($api,$device,$operation){
            if(!$api->connect($device['ip'],$device['user'],$device['password']??''))throw new RuntimeException('resolution_router_unavailable');return$operation($api);
        });}finally{$api->disconnect();}
    }
    private function write(array$device,string$path,string$action,string$id,array$values,array$expected):void
    {
        if(!in_array($path,['ppp/secret','ip/hotspot/user','ip/dhcp-server/lease','ipv6/dhcp-server/binding','queue/simple'],true)
            ||!in_array($action,['set','remove'],true)||($action==='set'&&array_keys($values)!==['comment']))throw new RuntimeException('resolution_invalid_request');
        $fields=array_keys($expected);$check=$this->read($device,$path,['.id'=>$id],$fields);
        if(count($check)!==1||!$this->sameRow($expected,$check[0]))throw new RuntimeException('resolution_changed');
        if($this->writer!==null){($this->writer)($device,$path,$action,$id,$values,$expected);}
        else$this->connection($device,function(RouterosAPI$api)use($path,$action,$id,$values,$expected,$fields):void{
            $rows=$this->nativeRead($api,$path,['.id'=>$id],$fields);
            if(count($rows)!==1||!$this->sameRow($expected,$rows[0]))throw new RuntimeException('resolution_changed');
            $response=$api->comm('/'.$path.'/'.$action,['.id'=>$id]+$values);
            if(!is_array($response)||isset($response['!trap'])||isset($response['!fatal'])||isset($response['!error']))throw new RuntimeException('resolution_write_unconfirmed');
            $rows=$this->nativeRead($api,$path,['.id'=>$id],array_values(array_unique(array_merge(['.id'],array_keys($values)))));
            if($action==='remove'?$rows!==[]:(count($rows)!==1||!$this->sameRow($values,$rows[0])))throw new RuntimeException('resolution_write_unconfirmed');
        });
        $after=$this->read($device,$path,['.id'=>$id],array_values(array_unique(array_merge(['.id'],array_keys($values)))));
        if($action==='remove'?$after!==[]:(count($after)!==1||!$this->sameRow($values,$after[0])))throw new RuntimeException('resolution_write_unconfirmed');
    }
    private function assertDerivedDependencies(array$device,array$repair):void
    {
        $name=$repair['selector']['name']??null;if(!is_string($name)||$name==='')throw new RuntimeException('resolution_derived_unverified');
        foreach(['queue/simple'=>'parent','ppp/profile'=>'parent-queue','ip/hotspot/user/profile'=>'parent-queue']as$path=>$field)
            if($this->read($device,$path,[$field=>$name],['.id','name',$field]))throw new RuntimeException('resolution_derived_in_use');
    }
    private function backup(array$evidence,array$rows,?array$choice,array$derived=[]):string
    {
        $name='router-resolution-'.gmdate('Ymd\THis\Z',$this->now()).'-'.bin2hex(random_bytes(8)).'.json';
        $this->privateWrite(dirname($this->dataPath).DIRECTORY_SEPARATOR.$name,['schema'=>1,'createdAt'=>gmdate('c',$this->now()),
            'serviceId'=>$evidence['serviceId'],'clientId'=>$evidence['clientId'],'deviceId'=>$evidence['deviceId'],
            'endpoint'=>ApiSharedResources::endpointIdentity((object)$evidence['device']),'path'=>$evidence['path'],
            'selectedRowId'=>$choice['rowId']??null,'removedRowIds'=>$choice['removeRowIds']??[],'rows'=>array_values($rows),
            'derivedRepairs'=>array_map(static fn(array$r):array=>['path'=>$r['path'],'keepRowId'=>$r['keepRowId'],
                'removedRowIds'=>$r['removeRowIds'],'rows'=>array_values(array_intersect_key($derived,array_fill_keys(array_column($r['rows'],'.id'),true)))],$evidence['derivedRepairs']??[])]);
        return$name;
    }
    public static function backupName($name):bool
    {return is_string($name)&&preg_match('/^router-resolution-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}\.json$/D',$name)===1;}
    public static function download(string$name):void
    {
        $path='data'.DIRECTORY_SEPARATOR.$name;
        if(!self::backupName($name)||is_link($path)||!is_file($path))throw new InvalidArgumentException('resolution_backup_unavailable');
        header('Content-Type: application/json');header('Content-Disposition: attachment; filename="'.$name.'"');header('Cache-Control: no-store');
        if(readfile($path)===false)throw new RuntimeException('resolution_backup_unavailable');
    }
    private function privateWrite(string$path,array$value):void
    {
        $stream=@fopen($path,'x+b');if(!$stream)throw new RuntimeException('resolution_storage_unavailable');
        try{if(!chmod($path,0600))throw new RuntimeException('resolution_storage_unavailable');$json=json_encode($value,JSON_THROW_ON_ERROR);
            if(fwrite($stream,$json)!==strlen($json)||!fflush($stream))throw new RuntimeException('resolution_storage_unavailable');
        }finally{fclose($stream);}
    }
    private function directory():string
    {
        $directory=dirname($this->dataPath).DIRECTORY_SEPARATOR.'service-resolution-previews';
        if(is_link($directory)||(!is_dir($directory)&&!mkdir($directory,0700,true)))throw new RuntimeException('resolution_storage_unavailable');
        return$directory;
    }
    private function tokenPath(string$token):string
    {if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new InvalidArgumentException('resolution_invalid_token');return$this->directory().DIRECTORY_SEPARATOR.$token.'.json';}
    private function pathsHash():string{return hash('sha256',json_encode([$this->dataPath,$this->cachePath,$this->jobsPath]));}
    private function prune():void
    {
        $count=0;
        foreach(new DirectoryIterator($this->directory())as$file){
            if(!$file->isFile()||$file->isLink()||!preg_match('/^[a-f0-9]{64}\.json$/D',$file->getFilename()))continue;
            $state=$file->getSize()<=32768?json_decode(file_get_contents($file->getPathname()),true):null;
            if(!is_array($state)||!is_int($state['expires']??null)||$state['expires']<=$this->now()){@unlink($file->getPathname());continue;}
            $count++;
        }
        if($count>=32)throw new RuntimeException('resolution_preview_limit');
    }
    private function get(string$path,array$parameters=[])
    {
        if(microtime(true)>=$this->deadline)throw new RuntimeException('resolution_timeout');
        if($this->request!==null)$result=($this->request)($path,$parameters);
        else{
            require_once __DIR__.'/api_uisp_client.php';$sdk=ApiUispClient::crm();
            if(class_exists(\GuzzleHttp\Client::class)){
                $property=new ReflectionProperty($sdk,'client');if(PHP_VERSION_ID<80100)$property->setAccessible(true);$client=$property->getValue($sdk);
                if(!$client instanceof \GuzzleHttp\Client)throw new RuntimeException('resolution_unavailable');$remaining=max(0.1,$this->deadline-microtime(true));
                $options=$client->getConfig();$options['timeout']=min(2,$remaining);$options['connect_timeout']=min(1,$remaining);$options['read_timeout']=min(2,$remaining);
                $property->setValue($sdk,new \GuzzleHttp\Client($options));
            }
            $result=$sdk->get($path,$parameters);
        }
        if(microtime(true)>=$this->deadline)throw new RuntimeException('resolution_timeout');return$result;
    }
    private function duid($value):string{return preg_replace('/^0x/','',preg_replace('/[:\s-]/','',strtolower((string)$value)));}
    private function consume(string$token):array
    {
        $path=$this->tokenPath($token);if(is_link($path)||!is_file($path)||filesize($path)>32768)throw new InvalidArgumentException('resolution_invalid_token');
        $stream=@fopen($path,'r+b');if(!$stream||!flock($stream,LOCK_EX|LOCK_NB)){if($stream)fclose($stream);throw new InvalidArgumentException('resolution_invalid_token');}
        try{$state=json_decode(stream_get_contents($stream),true,16,JSON_THROW_ON_ERROR);
            if(!is_array($state)||($state['schema']??null)!==1||!is_int($state['serviceId']??null)||$state['serviceId']<1
                ||!is_int($state['expires']??null)||$state['expires']<=$this->now()||($state['paths']??null)!==$this->pathsHash()
                ||!is_string($state['fingerprint']??null)||!is_array($state['candidates']??null))throw new InvalidArgumentException('resolution_invalid_token');
            if(!ftruncate($stream,0)||!fflush($stream))throw new RuntimeException('resolution_storage_unavailable');return$state;
        }finally{flock($stream,LOCK_UN);fclose($stream);@unlink($path);}
    }
    private function keys(array$keys):array{return array_values(array_filter($keys,static fn($key)=>is_string($key)&&preg_match('/^Q[1-9][0-9]*$/D',$key)));}
    private function now():int{return$this->clock===null?time():(int)($this->clock)();}
    private function reason(Throwable$error):string
    {
        $allowed=['resolution_changed','resolution_selection_required','resolution_invalid_selection','resolution_running','resolution_pending_cleanup',
            'resolution_backup_unavailable','resolution_queue_changed','resolution_router_unavailable','resolution_write_unconfirmed','resolution_timeout','resolution_storage_unavailable',
            'resolution_derived_unverified','resolution_derived_in_use'];
        return in_array($error->getMessage(),$allowed,true)?$error->getMessage():'resolution_unconfirmed';
    }
}
