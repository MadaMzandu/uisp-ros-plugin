<?php

include_once 'api_sqlite.php';

class API_IP
{

    private $addr; //assign
    private $prefix;
    private $len;
    private $alen ;
    private $pools; // active configured pool
    private $conf;
    private $type ; // 0 - v4 , 1 - v6

    public function __construct()
    {
        $this->conf = (new API_SQLite())->readConfig();
    }

    public function assign($device = false): ?array
    {
        $p4 = $device->pool ?? null ;
        $p6 = $device->pool6 ?? null ;
        $this->alen = $device->pfxLength ?? 64 ;
        foreach([$p4,$p6] as $pool){
            if($pool){
                $this->pools = explode(',', $pool . ',') ?? [];
                $this->findUnused();
            }
        }
        return $this->addr;
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

    private function findUnused(): void
    {
        foreach ($this->pools as $range) {
            if (empty($range)) continue;
            [$this->prefix, $this->len] = explode('/', $range);
            $this->setType();
            if($this->type < 0) continue ; //invalid range
            $this->iterate();
        }
    }

    private function setType(): void
    {
        if(filter_var($this->prefix,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6))
            $this->type = 1 ;
        elseif(filter_var($this->prefix,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))
            $this->type = 0 ;
        else $this->type = -1 ;
    }

    private function is_odd($address): bool
    {
        $s = $this->type == 0 ? '.' : ':';
        $a = explode($s,$address); //address into array
        $last = $a[sizeof($a)-1] ?? '0' ; //last byte or word
        if($this->type == 0 )$last = base_convert($last,10,16);
        $zero = '/^0+$/';
        $ff = '/^[fF]+$/';
        $odd = $this->type == 0
            ? preg_match($zero,$last) || preg_match($ff,$last)
            : preg_match($ff,$last) ;
        return $odd ;
    }

    private function iterate(): void
    {
        $last = $this->gmp_bcast();
        $address = $this->ip2gmp();
        while($address != $last){
            $address = $this->gmp_next($address);
            if($address == $last) break ;
            if($this->excluded($address)) continue; //skip excluded
            $ip = $this->gmp2ip($address);
            if($this->is_odd($ip)) continue ; // skip zeros and xFFFF
            if ($this->db()->ifIpAddressIsUsed($ip)) continue; // skip used
            $this->addr[$this->type] = $ip ;
            break ;
        }
    }

    private function gmp_hosts()
    {
        $len = $this->type == 0 ? 32 :128 ;
        $host_len = $len - $this->len;
        $base = 2;
        return gmp_pow($base, $host_len);
    }

    private function gmp2ip($address)
    {
		var_dump(gmp_strval($address,16));
        return inet_ntop(hex2bin(gmp_strval($address,16)));
    }

    private function gmp_bcast()
    {
        $hosts = $this->gmp_hosts();
        $addr = $this->ip2gmp();
        if($this->type == 0) {
            return gmp_add($addr, gmp_sub($hosts, 1));
        }
        return gmp_add($addr,$hosts);
    }

    private function gmp_next($addr)
    {
        $next = $this->type == 0 ? 1
            : gmp_pow(2,$this->alen);
        return gmp_add($addr,$next);
    }

    private function ip2gmp($address = null)
    {
        $ip = $address ? : $this->prefix ;
        return gmp_init(bin2hex(inet_pton($ip)),16);
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
        return $this->conf->excl_addr
            ? explode(',', $this->conf->excl_addr . ',')
            : [];
    }

    private function db()
    {
        return new API_SQLite();
    }

}
