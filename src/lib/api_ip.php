<?php
const IP_BASE2=2;
const IP_BASE16=16;
const IP_BASE10=10;
const IP_PWR2=2;
const IP_PAD0='0';
class ApiIP
{
    private int $len6=64;
    private bool $ipv6=false;
    private ?object $_conf=null;
    private array $assigned=[];
    private array $usedRanges=[];
    private array $blockedRanges=[];

    public function assign($sid,$device=[],$ipv6=false,?object $config=null): ?string
    {
        // A queued service carries its intended pools/exclusions with its other settings.
        // A caller without a snapshot must read current configuration, not the last job's.
        $this->_conf=$config;
        $this->ipv6=(bool)$ipv6;
        $this->len6=64;
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
        if(!$pool) return null;
        $prefixes=preg_split('/\s*,\s*/',trim($pool));
        $reserved = $this->db()->transaction(function() use($sid,$prefixes,$ipv6){
            // Check again under the write lock; another worker may have reserved this service.
            $existing=$this->db()->selectAddress($sid,$ipv6);
            if($existing) return $existing;
            $this->loadUsedRanges($sid,$ipv6);
            $this->blockedRanges=$this->mergeRanges(array_merge($this->usedRanges,$this->excludedRanges()));
            foreach($prefixes as $prefix){
                $address=$this->find_unused($prefix);
                if(!$address) continue;
                if($ipv6) $address.='/'.$this->len6;
                $this->persist($sid,$address,$ipv6);
                return $address;
            }
            return null;
        });
        if ($reserved) {
            $this->remember($sid, $reserved, $ipv6);
            MyLog()->Append(['ip_assigned', "address: $reserved"]);
        }
        return $reserved;
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
        if (!$ip6 && strpos((string)$address, '/') !== false) {
            throw new InvalidArgumentException('Manual IPv4 must contain only the client IP, without a subnet mask');
        }
        $address=self::normalize((string)$address,(bool)$ip6,$defaultLength);
        $this->db()->transaction(function() use($sid,$address,$ip6){
            $this->loadUsedRanges($sid,$ip6);
            if($this->overlapsUsed($this->range($address,$ip6)))
                throw new RuntimeException('The service address or IPv6 prefix is already reserved by another service');
            $this->persist($sid,$address,$ip6);
        });
        $this->remember($sid,$address,$ip6);
    }

    private function persist($sid,string $address,bool $ipv6): void
    {
        if(!is_numeric($sid) || (int)$sid<1) throw new InvalidArgumentException('A valid service ID is required to reserve an address');
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
        $found=$this->db()->selectAddress($sid,$ipv6);
        if($found) $this->remember($sid,$found,$ipv6);
        return $found;
    }

    public function flush(): void
    {
        // Both families have already been reserved atomically, including IPv6-only services.
        $this->assigned=[];
    }

    private function loadUsedRanges($sid,bool $ipv6): void
    {
        require_once __DIR__ . '/api_job_queue.php';
        $this->usedRanges=[];
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
                    $this->usedRanges[]=$this->range($address,$ipv6);
                } catch (InvalidArgumentException $error) {
                    // Invalid imported attributes cannot reserve a real network. Keep the row
                    // intact so that provisioning that service fails rather than allocating anew.
                    // Durable/applied reservations remain strict and are never released here.
                    if($source!=='cache') throw $error;
                    MyLog()->Append(['invalid_cached_address','serviceId'=>(int)$row['id'],'family'=>$ipv6 ? 'IPv6' : 'IPv4'],LOG_WARNING);
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
                if ($address) $this->usedRanges[] = $this->range((string)$address, $ipv6);
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
