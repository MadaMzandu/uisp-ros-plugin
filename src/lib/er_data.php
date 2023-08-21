<?php
include_once 'er_client.php';
include_once 'er_objects.php';
include_once 'data.php';
class ErData extends Data
{
    public function account()
    {
        switch ($this->type()){
            case 'dhcp': return $this->dhcp();
            default: return null ;
        }
    }

    public function queue()
    {
        if(!in_array($this->type(),['dhcp','dhcp6'])) return null ;
        $dev = $this->find_device();
        $qos = $dev->qos ?? null ;
        if(!$qos){ return null; }
        $q = new ErQueue($this->ip(),$this->limits(),
            $this->disabled(),$this->disabled_rate());
        $post = $q->toArray();
        $post['action'] = $this->service['action'];
        $post['path'] = 'queue';
        $post['batch'] = $this->service['batch'];
        return $post ;
    }

    protected function disabled_rate(): ?string
    {
        return $this->conf()->disabled_rate ?? null ;
    }

    private function dhcp()
    {
        return [
            'path' => 'dhcp',
            'action' => 'set',
            'disabled' => $this->disabled(),
            'batch' => $this->service['batch'],
            'id' => $this->account_name(),
            'ip-address' => $this->ip(),
            'mac-address' => $this->service['mac'],
        ];
    }

    protected function account_name(): string
    {
        $name = parent::account_name();
        return preg_replace("/[~`!@#$%^&*<>?;:'\s\"]*/",'',$name);
    }

    private function connect(): bool
    {
        $d = $this->find_device();
        $c = erClient();
        return $c->connect($d->user,$d->password,$d->ip);
    }

    public function __call($name, $arguments)
    {
        return null ;
    }

}