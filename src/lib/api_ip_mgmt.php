<?php
include_once "api_ucrm.php";
include_once "api_sqlite.php";

function find_attr($nk): ?array
{
    $api = new ApiSqlite();
    $c = $api->readConfig() ;
    $k = $c->$nk ?? null ; //native key to user key
    if(!$k){ return null; }
    $a = get_attrs() ;
    return $a[$k] ?? null ;
}

function get_attrs(): array
{
    $api = new ApiUcrm();
    $api->assoc = true ;
    $r = $api->get('custom-attributes');
    $m = [];
    foreach($r as $a){ //map by key
        $m[$a['key']] = $a ;
    }
    return $m;
}

function get_ips(): array
{
    $api = new ApiSqlite();
    $s = "SELECT id,address from network";
    $r = $api->selectCustom($s) ;
    $m = [];
    foreach ($r as $i){
        $m[$i['id']] = $i['address'] ;
    }
    return $m;
}

$fn = 'data/config.json';
$config = is_file($fn) ? json_decode(file_get_contents($fn),true) : [];
$hour = (int) ($config['syncAttrHour'] ?? '-1');
$date = $config['syncAttrDate'] ?? '2025-01-01';
$now = date('Y-m-d');
$curr = (int) date('G');
if($hour != $curr || $date == $now){
    return  ;
}

$attr = find_attr('ip_addr_attr') ;
if(!$attr){ return ; }
$attrid = $attr['id'] ;
$ips = get_ips() ;
$api = new ApiUcrm() ;
foreach($ips as $id => $addr){
    $ud = [['customAttributeId' => $attrid,'value' => $addr]];
    $api->patch("clients/services/$id",['attributes' => $ud]);
}
$config['syncAttrDate'] = $now;
file_put_contents($fn,json_encode($config,128));