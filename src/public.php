<?php

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: *');
header('Access-Control-Max-Age: 1000');
header('Access-Control-Allow-Headers: Content-Type, Content-Range, Content-Disposition, Content-Description');
chdir(__DIR__);

// restore default config if no config is found
if(!file_exists('data/data.db')){
    $db = new SQLite3('data/data.db');
    $schema = file_get_contents('includes/schema.sql');
    $done = false ;
    if($db->exec($schema)){
        $conf = file_get_contents('includes/conf.sql');
        $done = $db->exec($conf);
    }
    if(!$done) exit();
}

include_once('lib/app_router.php');
require_once 'vendor/autoload.php';

$json = file_get_contents('php://input') ?? false;

if ($json) { // api mode
    $data = json_decode($json);
    $api = new API_Router($data);
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