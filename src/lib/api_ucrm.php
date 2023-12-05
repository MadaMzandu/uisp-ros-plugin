<?php

require_once 'vendor/autoload.php';
include_once '_web_ucrm.php';

const USE_UCRM_CURL = 1;

use Ubnt\UcrmPluginSdk\Service\UcrmApi;
use Ubnt\UcrmPluginSdk\Service\UnmsApi;


class ApiUcrm
{

    public bool $assoc = false ;
    public bool $unms = false ;
    private ?string $method;
    private $data ;
    private ?string $url ;
    private ?string $token = null;

    public function request($path,$method = 'get',$data = [])
    {
        $this->configure($path,strtolower($method),$data);
        return $this->exec() ;
    }

    private function exec()
    {
        if(USE_UCRM_CURL) return $this->web_exec();
        $api = $this->unms ? UnmsApi::create($this->token()) : UcrmApi::create();
        $action = $this->method;
        try{
            $response = $api->$action($this->url, $this->data);
            return json_decode(json_encode($response), $this->assoc);
        }
        catch (\Exception $e){
            MyLog()->Append([$e->getMessage(),$e->getTraceAsString()]);
            return null ;
        }
    }

    private function web_exec()
    {
        $api = new WebUcrm(null,$this->assoc,$this->unms);
        $action = $this->method ;
        return $api->$action($this->url,$this->data);
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
        $this->method = strtolower(trim($method));
        $this->url = trim(trim($path),'/');
        $this->data = json_decode(json_encode($data),true);
    }

    private function token(): ?string
    {
        return $this->config()->nmsToken ?? $this->token ;
    }

    private function config(): object
    {
        $fn = 'data/config.json';
        if(is_file($fn)){
            $read = json_decode(file_get_contents($fn));
            if(is_object($read)) return $read ;
        }
        return new stdClass();
    }

    public function __construct($token=null,$assoc=false,$unms=false)
    {
        if($token) $this->token = $token ;
        $this->assoc = $assoc ;
        $this->unms = $unms ;
    }

}