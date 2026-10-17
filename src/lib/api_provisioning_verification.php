<?php

/** Read-back evidence is independent of execution: an inconclusive read never retries a write. */
final class ApiProvisioningVerification
{
    private $reader;
    private string $dataPath;
    private string $jobsPath;
    private const FIELDS = [
        'ppp/secret' => 'name comment remote-address remote-ipv6-prefix local-address profile caller-id service disabled',
        'ip/hotspot/user' => 'name comment address profile mac-address disabled limit-uptime limit-bytes-in limit-bytes-out limit-bytes-total',
        'ip/dhcp-server/lease' => 'address mac-address comment address-lists lease-time server disabled',
        'ipv6/dhcp-server/binding' => 'address duid iaid life-time prefix-pool address-lists comment disabled',
        'ppp/profile' => 'name comment rate-limit parent-queue local-address remote-address address-list only-one change-tcp-mss use-compression use-encryption',
        'ip/hotspot/user/profile' => 'name comment rate-limit parent-queue address-pool address-list shared-users session-timeout idle-timeout keepalive-timeout',
        'queue/simple' => 'name comment target max-limit limit-at parent burst-limit burst-threshold burst-time priority queue disabled',
        'ipv6/pool' => 'name comment prefix prefix-length',
        'ppp/active' => '', 'ip/hotspot/active' => '',
    ];

    public function __construct(?callable $reader = null, string $dataPath = 'data/data.db', string $jobsPath = 'data/jobs.db')
    { $this->reader = $reader; $this->dataPath = $dataPath; $this->jobsPath = $jobsPath; }

    /** Compile only effective, readable fields. Passwords never enter the evidence object. */
    public static function expectation(array $command, array $effective, string $action, ?string $skip = null): array
    {
        $path = trim((string)($command['path'] ?? ''), '/');
        if (!array_key_exists($path, self::FIELDS)) return ['path'=>'', 'kind'=>'resource', 'skip'=>'unsupported_resource'];
        $session = in_array($path, ['ppp/active','ip/hotspot/active'], true);
        $check = ['path'=>'/'.$path, 'kind'=>$session ? 'session' : (strpos($path,'profile')!==false ? 'profile' : 'resource'),
            'action'=>$action==='remove' ? 'remove' : 'set', 'filter'=>[], 'expected'=>[]];
        if ($skip !== null) { $check['skip']=$skip; return $check; }
        if (!empty($command['name'])) $check['filter'][$path==='ip/hotspot/active' ? 'user' : 'name']=self::scalar($command['name']);
        foreach (['mac-address','duid','iaid'] as $field) {
            if (isset($command[$field]) && $command[$field]!=='') $check['filter'][$field]=self::scalar($command[$field]);
        }
        if (!$check['filter']) { $check['skip']='identity_unavailable'; return $check; }
        if ($session && $action==='remove' && !empty($effective['.id'])) {
            $check['removed_ids']=explode(',', (string)$effective['.id']);
            foreach ($check['removed_ids'] as $id) if (!preg_match('/^\*[A-Fa-f0-9]+$/D',$id)) { $check['skip']='identity_unavailable'; break; }
        }
        if ($action!=='remove') {
            foreach (explode(' ',self::FIELDS[$path]) as $field) if (array_key_exists($field,$effective)) $check['expected'][$field]=self::scalar($effective[$field]);
            if (isset($effective['.id'])) $check['expected']['.id']=self::scalar($effective['.id']);
            if (!$check['expected']) $check['skip']='unsupported_resource';
        }
        return $check;
    }

