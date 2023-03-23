<?php


class ApiUcrm
{

    public $assoc = false;
    private $method;
    //private $has_data = false;
    private $post;
    private $url;
    private $status;
    private $result;

    public function __construct($data = [])
    {
        $data = json_decode(json_encode($data),true);
        $this->url = $data['path'] ?? null ;
        $this->post = $data['post'] ?? null;
        $this->result = [];
        $this->status = (object)[
            'error' => false,
            'message' => 'ok',
        ];
    }

    public function get($path = null,$post = null)
    {
        $this->configure($path,'GET',$post);
        $this->result = $this->exec();
    }

    private function exec()
    {
        $action = $this->method;
        $api = \Ubnt\UcrmPluginSdk\Service\UcrmApi::create();
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

    public function post($path = null,$post = [])
    {
        $this->configure($path,'POST',$post);
        $this->result = $this->exec();
    }

    public function patch($path = null,$post = [])
    {
        $this->configure($path,'PATCH',$post);
        $this->result = $this->exec();
    }

    public function request($url = '', $method = 'GET', $post = [])
    {
        $this->configure($url,$method,$post);
        $this->result = $this->exec();
        return $this->result;
    }

    private function configure($path = null,$method = null, $post = null)
    {
        $this->url = $path ? : $this->url ;
        $this->method = $method ? strtoupper($method) : $this->method ;
        $this->post = $post ?: ($this->post ?? []);
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