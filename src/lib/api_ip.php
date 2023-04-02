<?php

class ApiIP
{
    private int $alen ;
    private bool $ip6 = false;

    public function ip($sid,$device = null,$ip6 = false): ?string
    {
        $this->ip6 = $ip6;
        $this->alen = $device->pfxLength ?? 64;
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

    public function set_ip($sid,$address)
    {
        if($this->type($address) == 'ip6'){ $this->set_ip6($sid,$address); }
        else{
            $cache = sprintf("insert or replace into network (id,address) values (%s,'%s') ",$sid,$address);
            $main = sprintf("update or ignore services set address='%s' where id=%s ",$address,$sid);
            $this->db()->exec($main);
            $this->dbCache()->exec($cache);
        }
    }

    public function set_ip6($sid,$address)
    {
        $cache = sprintf("insert or replace into network (id,prefix6) values (%s,'%s') ",$sid,$address);
        $main = sprintf("update or ignore services set prefix6='%s' where id=%s ",$address,$sid);
        $this->db()->exec($main);
        $this->dbCache->exec($cache);
    }

    private function findUnused($prefix): ?string
    {
        if(!$this->valid($prefix)) return null ;
        return $this->iterate($prefix);

    }

    private function valid($prefix)
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
        if(!$this->ip6)$last = base_convert($last,10,16);
        $zero = '/^0+$/';
        $ff = '/^[fF]+$/';
        $odd = !$this->ip6
            ? preg_match($zero,$last) || preg_match($ff,$last)
            : preg_match($ff,$last) ;
        return $odd ;
    }

    private function is_used($address): bool
    {
        if($this->type($address) == 'ip6') return $this->is_used6($address);
        $main = $this->db()->singleQuery(sprintf("select id from services where address='%s'",$address));
        $cache = $this->dbCache()->singleQuery(sprintf("select id from network where address='%s'",$address));
        return $main || $cache ;
    }

    private function is_used6($address): bool
    {
        $address .= sprintf('/',$this->alen);
        $main = $this->db()->singleQuery(sprintf("select id from services where prefix6=",$address));
        $cache = $this->dbCache()->singleQuery(sprintf("select id from network where prefix6=",$address));
        return $main || $cache ;
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

    private function gmp_hosts($length)
    {
        $max = $this->ip6 ? 128 :32 ;
        $host_len = $max - $length;
        $base = 2;
        return gmp_pow($base, $host_len);
    }

    private function gmp2ip($address)
    {
        $hex = gmp_strval($address, 16);
        if (strlen($hex) % 2) {
            $newlen = strlen($hex) + 1;
            $hex = str_pad('0', $newlen, $hex, STR_PAD_RIGHT);
        };
        return inet_ntop(hex2bin($hex));
    }

    private function gmp_bcast($prefix)
    {
        [$address,$length] = explode('/',$prefix);
        $hosts_qty = $this->gmp_hosts($length);
        $net_addr = $this->ip2gmp($address);
        if(!$this->ip6) {
            return gmp_add($net_addr, gmp_sub($hosts_qty, 1));
        }
        return gmp_add($net_addr,$hosts_qty);
    }

    private function gmp_next($addr)
    {
        $next = !$this->ip6 ? 1
            : gmp_pow(2,$this->alen);
        return gmp_add($addr,$next);
    }

    private function ip2gmp($prefix)
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

    private function db()
    {
        return new ApiSqlite();
    }

    private function dbCache()
    {
        return new ApiSqlite('data/cache.db');
    }

    private function conf()
    {
        return $this->db()->readConfig();
    }

}
