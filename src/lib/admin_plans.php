<?php

include_once 'admin_mt_contention.php';
class Plans extends Admin
{

    private $plans; // imported service plans
    private $ids; // array of imported service plan ids
    private $unms; //uisp http query object

    protected function init(): void
    {
        parent::init();
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
        return (
            $this->db()->edit($this->data, 'plans')
            && $this->set_contention()
            && $this->set_message('contention ratio has been updated and applied on devices'))
            or $this->set_error('failed to update contention on one or more devices');
    }

    private function set_contention(): bool
    {
        if($this->conf->disable_contention){
            return true ;
        }
        $plan = $this->list()[$this->data->id] ?? null;
        if(!$plan){
            return $this->set_error('failed to retrieve service plan data');
        }
        return (new Admin_Mt_Contention(
            $plan,false))->update();
    }

}
