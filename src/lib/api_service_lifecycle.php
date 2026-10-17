<?php

/** Fresh billing lifecycle retires obsolete intent; router ownership is never inferred from an IP. */
final class ApiServiceLifecycle
{
    private $request;
    private $clock;
    private $routerReader;
    private string $dataPath;
    private string $cachePath;
    private string $jobsPath;
    private ?float $deadline;
    private array $sources=[];

    public function __construct(?callable $request=null,string $dataPath='data/data.db',string $cachePath='data/cache.db',
        string $jobsPath='data/jobs.db',?callable $clock=null,?float $deadline=null,?callable $routerReader=null)
    { $this->request=$request; $this->dataPath=$dataPath; $this->cachePath=$cachePath; $this->jobsPath=$jobsPath; $this->clock=$clock; $this->deadline=$deadline; $this->routerReader=$routerReader; }

    public function inspect(int $sid): array
    {
        if ($sid<1) throw new InvalidArgumentException('lifecycle_invalid');
        $result=['service_id'=>$sid,'client_id'=>null,'state'=>'unknown','reason'=>'lifecycle_unavailable','status'=>null,'missing'=>false];
        try {
            try { $service=$this->get('clients/services/'.$sid); }
            catch (Throwable $error) {
                if (!$this->notFound($error)) throw $error;
                $result['client_id']=$this->localClient($sid); $result['missing']=true;
                $result['state']='retired'; $result['reason']='service_not_found';
                return $this->proof($result);
            }
            if (!is_array($service)&&!is_object($service)) return $this->invalid($result);
            $service=(array)$service; $cid=self::id($service['clientId']??null); $status=self::status($service['status']??null);
            if (self::id($service['id']??null)!==$sid || $cid===null || $status===null) return $this->invalid($result);
            $result['client_id']=$cid; $result['status']=$status;
            // The local identity may be missing, but an explicit different owner cannot be silently retired.
            $local=$this->localClient($sid);
            if ($local!==null && $local!==$cid) { $result['reason']='lifecycle_identity_changed'; return $this->proof($result); }
            if (in_array($status,[2,5,8],true)) {
                $result['state']='retired'; $result['reason']='service_ended'; return $this->proof($result);
            }
            $client=$this->get('clients/'.$cid);
            if (!is_array($client)&&!is_object($client)) return $this->invalid($result);
            $client=(array)$client;
            if (self::id($client['id']??null)!==$cid || !array_key_exists('isArchived',$client)
                || !in_array($client['isArchived'],[true,false,0,1,'0','1'],true)) return $this->invalid($result);
            $archived=in_array($client['isArchived'],[true,1,'1'],true);
            $result['state']=$archived?'retired':'active'; $result['reason']=$archived?'client_archived':'active_service';
            return $this->proof($result);
        } catch (Throwable $error) {
            if ($error->getMessage()==='lifecycle_identity_changed') $result['reason']='lifecycle_identity_changed';
            return $this->proof($result);
        }
    }

    public function withDeadline(float $deadline,callable $operation)
    {
        $previous=$this->deadline; $this->deadline=$previous===null?$deadline:min($previous,$deadline);
        try { return $operation(); } finally { $this->deadline=$previous; }
    }

