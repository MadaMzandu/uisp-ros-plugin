<?php

class Admin_Rebuild{
    private $conf ;
    private $device ;

    private function db()
    {
        return new ApiSqlite() ;
    }

    private function clear_cache():bool
    {
        return $this->db()->deleteAll('services');
    }

    private function crm()
    {
        $c = new ApiUcrm();
        $c->assoc = true ;
        return $c;
    }

    private function filter($services): array
    {
        $dn = $this->conf->device_name_attr ?? 'deviceName';
        $result = [];
        foreach ($services as $s){
            $attrs = $s['attributes'] ?? [];
            foreach($attrs as $a){
                if($this->device){
                    if($a['key'] == $dn && $a['value'] == $this->device) $result[] = $s;
                }
                else{
                    if($a['key'] == $dn && $a['value']) $result[] = $s;
                }
            }
        }
        return $result;
    }

    private function find_attr(): ?int
    {
        $dn = $this->conf->device_name_attr ?? 'deviceName';
        $attrs = $this->crm()->request('/custom-attributes') ?? [];
        foreach ($attrs as $a){
            if($a['key'] == $dn) return $a['id'];
        }
        return null ;
    }

    public function rebuild_device($device)
    {
        $this->device = $device->name ?? null;
        $this->send_triggers();
    }

    public function send_triggers():void
    {
        $this->conf = $this->db()->readConfig();
        //$id = $this->find_attr();
        $opts = null ;
        //if($id) $opts = '?customAttributeId=' . $id . '&customAttributeValue="Test2"';
        $result = $this->crm()->request('/clients/services' . $opts) ?? [];
        $services = $this->filter($result) ;
        if($services && $this->clear_cache()) {
            $url = '/clients/services/';
            file_put_contents('data/cache.json',null); //reset cache
            file_put_contents('data/queue.json',null); // reset queue
            //$count = 20 ;
            foreach ($services as $item) {
                //if(!$count--)break ;
                $data = ['note' => $item['note']];
                $this->crm()->request($url . $item['id'], 'PATCH', $data);
            }
        }
    }
    
}

