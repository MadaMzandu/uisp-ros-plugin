<?php
include_once 'lib/app_sqlite.php';

$version = '1.8.1';
$conf = (new CS_SQLite())->readConfig();

$conf_updates  = [ //defaults
    'version' => '1.8.1',  
    'disabled_rate' => 1,
];

function create_db(){
    $db = new SQLite3('data/data.db');
    $schema = file_get_contents('includes/schema.sql');
    if($db->exec($schema)){
        $default_conf = file_get_contents('includes/conf.sql');
        return $db->exec($default_conf);
    }
    return false ;
}

function apply_updates(){
    global $conf,$conf_updates;
    $data=[];
    foreach(array_keys($conf_updates) as $key){
        if(isset($conf->$key)){continue;}
        $data[] = ['key' => $key, 'value' => $conf_updates[$key]];
    }
    return (new CS_SQLite())->insertMultiple($data,'config');
}

function db_is_ok(){
    return file_exists('data/data.db');
}

function version_is_ok(){
    global $version,$conf;
    if(!isset($conf->version) || $version > $conf->version){
        return false ;
    }
    return true ;
}

