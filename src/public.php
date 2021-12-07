<?php
// include_once 'includes/cors.php';

chdir(__DIR__);

if(!file_exists('data/data.db'))
{ //check db
    $db = new SQLite3('data/data.db');
    $schema = file_get_contents('includes/schema.sql');
    $done = false;
    if ($db->exec($schema)) {
        $default_conf = file_get_contents('includes/conf.sql');
        $done = $db->exec($default_conf);
    }
    if(!$done){exit();}
}

include_once('lib/api_router.php');
require_once 'vendor/autoload.php';
include_once 'includes/updates.php'; 

if(!version_is_ok())
{ //apply updates
    apply_updates();
}

if(!bak_is_ok()){ // create automatic backup
    create_backup();
}

//run_queue();  // run previously queued data

$json = file_get_contents('php://input') ?? false;

if ($json) { // api mode
    $data = json_decode($json);
    $api = new API_Router($data);
    $api->route();
    echo $api->http_response();
    exit();
}

if (isset($_SERVER['REQUEST_METHOD']) 
        && $_SERVER['REQUEST_METHOD'] == 'OPTIONS') { //skip redirect for options 
    exit();
}

if (isset($_GET['page']) && $_GET['page'] == 'panel') { //config page
    include 'public/panel/index.php';
    exit();
} 