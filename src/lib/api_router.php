<?php

include_once 'api_sqlite.php';
include_once 'api_logger.php' ;
include_once 'admin.php';
include_once 'api_action.php';

$conf = (new ApiSqlite())->readConfig();


class API_Router
{

    private $data;
    private $status;
    private $result;

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
        MyLog()->Append('router: begin route selection');
        if (!$this->data_is_valid()) { // check basic validity
            MyLog()->Append('router: request is not valid '.json_encode($this->status));
            return;
        }
        if ($this->request_is_admin()) { // execute admin calls
            MyLog()->Append('router: selected admin api');
            return;
        }
        MyLog()->Append('router: begin device provisioning');
        $api = new ApiAction($this->data);
        $api->submit();
        MyLog()->Append('router: routing completed');
    }

    private function data_is_valid(): bool
    {
        if (empty((array)$this->data)) {
            $this->set_message('No request data sent');
            MyLog()->Append('route empty request');
            return false;
        }
        $entity = $this->data->entity ?? null ;
        if ($entity && !in_array($entity,['service','admin','client'])) {
            $this->set_message('ok');
            MyLog()->Append('route wrong entity type: '.$entity);
            return false;
        }
        $change = $this->data->changeType ?? 'none';
        if($entity == 'admin') $change = 'admin';
        if (!in_array($change, ['insert', 'edit', 'end',
                'suspend', 'unsuspend', 'admin','move','delete','rename'])) {
            $this->set_message('ok');
            MyLog()->Append('route wrong entity action: '.$change);
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
