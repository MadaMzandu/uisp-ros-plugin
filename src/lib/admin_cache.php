<?php
include_once 'lib/_temp.php';
include_once 'lib/api_sqlite.php';

function db()
{
    return new API_SQLite() ;
}

function crm()
{
    return new API_Unms();
}

function devices(): array
{
    return db()->selectAllFromTable('devices') ?? [];
}

function services(): array
{
    $devices = devices();
    $ret = [];
    foreach($devices as $d){
        $svs = db()->selectServicesOnDevice($d['id']);
        $map =[];
        foreach ($svs as $sv){
            $map[$sv['id']] = $sv ;
        }
        $ret[$d['id']] = $map;
    }
    return $ret ;
}

function map($path): array
{
    $crm = crm();
    $crm->assoc = true ;
    $arr = $crm->request($path) ?? [];
    $map = [];
    foreach($arr as $i){
        $map[$i['id']] = $i;
    }
    return $map ;
}

function attributes($array): array
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

function cache_is_valid(): bool
{
    $file = 'data/cache.json';
    if(!file_exists($file)) return false ;
    $c = date('Y-m-d H:i:s',filemtime($file));
    $i = new DateInterval('PT10M'); // 10 minutes
    $mod = date_add(new DateTime($c),$i);
    $now = new DateTime();
    return $mod > $now;
}

function cache()
{
    $services = services();
    $clients = map('/clients');
    $plans = map('/clients/services') ;
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
            $a = attributes($plan['attributes'] ?? []);
            $service['username'] = $a['username'] ?? null ;
            $service['mac'] = $a['mac'] ?? null ;
            $services[$device][$sid] = $service ;
        }
    }
    return $services ;

}

if(cache_is_valid())exit(0);
$conf = db()->readConfig();
$cache = cache();
file_put_contents('data/cache.json',json_encode($cache));



