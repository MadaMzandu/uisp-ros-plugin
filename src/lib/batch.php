<?php
include_once 'mt.php';
include_once 'mt_data.php';
include_once 'api_ip.php';
include_once 'api_sqlite.php';
include_once 'api_sites.php';

class Batch
{
    private ?array $_plans = null;
    private ?array $_devices = null ;
    private array $_apis = [];
    private array $batch_failed = [];
    private array $batch_success = [];

    public function del_queues($ids): bool
    {
        $deviceServices = $this->find_services($ids, 'delete');
        $plans = $this->find_plans();
        $deviceData = [];
        foreach (array_keys($deviceServices) as $did) {
            if ($did == 'nodev') { continue;}
            $api = $this->datapi($did);
            foreach ($deviceServices[$did] as $service) {
                $plan = $plans[$service['planId']] ?? null;
                if (!$plan) {
                    $plan = $this->make_plan($service);
                } //generate plan if not found
                $api->set_data($service, $plan);
                $queue = $api->queue();
                if($queue){
                    $queue['action'] = 'remove';
                    $deviceData[$did]['queues'][] = $queue ;
                }
            }
        }
        $this->run_batch($deviceData,true);
        return empty($this->batch_failed);
    }

    public function del_parents(): bool
    {
        $plans = $this->find_plans();
        $devices = $this->find_devices();
        $deviceData = [];
        foreach(array_keys($devices) as $did){
            foreach ($plans as $plan){
                $parent = [
                    'path' => '/queue/simple',
                    'action' => 'remove',
                    'name' => sprintf('servicePlan-%s-parent',$plan['id']),
                ];
                $deviceData[$did]['parents'][] = $parent ;
            }
        }
        $this->run_batch($deviceData,true);
        return empty($this->batch_failed);
    }

    public function del_accounts(array $ids): bool
    {
        $deviceServices = $this->find_services($ids,'delete');
        $plans = $this->find_plans();
        $deviceData = [];
        foreach (array_keys($deviceServices) as $did){
            if($did == 'nodev'){ continue; }
            $api = $this->datapi($did);
            foreach($deviceServices[$did] as $service){
                $plan = $plans[$service['planId']] ?? null;
                if(!$plan){ $plan = $this->make_plan($service); } //generate plan if not found
                $data = $api->get_data($service,$plan);
                foreach(array_keys($data) as $key){
                    $item = $data[$key];
                    $item['action'] = 'remove';
                    $deviceData[$did][$key][] = $item;
                }
            }
        }
        $this->run_batch($deviceData,true);
        $this->unsave_batch($deviceServices);
        $this->queue_failed($deviceServices);
        return empty($this->batch_failed);
    }

    public function set_queues(array $ids,$on = true)
    {
        $deviceServices = $this->find_services($ids,'update');
        $plans = $this->find_plans();
        $deviceData = [];
        $device_ids = [];
        foreach (array_keys($deviceServices) as $did) {
            $api = $this->datapi($did);
            $device_ids[] = $did;
            foreach ($deviceServices[$did] as $service) {
                if ($did == 'nodev') { continue; }
                $plan = $plans[$service['planId']] ?? null ;
                if(!$plan){ $plan = $this->make_plan($service); } //generate plan if not found
                $api->set_data($service,$plan);
                $queue = $api->queue();
                if($queue){
                    $queue['action'] = $on ? 'set' : 'remove';
                    $deviceData[$did]['queues'][] = $queue ;
                }
            }
        }
        $this->run_batch($deviceData);
        $conf = $this->conf->disabled_routers ?? null;
        $routers = $conf ? explode(',',$conf) : [];
        if(!$on){
            $routers = array_merge($routers,$device_ids);
        }
        else {
            $routers = array_diff($routers,$device_ids);
        }
        $this->db()->saveConfig(['disabled_routers' => implode(',',$routers)]);
    }

    public function set_accounts(array $ids): bool
    {
        $deviceServices = $this->find_services($ids,'update');
        $plans = $this->find_plans();
        $deviceData = [];
        foreach (array_keys($deviceServices) as $did){
            $api = $this->datapi($did);
            foreach ($deviceServices[$did] as $service){
                if($did == 'nodev'){ continue; }
                $plan = $plans[$service['planId']] ?? null ;
                if(!$plan){ $plan = $this->make_plan($service); } //generate plan if not found
                $data = $api->get_data($service,$plan);
                foreach(array_keys($data) as $key){
                    $deviceData[$did][$key][] = $data[$key];
                }
            }
        }
        $this->run_batch($deviceData);
        $this->save_batch($deviceServices);
        $this->queue_failed($deviceServices);
        return empty($this->batch_failed);
    }

