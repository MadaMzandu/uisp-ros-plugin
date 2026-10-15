<?php
require_once __DIR__ . '/api_site_address_policy.php';

/** Explicit service diagnostics. Reads never initialize a queue, refresh a cache or provision resources. */
final class ApiServiceDiagnostics
{
    private $request;
    private $routerReader;
    private $clock;
    private string $dataPath;
    private string $cachePath;
    private string $jobsPath;
    private float $deadline = 0;
    private const FIELDS = ['username','site','address','address6','profile','plan_id','status','ownership'];

    public function __construct(?callable $request = null, ?callable $routerReader = null,
        string $dataPath = 'data/data.db', string $cachePath = 'data/cache.db',
        string $jobsPath = 'data/jobs.db', ?callable $clock = null)
    {
        $this->request = $request; $this->routerReader = $routerReader; $this->dataPath = $dataPath;
        $this->cachePath = $cachePath; $this->jobsPath = $jobsPath; $this->clock = $clock;
    }

    public function search(string $query): array
    {
        $query = trim($query);
        if ($query === '' || strlen($query) > 160 || !self::text($query,160)) throw new InvalidArgumentException('invalid_diagnostic_search');
        $result = ['available'=>false,'query'=>$query,'items'=>[],'has_more'=>false,'checked_at'=>$this->stamp(),'reason'=>'cache_unavailable'];
        $db = null;
        try {
            $db = $this->open($this->cachePath); $this->requireColumns($db,'services',['id','clientId']);
            $sc = $this->columns($db,'services'); $cc = $this->columns($db,'clients'); $nc = $this->columns($db,'network');
            $clientJoin = isset($cc['id']); $networkJoin = isset($nc['id']);
            $fields = ['s.id AS service_id','s.clientId AS client_id'];
            foreach (['username','device','status'] as $field) $fields[] = (isset($sc[$field]) ? 's.'.$field : 'NULL').' AS '.$field;
            foreach (['company','firstName','lastName'] as $field) $fields[] = ($clientJoin && isset($cc[$field]) ? 'c.'.$field : 'NULL').' AS '.$field;
            $fields[] = ($networkJoin && isset($nc['address']) ? 'n.address' : 'NULL').' AS address';
            $where = ['CAST(s.id AS TEXT)=?','CAST(s.clientId AS TEXT)=?']; $parameters = [$query,$query];
            $like = '%'.strtr($query,['\\'=>'\\\\','%'=>'\\%','_'=>'\\_']).'%';
            foreach (['username','mac'] as $field) if (isset($sc[$field])) { $where[] = 's.'.$field." LIKE ? ESCAPE '\\'"; $parameters[] = $like; }
            if ($clientJoin) foreach (['company','firstName','lastName'] as $field) if (isset($cc[$field])) { $where[] = 'c.'.$field." LIKE ? ESCAPE '\\'"; $parameters[] = $like; }
            if ($networkJoin) foreach (['address','address6'] as $field) if (isset($nc[$field])) { $where[] = 'n.'.$field." LIKE ? ESCAPE '\\'"; $parameters[] = $like; }
            $rows = $this->query($db,'SELECT '.implode(',',$fields).' FROM services s'.($clientJoin ? ' LEFT JOIN clients c ON c.id=s.clientId' : '')
                .($networkJoin ? ' LEFT JOIN network n ON n.id=s.id' : '').' WHERE ('.implode(' OR ',$where).') ORDER BY s.id LIMIT 31',$parameters);
            [, $devices] = ApiSiteAddressPolicy::stored($this->dataPath);
            foreach (array_slice($rows,0,30) as $row) {
                $sid = self::id($row['service_id']); if ($sid === null) continue;
                $did = self::id($row['device']); $result['items'][] = ['service_id'=>$sid,'client_id'=>self::id($row['client_id']),
                    'client_name'=>self::clientName($row),'username'=>self::safe($row['username']), 'address'=>self::address($row['address']),
                    'device_id'=>$did,'site'=>self::safe($did === null ? null : ($devices[$did]['name'] ?? null))];
            }
            $result['has_more'] = count($rows)>30; $result['available'] = true; $result['reason'] = null;
        } catch (Throwable $ignored) {}
        finally { if ($db !== null) $db->close(); }
        // A precise service ID can always be inspected live even when absent from the local cache.
        if (!$result['items'] && self::id($query) !== null) $result['items'][] = ['service_id'=>(int)$query,
            'client_id'=>null,'client_name'=>null,'username'=>null,'address'=>null,'device_id'=>null,'site'=>null];
        return $result;
    }

