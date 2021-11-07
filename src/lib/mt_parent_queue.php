<?php

class MT_Parent_Queue extends MT
{

    private $devices;

    public function set(): bool
    {
        if ($this->svc->contention < 0 && !$this->children()) {
            return $this->delete();
        }
        if (!$this->exists) {
            return $this->insert();
        }
        return $this->edit();
    }

    protected function children(): int
    {
        return $this->svc->plan_children() ?? 0;
    }

    private function delete(): bool
    {
        $data['.id'] = $this->data()->{'.id'};
        $child['.id'] = $this->child()->{'.id'};
        return $this->write((object)$child, 'remove') &&
            $this->write((object)$data, 'remove');
    }

    protected function data(): object
    {
        return (object)array(
            'name' => $this->name(),
            'max-limit' => $this->rate()->text,
            'limit-at' => $this->rate()->text,
            'queue' => 'pcq-upload-default/'
                . 'pcq-download-default',
            'comment' => $this->comment(),
            '.id' => $this->insertId ?? $this->name(),
        );
    }

    protected function rate()
    {
        return $this->svc->plan_rate();
    }

    protected function comment(): string
    {
        return 'do not delete';
    }

    private function child(): object
    {
        return (object)array(
            'name' => $this->prefix() . '-child',
            'packet-marks' => '1stchild',
            'parent' => $this->name(),
            'max-limit' => '1M/1M',
            'limit-at' => '1M/1M',
            'comment' => $this->comment(),
            '.id' => $this->prefix() . '-child',
        );
    }

    private function insert(): bool
    {
        $this->insertId = $this->write($this->data(), 'add');
        return $this->insertId
            && $this->write($this->child(), 'add');
    }

    private function edit(): bool
    {
        return $this->write($this->child()) &&
            $this->write($this->data());
    }

    public function reset($orphanId = false): bool
    { //recreates a parent queue
        $this->svc->contention = 0;
        $this->delete();
        $insert = $this->insert() ?? false;
        if ($orphanId && $insert) { //update orphan children
            $orphans = $this->read('?parent=' . $orphanId);
            foreach ($orphans as $item) {
                $data = (object)['parent' => $this->name(), '.id' => $item['.id']];
                if (!$this->write($data)) {
                    return false;
                }
            }
        }
        return $insert;
    }

    public function update_old($id)
    {
        $devices = $this->db()->selectAllFromTable('devices');
        if (!$devices) {
            $this->setErr('parent queue module failed to read devices');
            return;
        }
        $this->data = (object)[];
        $this->data->actionObj = 'entity';
        $this->entity = (object)[];
        $this->entity->servicePlanId = $id;
        foreach ($this->devices as $device) {
            $this->entity->{$this->conf->device_name_attr} = $device['name'];
            if (!$this->children()) {
                continue;
            }
            $this->editQueue();
        }
    }

    protected function init(): void
    {
        parent::init();
        $this->path = '/queue/simple/';
        $this->exists = $this->exists();
    }

    protected function exists(): bool
    {
        $this->read('?name=' . $this->name());
        $this->entity = $this->read[0] ?? null;
        $this->insertId = $this->read[0]['.id'] ?? null;
        return (bool)$this->insertId;
    }

    public function name(): string
    {
        return $this->prefix() . "-parent";
    }

    protected function prefix(): string
    {
        return "servicePlan-" . $this->svc->plan_id();
    }

}
