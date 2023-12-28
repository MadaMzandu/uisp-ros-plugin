<?php
include_once 'er.php';
include_once 'mt.php';
include_once 'er_data.php';
include_once 'mt_data.php';
include_once 'api_ip.php';
include_once 'api_sqlite.php';
include_once 'api_sites.php';

class Batch
{
    private ?array $_plans = null;
    private ?array $_devices = null ;
    private ?ApiSqlite $_db = null ;
    private ?ApiSqlite $_cache = null ;
    private array $_apis = [];
    private array $batch_failed = [];
    private array $batch_success = [];

    public function del_queues($ids)
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
        MyLog()->Append('Queues ready to delete');
        $this->run_batch($deviceData,true);
    }

    public function del_parents()
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
    }

    public function del_accounts(array $ids)
    {
        $deviceServices = $this->select_data($ids,'delete');
        $plans = $this->find_plans();
        $deviceData = [];
        foreach (array_keys($deviceServices) as $did){
            if($did == 'nodev'){ continue; }
            $api = $this->datapi($did);
            foreach($deviceServices[$did] as $service){
                $plan = $plans[$service['planId']] ?? null;
                if(!$plan){ $plan = $this->make_plan($service); } //generate plan if not found
                $api->set_data($service,$plan);
                $account = $api->account();
                if($account){
                    $account['action'] = 'remove';
                    $deviceData[$did]['accounts'][] = $account ;
                }
                $queue = $api->queue();
                if($queue){
                    $queue['action'] = 'remove';
                    $deviceData[$did]['queues'][] = $queue ;
                }
                $profile = $api->profile();
                if($profile){
                    $profile['action'] = 'remove';
                    $deviceData[$did]['profiles'][$profile['name']] = $profile ;
                }
                $parent = $api->parent();
                if($parent){
                    $parent['action'] = 'remove';
                    $deviceData[$did]['parents'][$parent['name']] = $parent ;
                }
                $disconnect = $api->account_reset();
                if($disconnect){$deviceData[$did]['disconn'][] = $disconnect; }
                $pool = $api->pool();
                if ($pool){
                    $pool['action'] = 'remove';
                    $deviceData[$did]['pool']['uisp_pool'] = $pool;
                }
                $dhcp6 = $api->dhcp6();
                if($dhcp6){
                    $dhcp6['action'] = 'remove';
                    $deviceData[$did]['accounts'][] = $dhcp6;
                }
            }
        }
        unset($api);
        MyLog()->Append('services ready to delete');
        $this->run_batch($deviceData,true);
        $this->unsave_batch($deviceServices);
        $this->queue_failed($deviceServices);
    }

    public function set_queues(array $ids,$on = true)
    {
        $deviceServices = $this->select_data($ids,'update');
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

    public function set_accounts(array $ids)
    {
        $deviceServices = $this->select_data($ids,'update');
        $plans = $this->find_plans();
        $deviceData = [];

        foreach (array_keys($deviceServices) as $did){
            $api = $this->datapi($did);
            foreach ($deviceServices[$did] as $service){
                if($did == 'nodev'){ continue; }
                $plan = $plans[$service['planId']] ?? null ;
                if(!$plan){ $plan = $this->make_plan($service); } //generate plan if not found
                $api->set_data($service,$plan);
                $account = $api->account();
                if(!$account){ continue; }
                $deviceData[$did]['accounts'][] = $account ; 
                $queue = $api->queue();
                if($queue){ $deviceData[$did]['queues'][] = $queue ; }
                $profile = $api->profile();
                if($profile){ $deviceData[$did]['profiles'][$profile['name']] = $profile ; }
                $parent = $api->parent();
                if($parent){ $deviceData[$did]['parents'][$parent['name']] = $parent ; }
                $disconnect = $api->account_reset();
                if($disconnect){$deviceData[$did]['disconn'][] = $disconnect; }
                $pool = $api->pool();
                if($pool){$deviceData[$did]['pool']['uisp_pool'] = $pool; }
                $dhcp6 = $api->dhcp6();
                if($dhcp6){$deviceData[$did]['accounts'][] = $dhcp6; }
            }
        }
        unset($api);
        $this->run_batch($deviceData);
        $this->save_batch($deviceServices);
        $this->queue_failed($deviceServices);
    }

    private function set_sites($ids,$delete = false)
    {
        $nms = new ApiSites();
        if($delete){ $nms->delete($ids);}
        else{ $nms->set($ids); }
    }

    private function run_batch($deviceData,$delete = false)
    {
        $timer = new ApiTimer("mt batch write");
        $sent = 0 ;
        $writes = 0 ;
        $this->batch_failed = [];
        $this->batch_success = [];
        foreach (array_keys($deviceData) as $did)
        {
            $device  = $this->find_device($did);
            if(!(array)$device){ continue; }
            $this->batch_device = $device ;
            $type = $device->type ?? 'mikrotik';
            $api = $this->device_api($type);
            MyLog()->Append('executing batch for device: '.$device->name);
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
        $fields = [
            'id',
            'device',
            'clientId',
            'planId',
            'status',
        ];
        $save = [];
        $sites = [];
        foreach ($deviceServices as $services){
            foreach ($services as $service){
                $id = $service['batch'] ?? null ;
                $success = $this->batch_success[$id] ?? null ;
                if($success){
                    $sites[] = $service['id'] ;
                    $values = [];
                    foreach ($fields as $key){ $values[$key] = $service[$key] ?? null ;}
                    $values['last'] = $this->now();
                    $save[] = $values;
                }
            }
        }
        MyLog()->Append('batch: saving data ');
        $this->db()->insert($save,'services',true);
        $this->set_sites($sites);
    }

    private function unsave_batch($deviceServices): array
    {
        if(empty($this->batch_success)) return [];
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
        foreach(['services','network'] as $table) {
            $sql = sprintf("delete from %s where id in (%s)",$table,
                implode(',',$ids));
            MyLog()->Append(sprintf("batch delete from %s sql: %s",$table,$sql));
            $this->db()->exec($sql);
        }
        $this->set_sites($ids,true);
        return $ids ;
    }

    private function queue_failed($deviceServices)
    {
        if(empty($this->batch_failed)) return ;
        MyLog()->Append("batch queueing failed: ".sizeof($this->batch_failed));
        $file = file_get_contents('data/queue.json') ?? '[]';
        $queue = json_decode($file,true);
        foreach ($deviceServices as $services){
            foreach ($services as $service){
                $id = $service['batch'] ?? null ;
                $failed = $this->batch_failed[$id] ?? null ;
                if($failed){ //do not requeue
                    $service['error'] = $failed ;
                    $service['last'] = date_create()->format('Y-m-d H:i:s');
                    $queue[]= $service ;
                }
            }
        }
        file_put_contents('data/queue.json',json_encode($queue));
    }

    private function device_api($type)
    {
        $client = $this->_apis[$type] ?? null ;
        if(!$client){
            if($type == 'mikrotik'){
                $client = new MT();
                $this->_apis[$type] = $client ;
            }
            if(in_array($type,['edge','edgeos','edgerouter'])){
                $client = new ER();
                $this->_apis[$type] = $client ;
            }
        }
        return $client ;
    }

    private function datapi($did)
    {
        $type = $this->find_device($did)->type ?? 'mikrotik';
        if($type == 'mikrotik') return new MtData();
        if(in_array($type,['edge','edgerouter','edgeos'])){ return new ErData(); }
        throw new Exception('No api for this device type: '.$type);
    }

    private function find_plans(): array
    {
        if(empty($this->_plans)){
            $api = new ApiPlans();
            $api->get();
            $plans = $api->result();
            foreach ($plans as $plan){
                $this->_plans[$plan['id']] = $plan; }
        }
        return $this->_plans ;
    }

    private function select_data(array $ids, $action): array
    {
        $data = $ids ;
        $first = $ids[0] ;
        if(is_int($first)){
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

    private function make_plan($service): array
    {
        $ul = $service['uploadSpeed'] ?? 1 ;
        $dl = $service['downloadSpeed'] ?? 1 ;
        $defaults = ['priorityUpload' => 8,'priorityDownload' => 8,'timeUpload' => 1,'timeDownload' => 1,
            'uploadSpeed' => $ul,'downloadSpeed' => $dl];
        $keys = ['ratio','priorityUpload','priorityDownload','limitUpload','limitDownload',
            'burstUpload','burstDownload','threshUpload','threshDownload','timeUpload','timeDownload',
            'uploadSpeed','downloadSpeed'];
        $plan = [];
        foreach ($keys as $key){ $plan[$key] = $defaults[$key] ?? 0 ;}
        $plan['name'] = sprintf('Custom Plan %s/%s',$ul,$dl);
        return $plan ;
    }

    private function db()
    {
        if(empty($this->_db)){
            $this->_db = mySqlite();
        }
        return $this->_db ;
    }

    private function dbCache()
    {
        if(empty($this->_cache)){
            $this->_cache = new ApiSqlite('data/cache.db');
        }
        return $this->_cache ;
    }

    private function now(): string {$date = new DateTime(); return $date->format('c'); }


}