    public function inspect(int $serviceId): array
    {
        if ($serviceId<1) throw new InvalidArgumentException('invalid_service_id');
        $this->deadline = microtime(true)+12;
        $result = ['service_id'=>$serviceId,'client_id'=>null,'client_name'=>null,'checked_at'=>$this->stamp(),
            'uisp'=>$this->emptySource('uisp_unavailable'),'expected'=>$this->emptySource('local_state_unavailable'),
            'router'=>$this->emptySource('router_not_inspected'),'comparisons'=>[],
            'jobs'=>['available'=>false,'items'=>[],'has_more'=>false],
            'history'=>['available'=>false,'recovery_count'=>null,'retained_count'=>null],
            'verification'=>null,'issues'=>[]];
        [$config,$devices] = ApiSiteAddressPolicy::stored($this->dataPath);
        $local = $this->local($serviceId); $queue = $this->queue($serviceId);
        $result['jobs'] = $queue['jobs']; $result['history'] = $queue['history'];
        $snapshot = $queue['snapshot'] ?? $local;
        if ($snapshot !== null) {
            $result['client_id'] = self::id($snapshot['clientId'] ?? null);
            $result['client_name'] = self::clientName($snapshot);
            $result['expected'] = ['available'=>true,'source'=>$queue['source'] ?? 'cache','checked_at'=>$this->stamp(),
                'stored_at'=>self::date($snapshot['last'] ?? null),
                'reason'=>null,'values'=>$this->snapshotValues($snapshot,$devices)];
        }
        $fresh = null; $catalog = null;
        try {
            $fresh = $this->get('clients/services/'.$serviceId);
            if (!is_array($fresh) || self::id($fresh['id'] ?? null)!==$serviceId || self::id($fresh['clientId'] ?? null)===null)
                throw new RuntimeException('uisp_identity_unknown');
            $catalog = $this->get('custom-attributes');
            if (!is_array($catalog) || !self::isList($catalog)) throw new RuntimeException('uisp_attributes_unknown');
            $values = array_fill_keys(self::FIELDS,null);
            foreach (['username'=>'pppoe_user_attr','site'=>'device_name_attr','address'=>'ip_addr_attr','address6'=>'ip_addr6_attr'] as $field=>$key)
                $values[$field] = $this->attribute($fresh,$catalog,$config[$key] ?? null);
            $values['address'] = self::address($values['address']); $values['address6'] = self::address($values['address6']);
            $values['status'] = self::status($fresh['status'] ?? null); $values['profile'] = self::safe($fresh['servicePlanName'] ?? null);
            $values['plan_id'] = self::id($fresh['servicePlanId'] ?? null);
            $values['ownership'] = $fresh['clientId'].':'.$serviceId;
            if ($result['client_id']!==null && $result['client_id']!==(int)$fresh['clientId']) $result['client_name'] = null;
            $result['client_id'] = (int)$fresh['clientId'];
            $result['uisp'] = ['available'=>true,'checked_at'=>$this->stamp(),'reason'=>null,'values'=>$values];
            try {
                $client = $this->get('clients/'.$result['client_id']);
                if (self::id($client['id'] ?? null)!==$result['client_id']) throw new RuntimeException('uisp_client_unavailable');
                $result['client_name'] = self::clientName($client);
            } catch (Throwable $ignored) { $result['issues'][] = 'uisp_client_unavailable'; }
        } catch (Throwable $ignored) { $result['issues'][] = 'uisp_unavailable'; }
        // Observe the applied/queued identity when it exists. Otherwise require fresh UISP identity and a unique Site.
        try {
            if (!$devices || !$config) throw new RuntimeException('configuration_unavailable');
            $target = $snapshot;
            if ($target === null && $fresh !== null && $catalog !== null && $result['uisp']['available']) {
                $uv = $result['uisp']['values']; $matches = [];
                foreach ($devices as $did=>$device) if (is_string($device['name'] ?? null) && is_string($uv['site'])
                    && strcasecmp(trim($device['name']),trim($uv['site']))===0) $matches[] = $did;
                if (count($matches)!==1) throw new RuntimeException('site_unverified');
                $target = ['id'=>$serviceId,'clientId'=>$result['client_id'],'device'=>$matches[0], 'username'=>$uv['username'],
                    'mac'=>$this->attribute($fresh,$catalog,$config['mac_addr_attr'] ?? null),
                    'duid'=>$this->attribute($fresh,$catalog,$config['dhcp6_duid_attr'] ?? null),
                    'iaid'=>$this->attribute($fresh,$catalog,$config['dhcp6_iaid_attr'] ?? null),
                    'hotspot'=>$this->attribute($fresh,$catalog,$config['hs_attr'] ?? null)];
            }
            if ($target === null) throw new RuntimeException('identity_unknown');
            $did = self::id($target['device'] ?? null);
            if ($did===null || !isset($devices[$did])) throw new RuntimeException('site_unverified');
            $device = $devices[$did];
            if (isset($target['_device']) && (!is_array($target['_device']) || $this->transport($target['_device'])!==$this->transport($device)))
                throw new RuntimeException('router_authority_changed');
            [$latestConfig,$latestDevices] = ApiSiteAddressPolicy::stored($this->dataPath);
            if ($latestConfig!==$config || !isset($latestDevices[$did]) || $this->transport($latestDevices[$did])!==$this->transport($device))
                throw new RuntimeException('router_authority_changed');
            $result['router'] = $this->observe($target,$device);
        } catch (Throwable $error) {
            $reason = in_array($error->getMessage(),['configuration_unavailable','site_unverified','identity_unknown','router_authority_changed','router_ambiguous','router_unavailable','router_observation_limit'],true)
                ? $error->getMessage() : 'router_unavailable';
            $result['router']['reason'] = $reason; $result['issues'][] = $reason;
        }
        if (is_file(__DIR__.'/api_provisioning_verification.php')) {
            try {
                require_once __DIR__.'/api_provisioning_verification.php';
                $result['verification'] = (new ApiProvisioningVerification(null,$this->dataPath,$this->jobsPath))->read($serviceId);
            } catch (Throwable $ignored) { $result['issues'][] = 'verification_unavailable'; }
        }
        foreach (self::FIELDS as $field) {
            $u = $result['uisp']['values'][$field]; $e = $result['expected']['values'][$field]; $r = $result['router']['values'][$field];
            $result['comparisons'][] = ['field'=>$field,'uisp'=>$u,'expected'=>$e,'router'=>$r,
                'matches_uisp'=>$field==='profile' || $u===null || $e===null ? null : $u===$e,
                'matches_router'=>$field==='status' || $e===null || $r===null ? null : $e===$r];
        }
        $result['issues'] = array_values(array_unique($result['issues'])); return $result;
    }

