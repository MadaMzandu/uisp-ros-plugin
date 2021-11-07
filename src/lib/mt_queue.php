<?php

class MT_Queue extends MT
{

    private $pq; //parent queue object
    private $id;

    public function set()
    {
        if ($this->svc->contention < 0) {
            return $this->delete();
        }
        if ($this->svc->contention > 0 && !$this->exists) {
            return $this->insert();
        }
        return $this->edit();
    }

    public function delete()
    {
        $del['.id'] = $this->insertId;
        if ($this->exists &&
            !$this->write((object)$del, 'remove')) {
            return false;
        }
        if (!$this->pq->set()) {  // edit or delete parent
            $this->setErr($this->pq->error());
            return false;
        }
        return true;
    }

    public function insert()
    {
        if (!$this->pq->set()) {
            $this->setErr($this->pq->error());
            return false;
        }
        $this->insertId = $this->write($this->data(), 'add');
        return (bool)$this->insertId;
    }

    protected function data()
    {
        return (object)array(
            'name' => $this->name(),
            'target' => $this->svc->ip(),
            'max-limit' => $this->svc->rate()->text,
            'limit-at' => $this->svc->rate()->text,
            'parent' => $this->pq->name(),
            'comment' => $this->comment(),
            '.id' => $this->insertId ?? $this->name(),
        );
    }

    protected function name()
    {
        return $this->svc->client_id() . "-"
            . $this->svc->client_name() . "-"
            . $this->svc->id();
    }

    public function edit()
    {
        $orphanId = $this->orphaned();
        $action = $this->exists ? 'set' : 'add';
        $p = $orphanId
            ? ($this->pq->reset($orphanId))
            : ($this->pq->set());
        $ret = $this->write($this->data(), $action);
        $this->insertId = is_string($ret) ? $ret : $this->insertId;
        return $p && (bool)$ret;
    }

    private function orphaned(): string
    {
        if (!$this->exists()) {
            return false;
        }
        $queue = $this->search[0];
        return substr($queue['parent'], 0, 1) == '*'
            ? $queue['parent'] : false;
    }

    protected function init(): void
    {
        parent::init();
        $this->path = '/queue/simple/';
        $this->exists = $this->exists();
        $this->pq = new MT_Parent_Queue($this->svc);
    }


}