    /** The callback may only issue exact /print requests on its already-open connection. */
    public static function compare(array $checks, callable $read, ?float $deadline=null): array
    {
        $results=[]; $deadline=$deadline??microtime(true)+12;
        foreach (array_slice($checks,0,64) as $check) {
            $item=['kind'=>$check['kind']??'resource','path'=>$check['path']??'', 'state'=>'unconfirmed','reason'=>'read_failed','fields'=>[]];
            if (isset($check['skip'])) {
                $item['state']=in_array($check['skip'],['reconnect_not_required','reconnect_already_absent','shared_resource_retained','external_profile'],true) ? 'not_applicable' : 'unconfirmed';
                $item['reason']=$check['skip']; $results[]=$item; continue;
            }
            if (microtime(true)>$deadline) { $item['reason']='read_timeout'; $results[]=$item; continue; }
            try {
                $rows=$read($check['path'],$check['filter']);
                if (!is_array($rows) || count($rows)>256) throw new RuntimeException('read_failed');
                foreach ($rows as $row) if (!is_array($row)) throw new RuntimeException('read_failed');
                if (($check['action']??'')==='remove') {
                    if (isset($check['removed_ids'])) $rows=array_values(array_filter($rows,static fn(array $row):bool=>in_array($row['.id']??null,$check['removed_ids'],true)));
                    $item['state']=$rows ? 'mismatch' : 'verified'; $item['reason']=$rows ? 'resource_present' : 'absence_confirmed';
                } elseif (count($rows)!==1) {
                    $item['state']='mismatch'; $item['reason']=$rows ? 'identity_ambiguous' : 'resource_absent';
                } else {
                    $item['state']='verified'; $item['reason']='configuration_confirmed';
                    foreach ($check['expected'] as $field=>$expected) {
                        $observed=array_key_exists($field,$rows[0]) ? self::scalar($rows[0][$field]) : null;
                        $same=self::normalize($field,$expected)===self::normalize($field,$observed);
                        $item['fields'][]=['field'=>$field,'expected'=>$expected,'observed'=>$observed,'state'=>$same?'verified':'mismatch'];
                        if (!$same) { $item['state']='mismatch'; $item['reason']='configuration_differs'; }
                    }
                }
            } catch (Throwable $ignored) { /* No exception text, transport response or private row leaves this boundary. */ }
            $results[]=$item;
        }
        if (count($checks)>64) $results[]=['kind'=>'resource','path'=>'','state'=>'unconfirmed','reason'=>'check_limit','fields'=>[]];
        return $results;
    }

    public static function stage(object $device, array $checks, callable $read): array
    {
        if (count($checks)>64) $checks=array_merge(array_slice($checks,0,63),[['path'=>'','kind'=>'resource','skip'=>'check_limit']]);
        return ['device_id'=>(int)($device->id??0),'endpoint'=>self::endpoint($device),'checks'=>$checks,
            'results'=>self::compare($checks,$read)];
    }

    public static function boundedStages(array $stages): array
    {
        if (count($stages)<=32) return $stages;
        $last=$stages[count($stages)-1]; $checks=[['path'=>'','kind'=>'resource','skip'=>'check_limit']];
        return array_merge(array_slice($stages,0,31),[['device_id'=>$last['device_id'],'endpoint'=>$last['endpoint'],
            'checks'=>$checks,'results'=>self::compare($checks,static fn()=>null)]]);
    }

    /** Called only after successful execution; storing evidence cannot fail the completed operation. */
    public function record(array $service, array $job, object $device, array $stages): array
    {
        $stages=self::boundedStages($stages);
        // A replacement after cleanup supersedes the earlier absence expectation
        // for that exact resource, while retaining old-router and old-session checks.
        $seen=[];
        for ($i=count($stages)-1;$i>=0;$i--) {
            foreach ($stages[$i]['checks'] as $index=>$check) {
                $key=hash('sha256',json_encode([$stages[$i]['endpoint'],$check['path']??'', $check['filter']??[]]));
                if (($check['kind']??'')==='session' || isset($check['skip'])) continue;
                if (isset($seen[$key])) { unset($stages[$i]['checks'][$index],$stages[$i]['results'][$index]); }
                else $seen[$key]=true;
            }
            $stages[$i]['checks']=array_values($stages[$i]['checks']); $stages[$i]['results']=array_values($stages[$i]['results']);
        }
        $stages=array_values(array_filter($stages,static fn(array $stage):bool=>$stage['checks']!==[]));
        $receipt=['schema'=>1,'service_id'=>(int)$service['id'],'client_id'=>(int)($service['clientId']??0),
            'job_id'=>'Q'.(int)$job['id'],'version'=>(int)$job['version'],'device_id'=>(int)($device->id??0),
            'operation'=>(string)$job['operation'],'checked_at'=>date('c'),'stages'=>$stages];
        try { $this->save($receipt); return $this->present($receipt,true); }
        catch (Throwable $ignored) {
            $result=$this->present($receipt,true); $result['state']='unconfirmed'; $result['reason']='receipt_unavailable'; return $result;
        }
    }

