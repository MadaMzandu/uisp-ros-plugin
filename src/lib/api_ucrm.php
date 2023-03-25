<?php


class ApiUcrm
{

    public bool $assoc = false ;
    private ?string $method;
    private $data ;
    private ?string $url ;

    public function request($path,$method = 'get',$data = [])
    {
        $this->configure($path,strtolower($method),$data);
        return $this->exec() ;
    }

    private function exec()
    {
        $api = \Ubnt\UcrmPluginSdk\Service\UcrmApi::create();
        $action = $this->method;
        $response = $api->$action($this->url, $this->data);
        return json_decode(json_encode($response), $this->assoc);
    }

    public function post($path,$data= [])
    {
        $this->configure($path,'post',$data);
        return $this->exec();
    }

    public function patch($path,$data = [])
    {
        $this->configure($path,'patch',$data);
        return $this->exec();
    }

    public function get($path,$data = [])
    {
        $this->configure($path,'get',$data);
        return $this->exec();
    }

    public function delete($path, $data = [])
    {
        $this->configure($path,'delete',$data);
        return $this->exec();
    }

    private function configure($path,$method,$data)
    {
        $this->method = $method;
        $this->url = $path ;
        $this->data = $data ;
    }

}