<?php
require_once __DIR__.'/api_trim.php';
require_once __DIR__.'/api_site_address_policy.php';
require_once __DIR__.'/api_shared_resources.php';

/** Read-only, fresh evidence for a deliberately reviewed account repair. No reusable write permission is issued here. */
final class ApiServiceResolutionEvidence
{
    private $request;
    private $reader;
    private string $dataPath;
    private string $cachePath;
    private string $jobsPath;
    private ?float $externalDeadline;
    private float $deadline=0;
    private int $requests=0;
    private array $configuration=[];
    private array $devices=[];
    private array $owners=[];
    private array $clients=[];
    private array $localClaims=[];
    private array $localPending=[];
    private array $attributeTypes=[];
    private const FIELDS=['.id','name','comment','remote-address','remote-ipv6-prefix','address','profile','disabled','dynamic','server',
        'mac-address','duid','iaid','service','caller-id','rate-limit','address-lists','lease-time','life-time','prefix-pool',
        'target','parent','max-limit','limit-at','burst-limit','burst-threshold','burst-time','priority','queue'];

    public function __construct(callable $request,callable $reader,string $dataPath='data/data.db',string $cachePath='data/cache.db',
        string $jobsPath='data/jobs.db',?float $deadline=null)
    {$this->request=$request;$this->reader=$reader;$this->dataPath=$dataPath;$this->cachePath=$cachePath;$this->jobsPath=$jobsPath;$this->externalDeadline=$deadline;}

