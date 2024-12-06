<?php

include_once 'api_sqlite.php';
include_once 'api_logger.php' ;
include_once 'api_action.php';
include_once 'api_update.php';
include_once 'api_list.php';

$conf = mySqlite()->readConfig();


class ApiRouter
{

    private ?object $data;
    private ?object $status;
    private $result;

    public function __construct($data)
    {
        $this->data = $this->toObject($data);
        $this->result = [];
        $this->status = json_decode('{"status":"ok","error":false,"message":"ok","session":false}');
    }

    private function toObject($data): ?object
    {
        $to = $data ? json_decode(json_encode($data)) : null ;
        return is_object($to) ? $to : null ;
    }

    public function status(): ?stdClass
    { //only for testing
        return $this->status;
    }

    public function route(): void
    {
        if (!$this->data_check()) { // check basic validity
            fail('request_invalid',$this->data);
        }
        $type = $this->data->changeType
            ?? 'none';
        $target = $this->data->target ?? 'none';
        //$api = null;
        switch ($type){
            case 'list': $api = new ApiList($this->data,$target); break;
            case 'update': $api = new ApiUpdate($this->data,$target); break ;
            case 'none': $api = new  stdClass(); break ;
            default: $api = new ApiAction();
        }
        $api->exec($this->data);
        $this->status = $api->status();
        $this->result = $api->result();
    }

    private function data_check(): bool
    {
        if (!is_object($this->data)) {
            bail('request_empty');
        }
        $entity = $this->data->entity ?? null ;
        if ($entity && !in_array($entity,['service','client'])) { //webhooks only
            bail('entity_unsupported: ' .$entity);
        }
        $change = $this->data->changeType ?? 'none';
        $allowed = "insert,edit,admin,data,update,list";
        if (!in_array($change, explode(',',$allowed))) {
            bail('change_unsupported: ' .$change);
        }
        return true;
    }

    public function http_response(): void
    {
        if(!headers_sent())header('content-type: application/json');
        $stat = 'ok';
        $error = $this->status->error ?? false ;
        if ($error) { // failed response
            if(!headers_sent()) header('X-API-Response: 202', true, 202);
            $stat = 'failed';
        }
        $response = [
            'status' => $stat,
            'error' => $error,
            'message' => $this->status->message ?? ($error ? 'error_unknown' : 'ok'),
            'data' => $this->result ?? [],
        ];
        echo json_encode($response,JSON_PRETTY_PRINT);
    }

}
