<?php

$version = '1.8.4d';
$conf = db()->readConfig();
$current = $conf->version ?? '1.0.0';

$conf_updates = json_decode(
    file_get_contents('includes/conf_updates.json'),true);

function apply_updates(): bool
{
   return table_updates()
       && conf_updates()
       && remove_jobs()
       && rebuild() ;
}


function rebuild(): bool
{
    global $current ;
    if($current < '1.8.2c'){
        $disable['disable_contention'] = true;
        $enable['disable_contention'] = false;
        return (new Settings($disable))->edit()
            && (new Settings($enable))->edit();
    }
    return true ;
}

function remove_jobs(){
    global $current ;
    $file = 'data/queue.json';
    if($current < '1.8.3c'){
        if(file_exists($file)){
            return file_put_contents($file,null);
        }
    }
    return true ;
}

function conf_updates(): bool
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

function table_updates(): bool
{
    global $current ;
    if($current < '1.8.5') {
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

function create_backup()
{
    $data = false ;
    return (new Admin_Backup($data))->run();
}

function bak_not_ok(): bool
{
    if(!file_exists('data/.last_backup')){return false;}
    $file = file_get_contents('data/.last_backup');
    $last = explode(',', $file . ",2000-01-01 00:00:00")[1] ;
    $now = new DateTime();
    $interval = new DateInterval('P1D');
    $next = (new DateTime($last))->add($interval);
    return $now >= $next ;
}

function version_not_ok(): bool
{
    global $version, $current;
    return $version > $current ;
}

function user_not_ok(): bool
{
    return false ;
    $security = \Ubnt\UcrmPluginSdk\Service\UcrmSecurity::create();
    $user = $security->getUser();
    return !$user || $user->isClient ;
}