    public function inspect(int $sid): array
    {
        if($sid<1)throw new InvalidArgumentException('invalid_service_id');
        $this->deadline=min(microtime(true)+30,$this->externalDeadline??INF);$this->requests=0;$this->owners=[];$this->clients=[];$this->localClaims=[];$this->localPending=[];
        $result=['serviceId'=>$sid,'clientId'=>null,'deviceId'=>null,'device'=>null,'service'=>null,'clientNames'=>null,'targetComment'=>null,
            'path'=>null,'selector'=>[],'rows'=>[],'configurationHash'=>null,'intentHash'=>null,'fingerprint'=>null,
            'referencedOwners'=>[],'retiredServiceIds'=>[],'derivedRepairs'=>[],'mode'=>'blocked','eligible'=>false,'autoApply'=>false,'defaultRowId'=>null,
            'reason'=>'resolution_unavailable','choices'=>[],
            'public'=>['serviceId'=>$sid,'clientId'=>null,'deviceId'=>null,'clientName'=>null,'site'=>null,'mode'=>'blocked','eligible'=>false,
                'autoApply'=>false,'reason'=>'resolution_unavailable','candidates'=>[],'changes'=>[],'willReconnect'=>false]];
        try{
            [$this->configuration,$this->devices]=ApiSiteAddressPolicy::stored($this->dataPath);
            if(!$this->configuration||!$this->devices)$this->fail('resolution_configuration_unavailable');
            $configurationHash=$this->hash([$this->configuration,$this->devices]);
            $this->attributes();
            $raw=$this->serviceValue($this->get('clients/services/'.$sid),$sid);
            $this->targetMappings($raw);
            if(!in_array((int)$raw['status'],[1,3,4,7],true))$this->fail('resolution_service_inactive');
            $cid=self::id($raw['clientId']);$client=$this->client($cid);
            if($client['isArchived'])$this->fail('resolution_client_archived');
            $service=$this->normalize($raw);$device=$this->device($service);$did=(int)$device['id'];
            [$path,$selector]=$this->principal($service);
            $names=$client['names'];$name=$this->clientName($names);$accountName=$names['company']?:($names['firstName']&&$names['lastName']?$names['firstName'].' '.$names['lastName']:'Client-'.$cid);
            $comment=$cid.' - '.$accountName.' - '.$sid;
            $service=array_replace($service,$names);
            $result=array_replace($result,['clientId'=>$cid,'deviceId'=>$did,'device'=>$device,'service'=>$service,'clientNames'=>$names,
                'targetComment'=>$comment,'path'=>$path,'selector'=>$selector,'configurationHash'=>$configurationHash,'intentHash'=>$this->hash($raw)]);
            $result['public']=array_replace($result['public'],['clientId'=>$cid,'deviceId'=>$did,'clientName'=>$name,'site'=>self::text($device['name']??null,256),
                'willReconnect'=>in_array($path,['ppp/secret','ip/hotspot/user'],true)]);
            $this->catalog($sid,$raw,$path,$selector,$device);
            $this->queueClaims($sid,$path,$selector,$device);
            $this->budget();$rows=($this->reader)($device,$path,$selector,self::FIELDS);$this->budget();
            if(!is_array($rows)||array_keys($rows)!==array_keys(array_values($rows))||count($rows)>20)$this->fail('resolution_router_ambiguous');
            $ids=[];
            foreach($rows as$row){
                if(!is_array($row)||!is_string($row['.id']??null)||preg_match('/^\*[a-fA-F0-9]+$/D',$row['.id'])!==1||isset($ids[$row['.id']])
                    ||!$this->matches($row,$selector))$this->fail('resolution_row_invalid');
                $ids[$row['.id']]=true;
                foreach($row as$key=>$value)if(in_array($key,self::FIELDS,true)&&$value!==null&&!is_scalar($value))$this->fail('resolution_row_invalid');
                $result['rows'][]=array_intersect_key($row,array_flip(self::FIELDS));
            }
            usort($result['rows'],static fn(array$a,array$b):int=>strcmp($a['.id'],$b['.id']));
            $serverAmbiguous=false;
            if(count($result['rows'])>1&&in_array($path,['ip/dhcp-server/lease','ipv6/dhcp-server/binding'],true)){
                $servers=[];foreach($result['rows']as$row){$server=$row['server']??null;if(!is_string($server)||$server==='')$serverAmbiguous=true;else$servers[$server]=true;}
                if(count($servers)>1)$serverAmbiguous=true;
            }
            $rowProof=[];
            foreach($result['rows']as$row){
                $marker=$this->marker($row['comment']??null);
                $proof=['safe'=>false,'manual'=>false,'reason'=>'resolution_marker_unverified'];
                if($marker===null){$proof=['safe'=>false,'manual'=>true,'reason'=>'resolution_manual_confirmation'];}
                elseif($marker['serviceId']===$sid&&$marker['clientId']===$cid){$proof=['safe'=>true,'manual'=>false,'reason'=>null];}
                elseif($marker['serviceId']===$sid){$proof=['safe'=>false,'manual'=>false,'reason'=>'resolution_marker_unverified'];}
                else{
                    try{$owner=$this->owner($marker['serviceId'],$path,$selector,$device);$proof=['safe'=>$owner['safe'],'manual'=>false,'reason'=>$owner['reason']];}
                    catch(Throwable$error){$proof=['safe'=>false,'manual'=>false,'reason'=>$this->reason($error)];}
                }
                $rowProof[$row['.id']]=$proof;
                $result['public']['candidates'][]=['rowId'=>$row['.id'],'path'=>$path,'username'=>self::text($row['name']??null,256),
                    'comment'=>self::display($row['comment']??null,512),'ownerServiceId'=>$marker['serviceId']??null,'ownerClientId'=>$marker['clientId']??null,
                    'address'=>self::text($row['remote-address']??($row['address']??null),256),'profile'=>self::text($row['profile']??null,256),
                    'disabled'=>$this->flag($row['disabled']??null),'dynamic'=>$this->flag($row['dynamic']??null),'server'=>self::text($row['server']??null,256),
                    'selectable'=>false,'reason'=>$proof['reason']];
            }
            foreach($result['rows']as$index=>$row){
                $proof=$rowProof[$row['.id']];$extras=[];$extrasSafe=true;
                foreach($result['rows']as$other)if($other['.id']!==$row['.id']){
                    if(!$rowProof[$other['.id']]['safe'])$extrasSafe=false;else$extras[]=$other['.id'];
                }
                $selectable=($proof['safe']||$proof['manual'])&&$extrasSafe&&!$serverAmbiguous;
                $reason=$serverAmbiguous?'resolution_dhcp_server_ambiguous':(!$extrasSafe?'resolution_duplicates_unreviewed':$proof['reason']);
                $auto=$selectable&&$proof['safe']&&count($result['rows'])===1;
                $result['choices'][]=['rowId'=>$row['.id'],'selectable'=>$selectable,'autoApply'=>$auto,'reason'=>$selectable&&$proof['safe']?null:$reason,
                    'removeRowIds'=>$selectable?$extras:[]];
                $result['public']['candidates'][$index]['selectable']=$selectable;$result['public']['candidates'][$index]['reason']=$selectable&&$proof['safe']?null:$reason;
            }
            if(!$result['rows']){$result['mode']='reconcile';$result['eligible']=true;$result['autoApply']=true;$result['reason']=null;}
            else{
                $eligible=array_values(array_filter($result['choices'],static fn(array$c):bool=>$c['selectable']));
                $result['eligible']=(bool)$eligible;
                if(count($eligible)===1&&$eligible[0]['autoApply']){
                    $result['mode']='reconcile';$result['autoApply']=true;$result['defaultRowId']=$eligible[0]['rowId'];$result['reason']=null;
                }elseif($eligible){$result['mode']='select_account';$result['reason']=count($result['rows'])>1?'resolution_select_account':'resolution_manual_confirmation';}
                else{$result['reason']=$serverAmbiguous?'resolution_dhcp_server_ambiguous':($result['choices'][0]['reason']??'resolution_marker_unverified');}
            }
            $result['derivedRepairs']=$this->derived($sid,$cid,$path,$device,$accountName);
            if($configurationHash!==$this->hash(ApiSiteAddressPolicy::stored($this->dataPath)))$this->fail('resolution_configuration_changed');
            $result['referencedOwners']=$this->owners;$result['retiredServiceIds']=array_values(array_map('intval',array_keys(array_filter($this->owners,static fn(array$o):bool=>($o['state']??null)==='retired'))));
            // Every observation still scans the complete live catalog for
            // competing principals. Unrelated services are not repair evidence.
            $result['fingerprint']=$this->hash([$result['configurationHash'],$result['intentHash'],$client,$result['rows'],$result['derivedRepairs'],$this->owners,$this->localClaims]);
            $result['public']['changes']=$this->changes($result);
        }catch(Throwable$error){$result['mode']='blocked';$result['eligible']=false;$result['autoApply']=false;$result['defaultRowId']=null;$result['reason']=$this->reason($error);$result['choices']=[];
            foreach($result['public']['candidates']as&$candidate){$candidate['selectable']=false;$candidate['reason']=$result['reason'];}unset($candidate);}
        foreach(['mode','eligible','autoApply','reason']as$field)$result['public'][$field]=$result[$field];
        return$result;
    }

