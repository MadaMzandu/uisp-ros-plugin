<?php

class MT_Parent_Queue extends MT
{

    public function set(): bool
    {
        $child = $this->children();
        if ($this->svc->plan->contention < 0 && !$child) {
            return $this->delete();
        }
        if($this->svc->disabled()){
            $this->svc->plan->contention = 0;
        }
        return $this->exec();
    }

    protected function children(): int
    {
        return $this->svc->plan->children() ;
    }

    private function delete(): bool
    {
        $data['.id'] = $this->data()->{'.id'};
        $child['.id'] = $this->child()->{'.id'};
        $done = true ;
        if($this->child_exists()){
            $done = $this->write((object)$child, 'remove');
        }
        if($this->exists){
            $done = $this->write((object)$data, 'remove');
        }
        return $done ;
    }

    protected function data(): object
    {
        return (object)array(
            'name' => $this->name(),
            'max-limit' => $this->svc->plan->total()->text,
            'limit-at' => $this->svc->plan->total()->text,
            'queue' => 'pcq-upload-default/'
                . 'pcq-download-default',
            'comment' => $this->comment(),
            '.id' => $this->insertId ?? $this->name(),
        );
    }

    protected function comment(): string
    {
        return 'do not delete';
    }

    private function child(): object
    {
        return (object)array(
            'name' => $this->prefix() . '-child',
            'packet-marks' => '1stchild',
            'parent' => $this->name(),
            'max-limit' => '1M/1M',
            'limit-at' => '1M/1M',
            'comment' => $this->comment(),
            '.id' => $this->prefix() . '-child',
        );
    }

    private function exec(): bool
    {
        $set = $this->exists ? 'set' : 'add';
        $parent = $this->write($this->data(),$set);
        $set = $this->child_exists() ? 'set' : 'add';
        return $parent
            && $this->write($this->child(),$set);
    }

    public function reset($orphanId = false): bool
    { //recreates a parent queue
        $ret = $this->exec();
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

    public function update($planId): void
    {
        $count = 0 ;
        while ($this->svc->device_index > -1){
            $device = $this->svc->device();
            $data = $this->contention_data($planId,$device->id);
            if($data) {
                $this->svc->device_index = $count; //restore index for write
                $this->write($data);
            }
            $count++;
        }
    }

    private function child_exists(): bool
    {
        return (bool)
            $this->read('?name='.$this->prefix() . '-child');
    }

    private function contention_data($planId,$deviceId): ?object
    {
        $children = $this->db()->countDeviceServicesByPlanId($planId, $deviceId);
        if(!$children){return null;}
        $plan = (new Plans($planId))->list()[$planId];
        $ratio = $plan['ratio'];
        $shares = intdiv($children, $ratio);
        if(($children % $ratio) > 0){$shares++ ;}
        $ul = $plan['uploadSpeed'] * $shares;
        $dl = $plan['downloadSpeed'] * $shares;
        $rate = $ul . "M/". $dl."M" ;
        return (object)[
            '.id' => 'servicePlan-'.$planId.'-parent',
            'max-limit' => $rate,
            'limit-at' => $rate,
        ];
    }

    protected function init(): void
    {
        parent::init();
        $this->path = '/queue/simple/';
        $this->exists = $this->svc->ready && $this->exists();
    }

    protected function filter(): string
    {
        return '?name=' . $this->name();
    }

    public function name(): string
    {
        return $this->prefix() . "-parent";
    }

    protected function prefix(): string
    {
        return "servicePlan-" . $this->svc->plan->id();
    }

}
