<?php
require_once __DIR__.'/api_service_diagnostics.php';

/** Fair, bounded post-worker drift review. Ambiguous services are incidents, never a global provisioning gate. */
final class ApiServiceReconciliation
{
    private const LIMIT = 100;
    private const BYTES = 131072;
    private const FIELDS = ['username','site','address','address6','profile','plan_id','status','ownership'];
    private const REASONS = ['differences_observed','correction_queued','inspection_unavailable','identity_unverified','account_absent',
        'identity_changed','terminal_requires_review','service_busy','state_changed','queue_unavailable','configuration_unavailable',
        'automatic_correction_disabled','correction_unavailable','observation_limit','plan_metadata_unavailable','foreign_site_address'];
    private $inspector;
    private $enqueue;
    private $clock;
    private $request;
    private string $dataPath;
    private string $cachePath;
    private string $jobsPath;
    private string $statePath;
    private float $deadline = 0;

    public function __construct(?callable $inspector = null, ?callable $enqueue = null, string $dataPath = 'data/data.db',
        string $cachePath = 'data/cache.db', string $jobsPath = 'data/jobs.db',
        string $statePath = 'data/service-reconciliation.json', ?callable $clock = null, ?callable $request = null)
    {
        $this->inspector=$inspector; $this->enqueue=$enqueue; $this->dataPath=$dataPath; $this->cachePath=$cachePath;
        $this->jobsPath=$jobsPath; $this->statePath=$statePath; $this->clock=$clock;
        $this->request=$request;
    }

    /** Poll existing evidence only. No queue construction, API call or state-file creation. */
    public function read(): array
    {
        [$config] = ApiSiteAddressPolicy::stored($this->dataPath);
        $result=['available'=>true,'enabled'=>$this->flag($config,'service_reconciliation_enabled'),
            'auto_apply'=>$this->flag($config,'service_reconciliation_auto_apply'),'last_tick_at'=>null,'last_checked_at'=>null,
            'cursor_service_id'=>0,'items'=>[],'has_more'=>false,'state'=>'never_observed',
            'summary'=>['differences'=>0,'queued'=>0,'unknown'=>0,'deferred'=>0],'checked_at'=>$this->stamp()];
        try {
            $state=$this->load(); $result['last_tick_at']=$state['last_tick_at']; $result['last_checked_at']=$state['last_checked_at'];
            $result['cursor_service_id']=$state['cursor']; $result['state']=$state['last_tick_at']===null ? 'never_observed' : 'observed';
            $items=array_values($state['items']); usort($items,static fn(array $a,array $b):int=>strcmp($b['checked_at'],$a['checked_at']) ?: $a['service_id']<=>$b['service_id']);
            foreach ($items as $item) $result['summary'][$item['state']]++;
            $result['items']=array_slice($items,0,50); $result['has_more']=count($items)>50;
        } catch (Throwable $ignored) { $result['available']=false; $result['state']='unknown'; $result['summary']=array_fill_keys(array_keys($result['summary']),null); }
        return $result;
    }

