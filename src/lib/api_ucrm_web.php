<?php
include_once 'api_curl.php';

class WebUcrm extends ApiCurl
{

    public bool $unms;
    private string $key = '7e7ab4f0-b5a2-4ccb-8e38-569dd50007c3';

    protected function configure($path, $method, $post)
    {
        $this->no_ssl = true ;
        $this->base = $this->unms ? '/nms/api/v2.1' : '/api/v2.1' ;
        $this->url = 'https://127.0.0.1' ;
        parent::configure($path,$method,$post);
        $this->heads = [
            'content-type: application/json',
            'x-auth-token: ' . $this->key(),
            'x-auth-app-key: ' . $this->key(),
        ];
    }

    protected function key()
    {
        return $this->key ?? $this->config()->unmsToken ;
    }

    public function __construct($token = null,$assoc = false,$unms = false)
    {
        if($token){ $this->key = $token; }
        $this->assoc = $assoc ;
        $this->unms = $unms;
    }

}
