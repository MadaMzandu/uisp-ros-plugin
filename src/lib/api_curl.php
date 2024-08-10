<?php


class ApiCurl
{

    public bool $assoc = false;
    public bool $no_ssl = false ;
    public bool $verbose = false ;
    protected string $base = '/api' ;
    protected ?string $url = null ;
    protected array $opts = [];
    protected array $heads = [];
    protected ?CurlHandle $ch = null ;

    public function request($url, $method = 'GET', $post = [])
    {
        $this->configure($url,$method,$post);
        return $this->exec();
    }

    public function get($path, $post = [])
    {
        $this->configure($path, 'GET', $post);
        return $this->exec();
    }

    public function patch($path, $post = [])
    {
        $this->configure($path, 'PATCH', $post);
        return $this->exec();
    }

    public function post($path, $post = [])
    {
        $this->configure($path, 'POST', $post);
        return $this->exec();
    }

    public function delete($path, $post = [])
    {
        $this->configure($path, 'DELETE', $post);
        return $this->exec();
    }

    protected function configure($path, $method, $post)
    {
        $fpath = sprintf('%s/%s/%s',
            trim($this->url(),'/'),
            trim($this->base,'/'),
            trim($path,'/'));
        curl_reset($this->curl());
        $method = strtoupper($method);
        $this->heads = [];
        $this->opts = [
            CURLOPT_URL => $fpath,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_VERBOSE => $this->verbose,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_POST => $method == 'POST',
            $this->opts[CURLOPT_SSL_VERIFYPEER] => !$this->no_ssl,
            $this->opts[CURLOPT_SSL_VERIFYHOST] => !$this->no_ssl,
        ];
        if($method != 'POST'){
            $this->opts[CURLOPT_CUSTOMREQUEST] = $method;
        }
        if($post && $method != 'GET') {
            $form = $post['form'] ?? null ;
            $this->opts[CURLOPT_POSTFIELDS] = $form ? http_build_query($form): json_encode($post);
        }
        else if($post) {
            $this->opts[CURLOPT_URL] = sprintf('%s?%s',$fpath,http_build_query($post));
        }
    }

    protected function key()
    {
        return $this->config()->apiToken ?? null ;
    }

    protected function exec()
    {
        if($this->heads){ $this->opts[CURLOPT_HTTPHEADER] = $this->heads; }
        curl_setopt_array($this->ch,$this->opts);
        $response = curl_exec($this->ch);
        $error = null ;
        if (curl_errno($this->ch) !== 0) {
            $error = curl_error($this->ch);
        }
        if (curl_getinfo($this->ch, CURLINFO_HTTP_CODE) >= 400) {
            $error = $response ;
        }
        curl_close($this->ch);
        if($error){
            MyLog()->Append("API error: ". $error);
            return null ;
        }
        $json  = json_decode($response, $this->assoc) ;
        return $json ? : $response ;
    }

    protected function config(): object
    {
        $fn = 'data/config.json';
        if(is_file($fn)){
            $read = json_decode(file_get_contents($fn));
            if(is_object($read)) return $read ;
        }
        return new stdClass();
    }

    protected function curl()
    {
        if(!is_resource($this->ch))
        {
            $this->ch = curl_init();
        }
        return $this->ch ;
    }

    protected function url():string
    {
        return $this->url ?? 'https://127.0.0.1/api/v2.1' ;
    }

}
