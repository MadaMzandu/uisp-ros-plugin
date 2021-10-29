<?php

include_once 'device_base.php';

class Device_Account extends Device_Base {

    protected $save ; //data for save

    protected function init() {
        parent::init();
        $this->save = [];
    }

    protected function save() {
        return $this->svc->save($this->save);
    }

    protected function comment() {
        return $this->svc->id() . ", "
                . $this->svc->client_id() . " - "
                . $this->svc->client_name();
    }

    protected function rate() {
        return $this->svc->rate();
    }

    protected function insertId() {
        return false;
    }

}
