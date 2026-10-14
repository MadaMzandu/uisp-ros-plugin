<?php
const IP_BASE2=2;
const IP_BASE16=16;
const IP_BASE10=10;
const IP_PWR2=2;
const IP_PAD0='0';

/** Address diagnostics identify ownership without exposing arbitrary imported values. */
final class InvalidNetworkAddress extends InvalidArgumentException
{
    private array $details;

    public function __construct(int $requestedServiceId, int $blockingServiceId, string $source, bool $ipv6)
    {
        $source = in_array($source, ['input', 'reserved', 'applied'], true) ? $source : 'reserved';
        $this->details = ['requestedServiceId' => max(0, $requestedServiceId),
            'blockingServiceId' => max(0, $blockingServiceId), 'addressSource' => $source,
            'addressFamily' => $ipv6 ? 'IPv6' : 'IPv4'];
        parent::__construct('invalid_network_address: requested_service=' . $this->details['requestedServiceId']
            . ' blocking_service=' . $this->details['blockingServiceId'] . ' source=' . $source
            . ' family=' . $this->details['addressFamily']);
    }

    public function context(): array { return $this->details; }
}

/** Valid collisions report their real owner without exposing imported field values. */
final class NetworkAddressConflict extends RuntimeException
{
    private array $details;
    public function __construct(int $requestedServiceId, int $blockingServiceId, string $source, bool $ipv6)
    {
        $this->details = ['requestedServiceId'=>$requestedServiceId, 'blockingServiceId'=>$blockingServiceId,
            'addressSource'=>in_array($source, ['reserved','cache','applied'], true) ? $source : 'reserved',
            'addressFamily'=>$ipv6 ? 'IPv6' : 'IPv4'];
        parent::__construct('network_address_conflict: requested_service=' . $requestedServiceId
            . ' blocking_service=' . $blockingServiceId . ' source=' . $this->details['addressSource']
            . ' family=' . $this->details['addressFamily']);
    }
    public function context(): array { return $this->details; }
}

class ApiIP
{
    private int $len6=64;
    private bool $ipv6=false;
    private ?object $_conf=null;
    private array $assigned=[];
    private array $usedRanges=[];
    private array $rangeOwners=[];
    private array $blockedRanges=[];
    private array $allocationProofs=[];
    private bool $readOnly;
    private bool $ignoreCurrentReservation;

    /** Read-only instances validate a prospective command build without acquiring ownership. */
    public function __construct(bool $readOnly = false, bool $ignoreCurrentReservation = false)
    {
        $this->readOnly = $readOnly;
        $this->ignoreCurrentReservation = $readOnly && $ignoreCurrentReservation;
    }