    public function preview(int $sid): array
    {
        $proof=$this->inspect($sid); $token=null; $expires=null;
        if ($proof['state']==='retired') {
            // A billing fact alone cannot promise that local physical ownership
            // is recoverable. Check the same journal used by apply before
            // presenting an actionable retirement button.
            try { $this->previewResources($sid,$proof); }
            catch (Throwable $error) {
                $reason=$error->getMessage();
                $proof['reason']=in_array($reason,['lifecycle_changed','lifecycle_identity_changed',
                    'lifecycle_router_identity_unknown','lifecycle_resources_unconfirmed','lifecycle_resource_limit','lifecycle_service_busy'],true)
                    ?$reason:'lifecycle_unavailable';
                return $this->publicProof($proof)+['eligible'=>false,'previewToken'=>null,'expiresAt'=>null];
            }
            $token=bin2hex(random_bytes(32)); $expires=(int)$this->now()+600;
            $path=$this->tokenPath($token); $stream=@fopen($path,'x');
            if (!$stream) throw new RuntimeException('lifecycle_preview_failed');
            try {
                if (!chmod($path,0600)) throw new RuntimeException('lifecycle_preview_failed');
                $json=json_encode(['expires'=>$expires,'service_id'=>$sid,'fingerprint'=>$proof['fingerprint'],
                    'queue'=>hash('sha256',$this->jobsPath)],JSON_THROW_ON_ERROR);
                if (fwrite($stream,$json)!==strlen($json)||!fflush($stream)) throw new RuntimeException('lifecycle_preview_failed');
            } finally { fclose($stream); }
        }
        return $this->publicProof($proof)+['eligible'=>$token!==null,'previewToken'=>$token,'expiresAt'=>$expires===null?null:gmdate('c',$expires)];
    }

    /** No router writes or retirement mutations are performed while reviewing. */
    private function previewResources(int $sid,array $proof): void
    {
        require_once __DIR__.'/api_job_queue.php';
        $queue=new ApiJobQueue($this->jobsPath,null);
        try {
            $queue->withServiceLock($sid,function () use($sid,$proof,$queue): void {
                if ($this->running($sid)) throw new RuntimeException('lifecycle_service_busy');
                $this->snapshots($sid,$queue,$proof['client_id']);
                $fresh=$this->inspect($sid);
                if ($fresh['state']!=='retired' || !hash_equals($proof['fingerprint'],$fresh['fingerprint']))
                    throw new RuntimeException('lifecycle_changed');
            });
        } catch (Throwable $error) {
            if ($error->getMessage()==='Another event or maintenance operation is handling this service; retry this webhook')
                throw new RuntimeException('lifecycle_service_busy',0,$error);
            throw $error;
        } finally { $queue->close(); }
    }

    private function running(int $sid): bool
    {
        if (!is_file($this->jobsPath)) return false;
        $db=new SQLite3($this->jobsPath,SQLITE3_OPEN_READONLY); $db->enableExceptions(true);
        try { return $this->table($db,'jobs') && (bool)$this->query($db,"SELECT id FROM jobs WHERE service_id=? AND status='running' LIMIT 1",[$sid]); }
        finally { $db->close(); }
    }

    public function apply(string $token,?ApiJobQueue $queue=null): array
    {
        $path=$this->tokenPath($token);
        if (is_link($path)||!is_file($path)||filesize($path)>4096) throw new InvalidArgumentException('lifecycle_invalid_token');
        $stream=@fopen($path,'r+'); if (!$stream||!flock($stream,LOCK_EX|LOCK_NB)) throw new InvalidArgumentException('lifecycle_invalid_token');
        try {
            $state=json_decode(stream_get_contents($stream),true,16,JSON_THROW_ON_ERROR);
            if (!is_array($state)||!is_int($state['expires']??null)||$state['expires']<=$this->now()
                || self::id($state['service_id']??null)===null || ($state['queue']??null)!==hash('sha256',$this->jobsPath)
                || !is_string($state['fingerprint']??null)) throw new InvalidArgumentException('lifecycle_invalid_token');
            // Consumption precedes every mutation, including an uncertain HTTP response.
            if (!ftruncate($stream,0)||!fflush($stream)) throw new RuntimeException('lifecycle_invalid_token');
            return $this->review($state['service_id'],$queue,$state['fingerprint']);
        } finally { flock($stream,LOCK_UN); fclose($stream); @unlink($path); }
    }

