<?php
const IP_BASE2 = 2;
const IP_BASE16 = 16;
const IP_BASE10 = 10;
const IP_PWR2 = 2;
const IP_PAD0 = '0';

class ApiIP
{
    private int $len6 = 64;
    private bool $ipv6 = false;
    private ?object $_conf = null;
    private array $assigned = [];

    public function assign($sid, $device = [], $ipv6 = false): ?string
    {
        $this->ipv6 = $ipv6;
        $pool = $this->conf()->ppp_pool ?? null ;
        if($device){ $device = json_decode(json_encode($device)); }
        if(is_object($device)){
            $len = $device->pfxLength ?? null ;
            $this->length6 = is_int($len) ? $len : 64;
            $pool = $ipv6 ? $device->pool6 : $device->pool ;
        }
        if(!$pool){
            MyLog()->Append(['pool_invalid',"sid: $sid ipv6: $ipv6 device: ",$device],6);
            return null ;
        }
        $prefixes = explode(',',$pool);
        foreach ($prefixes as $prefix){
            $address = $this->find_unused($prefix);
            if($address){
                MyLog()->Append(['ip_assigned',"address: $address prefix $prefix"]);
                if($ipv6) $address = $address . '/' . $this->len6 ;
                $this->set($sid,$address,$ipv6);
                return $address;
            }
        }
        MyLog()->Append(['ip_assign_failed',"pool: $pool ipv6: $ipv6 device: ",$device],6);
        return null ;
    }

    public function local(): string
    {
        $start = ip2long('169.254.1.0');
        $end = ip2long('169.254.254.255');
        $address = null ;
        while(!$address){
            $a = rand($start,$end);
            $i = long2ip($a);
            $lastOct = explode('.',$i)[3];
            if($lastOct > 0 && $lastOct < 255){
                $address = $i ;
            }
        }
        return $address ;
    }

    public function set($sid, $address, $ip6 = false)
    {
        $type = $ip6 ? 'v6' : 'v4';
        $this->assigned[$type][$address] = $sid ;
    }

    public function in_subnet($address,$subnet): bool
    {
        $a = $this->ip2gmp($address);
        $start = $this->ip2gmp($subnet);
        $end = $this->gmp_bcast($subnet);
        return gmp_cmp($a,$start) >= 0 && gmp_cmp($a,$end) <= 0;
    }

    public function in_subnet($address,$subnet)
    {
        $a = $this->ip2gmp($address);
        $start = $this->ip2gmp($subnet);
        $end = $this->gmp_bcast($subnet);
        return gmp_cmp($a,$start) >= 0 && gmp_cmp($a,$end) <= 0;
    }

    private function find_unused($prefix): ?string
    {
        if(!$this->is_prefix($prefix)) return null ;
        return $this->iterate($prefix);

    }

    public function find_assigned($sid,$ipv6 = false): ?string
    {
        $type = $ipv6 ? 'v6' : 'v4';
        $found = array_search($sid,$this->assigned[$type] ?? []);
        if($found){ return $found; }
        $found = $this->db()->selectAddress($sid,$ipv6);
        if($found){ $this->set($sid,$found,$ipv6); }
        return $found ;
    }

    public function flush()
    {
        $data = [];
        $ip = array_flip($this->assigned['v4'] ?? []) ;
        $ipv6 = array_flip($this->assigned['v6'] ?? []);
        $ids = array_keys($ip);
        $ids = array_merge($ids,array_diff($ids,array_keys($ipv6)));
        foreach($ids as $id){
            $item['id'] = $id ;
            $item['address'] = $ip[$id] ??  null;
            $item['address6'] = $ipv6[$id] ?? null ;
            $data[] = $item ;
        }
        if($data){
             $this->db()->insert($data,'network',true);
        }
    }

    private function is_prefix($prefix): bool
    {
        $split = explode('/',$prefix);
        if(sizeof($split) < 2) return false ;
        $FLAG = $this->ipv6 ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4 ;
        $MAX = $this->ipv6 ? 128 : 32;
        return
            filter_var($split[0],FILTER_VALIDATE_IP,$FLAG)
            && $split[1] >= 1 && $split[1] <= $MAX;
    }

