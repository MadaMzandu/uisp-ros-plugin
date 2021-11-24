<?php

class MT_Profile extends MT
{

    private $pq; //parent queue object

    public function set_profile(): bool
    {
        if ($this->svc->plan->contention < 0 && !$this->children()) {
            return $this->delete();
        }
        return $this->set_account();
    }

    private function children()
    {
        $this->path = '/ppp/secret/';
        $read = $this->read('?profile=' . $this->name()) ?? [];
        $disabled = $this->entity_disabled();
        $this->path = '/ppp/profile/';
        $count = sizeof($read) ?? 0;
        $count += $disabled ? 0 : -1; // do not deduct if account is disabled
        return (bool)max($count, 0);
    }

    private function entity_disabled(): bool
    {
        // $this->path = '/ppp/secret/'; path is already set by prev call
        $read = $this->read('?comment');
        $id = (string)$this->svc->id();
        foreach ($read as $item) {
            if (substr($item['comment'], 0, strlen($id)) == $id) {
                return $item['profile'] == $this->conf->disabled_profile;
            }
        }
        return false;
    }

    private function delete(): bool
    {
        if($this->exists) {
            $id['.id'] = $this->name();
            return $this->pq->set()
            && $this->write((object)$id, 'remove');
        }
        return true;
    }

    protected function data(): object
    {
        return (object)[
            'name' => $this->name(),
            'local-address' => $this->local_addr(),
            'rate-limit' => $this->rate()->text,
            'parent-queue' => $this->pq_name(),
            'address-list' => $this->address_list(),
            '.id' => $this->name(),
        ];
    }

    private function address_list():string
    {
        return $this->svc->disabled() ? $this->conf->disabled_list
            : $this->conf->active_list ;
    }

    private function pq_name(): ?string
    {
        if($this->router_disabled()){
            return 'none';
        }
        $plan = 'servicePlan-'.$this->svc->plan->id().'-parent';
        return $this->svc->disabled() ? 'none': $plan;
    }

    private function router_disabled(): bool
    {
        $id = $this->svc->device()->id ;
        $disabled_routers = json_decode($this->conf->disabled_routers);
        return isset($disabled_routers[$id]);
    }

    protected function rate():stdClass
    {
        $rate = parent::rate();
        if($this->router_disabled()){
            $rate->text = null;
        }
        return $rate;
    }

    private function local_addr()
    { // get one address for profile local address
        $savedPath = $this->path;
        $this->path = '/ip/address/';
        if ($this->read()) {
            foreach ($this->read as $prefix) {
                if ($prefix['dynamic'] == 'true') {
                    continue;
                }
                [$addr] = explode('/', $prefix['address']);
                $this->path = $savedPath;
                return $addr;
            }
        }
        $this->path = $savedPath;
        return false;
    }

    protected function findErr()
    {
        if ($this->pq->status()->error) {
            $this->status = $this->pq->status();
        }
    }

    private function set_account(): bool
    {
        $action = $this->exists ? 'set' : 'add';
        $orphanId = $this->orphaned();
        $p = $orphanId
            ? $this->pq->reset($orphanId)
            : $this->pq->set();
        $m = $this->svc->move ;
        $d = $this->data();
        $w = $this->write($d,$action);
        return $p && $w ;
    }

    private function orphaned(): ?string
    {
        if (!$this->exists()) {
            return false;
        }
        $profile = $this->entity;
        return substr($profile['parent-queue'], 0, 1) == '*'
            ? $profile['parent-queue'] : null;
    }

    protected function init(): void
    {
        parent::init();
        $this->path = '/ppp/profile/';
        if($this->svc) {
            $this->exists = $this->exists();
            $this->pq = new MT_Parent_Queue($this->svc);
        }
    }

    protected function filter(): string
    {
        return '?name=' . $this->name();
    }

    protected function name()
    {
        return $this->svc->disabled()
            ? $this->conf->disabled_profile
            : $this->svc->plan->name();
    }

}
