<?php
include_once 'api_logger.php';
include_once 'api_curl.php';

class ErClient extends ApiCurl

{
    private ?string $host = null;
    private ?int $port = null;
    private ?string $base;
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
        $this->configure($path, 'get', $post);
        return $this->exec();
    }

    public function post($path, $post = [])
    {
        $this->configure($path, 'post', $post);
        return $this->exec();
    }

    protected function configure($path, $method, $post)
    {
        curl_reset($this->curl());
        $this->assoc = true ;
        $this->no_ssl = true ;
        $mime = key_exists('form',$post) ? 'x-www-form-urlencoded' : 'json';
        parent::configure($path,$method,$post);
        $this->opts[CURLOPT_URL] = $this->make_url($path,$method,$post);
        $this->opts[CURLOPT_ENCODING] = '';
        $this->opts[CURLOPT_COOKIEFILE] = '';
        $this->opts[CURLOPT_FOLLOWLOCATION] = false ;
        $headers[] ='Content-Type: application/' . $mime;
        if($this->csrf){
            $headers[] = 'X-CSRF-Token: '. $this->csrf ;
        }
        $this->opts[CURLOPT_HTTPHEADER] = $headers ;
    }

    private function make_url($path, $method, $data): string
    {
        if ($path == '/') {
            return sprintf("https://%s:%s/",
                trim($this->host, '/'),
                $this->port ?? 443,
            );
        }
        $url = sprintf("https://%s:%s/%s/%s",
            trim($this->host, '/'),
            $this->port ?? 443,
            trim($this->base, '/'),
            trim($path, '/')
        );
        if ($method == 'get' && $data) {
            $url .= '?' . http_build_query($data);
        }
        return $url;
    }

    private function close()
    {
        if($this->ch)curl_close($this->ch);
        $this->ch = null;
    }

    private function curl(): CurlHandle|null
    {
        if (empty($this->ch)) {
            $ch = curl_init();
            if($ch){ $this->ch = $ch; }
        }
        return $this->ch;
    }

    public function __construct($base = '/api/edge') { $this->base = $base; }

    public function __destruct() { $this->close(); }
}

$ErClientInstant = new ErClient();

function erClient(): ErClient { global $ErClientInstant; return $ErClientInstant; }
