<?php

class MT_Parent_Queue extends MT
{
    private $parents = [];
    private $child = [];

    public function apply($data=[]): bool
    {
        $this->child = $data ;
        $this->set_mode();
        $this->exists = $this->exists();
        $this->set_contention();
        if(!$this->children() && $this->svc->plan->contention < 0)
            return $this->delete() ;
        return $this->exec();
    }

    private function set_contention(): void
    {
        $action = $this->svc->action ;
        if($this->svc->unsuspend) $action = 'unsuspend';
        $c = in_array($action,['insert','unsuspend']) ? 1 : -1;
        if(in_array($action,['edit','rename'])) $c = 0;
        $this->svc->plan->contention = $c ;
    }

    private function set_mode(): void
    {
        $action = $this->svc->action ;
        $mode = in_array($action,['delete','suspend']) ? 1 : 0 ;
        $this->svc->mode($mode);
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
        return !$this->findErr();
    }

    /*public function reset($orphanId = null): bool
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
    }*/

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

    private function add_parent(): bool
    {
        $suffix = null ;
        $size = sizeof($this->parents);
        if($size) $suffix = '-' . $size ;
        $this->entity = [];
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
        $this->insertId = $entity['.id'] ?? null ;
        return (bool) $this->insertId ;
    }

    private function find_name(): ?string
    {
        $action = $this->svc->action ;
        if(!in_array($action,['insert','unsuspend'])){
            return $this->child['parent']
                ?? $this->child['parent-queue'] ?? null;
        }
        return null ;
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
