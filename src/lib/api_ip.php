<?php
const IP_BASE2 = 2;
const IP_BASE16 = 16;
const IP_BASE10 = 10;
const IP_PWR2 = 2;
const IP_PAD0 = '0';
class ApiIP
{
    private int $length6 ;
    private bool $ip6 = false;

    public function ip($sid,$device = null,$ip6 = false): ?string
    {
        $this->ip6 = $ip6;
        $this->length6 = $device->pfxLength ?? 64;
        $pool = $this->conf()->ppp_pool ;
        if($device || $this->conf()->router_ppp_pool){
            $pool = $ip6 ? $device->pool6 : $device->pool ;
        }
        if(!$pool){
            throw new Exception('ip: cannot find an address pool');
        }
        $prefixes = explode(',',$pool);
        foreach ($prefixes as $prefix){
            $address = $this->findUnused($prefix);
            MyLog()->Append('ip assignment: '.$address);
            if($address){
                $this->set_ip($sid,$address);
                return $address;
            }
        }
        $name = $device->name ?? 'global';
        throw new Exception(sprintf(
            'ip: no addresses available pool: %s device: %s',$pool,$name));
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

    public function set_ip($sid,$address): void
    {
        if($this->type($address) == 'ip6'){ $this->set_ip6($sid,$address); }
        else{
            $cache = sprintf("insert or replace into network (id,address) values (%s,'%s') ",$sid,$address);
            $main = sprintf("update or ignore services set address='%s' where id=%s ",$address,$sid);
            $this->db()->exec($main);
            $this->dbCache()->exec($cache);
        }
    }

    public function set_ip6($sid,$address): void
    {
        $cache = sprintf("insert or replace into network (id,prefix6) values (%s,'%s') ",$sid,$address);
        $main = sprintf("update or ignore services set prefix6='%s' where id=%s ",$address,$sid);
        $this->db()->exec($main);
        $this->dbCache()->exec($cache);
    }

    private function findUnused($prefix): ?string
    {
        if(!$this->valid($prefix)) return null ;
        return $this->iterate($prefix);

    }

    private function valid($prefix): bool
    {
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

    private function is_used($address): bool
    {
        if($this->type($address) == 'ip6') return $this->is_used6($address);
        $main = $this->db()->singleQuery(sprintf("select id from services where address='%s'",$address));
        //$cache = $this->dbCache()->singleQuery(sprintf("select id from network where address='%s'",$address));
        return (bool) $main ;
    }

    private function is_used6($address): bool
    {
        $address .= sprintf('/%s',$this->length6);
        $main = $this->db()->singleQuery(sprintf("select id from services where prefix6=%s",$address));
        //$cache = $this->dbCache()->singleQuery(sprintf("select id from network where prefix6=%s",$address));
        return (bool) $main ;
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
        while($gmp_first != $gmp_last){
            $gmp_first = $this->gmp_next($gmp_first);
            if($gmp_first == $gmp_last) break ;
            if($this->excluded($gmp_first)) continue; //skip excluded
            $ip = $this->gmp2ip($gmp_first);
            if($this->is_odd($ip)) continue ; // skip zeros and xFFFF
            if ($this->is_used($ip)) continue; // skip used
            return $ip ;
        }
        return null ;
    }

    private function gmp_hosts($length): GMP
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

    private function gmp_bcast($prefix): GMP
    {
        [$address,$length] = explode('/',$prefix);
        $hosts_qty = $this->gmp_hosts($length);
        $net_addr = $this->ip2gmp($address);
        if(!$this->ip6) {
            return gmp_add($net_addr, gmp_sub($hosts_qty, 1));
        }
        return gmp_add($net_addr,$hosts_qty);
    }

    private function gmp_next($addr): GMP
    {
        $next = !$this->ip6 ? 1
            : gmp_pow(IP_PWR2,$this->length6);
        return gmp_add($addr,$next);
    }

    private function ip2gmp($prefix): GMP
    {
        $address = explode('/',$prefix)[0];
        return gmp_init(bin2hex(inet_pton($address)),16);
    }

    private function excluded($address): bool
    {
        $read = $this->exclusions();
        foreach ($read as $range) {
            $range .= '-';              //append hyphen incase of single addr entry
            [$s, $e] = explode('-', $range);
            if (!$e) $e = $s;
            $start = $this->ip2gmp($s);
            $end = $this->ip2gmp($e) ;
            if($address >= $start && $address <= $end) return true ;
        }
        return false;
    }

    private function exclusions(): array
    {
        return $this->conf()->excl_addr
            ? explode(',', $this->conf()->excl_addr . ',')
            : [];
    }

    private function db(): ApiSqlite
    {
        return new ApiSqlite();
    }

    private function dbCache(): ApiSqlite
    {
        return new ApiSqlite('data/cache.db');
    }

    private function conf(): stdClass
    {
        return $this->db()->readConfig();
    }

}
