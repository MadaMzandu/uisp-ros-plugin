<?php

include_once 'admin.php';
include_once 'api_routes.php';
include_once 'service.php';
include_once 'api_sqlite.php';

$conf = (new API_SQLite())->readConfig();
$debug_log = [];


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
        if(is_array($data) || is_object($data)){
            return is_object($data) ? $data
                :json_decode(json_encode((object)$data));
        }
        return null;
    }

    public function status(): ?stdClass
    { //only for testing
        return $this->status;
    }

    public function route(): void
    {
        if (!$this->data_is_valid()) { // check validity before system tasks
            return;
        }

        if ($this->request_is_admin()) { // admin requests end here
            return;
        }
        $service = new Service($this->data);
        if (!$service->ready) {
            $this->status = $service->status();
            return;
        }
        $route = new API_Routes($service); //execute
        $this->status = $route->status();
    }

    private function data_is_valid(): bool
    {
        if (!(array)$this->data) {
            $this->set_message('No request data sent');
            return false;
        }
        if (isset($this->data->entity) && $this->data->entity != 'service') {
            $this->set_message('ok');
            return false;
        }
        if (isset($this->data->changeType) &&
            !in_array($this->data->changeType, ['insert', 'edit', 'end',
                'suspend', 'unsuspend', 'admin'])) {
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

    public function http_response(): ?string
    {
        header('content-type: application/json');
        $status = 'ok';
        if ($this->status->error) { // failed response
            header('X-API-Response: 202', true, 202);
            $status = 'failed';
        }
        $response = [
            'status' => $status,
            'message' => $this->status->message ?? 'Unknown error',
            'data' => $this->result,
        ];
        return json_encode($response);
    }

}
