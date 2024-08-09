<?php

use Ubnt\UcrmPluginSdk\Service\UcrmSecurity;

include_once 'api_logger.php';

function myErrorHandler($errno, $errstr, $errfile, $errline){
    MyLog()->Append(sprintf('error no: %s error: %s file %s line: %s',
        $errno,$errstr,$errfile,$errline),7);
}

function dbUpdateHandler(){
    MyLog()->Append('Update chunk rejected - this is normal');
}

/**
 * @throws \GuzzleHttp\Exception\GuzzleException
 */
function backup_restore(){
    try{
        $sec = UcrmSecurity::create();
        $user = $sec->getUser();
        if(!$user || $user->isClient){ return; }
        $bu = new ApiBackup();
        if($bu->run()){
            $ul = $_FILES['backup']['tmp_name'] ?? null;
            if($ul){ copy($ul,'data/data.db'); }
        }
    }
    catch (\Exception $e){
        $msg = sprintf('backup restore error: %s, trace: %s',
            $e->getMessage(),$e->getTraceAsString());
        MyLog()->Append($msg,6);
        echo sprintf('{"status":"failed","error":true,"message":%s,"data":[]}',$msg);
    }
}

function fail($event, $data = [])
{
    MyLog()->Append("Fail: $event data: ". json_encode($data));
    respond($event,false,[]);
    exit();
}

function bail($event, $data = [])
{
    MyLog()->Append("Fail: $event data: ". json_encode($data));
    respond($event,false,[]);
    exit();
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
    if(!headers_sent()) header('content-type: application/json');
    if ($err && !headers_sent()) { // failed header
        header('X-API-Response: 202', true, 202);
    }
    echo json_encode($response,JSON_PRETTY_PRINT);
}
