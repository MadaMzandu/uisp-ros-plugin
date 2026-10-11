<?php
include_once 'api_curl.php';

class WebUcrm extends ApiCurl
{

    public bool $unms;
    private ?string $key = null;

    protected function configure($path, $method, $post)
    {
        $this->no_ssl = false;
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
        $key = $this->key ?? $this->config()->unmsToken ?? null;
        if (!is_string($key) || $key === '') throw new RuntimeException('API token is not configured');
        return $key;
    }

    public function __construct($token = null,$assoc = false,$unms = false)
    {
        if($token){ $this->key = $token; }
        $this->assoc = $assoc ;
        $this->unms = $unms;
    }

}
