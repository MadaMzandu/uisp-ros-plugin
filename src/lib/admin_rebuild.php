<?php
include_once 'lib/_temp.php';
include_once 'lib/api_sqlite.php';
include_once 'lib/timer.php';

function db()
{
    return new API_SQLite() ;
}

function clear_cache():bool
{
    return db()->deleteAll('services');
}

function crm()
{
    $c = new API_Unms();
    $c->assoc = true ;
    return $c;
}

function filter($services): array
{
    global $conf ;
    $dn = $conf->device_name_attr ?? 'deviceName';
    $result = [];
    foreach ($services as $s){
        $attrs = $s['attributes'] ?? [];
        foreach($attrs as $a){
            if($a['key'] == $dn && $a['value']) $result[] = $s;
        }
    }
    return $result;
}

function find_attr(): ?int
{
    global $conf;
    $dn = $conf->device_name_attr ?? 'deviceName';
    $attrs = crm()->request('/custom-attributes') ?? [];
    foreach ($attrs as $a){
        if($a['key'] == $dn) return $a['id'];
    }
    return null ;
}

function send_triggers():void
{
    $t = new Timer();
    //$id = find_attr();
    $opts = null ;
    //if($id) $opts = '?customAttributeId=' . $id . '&customAttributeValue="Test2"';
    $result = crm()->request('/clients/services' . $opts) ?? [];
    $services = filter($result) ;
    $t->end('Fetch and Filter');
    if($services && clear_cache()) {
        $url = '/clients/services/';
        file_put_contents('data/cache.json',null); //reset cache
        file_put_contents('data/queue.json',null); // reset queue
        foreach ($services as $item) {
            $data = ['note' => $item['note']];
            crm()->request($url . $item['id'], 'PATCH', $data);
        }
    }
    $t->end('Send 1940 Triggers');
}

$conf = db()->readConfig();
send_triggers();