    /** No HTTP request is made while either SQLite write transaction is held. */
    public function review(int $sid,?ApiJobQueue $queue=null,?string $expected=null,bool $terminalContext=false,bool $billingContext=false,?string $identityField=null): array
    {
        if ($identityField!==null && !in_array($identityField,['username','mac'],true)) throw new InvalidArgumentException('lifecycle_invalid');
        require_once __DIR__.'/api_job_queue.php'; $queue=$queue??new ApiJobQueue($this->jobsPath,null); $queue->setLifecycle($this);
        $operation=function () use($sid,$queue,$expected,$terminalContext,$billingContext,$identityField): array {
            $first=$terminalContext&&!$billingContext?$this->terminalProof($sid,$identityField):$this->inspect($sid); $public=$this->publicProof($first);
            $result=$public+['retired'=>false,'queued'=>0,'jobKeys'=>[],'released'=>false];
            if ($expected!==null && !hash_equals($expected,$first['fingerprint'])) throw new InvalidArgumentException('lifecycle_changed');
            if ($first['state']!=='retired') {
                if ($first['state']==='active') {
                    $queue->cancelRetirement($sid,$terminalContext);
                    if ($terminalContext) $queue->deferFreshReview($sid,'webhook_state_unconfirmed');
                }
                return $result;
            }
            $resources=$this->snapshots($sid,$queue,$first['client_id']);
            $second=$terminalContext&&!$billingContext?$this->terminalProof($sid,$identityField):$this->inspect($sid);
            if ($second['state']!=='retired'||!hash_equals($first['fingerprint'],$second['fingerprint'])) throw new InvalidArgumentException('lifecycle_changed');
            $keys=$queue->retireService($sid,$resources,$second);
            if (!$keys) $this->releaseLocal($sid);
            $result['retired']=true; $result['queued']=count($keys); $result['jobKeys']=$keys; $result['released']=!$keys;
            $result['reason']=$keys?'lifecycle_router_cleanup_pending':'lifecycle_retired';
            return $result;
        };
        try { return $queue->ownsServiceLock($sid)?$operation():$queue->withServiceLock($sid,$operation); }
        catch (Throwable $error) {
            if ($error->getMessage()==='Another event or maintenance operation is handling this service; retry this webhook')
                throw new RuntimeException('lifecycle_service_busy',0,$error);
            throw $error;
        }
    }

    public function reviewTerminal(int $sid,?ApiJobQueue $queue=null,bool $billingContext=false,?string $identityField=null): array
    { return $this->review($sid,$queue,null,true,$billingContext,$identityField); }

