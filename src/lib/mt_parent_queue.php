<?php

class MT_Parent_Queue extends MT {

    private $devices;

    public function __construct(Service &$svc) {
        parent::__construct($svc);
        $this->path = '/queue/simple/';
    }

    public function set() {
        if ($this->svc->count > 0 && !$this->exists()) {
            return $this->insert();
        }
        if ($this->svc->count < 0 && !$this->children()) {
            return $this->delete();
        }
        return $this->edit();
    }

    public function update($id) {
        global $conf;
        if (!$this->read_devices()) {
            $this->set_error('parent queue module failed to read devices');
            return;
        }
        $this->data = (object) [];
        $this->data->actionObj = 'entity';
        $this->entity = (object) [];
        $this->entity->servicePlanId = $id;
        foreach ($this->devices as $device) {
            $this->entity->{$conf->device_name_attr} = $device['name'];
            if (!$this->has_children()) {
                continue;
            }
            $this->editQueue();
        }
    }

    protected function data() {
        return $data = (object) array(
                    'name' => $this->name(),
                    'max-limit' => $this->rate()->text,
                    'limit-at' => $this->rate()->text,
                    'queue' => 'pcq-upload-default/'
                    . 'pcq-download-default',
                    'comment' => $this->comment(),
                    '.id' => $this->name(),
        );
    }
    
    public function child() {
        return $data = (object) array(
                    'name' => $this->prefix() . '-child',
                    'packet-marks' => '1stchild',
                    'parent' => $this->name(),
                    'max-limit' => '1M/1M',
                    'limit-at' => '1M/1M',
                    'comment' => $this->comment(),
                    '.id' => $this->prefix() . '-child',
        );
    }

    private function insert() {
        return !$this->write($this->data(), 'add') ?: $this->child_insert();
    }

    private function child_insert() {
        return $this->write($this->child(), 'add');
    }

    private function edit() {
        return $this->write($this->data());
    }

    private function delete() {
        $data['.id'] = $this->data()->{'.id'};
        return !$this->child_delete() ?: $this->write((object) $data, 'remove');
    }

    private function child_delete() {
        $data['.id'] = $this->child()->{'.id'};
        return $this->write((object) $data, 'remove');
    }

    protected function exists() {
        $this->read('?name=' . $this->name());
        if ($this->read) {
            return true;
        }
        return false;
    }

    protected function rate() {
        return $this->svc->plan_rate();
    }

    protected function children() {
        return $this->svc->plan_children();
    }

    protected function comment() {
        return 'do not delete';
    }

    protected function prefix() {
        return "servicePlan-" . $this->svc->plan_id();
    }

    public function name() {
        return $this->prefix() . "-parent";
    }

}