    public function read(int $serviceId): array
    {
        $receipt=$this->load($serviceId);
        if ($receipt===null) {
            $exists=$serviceId>0 && file_exists(dirname($this->dataPath).DIRECTORY_SEPARATOR.'provisioning-verification-'.$serviceId.'.json');
            return ['service_id'=>$serviceId,'job_id'=>null,'version'=>null,'device_id'=>null,
                'state'=>$exists?'unconfirmed':'not_applicable','reason'=>$exists?'receipt_unavailable':'no_verification','checked_at'=>null,'current'=>false,'checks'=>[]];
        }
        $reason=$this->current($receipt);
        $result=$this->present($receipt,$reason===null);
        if ($reason!==null) { $result['state']='unconfirmed'; $result['reason']=$reason; }
        return $result;
    }

    public function listing(int $limit=20): array
    {
        $limit=max(1,min(50,$limit)); $files=[]; $directory=dirname($this->dataPath);
        try {
            foreach (new DirectoryIterator($directory) as $entry) {
                if ($entry->isLink() || !$entry->isFile() || !preg_match('/^provisioning-verification-([1-9][0-9]*)\.json$/D',$entry->getFilename(),$match)) continue;
                $files[]=['id'=>(int)$match[1],'mtime'=>$entry->getMTime()];
                if (count($files)>10000) break;
            }
            usort($files,static fn(array $a,array $b):int=>$b['mtime']<=>$a['mtime'] ?: $b['id']<=>$a['id']);
            $items=[]; foreach (array_slice($files,0,$limit) as $file) $items[]=$this->read($file['id']);
            return ['available'=>true,'items'=>$items,'has_more'=>count($files)>$limit,'checked_at'=>date('c')];
        } catch (Throwable $ignored) { return ['available'=>false,'items'=>[],'has_more'=>false,'checked_at'=>date('c')]; }
    }

    /** A read-only retry of evidence, never a job retry or a CRM/router mutation. */
    public function recheck(int $serviceId): array
    {
        $receipt=$this->load($serviceId);
        if ($receipt===null) return $this->read($serviceId);
        $locks=[]; $deadline=microtime(true)+12;
        try {
            $reason=$this->current($receipt); if ($reason!==null) return $this->unconfirmed($receipt,$reason,false);
            $endpoints=array_values(array_unique(array_column($receipt['stages'],'endpoint'))); sort($endpoints,SORT_STRING);
            foreach ($endpoints as $endpoint) $locks[]=$this->lock($endpoint);
            if (($reason=$this->current($receipt))!==null) return $this->unconfirmed($receipt,$reason,false);
            $devices=$this->devices();
            foreach ($receipt['stages'] as &$stage) {
                $device=$devices[$stage['device_id']]??null;
                if ($device===null || self::endpoint((object)$device)!==$stage['endpoint']) return $this->unconfirmed($receipt,'router_changed',false);
                $reader=$this->reader;
                if (microtime(true)>=$deadline) $stage['results']=self::compare($stage['checks'],static fn()=>null,$deadline);
                elseif ($reader!==null) $stage['results']=$reader((object)$device,$stage['checks'],$deadline);
                else { require_once __DIR__.'/mt.php'; $stage['results']=(new MT())->readVerification((object)$device,$stage['checks'],$deadline); }
            }
            unset($stage);
            if (($reason=$this->current($receipt))!==null) return $this->unconfirmed($receipt,$reason,false);
            $fresh=$this->load($serviceId);
            if ($fresh===null || $fresh['job_id']!==$receipt['job_id'] || $fresh['version']!==$receipt['version']) return $this->read($serviceId);
            $receipt['checked_at']=date('c'); $this->save($receipt); return $this->present($receipt,true);
        } catch (Throwable $ignored) { return $this->unconfirmed($receipt,'read_unavailable',true); }
        finally { foreach ($locks as $lock) { flock($lock,LOCK_UN); fclose($lock); } }
    }

