<?php
require_once __DIR__.'/api_trim.php';
require_once __DIR__.'/api_site_address_policy.php';
require_once __DIR__.'/api_webhook_freshness.php';

/** Immediate router synchronization, one fresh service per request, through the ordinary guarded pipeline. */
final class ApiDeviceSync
{
    private const PAGE=500;
    private const MAX_SERVICES=20000;
    private $request;
    private $applier;
    private $retirer;
    private $clock;
    private string $dataPath;
    private string $cachePath;
    private string $jobsPath;
    private float $deadline=0;

    public function __construct(?callable $request=null,?callable $applier=null,?callable $retirer=null,
        string $dataPath='data/data.db',string $cachePath='data/cache.db',string $jobsPath='data/jobs.db',?callable $clock=null)
    { $this->request=$request;$this->applier=$applier;$this->retirer=$retirer;$this->dataPath=$dataPath;$this->cachePath=$cachePath;$this->jobsPath=$jobsPath;$this->clock=$clock; }

    public function begin(int $deviceId): array
    {
        [$configuration,$devices]=$this->configuration();
        if ($deviceId<1 || !isset($devices[$deviceId]) || ($devices[$deviceId]['type']??'mikrotik')!=='mikrotik')
            throw new RuntimeException('device_sync_router_unavailable');
        $key=$configuration['device_name_attr']??null;
        if (!is_string($key)||trim($key)==='') throw new RuntimeException('device_sync_configuration_missing');
        $name=strtolower(trim((string)$devices[$deviceId]['name']));$matches=[];
        foreach ($devices as $id=>$device) if (strtolower(trim((string)($device['name']??'')))===$name) $matches[]=(int)$id;
        if ($matches!==[$deviceId]) throw new RuntimeException('device_sync_router_ambiguous');
        $historical=$this->historical($deviceId);$token=bin2hex(random_bytes(32));
        $state=['schema'=>1,'expires'=>$this->now()+3600,'deviceId'=>$deviceId,'configuration'=>$this->configurationHash(),
            'ids'=>$historical,'historical'=>$historical,'seen'=>[],'offset'=>0,'catalogComplete'=>false,'cursor'=>0,
            'requested'=>0,'completed'=>0,'failed'=>0,'failures'=>[],'skipped'=>0];
        $directory=$this->directory();$create=$this->fileLock($directory.'/create.lock');
        try {$this->prune();$this->save($token,$state);}
        finally {flock($create,LOCK_UN);fclose($create);}
        return $this->progress($token,$state);
    }

    /** A consumed cursor is durable before any CRM or RouterOS write; uncertain requests are never replayed. */
    public function step(string $token): array
    {
        $run=$this->fileLock($this->path($token).'.run.lock');
        try {return $this->advance($token);}
        finally {flock($run,LOCK_UN);fclose($run);}
    }

    private function advance(string $token): array
    {
        $this->deadline=microtime(true)+20;
        $work=$this->locked($token,function(array &$state) use($token):array {
            $this->assertConfiguration($state);
            if (!$state['catalogComplete']) {
                try {$this->catalog($state);}
                catch(Throwable $error){throw new RuntimeException($error->getMessage()==='device_sync_limit'?'device_sync_limit':'device_sync_catalog_unavailable',0,$error);}
                return ['response'=>$this->progress($token,$state)];
            }
            if ($state['cursor']>=count($state['ids'])) return ['response'=>$this->progress($token,$state)];
            $sid=$state['ids'][$state['cursor']++];
            $state['requested']++;$state['failed']++;
            $state['failures'][(string)$sid]=['serviceId'=>$sid,'errorCode'=>'device_sync_unconfirmed'];
            return ['serviceId'=>$sid,'state'=>$state];
        });
        if (isset($work['response'])) return $work['response'];
        $sid=$work['serviceId'];$session=$work['state'];
        try { $item=$this->apply($sid,$session); }
        catch (Throwable $error) { $item=['serviceId'=>$sid,'state'=>'failed','reason'=>$this->reason($error),'jobKeys'=>[]]; }
        return $this->locked($token,function(array &$state) use($token,$sid,$item):array {
            if (isset($state['failures'][(string)$sid])) {
                if (in_array($item['state'],['completed','skipped'],true)) {
                    unset($state['failures'][(string)$sid]);$state['failed']--;
                    if ($item['state']==='completed') $state['completed']++;
                    else {$state['skipped']++;$state['requested']--;}
                } else $state['failures'][(string)$sid]=['serviceId'=>$sid,'errorCode'=>$item['reason']];
            }
            return $this->progress($token,$state)+['item'=>$item];
        });
    }