    private function local(int $sid): ?array
    {
        $db = null;
        try {
            $db = $this->open($this->cachePath); $this->requireColumns($db,'services',['id']);
            $columns = $this->columns($db,'services'); $fields = [];
            foreach (['id','clientId','device','username','mac','hotspot','duid','iaid','status','planId','last'] as $field)
                $fields[] = isset($columns[$field]) ? $field : 'NULL AS '.$field;
            $rows = $this->query($db,'SELECT '.implode(',',$fields).' FROM services WHERE id=? LIMIT 2',[$sid]);
            if (count($rows)!==1) return null; $row = $rows[0];
            if (isset($this->columns($db,'network')['id'])) {
                $nc = $this->columns($db,'network'); $nf = ['id'];
                foreach (['address','address6'] as $field) $nf[] = isset($nc[$field]) ? $field : 'NULL AS '.$field;
                $network = $this->query($db,'SELECT '.implode(',',$nf).' FROM network WHERE id=? LIMIT 2',[$sid]);
                if (count($network)===1) $row['network'] = $network[0];
            }
            $cid = self::id($row['clientId'] ?? null);
            if ($cid!==null && isset($this->columns($db,'clients')['id'])) {
                $cc = $this->columns($db,'clients'); $cf = [];
                foreach (['company','firstName','lastName'] as $field) $cf[] = isset($cc[$field]) ? $field : 'NULL AS '.$field;
                $client = $this->query($db,'SELECT '.implode(',',$cf).' FROM clients WHERE id=? LIMIT 2',[$cid]);
                if (count($client)===1) $row += $client[0];
            }
            return $row;
        } catch (Throwable $ignored) { return null; }
        finally { if ($db!==null) $db->close(); }
    }

