<?php
include_once 'api_sqlite.php';
include_once 'mt.php';
include_once 'admin_plans.php';
const BATCH_DHCP = 0 ;
const BATCH_PPP = 1 ;
const BATCH_HOTSPOT = 0 ;
class MtBatch extends MT
{
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
    }

    private function run_batch($deviceData)
    {
        $timer = new ApiTimer("mt batch write");
        $devices = $this->select_devices();
        $sent = 0 ;
        $writes = 0 ;
        foreach (array_keys($deviceData) as $did)
        {
            $this->batch_device = (object) $devices[$did];
            MyLog()->Append('executing batch for device: '.$this->batch_device->name);
            $keys = ['parents','profiles','queues','accounts'];
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

    private function account($service,$plan): ?array
    {
        switch ($this->type($service)){
            case BATCH_DHCP: return $this->dhcp($service);
            case BATCH_PPP: return $this->ppp($service,$plan);
            case BATCH_HOTSPOT: return $this->hotspot($service,$plan);
        }
        return null ;
    }

    private function profile($service,$plan): ?array
    {
        $type = $this->type($service);
        if($type < BATCH_PPP) return null ;
        $path = $type == BATCH_HOTSPOT ? '/ip/hotspot/user/profile/' : '/ppp/profile';
        $data = [
            'path' => $path,
            'name' => $this->profile_name($service,$plan),
            'rate-limit' => $this->profile_limits($service,$plan),
            //'parent-queue' => $this->pq_name(),
            'address-list' => $this->addr_list($service),
        ];
        if($type == BATCH_PPP) $data['local-address'] = null;
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
        if($this->type($service) != BATCH_DHCP) return null ;
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
        if($device && ($type == BATCH_DHCP || $router_pool)){
            return $api->ip((object) $device);
        }
        return $api->ip();
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

    private function profile_limits($service,$plan): string
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
            $name = sprintf('%s %s',$fn,$fn);
        }
        return $name ;
    }

    private function disabled($service)
    {
        $status = $service['status'] ?? 1 ;
        return $status != 1;
    }

    private function disabled_rate()
    {
        $rate = $this->conf->disabled ?? 0;
        if(!$rate) return null ;
        return $this->to_pair([$rate,$rate]);
    }

    private function type($service): int
    {
        $mac = $service['mac'] ?? null ;
        $user = $service['username'] ?? null ;
        $hotspot = $service['hotspot'] ?? null ;
        if(filter_var($mac,FILTER_VALIDATE_MAC)) return 0 ;
        if($user && $hotspot) return 2 ;
        if($user) return 1;
        return -1;
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
//        $data = $this->dbCache()->selectCustom(sprintf(
//            "select %s from services left join clients on ".
//            "services.clientId=clients.id left join network on services.id=network.id",$fields));
        $deviceMap = [];
        foreach ($data as $item){ $deviceMap[$item['device']][] = $item ; }
        return $deviceMap ;
    }

    private function select_devices(): array
    {
        $devs = $this->db()->selectAllFromTable('devices') ?? [];
        $map = [];
        foreach ($devs as $dev){ $map[$dev['id']] = $dev; }
        return $map ;
    }


}