    private function catalog(array &$state): void
    {
        $page=$this->get('clients/services',['limit'=>self::PAGE,'offset'=>$state['offset'],'statuses'=>[0,1,2,3,4,5,6,7,8]]);
        if (!is_array($page)||count($page)>self::PAGE || array_keys($page)!==array_keys(array_values($page)))
            throw new RuntimeException('device_sync_catalog_unavailable');
        $db=new ApiSqlite($this->dataPath,true);$trimmer=new ApiTrim(new ApiAttributes($db));
        try { foreach ($page as $raw) {
            $value=(array)$raw;$sid=self::id($value['id']??null);
            if ((!is_array($raw)&&!is_object($raw)) || $sid===null || !is_array($value['attributes']??null) || isset($state['seen'][(string)$sid]))
                throw new RuntimeException('device_sync_catalog_unavailable');
            $state['seen'][(string)$sid]=true;
            try { $normalized=$trimmer->trim('service',json_decode(json_encode($raw,JSON_THROW_ON_ERROR)))['entity']; }
            catch (InvalidArgumentException $ignored) { continue; }
            if ((int)($normalized['device']??0)===$state['deviceId']) $state['ids'][]=$sid;
        } } finally { $db->close(); }
        if (count($state['seen'])>self::MAX_SERVICES) throw new RuntimeException('device_sync_limit');
        $state['ids']=array_values(array_unique($state['ids']));sort($state['ids'],SORT_NUMERIC);
        if (count($state['ids'])>self::MAX_SERVICES) throw new RuntimeException('device_sync_limit');
        $state['offset']+=count($page);$state['catalogComplete']=count($page)<self::PAGE;
    }

    private function apply(int $sid,array $session): array
    {
        $historical=in_array($sid,$session['historical'],true);
        $fresh=(new ApiWebhookFreshness(fn(string $path)=>$this->get($path),$this->deadline))->current($sid,'review');
        if ($fresh['reason']!==null) throw new RuntimeException($fresh['reason']);
        if ($fresh['missing']) {
            if (!$historical) return $this->skip($sid,'device_sync_service_absent');
            return $this->retire($sid,$session);
        }
        $cid=self::id($fresh['entity']->clientId??null);
        if ($cid===null) throw new RuntimeException('device_sync_client_unconfirmed');
        $terminal=in_array((int)$fresh['entity']->status,[2,5,8],true);
        if ($terminal && $historical) return $this->retire($sid,$session);
        $client=$terminal?null:$this->client($cid);
        if ($historical && $client!==null && $client['isArchived']) return $this->retire($sid,$session);
        $service=$this->normalize($fresh['entity']);
        $selected=(int)($service['device']??0)===$session['deviceId'];
        if (!$selected && !$historical) return $this->skip($sid,'device_sync_site_changed');
        if ($terminal || $client['isArchived']) return $this->retire($sid,$session);
        if (!$selected) return $this->skip($sid,'device_sync_site_changed');
        if (!in_array((int)$service['status'],[1,3,4,7],true)) return $this->skip($sid,'device_sync_service_inactive');
        $clientNames=array_intersect_key($client,array_flip(['company','firstName','lastName']));
        $namesReader=static function(int $id) use($cid,&$clientNames):?array {return $id===$cid?$clientNames:null;};
        $guard=function(array $current,array $applied) use($session,$cid,$sid,&$clientNames):bool {
            $this->assertConfiguration($session);
            if (self::id($current['id']??null)!==$sid || (int)($current['device']??0)!==$session['deviceId'] || self::id($current['clientId']??null)!==$cid
                || !in_array((int)($current['status']??-1),[1,3,4,7],true)) return false;
            $latest=$this->client($cid);
            if ($latest['isArchived']) return false;
            $clientNames=array_intersect_key($latest,array_flip(['company','firstName','lastName']));return true;
        };
        if ($this->applier!==null) $response=($this->applier)($sid,true,$guard,$namesReader);
        else {
            require_once __DIR__.'/api_action.php';
            $action=new ApiAction(null,new ApiWebhookFreshness(fn(string $path)=>$this->get($path),$this->deadline));
            $response=$action->reconcileService($sid,false,true,$guard,null,$namesReader);
            if ($action->status()->error) return ['serviceId'=>$sid,'state'=>'failed',
                'reason'=>$response['reason']??'device_sync_pending','jobKeys'=>$this->keys($response['jobKeys']??[])];
        }
        if (!is_array($response)) throw new RuntimeException('device_sync_unconfirmed');
        $provisioning=$response['provisioning']??null;
        $complete=in_array($provisioning,['applied','already_applied'],true) && (int)($response['jobCounts']['pending']??0)===0;
        return ['serviceId'=>$sid,'state'=>$complete?'completed':'failed','reason'=>$complete?null:($response['reason']??'device_sync_pending'),
            'jobKeys'=>$this->keys($response['jobKeys']??[])];
    }