    private function queue(int $sid): array
    {
        $result = ['jobs'=>['available'=>false,'items'=>[],'has_more'=>false],
            'history'=>['available'=>false,'recovery_count'=>null,'retained_count'=>null],'snapshot'=>null,'source'=>null]; $db = null;
        if (!file_exists($this->jobsPath) && !is_link($this->jobsPath)) {
            $result['jobs']['available'] = true; $result['history'] = ['available'=>true,'recovery_count'=>0,'retained_count'=>0]; return $result;
        }
        try {
            $db = $this->open($this->jobsPath); $this->requireColumns($db,'jobs',['id','service_id','status','payload']);
            $jc = $this->columns($db,'jobs'); $fields = ['id','status','payload'];
            foreach (['operation','attempts','created','updated','next_attempt','error'] as $field) $fields[] = isset($jc[$field]) ? $field : 'NULL AS '.$field;
            $rows = $this->query($db,'SELECT '.implode(',',$fields).' FROM jobs WHERE service_id=? ORDER BY id DESC LIMIT 31',[$sid]);
            foreach (array_slice($rows,0,30) as $row) {
                $snapshot = $this->payload($row['payload'],$sid);
                $state = in_array($row['status'],['pending','running','succeeded','cancelled','superseded','cleaned'],true) ? $row['status'] : 'unknown';
                if ($result['snapshot']===null && in_array($state,['pending','running'],true) && $snapshot!==null) {
                    $result['snapshot'] = $snapshot; $result['source'] = 'queued';
                }
                $result['jobs']['items'][] = ['job_id'=>'Q'.(int)$row['id'],'status'=>$state,
                    'operation'=>in_array($row['operation'],['update','delete','queues_set'],true) ? $row['operation'] : 'unknown',
                    'attempts'=>max(0,(int)$row['attempts']),'created_at'=>self::date($row['created']),
                    'updated_at'=>self::date($row['updated']),'next_attempt'=>is_numeric($row['next_attempt']) && (int)$row['next_attempt']>0 ? gmdate('Y-m-d\TH:i:s\Z',(int)$row['next_attempt']) : null,
                    'error_code'=>$this->errorCode($row['error'])];
            }
            $result['jobs']['available'] = true; $result['jobs']['has_more'] = count($rows)>30;
            if ($result['snapshot']===null && isset($this->columns($db,'service_versions')['applied_payload'])) {
                $applied = $this->query($db,'SELECT applied_payload FROM service_versions WHERE service_id=? LIMIT 2',[$sid]);
                if (count($applied)===1) {
                    $result['snapshot'] = $this->payload($applied[0]['applied_payload'],$sid);
                    if ($result['snapshot']!==null) $result['source'] = 'applied';
                }
            }
            $historyAvailable = true;
            foreach (['cancellation_cleanups'=>'recovery_count','retained_resources'=>'retained_count'] as $table=>$field) {
                if (isset($this->columns($db,$table)['service_id'])) {
                    $count = $this->query($db,'SELECT count(*) AS total FROM '.$table.' WHERE service_id=?',[$sid]);
                    $result['history'][$field] = (int)$count[0]['total'];
                } else $historyAvailable = false;
            }
            $result['history']['available'] = $historyAvailable;
        } catch (Throwable $ignored) { $result['jobs']['available'] = false; }
        finally { if ($db!==null) $db->close(); }
        return $result;
    }

