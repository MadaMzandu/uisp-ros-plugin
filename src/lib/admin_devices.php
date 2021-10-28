<?php

class Devices extends Admin {

    protected $devices;
    private $device_name;

    public function delete() {
        
        $db = $this->connect();
        if (!$db->delete($this->data->id, $table = 'devices')) {
            $this->set_error('database error');
            return false;
        }
        $this->set_message('device has been deleted');
        return true;
    }

    
    public function insert() {
        
        $db = $this->connect();
        unset($this->data->id);
        if (!$db->insert($this->data, $table = 'devices')) {
            $this->set_error('database error');
            return false;
        }
        $this->set_message('device has been added');
        return true;
    }

    private function exists() {
        if (property_exists($this->devices, $this->device_name)) {
            return true;
        }
        return false;
    }

    public function edit() {
        
        $db = $this->connect();
        if (!$db->edit($this->data, $table = 'devices')) {
            $this->set_error('database error');
            return false;
        }
        $this->set_message('device has been updated');
        return true;
    }

    public function get() {
        
        if (!$this->read()) {
            $this->set_error('unable to retrieve list of devices');
            return false;
        }
        $this->setStatus();
        $this->setUsers();
        $this->result = $this->read ;
        $this->set_message('devices retrieved');
        return true;
    }

    private function setUsers() {
        $db = new API_SQLite();
        foreach ($this->read as &$device) {
            $device['users'] = $db->countServicesByDeviceId($device['id']);
        }
    }

    private function setStatus() {
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

    private function default_port($type) {
        $ports = array(
            'mikrotik' => 8728,
            'cisco' => 22,
            'radius' => 3301,
        );
        return $ports[$type];
    }

    private function read() {
        $db = $this->connect();
        $this->read = $db->selectAllFromTable('devices');
        if ($this->read) {
            return true;
        }
        return $this->read_file();
    }

    private function read_file() {
        if(!file_exists('json/devices.json')){
            return false ;
        }
        global $conf;
        $db = $this->connect();
        $file = json_decode(
                file_get_contents($conf->devices_file), true);
        if (!$file) {
            return false;
        }
        foreach ($file as &$item) {
            $item['pool'] = implode(',', $item['pool']);
            $item['user'] = $conf->api_user;
            $item['password'] = $conf->api_pass;
            if (!$db->insert((object) $item, 'devices')) { //update database
                continue;
            }
            $id = $db->selectDeviceIdByDeviceName($item['name']);
            $db->replaceServiceDeviceNameWithId($id, $item['name']);
            $item['id'] = $id;
            array_push($this->read, $item);
            //unset($item);
        }
        file_put_contents(json_encode($file, 128), $conf->devices_file);
        return true;
    }

    private function connect() {
        return new API_SQLite();
    }

}
