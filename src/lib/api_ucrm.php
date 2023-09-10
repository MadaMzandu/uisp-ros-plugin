<?php
require_once 'vendor/autoload.php';
include_once '_web_ucrm.php';

const USE_UCRM_CURL = 0;

use Ubnt\UcrmPluginSdk\Service\UcrmApi;
use Ubnt\UcrmPluginSdk\Service\UnmsApi;


class ApiUcrm
{

    public bool $assoc = false ;
    public bool $unms = false ;
    public ?string $key = null ;
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
        if(USE_UCRM_CURL > 0 ) return $this->web_exec();
        $api = $this->unms ? UnmsApi::create($this->key()): UcrmApi::create();
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
        $api = new WebUcrm();
        $api->assoc = $this->assoc ;
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

    private function key(): string
    {
        $c = [];
        if(is_file('data/config.json')){
            $c = json_decode(file_get_contents(
                    'data/config.json'),true) ?? [];
        }
        return $this->key ?? $c['unmsToken'] ?? '' ;
    }

}