<?php

include_once 'lib/api_sqlite.php';
include_once 'lib/admin.php';
include_once 'lib/admin_backup.php';

$version = '1.8.1b';
$conf = db()->readConfig();

$conf_updates = json_decode(
    file_get_contents('includes/conf_updates.json'),true);

function apply_updates() {
   return table_updates() && conf_updates();
}

function conf_updates()
{
    global $conf, $conf_updates,$version;
    foreach (array_keys($conf_updates) as $key) {
        if (!isset($conf->$key)) {
            db()->insert((object)['key' => $key,
                'value' => $conf_updates[$key]],'config');
        }
    }
    $conf->version = $version;
    db()->saveConfig($conf);
}

function table_updates(){
    $file = file_get_contents('includes/tables.sql');
    return db()->exec($file);
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
    global $version, $conf;
    if (!isset($conf->version) || $version > $conf->version) {
        return false;
    }
    return true;
}

function run_queue()
{
    $file = 'data/queue.json';
    if(!file_exists($file)) {
        touch($file);
        file_put_contents($file,json_encode([]));
    }
    $q = json_decode(file_get_contents($file));
    foreach($q as $item){
        $s = new Service($item);
        if($s->ready) {
            $s->queued = true;
            $m = new MT_Account($s);
            $action = $s->action;
            $m->$action();
        }
    }
}
