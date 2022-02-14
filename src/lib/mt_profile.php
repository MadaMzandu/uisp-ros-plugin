<?php

class MT_Profile extends MT
{

    private $pq; //parent queue object

    public function set_profile(): bool
    {
        if ($this->svc->plan->contention < 0 && !$this->children()) {
            return $this->delete();
        }
        return $this->exec();
    }

    private function children(): bool
    {
        $this->path = '/ppp/secret/';
        $read = $this->read('?profile=' . $this->name()) ?? [];
        $count = sizeof($read) ?? 0;
        $count += $this->account_disabled() ? 0 : -1; // do not deduct if account is disabled
        $this->path = '/ppp/profile/'; //restore path before return
        return (bool)max($count, 0);
    }

    private function account_disabled(): bool
    {
        // $this->path = '/ppp/secret/'; path is already set by prev call
        $read = $this->read('?name='.$this->svc->username());
        return $read && $read[0]['profile'] == $this->conf->disabled_profile;
    }

    private function delete(): bool
    {
        if($this->exists) {
            $id['.id'] = $this->insertId ?? $this->name();
            $this->pq->set_parent()
            && $this->write((object)$id, 'remove');
        }
        return !$this->findErr('ok');
    }

    protected function data(): object
    {
        return (object)[
            'name' => $this->name(),
            'local-address' => $this->local_address(),
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
        if($this->router_disabled() || $this->conf->disable_contention){
            return 'none';
        }
        $plan = 'servicePlan-'.$this->svc->plan->id().'-parent';
        return $this->svc->disabled() ? 'none': $plan;
    }

    private function router_disabled(): bool
    {
        $id = $this->svc->device()->id ;
        $conf = $this->conf->disabled_routers ?? "[]";
        $routers = json_decode($conf,true);
        return isset($routers[$id]);
    }

    protected function rate():stdClass
    {
        $rate = parent::rate();
        if($this->name() != $this->conf->disabled_profile
            && $this->router_disabled()){
            $rate->text = null;
        }
        return $rate;
    }

    private function local_address(): ?string
    { // get one address for profile local address
        $savedPath = $this->path;
        $this->path = '/ip/address/';
        $address = null;
        if ($this->read()) {
            foreach ($this->read as $prefix) {
                if ($this->makeBool($prefix['dynamic'])
                    || $this->makeBool($prefix['invalid'])
                    || $this->makeBool($prefix['disabled'])) {
                    continue;
                }
                $address = explode('/', $prefix['address'])[0];
                break ;
            }
        }
        $this->path = $savedPath;
        return $address ?? (new API_IPv4())->local();  // or generate one
    }

    private function makeBool($value): bool
    {
        return $value == "true";
    }

    protected function findErr($success='ok'): bool
    {
        $calls = [&$this,&$this->pq];
        foreach ($calls as $call){
            if(!$call->status()->error){continue;}
            $this->status = $call->status();
            return true;
        }
        $this->setMess($success);
        return false ;
    }

    private function exec(): bool
    {
        $action = $this->exists ? 'set' : 'add';
        $orphanId = $this->orphaned();
        $orphanId
            ? $this->pq->reset($orphanId)
            : $this->pq->set_parent();
        $this->write($this->data(),$action);
        return !$this->findErr('ok');
    }

    private function orphaned(): ?string
    {
        if (!$this->exists) {
            return false;
        }
        $parent = $this->entity['parent-queue'] ?? '';
        return substr($parent, 0, 1) == '*'
            ? $parent : null;
    }

    protected function init(): void
    {
        parent::init();
        $this->path = '/ppp/profile/';
        $this->exists = $this->exists();
        $this->pq = new MT_Parent_Queue($this->svc);
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
