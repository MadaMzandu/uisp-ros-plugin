<?php

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: *');
header('Access-Control-Max-Age: 1000');
header('Access-Control-Allow-Headers: Content-Type, Content-Range, Content-Disposition, Content-Description');
chdir(__DIR__);

include_once 'includes/updates.php';

if(!db_is_ok()){ //restore db with defaults
    if(!create_db()){exit();}
}

if(!version_is_ok()){ //apply updates
    apply_updates();
}

include_once('lib/app_router.php');
require_once 'vendor/autoload.php';

$json = file_get_contents('php://input') ?? false;

if ($json) { // api mode
    $data = json_decode($json);
    $api = new CS_Router($data);
    $api->route();
    echo $api->http_response();
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { //skip redirect for options 
    exit();
}

if (isset($_GET['page']) && $_GET['page'] == 'panel') { //config page
    header('Location: public/panel/index.html');
    exit();
} 