<?php

include_once 'app_sqlite.php';
$conf = (new CS_SQLite())->readConfig();

class Device_Base {

    protected $data; // request data object
    protected $entity; // shortcut to data->extraData->entity
    protected $before; // shortcut to data->extraData->entityBeforEdit
    protected $device; // device for provisioning
    protected $status; // execution status and errors
    protected $result; // output
    protected $read; // temporary reads for processing

    public function __construct(&$data) {
        $this->data = $data;
        $this->init();
    }

    public function status() {
        return $this->status;
    }

    protected function getDevice() {
        global $conf;
        $name = $this->{$this->data->actionObj}->{$conf->device_name_attr};
        $db = new CS_SQLite();
        $this->device = $db->selectDeviceByDeviceName($name);
        if (!$this->device) {
            $this->set_error('could not find device configuration');
            return false;
        }
        return true;
    }

    protected function comment() {   //comment
        return $this->entity->id . ','
                . $this->entity->clientId
                . "-" . $this->data->clientName;
    }

    protected function error() {
        return $this->status->message;
    }

    protected function rate() {
        return $this->entity->uploadSpeed . 'M/'
                . $this->entity->downloadSpeed . 'M';
    }

    protected function init() {
        if (is_object($this->data)) {
            $this->entity = &$this->data->extraData->entity; // shortcuts
            $this->before = &$this->data->extraData->entityBeforeEdit;
            $this->data->actionObj = 'entity';
        }
        $this->status = (object) [];
        $this->status->session = false;
        $this->status->error = false;
    }

    protected function set_message($msg) {
        $this->status->error = false;
        $this->status->message = $msg;
    }

    protected function set_error($msg, $obj = false) {
        $this->status->error = true;
        if ($obj) {
            $this->status->message = $this->read['!trap'][0]['message'];
        } else {
            $this->status->message = $msg;
        }
    }

    protected function debug($message) {
        global $debug_log;
        $debug_log[] = (new DateTime())->format('Y-m-d H:i:s ') . $message . "\n";
    }

}
