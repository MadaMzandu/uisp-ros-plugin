<?php


class ApiCurl
{

    public bool $assoc = false;
    public bool $no_ssl = true ;
    public bool $no_json = false ;
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
        $path = sprintf("%s/%s",
            trim($this->api(),'/'),
            trim($path,'/'));
        $this->ch = curl_init();
        $headers = [];
        $opts = [
            CURLOPT_URL => $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
        ];
        if(!$this->no_json){
            $headers[] = 'content-type: application/json';
        }
        if($this->no_ssl) {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = false ;
        }
        if($method != 'GET') {
            $opts[CURLOPT_POSTFIELDS] = $this->no_json ? $post : json_encode($post);
        }
        else {
            $opts[CURLOPT_URL] = sprintf('%s?%s',$path,http_build_query($post));
        }
        if($headers){
            $opts[CURLOPT_HTTPHEADER] = $headers;
        }
        curl_setopt_array($this->ch,$opts);
    }

    protected function key()
    {
        return $this->config()->apiToken ?? null ;
    }

    protected function exec()
    {
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
        }
        return $error ? null : json_decode($response, $this->assoc) ;
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

    protected function api():string
    {
        return 'https://127.0.0.1/api/v2.1' ;
    }

}
