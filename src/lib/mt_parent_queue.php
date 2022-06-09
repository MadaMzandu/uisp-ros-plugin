<?php

class MT_Parent_Queue extends MT
{
    private $parents = [];
    private $child = [];

    public function apply($data=[]): bool
    {
        $action = $this->svc->action ;
        if(!$this->read_child($data) && $action == 'action')
            return $this->setErr("missing profile or child data") ;
        if($action == 'suspend'
            && $this->svc->unsuspend) $action = 'unsuspend';
        $mode = $action == 'delete' ? 1 : 0 ;
        $this->svc->mode($mode);
        $contention = in_array($action,['insert','unsuspend']) ? 1 : -1;
        if($action == 'edit') $contention = 0;
        $this->svc->plan->contention = $contention ;
        if(!$this->children() && $contention < 0)
            return $this->delete() ;
        else return $this->exec();
    }

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
        $size = sizeof($this->entity['targets']) ?? 0;
        $size += $this->svc->plan->contention ;
        return max($size,0) ;
    }

    private function delete(): bool
    {
        $data['.id'] = $this->data()->{'.id'} ;
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
            'max-limit' => $this->total(),
            'limit-at' => $this->total(),
            'queue' => 'pcq-upload-default/'
                . 'pcq-download-default',
            'comment' => $this->comment(),
            '.id' => $this->insertId ?? $this->name(),
        );
    }

    protected function target(): ?string
    {
        $hosts = $this->entity['targets'] ?? [];
        $ip = $this->svc->ip() ;
        $old_ip = $this->svc->old_ip() ;
        if ($ip) {
            if ($this->svc->plan->contention < 0) {
                unset($hosts[$ip]);
            } else {
                if($old_ip && $ip != $old_ip)
                    unset($hosts[$old_ip]);
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
    }

    protected function exists(): bool
    {
        if($this->read_parents() && $this->find_parent())
            return true ;
        return $this->add_parent() ;
    }

    protected function filter(): string
    {
        return '?name=' . $this->name();
    }

    public function name(): ?string
    {
        return $this->entity['name'] ?? null ;
    }

    protected function prefix(): string
    {
        return "servicePlan-" . $this->svc->plan->id();
    }

    private function base_name(): string
    {
        return $this->prefix() . "-parent";
    }

    private function read_child($data): bool
    {
        if(is_array($data)) {
            foreach (array_keys($data) as $key) {
                $this->child[$key] = $data[$key];
            }
        }
        $this->exists = $this->exists();
        return $this->exists;
    }

    private function add_parent(): bool
    {
        $suffix = null ;
        $size = sizeof($this->parents);
        if($size) $suffix = '-' . $size ;
        $this->entity['name'] = $this->base_name() . $suffix ;
        $this->entity['targets'] = [];
        return false ;
    }

    private function total(): string
    {
        $rate = $this->svc->plan->rate();
        $shares = $this->shares() ;
        $u = $rate->upload * $shares;
        $d = $rate->download * $shares ;
        return $u . "M/" . $d . "M" ;
    }

    private function shares(): int
    {
        $ratio = $this->svc->plan->ratio();
        $children = $this->children();
        $shares = intdiv($children,$ratio);
        if($children % $ratio > 0) $shares++;
        return max($shares,1);
    }

    private function find_parent(): bool
    {
        $entity = [];
        $name = $this->find_name();
        if($name) {
            $entity = $this->parents[$name] ?? [];
        }
        if(!$entity) {
            foreach($this->parents as $p){
                if(sizeof($p['targets']) < 128){
                    $entity = $p ;
                    break ;
                }
            }
        }
        $this->entity = $entity ;
        $this->insertId = $entity['.id'];
        return (bool) $this->insertId ;
    }

    private function find_name(): ?string
    {
        return $this->child['parent']
            ?? $this->child['parent-queue'] ?? null ;
    }

    private function read_parents(): bool
    {
        $this->parents = [];
        $filter = '?parent=none';
        $read = $this->read($filter);
        foreach($read as $q) {
            $re = '/' . $this->base_name() . '/';
            if(preg_match($re,$q['name'])){
                foreach(explode(',',$q['target']) as $sn){
                    $ip = explode('/',$sn)[0] ?? null;
                    $q['targets'][$ip] = $ip;
                }
                $this->parents[$q['name']] = $q ;
            }
        }
        return (bool) $this->parents ;
    }

}