    private function set_sites($ids,$delete = false)
    {
        $nms = new ApiSites();
        if($delete){ $nms->delete($ids);}
        else{ $nms->set($ids); }
    }

    private function run_batch($deviceData,$delete = false)
    {
        $timer = new ApiTimer("device batch write");
        $sent = 0 ;
        $writes = 0 ;
        $this->batch_failed = [];
        $this->batch_success = [];
        foreach (array_keys($deviceData) as $did)
        {
            $device  = $this->find_device($did);
            if(!is_object($device)){ continue; }
            $type = $device->type ?? 'mikrotik';
            $api = $this->device_api($type);
            $keys = ['pool','parents','profiles','queues','accounts','dhcp6','disconn'];
            if($delete) {
                $keys = array_reverse(array_diff($keys,['disconn']));
                $keys[] = 'disconn'; //disconnect at the end
            }
            foreach ($keys as $key){
                $item = $deviceData[$did][$key] ?? [];
                $batch = array_values($item);
                $sent += sizeof($batch);
                $writes += $api->do_batch($device,$batch);
                $this->batch_success = array_replace($this->batch_success,$api->success());
                $this->batch_failed = array_replace($this->batch_failed,$api->failed());
            }

        }
        $timer->stop();
        MyLog()->Append(sprintf('batch sent: %s written: %s',$sent,$writes));
    }

    private function save_batch($deviceServices)
    {
        if(empty($this->batch_success)){ return ;}
        $fill = array_fill_keys(['id','device','clientId',
            'planId','status'],'%%$#');
        $save = [];
        $sites = [];
        $now = date('c');
        foreach ($deviceServices as $services){
            foreach ($services as $service){
                $id = $service['batch'] ?? null ;
                $success = $this->batch_success[$id] ?? null ;
                if($success){
                    $sites[] = $service['id'] ;
                    $values = array_intersect_key($service,$fill);
                    $values['last'] = $now ;
                    $save[] = $values;
                }
            }
        }
        $this->ip_flush();
        $this->db()->insert($save,'services',INSERT_REPLACE);
        $this->set_sites($sites);
    }

    private function unsave_batch($deviceServices): void
    {
        if(empty($this->batch_success)) return ;
        $ids = [];
        foreach ($deviceServices as $services){
            foreach ($services as $service){
                $id = $service['batch'] ?? null ;
                $success = $this->batch_success[$id] ?? null ;
                if($success){
                    $ids[] = $service['id'];
                }
            }
        }
        $this->ip_flush();
        foreach(['services','network'] as $table) {
            $sql = sprintf("delete from %s where id in (%s)",$table,
                implode(',',$ids));
            $this->db()->exec($sql);
        }
        $this->set_sites($ids,true);
    }

    private function queue_failed($deviceServices)
    {
        if(empty($this->batch_failed)) return ;
        MyLog()->Append("batch queueing failed: ".sizeof($this->batch_failed));
        $fn = 'data/queue.json';
        $file = file_get_contents($fn) ?? '[]';
        $queue = json_decode($file,true);
        foreach ($deviceServices as $services){
            foreach ($services as $service){
                $batch = $service['batch'] ?? null ;
                $id = $service['id'] ?? 0 ;
                $failed = $this->batch_failed[$batch] ?? null ;
                if($failed){
                    MyLog()->Append(['batch_error',$failed,$service],6);
                    $service['error'] = $failed ;
                    $service['last'] = date('c');
                    $queue["Q$id"] = $service ;
                }
            }
        }
        file_put_contents($fn,json_encode($queue));
    }

    private function device_api($type): ?object
    {
        if(!isset($this->_apis[$type])){
            if($type != 'mikrotik'){ fail('device_invalid'); }
            $this->_apis['mikrotik'] = new MT();
        }
        return $this->_apis[$type] ?? null ;
    }

    private function datapi($did): MtData
    {
        $device = $this->find_device($did);
        if(!is_object($device)){ fail('device_invalid'); }
        $type = $device->type ?? null ;
        if($type != 'mikrotik'){ fail('device_invalid'); }
        return new MtData();
    }

