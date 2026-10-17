<?php
require_once __DIR__.'/batch.php';
require_once __DIR__.'/api_trim.php';
require_once __DIR__.'/api_webhook_freshness.php';

/** Explicit, bounded sync review. Observations never initialize the executable queue. */
final class ApiSyncPreview
{
    private $request;
    private $routerReader;
    private $applier;
    private $clock;
    private string $dataPath;
    private string $cachePath;
    private string $jobsPath;
    private float $deadline = 0;
    private const PAGE = 500;
    private const MAX_SERVICES = 20000;
    private const INTENT_FIELDS = ['id','clientId','status','planId','device','uploadSpeed','downloadSpeed','uploadSpeedOverride','downloadSpeedOverride','trafficShapingOverrideEnd','trafficShapingOverrideEnabled','username','password','mac','hotspot','duid','iaid','callerId','network'];

    public function __construct(?callable $request=null, ?callable $routerReader=null, ?callable $applier=null,
        string $dataPath='data/data.db', string $cachePath='data/cache.db', string $jobsPath='data/jobs.db', ?callable $clock=null)
    {
        $this->request=$request; $this->routerReader=$routerReader; $this->applier=$applier; $this->clock=$clock;
        $this->dataPath=$dataPath; $this->cachePath=$cachePath; $this->jobsPath=$jobsPath;
    }

    public function begin(int $deviceId): array
    {
        [$config,$devices]=$this->configuration();
        if ($deviceId<1 || !isset($devices[$deviceId]) || ($devices[$deviceId]['type']??'mikrotik')!=='mikrotik') throw new RuntimeException('sync_preview_router_unavailable');
        $key=$config['device_name_attr']??null;
        if (!is_string($key)||trim($key)==='') throw new RuntimeException('sync_preview_configuration_missing');
        $names=[]; foreach ($devices as $device) $names[strtolower(trim((string)$device['name']))][]=(int)$device['id'];
        if (count($names[strtolower(trim((string)$devices[$deviceId]['name']))]??[])!==1) throw new RuntimeException('sync_preview_router_ambiguous');
        $cache=$this->cache();
        try { $ids=array_map('intval',array_column($cache->selectCustom('SELECT id FROM services WHERE device=? ORDER BY id',[$deviceId])??[],'id')); }
        finally { $cache->close(); }
        $token=bin2hex(random_bytes(32));
        $state=['schema'=>1,'expires'=>$this->now()+600,'deviceId'=>$deviceId,'configuration'=>$this->configurationHash(),
            'ids'=>$ids,'catalogSeen'=>[],'offset'=>0,'catalogComplete'=>false,'cursor'=>0,'items'=>[],'applyCursor'=>0,'mode'=>null];
        $this->directory(); $this->prune(); $this->save($token,$state);
        return $this->progress($token,$state,[]);
    }

