<?php
include_once 'mt.php';
include_once 'api_sqlite.php';

class MtBatch extends MT
{
    private array $sent ;

    public function delete_ids(array $ids)
    {
        $deviceServices = $this->select_ids($ids);
        $plans = $this->select_plans();
        $deviceData = [];
        foreach (array_keys($deviceServices) as $did){
            foreach($deviceServices[$did] as $service){
                $plan = $plans[$service['planId']] ;
                $account = $this->account($service,$plan);
                if($account){
                    $account['action'] = 'remove';
                    $deviceData[$did]['accounts'][] = $account ;
                }
                $queue = $this->queue($service,$plan);
                if($queue){
                    $queue['action'] = 'remove';
                    $deviceData[$did]['queues'][] = $queue ;
                }
                $profile = $this->profile($service,$plan);
                if($profile){
                    $profile['action'] = 'remove';
                    $deviceData[$did]['profiles'][$profile['name']] = $profile ;
                }
            }
        }
        MyLog()->Append('services ready to delete');
        $this->run_batch($deviceData,true);
        //$this->save_batch($deviceServices);
    }

    public function set_ids(array $ids)
    {
        $deviceServices = $this->select_ids($ids);
        $devices = $this->select_devices();
        $plans = $this->select_plans();
        $deviceData = [];
        foreach (array_keys($deviceServices) as $did){
            foreach ($deviceServices[$did] as $service){
                $plan = $plans[$service['planId']] ;
                $device = $devices[$service['device']] ?? null;
                $service['address'] = $this->ip($service,$device);
                $account = $this->account($service,$plan);
                if($account){ $deviceData[$did]['accounts'][] = $account ; }
                $queue = $this->queue($service,$plan);
                if($queue){ $deviceData[$did]['queues'][] = $queue ; }
                $profile = $this->profile($service,$plan);
                if($profile){ $deviceData[$did]['profiles'][$profile['name']] = $profile ; }
            }
        }
        $this->run_batch($deviceData);
        $this->save_batch($deviceServices);
    }

    private function run_batch($deviceData,$delete = false)
    {
        $timer = new ApiTimer("mt batch write");
        $devices = $this->select_devices();
        $sent = 0 ;
        $writes = 0 ;
        $this->batch_failed = [];
        foreach (array_keys($deviceData) as $did)
        {
            $this->batch_device = (object) $devices[$did];
            MyLog()->Append('executing batch for device: '.$this->batch_device->name);
            $keys = ['parents','profiles','queues','accounts'];
            if($delete) $keys = array_reverse($keys);
            foreach ($keys as $key){
                $item = $deviceData[$did][$key] ?? [];
                $this->batch = array_values($item);
                $sent += sizeof($this->batch);
                $writes += $this->write_batch();
            }
        }
        $timer->stop();
        MyLog()->Append(sprintf('batch sent: %s written: %s',$sent,$writes));
    }

    private function save_batch($deviceServices)
    {
        $failed = [];
        $fields = [
            'id',
            'device',
            'address',
            'prefix6',
            'clientId',
            'planId',
            'status',
        ];
        $save = [];
        foreach ($this->batch_failed as $item){
            $key = $item['mac-address'] ?? $item['name'];
            $key =strtolower($key);
            $failed[$key] = 1 ;
        }
        foreach ($deviceServices as $services){
            foreach ($services as $service){
                $values = [];
                if($this->is_success($service,$failed)){
                    foreach ($fields as $key){ $values[] = $service[$key] ?? null ;}
                    $values['last'] = $this->now();
                    $save[] = $values;
                }
            }
        }
        $sql = sprintf("insert or replace into services (%s,last) values ",implode(',',$fields));
        $sql .= $this->to_sql($save);
        MyLog()->Append('batch: saving data '.$sql);
        $this->db()->exec($sql);
    }

    private function to_sql($array): string
    {
        $query = [];
        foreach ($array as $row){
            $values = [];
            foreach($row as $value){
                if(is_null($value)) $values[] = 'null';
                else if(is_numeric($value)) $values[] = $value;
                else $values[] = sprintf("'%s'",$value);
            }
            $query[] = sprintf("(%s)",implode(',',$values));
        }
        return implode(',',$query);
    }
    private function is_success($service, $failed)
    {
        $key = $service['mac'] ?? $service['username'];
        $key = strtolower($key);
        $sent = $this->sent[$service['id']] ?? null ;
        $fail = $failed[$key] ?? null ;
        return $sent && !$fail ;
    }