    private function snapshotValues(array $snapshot, array $devices): array
    {
        $did = self::id($snapshot['device'] ?? null); $sid = self::id($snapshot['id'] ?? null); $cid = self::id($snapshot['clientId'] ?? null);
        $values = ['username'=>self::safe($snapshot['username'] ?? null),'site'=>self::safe($did===null ? null : ($devices[$did]['name'] ?? null)),
            'address'=>self::address($snapshot['address'] ?? ($snapshot['network']['address'] ?? null)),
            'address6'=>self::address($snapshot['address6'] ?? ($snapshot['network']['address6'] ?? null)),
            'profile'=>null,'plan_id'=>self::id($snapshot['planId'] ?? null),'status'=>self::status($snapshot['status'] ?? null),
            'ownership'=>$sid!==null && $cid!==null ? $cid.':'.$sid : null];
        foreach ($snapshot['_commands'] ?? [] as $command) {
            if (!is_array($command) || !in_array(trim((string)($command['path'] ?? ''),'/'),['ppp/secret','ip/hotspot/user'],true)
                || ($command['action'] ?? 'set')==='remove' || ($command['name'] ?? null)!==$values['username']) continue;
            $values['profile'] = self::safe($command['profile'] ?? null);
            $ip = self::address($command['remote-address'] ?? ($command['address'] ?? null)); if ($ip!==null) $values['address'] = $ip;
        }
        return $values;
    }