    /** One service per minute, after automatic jobs. Queue safe corrections for the next normal worker. */
    public function tick(): array
    {
        $result=['checked'=>0,'queued'=>0,'differences'=>0,'unknown'=>0,'deferred'=>0,'busy'=>false,'budget_exhausted'=>false]; $lock=null;
        try {
            [$config]=ApiSiteAddressPolicy::stored($this->dataPath);
            if (!$config || !$this->flag($config,'service_reconciliation_enabled')) return $result;
            $this->deadline=microtime(true)+8;
            $this->path($this->statePath); $this->path($this->statePath.'.lock');
            $lock=fopen($this->statePath.'.lock','c+b');
            if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { $result['busy']=true; return $result; }
            if (!chmod($this->statePath.'.lock',0600)) throw new RuntimeException('state_unavailable');
            $state=$this->load(); $now=$this->now();
            if ($state['last_tick_at']!==null && $now-(float)strtotime($state['last_tick_at'])<60) return $result;
            $sid=$this->next($state['cursor']); $state['last_tick_at']=$this->stamp();
            if ($sid===null) { $state['cursor']=0; $this->save($state); return $result; }
            // Advance before any remote read, so an offline/ambiguous first service never starves later ones.
            $state['cursor']=$sid; $this->save($state); $baseline=null;
            try {
                $baseline=$this->baseline($sid);
                $diagnostic=$this->inspector!==null ? ($this->inspector)($sid,$this->deadline)
                    : (new ApiServiceDiagnostics(null,null,$this->dataPath,$this->cachePath,$this->jobsPath,null,$this->deadline))->inspect($sid);
                $this->budget(); $this->same($baseline,$this->baseline($sid));
                $entry=$this->classify($sid,$baseline,$diagnostic); $result['checked']++;
                if ($entry===null) unset($state['items'][(string)$sid]);
                else {
                    if ($entry['state']==='differences' && $entry['reason']==='differences_observed') {
                        if (!$this->flag($config,'service_reconciliation_auto_apply')) $entry['reason']='automatic_correction_disabled';
                        else {
                            try {
                                $this->budget();
                                $keys=$this->enqueue!==null ? ($this->enqueue)($sid,$baseline,$diagnostic,$this->deadline)
                                    : $this->enqueueFresh($sid,$baseline,$diagnostic);
                                if (!is_array($keys) || !$keys || count($keys)>2) throw new RuntimeException('correction_unavailable');
                                foreach ($keys as $key) if (!is_string($key) || preg_match('/^Q[1-9][0-9]{0,14}$/D',$key)!==1) throw new RuntimeException('correction_unavailable');
                                $entry['state']='queued'; $entry['reason']='correction_queued'; $entry['job_keys']=array_values($keys);
                            } catch (Throwable $error) { $entry['reason']=$this->reason($error,'correction_unavailable'); }
                        }
                    }
                    $state['items'][(string)$sid]=$entry; $result[$entry['state']]++;
                }
                $state['last_checked_at']=$this->stamp();
            } catch (Throwable $error) {
                $reason=$this->reason($error,'inspection_unavailable'); $kind=in_array($reason,['service_busy','state_changed'],true)?'deferred':'unknown';
                $state['items'][(string)$sid]=$this->entry($sid,$baseline['device_id']??null,$kind,$reason,[]); $result[$kind]++;
                if ($reason==='observation_limit') $result['budget_exhausted']=true;
            }
            $this->save($state);
        } catch (Throwable $ignored) { $result['unknown']++; }
        finally { if (is_resource($lock)) { flock($lock,LOCK_UN); fclose($lock); } }
        return $result;
    }

    private function classify(int $sid,array $baseline,$report): ?array
    {
        $did=$baseline['device_id'];
        if (!is_array($report) || ($report['service_id']??null)!==$sid || ($report['expected']['source']??null)!=='applied'
            || ($report['uisp']['available']??null)!==true || ($report['router']['available']??null)!==true)
            return $this->entry($sid,$did,'unknown','inspection_unavailable',[]);
        $u=$report['uisp']['values']??[]; $e=$report['expected']['values']??[]; $r=$report['router']['values']??[];
        if (!in_array($u['status']??null,[1,3,4,7],true)) return $this->entry($sid,$did,'differences','terminal_requires_review',['status']);
        if (($report['router']['present']??null)!==true) return $this->entry($sid,$did,'differences','account_absent',['ownership']);
        $owner=$baseline['client_id'].':'.$sid;
        if (($u['ownership']??null)!==$owner || ($e['ownership']??null)!==$owner || ($r['ownership']??null)!==$owner)
            return $this->entry($sid,$did,'differences','identity_unverified',['ownership']);
        foreach (['username','site'] as $field) if (!is_string($u[$field]??null) || $u[$field]===''
            || ($u[$field]??null)!==($e[$field]??null) || ($u[$field]??null)!==($r[$field]??null))
            return $this->entry($sid,$did,'differences','identity_changed',[$field]);
        // The initial automatic path updates already-owned PPP/Hotspot principals only. Other modes remain visible for review.
        if (!in_array($baseline['principal_type'],['ppp/secret','ip/hotspot/user'],true))
            return $this->entry($sid,$did,'unknown','identity_unverified',[]);
        $fields=[]; $observed=0;
        foreach ($report['comparisons']??[] as $comparison) {
            if (!is_array($comparison) || !in_array($comparison['field']??null,self::FIELDS,true)) continue;
            if (is_bool($comparison['matches_uisp']??null) || is_bool($comparison['matches_router']??null)) $observed++;
            if (($comparison['matches_uisp']??null)===false || ($comparison['matches_router']??null)===false) $fields[]=$comparison['field'];
        }
        $fields=array_values(array_unique($fields));
        if (($report['router']['reason']??null)!==null || $observed<3) return $this->entry($sid,$did,'unknown','inspection_unavailable',$fields);
        if (!$fields) return null;
        if (array_diff($fields,['address','address6','profile','plan_id','status'])) return $this->entry($sid,$did,'differences','identity_changed',$fields);
        return $this->entry($sid,$did,'differences','differences_observed',$fields);
    }

