<?php

class Devices extends Admin
{

    protected $devices;
    private $device_name;

    public function service_list(){
        $services = $this->get_map('clients/services');
        $clients =  $this->get_map();
        $ids = $this->get_service_ids();
        $this->result = [];
        foreach($ids as $id){
            $this->result[$id] = $services[$id];
            $this->result[$id]['client'] = $clients[$services[$id]['clientId']];
        }
    }

    public function delete()
    {

        $db = $this->connect();
        if (!$db->delete($this->data->id, $table = 'devices')) {
            $this->set_error('database error');
            return false;
        }
        $this->set_message('device has been deleted');
        return true;
    }

    private function connect()
    {
        return new API_SQLite();
    }

    public function insert()
    {

        $db = $this->connect();
        unset($this->data->id);
        if (!$db->insert($this->data, $table = 'devices')) {
            $this->set_error('database error');
            return false;
        }
        $this->set_message('device has been added');
        return true;
    }

    public function edit()
    {

        $db = $this->connect();
        if (!$db->edit($this->data, $table = 'devices')) {
            $this->set_error('database error');
            return false;
        }
        $this->set_message('device has been updated');
        return true;
    }

    public function get()
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

    private function get_map($type='clients')
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

    private function get_service_ids(): array
    {
        $id = $this->data->id;
        $ids = [];
        $services = $this->db()->selectServicesOnDevice($id);
        foreach ($services as $service){
            $ids[]= $service['id'];
        }
        return $ids ;
    }

    private function read()
    {
        $db = $this->connect();
        $this->read = $db->selectAllFromTable('devices');
        return (bool) (array)$this->read;
    }

    private function setStatus()
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

    private function default_port($type)
    {
        $ports = array(
            'mikrotik' => 8728,
            'cisco' => 22,
            'radius' => 3301,
        );
        return $ports[$type];
    }

    private function setUsers()
    {
        $db = new API_SQLite();
        foreach ($this->read as &$device) {
            $device['users'] = $db->countServicesByDeviceId($device['id']);
        }
    }

    private function exists()
    {
        if (property_exists($this->devices, $this->device_name)) {
            return true;
        }
        return false;
    }

}
