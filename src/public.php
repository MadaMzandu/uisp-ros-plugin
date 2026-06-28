<?php

chdir(__DIR__);
set_time_limit(120);
ini_set('memory_limit', '512M');
//include_once 'includes/cors.php';
require_once 'vendor/autoload.php';
include_once 'lib/api_logger.php';
include_once 'lib/api_common.php';

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode([
            'status' => 'error',
            'error' => true,
            'message' => 'PHP Fatal Error: ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line']
        ]);
    }
});

if (isset($_SERVER['REQUEST_METHOD'])
    && $_SERVER['REQUEST_METHOD'] == 'OPTIONS') { //skip redirect for options
    exit();
}

if(isset($_GET['sync'])){
    include_once "lib/api_ip_mgmt.php";
    exit();
}

$json = file_get_contents('php://input') ?? null;

if(!$json && is_file('test.json')){ // for testing
    $json = file_get_contents('test.json');
}

try
{
    include_once 'lib/api_cache.php';
    include_once 'lib/api_setup.php';

    set_error_handler('myErrorHandler');
    run_setup();
    cache_setup();
    cache_sync();

    include_once 'lib/api_router.php';

    if(isset($_FILES) && !empty($_FILES))
    {//backup restore
        $bak = $_FILES['backup'] ?? null ;
        if($bak){ backup_restore(); }
        header('content-type: application/json');
        echo '{"status":"ok","error":false,"message":"ok","data":[]}';
        exit();
    }

    if(!$json)
    {
        if (isset($_GET['page']) && $_GET['page'] == 'panel')
        { //load new panel_v2
            include 'public/panel_v2/index.php';
        }
        exit();
    }

    $data = json_decode($json);
    
    // --- SECURITY CHECK ---
    $user = null;
    try {
        $sec = \Ubnt\UcrmPluginSdk\Service\UcrmSecurity::create();
        $user = $sec->getUser();
    } catch (\Exception $e) {
        // Ignore exception if security class fails to load or session is invalid
    }
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $server_ip = $_SERVER['SERVER_ADDR'] ?? '';
    // Allow if IP is localhost, server IP, or any private IP (Docker internal networks)
    $isLocal = in_array($ip, ['127.0.0.1', '::1', $server_ip]) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;

    if (!$user && !$isLocal) {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized: Invalid session or non-local webhook']);
        exit;
    }
    // --- END SECURITY CHECK ---

    $api = new ApiRouter($data);
    $api->route();
    $api->http_response();
}
catch ( \Exception | Error $error)
{
    MyLog()->Append(sprintf("Global exception message: %s \nTrace: %s",
        $error->getMessage(),$error->getTraceAsString()));
    
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'error' => true,
        'message' => 'PHP Error: ' . $error->getMessage()
    ]);
}