    public function assign($sid,$device=[],$ipv6=false,?object $config=null,?array $reset=null): ?string
    {
        // A queued service carries its intended pools/exclusions with its other settings.
        // A caller without a snapshot must read current configuration, not the last job's.
        $this->_conf=$config;
        $this->ipv6=(bool)$ipv6;
        $this->len6=64;
        // A global PPP pool can contain both families; each allocation uses its own CIDRs.
        $pool=$this->conf()->ppp_pool ?? null;
        if($device) $device=(object)(array)$device;
        if(is_object($device)){
            $length=$device->pfxLength ?? 64;
            $length=$length===null || $length==='' ? 64 : $length;
            if(!is_numeric($length) || (float)$length!=(int)$length || $length<1 || $length>128)
                throw new InvalidArgumentException('IPv6 client prefix length must be between 1 and 128');
            $this->len6=(int)$length;
            $pool=$ipv6 ? ($device->pool6 ?? null) : ($device->pool ?? null);
        }
        $prefixes=[];
        foreach ($pool ? preg_split('/\s*,\s*/',trim((string)$pool)) : [] as $prefix) {
            $parts=explode('/',$prefix);
            $family6=filter_var($parts[0],FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)!==false;
            $family4=filter_var($parts[0],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)!==false;
            if (count($parts)!==2 || !($family4 || $family6) || !ctype_digit($parts[1])
                || (int)$parts[1]<1 || (int)$parts[1]>($family6 ? 128 : 32))
                throw new InvalidArgumentException('Invalid address pool configuration');
            if ($family6===(bool)$ipv6) $prefixes[]=$prefix;
        }
        $reset=$this->resetIntent($reset, (bool)$ipv6);
        $reserve = function() use($sid,$prefixes,$ipv6,$reset,$pool,$device){
            // Check again under the write lock; another worker may have reserved this service.
            $stored=$this->db()->selectAddress($sid,$ipv6);
            $existing=$this->ignoreCurrentReservation ? null : $stored;
            if ($existing!==null && $existing!=='') {
                try { $existing=$this->validReservation($sid, $existing, $ipv6); }
                catch (InvalidNetworkAddress $error) {
                    if ($reset===null) throw $error;
                    $existing=null;
                }
            }
            $intent=$reset;
            if ($intent===null && $existing && $prefixes && !$this->withinPools($existing,$prefixes)) {
                // Moving an automatic account between pools must not carry its former router's IP.
                $intent=['generation'=>substr(hash('sha256',json_encode([(int)$sid,(bool)$ipv6,$existing,
                    $pool,(array)$device])),0,32),'previous'=>$existing];
            }
            if ($intent!==null) {
                $receipt=$this->allocationReceipt((int)$sid,(bool)$ipv6,$intent);
                $receiptCurrent=$existing;
                if ($this->ignoreCurrentReservation && $receipt && $stored!==null && $stored!=='') {
                    try { $receiptCurrent=$this->validReservation($sid,$stored,(bool)$ipv6); }
                    catch (InvalidNetworkAddress $error) { $receiptCurrent=null; }
                }
                if ($receipt && $receiptCurrent===$receipt['value'] && $this->withinPools($receiptCurrent,$prefixes)) {
                    $this->allocationProofs[(int)$sid][$ipv6 ? 'address6' : 'address']=$receipt;
                    return $receiptCurrent;
                }
            } elseif ($existing) return $existing;
            if (!$prefixes) return null;
            $this->loadUsedRanges($sid,$ipv6);
            if ($intent!==null) {
                // Own applied/prepared resources remain occupied until the queued change succeeds.
                foreach ([$existing,$intent['previous']] as $previous) if ($previous)
                    $this->usedRanges[]=$this->range($previous,$ipv6);
                if (is_file('data/jobs.db')) foreach (myJobQueue()->reservedAddresses($ipv6) as $row) {
                    if ((int)($row['id']??0)!==(int)$sid) continue;
                    $previous=$row[$ipv6 ? 'address6' : 'address'] ?? $row['address'] ?? null;
                    if ($previous) $this->usedRanges[]=$this->range((string)$previous,$ipv6);
                }
            }
            $this->blockedRanges=$this->mergeRanges(array_merge($this->usedRanges,$this->excludedRanges()));
            foreach($prefixes as $prefix){
                $address=$this->find_unused($prefix);
                if(!$address) continue;
                if($ipv6) $address.='/'.$this->len6;
                $this->persist($sid,$address,$ipv6);
                $proof=['value'=>$address,'previous'=>$intent['previous']??null,
                    'generation'=>$intent['generation']??bin2hex(random_bytes(16))];
                if (!$this->readOnly) $this->persistAllocation((int)$sid,(bool)$ipv6,$proof);
                if (!$this->readOnly) $this->allocationProofs[(int)$sid][$ipv6 ? 'address6' : 'address']=$proof;
                return $address;
            }
            return null;
        };
        $reserved = $this->readOnly ? $reserve() : $this->db()->transaction($reserve);
        if ($reserved) {
            $this->remember($sid, $reserved, $ipv6);
            if (!$this->readOnly) MyLog()->Append(['ip_assigned', "address: $reserved"]);
        }
        return $reserved;
    }

    /** Receipts describe actual pool selections, never inferred/manual address reuse. */
    public function allocationProofs(int $serviceId): array { return $this->allocationProofs[$serviceId] ?? []; }
    public function beginService(int $serviceId): void
    {
        unset($this->allocationProofs[$serviceId]);
        foreach (['v4','v6'] as $family) foreach ($this->assigned[$family]??[] as $address=>$sid)
            if ((int)$sid===$serviceId) unset($this->assigned[$family][$address]);
    }

