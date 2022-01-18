<?php

class Admin_System extends Admin
{

    public function rebuild(): bool
    {
        $this->send_triggers();
        return true ;
    }

    private function send_triggers():void
    {
        $this->result = [];
        $api = new API_Unms();
        $api->assoc = true;
        $services = $api->request('/clients/services') ?? [];
        if($this->clear_cache()) {
            $url = '/clients/services/';
            foreach ($services as $item) {
                if ($this->is_valid($item)) {
                    $data = ['note' => $item['note']];
                    $api->request($url . $item['id'], 'PATCH', $data);
                }
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
        $device = $this->get_attrib($this->conf->device_name_attr,$item);
        $user = $this->get_attrib($this->conf->pppoe_user_attr,$item);
        $mac = $this->get_attrib($this->conf->mac_addr_attr,$item);
        return ($device && ($user || $mac));
    }
}