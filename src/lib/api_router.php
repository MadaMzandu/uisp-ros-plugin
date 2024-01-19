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

    private function toObject($data): ?stdClass
    {
        if(empty($data)) return null ;
        if(is_object($data)) return $data ;
        if(is_array($data)) return json_decode(json_encode((object)$data));
        return null;
    }

    public function status(): ?stdClass
    { //only for testing
        return $this->status;
    }

    public function route(): void
    {
        if (!$this->data_is_valid()) { // check basic validity
            fail('request_invalid',$this->data);
        }
        if ($this->request_is_admin()) { // execute admin calls
            return;
        }
        $api = new ApiAction($this->data);
        $api->submit();
    }

    private function data_is_valid(): bool
    {
        if (empty((array)$this->data)) {
            $this->set_message('No request data sent');
            return false;
        }
        $entity = $this->data->entity ?? null ;
        if ($entity && !in_array($entity,['service','admin','client'])) {
            $this->set_message('ok');
            return false;
        }
        $change = $this->data->changeType ?? 'none';
        if($entity == 'admin') $change = 'admin';
        if (!in_array($change, ['insert', 'edit', 'end',
                'suspend', 'unsuspend', 'admin','move','delete','rename'])) {
            $this->set_message('ok');
            return false;
        }
        return true;
    }

    private function set_message($msg): void
    {
        $this->status->error = false;
        $this->status->message = $msg;
    }

    private function request_is_admin(): bool
    {
        if ($this->data->changeType != 'admin') {
            return false;
        }
        $admin = new Admin($this->data);
        $admin->exec();
        $this->status = $admin->status();
        $this->result = $admin->result();
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
            'message' => $this->status->message ?? 'Unknown error',
            'duration' => $this->status->duration ?? 0,
            'data' => $this->result ?? [],
        ];
        echo json_encode($response,JSON_PRETTY_PRINT);
    }

}
