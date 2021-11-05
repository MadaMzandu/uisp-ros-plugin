<?php

include_once 'routeros_api.class.php';
include_once 'device.php';

class MT extends Device
{

    protected $insertId;
    protected $search;
    protected $exists = false ;
    protected $path ;
    protected $entity ;

    protected function insertId()
    {
        return $this->insertId;
    }

    protected function write($data, $action = 'set',$filter=false)
    {
        if ($action == 'add') {
            unset($data->{'.id'});
        }
        $api = $this->connect();
        if (!$api) {
            return false;
        }
        try {
            $api->write($this->path . $action, false);
            foreach (array_keys((array)$data) as $key) {
                $api->write('=' . $key . '=' . $data->$key, false);
            }
            if($filter){
                $api->write($filter,false);
            }
            $api->write(';'); // trailing semi-colon works
            $this->read = $api->read();
            $api->disconnect();
            if (!$this->read || is_string($this->read)) { //don't care what's inside the string?
                $this->setMess('rosapi write:ok');
                return is_string($this->read) ? $this->read : true;
            }
            $this->setErr('rosapi write:failed', true);
            return false;
        } catch (Exception $e) {
            $api->disconnect;
            $this->setErr($e);
            return false;
        }
    }

    private function connect()
    {
        $d = $this->svc->device();
        try {
            $api = new Routerosapi();
            $api->timeout = 3;
            $api->attempts = 1;
            // $api->debug = true;
            if ($api->connect($d->ip, $d->user, $d->password)) {
                return $api;
            }
            $this->setErr('rosapi:connect failed');
            return false;
        } catch (Exception $e) {
            $this->setErr($e);
            return false;
        }
    }

    protected function setErr($msg, $obj = false)
    {
        $this->status->error = true;
        if ($obj) {
            $this->status->message = $this->read['!trap'][0]['message'];
        } else {
            $this->status->message = $msg;
        }
    }

    protected function findId():?String
    {
        return $this->exists() ? $this->insertId : false;
    }

    protected function init():void
    {
        parent::init();
        $this->insertId = null ;
        $this->exists = false ;
    }

    protected function exists():bool
    {
        $this->read('?comment');
        if ($this->read) {
            $this->findByComment();
        }
        return (bool)$this->insertId;
    }

    protected function read($filter = false)
    {  //implements mikrotik print
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
            return $this->read;
        } catch (Exception $e) {
            $api->disconnect();
            $this->setErr($e);
            return false;
        }
    }

    protected function findByComment()
    {
        $e = sizeof($this->read);
        $this->search = [];
        $this->insertId = null ;
        for ($i = 0; $i < $e; $i++) {
            $item = $this->read[$i];
            [$id] = explode(',', $item['comment'] . ',');
            if (is_numeric($id) && $id == $this->svc->id()) {
                $this->insertId = $item['.id'] ;
                $this->search[] = $item ;
                $this->entity = $item ;
            }
        }
    }


}