    private function present(array $receipt,bool $current): array
    {
        $checks=[];
        foreach ($receipt['stages'] as $stage) foreach ($stage['results'] as $item) {
            $path=is_string($item['path']??null)?trim($item['path'],'/'):'';
            $safe=['device_id'=>(int)$stage['device_id'],'kind'=>in_array($item['kind']??null,['resource','profile','session'],true)?$item['kind']:'resource',
                'path'=>array_key_exists($path,self::FIELDS)?'/'.$path:'','state'=>in_array($item['state']??null,['verified','unconfirmed','mismatch','not_applicable'],true)?$item['state']:'unconfirmed',
                'reason'=>self::reason($item['reason']??null),'fields'=>[]];
            foreach ($item['fields']??[] as $field) {
                if (!is_array($field) || !array_key_exists($path,self::FIELDS)
                    || !in_array($field['field']??null,array_merge(['.id'],explode(' ',self::FIELDS[$path])),true)) continue;
                try { $expected=self::scalar($field['expected']??null); $observed=self::scalar($field['observed']??null); }
                catch (Throwable $ignored) { $safe['state']='unconfirmed'; $safe['reason']='receipt_unavailable'; continue; }
                $safe['fields'][]=['field'=>$field['field'],'expected'=>$expected,'observed'=>$observed,
                    'state'=>in_array($field['state']??null,['verified','mismatch'],true)?$field['state']:'unconfirmed'];
            }
            $checks[]=$safe;
        }
        $states=array_column($checks,'state');
        $state=in_array('mismatch',$states,true)?'mismatch':(in_array('unconfirmed',$states,true)?'unconfirmed':(in_array('verified',$states,true)?'verified':'not_applicable'));
        if (!$checks) $state='unconfirmed';
        return ['service_id'=>$receipt['service_id'],'job_id'=>$receipt['job_id'],'version'=>$receipt['version'],'device_id'=>$receipt['device_id'],
            'state'=>$state,'reason'=>['verified'=>'configuration_confirmed','mismatch'=>'configuration_differs','unconfirmed'=>'read_unconfirmed','not_applicable'=>'no_changes_to_verify'][$state],
            'checked_at'=>$receipt['checked_at'],'current'=>$current,'checks'=>$checks];
    }

    private function unconfirmed(array $receipt,string $reason,bool $current): array
    { $result=$this->present($receipt,$current); $result['state']='unconfirmed'; $result['reason']=$reason; return $result; }

    private static function reason($reason): string
    {
        return in_array($reason,['read_failed','read_timeout','configuration_confirmed','configuration_differs','identity_ambiguous','resource_absent','resource_present',
            'absence_confirmed','reconnect_not_required','reconnect_already_absent','shared_resource_retained','external_profile','identity_unavailable',
            'unsupported_resource','check_limit','required_resource_retained','receipt_unavailable'],true)?$reason:'read_unconfirmed';
    }

    private function current(array $receipt): ?string
    {
        $db=null;
        try {
            if (!is_file($this->jobsPath) || is_link($this->jobsPath)) return 'queue_unavailable';
            $db=new SQLite3($this->jobsPath,SQLITE3_OPEN_READONLY); $db->enableExceptions(true); $db->busyTimeout(500);
            if ($db->querySingle("SELECT count(*) FROM sqlite_master WHERE (type='view' AND name IN ('jobs','service_versions')) OR (type='trigger' AND tbl_name IN ('jobs','service_versions'))")) return 'queue_unavailable';
            $sid=(int)$receipt['service_id']; $version=$db->querySingle('SELECT version FROM service_versions WHERE service_id='.$sid);
            if ((int)$version!==$receipt['version']) return 'superseded';
            $job=$db->querySingle('SELECT service_id,version,status FROM jobs WHERE id='.(int)substr($receipt['job_id'],1),true);
            if (!$job) return 'job_unavailable';
            if ((int)$job['service_id']!==$sid || (int)$job['version']!==$receipt['version']) return 'superseded';
            return $job['status']==='succeeded' ? null : 'execution_not_complete';
        } catch (Throwable $ignored) { return 'queue_unavailable'; }
        finally { if ($db!==null) $db->close(); }
    }

