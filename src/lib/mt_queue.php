<?php

class MT_Queue extends MT
{

    private $pq; //parent queue object
    private $id;

    public function set_queue(): bool
    {
        if ($this->svc->plan->contention < 0) {
            return $this->delete();
        }
        return $this->exec();
    }

    private function delete(): bool
    {
        if($this->exists) {
            $id['.id'] = $this->insertId;
            return $this->write((object)$id, 'remove')
                && $this->pq->set();
        }
        return true;
    }

    private function exec(): bool
    {
        $action = $this->exists ? 'set' : 'add';
        $orphanId = $this->orphaned();
        $pq = $orphanId
            ? $this->pq->reset($orphanId)
            : $this->pq->set();
        return $pq && $this->write($this->data(),$action);
    }

    protected function data(): stdClass
    {
        return (object)array(
            'name' => $this->name(),
            'target' => $this->svc->ip(),
            'max-limit' => $this->rate()->text,
            'limit-at' => $this->rate()->text,
            'parent' => $this->pq_name(),
            'comment' => $this->comment(),
            '.id' => $this->insertId ?? $this->name(),
        );
    }

    protected function pq_name(): string
    {
        $plan = 'servicePlan-'.$this->svc->plan->id().'-parent';
        return $this->svc->disabled() ? 'none': $plan;
    }

    protected function filter(): string
    {
        return '?name=' . $this->name();
    }

    protected function name(): string
    {
        return $this->svc->client->id() . "-"
            . $this->svc->client->name() . "-"
            . $this->svc->id();
    }

    private function orphaned(): ?string
    {
        if (!$this->exists()) {
            return false;
        }
        $queue = $this->entity;
        return substr($queue['parent'], 0, 1) == '*'
            ? $queue['parent'] : null;
    }

    protected function init(): void
    {
        parent::init();
        $this->path = '/queue/simple/';
        if ($this->svc) {
            $this->exists = $this->exists();
            $this->pq = new MT_Parent_Queue($this->svc);
        }
    }


}
