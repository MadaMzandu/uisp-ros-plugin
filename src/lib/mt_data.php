<?php
class MtData extends MT
{
    private array $service = [];
    private array $plan = [];
    
    public function set_data($service,$plan)
    {
        $this->service = $service ;
        $this->plan = $plan ;
    }
    
    public function account(): ?array
    {
        $data = null ;
        switch ($this->type()){
            case 'dhcp': $data = $this->dhcp();break ;
            case 'ppp': $data = $this->ppp(); break ;
            case 'hotspot': $data = $this->hotspot(); break ;
        }
        return $data ;
    }

    public function profile(): ?array
    {
        $type = $this->type();
        if($type == 'dhcp') return null ;
        $path = $type == 'hotspot' ? '/ip/hotspot/user/profile/' : '/ppp/profile';
        $data = [
            'path' => $path,
            'name' => $this->profile_name(),
            'rate-limit' => $this->profile_limits(),
            'parent-queue' => $this->parent_name(),
            'address-list' => $this->addr_list(),
        ];
        if($type == 'ppp') $data['local-address'] = null;
        return $data;
    }

    private function hotspot(): array
    {
        return [
            'path' => '/ip/hotspot/user/',
            'name' => $this->service['username'],
            'password' => $this->service['password'],
            'address' => $this->service['address'],
            'parent-queue' => $this->parent_name(),
            'profile' => $this->profile_name(),
            'comment' => $this->account_comment(),
        ];
    }

    public function queue(): ?array
    {
        if($this->type() != 'dhcp') return null ;
        $address = $this->service['address'] ?? null ;
        if(!$address) return null ;
        $limits = $this->limits();
        if($this->disabled()){
            return [
                'path' => '/queue/simple',
                'name' => $this->account_name(),
                'target' => $this->service['address'],
                'max-limit' => $this->disabled_rate(),
                'limit-at' => $this->disabled_rate(),
                'comment' => $this->account_comment(),
            ];
        }
        return [
            'path' => '/queue/simple',
            'name' => $this->account_name(),
            'target' => $this->service['address'],
            'max-limit' => $this->to_pair($limits['rate']),
            'limit-at' => $this->to_pair($limits['limit']),
            'burst-limit' => $this->to_pair($limits['burst']),
            'burst-threshold' => $this->to_pair($limits['thresh']),
            'burst-time' => $this->to_pair($limits['time'],false),
            'priority' => $this->to_pair($limits['prio'],false),
            'parent' => $this->parent_name(),
            'comment' => $this->account_comment(),
        ];
    }

    private function ppp(): array
    {
        return[
            'path' => '/ppp/secret',
            'remote-address' => $this->service['address'],
            'name' => $this->service['username'],
            'caller-id' => $this->service['callerId'] ?? null,
            'password' => $this->service['password'] ?? null,
            'profile' => $this->profile_name(),
            'comment' => $this->account_comment(),
        ];
        //REMEMBER IP6 ADDRESSING HERE
    }

    public function disconnect(): ?array
    {
        $type = $this->type();
        if($type == 'dhcp') return null ;
        $path = $type == 'ppp' ? '/ppp/active' : '/ip/hotspot/active';
        return [
            'path' => $path,
            'action' => 'remove',
            'name' => $this->service['username'],
        ];
    }

    private function dhcp(): array
    {
        return [
            'path' => '/ip/dhcp-server/lease',
            'address' => $this->service['address'],
            'mac-address' => strtoupper($this->service['mac']),
            'insert-queue-before' => 'bottom',
            'address-lists' => $this->addr_list(),
            'comment' => $this->account_comment(),
        ];
    }

    public function parent(): ?array
    {
        if($this->conf->disable_contention) return null ;
        if($this->disabled()) return null ;
        return [
            'path' => '/queue/simple',
            'name' => $this->parent_name(),
            'target' => $this->parent_target(),
            'max-limit' => $this->parent_total(),
            'limit-at' => $this->parent_total(),
            'comment' => 'do not delete',
        ];
    }

    private function parent_target(): ?string
    {
        $did = $this->service['device'] ?? 0 ;
        $sql = sprintf("SELECT network.address FROM services LEFT JOIN network ".
            "ON services.id=network.id WHERE services.planId=%s AND services.device=%s ",
            $this->plan['id'],$did);
        $data = $this->dbCache()->selectCustom($sql);
        $addresses = [];
        foreach ($data as $item){ if($item['address']) $addresses[] = $item['address']; }
        return implode(',',$addresses);
    }

