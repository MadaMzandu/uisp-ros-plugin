<?php

include_once 'api_sqlite.php';
include_once 'api_logger.php' ;
include_once 'admin.php';
include_once 'api_routes.php';
include_once 'service.php';

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
        $this->status = (object)['status' => 'ok', 'message' => '', 'session' => false];
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
            MyLog()->Append('router: request is not valid');
            return;
        }
        if ($this->request_is_admin()) { // execute admin calls
            MyLog()->Append('router: selected admin api');
            return;
        }
        MyLog()->Append('router: begin device provisioning');
        $service = new Service($this->data);
        if (!$service->ready) { // invalid service data
            throw new Exception('router: failed to create service object'
                .json_encode($service->status()));
        }
        $route = new API_Routes($service); //execute
        $this->status = $route->status();
        MyLog()->Append('router: routing completed');
    }

    private function data_is_valid(): bool
    {
        if (!(array)$this->data) {
            $this->set_message('No request data sent');
            return false;
        }
        $entity = $this->data->entity ?? null ;
        if ($entity && $entity != 'service') {
            $this->set_message('ok');
            return false;
        }
        $change = $this->data->changeType ?? 'none';
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
        header('content-type: application/json');
        $stat = 'ok';
        if ($this->status->error) { // failed response
            header('X-API-Response: 202', true, 202);
            $stat = 'failed';
        }
        $response = [
            'status' => $stat,
            'error' => $this->status->error,
            'message' => $this->status->message ?? 'Unknown error',
            'duration' => $this->status->duration ?? 0,
            'data' => $this->result ?? [],
        ];
        echo json_encode($response,JSON_PRETTY_PRINT);
    }

}
