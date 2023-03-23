<?php
chdir(__DIR__);
//include_once 'includes/cors.php';

if (isset($_SERVER['REQUEST_METHOD'])
    && $_SERVER['REQUEST_METHOD'] == 'OPTIONS') { //skip redirect for options
    exit();
}

require_once 'vendor/autoload.php';
include_once 'lib/api_sqlite.php';
include_once 'lib/api_common.php';
include_once 'includes/api_setup.php';


//if(user_not_ok()){
//    $status = [
//        'status' => 'failed',
//        'error' => true,
//        'message' => 'User is not authenticated',
//        'data' => []
//    ];
//    exit(json_encode($status));
//}

$json = file_get_contents('php://input') ?? null;

try
{
    set_error_handler('myErrorHandler');
    run_setup();

    include_once 'lib/api_router.php';

    if(!$json)
    {
        if (isset($_GET['page']) && $_GET['page'] == 'panel')
        { //load panel
            include 'public/panel/index.php';
        }
        exit();
    }

    $data = json_decode($json);
    $api = new API_Router($data);
    $api->route();
    $api->http_response();
}
catch (
Exception
| Error
| \GuzzleHttp\Exception\GuzzleException
| \Ubnt\UcrmPluginSdk\Exception\ConfigurationException
| \Ubnt\UcrmPluginSdk\Exception\InvalidPluginRootPathException
| \Ubnt\UcrmPluginSdk\Exception\JsonException $err){
    MyLog()->appendLog('api: '.$err->getMessage().' request: '.$json);
    respond($err->getMessage(),true);
}