    private function parent_name(): ?string
    {
        if($this->conf->disable_contention) return 'none' ;
        if($this->disabled()) return 'none';
        return sprintf('servicePlan-%s-parent',$this->plan['id']);
    }

    private function parent_total(): string
    {
        $children = $this->parent_children();
        $ratio = $this->plan['ratio'];
        $ratio = max($ratio,1);
        $shares = intdiv($children,$ratio);
        if($children % $ratio > 0) $shares++ ;
        $upload = $this->plan['uploadSpeed'] * $shares ;
        $download = $this->plan['downloadSpeed'] * $shares;
        return sprintf("%sM/%sM",$upload,$download);
    }

    private function parent_children(): int
    {
        $did = $this->service['device'] ?? 0 ;
        $sql = sprintf("SELECT COUNT(services.id) FROM services WHERE ".
            "services.planId=%s AND services.device=%s ",$this->plan['id'],$did);
        $count = $this->dbCache()->singleQuery($sql);
        return max($count,1);
    }

    public function ip($ip6 = false): string
    {
        $ip = $this->db()->selectIp($this->service['id'],$ip6);
        if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)){
            return $ip ;
        }
        $router_pool = $this->conf->router_ppp_pool ?? true ;
        $type = $this->type();
        $api = new ApiIP();
        $sid = $this->service['id'];
        $did = $this->service['device'] ?? 0 ;
        $device = $this->db()->selectDeviceById($did);
        if($device && ($type == 'dhcp' || $router_pool)){
            return $api->ip($sid,$device);
        }
        return $api->ip($sid);
    }

    private function profile_name(): string
    {
        if($this->disabled())
            return $this->conf->disabled_profile ?? 'default';
        return $this->plan['name'] ?? 'default';
    }

    private function limits(): array
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
                case 'priority': $values['prio'] = $this->plan[$key]; break;
                case 'limitUpload':
                case 'limitDownload': $values['limit'][] = $this->plan[$key];break;
                case 'uploadSpeed':
                case 'downloadSpeed': $values['rate'][] = $this->plan[$key];break;
                case 'burstUpload':
                case 'burstDownload': $values['burst'][] = $this->plan[$key];break;
                case 'threshUpload':
                case 'threshDownload': $values['thresh'][] = $this->plan[$key];break;
                case 'timeUpload':
                case 'timeDownload': $values['time'][] = $this->plan[$key];break;
            }
        }
        return $values ;
    }

    private function profile_limits(): ?string
    {
        if($this->disabled()) return $this->disabled_rate();
        $limits = $this->limits();
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

    private function addr_list()
    {
        if($this->disabled()){
            return $this->conf->disabled_list ?? null ;
        }
        return $this->conf->active_list ?? null ;
    }

    private function account_comment(): string
    {
        $id = $this->service['id'];
        return $this->service['clientId'] . " - "
            . $this->account_name() . " - "
            . $id;
    }

    private function account_name(): string
    {
        $name = sprintf('Client-%s',$this->service['id']);
        $co = $this->service['company'];
        $fn = $this->service['firstName'];
        $ln = $this->service['lastName'];
        if($co){
            $name = $co ;
        }
        else if($fn && $ln){
            $name = sprintf('%s %s',$fn,$ln);
        }
        return $name ;
    }

    private function disabled(): bool
    {
        $status = $this->service['status'] ?? 1 ;
        return in_array($status,[3,5,2,8]);
    }

    private function disabled_rate(): ?string
    {
        $rate = $this->conf->disabled ?? 0;
        if(!$rate) return null ;
        return $this->to_pair([$rate,$rate]);
    }

    private function type(): string
    {
        $mac = $this->service['mac'] ?? null ;
        $user = $this->service['username'] ?? null ;
        $hotspot = $this->service['hotspot'] ?? null ;
        if(filter_var($mac,FILTER_VALIDATE_MAC)) return 'dhcp' ;
        if($user && $hotspot) return 'hotspot' ;
        if($user) return 'ppp';
        return 'invalid';
    }


}