<?php

class MT_Profile extends MT
{

    private $pq; //parent queue object
    private $profiles ;
    private $cache ;
    private $child ;


    public function apply($data)
    {
        $this->child = $data;
        $this->exists = $this->exists();
        $contentions = ['rename' => 0,'edit' => 0,'delete' => -1];
        $action = $this->svc->action ;
        //if($this->svc->unsuspend) $action = 'unsuspend';
        if($action == 'edit' && $this->svc->disabled()) return true ;  //forget this scenario
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
        $name = $this->child['profile'] ?? null ;
        $children = $this->cache[$name]['children'] ?? 2; // pretend we have children on fail
        if(!max(--$children,0)){
            $this->set_batch(
                ['.id' => $name,
                    'action' => 'remove'
                    ]
            );
        }
        return true ;
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
            $child = $this->find_last() ?? $this->entity;
            $this->pq->apply($child)
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
        /*$orphanId = $this->orphaned();
        $orphanId
            ? $this->pq->reset($orphanId)
            : $this->pq->apply();*/
        $child = $this->find_last() ?? $this->entity ;
        $this->pq->apply($child);
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
		$this->entity = [];
        if($this->read_profiles()){
            $action = $this->svc->action ;
            if(!in_array($action,['insert','suspend'])){
                $this->entity = $this->find_last();
            }
			if(!$this->entity)$this->entity =  $this->find_profile();
            $this->insertId = $this->entity['.id'] ?? null ;
            if($this->insertId) return true ;
        }
        $this->entity = $this->add_profile();
        return false;
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

    private function children(): int
    {
        $count = $this->entity['children'] ?? 0 ;
        $children = $count + $this->svc->plan->contention ;
        return max($children,0);
    }

    private function series(): int
    {
        $re = '/' . $this->base_name() . '/' ;
        $m = preg_grep($re,array_keys($this->cache));
        return sizeof($m) ?? 0 ;
    }

    private function find_profile(): ?array
    {
        $re = '/' . $this->base_name() . '/' ;
        foreach($this->cache as $profile){
            if(preg_match($re,$profile['name'])){
                $size = $profile['children'] ?? 0;
                if($size < 128)return $profile ;
            }
        }
        return null ;
    }

    private function find_last(): ?array
    {
        $name = $this->find_name();
        if($name){
            return $this->cache[$name] ?? null ;
        }
        return null ;
    }

    private function find_name(): ?string
    {
        return $this->child['profile'] ?? null ;
    }

    private function add_profile()
    {
        $series = $this->series();
        $suffix = null ;
        if($series) $suffix = '-' . $series;
        $name = $this->base_name() . $suffix ;
        return ['name' => $name,'children'=> 0];
    }

    private function count_children(): array
    {
        $this->path = '/queue/simple/';
        $filter = '?parent=none';
        $array = [];
        $read = $this->read($filter) ?? [];
        $this->path = $this->path();
        foreach ($read as $parent){
            $children = explode(',',$parent['target']) ?? [];
            $array[$parent['name']] = sizeof($children) ?? 0 ;
        }
        return $array ;
    }


    private function read_profiles(): bool
    {
        $this->cache = [];
        $children = $this->count_children();
        $read = $this->read() ?? [];
        foreach($read as $profile){
            $queue = $profile['parent-queue'] ?? 'none';
            $profile['children'] = $children[$queue] ?? 1 ;
            $this->cache[$profile['name']] = $profile ;

        }
        return (bool) $this->cache ;
    }

}
