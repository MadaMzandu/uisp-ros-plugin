<?php


class ApiCurl
{

    public bool $assoc = false;
    public bool $no_ssl = false ;
    public bool $verbose = false ;
    protected string $base = '/api';
    protected ?string $url = null ;
    protected array $opts = [];
    protected array $heads = [];
    protected $ch = null ;

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
        curl_reset($this->curl());
        $fpath = sprintf("%s/%s/%s",
            trim($this->url(),'/'),
            trim($this->base,'/'),
            trim($path,'/'));
        $method = strtoupper($method);
        $this->heads = [];
        $this->opts = [
            CURLOPT_URL => $fpath,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_VERBOSE => $this->verbose,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_POST => $method == 'POST',
            CURLOPT_SSL_VERIFYPEER => !$this->no_ssl,
            CURLOPT_SSL_VERIFYHOST => $this->no_ssl ? 0 : 2,
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
        curl_setopt_array($this->curl(),$this->opts);
        $response = curl_exec($this->curl());
        $error = null ;
        if (curl_errno($this->curl()) !== 0) {
            $error = curl_error($this->curl());
        }
        $status = curl_getinfo($this->curl(), CURLINFO_HTTP_CODE);
        if ($status >= 300) {
            $error = 'API HTTP status ' . $status;
        }
        if (PHP_VERSION_ID < 80000) curl_close($this->ch);
        $this->ch = null;
        if($error){
            MyLog()->Append("API error: ". $error);
            return null ;
        }
        $json  = json_decode($response, $this->assoc) ;
        return is_string($json) ? null : $json ;
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

    protected function url():string
    {
        return $this->url ?? 'https://127.0.0.1/' ;
    }

    protected function curl()
    {
        if(empty($this->ch))
        {
            $this->ch = curl_init();
        }
        return $this->ch ;
    }

}
