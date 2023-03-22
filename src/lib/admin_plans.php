<?php

include_once 'admin_mt_contention.php';
class AdminPlans extends Admin
{

    public function get()
    {
        $this->result = $this->update();
    }

    private function merge()
    {
        $plans = $this->get_plans();
        $config = $this->get_config() ;
        $keys = ['ratio','burstUpload','burstDownload',
            'threshUpload','threshDownload','timeUpload','timeDownload'];
        foreach($plans as $plan){
            $id = $plan['id'] ;
            foreach($keys as $key){
                $plans[$id][$key] = $config[$id][$key] ?? 0 ;
            }
        }
        return $plans ;
    }

    private function update(): array
    {
        $plans = $this->merge();
        $ids = array_keys($plans);
        $delete_expired = sprintf('DELETE FROM plans WHERE id NOT IN (%s)',implode(',',$ids)) ;
        $this->db()->exec($delete_expired);
        $keys = "id,ratio,burstUpload,burstDownload,threshUpload,threshDownload,timeUpload,timeDownload";
        $update = sprintf("INSERT OR IGNORE INTO plans (%s) VALUES ",$keys);
        $values = [];
        foreach ($plans as $plan){
            $row = [];
            foreach(explode(',',$keys) as $key) {
                $row[] = $plan[$key] ?? 0 ;
            }
            $values[] = sprintf("(%s)",implode(',',$row));
        }
        $update .= implode(',',$values);
        $this->db()->exec($update);
        return $plans;
    }

    private function get_plans(): array
    {
        $u = $this->ucrm();
        $u->assoc = true;
        $read = $this->ucrm()->get('service-plans');
        $tmp = [];
        foreach ($read as $item) {
            $tmp[$item['id']] = $item ;
        }
        return $tmp ;
    }

    private function get_config(): array
    {
        $read = $this->db()->selectAllFromTable('plans') ?? [];
        $tmp = [];
        foreach($read as $item){
            $tmp[$item['id']] = $item ;
        }
        return $tmp ;
    }

/*    private function readCache(): void
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
    }*/

    public function list(): array
    {
        return $this->update();
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
            $plan))->update();
    }

}
