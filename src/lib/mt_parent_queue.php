<?php

class MT_Parent_Queue extends MT
{

    public function set_parent(): bool
    {
        if ($this->conf->disable_contention) {
            return true;
        }
        if ($this->svc->plan->contention < 0 && !$this->children()) {
            return $this->delete();
        }
        if ($this->svc->disabled()) {
            $this->svc->plan->contention = 0;
        }
        return $this->exec();
    }

    protected function children(): int
    {
        return $this->svc->plan->children();
    }

    private function delete(): bool
    {
        $data['.id'] = $this->data()->{'.id'};
        if ($this->exists) {
            $this->write((object)$data, 'remove');
        }
        return !$this->findErr();
    }

    protected function findErr($success = 'ok'): bool
    {
        if ($this->status->error) {
            return true;
        }
        $this->setMess($success);
        return false;
    }

    protected function data(): stdClass
    {
        return (object)array(
            'name' => $this->name(),
            'target' => $this->target(),
            'max-limit' => $this->svc->plan->total()->text,
            'limit-at' => $this->svc->plan->total()->text,
            'queue' => 'pcq-upload-default/'
                . 'pcq-download-default',
            'comment' => $this->comment(),
            '.id' => $this->insertId ?? $this->name(),
        );
    }

    protected function target(): ?string
    {
        $hosts = $this->svc->plan->target() ?? [];
        $ip = $this->svc->ip() ?? null;
        if ($ip) {
            if ($this->svc->plan->contention < 0) {
                unset($hosts[$ip]);
            } else {
                $hosts[$ip] = $ip;
            }
        }
        return implode(',', $hosts);
    }

    protected function comment(): string
    {
        return 'do not delete';
    }

    private function exec(): bool
    {
        $action = $this->exists ? 'set' : 'add';
        $this->write($this->data(), $action);
        return !$this->findErr('ok');
    }

    public function reset($orphanId = null): bool
    { //recreates a parent queue
        if (!$this->exec()) {
            return false;
        }
        if ($orphanId) { //update orphan children
            $orphans = $this->read('?parent=' . $orphanId);
            foreach ($orphans as $item) {
                $data = (object)['parent' => $this->name(), '.id' => $item['.id']];
                if (!$this->write($data)) {
                    return false;
                }
            }
        }
        return true;
    }

    protected function init(): void
    {
        parent::init();
        $this->path = '/queue/simple/';
        $this->exists = $this->exists();
    }

    protected function filter(): string
    {
        return '?name=' . $this->name();
    }

    public function name(): string
    {
        return $this->prefix() . "-parent";
    }

    protected function prefix(): string
    {
        return "servicePlan-" . $this->svc->plan->id();
    }

}