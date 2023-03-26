<?php
chdir(__DIR__);
include_once 'includes/cors.php';

if (isset($_SERVER['REQUEST_METHOD'])
    && $_SERVER['REQUEST_METHOD'] == 'OPTIONS') { //skip redirect for options
    exit();
}

require_once 'vendor/autoload.php';
include_once 'lib/api_sqlite.php';
include_once 'lib/api_cache.php';
include_once 'includes/api_setup.php';
include_once 'lib/api_common.php';
include_once 'lib/api_logger.php';


$json = file_get_contents('php://input') ?? null;

try
{
    set_error_handler('myErrorHandler');
    run_setup();
    MyLog()->Append('public: setup completed');

    include_once 'lib/api_router.php';

    if(!$json)
    {
        MyLog()->Append('public: begin page load param '. json_encode($_GET ?? '{}'));
        if (isset($_GET['page']) && $_GET['page'] == 'panel')
        { //load panel
            include 'public/panel/index.php';
        }
        exit();
    }

    MyLog()->Append('public: begin api request: '.$json);
    $data = json_decode($json);
    $api = new API_Router($data);
    $api->route();
    $api->http_response();
    MyLog()->Append('api: finished without error : '
        . json_encode($api->status()));
}
catch (
Exception
| Error
| \GuzzleHttp\Exception\GuzzleException
| \Ubnt\UcrmPluginSdk\Exception\ConfigurationException
| \Ubnt\UcrmPluginSdk\Exception\InvalidPluginRootPathException
| \Ubnt\UcrmPluginSdk\Exception\JsonException $err){
    MyLog()->Append('exeption triggered: '.$err->getMessage().' request: '.$json);
    respond($err->getMessage(),true);
}
MyLog()->Append('public: begin cache sync');

run_cache($json);
