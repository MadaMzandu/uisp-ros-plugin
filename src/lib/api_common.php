<?php
include_once 'api_logger.php';
include_once 'admin_backup.php';

function myErrorHandler($errno, $errstr, $errfile, $errline){
    MyLog()->Append(sprintf('error no: %s error: %s',$errno,$errstr),6);
}

function backup_restore(){
    try{
        $sec = \Ubnt\UcrmPluginSdk\Service\UcrmSecurity::create();
        $user = $sec->getUser();
        if(!$user || $user->isClient){ return; }
        $bu = new Admin_Backup();
        if($bu->run()){
            $ul = $_FILES['backup']['tmp_name'] ?? null;
            if($ul){ copy($ul,'data/data.db'); }
        }
    }
    catch (\Exception $e){
        $msg = sprintf('backup restore error: %s, trace: %s',
            $e->getMessage(),$e->getTraceAsString());
        MyLog()->Append($msg,6);
        header('content-type: application/json');
        echo sprintf('{"status":"failed","error":true,"message":%s,"data":[]}',$msg);
    }
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