    /** Rehydrate twice under the normal service gate. Never enqueue the inspector's cached or displayed values. */
    private function enqueueFresh(int $sid,array $baseline,array $diagnostic): array
    {
        if (!function_exists('myJobQueue')) throw new RuntimeException('correction_unavailable');
        $queue=myJobQueue();
        return $queue->withServiceLock($sid,function() use ($sid,$baseline,$diagnostic): array {
            $this->same($baseline,$this->baseline($sid,true)); $this->budget();
            $fresh=$this->get('clients/services/'.$sid); $catalog=$this->get('custom-attributes');
            $client=$this->get('clients/'.$baseline['client_id']);
            if (($fresh['id']??null)!==$sid || ($fresh['clientId']??null)!==$baseline['client_id']
                || !in_array($fresh['status']??null,[1,3,4,7],true) || ($client['id']??null)!==$baseline['client_id']
                || !in_array($client['isArchived']??null,[false,0,'0'],true) || !is_array($fresh['attributes']??null)) throw new RuntimeException('identity_unverified');
            if (($fresh['status']??null)!==($diagnostic['uisp']['values']['status']??null)
                || ($fresh['servicePlanId']??null)!==($diagnostic['uisp']['values']['plan_id']??null)) throw new RuntimeException('state_changed');
            $configuration=$baseline['configuration'];
            foreach (['site'=>'device_name_attr','username'=>'pppoe_user_attr','address'=>'ip_addr_attr','address6'=>'ip_addr6_attr'] as $field=>$key) {
                if (!is_string($configuration[$key]??null) || $configuration[$key]==='') {
                    if (in_array($field,['site','username'],true)) throw new RuntimeException('identity_unverified'); continue;
                }
                $value=$this->attribute($fresh,$catalog,$configuration[$key]);
                if ($value!==($diagnostic['uisp']['values'][$field]??null)) throw new RuntimeException('state_changed');
            }
            require_once __DIR__.'/api_attributes.php';
            $attributes=(new ApiAttributes())->extract(json_decode(json_encode($fresh['attributes'],JSON_THROW_ON_ERROR)));
            $entity=array_replace($attributes,array_intersect_key($fresh,array_fill_keys(['id','clientId','status','uploadSpeed','downloadSpeed','price','totalPrice','currencyCode'],true)));
            $entity['planId']=$fresh['servicePlanId']??null;
            if (($entity['device']??null)!==$baseline['device_id'] || ($entity['username']??null)!==$baseline['principal']
                || !is_string($entity['password']??null) || $entity['password']==='' || filter_var($entity['mac']??'',FILTER_VALIDATE_MAC)
                || (!empty($entity['hotspot'])!==!empty($baseline['snapshot']['hotspot']))) throw new RuntimeException('identity_changed');
            foreach (['mac','hotspot','duid','iaid'] as $field) {
                $previous=$baseline['snapshot'][$field]??null; $current=$entity[$field]??null;
                $same=$field==='hotspot' ? empty($previous)===empty($current)
                    : (($previous===null||$previous==='') && ($current===null||$current==='') || (string)$previous===(string)$current);
                if (!$same) throw new RuntimeException('identity_changed');
                $entity[$field]=$previous;
            }
            $passwordKey=$configuration['pppoe_pass_attr']??null;
            if (!is_string($passwordKey) || $passwordKey==='' || $this->attribute($fresh,$catalog,$passwordKey)!==$entity['password'])
                throw new RuntimeException('identity_unverified');
            $entity['firstName']=is_string($client['firstName']??null)?$client['firstName']:null;
            $entity['lastName']=is_string($client['lastName']??null)?$client['lastName']:null;
            $entity['company']=is_string($client['companyName']??null)?$client['companyName']:null;
            $this->validateAddresses($entity,$baseline);
            $this->plan($entity['planId']);
            $second=$this->get('clients/services/'.$sid);
            if ($this->freshFingerprint($fresh)!==$this->freshFingerprint($second)) throw new RuntimeException('state_changed');
            $this->same($baseline,$this->baseline($sid,true)); $this->budget();
            if (!class_exists('Batch',false)) require_once __DIR__.'/batch.php';
            // The normal read-only address preflight rejects known collisions before a corrective job is created.
            $batch=new Batch(); $batch->validateSiteAddressReplacement($entity,false); $this->budget();
            $this->same($baseline,$this->baseline($sid,true));
            // Normal preparation/ownership validation executes later; this detector never runs a RouterOS command.
            return $batch->queue_accounts([$entity]);
        });
    }

