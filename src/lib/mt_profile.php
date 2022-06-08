<?php

class MT_Profile extends MT
{

    private $pq; //parent queue object
    private $profiles ;
    private $secret ;
    private $disabled ;

    public function apply($data)
    {
        $this->exists = $this->read_child($data);
        $contentions = ['rename' => 0,'edit' => 0,'delete' => -1];
        $action = $this->svc->action ;
        $this->svc->plan->contention = $contentions[$action] ?? 1;
        if($this->svc->plan->contention < 0 && !$this->children()){
            return $this->delete();
        }
        return $this->check_suspend() && $this->exec();
    }

    public function set_profile(): bool
    {
        if ($this->svc->plan->contention < 0 && !$this->children()) {
            return $this->delete();
        }
        return $this->exec();
    }

    private function check_suspend(): bool
    {
        $action = $this->svc->action ;
        if(!in_array($action,['suspend','unsuspend']))
            return true ;
        $name = $this->secret['profile'] ?? null ;
        $children = $this->count_secrets($name) ?? 2; // pretend we have children on fail
        if(!max(--$children,0)){
            $this->set_batch(
                ['.id' => $name,
                    'action' => 'remove'
                    ]
            );
        }
        return true ;
    }

    private function children(): bool
    {
        $count = $this->entity['children'] ?? 0 ;
        $children = $count + $this->svc->plan->contention ;
        return max($children,0);
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
            $data['.id'] = $this->insertId ?? $this->name();
            $data['action'] = 'remove';
            $this->set_batch($data);
            $this->pq->apply()
            && $this->write();
        }
        return !$this->findErr('ok');
    }

    protected function data($action): object
    {
        return (object)[
            'action' => $action,
            'name' => $this->name(),
            'local-address' => $this->local_address(),
            'rate-limit' => $this->rate()->text,
            'parent-queue' => $this->pq_name(),
            'address-list' => $this->address_list(),
            '.id' => $this->insertId ?? $this->name(),
        ];
    }

    private function address_list():string
    {
        return $this->svc->disabled() ? $this->conf->disabled_list
            : $this->conf->active_list ;
    }

    private function pq_name(): ?string
    {
        if ($this->router_disabled()
            || $this->svc->disabled()
            || $this->conf->disable_contention) {
            return 'none';
        }
        return $this->pq->name();
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
            : $this->pq->apply();
        $this->set_batch($this->data($action));
        $this->write();
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
        $this->pq = new MT_Parent_Queue($this->svc);
    }

    protected function exists(): bool
    {
        if($this->read_profiles()
            && $this->find_profile()) return true ;
        $this->add_profile();
        return false ;
    }

    protected function filter(): string
    {
        return '?name=' . $this->name();
    }

    protected function base_name()
    {
        return $this->svc->disabled()
            ? $this->conf->disabled_profile
            : $this->svc->plan->name();
    }

    public function name(): ?string
    {
        return $this->entity['name'] ?? null;
    }

    private function count_secrets($profile): int
    {
        $this->path = '/ppp/secret/';
        $read = $this->read('?profile=' . $profile) ?? [];
        $this->path = '/ppp/profile/';
        return sizeof($read) ?? 0;
    }

    private function read_child($data)
    {
        $this->secret = [] ;
        if(is_array($data)){
            foreach(array_keys($data) as $key){
                $this->secret[$key] = $data[$key];
            }
        }
        return $this->exists() ;
    }

    private function find_profile(): bool
    {
        $name = $this->secret['profile'] ?? null;
        $entity = [] ;
        if($name){
            $entity = $this->profiles[$name] ?? [];
        }else {
            foreach($this->profiles as $p){
                if($p['children'] < 128){
                    $entity = $p ;
                    break ;
                }
            }
        }
        $this->entity = $entity ;
        $this->insertId = $entity['.id'] ?? null ;
        return (bool) $this->insertId ;
    }

    private function add_profile()
    {
        $series = sizeof($this->profiles) ?? 0;
        $suffix = null ;
        if($series) $suffix = '-' . $series;
        $name = $this->base_name() . $suffix ;
        $this->entity['name'] = $name ;
    }

    private function read_profiles(): bool
    {
        $this->profiles = [];
        $read = $this->read();
        foreach($read as $p){
            $re = '/' . $this->base_name() . '/';
            if(preg_match($re,$p['name'])){
                $p['children'] = $this->count_secrets($p['name']);
                $this->profiles[$p['name']] = $p ;
            }
        }
        return (bool) $this->profiles ;
    }

}