    private function resetIntent(?array $reset, bool $ipv6): ?array
    {
        if ($reset===null) return null;
        if (!is_string($reset['generation']??null) || !preg_match('/^[a-f0-9]{32}$/D',$reset['generation']))
            throw new InvalidArgumentException('Invalid address reset generation');
        $previous=$reset['previous']??null;
        if ($previous!==null && $previous!=='') {
            try { $previous=self::normalize((string)$previous,$ipv6); }
            catch (InvalidArgumentException $error) { $previous=null; }
        } else $previous=null;
        return ['generation'=>$reset['generation'],'previous'=>$previous];
    }

    private function withinPools(string $address,array $prefixes): bool
    {
        foreach ($prefixes as $prefix) if ($this->in_subnet($address,$prefix)) return true;
        return false;
    }

    private function allocationReceipt(int $sid,bool $ipv6,array $intent): ?array
    {
        if (!$this->db()->singleQuery("SELECT 1 FROM sqlite_master WHERE type='table' AND name='pool_allocations'")) return null;
        $rows=$this->db()->selectCustom('SELECT value,previous,generation FROM pool_allocations WHERE service_id=:id AND family=:family',
            [':id'=>$sid,':family'=>$ipv6 ? 6 : 4]) ?? [];
        $receipt=$rows[0]??null;
        if (!$receipt || ($receipt['generation']??null)!==$intent['generation'] || ($receipt['previous']??null)!==$intent['previous']) return null;
        try { $receipt['value']=self::normalize((string)$receipt['value'],$ipv6); }
        catch (InvalidArgumentException $error) { return null; }
        return $receipt['value']===$intent['previous'] ? null : $receipt;
    }

    private function persistAllocation(int $sid,bool $ipv6,array $proof): void
    {
        $this->db()->exec('CREATE TABLE IF NOT EXISTS pool_allocations (service_id INTEGER NOT NULL,family INTEGER NOT NULL,value TEXT NOT NULL,previous TEXT,generation TEXT NOT NULL,PRIMARY KEY(service_id,family))');
        if (!$this->db()->execute('INSERT INTO pool_allocations(service_id,family,value,previous,generation) VALUES(:id,:family,:value,:previous,:generation) ON CONFLICT(service_id,family) DO UPDATE SET value=excluded.value,previous=excluded.previous,generation=excluded.generation',
            [':id'=>$sid,':family'=>$ipv6 ? 6 : 4,':value'=>$proof['value'],':previous'=>$proof['previous'],':generation'=>$proof['generation']]))
            throw new RuntimeException('Failed to retain pool allocation receipt');
    }

    public function local(): string
    {
        do{
            $address=long2ip(random_int(ip2long('169.254.1.0'),ip2long('169.254.254.255')));
            $last=(int)explode('.',$address)[3];
        }while($last===0 || $last===255);
        return $address;
    }

    public static function normalize(string $address,bool $ipv6=false,int $defaultLength=64): string
    {
        $parts=explode('/',trim($address));
        $flag=$ipv6 ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;
        if(!filter_var($parts[0],FILTER_VALIDATE_IP,$flag) || count($parts)>2)
            throw new InvalidArgumentException('Invalid '.($ipv6 ? 'IPv6' : 'IPv4').' address');
        if(!$ipv6){
            if(isset($parts[1]) && $parts[1]!=='32') throw new InvalidArgumentException('IPv4 service address must be a host address');
            return inet_ntop(inet_pton($parts[0]));
        }
        $length=$parts[1] ?? $defaultLength;
        if(!ctype_digit((string)$length) || (int)$length<1 || (int)$length>128)
            throw new InvalidArgumentException('Invalid IPv6 prefix length');
        $length=(int)$length;
        $value=gmp_init(bin2hex(inet_pton($parts[0])),16);
        $hosts=gmp_pow(2,128-$length);
        $network=gmp_mul(gmp_div_q($value,$hosts),$hosts);
        return inet_ntop(hex2bin(str_pad(gmp_strval($network,16),32,'0',STR_PAD_LEFT))).'/'.$length;
    }

