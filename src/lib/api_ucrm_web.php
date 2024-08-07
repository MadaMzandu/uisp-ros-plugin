<?php
include_once 'api_curl.php';

class WebUcrm extends ApiCurl
{

    public bool $unms;
    private string $key = 'b59d8d21-913b-401d-9d00-2708bbabba0c';//'67830f0f-c732-4d32-b0e2-7458d4f97b52';

    protected function configure($path, $method, $post)
    {
        $this->no_ssl = true ;
        parent::configure($path,$method,$post);
        $this->opts[CURLOPT_HTTPHEADER] = [
            'content-type: application/json',
            'x-auth-token: ' . $this->key(),
            'x-auth-app-key: ' . $this->key(),
        ];
    }

    protected function key()
    {
        return $this->config()->unmsToken ?? $this->key ;
    }

    protected function api(): string
    {
        $nms = null ;
        if($this->unms) $nms = 'nms/';
        return 'https://127.0.0.1/' . $nms . 'api/v2.1' ;
    }

    public function __construct($token = null,$assoc = false,$unms = false)
    {
        if($token){ $this->key = $token; }
        $this->assoc = $assoc ;
        $this->unms = $unms;
    }

}
