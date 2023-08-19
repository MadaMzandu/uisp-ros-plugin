<?php
include_once 'api_logger.php';

class ErClient
{
    private ?string $host = null;
    private ?int $port = null;
    private ?string $base;
    private $_curl = null;
    public bool $verbose = false ;
    private ?string $csrf = null ;

    public function connect($username, $password, $host, $port = 443): bool
    {
        if($host == $this->host
            && $port == $this->port){ return true; }

        { $this->host = $host ; $this->port = $port; }

        {
            $this->close();
            $data = ['username' => $username, 'password' => $password];
            $this->configure('/', 'post', $data, 'x-www-form-urlencoded');
            $this->exec();
        }
        if($this->exit_code() == 303)
        {
            $this->set_token();
            return true;
        }

        MyLog()->Append('edge router login failed username: '. $username,6);
        $this->host = $this->port = null ;
        return false ;
    }

    private function set_token()
    {
        $cookies = curl_getinfo($this->curl(),CURLINFO_COOKIELIST);
        $token = null ;
        foreach($cookies as $cookie){
            if(preg_match("/CSRF/",$cookie)){
                $token = explode("\t",$cookie);
            }
        }
        $this->csrf = $token[6] ?? null ;
    }

    private function exit_code(): int { return curl_getinfo($this->curl(), CURLINFO_RESPONSE_CODE); }

    public function get($path, $data = [])
    {
        $this->configure($path, 'get', $data,);
        return $this->exec();
    }

    public function post($path, $data = [])
    {
        $this->configure($path, 'post', $data);
        return $this->exec();
    }

    private function exec()
    {
        $response = curl_exec($this->curl());
        if (curl_errno($this->curl()) !== 0) {
            MyLog()->Append(["curl error: ",curl_error($this->curl())]);
            return null;
        }
        $ecode = curl_getinfo($this->curl(), CURLINFO_HTTP_CODE);
        if ($ecode != 200) {
            $error = empty($response) ? 'http: '. $ecode : $response ;
            if($ecode >= 400) MyLog()->Append(["edge router error: ",$error]);
            return null;
        }
        return json_decode($response,true);
    }

    private function configure($path, $method, $data, $mime = 'json')
    {
        curl_reset($this->curl());
        curl_setopt_array($this->curl(), [
            CURLOPT_URL => $this->make_url($path, $method, $data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_VERBOSE => $this->verbose,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_COOKIEFILE => "",
        ]);
        $headers[] ='Content-Type: application/' . $mime;
        if($this->csrf){
            $headers[] = 'X-CSRF-Token: '. $this->csrf ;
        }
        curl_setopt($this->curl(),CURLOPT_HTTPHEADER,$headers);
        $this->make_post($method, $data, $mime);
        $this->set_method($method);
    }

    private function set_method($method): void
    {
        $post = strtolower($method) == 'post';
        if ($post) {
            curl_setopt($this->curl(), CURLOPT_POST, true);
        } else {
            curl_setopt($this->curl(), CURLOPT_CUSTOMREQUEST, strtoupper($method));
        }
    }

    private function make_post($method, $data, $mime)
    {
        if (empty($data) || $method == 'get') {
            return;
        }
        $post = json_encode($data);
        if (preg_match("/form/", $mime)) {
            $post = http_build_query($data);
        }
        curl_setopt($this->curl(), CURLOPT_POSTFIELDS, $post);
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
        if(is_resource($this->_curl))
        {
            curl_close($this->_curl);
            $this->_curl = null;
        }
    }

    private function curl()
    {
        if (empty($this->_curl)) {
            $this->_curl = curl_init();
        }
        return $this->_curl;
    }

    public function __construct($base = '/api/edge') { $this->base = $base; }

    public function __destruct() { $this->close(); }
}

$ErClientInstant = new ErClient();

function erClient(): ErClient { global $ErClientInstant; return $ErClientInstant; }