    private function validateAddresses(array $entity,array $baseline): void
    {
        foreach (['address'=>false,'address6'=>true] as $field=>$v6) {
            $value=$entity['network'][$field]??null; if ($value===null || $value==='') continue;
            $parts=explode('/',$value);
            if (count($parts)>2 || !$v6 && count($parts)!==1 || !filter_var($parts[0],FILTER_VALIDATE_IP,$v6?FILTER_FLAG_IPV6:FILTER_FLAG_IPV4)
                || isset($parts[1]) && (filter_var($parts[1],FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>$v6?128:32]])===false)) throw new RuntimeException('identity_unverified');
            // Refuse addresses known to belong to another configured Site; do not trigger a CRM-clearing policy from a monitor.
            foreach ($baseline['devices'] as $did=>$device) {
                if ((int)$did===$baseline['device_id']) continue;
                foreach (explode(',',(string)($device[$v6?'pool6':'pool']??'')) as $pool) {
                    if ($this->inPool($parts[0],trim($pool))) throw new RuntimeException('foreign_site_address');
                }
            }
        }
    }
    private function inPool(string $address,string $pool): bool
    {
        $parts=explode('/',$pool); if (count($parts)!==2 || !ctype_digit($parts[1])) return false;
        $ip=@inet_pton($address); $network=@inet_pton($parts[0]);
        if ($ip===false || $network===false || strlen($ip)!==strlen($network) || (int)$parts[1]>strlen($ip)*8) return false;
        $bytes=intdiv((int)$parts[1],8); $bits=(int)$parts[1]%8;
        return substr($ip,0,$bytes)===substr($network,0,$bytes) && (!$bits || (ord($ip[$bytes])>>(8-$bits))===(ord($network[$bytes])>>(8-$bits)));
    }

    private function baseline(int $sid,bool $owned = false): array
    {
        [$configuration,$devices]=ApiSiteAddressPolicy::stored($this->dataPath);
        if (!$configuration || !$devices) throw new RuntimeException('configuration_unavailable');
        $legacy=dirname($this->jobsPath).DIRECTORY_SEPARATOR.'queue.json';
        if (file_exists($legacy) || is_link($legacy)) {
            if (is_link($legacy) || !is_file($legacy) || filesize($legacy)>1048576) throw new RuntimeException('queue_unavailable');
            $raw=file_get_contents($legacy);
            if ($raw===false || trim($raw)!=='' && json_decode($raw,true,32,JSON_THROW_ON_ERROR)!==[]) throw new RuntimeException('queue_unavailable');
        }
        $db=$this->database($this->jobsPath);
        try {
            foreach (['jobs'=>['id','service_id','status'],'service_versions'=>['service_id','version','applied_payload'],
                'service_event_locks'=>['service_id'],'queue_meta'=>['key']] as $table=>$columns) $this->schema($db,$table,$columns);
            if ($this->query($db,"SELECT id FROM jobs WHERE status='running' OR (service_id=? AND status='pending') LIMIT 1",[$sid])
                || $this->query($db,"SELECT key FROM queue_meta WHERE key='maintenance' LIMIT 1")
                || !$owned && $this->query($db,'SELECT service_id FROM service_event_locks WHERE service_id=? LIMIT 1',[$sid])) throw new RuntimeException('service_busy');
            if ($db->querySingle("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='webhook_fresh_reviews'")
                && $this->query($db,'SELECT service_id FROM webhook_fresh_reviews WHERE service_id=? LIMIT 1',[$sid])) throw new RuntimeException('service_busy');
            if ($db->querySingle("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='service_tombstones'")
                && $this->query($db,'SELECT service_id FROM service_tombstones WHERE service_id=? LIMIT 1',[$sid])) throw new RuntimeException('terminal_requires_review');
            $rows=$this->query($db,'SELECT version,applied_payload FROM service_versions WHERE service_id=? LIMIT 2',[$sid]);
            if (count($rows)!==1 || !is_string($rows[0]['applied_payload']) || strlen($rows[0]['applied_payload'])>2097152) throw new RuntimeException('identity_unverified');
            $snapshot=json_decode($rows[0]['applied_payload'],true,32,JSON_THROW_ON_ERROR); $did=self::id($snapshot['device']??null); $cid=self::id($snapshot['clientId']??null);
            if (($snapshot['id']??null)!==$sid || $did===null || $cid===null || !isset($devices[$did])
                || !is_array($snapshot['_device']??null) || !is_array($snapshot['_commands']??null)
                || !in_array($snapshot['status']??null,[1,3,4,7],true) || $this->endpoint($snapshot['_device'])!==$this->endpoint($devices[$did])) throw new RuntimeException('identity_unverified');
            $principal=$snapshot['username']??null;
            $type=filter_var($snapshot['mac']??'',FILTER_VALIDATE_MAC)?'ip/dhcp-server/lease':(empty($snapshot['hotspot'])?'ppp/secret':'ip/hotspot/user');
            if (!is_string($principal) || $principal==='' || strlen($principal)>256) throw new RuntimeException('identity_unverified');
            return ['service_id'=>$sid,'client_id'=>$cid,'device_id'=>$did,'principal'=>$principal,'principal_type'=>$type,
                'version'=>(int)$rows[0]['version'],'applied_hash'=>hash('sha256',$rows[0]['applied_payload']),
                'configuration_hash'=>hash('sha256',json_encode([$configuration,$devices],JSON_THROW_ON_ERROR)),
                'configuration'=>$configuration,'devices'=>$devices,'snapshot'=>$snapshot];
        } catch (RuntimeException $error) { throw $error; }
        catch (Throwable $ignored) { throw new RuntimeException('queue_unavailable'); }
        finally { $db->close(); }
    }
    private function same(array $first,array $second): void
    { foreach (['version','applied_hash','configuration_hash'] as $key) if ($first[$key]!==$second[$key]) throw new RuntimeException('state_changed'); }

    private function next(int $cursor): ?int
    {
        foreach ([$cursor,0] as $from) {
            $ids=[];
            foreach ([$this->dataPath,$this->cachePath] as $path) {
                $db=null;
                try { $db=$this->database($path); $this->schema($db,'services',['id','device','status']);
                    $rows=$this->query($db,'SELECT id FROM services WHERE id>? AND device>0 AND status IN (1,3,4,7) ORDER BY id LIMIT 1',[$from]);
                    if ($rows && self::id($rows[0]['id'])!==null) $ids[]=(int)$rows[0]['id'];
                } catch (Throwable $ignored) {} finally { if ($db!==null) $db->close(); }
            }
            if ($ids) return min($ids);
            if ($cursor===0) break;
        }
        return null;
    }

    private function plan($id): void
    {
        $id=self::id($id); if ($id===null) throw new RuntimeException('plan_metadata_unavailable');
        $db=$this->database($this->dataPath);
        try { $this->schema($db,'plans',['id','name','uploadSpeed','downloadSpeed']); $rows=$this->query($db,'SELECT * FROM plans WHERE id=? LIMIT 2',[$id]);
            require_once __DIR__.'/api_plan_catalog.php';
            if (count($rows)!==1 || ApiPlanCatalog::needsHydration($rows[0])) throw new RuntimeException('plan_metadata_unavailable');
        } finally { $db->close(); }
    }
    private function get(string $path): array
    {
        $this->budget();
        if ($this->request!==null) {
            $value=json_decode(json_encode(($this->request)($path,'get',[]),JSON_THROW_ON_ERROR),true,32,JSON_THROW_ON_ERROR); $this->budget();
            if (!is_array($value)) throw new RuntimeException('correction_unavailable'); return $value;
        }
        require_once __DIR__.'/api_uisp_client.php'; $api=ApiUispClient::crm();
        if (class_exists(\GuzzleHttp\Client::class)) {
            $property=new ReflectionProperty($api,'client'); if (PHP_VERSION_ID<80100) $property->setAccessible(true);
            $client=$property->getValue($api); if (!$client instanceof \GuzzleHttp\Client) throw new RuntimeException('correction_unavailable');
            $remaining=max(0.05,$this->deadline-microtime(true)); $options=$client->getConfig();
            $options['timeout']=min(1.5,$remaining); $options['connect_timeout']=min(.75,$remaining); $options['read_timeout']=min(1.5,$remaining);
            $property->setValue($api,new \GuzzleHttp\Client($options));
        }
        $value=json_decode(json_encode($api->get($path,[]),JSON_THROW_ON_ERROR),true,32,JSON_THROW_ON_ERROR); $this->budget();
        if (!is_array($value)) throw new RuntimeException('correction_unavailable'); return $value;
    }
    private function attribute(array $service,array $catalog,string $key): ?string
    {
        $matches=array_filter($catalog,static fn($row):bool=>is_array($row) && ($row['key']??null)===$key && ($row['attributeType']??null)==='service');
        if (count($matches)!==1) throw new RuntimeException('identity_unverified'); $id=self::id(reset($matches)['id']??null);
        if ($id===null) throw new RuntimeException('identity_unverified'); $found=false; $value=null;
        foreach ($service['attributes'] as $row) {
            if (!is_array($row)) throw new RuntimeException('identity_unverified');
            if (($row['key']??null)!==$key && self::id($row['customAttributeId']??null)!==$id) continue;
            if ($found || ($row['key']??null)!==$key || isset($row['customAttributeId']) && self::id($row['customAttributeId'])!==$id
                || !array_key_exists('value',$row) || !is_string($row['value']) && $row['value']!==null) throw new RuntimeException('identity_unverified');
            $found=true; $value=$row['value'];
        }
        return $value;
    }
    private function freshFingerprint(array $service): string
    {
        $safe=array_intersect_key($service,array_fill_keys(['id','clientId','status','servicePlanId','attributes','uploadSpeed','downloadSpeed'],true));
        if (is_array($safe['attributes']??null)) usort($safe['attributes'],static fn($a,$b):int=>strcmp(json_encode($a),json_encode($b)));
        return hash('sha256',json_encode($safe,JSON_THROW_ON_ERROR));
    }
    private function endpoint(array $device): string
    { return hash('sha256',json_encode(array_intersect_key($device,array_fill_keys(['id','ip','port','ssl','allowSelfSigned','user','password'],true)),JSON_THROW_ON_ERROR)); }
    private function database(string $path): SQLite3
    {
        if (!is_file($path) || is_link($path)) throw new RuntimeException('queue_unavailable');
        $db=new SQLite3($path,SQLITE3_OPEN_READONLY); $db->enableExceptions(true); $db->busyTimeout(150); $db->exec('PRAGMA query_only=ON');
        if ($db->querySingle("SELECT count(*) FROM sqlite_master WHERE type IN ('view','trigger')")) { $db->close(); throw new RuntimeException('queue_unavailable'); }
        return $db;
    }
    private function schema(SQLite3 $db,string $table,array $columns): void
    {
        if (!in_array($table,['services','jobs','service_versions','service_event_locks','queue_meta','plans'],true)) throw new LogicException('table');
        $rows=$this->query($db,'PRAGMA table_info('.$table.')');
        if (array_diff($columns,array_column($rows,'name'))) throw new RuntimeException('queue_unavailable');
    }
    private function query(SQLite3 $db,string $sql,array $parameters=[]): array
    {
        $statement=$db->prepare($sql);
        foreach ($parameters as $index=>$value) $statement->bindValue($index+1,$value,is_int($value)?SQLITE3_INTEGER:SQLITE3_TEXT);
        try { $result=$statement->execute(); $rows=[]; try { while ($row=$result->fetchArray(SQLITE3_ASSOC)) $rows[]=$row; }
            finally { $result->finalize(); } return $rows;
        } finally { $statement->close(); }
    }
    private function load(): array
    {
        $state=['schema'=>1,'cursor'=>0,'last_tick_at'=>null,'last_checked_at'=>null,'items'=>[]];
        $this->path($this->statePath); if (!is_file($this->statePath)) return $state;
        if (filesize($this->statePath)>self::BYTES) throw new RuntimeException('state_unavailable');
        $value=json_decode(file_get_contents($this->statePath),true,16,JSON_THROW_ON_ERROR);
        if (!is_array($value) || ($value['schema']??null)!==1 || !is_int($value['cursor']??null) || $value['cursor']<0
            || !is_array($value['items']??null) || count($value['items'])>self::LIMIT) throw new RuntimeException('state_unavailable');
        foreach (['last_tick_at','last_checked_at'] as $field) if ($value[$field]!==null && !$this->date($value[$field])) throw new RuntimeException('state_unavailable');
        foreach ($value['items'] as $key=>$item) {
            if (!is_array($item) || self::id($item['service_id']??null)===null || (string)$item['service_id']!==(string)$key
                || !in_array($item['state']??null,['differences','queued','unknown','deferred'],true) || !in_array($item['reason']??null,self::REASONS,true)
                || ($item['device_id']??null)!==null && self::id($item['device_id'])===null
                || !is_array($item['fields']??null) || count($item['fields'])>count(self::FIELDS) || array_diff($item['fields'],self::FIELDS) || !$this->date($item['checked_at']??null)
                || !is_array($item['job_keys']??null) || count($item['job_keys'])>2) throw new RuntimeException('state_unavailable');
            foreach ($item['job_keys'] as $job) if (!is_string($job) || preg_match('/^Q[1-9][0-9]{0,14}$/D',$job)!==1) throw new RuntimeException('state_unavailable');
            $value['items'][$key]=array_intersect_key($item,array_fill_keys(['service_id','device_id','state','reason','fields','checked_at','job_keys'],true));
        }
        return array_replace($state,$value);
    }
    private function save(array $state): void
    {
        if (count($state['items'])>self::LIMIT) {
            uasort($state['items'],static fn(array $a,array $b):int=>strcmp($b['checked_at'],$a['checked_at']));
            $state['items']=array_slice($state['items'],0,self::LIMIT,true);
        }
        $json=json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES); if (strlen($json)>self::BYTES) throw new RuntimeException('state_unavailable');
        $temporary=tempnam(dirname($this->statePath),'.reconciliation-'); if ($temporary===false) throw new RuntimeException('state_unavailable');
        try { if (!chmod($temporary,0600) || file_put_contents($temporary,$json,LOCK_EX)!==strlen($json) || !rename($temporary,$this->statePath)) throw new RuntimeException('state_unavailable'); }
        finally { if (is_file($temporary)) unlink($temporary); }
    }
    private function path(string $path): void
    { if (is_link($path) || is_link(dirname($path)) || !is_dir(dirname($path)) || file_exists($path) && !is_file($path)) throw new RuntimeException('state_unavailable'); }
    private function entry(int $sid,?int $did,string $state,string $reason,array $fields): array
    { return ['service_id'=>$sid,'device_id'=>$did,'state'=>$state,'reason'=>$reason,'fields'=>$fields,'checked_at'=>$this->stamp(),'job_keys'=>[]]; }
    private function flag(array $config,string $key): bool
    { return !array_key_exists($key,$config) || filter_var($config[$key],FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE)===true; }
    private function date($value): bool { return is_string($value) && strlen($value)<=32 && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D',$value)===1 && strtotime($value)!==false; }
    private function reason(Throwable $error,string $fallback): string { return in_array($error->getMessage(),self::REASONS,true)?$error->getMessage():$fallback; }
    private function budget(): void { if (microtime(true)>=$this->deadline) throw new RuntimeException('observation_limit'); }
    private function now(): float { return $this->clock===null ? microtime(true) : (float)($this->clock)(); }
    private function stamp(): string { return gmdate('Y-m-d\TH:i:s\Z',(int)$this->now()); }
    private static function id($value): ?int { if (!is_int($value) && !is_string($value)) return null; $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]); return $id===false?null:$id; }
}
