<?php

class Devices extends Admin
{
    public function disable()
    { // disables/enables plan limits on device
        $id = $this->data->id ;
        $enable = $this->data->enable ?? false ;
        $data = (object)[
            'device_id' => $this->data->id,
            'path' => '/ppp/profile'
        ];
        $profiles = (new MT_Profile($data,false))->get();
        $plans = $this->get_plans();
        foreach($profiles as $profile)
        {
            if(isset($plans[$profile['name']])){
                $this->set_profile_limit($id,$profile,$plans[$profile['name']],$enable);
            }
        }
        $this->save_router($id,$enable);
        $this->reset_pppoe($id);
    }

    private function reset_pppoe($id)
    {
        $data = (object)[
            'device_id' => $id,
            'path' => '/interface/pppoe-server/server'
        ];
        $servers = (new MT($data,false))->get();
        foreach ($servers as $server)
        {
            $edit = (object)[
                'device_id'=> $id,
                'path' => '/interface/pppoe-server/server',
                'action' => 'disable',
                'data' => (object) ['.id' => $server['.id'],],];
            (new MT($edit,false))->set();
            $edit->action = 'enable';
            (new MT($edit,false))->set();
        }

    }

    private function get_plans()
    {
        $plans = (new Plans($data))->list();
        $plans_map = [];
        foreach ($plans as $plan) {
            $plans_map[$plan['name']] = $plan ;
        }
        return $plans_map;
    }

    private function save_router($id,$enable=false)
    {
        $list = json_decode($this->conf->disabled_routers,true) ?? [];
        if($enable){
            unset($list[$id]);
        }else{
            $list[$id] = 1;
        }
        $this->conf->disabled_routers = json_encode($list) ?? [];
        return $this->db()->saveConfig($this->conf);
    }

    private function set_profile_limit($id,$profile,$plan,$enable=false)
    {
        $rate = $enable
            ? $plan['uploadSpeed']. 'M/'.$plan['downloadSpeed'] .'M'
            : null ;
        $parent = $enable
            ? 'servicePlan-'.$plan['id'].'-parent'
            : 'none';
        $data = (object)[
            'device_id' => $id,
            'action' => 'set',
            'path' => '/ppp/profile',
            'data' => (object)[
                '.id' => $profile['.id'],
                'rate-limit' => $rate,
                'parent-queue' => $parent,
            ],
        ];
        return (new MT($data,false))->set();
    }


    public function services(): void
    {
        $services = $this->get_map('clients/services');
        $clients =  $this->get_map();
        $records = $this->service_records();
        $ids = array_keys($records);
        $this->result = [];
        foreach($ids as $id){
            $this->result[$id] = $services[$id];
            $this->result[$id]['attributes'] = $this->fix_attributes($services[$id]['attributes']);
            $this->result[$id]['client'] = $clients[$services[$id]['clientId']];
            $this->result[$id]['record'] = $records[$id];
        }
    }

    private function fix_attributes($attributes): array
    {
        $fixed = [];
        $keys = [$this->conf->pppoe_user_attr => 'username',
            $this->conf->mac_addr_attr => 'mac',
            $this->conf->device_name_attr => 'device',
            $this->conf->ip_addr_attr => 'ip'];
        foreach (array_keys($keys) as $key) {
            foreach ($attributes as $item) {
                if ($item['key'] == $key){
                    $fixed[$keys[$key]] = $item['value'];
                }
            }
        }
        return $fixed ;
    }

    public function delete(): bool
    {

        $db = $this->connect();
        if (!$db->delete($this->data->id, 'devices')) {
            $this->set_error('database error');
            return false;
        }
        $this->set_message('device has been deleted');
        return true;
    }

    private function connect(): API_SQLite
    {
        return new API_SQLite();
    }

    public function insert(): bool
    {

        $db = $this->connect();
        unset($this->data->id);
        if (!$db->insert($this->data, 'devices')) {
            $this->set_error('database error');
            return false;
        }
        $this->set_message('device has been added');
        return true;
    }

    public function edit(): bool
    {

        $db = $this->connect();
        if (!$db->edit($this->data, 'devices')) {
            $this->set_error('database error');
            return false;
        }
        $this->set_message('device has been updated');
        return true;
    }

    public function get(): bool
    {
        if (!$this->read()) {
            $this->set_error('unable to retrieve list of devices');
            return false;
        }
        $this->setStatus();
        $this->setUsers();
        $this->result = $this->read;
        $this->set_message('devices retrieved');
        return true;
    }

    private function get_map($type='clients'): array
    {
        $api = new API_Unms();
        $api->assoc = true ;
        $array = $api->request('/'.$type);
        $map = [];
        foreach($array as $item){
            $map[$item['id']] = $item ;
        }
        return $map ;
    }

    private function service_records(): array
    {
        $id = $this->data->id;
        $records = [];
        $services = $this->db()->selectServicesOnDevice($id);
        foreach ($services as $service){
            $records[$service['id']] = $service;
        }
        return $records ;
    }

    private function read(): bool
    {
        $db = $this->connect();
        $this->read = $db->selectAllFromTable('devices');
        return (bool) $this->read;
    }

    private function setStatus(): void
    {
        foreach ($this->read as &$device) {
            $conn = @fsockopen($device['ip'],
                $this->default_port($device['type']),
                $code, $err, 0.3);
            if (!is_resource($conn)) {
                $device['status'] = false;
                continue;
            }
            $device['status'] = true;
            fclose($conn);
        }
    }

    private function default_port($type): int
    {
        $ports = array(
            'mikrotik' => 8728,
            'cisco' => 22,
            'radius' => 3301,
        );
        return $ports[$type];
    }

    private function setUsers(): void
    {
        $db = new API_SQLite();
        foreach ($this->read as &$device) {
            $device['users'] = $db->countServicesByDeviceId($device['id']);
        }
    }

}