    /** Legacy terminal flags also represented a deliberately emptied Site. Confirm that distinction from a full GET. */
    private function terminalProof(int $sid,?string $identityField=null): array
    {
        $proof=$this->inspect($sid); if ($proof['state']!=='active') return $proof;
        try {
            require_once __DIR__.'/api_site_address_policy.php';
            [$configuration]=$this->storedConfiguration();
            $key=$configuration['device_name_attr']??null;
            $catalog=$this->get('custom-attributes');
            $descriptor=ApiNetworkAddressEvidence::validateMapping(['address'=>$key,'address6'=>null],$catalog)['address'];
            if ($descriptor===null) throw new RuntimeException('lifecycle_unavailable');
            $service=$this->get('clients/services/'.$sid);
            if (!is_array($service)&&!is_object($service)) throw new RuntimeException('lifecycle_invalid');
            $service=(array)$service;
            if (self::id($service['id']??null)!==$sid || self::id($service['clientId']??null)!==$proof['client_id']
                || self::status($service['status']??null)!==$proof['status'] || !is_array($service['attributes']??null)
                || count($service['attributes'])>512 || array_keys($service['attributes'])!==array_keys(array_values($service['attributes']))) throw new RuntimeException('lifecycle_invalid');
            $site=null; $seen=false; $keys=[]; $values=[];
            foreach($service['attributes'] as $raw) {
                if (!is_array($raw)&&!is_object($raw)) throw new RuntimeException('lifecycle_invalid');
                $attribute=(array)$raw; $attributeKey=$attribute['key']??null;
                if (!is_string($attributeKey)||$attributeKey===''||isset($keys[$attributeKey])||!array_key_exists('value',$attribute)) throw new RuntimeException('lifecycle_invalid');
                $keys[$attributeKey]=true;
                $values[$attributeKey]=$attribute;
                $attributeId=array_key_exists('customAttributeId',$attribute)?self::id($attribute['customAttributeId']):null;
                if ($attributeKey!==$descriptor['key']) {
                    if ($attributeId===$descriptor['id']) throw new RuntimeException('lifecycle_invalid');
                    continue;
                }
                if ($seen || (array_key_exists('customAttributeId',$attribute)&&$attributeId!==$descriptor['id'])
                    || ($attribute['value']!==null&&!is_string($attribute['value']))) throw new RuntimeException('lifecycle_invalid');
                $seen=true; $site=$attribute['value'];
            }
            if ($site===null || trim($site)==='') {
                unset($proof['fingerprint'],$proof['checked_at']); $proof['state']='retired'; $proof['reason']='service_detached'; return $this->proof($proof);
            }
            if ($identityField!==null) {
                $setting=$identityField==='username'?'pppoe_user_attr':'mac_addr_attr';
                $otherSetting=$identityField==='username'?'mac_addr_attr':'pppoe_user_attr';
                $identity=ApiNetworkAddressEvidence::validateMapping(['address'=>$configuration[$setting]??null,'address6'=>null],$catalog)['address'];
                if ($identity===null) throw new RuntimeException('lifecycle_unavailable');
                $attribute=$values[$identity['key']]??null;
                // Clearing credentials is a separate, explicit intent. A missing/renamed field cannot authorize it.
                if ($attribute===null || (array_key_exists('customAttributeId',$attribute)&&self::id($attribute['customAttributeId'])!==$identity['id'])
                    || ($attribute['value']!==null&&!is_string($attribute['value']))) throw new RuntimeException('lifecycle_invalid');
                if ($attribute['value']!==null && trim($attribute['value'])!=='') return $proof;
                $otherKey=$configuration[$otherSetting]??null;
                if (is_string($otherKey) && isset($values[$otherKey])) {
                    $other=$values[$otherKey]['value'];
                    if ($other!==null && (!is_string($other)||trim($other)!=='')) return $proof;
                }
                unset($proof['fingerprint'],$proof['checked_at']);
                $proof['state']='retired'; $proof['reason']='service_identity_removed'; $proof['identity_field']=$identityField;
                return $this->proof($proof);
            }
            return $proof;
        } catch (Throwable $ignored) {
            unset($proof['fingerprint'],$proof['checked_at']); $proof['state']='unknown'; $proof['reason']='lifecycle_unavailable'; return $this->proof($proof);
        }
    }

    private function storedConfiguration(): array
    { return ApiSiteAddressPolicy::stored($this->dataPath); }

    /** A terminal cleanup is checked again inside its service gate before its first physical write. */
    public function assertRetired(int $sid,array $marker): void
    {
        $reason=$marker['reason']??null;
        $proof=in_array($reason,['service_detached','service_identity_removed'],true)
            ?$this->terminalProof($sid,$reason==='service_identity_removed'?($marker['identity_field']??null):null):$this->inspect($sid);
        if ($proof['state']!=='retired') throw new RuntimeException($proof['state']==='active'?'lifecycle_reactivated':'lifecycle_unavailable');
        if (isset($marker['client_id']) && $marker['client_id']!==null && $proof['client_id']!==$marker['client_id'])
            throw new RuntimeException('lifecycle_identity_changed');
    }