    public function set($sid,$address,$ip6=false,int $defaultLength=64): void
    {
        // Manual addresses belong to the current service, never to the preceding allocation.
        try {
            if (!$ip6 && strpos((string)$address, '/') !== false) {
                throw new InvalidArgumentException('Manual IPv4 must contain only the client IP, without a subnet mask');
            }
            $address=self::normalize((string)$address,(bool)$ip6,$defaultLength);
        } catch (InvalidArgumentException $error) {
            throw new InvalidNetworkAddress((int)$sid, (int)$sid, 'input', (bool)$ip6);
        }
        $reserve = function() use($sid,$address,$ip6){
            // Reusing this exact valid reservation does not acquire any new
            // ownership. An unrelated corrupt legacy row must not interrupt
            // suspension/reactivation of an already assigned service.
            if (!$this->ignoreCurrentReservation && $this->db()->selectAddress($sid,(bool)$ip6) === $address) return;
            $this->loadUsedRanges($sid,$ip6);
            $owner=$this->conflictingOwner($this->range($address,$ip6));
            if($owner!==null) throw new NetworkAddressConflict((int)$sid,$owner['id'],$owner['source'],(bool)$ip6);
            $this->persist($sid,$address,$ip6);
        };
        if ($this->readOnly) $reserve(); else $this->db()->transaction($reserve);
        $this->remember($sid,$address,$ip6);
    }

    private function persist($sid,string $address,bool $ipv6): void
    {
        if(!is_numeric($sid) || (int)$sid<1) throw new InvalidArgumentException('A valid service ID is required to reserve an address');
        if ($this->readOnly) return;
        $field=$ipv6 ? 'address6' : 'address';
        $sql="INSERT INTO network(id,$field) VALUES (:id,:address) ON CONFLICT(id) DO UPDATE SET $field=excluded.$field";
        if(!$this->db()->execute($sql,[':id'=>(int)$sid,':address'=>$address]))
            throw new RuntimeException('Failed to reserve service address');
    }

    private function remember($sid,string $address,bool $ipv6): void
    {
        $type=$ipv6 ? 'v6' : 'v4';
        foreach($this->assigned[$type] ?? [] as $oldAddress=>$oldSid){
            if((string)$oldSid===(string)$sid) unset($this->assigned[$type][$oldAddress]);
        }
        $this->assigned[$type][$address]=$sid;
    }

    public function find_assigned($sid,$ipv6=false): ?string
    {
        $type=$ipv6 ? 'v6' : 'v4';
        $found=array_search($sid,$this->assigned[$type] ?? []);
        if($found!==false) return $found;
        if ($this->ignoreCurrentReservation) return null;
        $found=$this->db()->selectAddress($sid,$ipv6);
        if($found!==null && $found!=='') $found=$this->validReservation($sid,$found,(bool)$ipv6);
        if($found) $this->remember($sid,$found,$ipv6);
        return $found;
    }

    private function validReservation($sid, $address, bool $ipv6): string
    {
        try {
            // A persisted IPv6 prefix must carry its own length; current
            // router settings cannot reinterpret historical ownership.
            if (!is_string($address) || ($ipv6 && strpos($address, '/') === false))
                throw new InvalidArgumentException('Invalid persisted reservation');
            return self::normalize($address, $ipv6);
        } catch (InvalidArgumentException $error) {
            throw new InvalidNetworkAddress((int)$sid, (int)$sid, 'reserved', $ipv6);
        }
    }

    public function flush(): void
    {
        // Both families have already been reserved atomically, including IPv6-only services.
        $this->assigned=[];
        $this->allocationProofs=[];
    }

