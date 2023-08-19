<?php
const IP_BASE2 = 2;
const IP_BASE16 = 16;
const IP_BASE10 = 10;
const IP_PWR2 = 2;
const IP_PAD0 = '0';

class ApiIP
{
    private int $length6 = 64;
    private bool $ip6 = false;
    private ?object $_conf = null;
//    private ?ApiSqlite $_db = null ;

    public function assign($sid, $device = null, $ip6 = false): ?string
    {
        $this->ip6 = $ip6;
        $pool = $this->conf()->ppp_pool ?? null ;
        if((array) $device){
            $this->length6 = $device->pfxLength ?? 64;
            $pool = $ip6 ? $device->pool6 : $device->pool ;
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
                if($ip6) $address = $address . '/' . $this->length6 ;
                $this->set_ip($sid,$address,$ip6);
                return $address;
            }
        }
        $name = $device->name ?? 'global';
        $type = $ip6 ? 'ipv6' : 'ipv4';
        MyLog()->Append(sprintf('ip: no addresses available type: %s pool: %s device: %s',$type,$pool,$name));
        return null ;
    }

    public function clear($ids)
    {
        $this->db()->exec(sprintf(
            'delete from network where id in (%s)', implode(',',$ids)));
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

    public function set_ip($sid,$address,$ip6 = false): void
    {
        $field = $ip6 ? 'address6' : 'address';
        $set = $ip6 ? 'address' : 'address6';
        $sql = sprintf("INSERT OR REPLACE INTO network (id,%s,%s) ".
            "VALUES (%s,'%s',(SELECT %s FROM network WHERE id = %s))",
        $field,$set,$sid,$address,$set,$sid);
        $this->db()->exec($sql);
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
        $field = $ip6 ? 'address6' : 'address';
        $sql = sprintf("SELECT %s FROM network WHERE id = %s",$field,$sid);
        return $this->db()->singleQuery($sql);
    }

    private function valid($prefix): bool
    {
        if(!preg_match('/[\da-fA-F\.\:]+\/\d{1,3}/',$prefix)) return false ;
        $arr = explode('/',$prefix);
        if(sizeof($arr) < 2) return false ;
        if($this->ip6){
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
        $s = !$this->ip6 ? '.' : ':';
        $a = explode($s,$address); //address into array
        $last = $a[sizeof($a)-1] ?? '0' ; //last byte or word
        if(!$this->ip6)$last = base_convert($last,IP_BASE10,IP_BASE16);
        $zero = '/^0+$/';
        $ff = '/^[fF]+$/';
        return !$this->ip6
            ? preg_match($zero,$last) || preg_match($ff,$last)
            : preg_match($ff,$last);
    }

    public function is_used($address): bool
    {
        if($this->ip6) $address = $address . '/' . $this->length6;
        $field = 'address';
        if($this->ip6) $field = 'address6';
        $id = $this->db()->singleQuery(
        sprintf("SELECT id FROM network WHERE %s = '%s'", $field, $address));
        return (bool)$id;
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
        $max = $this->ip6 ? 128 :32 ;
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
        if(!$this->ip6) {
            return gmp_add($net_addr, gmp_sub($hosts_qty, 1));
        }
        return gmp_add($net_addr,$hosts_qty);
    }

    private function gmp_next($gmp_addr)
    {
        $next = !$this->ip6 ? 1
            : $this->gmp_hosts($this->length6);
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
        return ip_database();
    }

    private function conf(): object
    {
        if(empty($this->_conf)){
            $this->_conf = $this->db()->readConfig();
        }
        return $this->_conf ;
    }

}

$IP_DATABASE = null ;
function ip_database(): ApiSqlite
{
    global $IP_DATABASE ;
    if(empty($IP_DATABASE)){
        $IP_DATABASE = new ApiSqlite(null,true);
    }
    return $IP_DATABASE ;
}
