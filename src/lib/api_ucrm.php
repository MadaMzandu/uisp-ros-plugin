<?php


class ApiUcrm
{

    public $assoc = false;
    private $method;
    private $has_data = false;
    private $post;
    private $url;
    private $status;
    private $result;

    public function __construct($data = false)
    {
        if ($data) {
            $this->has_data = true;
            $this->url = $data->path;
            $this->post = (array)$data->post;
        }
        $this->result = [];
        $this->status = (object)[
            'error' => false,
            'message' => 'ok',
        ];
    }

    public function get()
    {
        $this->method = 'get';
        $this->result = $this->exec();
    }

    private function exec()
    {
        $action = $this->method ?? 'get';
        $api = \Ubnt\UcrmPluginSdk\Service\UcrmApi::create();
        $response = $api->$action($this->url,$this->post);
        return json_decode(json_encode($response), $this->assoc);
    }

    public function post()
    {
        $this->method = 'post';
        $this->result = $this->exec();
    }

    public function patch()
    {
        $this->method = 'patch';
        $this->result = $this->exec();
    }

    public function request($url = '', $method = 'GET', $post = [])
    {
        if (!$this->has_data) {
            $this->method = strtolower($method);
            $this->post = $post;
            $this->url = $url;
        }
        $this->result = $this->exec();
        return $this->result;
    }

    public function status()
    {
        return $this->status;
    }

    public function result()
    {
        return $this->result;
    }

}