    private function loadUsedRanges($sid,bool $ipv6): void
    {
        require_once __DIR__ . '/api_job_queue.php';
        $this->usedRanges=[];
        $this->rangeOwners=[];
        $field=$ipv6 ? 'address6' : 'address';
        $devices=[];
        foreach ($this->db()->selectAllFromTable('devices') ?? [] as $device) $devices[(int)$device['id']]=$device;
        foreach(['reserved'=>$this->db(),'cache'=>$this->cache()] as $source=>$database){
            $sql=$source==='cache'
                ? "SELECT n.id,n.$field AS address,s.device FROM network n JOIN services s ON s.id=n.id WHERE n.id<>:id AND n.$field IS NOT NULL AND s.device>0 AND s.status NOT IN (0,2,5,6,8)"
                : "SELECT id,$field AS address FROM network WHERE id<>:id AND $field IS NOT NULL";
            $rows=$database->selectCustom($sql,[':id'=>(int)$sid]) ?? [];
            foreach($rows as $row){
                if($row['address']===null || $row['address']==='') continue;
                if ($source==='cache' && (!isset($devices[(int)$row['device']]) || myJobQueue()->isServiceBlocked((int)$row['id']))) continue;
                try {
                    $address=$row['address'];
                    if ($source==='cache') {
                        if (!$ipv6 && strpos((string)$address, '/') !== false) throw new InvalidArgumentException('Manual IPv4 must be a host IP');
                        $length=$devices[(int)$row['device']]['pfxLength'] ?? 64;
                        if ($length==='') $length=64;
                        $address=self::normalize((string)$address,$ipv6,(int)$length);
                    }
                    $range=$this->range($address,$ipv6);
                    $this->usedRanges[]=$range;
                    $this->rangeOwners[]=['range'=>$range,'id'=>(int)$row['id'],'source'=>$source];
                } catch (InvalidArgumentException $error) {
                    // A malformed peer string cannot occupy a numeric IP range.
                    // Preserve its row for review and reject it when that service
                    // is processed, without stopping another customer's work.
                    // Valid cache/applied/prepared/retained ownership is still
                    // collected below and remains globally exclusive.
                    if (!$this->readOnly) {
                        if ($source === 'reserved') MyLog()->Append(['event'=>'invalid_peer_reservation_ignored',
                            'requestedServiceId'=>(int)$sid,'blockingServiceId'=>(int)$row['id'],
                            'addressSource'=>'reserved','addressFamily'=>$ipv6 ? 'IPv6' : 'IPv4'],LOG_WARNING);
                        else MyLog()->Append(['invalid_cached_address','serviceId'=>(int)$row['id'],'family'=>$ipv6 ? 'IPv6' : 'IPv4'],LOG_WARNING);
                    }
                }
            }
        }
        // Applied or partially prepared operations may still own their old address on a router.
        // Replacing network's desired address does not release those durable reservations.
        if (is_file('data/jobs.db')) {
            require_once __DIR__ . '/api_job_queue.php';
            foreach (myJobQueue()->reservedAddresses($ipv6) as $row) {
                if ((int)($row['id'] ?? 0) === (int)$sid) continue;
                $address = $row[$field] ?? $row['address'] ?? null;
                if ($address) {
                    try {
                        $range=$this->range((string)$address, $ipv6);
                        $this->usedRanges[]=$range;
                        $this->rangeOwners[]=['range'=>$range,'id'=>(int)($row['id']??0),'source'=>'applied'];
                    }
                    catch (InvalidArgumentException $error) {
                        throw new InvalidNetworkAddress((int)$sid, (int)($row['id'] ?? 0), 'applied', $ipv6);
                    }
                }
            }
        }
    }

    private function range(string $prefix,bool $ipv6): array
    {
        $parts=explode('/',trim($prefix));
        $flag=$ipv6 ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;
        if(count($parts)>2 || !filter_var($parts[0],FILTER_VALIDATE_IP,$flag)) throw new InvalidArgumentException('Invalid address in a reserved network');
        $max=$ipv6 ? 128 : 32;
        $length=$parts[1] ?? $max;
        if(!ctype_digit((string)$length) || (int)$length<0 || (int)$length>$max)
            throw new InvalidArgumentException('Invalid network prefix length');
        $hosts=gmp_pow(2,$max-(int)$length);
        $value=$this->ip2gmp($parts[0]);
        $start=gmp_mul(gmp_div_q($value,$hosts),$hosts);
        return [$start,gmp_sub(gmp_add($start,$hosts),1)];
    }

    private function overlapsUsed(array $candidate): bool
    {
        foreach($this->usedRanges as $used){
            if(gmp_cmp($candidate[0],$used[1])<=0 && gmp_cmp($candidate[1],$used[0])>=0) return true;
        }
        return false;
    }

    private function conflictingOwner(array $candidate): ?array
    {
        foreach ($this->rangeOwners as $owner) {
            $range=$owner['range'];
            if (gmp_cmp($candidate[0],$range[1])<=0 && gmp_cmp($candidate[1],$range[0])>=0) return $owner;
        }
        return null;
    }

    public function in_subnet($address,$subnet): bool
    {
        $ipv6=strpos((string)$subnet,':')!==false;
        $candidate=$this->range($address,$ipv6);
        $network=$this->range($subnet,$ipv6);
        return gmp_cmp($candidate[0],$network[0])>=0 && gmp_cmp($candidate[1],$network[1])<=0;
    }