    private function attributes(): void
    {
        $attributes=$this->get('custom-attributes');
        if(!is_array($attributes)||array_keys($attributes)!==array_keys(array_values($attributes)))$this->fail('resolution_mapping_unverified');
        $known=[];$ids=[];
        foreach($attributes as$raw){if(!is_array($raw)&&!is_object($raw))$this->fail('resolution_mapping_unverified');$raw=(array)$raw;
            $id=self::id($raw['id']??null);$key=$raw['key']??null;if($id===null||!is_string($key)||$key===''||isset($known[$key])||isset($ids[$id]))$this->fail('resolution_mapping_unverified');
            $known[$key]=$raw['attributeType']??null;$ids[$id]=true;}
        $this->attributeTypes=$known;
        $site=$this->configuration['device_name_attr']??null;if(!is_string($site)||$site===''||($known[$site]??null)!=='service')$this->fail('resolution_mapping_unverified');
    }

    private function targetMappings(array$raw):void
    {
        $present=[];foreach($raw['attributes']as$attribute){$attribute=(array)$attribute;$present[$attribute['key']]=true;}
        foreach(['pppoe_user_attr','mac_addr_attr','dhcp6_duid_attr','dhcp6_iaid_attr','hs_attr','ip_addr_attr','ip_addr6_attr']as$setting){
            $key=$this->configuration[$setting]??null;if(is_string($key)&&$key!==''&&isset($present[$key])&&($this->attributeTypes[$key]??null)!=='service')$this->fail('resolution_mapping_unverified');}
    }

