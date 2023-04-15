<?php
include_once 'api_logger.php';

function myErrorHandler($errno, $errstr, $errfile, $errline){
    MyLog()->Append(sprintf('error no: %s error: %s',$errno,$errstr),6);
}

function respond($msg,$err = false,$data = [])
{
    $status = $err ? 'failed' : 'ok';
    $response = [
        'status' => $status,
        'error' => $err,
        'message' => $msg,
        'data' => $data,
    ];
    header('content-type: application/json');
    if ($err) { // failed header
        header('X-API-Response: 202', true, 202);
    }
    echo json_encode($response,JSON_PRETTY_PRINT);
}
