<?php
include_once 'api_logger.php';
include_once 'api_curl.php';

class ErClient extends ApiCurl

{
    private ?string $host = null;
    private ?int $port = null;
    public bool $verbose = false ;
    private ?string $csrf = null ;

    public function connect($username, $password, $host, $port = 443): bool
    {
        if($host == $this->host
            && $port == $this->port){ return true; } //already connected

        { $this->host = $host ; $this->port = $port; }

        {
            $this->close();
            $data = ['username' => $username, 'password' => $password];
            $this->base = '/';
            $this->configure('/', 'post',['form' => $data]);
            $this->exec();
        }

        if($this->exit_code() == 303)
        {
            $this->set_token();
            return true;
        }

        MyLog()->Append(['invalid_login',$username],6);
        $this->host = $this->port = null ;
        return false ;
    }

    private function set_token()
    {
        $cookies = curl_getinfo($this->curl(),CURLINFO_COOKIELIST);
        $split = [] ;
        foreach($cookies as $cookie){
            if(str_contains($cookie, "X-CSRF")){
                $split = preg_split('#\s+#',$cookie);
            }
        }
        $this->csrf = array_pop($split);
    }

    private function exit_code(): int { return curl_getinfo($this->curl(), CURLINFO_RESPONSE_CODE); }

    public function get($path, $post = [])
    {
        $this->base = '/api/edge';
        $this->configure($path, 'get', $post);
        return $this->exec();
    }

    public function post($path, $post = [])
    {
        $this->base = '/api/edge';
        $this->configure($path, 'post', $post);
        return $this->exec();
    }

    protected function configure($path, $method, $post)
    {
        curl_reset($this->curl());
        $this->assoc = true ;
        $this->no_ssl = true ;
        $this->url =  sprintf('https://%s:%s',$this->host,$this->port);
        $mime = key_exists('form',$post) ? 'x-www-form-urlencoded' : 'json';
        parent::configure($path,$method,$post);
        $this->opts[CURLOPT_FOLLOWLOCATION] = false ;
        $this->opts[CURLOPT_COOKIEFILE] = '';
        $this->heads[] ='Content-Type: application/' . $mime;
        if($this->csrf){
            $this->heads[] = 'X-CSRF-Token: '. $this->csrf ;
        }
    }

    private function close()
    {
        if($this->ch)curl_close($this->ch);
        $this->ch = null;
    }

    public function __destruct() { $this->close(); }
}

$ErClientInstant = new ErClient();

function erClient(): ErClient { global $ErClientInstant; return $ErClientInstant; }
