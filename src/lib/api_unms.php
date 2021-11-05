<?php

use Ubnt\UcrmPluginSdk\Service\UcrmApi;

class API_Unms
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
        $action = $this->method;
        $api = UcrmApi::create();
        try {
            $response = $api->$action($this->url, $this->post) ?? [];
            return json_decode(json_encode($response), $this->assoc);
        } catch (GuzzleHttp\Exception\ClientException $e) {
            $response = [];
            $this->status->error = true;
            $this->status->message = $e->getMessage();
            return $this->assoc ? $response : (object)$response;
        }
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