    /** Fair, bounded legacy sweep includes historical owners omitted from active-service cache scans. */
    public function tick(?ApiJobQueue $queue=null,int $limit=1): array
    {
        require_once __DIR__.'/api_job_queue.php'; $queue=$queue??new ApiJobQueue($this->jobsPath,null); $queue->setLifecycle($this);
        $deadline=$this->deadline??microtime(true)+8; $previous=$this->deadline; $this->deadline=$deadline;
        $result=['checked'=>0,'retired'=>0,'released'=>0,'queued'=>0]; $cursor=(int)($queue->lifecycleMeta('cursor')['service_id']??0);
        try {
            $ids=$this->candidates($cursor,max(1,min(50,$limit)));
            if (!$ids && $cursor>0) $ids=$this->candidates(0,max(1,min(50,$limit)));
            foreach ($ids as $sid) {
                if (microtime(true)>=$deadline) break;
                $queue->lifecycleMeta('cursor',['service_id'=>$sid]); $result['checked']++;
                try { $review=$this->review($sid,$queue); }
                catch (Throwable $ignored) { continue; }
                if ($review['retired']) $result['retired']++;
                if ($review['released']) $result['released']++;
                $result['queued']+=$review['queued'];
            }
        } finally { $this->deadline=$previous; }
        return $result;
    }

    /** On a real allocator collision, review only its recorded owner; never reinterpret the requester as the owner. */
    public function resolveConflict(Throwable $error,ApiJobQueue $queue,int $requester,?float $deadline=null): bool
    {
        $deadline=$deadline??microtime(true)+8;
        return $this->withDeadline($deadline,function () use($error,$queue,$requester): bool {
            $ids=[];
            if (class_exists('NetworkAddressConflict',false) && $error instanceof NetworkAddressConflict) {
                $sid=(int)($error->context()['blockingServiceId']??0);
                if ($sid>0 && $sid!==$requester) $ids[]=$sid;
            } elseif (in_array($error->getMessage(),['No IPv4 address is available for the PPP service','No address is available for the DHCP service'],true)) {
                $cursor=(int)($queue->lifecycleMeta('cursor')['service_id']??0);
                $ids=$this->candidates($cursor,8); if (!$ids) $ids=$this->candidates(0,8);
            }
            $changed=false;
            foreach($ids as $sid) {
                if ($sid===$requester || ($this->deadline!==null && microtime(true)>=$this->deadline)) continue;
                $queue->lifecycleMeta('cursor',['service_id'=>$sid]);
                try {
                    $review=$this->review($sid,$queue);
                    if (!$review['retired']) continue;
                    if ($review['jobKeys']) {
                        $queue->runSelected($review['jobKeys']);
                        if (!$queue->completed($review['jobKeys'])) continue;
                    }
                    $changed=true;
                } catch (Throwable $ignored) { /* A single unknown owner cannot redirect the rest of the pool. */ }
            }
            return $changed;
        });
    }

    /** Called before claiming old/retried work, rather than allowing it to consume another customer's IP. */
    public function requiresReview(array $job): bool
    {
        if (!in_array($job['operation']??'', ['update','queues_set'],true)) return false;
        if ((int)($job['attempts']??0)>0 || !empty($job['error'])) return true;
        $created=strtotime($job['created']??'');
        if ($created!==false && $created<=(int)$this->now()-60) return true;
        $sid=(int)($job['service_id']??0);
        foreach ([$this->cachePath,$this->dataPath] as $path) {
            $service=$this->row($path,'services',$sid);
            if (in_array(self::status($service['status']??null),[2,5,8],true)) return true;
            $cid=self::id($service['clientId']??null);
            if ($cid!==null && in_array($this->row($this->cachePath,'clients',$cid)['isArchived']??null,[true,1,'1'],true)) return true;
        }
        return false;
    }