    private function find_plans(): array
    {
        if(empty($this->_plans)){
            $api = new ApiList([],'plans');
            $api->list();
            $st =[
                'SELECT id,name,ratio,uploadSpeed,downloadSpeed,ifnull(priorityUpload,8) as priorityUpload,',
                'ifnull(priorityDownload,8) as priorityDownload,ifnull(timeUpload,1) as timeUpload,',
                'ifnull(timeDownload,1) as timeDownload,last,created,',
                'CASE WHEN limitUpload = 0 THEN 0 ELSE (uploadSpeed * 1000000) - ((limitUpload * uploadSpeed * 1000000) / 100) END as limitUpload,',
                'CASE WHEN limitDownload = 0 THEN 0 ELSE (downloadSpeed * 1000000) - ((limitDownload * downloadSpeed * 1000000) / 100) end as limitDownload, ',
                'CASE WHEN threshUpload = 0 THEN 0 ELSE (uploadSpeed * 1000000) - ((threshUpload * uploadSpeed * 1000000) / 100) END as threshUpload,',
                'CASE WHEN threshDownload = 0 THEN 0 ELSE (downloadSpeed * 1000000) - ((threshDownload * downloadSpeed * 1000000) / 100) END as threshDownload,',
                'CASE WHEN burstUpload = 0 THEN 0 ELSE (uploadSpeed * 1000000) + ((burstUpload * uploadSpeed * 1000000) / 100) END as burstUpload,',
                'CASE WHEN burstDownload = 0 THEN 0 ELSE (downloadSpeed * 1000000) + ((burstDownload * downloadSpeed* 1000000) / 100) END as burstDownload',
                'FROM plans',

            ];
            $plans = $this->db()->selectCustom(implode(' ',$st));
            foreach ($plans as $plan){
                $this->_plans[$plan['id']] = $plan; }
        }
        return $this->_plans ;
    }

    private function find_services(array $ids, $action): array
    {
        $data = $ids ;
        $first = array_values($ids)[0] ;
        if(!is_array($first)){
            $fields = 'services.*,clients.company,clients.firstName,clients.lastName,'.
                'network.address,network.address6';
            $sql = sprintf("SELECT %s FROM services LEFT JOIN clients ON services.clientId=clients.id ".
                "LEFT JOIN network ON services.id=network.id ".
                "WHERE services.id IN (%s) ",$fields,implode(',',$ids));
            $data = $this->dbCache()->selectCustom($sql) ?? [];
        }
        $deviceMap = [];
        $count = 0;
        $date = date('c');
        foreach ($data as $item){
            $item['action'] ??= $action ;
            $item['batch'] = $date . '-' . ++$count;
            $id = $item['device'] ?? 'nodev';
            $deviceMap[$id][] = $item ;
        }
        return $deviceMap ;
    }

    private function find_device($did): ?object
    {
        $dev = $this->find_devices()[$did] ?? null ;
        return $dev ? (object) $dev : null ;
    }

    private function find_devices(): array
    {
        if(empty($this->_devices)){
            $this->_devices = [];
            $devs = $this->db()->selectAllFromTable('devices') ?? [];
            foreach ($devs as $dev){ $this->_devices[$dev['id']] = $dev; }
        }
        return $this->_devices ;
    }

    private function ip_flush(): void
    {
        myIPClass()->flush();
    }

    private function make_plan($service): array
    {
        $keys = ['limitUpload','limitDownload', 'burstUpload','burstDownload',
            'threshUpload','threshDownload'];
        $ul = $service['uploadSpeed'] ?? 1 ;
        $dl = $service['downloadSpeed'] ?? 1 ;
        $defaults = ['ratio' => 1, 'priorityUpload' => 8,'priorityDownload' => 8,
            'timeUpload' => 1,'timeDownload' => 1, 'uploadSpeed' => $ul,'downloadSpeed' => $dl];
        $fill = array_fill_keys($keys,0);
        $plan = array_replace($fill,$defaults);
        $plan['name'] = sprintf('Custom Plan %s/%s',$ul,$dl);
        return $plan ;
    }

    private function db(): ApiSqlite
    {
        return mySqlite();
    }

    private function dbCache(): ApiSqlite
    {
        return myCache();
    }

}