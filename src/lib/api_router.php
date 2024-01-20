<?php

include_once 'api_sqlite.php';
include_once 'api_logger.php' ;
include_once 'admin.php';
include_once 'api_action.php';

$conf = mySqlite()->readConfig();


class API_Router
{

    private ?object $data = null ;
    private ?object $status = null ;
    private mixed $result = null ;

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
        $api = match ($type){
            'admin' => new Admin(),
            'none' => new  stdClass(),
             default => new ApiAction()
        };
        $api?->exec($this->data);
        $this->status = $api?->status();
        $this->result = $api?->result();
    }

    private function data_check(): bool
    {
        if (!is_object($this->data)) {
            $this->set_message('ok');
            return false;
        }
        $entity = $this->data->entity ?? null ;
        if ($entity && !in_array($entity,['service','client'])) { //webhooks only
            $this->set_message("ignoring entity: $entity");
            return false;
        }
        $change = $this->data->changeType ?? 'none';
        $allowed = "insert,edit,admin";
        if (!in_array($change, explode(',',$allowed))) {
            $this->set_message("ignoring change type: $change");
            return false;
        }
        return true;
    }

    private function set_message($msg): void
    {
        $this->status->error = false;
        $this->status->message = $msg;
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
