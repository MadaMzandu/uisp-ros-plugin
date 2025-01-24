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

function prn($st,$p = '0'){
    $dbg = $_GET['sync'] ?? false;
    if(!$dbg){ return; }
    if(!$p){
        $st = "<div>$st</div>";
    }
    echo $st ;
}

prn("<html lang=''><body><title>Sync Debug</title>",1);

try{
    $fn = 'data/config.json';
    $config = is_file($fn) ? json_decode(file_get_contents($fn),true) : [];
    $hour = (int) ($config['syncAttrHour'] ?? '-1');
    prn("configured sync hour is $hour");
    $date = $config['syncAttrDate'] ?? '2025-01-01';
    prn("last sync date was $date");
    $now = date('Y-m-d');
    $curr = (int) date('G');
    $dbg = $_GET['sync'] ?? null;
    if(!$dbg && ($hour != $curr || $date == $now)){
        return  ;
    }

    $attr = find_attr('ip_addr_attr') ;
    if(!$attr){ return ; }
    $attrname = $attr['name'];
    $attrid = $attr['id'] ;
    prn("Attribute is #$attrname# id $attrid");
    $ips = get_ips() ;
    $qty = sizeof($ips);
    prn("Found $qty ip addresses");
    $api = new ApiUcrm() ;
    foreach($ips as $id => $addr){
        $ud = [['customAttributeId' => $attrid,'value' => $addr]];
        prn("Setting ip address $addr for service $id");
        $api->patch("clients/services/$id",['attributes' => $ud]);
    }
    $config['syncAttrDate'] = $now;
    file_put_contents($fn,json_encode($config,128));
}
catch (Exception $e){
    $err = $e->getMessage() . $e->getTraceAsString() ;
    prn($err);
    prn("</body></html>",1);
}
prn("Completed!");
prn("</body></html>",1);