    private function snapshots(int $sid,ApiJobQueue $queue,?int $cid): array
    {
        $resources=$queue->lifecycleSnapshots($sid);
        $legacy=$this->row($this->dataPath,'services',$sid); $cache=$this->row($this->cachePath,'services',$sid);
        if ($legacy) {
            $base=$cache;
            // Desired cache may already have lost its username/MAC. The applied row describes the old physical owner.
            foreach($resources as $recorded) {
                if ((int)($recorded['device']??0)===(int)($legacy['device']??-1)
                    && (int)($recorded['clientId']??0)===(int)($legacy['clientId']??-1)
                    && ((!empty($recorded['username'])&&is_string($recorded['username'])) || filter_var($recorded['mac']??'',FILTER_VALIDATE_MAC))) {
                    $base=$recorded; break;
                }
            }
            if (!$this->accountIdentityKnown($base)) {
                // A purged desired cache must not strand an applied legacy row,
                // nor may a newer unattempted username stand in for its owner.
                // Recover exact physical identities from their client/service
                // comments, then keep those recovered fields frozen verbatim.
                $did=self::id($legacy['device']??null); $legacyClient=self::id($legacy['clientId']??null);
                $device=$did===null?[]:$this->row($this->dataPath,'devices',$did);
                if ($legacyClient===null || ($cid!==null&&$legacyClient!==$cid) || !$device)
                    throw new RuntimeException('lifecycle_router_identity_unknown');
                require_once __DIR__.'/api_lifecycle_router_recovery.php';
                try { $recovered=(new ApiLifecycleRouterRecovery($this->routerReader,$this->deadline))->recover($legacy,$device,$cid); }
                catch (Throwable $error) {
                    if ($error->getMessage()==='lifecycle_router_unavailable')
                        throw new RuntimeException('lifecycle_resources_unconfirmed',0,$error);
                    throw $error;
                }
                if (!$recovered) throw new RuntimeException('lifecycle_router_identity_unknown');
                foreach ($recovered as $snapshot) $resources[]=$snapshot;
            } else $resources[]=array_replace($base,$legacy);
        }
        $result=[]; $seen=[];
        foreach ($resources as $snapshot) {
            if ((int)($snapshot['id']??0)!==$sid || self::id($snapshot['clientId']??null)===null
                || ($cid!==null && (int)$snapshot['clientId']!==$cid)) throw new RuntimeException('lifecycle_identity_changed');
            $did=self::id($snapshot['device']??null);
            if ($did===null || !$this->accountIdentityKnown($snapshot))
                throw new RuntimeException('lifecycle_router_identity_unknown');
            $device=$this->row($this->dataPath,'devices',$did);
            if (!$device || !is_string($device['ip']??null) || trim($device['ip'])==='') throw new RuntimeException('lifecycle_router_identity_unknown');
            if (!isset($snapshot['_device'])) $snapshot['_device']=$device;
            // Frozen endpoints keep precedence. Batch will reject an endpoint that has been repurposed.
            if (!is_array($snapshot['_device'])) throw new RuntimeException('lifecycle_router_identity_unknown');
            $snapshot['action']='delete'; unset($snapshot['_cleanup']);
            if (isset($snapshot['_commands'])) {
                if (!is_array($snapshot['_commands'])) throw new RuntimeException('lifecycle_router_identity_unknown');
                $commands=[]; $active=[]; $allRemoval=true;
                foreach ($snapshot['_commands'] as $command) {
                    if (!is_array($command)) throw new RuntimeException('lifecycle_router_identity_unknown');
                    if (($command['action']??null)!=='remove') $allRemoval=false;
                    $command['action']='remove';
                    if (in_array(trim($command['path']??'','/'),['ppp/active','ip/hotspot/active'],true)) $active[]=$command;
                    else $commands[]=$command;
                }
                $snapshot['_commands']=array_merge($allRemoval?$commands:array_reverse($commands),$active); $snapshot['_delete_frozen']=true;
            }
            $identity=[];
            foreach(['id','clientId','device','username','mac','hotspot','duid','iaid'] as $field) $identity[$field]=$snapshot[$field]??null;
            $identity['_endpoint']=[$snapshot['_device']['ip']??null,$snapshot['_device']['port']??8728];
            ksort($identity); $key=hash('sha256',json_encode($identity,JSON_THROW_ON_ERROR));
            if (isset($seen[$key])) {
                $index=$seen[$key];
                if (!isset($snapshot['_commands'])) continue;
                if (!isset($result[$index]['_commands'])) { $result[$index]=$snapshot; continue; }
                if ($result[$index]['_commands']===$snapshot['_commands']) continue;
                // The same account may have produced different auxiliary frozen resources.
                $key.=':'.hash('sha256',json_encode($snapshot['_commands'],JSON_THROW_ON_ERROR));
                if (isset($seen[$key])) continue;
            }
            $seen[$key]=count($result); $result[]=$snapshot;
        }
        if (count($result)>100) throw new RuntimeException('lifecycle_resource_limit');
        return $result;
    }

