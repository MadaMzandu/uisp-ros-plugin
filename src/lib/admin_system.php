<?php

class System extends Admin
{

    public function test()
    {
        $this->read();
    }

    private function read()
    {
        $this->result = [];
        $read = [];
        $api = new API_Unms();
        $api->assoc = true;
        $services = $api->request('/clients/services');
        $count = 0;
        $d = (object)[];
        (new API_SQLite)->deleteAll('services');
        foreach ($services as $item) {
            if ($this->is_valid($item)) {
                $url = '/clients/services/' . $item['id'];
                $data = ['name' => $item['name']];
                $ret = $api->request($url, 'PATCH', $data);
            }
        }
        //$this->result = $read ;
    }

    private function is_valid($item)
    {
        if ($item['status'] > 5) {
            return false;
        }
        global $conf;
        $map = [];
        foreach ($item['attributes'] as $a) {
            $map[$a['key']] = $a['value'] ?? null;
        }
        return isset($map[$conf->device_name_attr])
        && (isset($map[$conf->pppoe_user_attr])
            || isset($map[$conf->mac_addr_attr])) ? true : false;
    }
}