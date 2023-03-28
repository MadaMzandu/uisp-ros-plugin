<?php


chdir(__DIR__);
include_once 'includes/cors.php';

if (isset($_SERVER['REQUEST_METHOD'])
    && $_SERVER['REQUEST_METHOD'] == 'OPTIONS') { //skip redirect for options
    exit();
}


$json = file_get_contents('php://input') ?? null;

try
{
    require_once 'vendor/autoload.php';
    include_once 'lib/api_logger.php';
    include_once 'lib/api_cache.php';
    include_once 'lib/api_setup.php';
    include_once 'lib/api_common.php';

    set_error_handler('myErrorHandler');
    run_setup();
    cache_setup();exit();

    include_once 'lib/api_router.php';

    MyLog()->Append('public: setup completed');

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
    MyLog()->Append('api: finished without error : ' . json_encode($api->status()));
    MyLog()->Append('public: begin cache sync');
    cache_sync($json);
}
catch (
Exception
| Error
| GuzzleHttp\Exception\GuzzleException
| \Ubnt\UcrmPluginSdk\Exception\ConfigurationException
| \Ubnt\UcrmPluginSdk\Exception\InvalidPluginRootPathException
| \Ubnt\UcrmPluginSdk\Exception\JsonException $err){
    MyLog()->Append('exception triggered: '.$err->getMessage().' request: '.$json);
    respond($err->getMessage(),true);
}