    private function account($service,$plan): ?array
    {
       $data = null ;
        switch ($this->type($service)){
            case 'dhcp': $data = $this->dhcp($service);break ;
            case 'ppp': $data = $this->ppp($service,$plan); break ;
            case 'hotspot': $data = $this->hotspot($service,$plan); break ;
        }
        if($data){ //register as sent
            $this->sent[$service['id']] = 1;
        }
        return $data ;
    }

    private function profile($service,$plan): ?array
    {
        $type = $this->type($service);
        if($type == 'dhcp') return null ;
        $path = $type == 'hotspot' ? '/ip/hotspot/user/profile/' : '/ppp/profile';
        $data = [
            'path' => $path,
            'name' => $this->profile_name($service,$plan),
            'rate-limit' => $this->profile_limits($service,$plan),
            //'parent-queue' => $this->pq_name(),
            'address-list' => $this->addr_list($service),
        ];
        if($type == 'ppp') $data['local-address'] = null;
        return $data;
    }

    private function hotspot($service,$plan)
    {
        return [
            'path' => '/ip/hotspot/user/',
            'name' => $service['username'],
            'password' => $service['password'],
            'address' => $service['address'],
            'profile' => $this->profile_name($service,$plan),
            'comment' => $this->account_comment($service),
        ];
    }

    private function queue($service,$plan)
    {
        if($this->type($service) != 'dhcp') return null ;
        $address = $service['address'] ?? null ;
        if(!$address) return null ;
        $limits = $this->limits($plan);
        if($this->disabled($service)){
            return [
                'path' => '/queue/simple',
                'name' => $this->account_name($service),
                'target' => $service['address'],
                'max-limit' => $this->disabled_rate(),
                'limit-at' => $this->disabled_rate(),
                'comment' => $this->account_comment($service),
            ];
        }
        return [
            'path' => '/queue/simple',
            'name' => $this->account_name($service),
            'target' => $service['address'],
            'max-limit' => $this->to_pair($limits['rate']),
            'limit-at' => $this->to_pair($limits['limit']),
            'burst-limit' => $this->to_pair($limits['burst']),
            'burst-threshold' => $this->to_pair($limits['thresh']),
            'burst-time' => $this->to_pair($limits['time'],false),
            'priority' => $this->to_pair($limits['prio'],false),
//            'parent' => $this->pq_name(),
            'comment' => $this->account_comment($service),
        ];
    }

    private function ppp($service,$plan)
    {
        return[
            'path' => '/ppp/secret',
            'remote-address' => $service['address'],
            'name' => $service['username'],
            'caller-id' => $service['callerId'] ?? null,
            'password' => $service['password'] ?? null,
            'profile' => $this->profile_name($service,$plan),
            'comment' => $this->account_comment($service),
        ];
        //REMEMBER IP6 ADDRESSING HERE
    }

    private function dhcp($service)
    {
        return [
            'path' => '/ip/dhcp-server/lease',
            'address' => $service['address'],
            'mac-address' => strtoupper($service['mac']),
            'insert-queue-before' => 'bottom',
            'address-lists' => $this->addr_list($service),
            'comment' => $this->account_comment($service),
        ];
    }


