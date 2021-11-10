<?php

class MT_Queue extends MT
{

    private $pq; //parent queue object
    private $id;

    public function set()
    {
        if ($this->svc->plan->contention < 0) {
            return $this->delete();
        }
        return $this->exec();
    }

    private function delete(): bool
    {
        $id['.id'] = $this->insertId;
        return $this->write((object)$id, 'remove')
                && $this->pq->set();
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

    protected function data()
    {
        return (object)array(
            'name' => $this->name(),
            'target' => $this->svc->ip(),
            'max-limit' => $this->svc->plan->rate()->text,
            'limit-at' => $this->svc->plan->rate()->text,
            'parent' => $this->pq->name(),
            'comment' => $this->comment(),
            '.id' => $this->insertId ?? $this->name(),
        );
    }

    protected function filter(): string
    {
        return '?name=' . $this->name();
    }

    protected function name()
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
        $this->exists = $this->exists();
        $this->pq = new MT_Parent_Queue($this->svc);
    }


}
