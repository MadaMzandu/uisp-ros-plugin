<?php

class System extends Admin
{

    public function test()
    {
        $this->send_triggers();
    }

    private function send_triggers():void
    {
        $this->result = [];
        $api = new API_Unms();
        $api->assoc = true;
        $services = $api->request('/clients/services') ?? [];
        $this->clear_cache();
        $url = '/clients/services/';
        foreach ($services as $item) {
            if ($this->is_valid($item)) {
                $data = ['note' => $item['note']];
                $api->request($url.$item['id'], 'PATCH', $data);
            }
        }
    }

    private function clear_cache():bool
    {
        return $this->db()->deleteAll('services');
    }

    private function is_valid($item): bool
    {
        if ($item['status'] > 5) {
            return false;
        }
        $map = [];
        foreach ($item['attributes'] as $a) {
            $map[$a['key']] = $a['value'] ?? null;
        }
        return isset($map[$this->conf->device_name_attr])
        && (isset($map[$this->conf->pppoe_user_attr])
            || isset($map[$this->conf->mac_addr_attr]));
    }
}