    private function accountIdentityKnown(array $snapshot): bool
    { return (is_string($snapshot['username']??null)&&$snapshot['username']!=='') || (bool)filter_var($snapshot['mac']??'',FILTER_VALIDATE_MAC); }

    /** With no physical ownership evidence, cancellation and the terminal gate are durable before this local release. */
    private function releaseLocal(int $sid): void
    {
        if (!is_file($this->dataPath)) return;
        $db=new SQLite3($this->dataPath,SQLITE3_OPEN_READWRITE); $db->enableExceptions(true); $db->busyTimeout(1000);
        try {
            $db->exec('BEGIN IMMEDIATE');
            if ($this->table($db,'services') && $this->query($db,'SELECT id FROM services WHERE id=?',[$sid])) throw new RuntimeException('lifecycle_changed');
            if ($this->table($db,'network')) $this->query($db,'DELETE FROM network WHERE id=?',[$sid]);
            if ($this->table($db,'pool_allocations')) $this->query($db,'DELETE FROM pool_allocations WHERE service_id=?',[$sid]);
            $db->exec('COMMIT');
        } catch (Throwable $error) { try{$db->exec('ROLLBACK');}catch(Throwable $ignored){} throw $error; }
        finally { $db->close(); }
    }

    private function candidates(int $cursor,int $limit): array
    {
        $ids=[];
        foreach ([[$this->dataPath,['network'=>'id','services'=>'id']],
            [$this->jobsPath,['service_versions'=>'service_id','cancellation_cleanups'=>'service_id','retained_resources'=>'service_id','jobs'=>'service_id']]] as [$path,$tables]) {
            if (!is_file($path)) continue;
            $db=new SQLite3($path,SQLITE3_OPEN_READONLY); $db->enableExceptions(true);
            try { foreach($tables as $table=>$field) if ($this->table($db,$table)) {
                $filter=$table==='jobs'?" AND status IN ('pending','running','cancelled','superseded')":($table==='service_versions'?' AND applied_payload IS NOT NULL':'');
                foreach($this->query($db,'SELECT DISTINCT '.$field.' AS id FROM '.$table.' WHERE '.$field.'>?'.$filter.' ORDER BY '.$field.' LIMIT '.$limit,[$cursor]) as $row)
                    if (($sid=self::id($row['id']))!==null) $ids[$sid]=$sid;
            } } finally { $db->close(); }
        }
        sort($ids,SORT_NUMERIC); return array_slice($ids,0,$limit);
    }

    private function localClient(int $sid): ?int
    {
        $ids=[];
        foreach ([$this->dataPath,$this->cachePath] as $path) {
            $cid=self::id($this->row($path,'services',$sid)['clientId']??null); if ($cid!==null) $ids[$cid]=$cid;
        }
        if (is_file($this->jobsPath)) {
            $db=new SQLite3($this->jobsPath,SQLITE3_OPEN_READONLY); $db->enableExceptions(true);
            try {
                if ($this->table($db,'service_versions')) foreach($this->query($db,'SELECT applied_payload FROM service_versions WHERE service_id=?',[$sid]) as $row) {
                    $payload=json_decode($row['applied_payload']??'null',true); $cid=self::id($payload['clientId']??null); if ($cid!==null) $ids[$cid]=$cid;
                }
            } finally { $db->close(); }
        }
        if (count($ids)>1) throw new RuntimeException('lifecycle_identity_changed');
        return $ids?reset($ids):null;
    }

