<?php

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: *');
header('Access-Control-Max-Age: 1000');
header('Access-Control-Allow-Headers: Content-Type, Content-Range, Content-Disposition, Content-Description');
chdir(__DIR__);

if(!file_exists('data/data.db')){ //check db
    $db = new SQLite3('data/data.db');
    $schema = file_get_contents('includes/schema.sql') ?? null;
    $done = false;
    if ($db->exec($schema)) {
        $default_conf = file_get_contents('includes/conf.sql');
        $done = $db->exec($default_conf);
    }
    if(!$done){exit();}
}
// valid db required to proceed

include_once 'includes/updates.php'; 

if(!version_is_ok()){ //apply updates
    apply_updates();
}

if(!bak_is_ok()){ // create automatic backup
    create_backup();
}

include_once('lib/api_router.php');
require_once 'vendor/autoload.php';


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
    // header('Location: public/panel/index.html');
    include 'public/panel/index.html';
    exit();
} 