    private function derived(int$sid,int$cid,string$principalPath,array$device,string$accountName):array
    {
        $name=in_array($principalPath,['ip/dhcp-server/lease','ipv6/dhcp-server/binding'],true)?$accountName.' - '.$sid:'uisp-service-'.$sid.'-suspension';
        $this->budget();$rows=($this->reader)($device,'queue/simple',['name'=>$name],self::FIELDS);$this->budget();
        if(!is_array($rows)||array_keys($rows)!==array_keys(array_values($rows))||count($rows)>20)$this->fail('resolution_derived_unverified');
        if(count($rows)<2)return[];
        $safe=[];$ids=[];
        foreach($rows as$row){
            if(!is_array($row)||($row['name']??null)!==$name||!is_string($row['.id']??null)||preg_match('/^\*[a-fA-F0-9]+$/D',$row['.id'])!==1||isset($ids[$row['.id']]))$this->fail('resolution_derived_unverified');
            $marker=$this->marker($row['comment']??null);
            if($marker===null||$marker['serviceId']!==$sid||$marker['clientId']!==$cid)$this->fail('resolution_derived_ownership_unverified');
            $ids[$row['.id']]=true;$safe[]=array_intersect_key($row,array_flip(self::FIELDS));
        }
        usort($safe,static fn(array$a,array$b):int=>strcmp($a['.id'],$b['.id']));
        return[['path'=>'queue/simple','selector'=>['name'=>$name],'rows'=>$safe,'keepRowId'=>$safe[0]['.id'],'removeRowIds'=>array_column(array_slice($safe,1),'.id')]];
    }

    private function catalog(int$sid,array$target,string$path,array$selector,array$device):void
    {
        $seen=[];$found=false;
        for($offset=0;$offset<20000;$offset+=500){
            $page=$this->get('clients/services',['limit'=>500,'offset'=>$offset,'statuses'=>[0,1,2,3,4,5,6,7,8]]);
            if(!is_array($page)||count($page)>500||array_keys($page)!==array_keys(array_values($page)))$this->fail('resolution_catalog_incomplete');
            foreach($page as$value){$raw=$this->serviceValue($value,null,false);$id=(int)$raw['id'];if(isset($seen[$id]))$this->fail('resolution_catalog_incomplete');$seen[$id]=true;
                if($id===$sid){$raw=$this->serviceValue($raw,$sid);$normalized=$this->normalize($raw);
                    if((int)$raw['clientId']!==(int)$target['clientId']||(int)$raw['status']!==(int)$target['status']
                        ||!$this->rawPrincipalMatches($raw,$path,$selector)||$this->endpoint($this->device($normalized))!==$this->endpoint($device))$this->fail('resolution_target_changed');$found=true;continue;}
                if(in_array((int)$raw['status'],[2,5,8],true))continue;
                $raw=$this->serviceValue($raw);
                // Only relevant principals require additional exact reads and client archive evidence.
                if(!$this->rawPrincipalMatches($raw,$path,$selector))continue;
                $owner=$this->owner($id,$path,$selector,$device);
                if(!$owner['safe'])$this->fail($owner['reason']);
            }
            if(count($page)<500){if(!$found)$this->fail('resolution_catalog_incomplete');return;}
        }
        $this->fail('resolution_catalog_incomplete');
    }

