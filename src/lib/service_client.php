<?php
include_once 'service_base.php';

class Service_Client extends Service_Base{

    protected $client;

    protected function get(): ?object
    {
        if (!(array)$this->client) {
            $this->client = (new API_Unms())->request('/clients/' . $this->id());
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
}