    private function retire(int $sid,array $session): array
    {
        $this->assertConfiguration($session);
        if ($this->retirer!==null) $result=($this->retirer)($sid,$this->deadline);
        else {
            require_once __DIR__.'/api_service_lifecycle.php';require_once __DIR__.'/api_job_queue.php';
            $queue=myJobQueue();
            $lifecycle=new ApiServiceLifecycle(fn(string $path)=>$this->get($path),$this->dataPath,$this->cachePath,$this->jobsPath,null,$this->deadline);
            $result=$lifecycle->review($sid,$queue);
            if (!empty($result['retired']) && !empty($result['jobKeys'])) {
                $queue->runSelected($result['jobKeys']);$result['released']=$queue->completed($result['jobKeys']);
            }
        }
        $complete=is_array($result)&&($result['retired']??false)===true&&($result['released']??false)===true;
        return ['serviceId'=>$sid,'state'=>$complete?'completed':'failed','reason'=>$complete?null:'device_sync_retirement_pending',
            'jobKeys'=>$this->keys(is_array($result)?($result['jobKeys']??[]):[])];
    }

    private function client(int $cid): array
    {
        $raw=$this->get('clients/'.$cid);
        if (!is_array($raw)&&!is_object($raw)) throw new RuntimeException('device_sync_client_unconfirmed');
        $raw=(array)$raw;
        if (self::id($raw['id']??null)!==$cid || !array_key_exists('isArchived',$raw)
            || !in_array($raw['isArchived'],[true,false,0,1,'0','1'],true)) throw new RuntimeException('device_sync_client_unconfirmed');
        $names=[];
        foreach (['company'=>'companyName','firstName'=>'firstName','lastName'=>'lastName'] as $field=>$source) {
            $value=$raw[$source]??null;
            if ($value!==null&&!is_string($value)) throw new RuntimeException('device_sync_client_unconfirmed');
            $names[$field]=$value;
        }
        return ['id'=>$cid,'isArchived'=>in_array($raw['isArchived'],[true,1,'1'],true)]+$names;
    }

    private function normalize(object $raw): array
    {
        $db=new ApiSqlite($this->dataPath,true);
        try { return (new ApiTrim(new ApiAttributes($db)))->trim('service',$raw)['entity']; }
        finally { $db->close(); }
    }

    private function historical(int $deviceId): array
    {
        $ids=[];
        foreach ([$this->dataPath,$this->cachePath] as $path) {
            if (!is_file($path)) continue;$db=new ApiSqlite($path,true);
            try { foreach ($db->selectCustom('SELECT id FROM services WHERE device=? LIMIT 20001',[$deviceId])??[] as $row)
                if (($sid=self::id($row['id']??null))!==null) $ids[$sid]=$sid;
            } finally { $db->close(); }
        }
        if (is_file($this->jobsPath)) {
            $db=new ApiSqlite($this->jobsPath,true);
            try { foreach ($db->selectCustom('SELECT service_id,applied_payload FROM service_versions WHERE applied_payload IS NOT NULL LIMIT 20001')??[] as $row) {
                if (!is_string($row['applied_payload'])||strlen($row['applied_payload'])>1048576) throw new RuntimeException('device_sync_limit');
                $snapshot=json_decode($row['applied_payload'],true,64,JSON_THROW_ON_ERROR);
                if ((int)($snapshot['device']??0)===$deviceId && ($sid=self::id($row['service_id']))!==null) $ids[$sid]=$sid;
            } } finally { $db->close(); }
        }
        if (count($ids)>self::MAX_SERVICES) throw new RuntimeException('device_sync_limit');
        $ids=array_values($ids);sort($ids,SORT_NUMERIC);return $ids;
    }