    private function ip($service,$device)
    {
        $ip = $service['address'] ?? null ;
        if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)){
            return $ip ;
        }
        $router_pool = $this->conf->router_ppp_pool ?? true ;
        $type = $this->type($service);
        $api = new ApiIP();
        $sid = $service['id'];
        if($device && ($type == 'dhcp' || $router_pool)){
            return $api->ip($sid,(object) $device);
        }
        return $api->ip($sid);
    }

    private function profile_name($service, $plan)
    {
        if($this->disabled($service))
            return $this->conf->disabled_profile ?? 'default';
        return $plan['name'] ?? 'default';
    }

    private function limits($plan): array
    {
                $keys = [
            'ratio',
            'priority',
            'limitUpload',
            'limitDownload',
            'uploadSpeed',
            'downloadSpeed',
            'burstUpload',
            'burstDownload',
            'threshUpload',
            'threshDownload',
            'timeUpload',
            'timeDownload',
        ];
        $values = [];
        foreach($keys as $key){
            switch ($key)
            {
                case 'priority': $values['prio'] = $plan[$key]; break;
                case 'limitUpload':
                case 'limitDownload': $values['limit'][] = $plan[$key];break;
                case 'uploadSpeed':
                case 'downloadSpeed': $values['rate'][] = $plan[$key];break;
                case 'burstUpload':
                case 'burstDownload': $values['burst'][] = $plan[$key];break;
                case 'threshUpload':
                case 'threshDownload': $values['thresh'][] = $plan[$key];break;
                case 'timeUpload':
                case 'timeDownload': $values['time'][] = $plan[$key];break;
            }
        }
        return $values ;
    }

    private function profile_limits($service,$plan): ?string
    {
        if($this->disabled($service)) return $this->disabled_rate();
        $limits = $this->limits($plan);
        $values = [];
        foreach (array_keys($limits) as $key) {
            $limit = $limits[$key];
            if (is_array($limit)) {
                $mbps = $key != 'time';
                $values[$key] = $this->to_pair($limit, $mbps);
            } else {
                $values[$key] = $this->to_pair($limit,false);
            }
        }
        $order = 'rate,burst,thresh,time,prio,limit';
        $ret = [];
        foreach (explode(',', $order) as $key) {
            $ret[] = $values[$key];
        }
        return implode(' ', $ret);
    }

    private function addr_list($service)
    {
        if($this->disabled($service)){
            return $this->conf->disabled_list ?? null ;
        }
        return $this->conf->active_list ?? null ;
    }

    private function account_comment($service): string
    {
        $id = $service['id'];
        return $service['clientId'] . " - "
            . $this->account_name($service) . " - "
            . $id;
    }

    private function account_name($service): string
    {
        $name = sprintf('Client-%s',$service['id']);
        $co = $service['company'];
        $fn = $service['firstName'];
        $ln = $service['lastName'];
        if($co){
            $name = $co ;
        }
        else if($fn && $ln){
            $name = sprintf('%s %s',$fn,$ln);
        }
        return $name ;
    }

    private function disabled($service)
    {
        $status = $service['status'] ?? 1 ;
        return in_array($status,[3,5,2,8]);
    }

    private function disabled_rate()
    {
        $rate = $this->conf->disabled ?? 0;
        if(!$rate) return null ;
        return $this->to_pair([$rate,$rate]);
    }

    private function type($service): string
    {
        $mac = $service['mac'] ?? null ;
        $user = $service['username'] ?? null ;
        $hotspot = $service['hotspot'] ?? null ;
        if(filter_var($mac,FILTER_VALIDATE_MAC)) return 'dhcp' ;
        if($user && $hotspot) return 'hotspot' ;
        if($user) return 'ppp';
        return 'invalid';
    }

    private function select_plans()
    {
        $api = new AdminPlans();
        $api->get();
        $plans = $api->result();
        $map = [];
        foreach ($plans as $plan){ $map[$plan['id']] = $plan; }
        return $map ;
    }

    private function select_ids(array $ids): array
    {
        $fields = 'services.*,network.address,network.prefix6,clients.company,clients.firstName,clients.lastName';
        $data = $this->dbCache()->selectCustom(
            sprintf("SELECT %s FROM services LEFT JOIN clients ON services.clientId=clients.id ".
                "LEFT JOIN network ON services.id=network.id ".
                "WHERE services.id IN (%s) ",$fields,implode(',',$ids)));
       $deviceMap = [];
        foreach ($data as $item){ $id = $item['device'] ?? 'nodev'; $deviceMap[$id][] = $item ; }
        return $deviceMap ;
    }

    private function select_devices(): array
    {
        $devs = $this->db()->selectAllFromTable('devices') ?? [];
        $map = [];
        foreach ($devs as $dev){ $map[$dev['id']] = $dev; }
        return $map ;
    }

    private function now(){$date = new DateTime(); return $date->format('Y-m-d H:i:s'); }


}