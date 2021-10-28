<?php

include_once 'admin.php';
include_once 'app_routes.php';
include_once 'api_service.php';

$debug_log = [];


class API_Router {

    private $data;
    private $status;
    private $result;
    
    public function status(){ //only for testing
        return $this->status ;
    }

    public function __construct(&$data) {
        $this->data = $data;
        $this->result = [];
        $this->status = (object) ['status' => 'ok','message' => '','session' => false];
    }

    public function route() {
        
        if (!$this->is_valid_request()) { // check validity before system tasks
            return;
        }
        
        if ($this->is_admin_request()) { // admin requests end here
            return;
        }
        $service = new Service($this->data);
        if(!$service->valid){
            $this->status = $service->status();
            return ;
        }
        $route = new Routes($service); //execute
        $this->status = $route->status();
    }

    private function is_admin_request() {
        if ($this->data->changeType != 'admin') {
            return false;
        }
        $admin = new Admin($this->data);
        $admin->exec();
        $this->status = $admin->status();
        $this->result = $admin->result();
        return true;
    }

    private function is_valid_request() {
        if(!$this->data){
            $this->set_message('No request data sent');
            return false ;
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

    public function http_response() {
        header('content-type: application/json');
        $status = 'ok';
        if ($this->status->error) { // failed response
            header('X-API-Response: 202', true, 202);
            $status = 'failed';
        }
        $response = [
            'status' =>$status,
            'message' => isset($this->status->message) 
               ? $this->status->message :'Unknown error',
            'data' => $this->result,
        ];
        if(property_exists($this->status,'session')){
            $response['session'] = $this->status->session ;
        }
        return json_encode($response);
    }

    private function set_message($msg) {
        $this->status->error = false;
        $this->status->message = $msg;
    }

}
