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
    private ?ApiSqlite $_db = null ;
    private ?ApiSqlite $_cache = null ;
    private array $ipv4_map = [] ;
    private array $ipv6_map = [] ;


    public function assign($sid, $device = null, $ipv6 = false): ?string
    {
        $this->ipv6 = $ipv6;
        $pool = $this->conf()->ppp_pool ?? null ;
        if(is_object($device)){
            $len = $device->pfxLength ?? 64 ;
            $this->len6 = is_int($len) ? $len : 64;
            $pool = $ipv6 ? $device->pool6 : $device->pool ;
        }
        if(!$pool){
            MyLog()->Append('ip: no ip pool defined');
            return null ;
        }
        $prefixes = explode(',',$pool);
        foreach ($prefixes as $prefix){
            if(!$prefix){ continue; }
            MyLog()->Append('pool: '. $prefix);
            $address = $this->find_unused($prefix);
            if($address){
                MyLog()->Append(sprintf("ip assignment: %s",$address));
                if($ipv6) $address = $address . '/' . $this->len6 ;
                $this->set_ip($sid,$address,$ipv6);
                return $address;
            }
        }
        $name = $device->name ?? 'global';
        $type = $ipv6 ? 'ipv6' : 'ipv4';
        MyLog()->Append(sprintf('ip: no addresses available type: %s pool: %s device: %s',$type,$pool,$name));
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

    public function set_ip($sid,$address,$ip6 = false)
    {
        $map = $ip6 ? 'ipv6_map' : 'ipv4_map';
        $this->$map[$address] = $sid ;
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
        if(!$this->valid($prefix)) return null ;
        return $this->iterate($prefix);

    }

    public function find_used($sid,$ip6 = false): ?string
    {
        $mapname = $ip6 ? 'ipv6_map' : 'ipv4_map';
        $map = array_flip($this->$mapname) ;
        $assigned = $map[$sid] ?? null ;
        if($assigned){ return $assigned; }
        $field = $ip6 ? 'address6' : 'address';
        $sql = sprintf("SELECT %s FROM network WHERE id = %s",$field,$sid);
        return $this->db()->singleQuery($sql);
    }

    private function save_used()
    {
        $data = [];
        $ip = array_flip($this->ipv4_map) ;
        $ipv6 = array_flip($this->ipv6_map);
        $ids = array_keys($ip);
        $ids = array_merge($ids,array_diff($ids,array_keys($ipv6)));
        foreach($ids as $id){
            $item['id'] = $id ;
            $item['address'] = $ip[$id] ??  null;
            $item['address6'] = $ipv6[$id] ?? null ;
            $data[] = $item ;
        }
        if($data){
            $this->db()->insert($data,'network',true);}
    }

    private function valid($prefix): bool
    {
        if(!preg_match('/[\da-fA-F.:]+\/\d{1,3}/',$prefix)) return false ;
        $arr = explode('/',$prefix);
        if(sizeof($arr) < 2) return false ;
        if($this->ipv6){
            return filter_var($arr[0],FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)
                && $arr[1] >= 1 && $arr[1] <= 128;
        }
        else{
            return filter_var($arr[0],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)
                && $arr[1] >= 1 && $arr[1] <= 32;
        }
    }

    private function is_odd($address): bool
    {
        $s = !$this->ipv6 ? '.' : ':';
        $a = explode($s,$address); //address into array
        $last = $a[sizeof($a)-1] ?? '0' ; //last byte or word
        if(!$this->ipv6)$last = base_convert($last,IP_BASE10,IP_BASE16);
        $zero = '/^0+$/';
        $ff = '/^[fF]+$/';
        return !$this->ipv6
            ? preg_match($zero,$last) || preg_match($ff,$last)
            : preg_match($ff,$last);
    }

    public function is_used_db($address): bool
    {
        return $this->cache()->selectIsUsed($address,$this->ipv6) ||
            $this->db()->selectIsUsed($address,$this->ipv6);
    }

    public function is_used($address)
    {
        $map = $this->ipv6 ? 'ipv6_map' : 'ipv4_map' ;
        if($this->ipv6) $address = $address . '/' . $this->len6;
        $used = $this->$map[$address] ?? null ;
        if($used){ return true; }
        return $this->is_used_db($address);
    }

    private function type($address): ?string
    {
        $prefix = explode('/',$address)[0] ?? null;
        if(filter_var($prefix,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) return 'ip4';
        if(filter_var($prefix,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)) return 'ip6';
        return null ;
    }

    private function iterate($prefix): ?string
    {
        $gmp_last = $this->gmp_bcast($prefix);
        $gmp_first = $this->ip2gmp($prefix);
        for($assign = $gmp_first;
            gmp_cmp($assign,$gmp_last) < 0; // less than bcast address
            $assign = $this->gmp_next($assign)){
            if($this->excluded($assign)) continue; //skip excluded
            $ip = $this->gmp2ip($assign);
            if($this->is_odd($ip)) continue ; // skip zeros and xFFFF
            if ($this->is_used($ip)) continue; // skip used
            return $ip ;
        }
        return null ;
    }

    private function gmp_hosts($length)
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

    private function gmp_bcast($prefix)
    {
        if(!$this->valid($prefix)) return null ;
        [$address,$length] = explode('/',$prefix);
        $hosts_qty = $this->gmp_hosts((int)$length);
        $net_addr = $this->ip2gmp($address);
        if(!$this->ipv6) {
            return gmp_add($net_addr, gmp_sub($hosts_qty, 1));
        }
        return gmp_add($net_addr,$hosts_qty);
    }

    private function gmp_next($gmp_addr)
    {
        $next = !$this->ipv6 ? 1
            : $this->gmp_hosts($this->len6);
        return gmp_add($gmp_addr,$next);
    }

    private function ip2gmp($prefix)
    {
        $address = preg_replace('/\/\d*/','',$prefix);
        return gmp_init(bin2hex(inet_pton($address)),16);
    }

    private function excluded($gmp_addr): bool
    {
        $read = $this->exclusions();
        foreach ($read as $range) {
            $range = explode('-', $range);
            $range[1] ??= $range[0];
            $start = $this->ip2gmp($range[0]);
            $end = $this->ip2gmp($range[1]) ;
            if(gmp_cmp($gmp_addr,$start) >= 0 &&
                gmp_cmp($gmp_addr,$end) <= 0 ){
                return true ;
            }
        }
        return false;
    }

    private function exclusions(): array
    {
        if(empty($this->conf()->excl_addr)) return [];
        return explode(',',trim($this->conf()->excl_addr));
    }

    private function db(): ApiSqlite
    {
        if(empty($this->_db)){
            $this->_db = new ApiSqlite();
        }
        return $this->_db ;
    }

    private function cache(): ApiSqlite
    {
        if(empty($this->_cache)){
            $this->_cache = new ApiSqlite('data/cache.db');
        }
        return $this->_cache ;
    }

    private function conf(): object
    {
        if(empty($this->_conf)){
            $this->_conf = $this->db()->readConfig();
        }
        return $this->_conf ;
    }

    public function __destruct() { $this->save_used(); }

}
