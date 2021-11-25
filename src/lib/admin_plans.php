<?php

class Plans extends Admin
{

    private $plans; // imported service plans
    private $ids; // array of imported service plan ids
    private $unms; //uisp http query object

    public function __construct(&$data)
    {
        parent::__construct($data);
        $this->unms = new API_Unms();
        $this->ids = [];
    }

    public function get(): void
    {
        $this->readCache();
        $this->updateCache();
    }

    private function readCache(): void
    {
        $plans = $this->db()->selectAllFromTable('plans') ?? [];
        $this->result = [];
        foreach ($plans as $plan) {
            $this->result[$plan['id']] = $plan;
        }
    }

    private function updateCache(): void
    { //updating cache with uisp import
        if (!$this->readUisp()) {
            $this->set_message('failed to read plans from uisp.using cached entries');
        }
        $this->mergeCache();
        $this->pruneCache();
    }

    private function readUisp(): bool
    {
        $this->unms->assoc = true;
        $this->plans = $this->unms->request('/service-plans') ?? [];
        return (bool)$this->plans;
    }

    private function mergeCache(): void
    { // merge import into cache
        $cachedKeys = array_keys($this->result);
        $relevantKeys = ['id', 'name', 'downloadSpeed', 'uploadSpeed',
            'downloadBurst', 'uploadBurst', 'dataUsageLimit'];
        foreach ($this->plans as $plan) {
            $isNew = false;
            $this->ids[] = $plan['id']; // save for removing orphans
            if (!in_array($plan['id'], $cachedKeys)) { // new entry
                $isNew = true;
                $this->result[$plan['id']] = [];
                $this->result[$plan['id']]['ratio'] = 1; //set default contention ratio
            }
            foreach ($relevantKeys as $key) {
                $this->result[$plan['id']][$key] = $plan[$key] ?? 0;
            }
            if ($isNew) {
                $this->db()->insert($this->result[$plan['id']], 'plans');
            }
        }
    }

    private function pruneCache(): void
    { //remove orphans from cache
        $keys = array_keys($this->result);
        foreach ($keys as $key) {
            if (!in_array($key, $this->ids)) {
                unset($this->result[$key]);
                $this->db()->delete($key, 'plans');
            }
        }
    }

    public function list(): array
    {
        $this->readCache();
        $this->updateCache();
        return $this->result ?? [];
    }

    public function edit(): bool
    {
        if (!$this->db()->edit($this->data, 'plans')) {
            $this->set_error('failed to update contention ratio for service plan');
            return false;
        }
        if($this->set_queue_contention()) {
            $this->set_message('contention ratio has been updated and applied on devices');
            return true;
        }
        $this->set_error('failed to update contention on one or more devices');
        return false ;
    }

    private function set_queue_contention(): bool
    {
        $devices = $this->db()->selectAllFromTable('devices');
        $ret = true ;
        foreach($devices as $device){
            $contention = $this->contention_data($device['id']);
            if(!$contention){
                continue;
            }
            $data =(object) [
                'path' => '/queue/simple',
                'device_id' => $device['id'],
                'action' => 'set',
                'data' => $contention,
            ];
            $ret = $ret && (new MT($data,false))->set();
        }
        return $ret ;
    }

    private function contention_data($deviceId): ?object
    {
        $planId = $this->data->id ;
        $children = $this->db()->countDeviceServicesByPlanId($planId, $deviceId);
        if(!$children){return null;}
        $plan = $this->list()[$planId];
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

}