    private function owner(int$sid,string$path,array$selector,array$device):array
    {
        if(isset($this->owners[$sid]))return$this->owners[$sid];
        try{$raw=$this->serviceValue($this->get('clients/services/'.$sid),$sid,false);}
        catch(Throwable$error){if(!$this->notFound($error))throw$error;return$this->owners[$sid]=['serviceId'=>$sid,'clientId'=>null,'status'=>null,'state'=>'retired','safe'=>true,'reason'=>null,'fingerprint'=>'404'];}
        $cid=(int)$raw['clientId'];$status=(int)$raw['status'];
        $proof=['serviceId'=>$sid,'clientId'=>$cid,'status'=>$status,'state'=>'active','safe'=>false,'reason'=>'resolution_owner_active','fingerprint'=>$this->hash($raw)];
        if(in_array($status,[2,5,8],true)){$proof['state']='retired';$proof['safe']=true;$proof['reason']=null;return$this->owners[$sid]=$proof;}
        $client=$this->client($cid);$proof['clientFingerprint']=$client['fingerprint'];
        if($client['isArchived']){$proof['state']='retired';$proof['safe']=true;$proof['reason']=null;return$this->owners[$sid]=$proof;}
        $raw=$this->serviceValue($raw,$sid);$this->targetMappings($raw);
        // Exact, complete current attributes can detach an obsolete principal even
        // when its Site was cleared too. Frozen local state is not current ownership.
        // A matching principal still requires its configured physical endpoint.
        $principal=$this->rawPrincipal($raw);$proof['principal']=$principal===null?null:['path'=>$principal[0],'selector'=>$principal[1]];
        if($principal===null||$principal[0]!==$path||$principal[1]!==$selector){
            $proof['state']='disjoint';$proof['safe']=true;$proof['reason']=null;return$this->owners[$sid]=$proof;
        }
        $normalized=$this->normalize($raw);$other=$this->device($normalized);$proof['endpoint']=$this->endpoint($other);
        if($this->endpoint($other)!==$this->endpoint($device)){$proof['state']='disjoint';$proof['safe']=true;$proof['reason']=null;}
        return$this->owners[$sid]=$proof;
    }

    private function queueClaims(int$sid,string$path,array$selector,array$device):void
    {
        if(!is_file($this->jobsPath))return;if(is_link($this->jobsPath))$this->fail('resolution_queue_unverified');
        $db=new SQLite3($this->jobsPath,SQLITE3_OPEN_READONLY);$db->enableExceptions(true);$db->busyTimeout(500);
        try{
            foreach([['jobs',"status IN ('pending','running')",'payload'],['cancellation_cleanups','1','payload'],['service_versions','applied_payload IS NOT NULL','applied_payload']]as[$table,$where,$field]){
                if(!$db->querySingle("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='".$table."'"))continue;
                if($db->querySingle("SELECT count(*) FROM sqlite_master WHERE (type='view' AND name='".$table."') OR (type='trigger' AND tbl_name='".$table."')"))$this->fail('resolution_queue_unverified');
                $read=$db->query('SELECT service_id,'.$field.' AS payload FROM '.$table.' WHERE '.$where.' LIMIT 20001');$count=0;
                try{while($row=$read->fetchArray(SQLITE3_ASSOC)){
                    if(++$count>20000||!is_string($row['payload'])||strlen($row['payload'])>2097152)$this->fail('resolution_queue_unverified');
                    $snapshot=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
                    if(!is_array($snapshot)||self::id($snapshot['id']??null)!==self::id($row['service_id']))$this->fail('resolution_queue_unverified');
                    $this->snapshotClaim($snapshot,$sid,$path,$selector,$device);
                }}finally{$read->finalize();}
            }
        }finally{$db->close();}
        // Resolve canonical owners only after releasing every SQLite reader/statement.
        foreach($this->localPending as$claim){$owner=$this->owner($claim['serviceId'],$path,$selector,$device);
            $this->localClaims[]=$claim+['owner'=>$owner['fingerprint']];if(!$owner['safe'])$this->fail('resolution_local_claim');}
    }
    private function snapshotClaim(array$snapshot,int$sid,string$path,array$selector,array$device,int$depth=0):void
    {
        if($depth>4)$this->fail('resolution_queue_unverified');
        if(isset($snapshot['_cleanup'])){if(!is_array($snapshot['_cleanup']))$this->fail('resolution_queue_unverified');$this->snapshotClaim($snapshot['_cleanup'],$sid,$path,$selector,$device,$depth+1);}
        $id=self::id($snapshot['id']??null);if($id===null)$this->fail('resolution_queue_unverified');if($id===$sid)return;
        $claim=false;
        try{[$snapshotPath,$snapshotSelector]=$this->principal($snapshot);$claim=$snapshotPath===$path&&$snapshotSelector===$selector;}catch(Throwable$ignored){}
        foreach($snapshot['_commands']??[]as$command){if(!is_array($command))$this->fail('resolution_queue_unverified');
            if(trim((string)($command['path']??''),'/')===$path&&$this->matches($command,$selector))$claim=true;}
        if(!$claim)return;
        $did=self::id($snapshot['device']??null);$old=$snapshot['_device']??($this->devices[$did]??null);
        if(!is_array($old)||!is_string($old['ip']??null)||trim($old['ip'])==='')$this->fail('resolution_queue_unverified');
        if($this->endpoint($old)!==$this->endpoint($device))return;
        $this->localPending[]=['serviceId'=>$id,'fingerprint'=>$this->hash($snapshot)];
    }