    private function devices(): array
    { require_once __DIR__.'/api_site_address_policy.php'; return ApiSiteAddressPolicy::stored($this->dataPath)[1]; }

    private function lock(string $endpoint)
    {
        $identity=realpath($this->jobsPath) ?: (realpath(dirname($this->jobsPath)) ?: dirname($this->jobsPath)).DIRECTORY_SEPARATOR.basename($this->jobsPath);
        if (PHP_OS_FAMILY==='Windows') $identity=strtolower($identity);
        $directory=dirname($identity).DIRECTORY_SEPARATOR.'.uisp-queue-locks-'.hash('sha256',$identity);
        if (!is_dir($directory) || is_link($directory)) throw new RuntimeException('router_busy');
        $path=$directory.DIRECTORY_SEPARATOR.'resource-'.hash('sha256',$endpoint).'.lock';
        if (is_link($path)) throw new RuntimeException('router_busy');
        $handle=fopen($path,'c'); if (!$handle) throw new RuntimeException('router_busy');
        if (!flock($handle,LOCK_EX|LOCK_NB)) { fclose($handle); throw new RuntimeException('router_busy'); }
        return $handle;
    }

    private function load(int $sid): ?array
    {
        if ($sid<1) return null;
        $path=dirname($this->dataPath).DIRECTORY_SEPARATOR.'provisioning-verification-'.$sid.'.json';
        try {
            if (!is_file($path) || is_link($path) || filesize($path)>524288) return null;
            $receipt=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
            if (($receipt['schema']??null)!==1 || ($receipt['service_id']??null)!==$sid || !preg_match('/^Q[1-9][0-9]*$/D',$receipt['job_id']??'')
                || !is_int($receipt['version']??null) || $receipt['version']<1
                || !is_int($receipt['device_id']??null) || $receipt['device_id']<1
                || !is_int($receipt['client_id']??null) || $receipt['client_id']<0
                || !in_array($receipt['operation']??null,['update','delete','queues_set','queues_remove'],true)
                || !is_string($receipt['checked_at']??null) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D',$receipt['checked_at'])
                || !is_array($receipt['stages']??null) || count($receipt['stages'])>32) return null;
            foreach ($receipt['stages'] as $stage) {
                if (!is_int($stage['device_id']??null) || !is_string($stage['endpoint']??null) || !is_array($stage['checks']??null)
                    || !is_array($stage['results']??null) || count($stage['checks'])>64) return null;
                foreach ($stage['checks'] as $check) {
                    $path=trim($check['path']??'','/'); if (isset($check['skip'])) continue;
                    if (!array_key_exists($path,self::FIELDS) || !in_array($check['action']??null,['set','remove'],true)
                        || !is_array($check['filter']??null) || !$check['filter'] || !is_array($check['expected']??null)) return null;
                    foreach ($check['filter'] as $key=>$value) if (!in_array($key,['name','user','mac-address','duid','iaid'],true) || !is_string($value) || strlen($value)>512) return null;
                    foreach ($check['expected'] as $key=>$value) if (!in_array($key,array_merge(['.id'],explode(' ',self::FIELDS[$path])),true) || (!is_string($value) && $value!==null)) return null;
                }
                // Persisted/public results are derived again from the safe field allowlist.
                foreach ($stage['results'] as $result) {
                    if (!in_array($result['state']??null,['verified','unconfirmed','mismatch','not_applicable'],true)) return null;
                    $path=trim($result['path']??'','/');
                    if (!is_array($result['fields']??null) || count($result['fields'])>32) return null;
                    foreach ($result['fields'] as $field) {
                        if (!is_array($field) || !array_key_exists($path,self::FIELDS)
                            || !in_array($field['field']??null,array_merge(['.id'],explode(' ',self::FIELDS[$path])),true)) return null;
                        self::scalar($field['expected']??null); self::scalar($field['observed']??null);
                    }
                }
            }
            return $receipt;
        } catch (Throwable $ignored) { return null; }
    }

