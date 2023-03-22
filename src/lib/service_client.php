<?php
include_once 'service_base.php';

class Service_Client extends Service_Base{

    protected $client;
    public $move ;

    protected function get(): ?stdClass
    {
        if (!(array)$this->client) {
            $this->client = (new ApiUcrm())->request('/clients/' . $this->id());
        }
        return $this->client ;
    }

    public function id(): int
    {
        return $this->entity->clientId;
    }

    public function name(): string
    {
        $name = 'Client Id:' . $this->entity->clientId;
        $client = $this->get();
        if ((array)$client) {
            $name = $client->firstName . ' ' . $client->lastName;
            if (isset($client->companyName)) {
                $name = $client->companyName;
            }
        }
        return $name;
    }

    public function username(): ?string
    {
        $entity = $this->mode ? 'before' : 'entity';
        $default = 'client-'
            .$this->$entity->clientId.'-'
            .$this->$entity->id;
        $client = $this->get();
        $tmp = $client->username ?? null;
        if(filter_var($tmp,FILTER_VALIDATE_EMAIL)){
            $tmp = $client->companyName ?? $client->lastName ??  null;
        }
        $chars = ".!&%#@*^()'\":;\\/[]{}|?><,";
        $stripped = str_replace(str_split($chars),'',strtolower($tmp)).'-'.$this->$entity->id;
        $username = preg_replace('/\s+/','',$stripped) ?? $default ;
        return $this->set_attribute($this->conf->pppoe_user_attr,$username) ? $username : null;
    }

}