    private function observe(array $snapshot, array $device): array
    {
        $this->transport($device); $username = self::safe($snapshot['username'] ?? null); $mac = $snapshot['mac'] ?? null;
        $v6Filter = $this->v6Selector($snapshot);
        if (is_string($mac) && filter_var($mac,FILTER_VALIDATE_MAC)) { $path = 'ip/dhcp-server/lease'; $filter = ['mac-address'=>strtoupper($mac)]; }
        elseif ($username!==null && $username!=='') { $path = empty($snapshot['hotspot']) ? 'ppp/secret' : 'ip/hotspot/user'; $filter = ['name'=>$username]; }
        elseif ($v6Filter!==null) { $path = 'ipv6/dhcp-server/binding'; $filter = $v6Filter; }
        else throw new RuntimeException('identity_unknown');
        $fields = ['.id','name','mac-address','address','remote-address','profile','disabled','comment','ipv6-prefix','rate-limit','duid','iaid'];
        $rows = $this->observedRows($device,$path,$filter,$fields);
        $values = array_fill_keys(self::FIELDS,null);
        if ($rows) {
            $row = $rows[0];
            $values['username'] = self::safe($row['name'] ?? null); $values['site'] = self::safe($device['name'] ?? null);
            if ($path==='ipv6/dhcp-server/binding') $values['address6'] = self::address($row['address'] ?? null);
            else {
                $values['address'] = self::address($row['remote-address'] ?? ($row['address'] ?? null));
                $values['address6'] = self::address($row['ipv6-prefix'] ?? null);
            }
            $values['profile'] = self::safe($row['profile'] ?? null);
            $disabled = filter_var($row['disabled'] ?? null,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
            $values['status'] = array_key_exists('disabled',$row) && $disabled!==null ? ($disabled ? 'disabled' : 'enabled') : null;
            if (is_string($row['comment'] ?? null) && preg_match('/^([1-9][0-9]*) - .+ - ([1-9][0-9]*)$/D',$row['comment'],$match))
                $values['ownership'] = $match[1].':'.$match[2];
        }
        $v6Present = null; $reason = $rows ? null : 'account_absent';
        if ($path!=='ipv6/dhcp-server/binding' && $v6Filter!==null && $this->expectsV6($snapshot)) {
            try {
                $v6Rows = $this->observedRows($device,'ipv6/dhcp-server/binding',$v6Filter,$fields);
                $v6Present = (bool)$v6Rows;
                if ($v6Rows) $values['address6'] = self::address($v6Rows[0]['address'] ?? null);
            } catch (Throwable $ignored) { $values['address6'] = null; $reason = 'ipv6_unavailable'; }
        }
        return ['available'=>true,'checked_at'=>$this->stamp(),'reason'=>$reason,
            'device_id'=>(int)$device['id'],'resource_type'=>$path,'present'=>(bool)$rows,'ipv6_present'=>$v6Present,'values'=>$values];
    }

    private function observedRows(array $device,string $path,array $filter,array $fields): array
    {
        $rows = $this->routerRead($device,$path,$filter,$fields);
        if (!self::isList($rows) || count($rows)>1) throw new RuntimeException('router_ambiguous');
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['.id'] ?? null) || preg_match('/^\*[a-fA-F0-9]+$/D',$row['.id'])!==1) throw new RuntimeException('router_unavailable');
            foreach ($filter as $key=>$value) {
                if (!is_scalar($row[$key] ?? null)) throw new RuntimeException('router_unavailable');
                $observed = (string)$row[$key];
                $matches = $key==='duid' ? $this->duid($observed)===$value
                    : ($key==='iaid' ? $this->iaid($observed)===$this->iaid($value)
                    : ($key==='mac-address' ? strcasecmp($observed,$value)===0 : $observed===$value));
                if (!$matches) throw new RuntimeException('router_unavailable');
            }
        }
        return $rows;
    }
    private function v6Selector(array $snapshot): ?array
    {
        $duid = $this->duid($snapshot['duid'] ?? ($snapshot['mac'] ?? null)); $iaid = $this->iaid($snapshot['iaid'] ?? 1);
        return $duid!==null && $iaid!==null ? ['duid'=>$duid,'iaid'=>(string)$iaid] : null;
    }
    private function duid($value): ?string
    {
        if (!is_string($value) || strlen($value)>256) return null;
        $value = preg_replace('/^0x/','',preg_replace('/[:\s-]/','',strtolower($value)));
        return preg_match('/^(?:[0-9a-f]{2}){6,}$/D',$value)===1 ? '0x'.$value : null;
    }
    private function iaid($value): ?int
    {
        if (is_string($value) && preg_match('/^0x[0-9a-f]+$/iD',$value)) $value = hexdec($value);
        if ((!is_int($value) && !is_string($value)) || !is_numeric($value) || (float)$value<0 || (float)$value>4294967295 || (float)$value!==floor((float)$value)) return null;
        return (int)$value;
    }
    private function expectsV6(array $snapshot): bool
    {
        if (self::address($snapshot['address6'] ?? ($snapshot['network']['address6'] ?? null))!==null) return true;
        foreach ($snapshot['_commands'] ?? [] as $command) if (is_array($command) && trim((string)($command['path'] ?? ''),'/')==='ipv6/dhcp-server/binding') return true;
        return false;
    }

    private function routerRead(array $device, string $path, array $filter, array $fields): array
    {
        if (microtime(true)>=$this->deadline) throw new RuntimeException('router_observation_limit');
        if ($this->routerReader!==null) {
            $rows = ($this->routerReader)($device,$path,$filter,$fields);
            if (microtime(true)>=$this->deadline) throw new RuntimeException('router_observation_limit'); return $rows;
        }
        require_once __DIR__.'/routeros_api.class.php'; $api = new RouterosAPI(); $transport = $this->transport($device);
        $api->timeout = 2; $api->attempts = 1;
        $api->port = $transport['port']; $api->ssl = $transport['ssl']; $api->allow_self_signed = $transport['allow_self_signed']; $api->debug = false;
        try {
            return $api->withReadDeadline($this->deadline,function() use ($api,$transport,$device,$path,$filter,$fields): array {
                if (!$api->connect($transport['host'],$device['user'],$device['password'])) throw new RuntimeException('router_unavailable');
                $api->write('/'.$path.'/print',false); $api->write('=.proplist='.implode(',',$fields),false);
                foreach ($filter as $key=>$value) $api->write('?'.$key.'='.$value,false);
                $api->finishSentence(); return $this->decodePrint($api->read(false),$fields);
            });
        } finally { $api->disconnect(); }
    }

    /** RouterOS can include additional properties despite .proplist. Drop them before building any public result. */
    private function decodePrint($words,array $fields): array
    {
        if (!is_array($words) || !self::isList($words) || !$words || count($words)>2048
            || end($words)!=='!done' || count(array_keys($words,'!done',true))!==1) throw new RuntimeException('router_unavailable');
        if ($words===['!done'] || $words===['!empty','!done']) return [];
        $rows = []; $row = null;
        foreach ($words as $word) {
            if (!is_string($word) || strlen($word)>2048) throw new RuntimeException('router_unavailable');
            if ($word==='!re' || $word==='!done') {
                if ($row!==null) $rows[] = $row;
                $row = $word==='!re' ? [] : null; continue;
            }
            if ($row===null || !preg_match('/^=([^=]+)=(.*)$/sD',$word,$match)) throw new RuntimeException('router_unavailable');
            if (!in_array($match[1],$fields,true)) continue;
            if (array_key_exists($match[1],$row)) throw new RuntimeException('router_unavailable');
            $row[$match[1]] = $match[2];
        }
        return $rows;
    }

    private function transport(array $device): array
    {
        $host = $device['ip'] ?? null; $user = $device['user'] ?? null;
        $port = filter_var($device['port'] ?? 8728,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);
        $ssl = filter_var($device['ssl'] ?? ($port===8729),FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
        $self = filter_var($device['allowSelfSigned'] ?? false,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
        if (!self::text($host,512) || trim($host)==='' || !self::text($user,256) || $user==='' || !is_string($device['password'] ?? null) || $port===false || $ssl===null || $self===null
            || !in_array($device['type'] ?? 'mikrotik',[null,'','mikrotik'],true)) throw new RuntimeException('router_authority_changed');
        return ['host'=>strtolower($host),'port'=>$port,'ssl'=>$ssl,'allow_self_signed'=>$self,
            'user'=>hash('sha256',$user),'password'=>hash('sha256',$device['password'])];
    }

    private function get(string $path): array
    {
        if (microtime(true)>=$this->deadline) throw new RuntimeException('uisp_unavailable');
        if ($this->request!==null) $value = ($this->request)($path,'get',[]);
        else {
            require_once __DIR__.'/api_uisp_client.php'; $api = ApiUispClient::crm();
            if (class_exists(\GuzzleHttp\Client::class)) {
                $property = new ReflectionProperty($api,'client'); if (PHP_VERSION_ID<80100) $property->setAccessible(true);
                $client = $property->getValue($api); if (!$client instanceof \GuzzleHttp\Client) throw new RuntimeException('uisp_unavailable');
                $remaining = max(0.1,$this->deadline-microtime(true)); $options = $client->getConfig();
                $options['timeout'] = min(2,$remaining); $options['connect_timeout'] = min(1,$remaining); $options['read_timeout'] = min(2,$remaining);
                $property->setValue($api,new \GuzzleHttp\Client($options));
            }
            $value = $api->get($path,[]);
        }
        $value = json_decode(json_encode($value,JSON_THROW_ON_ERROR),true,32,JSON_THROW_ON_ERROR);
        if (!is_array($value) || microtime(true)>=$this->deadline) throw new RuntimeException('uisp_unavailable'); return $value;
    }

    private function attribute(array $service, array $catalog, $key): ?string
    {
        if (!self::text($key,128) || trim($key)==='' || !is_array($service['attributes'] ?? null) || !self::isList($service['attributes'])) return null;
        $matches = [];
        foreach ($catalog as $definition) if (is_array($definition) && ($definition['key'] ?? null)===$key
            && ($definition['attributeType'] ?? null)==='service' && self::id($definition['id'] ?? null)!==null) $matches[] = $definition;
        if (count($matches)!==1) return null; $found = false; $value = null;
        foreach ($service['attributes'] as $attribute) {
            if (!is_array($attribute)) return null;
            $sameKey = ($attribute['key'] ?? null)===$key;
            $sameId = self::id($attribute['customAttributeId'] ?? null)===(int)$matches[0]['id'];
            if (!$sameKey && !$sameId) continue;
            if ($found || !$sameKey || (isset($attribute['customAttributeId']) && !$sameId)) return null;
            $found = true; $value = self::safe($attribute['value'] ?? null);
        }
        return $value;
    }

    private function open(string $path): SQLite3
    {
        if (is_link($path) || !is_file($path)) throw new RuntimeException('state_unavailable');
        $db = new SQLite3($path,SQLITE3_OPEN_READONLY); $db->enableExceptions(true); $db->busyTimeout(300); $db->exec('PRAGMA query_only=ON');
        if ($db->querySingle("SELECT count(*) FROM sqlite_master WHERE type IN ('view','trigger')")) { $db->close(); throw new RuntimeException('state_unavailable'); }
        return $db;
    }
    private function columns(SQLite3 $db, string $table): array
    {
        if (!in_array($table,['services','clients','network','jobs','service_versions','cancellation_cleanups','retained_resources'],true)) throw new LogicException('invalid_table');
        $rows = $this->query($db,'PRAGMA table_info('.$table.')'); return array_fill_keys(array_column($rows,'name'),true);
    }
    private function requireColumns(SQLite3 $db, string $table, array $fields): void
    { if (array_diff($fields,array_keys($this->columns($db,$table)))) throw new RuntimeException('state_unavailable'); }
    private function query(SQLite3 $db, string $sql, array $parameters = []): array
    {
        $statement = $db->prepare($sql);
        foreach ($parameters as $index=>$value) $statement->bindValue($index+1,$value,is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
        try {
            $result = $statement->execute(); $rows = [];
            try { while ($row=$result->fetchArray(SQLITE3_ASSOC)) $rows[] = $row; }
            finally { $result->finalize(); } return $rows;
        } finally { $statement->close(); }
    }
    private function payload($value, int $sid): ?array
    {
        if (!is_string($value) || strlen($value)>2097152) return null;
        try { $snapshot = json_decode($value,true,32,JSON_THROW_ON_ERROR); }
        catch (Throwable $ignored) { return null; }
        return is_array($snapshot) && self::id($snapshot['id'] ?? null)===$sid ? $snapshot : null;
    }
    private function errorCode($value): ?string
    {
        if ($value===null || $value==='') return null; if (!is_string($value)) return 'operation_failed';
        foreach (['invalid_network_address','router_ownership_conflict','router_ownership_record_changed','site_address_identity_changed','site_address_device_changed',
            'site_address_resources_changed','site_address_prepared_guard_changed','queue_busy','device_busy','provisioning_cancelled'] as $code)
            if (preg_match('/(?:^|[^a-z_])'.preg_quote($code,'/').'(?:$|[^a-z_])/D',$value)) return $code;
        return 'operation_failed';
    }
    private function emptySource(string $reason): array { return ['available'=>false,'checked_at'=>null,'reason'=>$reason,'values'=>array_fill_keys(self::FIELDS,null)]; }
    private function stamp(): string { return gmdate('Y-m-d\TH:i:s\Z',$this->clock===null ? time() : (int)($this->clock)()); }
    private static function safe($value): ?string { return self::text($value,512) ? $value : null; }
    private static function text($value, int $limit): bool { return is_string($value) && strlen($value)<=$limit && preg_match('/[\x00-\x1f\x7f]/',$value)!==1 && preg_match('//u',$value)===1; }
    private static function id($value): ?int { if (!is_int($value) && !is_string($value)) return null; $id = filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]); return $id===false ? null : $id; }
    private static function status($value): ?int { if (!is_int($value) && !is_string($value)) return null; $status = filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>8]]); return $status===false ? null : $status; }
    private static function address($value): ?string {
        if (!is_string($value) || strlen($value)>128) return null; $parts = explode('/',$value);
        if (count($parts)>2 || !filter_var($parts[0],FILTER_VALIDATE_IP)) return null;
        if (isset($parts[1]) && (filter_var($parts[1],FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>strpos($parts[0],':')===false ? 32 : 128]])===false)) return null;
        return $value;
    }
    private static function clientName(array $row): ?string {
        if (self::safe($row['company'] ?? null)!==null && trim($row['company'])!=='') return trim($row['company']);
        $name = trim((self::safe($row['firstName'] ?? null) ?? '').' '.(self::safe($row['lastName'] ?? null) ?? '')); return $name!=='' ? $name : self::safe($row['clientName'] ?? null);
    }
    private static function date($value): ?string { if (!is_string($value) || strlen($value)>64) return null; $time = strtotime($value); return $time===false ? null : gmdate('Y-m-d\TH:i:s\Z',$time); }
    private static function isList(array $value): bool { return $value===[] || array_keys($value)===range(0,count($value)-1); }
}
