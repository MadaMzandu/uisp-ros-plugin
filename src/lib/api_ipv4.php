<?php

include_once 'api_sqlite.php';

Class API_IPv4 {

    private $addr; //assign
    private $prefix;
    private $len;
    private $pool; // active configured pool

    public function assign($device = false) {
        global $conf ;
        $pool = $device 
                ? $device->pool  
                : $conf->ppp_pool ;
        $pool .= ','; // just in case
        if ($pool) {
            $this->pool = explode(',', $pool);
            return $this->findUnused() ? $this->addr : false ;
        }
        return false;
    }
    
    private function readExclusions(){
        global $conf ;
        return $conf->excl_addr 
                ? explode(',',$conf->excl_addr.',') 
                :[] ;
    }
    
    private function findUnused() {
        foreach ($this->pool as $range) {
            [$this->prefix, $this->len] = explode('/', $range);
            if ($this->iteratePool()) {
                return true;
            }
        }
        return false;
    }

    private function exclusions() {
        $read = $this->readExclusions();
        $exclusions = [];
        foreach ($read as $range) {
            $range .= '-';              //append hyphen incase of single addr entry
            [$start, $end] = explode('-', $range);
            if (!$end) {
                $end = $start;
            }
            //$end = str_replace('-', '', $last); //remove hyphen now useless
            if (filter_var($start, FILTER_VALIDATE_IP) &&
                    filter_var($end, FILTER_VALIDATE_IP)) {
                $exclusions = array_merge($exclusions, $this->explodeRange($start, $end));
            }
        }
        return $exclusions;
    }

    private function explodeRange($start, $end) {
        $addresses = [];
        $s = ip2long($start);
        $e = ip2long($end);
        for ($i = $s; $i < $e + 1; $i++) {
            $addresses[] = $i;
        }
        return $addresses;
    }

    private function iteratePool() {
        $hosts = $this->hosts();
        $net = ip2long($this->network()); //net_number2dec
        $db = new API_SQLite();
        $exclusions = $this->exclusions();
        for ($i = $net + 1; $i < $net + $hosts - 1; $i++) {
            if (in_array($i, $exclusions)) {  //skip if listed as exclusion
                continue;
            }
            $addr = long2ip($i);
            $lastoct = explode('.', $addr)[3];
            if ($lastoct < 1 || $lastoct > 254) { // skip zeros and 255s
                continue;
            }
            if ($db->ifIpAddressIsUsed($addr)) { //skip if already assigned
                continue;
            }
            $this->addr = $addr;
            return true;
        }
        return false;
    }

    private function network() { //ok here we go
        $ip = decbin(ip2long($this->prefix)); //ip2bin
        $mask = decbin(ip2long(long2ip(-1 << (32 - $this->len)))); //len2netmask2bin
        $net = $ip & $mask;
        return long2ip(bindec($net)); //back2ip
    }

    private function hosts() {
        $host_len = 32 - $this->len;
        $base = 2;
        return pow($base, $host_len);
    }

}