    /** One collection page or one exact service/router observation per request. */
    public function step(string $token): array
    {
        return $this->locked($token,function(array &$state) use($token):array {
            $this->deadline=microtime(true)+12; $this->assertConfiguration($state);
            if (!$state['catalogComplete']) {
                $page=$this->get('clients/services?'.http_build_query(['limit'=>self::PAGE,'offset'=>$state['offset'],'statuses'=>[0,1,2,3,4,5,6,7,8]]));
                if (!is_array($page)||count($page)>self::PAGE) throw new RuntimeException('sync_preview_catalog_unavailable');
                $db=$this->database(); $trim=new ApiTrim(new ApiAttributes($db));
                try { foreach ($page as $raw) {
                    $raw=is_array($raw)?(object)$raw:$raw;
                    $sid=is_object($raw)?filter_var($raw->id??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]):false;
                    if ($sid===false || !is_array($raw->attributes??null) || isset($state['catalogSeen'][$sid])) throw new RuntimeException('sync_preview_catalog_unavailable');
                    $state['catalogSeen'][$sid]=true;
                    try { $entity=$trim->trim('service',json_decode(json_encode($raw),false))['entity']; }
                    catch (InvalidArgumentException $error) { continue; }
                    if ((int)($entity['device']??0)===$state['deviceId']) $state['ids'][]=$sid;
                } } finally { $db->close(); }
                if (count($state['catalogSeen'])>self::MAX_SERVICES) throw new RuntimeException('sync_preview_limit');
                $state['ids']=array_values(array_unique($state['ids'])); sort($state['ids'],SORT_NUMERIC);
                $state['offset']+=count($page); $state['catalogComplete']=count($page)<self::PAGE;
                return $this->progress($token,$state,[]);
            }
            if ($state['cursor']>=count($state['ids'])) return $this->progress($token,$state,[]);
            $sid=$state['ids'][$state['cursor']];
            try { $item=$this->observe($sid,$state); }
            catch (Throwable $error) { $item=['public'=>$this->blocked($sid,$this->reason($error))]; }
            $state['items'][]=$item; $state['cursor']++;
            return $this->progress($token,$state,[$item['public']]);
        });
    }

    /** Consume before invoking the ordinary fresh-state/service-lock path; never replay an uncertain enqueue. */
    public function applyStep(string $token,string $mode): array
    {
        if (!in_array($mode,['differences','force_reconnect'],true)) throw new InvalidArgumentException('sync_preview_invalid_mode');
        $selected=$this->locked($token,function(array &$state) use($mode):array {
            if (!$state['catalogComplete'] || $state['cursor']!==count($state['ids'])) throw new RuntimeException('sync_preview_incomplete');
            $this->assertConfiguration($state);
            if ($state['mode']!==null && $state['mode']!==$mode) throw new RuntimeException('sync_preview_mode_changed');
            $state['mode']=$mode;
            if ($state['applyCursor']>=count($state['items'])) return ['done'=>true,'processed'=>$state['applyCursor'],'total'=>count($state['items'])];
            $index=$state['applyCursor']++; $item=$state['items'][$index];
            $state['items'][$index]['consumed']=true;
            return ['done'=>false,'item'=>$item,'session'=>$state,'processed'=>$state['applyCursor'],'total'=>count($state['items'])];
        });
        if (!isset($selected['item'])) return $selected+['state'=>'complete','jobKeys'=>[]];
        $item=$selected['item']; $public=$item['public']; $sid=$public['serviceId'];
        $result=['serviceId'=>$sid,'processed'=>$selected['processed'],'total'=>$selected['total'],
            'done'=>$selected['processed']===$selected['total'],'state'=>'skipped','reason'=>$public['reason'],'jobKeys'=>[]];
        if ($public['state']==='blocked') { $result['state']='blocked'; return $result; }
        if ($mode==='differences' && $public['state']==='unchanged') return $result;
        $state=$selected['session']; $this->deadline=microtime(true)+12; $guardPassed=false; $keys=[]; $clientNames=[];
        $guard=function(array $fresh,array $applied) use($sid,$state,$item,&$guardPassed,&$clientNames):bool {
            try {
                $this->assertConfiguration($state);
                if ($this->intentHash($fresh)!==$item['intent'] || $this->hash($applied)!==$item['applied']) return false;
                $current=$this->observe($sid,$state,$fresh,$applied);
                $guardPassed=$current['public']['state']!=='blocked' && $current['commands']===$item['commands'] && $current['router']===$item['router'];
                if ($guardPassed) $clientNames=$current['clientNames'];
                return $guardPassed;
            } catch (Throwable $ignored) { return false; }
        };
        $sink=function(array $fresh) use($item,$mode,&$keys,&$clientNames):void {
            $keys=(new Batch())->queueReviewed(array_replace($fresh,$clientNames),fn(array $snapshot):bool=>$this->commandHash($snapshot['_commands']??[])===$item['commands'],$mode==='force_reconnect');
        };
        try {
            if ($this->applier!==null) $response=($this->applier)($sid,$mode==='force_reconnect',$guard,$sink);
            else { require_once __DIR__.'/api_action.php'; $response=(new ApiAction())->reconcileService($sid,true,$mode==='force_reconnect',$guard,$sink); }
            if (!$guardPassed) { $result['state']='blocked'; $result['reason']='sync_preview_state_changed'; }
            elseif (!$keys && is_array($response) && isset($response['jobKeys'])) { $result['jobKeys']=$response['jobKeys']; $result['state']='queued'; $result['reason']=null; }
            elseif ($keys) { $result['jobKeys']=$keys; $result['state']='queued'; $result['reason']=null; }
            else { $result['state']='unconfirmed'; $result['reason']='sync_preview_enqueue_unconfirmed'; }
        } catch (Throwable $ignored) { $result['state']='unconfirmed'; $result['reason']='sync_preview_enqueue_unconfirmed'; }
        return $result;
    }

    private function observe(int $sid,array $session,?array $fresh=null,?array $applied=null): array
    {
        $db=$this->database(); $cache=$this->cache();
        try {
            [$config,$devices]=$this->configuration(); $queue=$this->queueProjection();
            $fresh=$fresh??(new ApiTrim(new ApiAttributes($db)))->trim('service',
                (new ApiWebhookFreshness(fn(string $path)=>$this->get($path),$this->deadline))->service($sid))['entity'];
            $cached=$cache->singleQuery('SELECT * FROM services WHERE id=?',true,[$sid])??[];
            $service=array_replace($cached,['uploadSpeed'=>null,'downloadSpeed'=>null,'uploadSpeedOverride'=>null,'downloadSpeedOverride'=>null,'trafficShapingOverrideEnd'=>null,'trafficShapingOverrideEnabled'=>null],$fresh); $cachedNetwork=$cache->singleQuery('SELECT address,address6 FROM network WHERE id=?',true,[$sid])??[];
            $service['network']=array_replace($cachedNetwork,$fresh['network']??[]);
            $intent=$this->intentHash($fresh);
            $did=(int)($service['device']??0);
            if ($did!==$session['deviceId'] || !isset($devices[$did])) return ['public'=>$this->blocked($sid,'sync_preview_site_changed')];
            if (!in_array((int)($service['status']??-1),[1,3,4,7],true)) return ['public'=>$this->blocked($sid,'sync_preview_service_inactive')];
            if (isset($queue['pending'][$sid]) || isset($queue['blocked'][$sid])) return ['public'=>$this->blocked($sid,'sync_preview_pending_work')];
            $client=$cache->singleQuery('SELECT * FROM clients WHERE id=?',true,[(int)$service['clientId']])??[];
            $currentClient=$this->get('clients/'.(int)$service['clientId']);
            // Older cache rows intentionally retain an unknown archive flag. Only
            // the exact live client can establish its current lifecycle state.
            if ((!is_object($currentClient)&&!is_array($currentClient))
                || is_bool(((array)$currentClient)['id']??null) || is_float(((array)$currentClient)['id']??null)
                || filter_var(((array)$currentClient)['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])!==(int)$service['clientId']
                || !in_array(((array)$currentClient)['isArchived']??null,[0,'0',false],true)) return ['public'=>$this->blocked($sid,'sync_preview_client_unconfirmed')];
            $names=array_fill_keys(['company','firstName','lastName'],true);
            $currentClient=(array)$currentClient;
            if (array_key_exists('companyName',$currentClient)) $currentClient['company']=$currentClient['companyName'];
            $service=array_replace($service,array_intersect_key($client,$names),array_intersect_key($currentClient,$names));
            $service+=['company'=>null,'firstName'=>null,'lastName'=>null];
            $service['_device']=$devices[$did]; $service['_config']=$config; $service['action']='update'; $service['batch']='review';
            if (ApiSiteAddressPolicy::foreignAddresses($service,$devices[$did],$config,$devices)) return ['public'=>$this->blocked($sid,'sync_preview_foreign_site_address')];
            $applied=$applied??($queue['applied'][$sid]??[]);
            foreach (['address','address6'] as $field) {
                $previous=is_array($applied['network']??null)&&array_key_exists($field,$applied['network'])
                    ? $applied['network'][$field] : ($applied[$field]??null);
                if (is_array($fresh['network']??null) && array_key_exists($field,$fresh['network']) && $fresh['network'][$field]===null
                    && is_string($cachedNetwork[$field]??null) && $cachedNetwork[$field]!=='') $previous=$cachedNetwork[$field];
                if (($service['network'][$field]??null)===null && is_string($previous)&&$previous!=='')
                    $service['_network_reset'][$field]=['previous'=>$previous,'generation'=>substr($this->hash([$sid,$field,$previous,$applied,$devices[$did]]),0,32)];
            }
            $plans=Batch::previewPlans($db); $plan=$plans[$service['planId']??0]??null;
            if ($plan===null) return ['public'=>$this->blocked($sid,'sync_preview_plan_unavailable')];
            foreach ($session['items'] as $previousItem) {
                $peer=$previousItem['public']; if ($peer['serviceId']===$sid || $peer['state']==='blocked') continue;
                if ($peer['newAddress']) $queue['reserved4'][]=['id'=>$peer['serviceId'],'address'=>$peer['newAddress']];
                if ($peer['newAddress6']) $queue['reserved6'][]=['id'=>$peer['serviceId'],'address6'=>$peer['newAddress6']];
            }
            $addresses=new ApiIP(true,false,['db'=>$db,'cache'=>$cache]+$queue);
            $commands=MtData::previewCommands($service,$plan,$addresses,$db,$cache,
                ['resourceSnapshots'=>$queue['resourceSnapshots'],'blocked'=>$queue['blocked'],'applied'=>$applied]);
            $observation=$this->inspectCommands($devices[$did],$service,$commands);
            if ($observation['blocked']!==null) return ['public'=>$this->blocked($sid,$observation['blocked'])];
            $migration=false;
            if ($applied) {
                foreach (['device','clientId','username','mac','hotspot','duid','iaid'] as $field)
                    if (($applied[$field]??null)!=($service[$field]??null)) $migration=true;
                if ($migration) {
                    $sourceId=(int)($applied['device']??0); $source=$devices[$sourceId]??null;
                    if ($source===null || ($source['ip']??null)!==($applied['_device']['ip']??null)
                        || (int)($source['port']??8728)!==(int)($applied['_device']['port']??8728)) return ['public'=>$this->blocked($sid,'sync_preview_source_router_changed')];
                    $cleanup=[];
                    foreach($applied['_commands']??[] as $command) {
                        $path=trim($command['path']??'','/');
                        if (!in_array($path,['ppp/secret','ip/hotspot/user','ip/dhcp-server/lease','ipv6/dhcp-server/binding'],true)
                            && !($path==='queue/simple' && (int)($command['_service_id']??0)===$sid)) continue;
                        $command['action']='remove'; $cleanup[]=$command;
                    }
                    if (!$cleanup) return ['public'=>$this->blocked($sid,'sync_preview_cleanup_unconfirmed')];
                    $prior=$this->inspectCommands($source,$applied,$cleanup);
                    if ($prior['blocked']!==null) return ['public'=>$this->blocked($sid,$prior['blocked'])];
                    $observation['checks']=array_merge($observation['checks'],$prior['checks']);
                    $observation['fingerprint']=$this->hash([$observation['fingerprint'],$prior['fingerprint']]);
                }
            }
            $old4=null; $old6=null; $new4=null; $new6=null; $changed=false; $fields=[];
            foreach ($observation['checks'] as $check) {
                if ($check['state']==='unconfirmed') return ['public'=>$this->blocked($sid,'sync_preview_router_unavailable')];
                if ($check['state']==='mismatch') $changed=true;
                foreach ($check['fields'] as $field) {
                    $field['path']=$check['path']; $fields[]=$field;
                    if (in_array($field['field'],['remote-address','address'],true) && in_array(trim($check['path'],'/'),['ppp/secret','ip/hotspot/user','ip/dhcp-server/lease'],true)) { $old4=$field['observed']; $new4=$field['expected']; }
                    if (in_array($field['field'],['remote-ipv6-prefix'],true) || ($field['field']==='address'&&trim($check['path'],'/')==='ipv6/dhcp-server/binding')) { $old6=$field['observed']; $new6=$field['expected']; }
                }
            }
            // Missing accounts still have concrete proposed addresses, represented by absence fields.
            foreach ($commands as $command) {
                $path=trim($command['path'],'/');
                if ($path==='ppp/secret') { $new4=$command['remote-address']??null; $new6=$command['remote-ipv6-prefix']??null; }
                elseif (in_array($path,['ip/hotspot/user','ip/dhcp-server/lease'],true)) $new4=$command['address']??null;
                elseif ($path==='ipv6/dhcp-server/binding') $new6=$command['address']??null;
            }
            $credentialChanged=isset($service['password']) && isset($applied['password']) ? $service['password']!==$applied['password'] : null;
            $changed=$changed||$credentialChanged===true||$migration;
            $public=['serviceId'=>$sid,'clientId'=>(int)$service['clientId'],'clientName'=>self::safe(trim(implode(' ',array_filter([$service['company']??null,$service['firstName']??null,$service['lastName']??null])))),
                'state'=>$changed?'changes':'unchanged','reason'=>null,'oldAddress'=>$old4,'newAddress'=>$new4,'oldAddress6'=>$old6,'newAddress6'=>$new6,
                'reconnect'=>filter_var($service['mac']??'',FILTER_VALIDATE_MAC)?false:$changed,'credentialChange'=>$credentialChanged,'credentialsVerified'=>false,
                'migration'=>$migration,'sourceDeviceId'=>$migration?(int)$applied['device']:null,'deviceId'=>$did,'fields'=>$fields,'checkedAt'=>gmdate('c',(int)$this->now())];
            return ['public'=>$public,'intent'=>$intent,'applied'=>$this->hash($applied),
                'commands'=>$this->commandHash($commands),'router'=>$observation['fingerprint'],'clientNames'=>array_intersect_key($service,$names)];
        } finally { $db->close(); $cache->close(); }
    }

    /** Exact filtered reads only. Neither find_id nor make-static/write paths are used. */
    private function inspectCommands(array $device,array $service,array $commands): array
    {
        $inspect=function(callable $read) use($service,$commands):array {
            $checks=[]; $seen=[];
            foreach ($commands as $command) {
                $path=trim($command['path'],'/');
                if (in_array($path,['ppp/active','ip/hotspot/active'],true)) continue;
                $effective=$command; unset($effective['batch']);
                if (strpos($path,'profile')!==false && isset($command['_owner'])) {
                    if (preg_match('/^uisp-ros-plugin:plan:([1-9][0-9]*)$/D',$command['_owner'],$match)) $effective['comment']='Plan #'.$match[1];
                    elseif (preg_match('/^uisp-ros-plugin:service-profile:([1-9][0-9]*)$/D',$command['_owner'],$match)) $effective['comment']='Servicio #'.$match[1];
                }
                $check=ApiProvisioningVerification::expectation($command,$effective,$command['action']??'set');
                if (isset($check['skip'])) return ['blocked'=>'sync_preview_identity_unconfirmed','checks'=>[],'fingerprint'=>''];
                $rows=$read($check['path'],$check['filter']);
                if (is_array($rows) && isset($command['_owner']) && strpos($path,'profile')!==false && empty($command['_bootstrap_suspension'])) {
                    $ownerRows=$read($check['path'],['comment'=>$command['_owner']]);
                    $labelRows=$read($check['path'],['comment'=>$effective['comment']??$command['_owner']]);
                    if (!is_array($ownerRows)||!is_array($labelRows)) return ['blocked'=>'sync_preview_router_unavailable','checks'=>[],'fingerprint'=>''];
                    $matches=[]; foreach(array_merge($rows,$ownerRows,$labelRows) as $candidate) {
                        if (!is_array($candidate)||!$this->owned($command,$candidate,$service)) return ['blocked'=>'sync_preview_ownership_unconfirmed','checks'=>[],'fingerprint'=>''];
                        $matches[$candidate['.id']]=$candidate;
                    }
                    $rows=array_values($matches);
                }
                if (!is_array($rows)||count($rows)>1) return ['blocked'=>is_array($rows)?'sync_preview_identity_ambiguous':'sync_preview_router_unavailable','checks'=>[],'fingerprint'=>''];
                $row=$rows[0]??null;
                if ($row!==null && !$this->owned($command,$row,$service)) return ['blocked'=>'sync_preview_ownership_unconfirmed','checks'=>[],'fingerprint'=>''];
                if (!empty($command['_bootstrap_suspension']) && $row!==null) continue;
                if (array_key_exists('local-address',$check['expected']) && $check['expected']['local-address']===null) {
                    $locals=$read('/ip/address',['disabled'=>'false','dynamic'=>'false','invalid'=>'false']);
                    $local=is_array($locals)?($locals[0]['address']??null):null;
                    if (!is_string($local)||!filter_var(explode('/',$local)[0],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))
                        return ['blocked'=>'sync_preview_local_address_unavailable','checks'=>[],'fingerprint'=>''];
                    $check['expected']['local-address']=explode('/',$local)[0];
                }
                $seen[]=['path'=>$check['path'],'rows'=>self::safeRows($rows)];
                $result=ApiProvisioningVerification::compare([$check],static fn()=>$rows,$this->deadline)[0];
                if ($row!==null && ($command['action']??'set')==='remove') foreach(['remote-address','remote-ipv6-prefix','address'] as $field)
                    if(isset($row[$field])) $result['fields'][]=['field'=>$field,'expected'=>null,'observed'=>self::safe($row[$field]),'state'=>'mismatch'];
                if ($row===null && ($command['action']??'set')!=='remove') foreach ($check['expected'] as $field=>$value)
                    $result['fields'][]=['field'=>$field,'expected'=>$value,'observed'=>null,'state'=>'mismatch'];
                $checks[]=$result;
            }
            return ['blocked'=>null,'checks'=>$checks,'fingerprint'=>$this->hash($seen)];
        };
        if ($this->routerReader!==null) return $inspect(fn(string $path,array $filter)=>($this->routerReader)((object)$device,$path,$filter,$this->deadline));
        require_once __DIR__.'/routeros_api.class.php'; $api=new RouterosAPI(); $api->timeout=3; $api->attempts=1;
        $api->port=(int)($device['port']??8728); $api->ssl=isset($device['ssl'])?filter_var($device['ssl'],FILTER_VALIDATE_BOOLEAN):$api->port===8729;
        $api->allow_self_signed=filter_var($device['allowSelfSigned']??false,FILTER_VALIDATE_BOOLEAN);
        try { return $api->withReadDeadline($this->deadline,function() use($api,$device,$inspect):array {
            if (!$api->connect($device['ip'],$device['user'],$device['password']??null)) throw new RuntimeException('sync_preview_router_unavailable');
            return $inspect(function(string $path,array $filter) use($api):array {
                $args=[]; foreach ($filter as $key=>$value) $args['?'.$key]=$value;
                // RouterOS secrets are never requested or serialized by this review.
                $args['.proplist']='.id,name,user,comment,remote-address,remote-ipv6-prefix,local-address,profile,caller-id,service,disabled,address,mac-address,address-lists,lease-time,server,duid,iaid,life-time,prefix-pool,rate-limit,parent-queue,address-pool,max-limit,limit-at,target,parent,burst-limit,burst-threshold,burst-time,priority,prefix,prefix-length';
                $rows=$api->comm('/'.trim($path,'/').'/print',$args);
                if (!is_array($rows)||isset($rows['!trap'])||isset($rows['!fatal'])) throw new RuntimeException('sync_preview_router_unavailable');
                return $rows;
            });
        }); } finally { $api->disconnect(); }
    }

    private function owned(array $command,array $row,array $service): bool
    {
        if (!is_string($row['.id']??null)||!preg_match('/^\*[a-fA-F0-9]+$/D',$row['.id'])) return false;
        if (!empty($command['_bootstrap_suspension'])) return true;
        $comment=$row['comment']??null; if (!is_string($comment)) return false;
        if (isset($command['_owner'])) {
            if (hash_equals((string)$command['_owner'],$comment)) return true;
            if (preg_match('/^uisp-ros-plugin:plan:([1-9][0-9]*)$/D',$command['_owner'],$m)) return $comment==='Plan #'.$m[1]
                && (($row['name']??'')==='uisp-plan-'.$m[1] || preg_match('/\[UISP#'.$m[1].'\]$/D',$row['name']??'')===1);
            if (preg_match('/^uisp-ros-plugin:service-profile:([1-9][0-9]*)$/D',$command['_owner'],$m)) return $comment==='Servicio #'.$m[1] && ($row['name']??'')==='uisp-service-'.$m[1].'-profile';
            return false;
        }
        return preg_match('/^([1-9][0-9]*)\s+-\s+.+\s+-\s+([1-9][0-9]*)$/D',$comment,$m)===1
            && (int)$m[1]===(int)$service['clientId'] && (int)$m[2]===(int)$service['id'];
    }

    private function queueProjection(): array
    {
        $result=['reserved4'=>[],'reserved6'=>[],'blocked'=>[],'pending'=>[],'applied'=>[],'resourceSnapshots'=>[]];
        if (!is_file($this->jobsPath)) return $result;
        $db=new ApiSqlite($this->jobsPath,true);
        try {
            $db->exec('BEGIN');
            foreach ($db->selectCustom("SELECT name FROM sqlite_master WHERE type IN ('view','trigger')")??[] as $ignored) throw new RuntimeException('sync_preview_queue_unavailable');
            foreach (['service_tombstones','service_versions','jobs','cancellation_cleanups','retained_resources'] as $table) {
                if ((int)$db->singleQuery('SELECT COUNT(*) FROM '.$table)>self::MAX_SERVICES) throw new RuntimeException('sync_preview_limit');
                $field=$table==='service_versions'?'applied_payload':'payload';
                if ($table!=='service_tombstones' && (int)$db->singleQuery('SELECT MAX(length('.$field.')) FROM '.$table)>1048576) throw new RuntimeException('sync_preview_limit');
            }
            foreach ($db->selectCustom('SELECT service_id FROM service_tombstones LIMIT 20001')??[] as $row) $result['blocked'][(int)$row['service_id']]=true;
            foreach ($db->selectCustom('SELECT service_id,applied_payload FROM service_versions WHERE applied_payload IS NOT NULL LIMIT 20001')??[] as $row) {
                $snapshot=$this->payload($row['applied_payload']); $result['applied'][(int)$row['service_id']]=$snapshot;
                $result['resourceSnapshots'][]=$snapshot;
                foreach ([false,true] as $ipv6) ApiJobQueue::collectAddresses($result[$ipv6?'reserved6':'reserved4'],(int)$row['service_id'],$snapshot,$ipv6,false);
            }
            foreach ($db->selectCustom("SELECT service_id,status,payload,prior_cleared,cleanup_done FROM jobs WHERE status IN ('pending','running','cancelled','superseded') LIMIT 20001")??[] as $row) {
                $sid=(int)$row['service_id']; $snapshot=$this->payload($row['payload']);
                if (in_array($row['status'],['pending','running'],true)) $result['pending'][$sid]=true;
                if (isset($snapshot['_commands'])) $result['resourceSnapshots'][]=$snapshot;
                if (isset($snapshot['_cleanup'])&&!$row['cleanup_done']) $result['resourceSnapshots'][]=$snapshot['_cleanup'];
                foreach ([false,true] as $ipv6) { $key=$ipv6?'reserved6':'reserved4';
                    if (isset($snapshot['_commands'])&&is_array($snapshot['_commands'])) ApiJobQueue::collectAddresses($result[$key],$sid,$snapshot,$ipv6,false);
                    if (!$row['prior_cleared']) ApiJobQueue::collectAddresses($result[$key],$sid,['_prior_network'=>$snapshot['_prior_network']??[]],$ipv6,true);
                    if (isset($snapshot['_cleanup'])&&!$row['cleanup_done']) ApiJobQueue::collectAddresses($result[$key],$sid,$snapshot['_cleanup'],$ipv6,false);
                }
            }
            foreach (['cancellation_cleanups','retained_resources'] as $table) foreach ($db->selectCustom('SELECT * FROM '.$table.' LIMIT 20001')??[] as $row) {
                $sid=(int)$row['service_id']; $snapshot=$this->payload($row['payload']);
                if ($table==='cancellation_cleanups') $result['pending'][$sid]=true;
                if ($table==='cancellation_cleanups' || $row['slot']!=='source' || isset($snapshot['_commands'])) $result['resourceSnapshots'][]=$snapshot;
                if ($table==='retained_resources' && $row['slot']==='source' && isset($snapshot['_cleanup'])) $result['resourceSnapshots'][]=$snapshot['_cleanup'];
                foreach ([false,true] as $ipv6) { $key=$ipv6?'reserved6':'reserved4';
                    if ($table==='retained_resources' && $row['slot']==='source') {
                        if (isset($snapshot['_commands'])&&is_array($snapshot['_commands'])) ApiJobQueue::collectAddresses($result[$key],$sid,$snapshot,$ipv6,false);
                        ApiJobQueue::collectAddresses($result[$key],$sid,['_prior_network'=>$snapshot['_prior_network']??[]],$ipv6,true);
                        if (isset($snapshot['_cleanup'])) ApiJobQueue::collectAddresses($result[$key],$sid,$snapshot['_cleanup'],$ipv6,true);
                    } else ApiJobQueue::collectAddresses($result[$key],$sid,$snapshot,$ipv6,true);
                }
            }
            foreach (['reserved4','reserved6'] as $key) $result[$key]=array_values($result[$key]);
            foreach ($result as $rows) if (count($rows)>self::MAX_SERVICES) throw new RuntimeException('sync_preview_limit');
            return $result;
        } finally { $db->close(); }
    }

    private function get(string $path)
    {
        if (microtime(true)>=$this->deadline) throw new RuntimeException('sync_preview_timeout');
        if ($this->request!==null) $value=($this->request)($path);
        else {
            require_once __DIR__.'/api_uisp_client.php'; $sdk=ApiUispClient::crm();
            if (class_exists(\GuzzleHttp\Client::class)) {
                $property=new ReflectionProperty($sdk,'client'); if (PHP_VERSION_ID<80100) $property->setAccessible(true);
                $client=$property->getValue($sdk); $options=$client->getConfig(); $remaining=max(0.1,$this->deadline-microtime(true));
                $options['timeout']=min(5,$remaining); $options['connect_timeout']=min(2,$remaining); $options['read_timeout']=min(5,$remaining);
                $property->setValue($sdk,new \GuzzleHttp\Client($options));
            }
            $parts=explode('?',$path,2); $query=[]; if (isset($parts[1])) parse_str($parts[1],$query); $value=$sdk->get($parts[0],$query);
        }
        if (microtime(true)>=$this->deadline) throw new RuntimeException('sync_preview_timeout');
        return $value;
    }

    private function payload(string $payload): array
    { if (strlen($payload)>1048576) throw new RuntimeException('sync_preview_limit'); $value=json_decode($payload,true,64,JSON_THROW_ON_ERROR); if (!is_array($value)) throw new RuntimeException('sync_preview_queue_unavailable'); return $value; }
    private function database(): ApiSqlite { return new ApiSqlite($this->dataPath,true); }
    private function cache(): ApiSqlite { return new ApiSqlite($this->cachePath,true); }
    private function configuration(): array {
        [, $devices]=ApiSiteAddressPolicy::stored($this->dataPath); $db=$this->database();
        try { return [(array)$db->readConfig(),$devices]; } finally { $db->close(); }
    }
    private function configurationHash(): string { $db=$this->database(); try { return $this->hash([$this->configuration(),Batch::previewPlans($db)]); } finally { $db->close(); } }
    private function assertConfiguration(array $state): void { if ($this->configurationHash()!==$state['configuration']) throw new RuntimeException('sync_preview_state_changed'); }
    private function intentHash(array $service): string { $values=[]; foreach(self::INTENT_FIELDS as $field) $values[$field]=$service[$field]??null; return $this->hash($values); }
    private function commandHash(array $commands): string { foreach($commands as &$command) { unset($command['batch'],$command['_reconnect_required']); } unset($command); return $this->hash($commands); }
    private function hash($value): string { return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)); }
    private function now(): float { return $this->clock!==null?(float)($this->clock)():microtime(true); }
    private static function safe($value): ?string { return is_scalar($value)&&strlen((string)$value)<=512&&!preg_match('/[\x00-\x1f\x7f]/',(string)$value)?(string)$value:null; }
    private static function safeRows(array $rows): array { $safe=[]; foreach($rows as $row) { if (!is_array($row)) throw new RuntimeException('sync_preview_router_unavailable'); $value=[]; foreach($row as $key=>$field) if (!in_array($key,['password','secret'],true)) $value[$key]=self::safe($field); ksort($value); $safe[]=$value; } return $safe; }
    private function blocked(int $sid,string $reason): array { return ['serviceId'=>$sid,'state'=>'blocked','reason'=>$reason,'oldAddress'=>null,'newAddress'=>null,'oldAddress6'=>null,'newAddress6'=>null,'reconnect'=>null,'fields'=>[]]; }
    private function reason(Throwable $error): string {
        $code=$error->getMessage(); $allowed=explode(' ','address_conflict busy catalog_unavailable cleanup_unconfirmed client_unconfirmed configuration_missing enqueue_unconfirmed expired foreign_site_address identity_ambiguous identity_unconfirmed incomplete invalid_mode invalid_token limit local_address_unavailable mode_changed observation_unavailable ownership_unconfirmed pending_work plan_unavailable queue_unavailable router_ambiguous router_unavailable service_inactive session_limit site_changed source_router_changed state_changed storage_unavailable timeout');
        return in_array($code,array_map(static fn(string $name):string=>'sync_preview_'.$name,$allowed),true)?$code
            :($error instanceof NetworkAddressConflict?'sync_preview_address_conflict':'sync_preview_observation_unavailable');
    }
    private function progress(string $token,array $state,array $items): array {
        $summary=['changes'=>0,'unchanged'=>0,'blocked'=>0]; foreach($state['items'] as $item) $summary[$item['public']['state']]++;
        return ['previewToken'=>$token,'expiresAt'=>gmdate('c',(int)$state['expires']),'total'=>count($state['ids']),'processed'=>$state['cursor'],'catalogComplete'=>$state['catalogComplete'],
            'catalogScanned'=>count($state['catalogSeen']),'done'=>$state['catalogComplete']&&$state['cursor']===count($state['ids']),'items'=>$items,'summary'=>$summary];
    }
    private function directory(): string {
        $base=dirname($this->dataPath); if (!is_dir($base)||is_link($base)) throw new RuntimeException('sync_preview_storage_unavailable'); $path=$base.'/sync-previews';
        if (is_link($path)||(!is_dir($path)&&!mkdir($path,0700))) throw new RuntimeException('sync_preview_storage_unavailable'); @chmod($path,0700); return $path;
    }
    private function path(string $token): string { if (!preg_match('/^[a-f0-9]{64}$/D',$token)) throw new RuntimeException('sync_preview_invalid_token'); return $this->directory().'/'.hash('sha256',$token).'.json'; }
    private function save(string $token,array $state): void {
        $path=$this->path($token); if (is_link($path)) throw new RuntimeException('sync_preview_storage_unavailable'); $json=json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if(strlen($json)>16777216) throw new RuntimeException('sync_preview_limit'); $temporary=tempnam(dirname($path),'.preview-');
        try { chmod($temporary,0600); $handle=fopen($temporary,'wb'); if(!$handle) throw new RuntimeException('sync_preview_storage_unavailable');
            try { if(fwrite($handle,$json)!==strlen($json)||!fflush($handle)||(function_exists('fsync')&&!fsync($handle))) throw new RuntimeException('sync_preview_storage_unavailable'); }
            finally { fclose($handle); }
            if(!rename($temporary,$path)) throw new RuntimeException('sync_preview_storage_unavailable'); chmod($path,0600); }
        finally { if(is_file($temporary)) unlink($temporary); }
    }
    private function locked(string $token,callable $operation): array {
        $path=$this->path($token); $lockPath=$path.'.lock'; if(is_link($lockPath)||is_link($path)) throw new RuntimeException('sync_preview_invalid_token');
        $lock=fopen($lockPath,'c+b'); if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)) { if($lock)fclose($lock); throw new RuntimeException('sync_preview_busy'); }
        chmod($lockPath,0600);
        try { if(!is_file($path)||filesize($path)>16777216) throw new RuntimeException('sync_preview_invalid_token'); $state=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
            if(!is_array($state)||($state['schema']??null)!==1||!is_numeric($state['expires']??null)||$state['expires']<$this->now()) throw new RuntimeException('sync_preview_expired');
            $result=$operation($state); $this->save($token,$state); return $result;
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    private function prune(): void {
        $active=0; foreach(new DirectoryIterator($this->directory()) as $entry) {
            if(!$entry->isFile()||$entry->isLink()||!preg_match('/^[a-f0-9]{64}\.json$/D',$entry->getFilename())) continue;
            if($entry->getMTime()+660<$this->now()) { @unlink($entry->getPathname()); $lock=$entry->getPathname().'.lock'; if(is_file($lock)&&!is_link($lock))@unlink($lock); }
            else $active++;
        } if($active>=16) throw new RuntimeException('sync_preview_session_limit');
    }
}
