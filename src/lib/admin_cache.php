<?php

class Admin_Cache
{
    private $conf ;

    public function create()
    {
        $this->conf = $this->db()->readConfig();
        if($this->cache_is_valid())return ;
        $cache = $this->cache();
        file_put_contents('data/cache.json',json_encode($cache));
    }

    private function db()
    {
        return new ApiSqlite() ;
    }

    private function crm()
    {
        return new ApiUcrm();
    }

    private function devices(): array
    {
        return $this->db()->selectAllFromTable('devices') ?? [];
    }

    private function services(): array
    {
        $devices = $this->devices();
        $ret = [];
        foreach($devices as $d){
            $svs = $this->db()->selectServicesOnDevice($d['id']);
            $map =[];
            foreach ($svs as $sv){
                $map[$sv['id']] = $sv ;
            }
            $ret[$d['id']] = $map;
        }
        return $ret ;
    }

    private function map($path): array
    {
        $crm = $this->crm();
        $crm->assoc = true ;
        $arr = $crm->request($path) ?? [];
        $map = [];
        foreach($arr as $i){
            $map[$i['id']] = $i;
        }
        return $map ;
    }

    private function attributes($array): array
    {
        global $conf ;
        $user = $conf->pppoe_user_attr ;
        $mac = $conf->mac_addr_attr ;
        $ret = [];
        foreach($array as $i){
            if($i['key'] == $user) $ret['username'] = $i['value'];
            if($i['key'] == $mac) $ret['mac'] = $i['value'];
        }
        return $ret ;
    }

    private function cache_is_valid(): bool
    {
        $file = 'data/cache.json';
        if(!file_exists($file)) return false ;
        $c = date('Y-m-d H:i:s',filemtime($file));
        $i = new DateInterval('PT10M'); // 10 minutes
        $mod = date_add(new DateTime($c),$i);
        $now = new DateTime();
        return $mod > $now;
    }

    private function cache()
    {
        $services = $this->services();
        $clients = $this->map('/clients');
        $plans = $this->map('/clients/services') ;
        foreach(array_keys($services) as $device){
            foreach(array_keys($services[$device]) as $sid){
                $service = $services[$device][$sid];
                $client = $clients[$service['clientId']] ?? [];
                $plan = $plans[$service['id']] ?? [];
                $service['lastName'] = $client['lastName'] ?? null;
                $service['firstName'] = $client['firstName'] ?? null ;
                $service['companyName'] = $client['companyName'] ??  null;
                $service['plan'] = $plan['servicePlanName'] ?? null;
                $service['price'] = $plan['price'] ?? null;
                $service['currencyCode'] = $plan['currencyCode'] ?? null;
                $a = $this->attributes($plan['attributes'] ?? []);
                $service['username'] = $a['username'] ?? null ;
                $service['mac'] = $a['mac'] ?? null ;
                $services[$device][$sid] = $service ;
            }
        }
        return $services ;

    }
}