    private function is_prefix($prefix): bool
    {
        $parts=explode('/',trim($prefix));
        $flag=$this->ipv6 ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;
        return count($parts)===2 && ctype_digit($parts[1]) && (int)$parts[1]>0
            && (int)$parts[1]<=($this->ipv6 ? 128 : 32)
            && filter_var($parts[0],FILTER_VALIDATE_IP,$flag)!==false;
    }

    private function find_unused($prefix): ?string
    {
        if(!$this->is_prefix($prefix)) return null;
        if($this->ipv6 && $this->len6<(int)explode('/',$prefix)[1])
            throw new InvalidArgumentException('Client IPv6 prefix cannot be larger than its pool');
        [$first,$last]=$this->range($prefix,$this->ipv6);
        $step=$this->ipv6 ? gmp_pow(2,128-$this->len6) : gmp_init(1);
        $value=$this->ipv6 ? $first : gmp_add($first,1);
        $blockedIndex=0;
        while(gmp_cmp($value,$last)<=0){
            $end=gmp_sub(gmp_add($value,$step),1);
            if(gmp_cmp($end,$last)>0) break;
            if(!$this->ipv6 && gmp_cmp($value,$last)===0) break;
            while(isset($this->blockedRanges[$blockedIndex]) && gmp_cmp($this->blockedRanges[$blockedIndex][1],$value)<0) $blockedIndex++;
            $blocked=$this->blockedRanges[$blockedIndex] ?? null;
            if($blocked && gmp_cmp($blocked[0],$end)<=0){
                // Jump to the first aligned client prefix after the whole occupied interval.
                $next=gmp_add($blocked[1],1);
                $value=gmp_mul(gmp_div_q(gmp_add($next,gmp_sub($step,1)),$step),$step);
                continue;
            }
            return $this->gmp2ip($value);
        }
        return null;
    }

    public function is_used($address): bool
    {
        $this->loadUsedRanges(0,$this->ipv6);
        $prefix=$this->ipv6 && strpos($address,'/')===false ? $address.'/'.$this->len6 : $address;
        return $this->overlapsUsed($this->range($prefix,$this->ipv6));
    }

    private function gmp2ip($value): string
    {
        return inet_ntop(hex2bin(str_pad(gmp_strval($value,16),$this->ipv6 ? 32 : 8,'0',STR_PAD_LEFT)));
    }

    private function ip2gmp($prefix): object
    {
        $packed=inet_pton(explode('/',trim($prefix))[0]);
        if($packed===false) throw new InvalidArgumentException('Invalid network address');
        return gmp_init(bin2hex($packed),16);
    }

    private function excludedRanges(): array
    {
        $ranges=[];
        foreach(preg_split('/\s*,\s*/',$this->conf()->excl_addr ?? '') as $excluded){
            if($excluded==='') continue;
            $ipv6=strpos($excluded,':')!==false;
            if($ipv6!==$this->ipv6) continue;
            $parts=preg_split('/\s*-\s*/',$excluded);
            if(count($parts)>2) throw new InvalidArgumentException('Invalid excluded address range');
            $start=$this->range($parts[0],$ipv6)[0];
            $end=$this->range($parts[1] ?? $parts[0],$ipv6)[1];
            if(gmp_cmp($start,$end)>0) throw new InvalidArgumentException('Excluded range starts after its end');
            $ranges[]=[$start,$end];
        }
        return $ranges;
    }

    private function mergeRanges(array $ranges): array
    {
        usort($ranges,static function($left,$right): int { return gmp_cmp($left[0],$right[0]); });
        $merged=[];
        foreach($ranges as $range){
            $last=count($merged)-1;
            if($last>=0 && gmp_cmp($range[0],gmp_add($merged[$last][1],1))<=0){
                if(gmp_cmp($range[1],$merged[$last][1])>0) $merged[$last][1]=$range[1];
            } else $merged[]=$range;
        }
        return $merged;
    }

    private function db(): ApiSqlite { return mySqlite(); }
    private function cache(): ApiSqlite { return myCache(); }
    private function conf(): object
    {
        if(empty($this->_conf)) $this->_conf=$this->db()->readConfig();
        return $this->_conf ?? new stdClass();
    }
    public function __destruct() { $this->flush(); }
}

$apiIpClass=null;
function myIPClass(): ApiIP
{
    global $apiIpClass;
    if(empty($apiIpClass)) $apiIpClass=new ApiIP();
    return $apiIpClass;
}