    private function configuration(): array { return ApiSiteAddressPolicy::stored($this->dataPath); }
    private function configurationHash(): string
    {
        [$configuration,$devices]=$this->configuration();
        foreach (['version','cache_version','last_cache','next_cache','last_net','next_sites'] as $key) unset($configuration[$key]);
        ksort($configuration);ksort($devices);
        $runtime=defined('MY_VERSION')?MY_VERSION:hash_file('sha256',__FILE__);
        return hash('sha256',json_encode([$runtime,$configuration,$devices],JSON_THROW_ON_ERROR));
    }
    private function assertConfiguration(array $state): void
    { if (!hash_equals($state['configuration'],$this->configurationHash())) throw new RuntimeException('device_sync_configuration_changed'); }
    private function skip(int $sid,string $reason): array { return ['serviceId'=>$sid,'state'=>'skipped','reason'=>$reason,'jobKeys'=>[]]; }
    private function keys(array $keys): array { return array_values(array_filter($keys,static fn($key):bool=>is_string($key)&&preg_match('/^Q[1-9][0-9]*$/D',$key)===1)); }
    private static function id($value): ?int
    { if(is_bool($value)||is_float($value))return null;$id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);return$id===false?null:$id; }
    private function now(): int { return $this->clock===null?time():(int)($this->clock)(); }
    private function reason(Throwable $error): string
    {
        $code=$error->getMessage();
        return in_array($code,['device_sync_configuration_changed','device_sync_client_unconfirmed','device_sync_router_unavailable',
            'device_sync_limit','device_sync_pending','device_sync_retirement_pending','device_sync_unconfirmed',
            'webhook_fresh_state_unavailable','webhook_fresh_state_invalid','webhook_fresh_review_blocked','webhook_state_unconfirmed'],true)
            ?$code:'device_sync_unconfirmed';
    }

    private function get(string $path,array $parameters=[])
    {
        if (microtime(true)>=$this->deadline) throw new RuntimeException('webhook_fresh_state_unavailable');
        if ($this->request!==null) $value=($this->request)($path,$parameters,$this->deadline);
        else {
            require_once __DIR__.'/api_uisp_client.php';$sdk=ApiUispClient::crm();
            if (class_exists(\GuzzleHttp\Client::class)) {
                $property=new ReflectionProperty($sdk,'client');if(PHP_VERSION_ID<80100)$property->setAccessible(true);
                $client=$property->getValue($sdk);if(!$client instanceof \GuzzleHttp\Client)throw new RuntimeException('webhook_fresh_state_unavailable');
                $remaining=max(0.1,$this->deadline-microtime(true));$options=$client->getConfig();
                $options['timeout']=min(5,$remaining);$options['connect_timeout']=min(2,$remaining);$options['read_timeout']=min(5,$remaining);
                $property->setValue($sdk,new \GuzzleHttp\Client($options));
            }
            $value=$sdk->get($path,$parameters);
        }
        if (microtime(true)>=$this->deadline) throw new RuntimeException('webhook_fresh_state_unavailable');return $value;
    }

    private function progress(string $token,array $state): array
    {
        $done=$state['catalogComplete']&&$state['cursor']>=count($state['ids']);
        return ['syncToken'=>$token,'done'=>$done,'phase'=>$done?'complete':($state['catalogComplete']?'applying':'catalog'),
            'catalogScanned'=>count($state['seen']),'total'=>count($state['ids']),'processed'=>$state['cursor'],
            'requested'=>$state['requested'],'completed'=>$state['completed'],'failed'=>$state['failed'],'failures'=>array_values($state['failures']),'skipped'=>$state['skipped']];
    }
    private function directory(): string
    {
        $base=dirname($this->dataPath);if(!is_dir($base)||is_link($base))throw new RuntimeException('device_sync_storage_unavailable');
        $path=$base.'/device-syncs';if(is_link($path)||(!is_dir($path)&&!mkdir($path,0700)))throw new RuntimeException('device_sync_storage_unavailable');
        if(!chmod($path,0700))throw new RuntimeException('device_sync_storage_unavailable');return$path;
    }
    private function path(string $token): string
    { if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new InvalidArgumentException('device_sync_invalid_token');return$this->directory().'/'.hash('sha256',$token).'.json'; }
    private function fileLock(string $path)
    {
        if(is_link($path)||(file_exists($path)&&!is_file($path)))throw new RuntimeException('device_sync_invalid_token');
        $file=fopen($path,'c+b');if(!$file||!flock($file,LOCK_EX|LOCK_NB)){if($file)fclose($file);throw new RuntimeException('device_sync_busy');}
        if(!chmod($path,0600)){flock($file,LOCK_UN);fclose($file);throw new RuntimeException('device_sync_storage_unavailable');}return$file;
    }
    private function prune(): void
    {
        $count=0;$directory=$this->directory();
        foreach(new FilesystemIterator($directory,FilesystemIterator::SKIP_DOTS)as$entry){
            if($entry->isLink()||!$entry->isFile()||!preg_match('/^[a-f0-9]{64}\.json$/D',$entry->getFilename()))continue;
            $count++;$path=$entry->getPathname();$expired=$entry->getMTime()<$this->now()-3600;
            if($entry->getSize()<=8388608){try{$state=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);if(is_int($state['expires']??null))$expired=$state['expires']<$this->now();}catch(Throwable$ignored){}}
            if(!$expired)continue;
            $run=null;$stateLock=null;
            try{$run=$this->fileLock($path.'.run.lock');$stateLock=$this->fileLock($path.'.lock');
                if(is_file($path)&&!is_link($path)&&unlink($path)){$count--;}
            }catch(Throwable$ignored){}
            finally{foreach([$stateLock,$run]as$file)if(is_resource($file)){flock($file,LOCK_UN);fclose($file);}}
            if(!is_file($path))foreach([$path.'.lock',$path.'.run.lock']as$lock)if(is_file($lock)&&!is_link($lock))unlink($lock);
        }
        // Old invalid/expired token requests may have left only their guard files.
        foreach(new FilesystemIterator($directory,FilesystemIterator::SKIP_DOTS)as$entry){
            if($entry->isLink()||!$entry->isFile()||!preg_match('/^([a-f0-9]{64}\.json)\.(?:run\.)?lock$/D',$entry->getFilename(),$match)
                ||$entry->getMTime()>=$this->now()-3600||is_file($directory.'/'.$match[1]))continue;
            $file=null;try{$file=$this->fileLock($entry->getPathname());flock($file,LOCK_UN);fclose($file);$file=null;unlink($entry->getPathname());}catch(Throwable$ignored){}
            finally{if(is_resource($file)){flock($file,LOCK_UN);fclose($file);}}
        }
        if($count>=64)throw new RuntimeException('device_sync_session_limit');
    }
    private function save(string $token,array $state): void
    {
        $path=$this->path($token);if(is_link($path))throw new RuntimeException('device_sync_invalid_token');
        $json=json_encode($state,JSON_THROW_ON_ERROR);if(strlen($json)>8388608)throw new RuntimeException('device_sync_limit');
        $temporary=tempnam(dirname($path),'.sync-');
        try { if(!chmod($temporary,0600))throw new RuntimeException('device_sync_storage_unavailable');
            $file=fopen($temporary,'wb');if(!$file)throw new RuntimeException('device_sync_storage_unavailable');
            try { if(fwrite($file,$json)!==strlen($json)||!fflush($file)||(function_exists('fsync')&&!fsync($file)))throw new RuntimeException('device_sync_storage_unavailable'); }
            finally {fclose($file);}
            if(!rename($temporary,$path))throw new RuntimeException('device_sync_storage_unavailable');
        } finally {if(is_file($temporary))unlink($temporary);}
    }
    private function locked(string $token,callable $operation): array
    {
        $path=$this->path($token);$lockPath=$path.'.lock';
        if(is_link($path)||is_link($lockPath))throw new InvalidArgumentException('device_sync_invalid_token');
        $lock=fopen($lockPath,'c+b');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);throw new RuntimeException('device_sync_busy');}
        try {if(!chmod($lockPath,0600)||!is_file($path)||filesize($path)>8388608)throw new InvalidArgumentException('device_sync_invalid_token');
            $state=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
            if(!is_array($state)||($state['schema']??null)!==1||!is_int($state['expires']??null)||$state['expires']<$this->now())throw new RuntimeException('device_sync_expired');
            $result=$operation($state);$this->save($token,$state);return$result;
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
}