    private function is_odd($address): bool
    {
        if(!filter_var($address,FILTER_VALIDATE_IP)) {
            return true;
        }
        $ip = filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4);
        $a = preg_split("#[.:]+#",$address); //address into array
        $last = array_pop($a) ?? '0' ; //last byte or word
        if($ip)$last = base_convert($last,IP_BASE10,IP_BASE16);
        $f = $ip ? 2 : 4 ;
        $zero = "#^0+$#";
        $ff = "#^[fF]{$f}$#";
        return $ip
            ? preg_match($zero,$last) || preg_match($ff,$last)
            : preg_match($ff,$last);
    }

    public function is_used_db($address): bool
    {
        $type = $this->ipv6 ? 'v6' : 'v4' ;
        if($this->ipv6) { $address = "$address/" . $this->len6;  }
        $used = $this->assigned[$type][$address] ?? null ;
        if($used){ return true; }
        if($this->cache()->selectIsUsed($address,$this->ipv6)
            || $this->db()->selectIsUsed($address,$this->ipv6)){
            return true ;
        }
        return false ;
    }

    private function iterate($prefix): ?string
    {
        $gmp_last = $this->gmp_bcast($prefix);
        $gmp_first = $this->ip2gmp($prefix);
        for($assign = $gmp_first;
            gmp_cmp($assign,$gmp_last) < 0; // less than bcast address
            $assign = $this->gmp_next($assign)){
            if($this->is_excl($assign)) continue; //skip excluded
            $ip = $this->gmp2ip($assign);
            if($this->is_odd($ip)) continue ; // skip zeros and xFFFF
            if ($this->is_used($ip)) continue; // skip used
            return $ip ;
        }
        return null ;
    }

    private function gmp_hosts($length): object
    {
        $max = $this->ipv6 ? 128 :32 ;
        $host_len = $max - $length;
        return gmp_pow(IP_BASE2, $host_len);
    }

    private function gmp2ip($gmp): string
    {
        $hex = gmp_strval($gmp, IP_BASE16);
        if(strlen($hex) % IP_PWR2){
            $len = strlen($hex) + 1;
            $hex = str_pad($hex,$len,IP_PAD0,STR_PAD_LEFT);
        }
        return inet_ntop(hex2bin($hex));
    }

    private function gmp_bcast($prefix): ?object
    {
        if(!$this->is_prefix($prefix)) return null ;
        [$address,$length] = explode('/',$prefix);
        $hosts_qty = $this->gmp_hosts((int)$length);
        $net_addr = $this->ip2gmp($address);
        if(!$this->ipv6) {
            return gmp_add($net_addr, gmp_sub($hosts_qty, 1));
        }
        return gmp_add($net_addr,$hosts_qty);
    }

    private function gmp_next($gmp_addr): object
    {
        $next = !$this->ipv6 ? 1
            : $this->gmp_hosts($this->len6);
        return gmp_add($gmp_addr,$next);
    }

    private function ip2gmp($prefix): object
    {
        $address = preg_replace('#\s*/[\d\s]*#','',$prefix);
        return gmp_init(bin2hex(inet_pton($address)),16);
    }

    private function is_excl($gmp_addr): bool
    {
        $exclusions = $this->conf()->excl_addr ?? '';
        $ranges = preg_split("#\s*,\s*#",$exclusions);
        foreach ($ranges as $range) {
            if(empty($range)){ continue; }
            $split = preg_split("#\s*-+\s*#",$range);
            $split[1] ??= $split[0];
            $start = $this->ip2gmp($split[0]);
            $end = $this->ip2gmp($split[1]) ;
            if(gmp_cmp($gmp_addr,$start) >= 0 &&
                gmp_cmp($gmp_addr,$end) <= 0 ){
                return true ;
            }
        }
        return false;
    }

    private function db(): ApiSqlite
    {
       return mySqlite();
    }

    private function cache(): ApiSqlite
    {
        return myCache();
    }

    private function conf(): object
    {
        if(empty($this->_conf)){
            $this->_conf = $this->db()->readConfig();
        }
        return $this->_conf ;
    }

    public function __destruct() { $this->flush(); }
}

$apiIpClass = null ;

function myIPClass(): ApiIP
{
    global $apiIpClass ;
    if(empty($apiIpClass)){
        $apiIpClass = new ApiIP();
    }
    return $apiIpClass ;
}