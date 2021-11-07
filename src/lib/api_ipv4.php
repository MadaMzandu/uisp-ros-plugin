<?php

include_once 'api_sqlite.php';

class API_IPv4
{

    private $addr; //assign
    private $prefix;
    private $len;
    private $pool; // active configured pool
    private $conf;

    public function __construct()
    {
        $this->conf = (new API_SQLite())->readConfig();
    }

    public function assign($device = false): ?string
    {
        $pool = $device
            ? $device->pool
            : $this->conf->ppp_pool;
        if ($pool) {
            $this->pool = explode(',', $pool . ',');
            $this->findUnused();
        }
        return $this->addr;
    }

    private function findUnused(): void
    {
        foreach ($this->pool as $range) {
            if (empty($range)) continue;
            [$this->prefix, $this->len] = explode('/', $range);
            $this->iteratePool();
        }
    }

    private function iteratePool(): void
    {
        $hosts = $this->hosts();
        $net = ip2long($this->network()); //net_number2dec
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
            if ($this->db()->ifIpAddressIsUsed($addr)) { //skip if already assigned
                continue;
            }
            $this->addr = $addr;
            return;
        }
    }

    private function hosts(): float
    {
        $host_len = 32 - $this->len;
        $base = 2;
        return pow($base, $host_len);
    }

    private function network(): string
    { //ok here we go
        $ip = decbin(ip2long($this->prefix)); //ip2bin
        $mask = decbin(ip2long(long2ip(-1 << (32 - $this->len)))); //len2netmask2bin
        $net = $ip & $mask;
        return long2ip(bindec($net)); //back2ip
    }

    private function exclusions(): array
    {
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

    private function readExclusions(): array
    {
        return $this->conf->excl_addr
            ? explode(',', $this->conf->excl_addr . ',')
            : [];
    }

    private function explodeRange($start, $end): array
    {
        $addresses = [];
        $s = ip2long($start);
        $e = ip2long($end);
        for ($i = $s; $i < $e + 1; $i++) {
            $addresses[] = $i;
        }
        return $addresses;
    }

    private function db()
    {
        return new API_SQLite();
    }

}
