<?php


class WebUcrm
{

    public $assoc = false;
    protected $ch;
    protected $method;
    protected $post;
    protected $url;
    protected $data;
    private $result ;
    protected $key = 'c3458db8-ad00-457f-bf08-f87f1e6b12f7';
    private $disable_ssl_verify = true;

    public function request($url, $method = 'GET', $post = [])
    {
        $this->method = strtoupper($method);
        $this->ch = curl_init();
        $this->post = $post;
        $this->url = $url;
        $this->set_opts();
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

    public function result() {return $this->result; }


    private function api(): string
    {
        return 'https://127.0.0.1/api/v1.0';
    }

    private function set_opts()
    {
        $this->set_url();
        curl_setopt($this->ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->ch, CURLOPT_ENCODING, '');
        curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($this->ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($this->ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        //curl_setopt($this->ch,CURLOPT_VERBOSE,true);
        if ($this->disable_ssl_verify) {
            curl_setopt($this->ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($this->ch, CURLOPT_SSL_VERIFYHOST, 0);
        }
        $this->set_key();
        $this->set_method();
        $this->set_body();
    }

    private function set_url()
    {
        $url =
            trim(
                sprintf(
                    '%s/%s?%s',
                    trim($this->api(), '/'),
                    trim($this->url . '/'),
                    $this->parameters()),
                '/?');
        curl_setopt($this->ch, CURLOPT_URL, $url);
    }

    private function set_key()
    {
        curl_setopt(
            $this->ch,
            CURLOPT_HTTPHEADER,
            [
                'Content-Type: application/json',
                sprintf('x-auth-token: %s', $this->key),
            ]
        );
    }

    private function set_method()
    {
        if ($this->method === 'POST') {
            curl_setopt($this->ch, CURLOPT_POST, true);
        } elseif ($this->method !== 'GET') {
            curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, $this->method);
        }
    }

    private function parameters(): ?string
    {
        if (empty($this->post)) return null;
        return http_build_query($this->post) ?? null;
    }

    private function set_body()
    {
        if ($this->method !== 'GET') {
            curl_setopt($this->ch, CURLOPT_POSTFIELDS, json_encode($this->post));
        }
    }

    private function configure($path, $method, $post)
    {
        $this->method = $method;
        $this->post = $post;
        $this->url = $path;
        $this->ch = curl_init();
        $this->set_opts();
    }

    private function exec()
    {
        $response = curl_exec($this->ch);
        if (curl_errno($this->ch) !== 0) {
            echo sprintf('Curl error: %s', curl_error($this->ch)) . PHP_EOL;
        }
        if (curl_getinfo($this->ch, CURLINFO_HTTP_CODE) >= 400) {
            echo sprintf('API error: %s', $response) . PHP_EOL;
        }
        curl_close($this->ch);
        return json_decode($response, $this->assoc) ;
    }

}
