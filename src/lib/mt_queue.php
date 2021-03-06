<?php

class MT_Queue extends MT
{

    private $pq; //parent queue object

    public function set_queue(): bool
    {
        if ($this->svc->plan->contention < 0) {
            return $this->delete();
        }
        return $this->exec();
    }

    protected function findErr($success = 'ok')
    {
        $calls = [&$this, &$this->pq];
        foreach ($calls as $call) {
            if ($call->status()->error) {
                $this->status = $call->status();
                return true;
            }
        }
        $this->setMess($success);
        return false;
    }

    private function delete(): bool
    {
        if ($this->exists) {
            $id['.id'] = $this->insertId;
            $this->write((object)$id, 'remove')
            && $this->pq->apply($this->entity);
        }
        return !$this->findErr('ok');
    }

    private function exec(): bool
    {
        $action = $this->exists ? 'set' : 'add';
        /*$orphanId = $this->orphaned();
        $orphanId
            ? $this->pq->reset($orphanId)
            : $this->pq->apply($this->entity);*/
        $this->pq->apply($this->entity);
        $this->write($this->data(), $action);
        return !$this->findErr('ok');
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
        if ($this->conf->disable_contention) {
            return 'none';
        }
        return $this->svc->disabled() ? 'none' : $this->pq->name();
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
        if (!$this->exists) {
            return null;
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
