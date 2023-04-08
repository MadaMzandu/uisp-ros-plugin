<?php
class MtData extends MT
{
    public function account($service,$plan): ?array
    {
        $data = null ;
        switch ($this->type($service)){
            case 'dhcp': $data = $this->dhcp($service,$plan);break ;
            case 'ppp': $data = $this->ppp($service,$plan); break ;
            case 'hotspot': $data = $this->hotspot($service,$plan); break ;
        }
        if($data){ //register as sent
            $this->sent[$service['id']] = 1;
        }
        return $data ;
    }

    public function profile($service,$plan): ?array
    {
        $type = $this->type($service);
        if($type == 'dhcp') return null ;
        $path = $type == 'hotspot' ? '/ip/hotspot/user/profile/' : '/ppp/profile';
        $data = [
            'path' => $path,
            'name' => $this->profile_name($service,$plan),
            'rate-limit' => $this->profile_limits($service,$plan),
            'parent-queue' => $this->parent_name($service,$plan),
            'address-list' => $this->addr_list($service),
        ];
        if($type == 'ppp') $data['local-address'] = null;
        return $data;
    }

    private function hotspot($service,$plan): array
    {
        return [
            'path' => '/ip/hotspot/user/',
            'name' => $service['username'],
            'password' => $service['password'],
            'address' => $service['address'],
            'parent-queue' => $this->parent_name($service,$plan),
            'profile' => $this->profile_name($service,$plan),
            'comment' => $this->account_comment($service),
        ];
    }

    public function queue($service,$plan): ?array
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
            'parent' => $this->parent_name($service,$plan),
            'comment' => $this->account_comment($service),
        ];
    }

    private function ppp($service,$plan): array
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

    public function disconnect($service): ?array
    {
        $type = $this->type($service);
        if($type == 'dhcp') return null ;
        $path = $type == 'ppp' ? '/ppp/active' : '/ip/hotspot/active';
        return [
            'path' => $path,
            'action' => 'remove',
            'name' => $service['username'],
        ];
    }

    private function dhcp($service,$plan)
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

    public function parent($service,$plan): ?array
    {
        if($this->conf->disable_contention) return null ;
        if($this->disabled($service)) return null ;
        return [
            'path' => '/queue/simple',
            'name' => $this->parent_name($service,$plan),
            'target' => $this->parent_target($plan),
            'max-limit' => $this->parent_total($plan),
            'limit-at' => $this->parent_total($plan),
            'comment' => 'do not delete',
        ];
    }

    private function parent_target($plan): ?string
    {
        $sql = sprintf("select network.address from services left join network on services.id=network.id ".
            "where services.planId=%s",$plan['id']);
        $data = $this->dbCache()->selectCustom($sql);
        $addresses = [];
        foreach ($data as $item){ $addresses[] = $item['address']; }
        return implode(',',$addresses);
    }

    private function parent_name($service,$plan): ?string
    {
        if($this->conf->disable_contention) return 'none' ;
        if($this->disabled($service)) return 'none';
        return sprintf('servicePlan-%s-parent',$plan['id']);
    }

    private function parent_total($plan): string
    {
        $children = $this->parent_children($plan);
        $ratio = $plan['ratio'];
        $ratio = max($ratio,1);
        $shares = intdiv($children,$ratio);
        if($children % $ratio > 0) $shares++ ;
        $upload = $plan['uploadSpeed'] * $shares ;
        $download = $plan['downloadSpeed'] * $shares;
        return sprintf("%sM/%sM",$upload,$download);
    }

    private function parent_children($plan): int
    {
        $sql = sprintf("select count(services.id) from services left join ".
            "network on services.id=network.id where planId=%s",$plan['id']);
        $count = $this->dbCache()->singleQuery($sql);
        return max($count,1);
    }

    public function ip($service,$device,$ip6 = false): string
    {
        $ip = $this->db()->selectIp($service['id'],$ip6);
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

    private function profile_name($service, $plan): string
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

    private function disabled($service): bool
    {
        $status = $service['status'] ?? 1 ;
        return in_array($status,[3,5,2,8]);
    }

    private function disabled_rate(): ?string
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


}