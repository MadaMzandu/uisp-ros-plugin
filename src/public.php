<?php
//include_once 'includes/cors.php';

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

if(user_not_ok()){
    $status = [
        'status' => 'failed',
        'error' => true,
        'message' => 'User is not authenticated',
        'data' => []
    ];
    exit(json_encode($status));
}

if(version_not_ok())
{ //apply updates
    apply_updates();
}

if(bak_not_ok()){ // create automatic backup
    create_backup();
}

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