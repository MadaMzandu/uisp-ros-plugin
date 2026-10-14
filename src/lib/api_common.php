<?php

use Ubnt\UcrmPluginSdk\Service\UcrmSecurity;

include_once 'api_logger.php';

function myErrorHandler($errno, $errstr, $errfile, $errline){
    if (!(error_reporting() & $errno)) return false;
    MyLog()->Append(sprintf('error no: %s error: %s file %s line: %s',
        $errno,$errstr,$errfile,$errline),LOG_ERR);
    return false;
}

function dbUpdateHandler(){
    MyLog()->Append('Update chunk rejected - this is normal');
}

/**
 * @throws \GuzzleHttp\Exception\GuzzleException
 */
function backup_restore(){
    require_once __DIR__ . '/api_backup.php';
    ApiBackup::restoreUpload($_FILES['backup'] ?? []);
}

function fail($event, $data = [])
{
    MyLog()->Append(['event' => $event, 'data' => $data], LOG_ERR);
    throw new RuntimeException($event);
}

function bail($event, $data = [])
{
    MyLog()->Append(['event' => $event, 'data' => $data], LOG_ERR);
    throw new InvalidArgumentException($event);
}

function respond($msg,$err = false,$data = [], array $metadata = [])
{
    if (defined('JSON_RESPOND_SENT')) return;
    define('JSON_RESPOND_SENT', true);
    $status = $err ? 'failed' : 'ok';
    $response = [
        'status' => $status,
        'error' => $err,
        'message' => $msg,
        'data' => $data,
    ];
    if (isset($metadata['recovery'])) $response['recovery'] = $metadata['recovery'];
    if(!headers_sent()) header('content-type: application/json');
    if ($err && !headers_sent()) { // failed header
        if (http_response_code() < 400) http_response_code(400);
    }
    $json = json_encode($response, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    MyLog()->Append('Outputting JSON length: ' . strlen($json));
    echo $json;
    if(ob_get_level() > 0) ob_flush();
    flush();
    if(session_id()) session_write_close();
}
