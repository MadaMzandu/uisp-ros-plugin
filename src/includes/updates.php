<?php

$version = '1.8.2a';
$conf = db()->readConfig();
$current = $conf->version ?? '1.0.0';

$conf_updates = json_decode(
    file_get_contents('includes/conf_updates.json'),true);

function apply_updates() {
   return table_updates()
       && conf_updates()
       && rebuild();
}

function rebuild()
{
    global $current ;
    if($current < '1.8.2b'){
        $data =[];
        return (new Admin_System($data))->rebuild();
    }
    return true ;
}

function conf_updates()
{
    global $conf, $conf_updates,$version;
    $ret = true ;
    foreach (array_keys($conf_updates) as $key) {
        if (!isset($conf->$key)) {
            $ret &= db()->insert((object)['key' => $key,
                'value' => $conf_updates[$key]],'config');
        }
    }
    return $ret && db()->setVersion($version);
}

function table_updates(){
    global $current ;
    if($current < '1.8.1') {
        $file = file_get_contents('includes/tables.sql');
        return db()->exec($file);
    }
    return true ;
}

function db():?API_SQLite
{
    try{
        return new API_SQLite();
    }catch (Exception $e){
        return null;
    }
}

function create_backup(){
    $data = false ;
    return (new Admin_Backup($data))->run();
}

function bak_is_ok() {
    if(!file_exists('data/.last_backup')){return false;}
    $file = file_get_contents('data/.last_backup');
    $last = explode(',', $file . ",2000-01-01 00:00:00")[1] ;
    $now = new DateTime();
    $interval = new DateInterval('P1D');
    $next = (new DateTime($last))->add($interval);
    return $next > $now ;
}

function version_is_ok() {
    global $version, $current;
    $test = $current >= $version ;
    return $test ;
}

function run_queue()
{
    $file = 'data/queue.json';
    if(!file_exists($file)) {
        touch($file);
        file_put_contents($file,json_encode([]));
    }
    $q = json_decode(file_get_contents($file)) ?? [];
    foreach($q as $item){
        $s = new Service($item->data);
        if($s->ready) {
            $s->queued = true;
            $m = new MT_Account($s);
            $action = $s->action;
            $m->$action();
            if(!$m->status()->error){
                unset($q->{$item->data->entityId});
            }
        }
    }
    $write = json_encode($q) ?? "[]";
    file_put_contents($file,$write);
}
