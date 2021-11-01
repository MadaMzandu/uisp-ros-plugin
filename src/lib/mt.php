<?php

include_once 'routeros_api.class.php';

class MT extends Device {

    protected $path ;
    protected $insertId ;
    protected $search;

    protected function read($filter = false) {  //implements mikrotik print
        $api = $this->connect();
        if (!$api) {
            return false;
        }
        try {
            $api->write($this->path . 'print', false);
            if ($filter) {
                $api->write($filter, false);
            }
            $api->write(";");
            $this->read = $api->read();
            $api->disconnect();
            return $this->read ;
        } catch (Exception $e) {
            $api->disconnect();
            $this->set_error($e);
            return false;
        }
    }
    
    protected function insertId() {
        return $this->insertId;
    }

    protected function write($data, $action = 'set') {
        $api = $this->connect();
        if (!$api) {
            return false;
        }
        try {
            $api->write($this->path . $action, false);
            foreach (array_keys((array) $data) as $key) {
                $api->write('=' . $key . '=' . $data->$key, false);
            }
            $api->write(';'); // trailing semi-colon works
            $this->read = $api->read();
            $api->disconnect();            
            if (!$this->read || is_string($this->read)) { //don't care what's inside the string?
                $this->set_message('rosapi write:ok');
                return is_string($this->read) ? $this->read : true ;
            }
            $this->set_error('rosapi write:failed', true);
            return false;
        } catch (Exception $e) {
            $api->disconnect;
            $this->set_error($e);
            return false;
        }
    }

    private function connect() {
        $d = $this->get_device();
        if (!$d) {
            return false;
        }
        try {
            $api = new Routerosapi();
            $api->timeout = 3;
            $api->attempts = 1;
            // $api->debug = true;
            if ($api->connect($d->ip, $d->user, $d->password)) {
                return $api;
            }
            $this->set_error('rosapi:connect failed');
            return false;
        } catch (Exception $e) {
            $this->set_error($e);
            return false;
        }
    }
    
        protected function exists() {
        $this->read('?comment');
        if ($this->read) {
            $this->findByComment();
            if ($this->search) {
                return true ;
            }
        }
        return false;
    }

    protected function findByComment() {
        $e = sizeof($this->read);
        $this->search = [];
        for ($i = 0; $i < $e; $i++) {
            $item = $this->read[$i];
            [$id] = explode(',', $item['comment']);
            if ($id == $this->{$this->data->actionObj}->id) {
                $this->search[] = $item;
            }
        }
    }

    protected function get_device() {
        global $conf;
        $name = $this->{$this->data->actionObj}->{$conf->device_name_attr};
        return (new CS_SQLite())->selectDeviceByDeviceName($name);
    }

}