    private function save(array $receipt): void
    {
        $directory=dirname($this->dataPath); if (!is_dir($directory) || is_link($directory)) throw new RuntimeException('receipt_unavailable');
        $path=$directory.DIRECTORY_SEPARATOR.'provisioning-verification-'.$receipt['service_id'].'.json';
        if (is_link($path)) throw new RuntimeException('receipt_unavailable');
        $encoded=json_encode($receipt,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (strlen($encoded)>524288) throw new RuntimeException('receipt_unavailable');
        $temporary=tempnam($directory,'.verification-'); if ($temporary===false) throw new RuntimeException('receipt_unavailable');
        try {
            chmod($temporary,0600);
            if (file_put_contents($temporary,$encoded,LOCK_EX)!==strlen($encoded) || !rename($temporary,$path)) throw new RuntimeException('receipt_unavailable');
            chmod($path,0600);
        } finally { if (is_file($temporary)) unlink($temporary); }
    }

    private static function scalar($value): ?string
    {
        if ($value===null) return null;
        if (!is_scalar($value)) throw new RuntimeException('invalid_field');
        $value=is_bool($value)?($value?'true':'false'):(string)$value;
        if (strlen($value)>512 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value)) throw new RuntimeException('invalid_field');
        return $value;
    }

    private static function endpoint(object $device): string
    {
        $host=strtolower(rtrim(trim((string)($device->ip??'')),'.'));
        if (filter_var(trim($host,'[]'),FILTER_VALIDATE_IP)) $host=bin2hex(inet_pton(trim($host,'[]')));
        return 'router:'.$host.':'.(int)($device->port??8728);
    }

    private static function normalize(string $field,?string $value): string
    {
        if ($value===null || $value==='') return '';
        if (in_array($field,['disabled','only-one','change-tcp-mss','use-compression','use-encryption'],true)) {
            if (in_array($value,['1','true','yes'],true)) return 'true'; if (in_array($value,['0','false','no'],true)) return 'false';
        }
        if (in_array($field,['mac-address','duid'],true)) return strtolower($value);
        if (in_array($field,['address','local-address','remote-address','remote-ipv6-prefix','prefix','target'],true)) {
            $items=explode(',',$value);
            foreach ($items as &$item) { $parts=explode('/',trim($item)); $packed=@inet_pton($parts[0]);
                if ($packed!==false) $item=bin2hex($packed).(isset($parts[1]) && !in_array($parts[1],strlen($packed)===4?['32']:['128'],true)?'/'.(int)$parts[1]:''); }
            unset($item); sort($items,SORT_STRING); return implode(',',$items);
        }
        if (in_array($field,['rate-limit','max-limit','limit-at','burst-limit','burst-threshold'],true)) {
            $tokens=preg_split('/\s+/',trim($value)); foreach ($tokens as $index=>&$token) {
                $parts=explode('/',$token); foreach ($parts as &$part) if (preg_match('/^([0-9]+(?:\.[0-9]+)?)([kKmMgG]?)$/D',$part,$match)) {
                    $factor=$field!=='rate-limit'||in_array($index,[0,1,2,5],true)?[''=>1,'k'=>1000,'m'=>1000000,'g'=>1000000000][strtolower($match[2])]:1;
                    $part=sprintf('%.17g',(float)$match[1]*$factor); }
                unset($part); $token=implode('/',$parts); }
            unset($token); return implode(' ',$tokens);
        }
        if (in_array($field,['lease-time','life-time','session-timeout','idle-timeout','keepalive-timeout','limit-uptime'],true)) {
            if (preg_match('/^(\d+):(\d+):(\d+)$/D',$value,$m)) return (string)((int)$m[1]*3600+(int)$m[2]*60+(int)$m[3]);
            if (preg_match('/^(?:(\d+)w)?(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/D',$value,$m))
                return (string)((int)($m[1]??0)*604800+(int)($m[2]??0)*86400+(int)($m[3]??0)*3600+(int)($m[4]??0)*60+(int)($m[5]??0));
        }
        if ($field==='burst-time') return implode('/',array_map(static fn(string $part):string=>self::normalize('limit-uptime',$part),explode('/',$value)));
        return $value;
    }
}