    private function row(string $path,string $table,int $sid): array
    {
        if (!is_file($path)||is_link($path)) return [];
        $db=new SQLite3($path,SQLITE3_OPEN_READONLY); $db->enableExceptions(true);
        try { return $this->table($db,$table)?($this->query($db,'SELECT * FROM '.$table.' WHERE id=?',[$sid])[0]??[]):[]; }
        finally { $db->close(); }
    }
    private function table(SQLite3 $db,string $name): bool
    { return (bool)$this->query($db,"SELECT name FROM sqlite_master WHERE type='table' AND name=?",[$name]); }
    private function query(SQLite3 $db,string $sql,array $values): array
    {
        $stmt=$db->prepare($sql);
        try {
            foreach($values as $index=>$value) $stmt->bindValue($index+1,$value,is_int($value)?SQLITE3_INTEGER:SQLITE3_TEXT);
            $result=$stmt->execute(); $rows=[];
            try { while($row=$result->fetchArray(SQLITE3_ASSOC)) $rows[]=$row; } finally { $result->finalize(); }
            return $rows;
        } finally { $stmt->close(); }
    }
    private function get(string $path)
    {
        if ($this->deadline!==null && microtime(true)>=$this->deadline) throw new RuntimeException('lifecycle_unavailable');
        if ($this->request!==null) {
            $value=($this->request)($path);
            if ($this->deadline!==null && microtime(true)>=$this->deadline) throw new RuntimeException('lifecycle_unavailable');
            return $value;
        }
        require_once __DIR__.'/api_uisp_client.php'; $sdk=ApiUispClient::crm();
        if (class_exists(\GuzzleHttp\Client::class)) {
            $property=new ReflectionProperty($sdk,'client'); if (PHP_VERSION_ID<80100) $property->setAccessible(true); $client=$property->getValue($sdk);
            if (!$client instanceof \GuzzleHttp\Client) throw new RuntimeException('lifecycle_unavailable');
            $options=$client->getConfig(); $remaining=$this->deadline===null?5:max(.1,$this->deadline-microtime(true));
            $options['timeout']=min(5,$remaining); $options['connect_timeout']=min(2,$remaining); $options['read_timeout']=min(5,$remaining);
            $property->setValue($sdk,new \GuzzleHttp\Client($options));
        }
        $value=$sdk->get($path,[]);
        if ($this->deadline!==null && microtime(true)>=$this->deadline) throw new RuntimeException('lifecycle_unavailable');
        return $value;
    }
    private function notFound(Throwable $error): bool
    {
        for($cause=$error;$cause!==null;$cause=$cause->getPrevious())
            if ($cause instanceof \GuzzleHttp\Exception\RequestException) return $cause->hasResponse() && $cause->getResponse()->getStatusCode()===404;
        return false;
    }
    private function proof(array $value): array
    { $value['fingerprint']=hash('sha256',json_encode($value,JSON_THROW_ON_ERROR)); $value['checked_at']=gmdate('c',(int)$this->now()); return $value; }
    private function invalid(array $value): array { $value['reason']='lifecycle_invalid'; return $this->proof($value); }
    private function publicProof(array $value): array
    { return ['serviceId'=>$value['service_id'],'clientId'=>$value['client_id'],'state'=>$value['state'],'reason'=>$value['reason'],'status'=>$value['status'],'missing'=>$value['missing']]; }
    private function tokenPath(string $token): string
    { if (!preg_match('/^[a-f0-9]{64}$/D',$token)) throw new InvalidArgumentException('lifecycle_invalid_token'); return dirname($this->jobsPath).'/lifecycle-review-'.$token.'.json'; }
    private function now(): float { return $this->clock===null?microtime(true):(float)($this->clock)(); }
    private static function id($value): ?int
    { if (is_bool($value)||is_float($value)) return null; $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]); return $id===false?null:$id; }
    private static function status($value): ?int
    { if (is_bool($value)||is_float($value)) return null; $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>8]]); return $id===false?null:$id; }
}
