<?php

include_once 'lib/app_sqlite.php';
include_once 'lib/admin.php';
include_once 'lib/admin_backup.php';

$version = '1.8.0';
$conf = (new CS_SQLite())->readConfig();

$conf_updates = [//defaults
    'version' => '1.8.0',
    'disabled_rate' => 1,
];

function apply_updates() {
    global $conf, $conf_updates;
    $data = [];
    foreach (array_keys($conf_updates) as $key) {
        if (isset($conf->$key)) {
            continue;
        }
        $data[] = ['key' => $key, 'value' => $conf_updates[$key]];
    }
    return (new CS_SQLite())->insertMultiple($data, 'config');
}

function create_backup(){
    $data = false ;
    return (new Backup($data))->backup();
}

function bak_is_ok() {
    if(!file_exists('data/.last_backup')){return false;}
    $file = file_get_contents('data/.last_backup');
    $date = $file ? explode(',', $file . ",2000-01-01 00:00:00")[1] : '2000-01-01 00:00:00';
    $last = new DateTime($date);
    $now = new DateTime();
    $interval = new DateInterval('P1D');
    $last->add($interval);
    if ($last < $now) {
       return false ;
    }
    return true ;
}

function version_is_ok() {
    global $version, $conf;
    if (!isset($conf->version) || $version > $conf->version) {
        return false;
    }
    return true;
}