    private function normalize(array$raw):array
    {
        $db=new ApiSqlite($this->dataPath,true);
        try{$result=(new ApiTrim(new ApiAttributes($db)))->trim('service',json_decode(json_encode($raw,JSON_THROW_ON_ERROR)))['entity'];}
        finally{$db->close();}
        return$result;
    }
    private function device(array$service):array
    {
        $id=self::id($service['device']??null);if($id===null||!isset($this->devices[$id]))$this->fail('resolution_site_unverified');$device=$this->devices[$id];$matches=[];
        foreach($this->devices as$did=>$candidate)if(strcasecmp(trim((string)($candidate['name']??'')),trim((string)($device['name']??'')))===0)$matches[]=(int)$did;
        if($matches!==[$id]||!is_string($device['ip']??null)||trim($device['ip'])==='')$this->fail('resolution_site_unverified');return$device;
    }
    private function principal(array$service):array
    {
        if(filter_var($service['mac']??'',FILTER_VALIDATE_MAC))return['ip/dhcp-server/lease',['mac-address'=>strtoupper($service['mac'])]];
        $user=$service['username']??null;
        if(self::text($user,256)!==null&&$user!=='')return[empty($service['hotspot'])?'ppp/secret':'ip/hotspot/user',['name'=>$user]];
        $duid=$this->duid($service['duid']??null);$iaid=$this->iaid($service['iaid']??1);
        if($duid!==null&&$iaid!==null)return['ipv6/dhcp-server/binding',['duid'=>$duid,'iaid'=>$iaid]];
        $this->fail('resolution_identity_unverified');
    }
    private function rawPrincipalMatches(array$raw,string$path,array$selector):bool
    {try{$principal=$this->rawPrincipal($raw);return$principal!==null&&$principal[0]===$path&&$principal[1]===$selector;}catch(Throwable$ignored){return false;}}
    private function rawPrincipal(array$raw):?array
    {
        $values=[];foreach($raw['attributes']as$attribute){$attribute=(array)$attribute;$values[$attribute['key']]=$attribute['value'];}
        // Match the ordinary attribute reader: routing fields are trimmed while
        // usernames remain literal. Otherwise a whitespace-padded competing MAC
        // or a padded "0" Hotspot flag could disappear from ownership evidence.
        $service=[];foreach(['username'=>'pppoe_user_attr','mac'=>'mac_addr_attr','duid'=>'dhcp6_duid_attr','iaid'=>'dhcp6_iaid_attr','hotspot'=>'hs_attr']as$field=>$setting){
            $value=$values[$this->configuration[$setting]??'']??null;
            if($value!==null)$value=$field==='username'?(string)$value:trim((string)$value);
            $service[$field]=$value===''?null:$value;
        }
        // An explicitly complete empty identity differs from malformed identity
        // data. Keep malformed nonempty values blocked rather than calling them cleared.
        if($service['username']===null&&$service['mac']===null&&$service['duid']===null)return null;
        return$this->principal($service);
    }
    private function matches(array$row,array$selector):bool
    {foreach($selector as$key=>$value){$actual=$row[$key]??null;if($key==='duid'){$actual=$this->duid($actual);}elseif($key==='iaid'){$actual=$this->iaid($actual);}elseif($key==='mac-address'){if(!is_string($actual))return false;$actual=strtoupper($actual);}if((string)$actual!==(string)$value)return false;}return true;}
    private function client(int$cid):array
    {
        if(isset($this->clients[$cid]))return$this->clients[$cid];$raw=$this->get('clients/'.$cid);
        if(!is_array($raw)&&!is_object($raw))$this->fail('resolution_client_unverified');$raw=(array)$raw;
        if(self::id($raw['id']??null)!==$cid||!in_array($raw['isArchived']??null,[true,false,0,1,'0','1'],true))$this->fail('resolution_client_unverified');
        $names=[];foreach(['company'=>'companyName','firstName'=>'firstName','lastName'=>'lastName']as$field=>$key){$value=$raw[$key]??null;if($value!==null&&self::text($value,512)===null)$this->fail('resolution_client_unverified');$names[$field]=$value;}
        return$this->clients[$cid]=['id'=>$cid,'isArchived'=>in_array($raw['isArchived'],[true,1,'1'],true),'names'=>$names,'fingerprint'=>$this->hash($raw)];
    }
    private function serviceValue($value,?int$expected=null,bool$full=true):array
    {
        if(!is_array($value)&&!is_object($value))$this->fail('resolution_service_unverified');$raw=(array)$value;
        if(self::id($raw['id']??null)===null||($expected!==null&&self::id($raw['id'])!==$expected)||self::id($raw['clientId']??null)===null
            ||!in_array($raw['status']??null,[0,1,2,3,4,5,6,7,8,'0','1','2','3','4','5','6','7','8'],true))$this->fail('resolution_service_unverified');
        if(!$full)return$raw;
        if(!array_key_exists('servicePlanId',$raw)||($raw['servicePlanId']!==null&&self::id($raw['servicePlanId'])===null)
            ||!is_array($raw['attributes']??null)||array_keys($raw['attributes'])!==array_keys(array_values($raw['attributes']))||count($raw['attributes'])>512)$this->fail('resolution_service_unverified');
        $keys=[];$ids=[];foreach($raw['attributes']as$attribute){if(!is_array($attribute)&&!is_object($attribute))$this->fail('resolution_service_unverified');$attribute=(array)$attribute;$key=$attribute['key']??null;
            if(!is_string($key)||$key===''||isset($keys[$key])||!array_key_exists('value',$attribute)||($attribute['value']!==null&&!is_scalar($attribute['value'])))$this->fail('resolution_service_unverified');$keys[$key]=true;
            if(isset($attribute['customAttributeId'])){$id=self::id($attribute['customAttributeId']);if($id===null||isset($ids[$id]))$this->fail('resolution_service_unverified');$ids[$id]=true;}}
        return$raw;
    }
    private function get(string$path,array$parameters=[])
    {$this->budget();if(++$this->requests>120)$this->fail('resolution_read_limit');$result=($this->request)($path,$parameters);$this->budget();return$result;}
    private function budget():void{if(microtime(true)>=$this->deadline)$this->fail('resolution_timeout');}
    private function marker($comment):?array
    {if(!is_string($comment)||strlen($comment)>2048||preg_match('/^([1-9][0-9]*) - .+ - ([1-9][0-9]*)$/sD',$comment,$match)!==1)return null;$cid=self::id($match[1]);$sid=self::id($match[2]);return$cid!==null&&$sid!==null?['clientId'=>$cid,'serviceId'=>$sid]:null;}
    private function endpoint(array$device):string
    {$port=(int)($device['port']??8728);if($port<1||$port>65535)$this->fail('resolution_configuration_unavailable');return ApiSharedResources::endpointIdentity((object)$device);}
    private function changes(array$result):array
    {
        if(!$result['rows'])$changes=[['field'=>'account','before'=>null,'after'=>'create']];else$changes=[];
        $row=count($result['rows'])===1?$result['rows'][0]:[];$service=$result['service'];
        foreach(['ownership'=>[$row['comment']??null,$result['targetComment']],'address'=>[$row['remote-address']??($row['address']??null),$service['network']['address']??null],
            'address6'=>[$row['remote-ipv6-prefix']??null,$service['network']['address6']??null]]as$field=>[$before,$after])if($before!==$after)$changes[]=['field'=>$field,'before'=>self::display($before,512),'after'=>self::display($after,512)];
        foreach($result['derivedRepairs']as$repair)$changes[]=['field'=>'duplicate_queue','before'=>(string)count($repair['rows']),'after'=>'1'];return$changes;
    }
    private function clientName(array$names):?string{if(is_string($names['company'])&&trim($names['company'])!=='')return trim($names['company']);$name=trim(($names['firstName']??'').' '.($names['lastName']??''));return$name!==''?$name:null;}
    private function flag($value):?bool{return in_array($value,[true,'true','yes','1',1],true)?true:(in_array($value,[false,'false','no','0',0],true)?false:null);}
    private function duid($value):?string{if(!is_string($value))return null;$value=preg_replace('/[:\s-]/','',strtolower($value));$value=preg_replace('/^0x/','',$value);return preg_match('/^(?:[0-9a-f]{2}){6,}$/D',$value)?$value:null;}
    private function iaid($value):?string{if(is_string($value)&&preg_match('/^0x[0-9a-f]+$/iD',$value))$value=hexdec($value);if(is_bool($value)||!is_numeric($value)||(float)$value<0||(float)$value>4294967295||(float)$value!==(float)(int)$value)return null;return(string)(int)$value;}
    private function notFound(Throwable$error):bool{for($cause=$error;$cause!==null;$cause=$cause->getPrevious())if($cause instanceof \GuzzleHttp\Exception\RequestException&&$cause->hasResponse())return$cause->getResponse()->getStatusCode()===404;return false;}
    private function hash($value):string{$canonical=function($input)use(&$canonical){if(is_object($input))$input=(array)$input;if(!is_array($input))return$input;if(array_keys($input)!==array_keys(array_values($input)))ksort($input);foreach($input as$key=>$item)$input[$key]=$canonical($item);return$input;};return hash('sha256',json_encode($canonical($value),JSON_THROW_ON_ERROR));}
    private static function id($value):?int{if(!is_int($value)&&!is_string($value))return null;$id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);return$id===false?null:$id;}
    private static function text($value,int$max):?string{return is_string($value)&&strlen($value)<=$max&&preg_match('/[\x00-\x1f\x7f]/',$value)!==1&&preg_match('//u',$value)===1?$value:null;}
    private static function display($value,int$max):?string{if(!is_scalar($value))return null;$value=preg_replace('/[\x00-\x1f\x7f]/',' ',(string)$value);if(preg_match('//u',$value)!==1)return null;if(strlen($value)>$max){$value=substr($value,0,$max);while($value!==''&&preg_match('//u',$value)!==1)$value=substr($value,0,-1);}return$value;}
    private function reason(Throwable$error):string
    {$code=$error->getMessage();return in_array($code,['resolution_catalog_incomplete','resolution_client_archived','resolution_client_unverified',
        'resolution_configuration_changed','resolution_configuration_unavailable','resolution_derived_ownership_unverified','resolution_derived_unverified',
        'resolution_dhcp_server_ambiguous','resolution_duplicates_unreviewed','resolution_identity_unverified','resolution_local_claim','resolution_manual_confirmation',
        'resolution_mapping_unverified','resolution_marker_unverified','resolution_owner_active','resolution_queue_unverified','resolution_read_limit',
        'resolution_router_ambiguous','resolution_router_unavailable','resolution_row_invalid','resolution_select_account','resolution_service_inactive','resolution_service_unverified',
        'resolution_site_unverified','resolution_target_changed','resolution_timeout','resolution_unavailable'],true)?$code:'resolution_unavailable';}
    private function fail(string$reason):void{throw new RuntimeException($reason);}
}
