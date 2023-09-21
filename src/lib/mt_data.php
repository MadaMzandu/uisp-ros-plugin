<?php
class MtData extends MT
{
    private array $service = [];
    private array $plan = [];
    private ?ApiIP $_ipapi = null;
    private ?array $_devices = null ;
    
    public function set_data($service,$plan)
    {
        $this->service = $service ;
        $this->plan = $plan ;
        MyLog()->Append('account data: '.json_encode([$service,$plan]));
    }

    public function ip_clear($ids):void
    {
        $this->ipapi()->clear($ids);
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
            'batch' => $this->service['batch'] ?? null ,
            'path' => '/ip/hotspot/user/',
            'name' => $this->service['username'],
            'password' => $this->service['password'],
            'address' => $this->ip(),
            'profile' => $this->profile_name(),
            'comment' => $this->account_comment(),
        ];
    }

    public function queue(): ?array
    {
        if(!in_array($this->type(),['dhcp','dhcp6'])) return null ;
        $limits = $this->limits();
        $id = $this->service['id'] ?? rand(1000000);
        $ip = $this->ip();
        $ipv6 = $this->ip(true);
        if(!($ip || $ipv6)){ return null; }
        if($this->disabled() && $this->disabled_rate()){
            return [
                'path' => '/queue/simple',
                'name' => sprintf('%s - %s',$this->account_name(),$id),
                'target' => $ip,
                'max-limit' => $this->disabled_rate(),
                'limit-at' => $this->disabled_rate(),
                'comment' => $this->account_comment(),
            ];
        }
        $data = [
            'path' => '/queue/simple',
            'name' => sprintf('%s - %s',$this->account_name(),$id),
            'target' => $ip,
            'max-limit' => $this->to_pair($limits['rate']),
            'limit-at' => $this->to_pair($limits['limit']),
            'burst-limit' => $this->to_pair($limits['burst']),
            'burst-threshold' => $this->to_pair($limits['thresh']),
            'burst-time' => $this->to_pair($limits['time'],false),
            'priority' => $this->to_pair($limits['prio'],false),
            'parent' => $this->parent_name(),
            'comment' => $this->account_comment(),
        ];
        if($this->has_dhcp6() && $ipv6){
            $data['target'] .= ',' . $ipv6;
        }
        return $data ;
    }

    private function ppp(): ?array
    {
        $ip = $this->ip();
        if(!$ip){ return null; }
       $data = [
           'batch' => $this->service['batch'] ?? null ,
            'path' => '/ppp/secret',
            'remote-address' => $ip,
            'name' => $this->service['username'],
            'caller-id' => $this->service['callerId'] ?? null,
            'password' => $this->service['password'] ?? null,
            'profile' => $this->profile_name(),
            'comment' => $this->account_comment(),
        ];
        $prefix = $this->ip(true);
        if($prefix) $data['remote-ipv6-prefix'] = $prefix ;
        return $data ;
    }

    public function account_reset(): ?array
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

    private function dhcp(): ?array
    {
        $lease = $this->conf->lease_time ?? $this->conf->dhcp_lease_time ?? 60;
        $ip = $this->ip();
        if(!$ip){ return null; }
        return [
            'batch' => $this->service['batch'] ?? null ,
            'path' => '/ip/dhcp-server/lease',
            'address' => $ip,
            'mac-address' => strtoupper($this->service['mac']),
            'insert-queue-before' => 'bottom',
            'address-lists' => $this->addr_list(),
            'lease-time' => $lease . 'm',
            'comment' => $this->account_comment(),
        ];
    }

    public function dhcp6(): ?array
    {
        if(!$this->has_dhcp6()) return null ;
        $lease = $this->conf->lease_time ?? $this->conf->dhcp_lease_time ?? 60;
        $ipv6 = $this->ip(true);
        if(!$ipv6){ return null; }
        return [
            'batch' => $this->service['batch'] ?? null ,
            'path' => '/ipv6/dhcp-server/binding',
            'address' => $ipv6,
            'duid' => $this->make_duid(),
            'iaid' => $this->make_iaid(),
            'life-time' => $lease . 'm',
            'prefix-pool' => $this->pool_name(),
            'comment' => $this->account_comment(),
        ];
    }

    public function pool(): ?array
    {
        if(!$this->has_dhcp6()) return null ;
        $did = $this->service['device'] ?? 0 ;
        $device = $this->db()->selectDeviceById($did) ?? [];
        $pool_str = $device->pool6 ?? null ;
        if(!$pool_str) return null ;
        $pool = explode(',',$pool_str)[0] ;
        $len = $device->pfxLength ?? 64 ;
        return [
            'path' => '/ipv6/pool',
            'name' => $this->pool_name(),
            'prefix' => $pool,
            'prefix-length' => $len,
        ];
    }

    private function pool_name(): string
    {
        return 'uisp-pool' ;
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
        $sql = sprintf("SELECT network.address,network.address6 FROM services LEFT JOIN network ".
            "ON services.id=network.id WHERE services.planId=%s AND services.device=%s ",
            $this->plan['id'],$did);
        $data = $this->dbCache()->selectCustom($sql);
        $addresses = [];
        foreach ($data as $item){
            if($item['address']) $addresses[] = $item['address'];
            if($item['address6']) $addresses[] = $item['address6'];
        }
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

    private function ip($ip6 = false): ?string
    {
        MyLog()->Append('checking for assigned address');
        $assigned = $this->find_address($ip6);
        if($assigned){ return $assigned; }
        MyLog()->Append('requesting address assignment');
        return $this->assign_address($ip6);
    }

    private function ipapi(): ApiIP
    {
        if(empty($this->_ipapi)){
            $this->_ipapi = new ApiIP();
        }
        return $this->_ipapi ;
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
            'priorityUpload',
            'priorityDownload',
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
                case 'priorityUpload':
                case 'priorityDownload': $values['prio'][] = $this->plan[$key]; break;
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
        $hs = $this->type() == 'hotspot';
        foreach (['rate','burst','thresh','time','prio','limit'] as $key) {
            $limit = $limits[$key];
            $mbps = !in_array($key,['time','prio']);
            if($hs && $key == 'prio'){ $values[] = $limit[0] ?? 8 ;}
            else{ $values[] = $this->to_pair($limit, $mbps); }
        }
        return implode(' ', $values);
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
        $name = sprintf('Client-%s',$this->service['clientId']);
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
        $rate = $this->conf->disabled_rate ?? 0;
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

    private function find_device(): ?object
    {
        if(empty($this->_devices)){
            $read = $this->db()->selectAllFromTable('devices');
            foreach ($read as $item){$this->_devices[$item['id']] = $item; }
        }
        $id = $this->service['device'] ?? 0 ;
        $device = $this->_devices[$id] ?? null ;
        return $device ? (object) $device : null ;
    }

    private function find_address($ip6): ?string
    {
        $type = $ip6 ? 'address6' : 'address';
        $fixed = $this->service[$type] ?? null ;
        if($fixed){ return $fixed; }
        $service = $this->service['id'] ?? 0 ;
        return $this->ipapi()->find_used($service,$ip6);
    }

    private function assign_address($ip6): ?string
    {
        $device = $this->find_device();
        $router_pool = $this->conf->router_ppp_pool ?? true ;
        if($this->type() == 'ppp' && !$router_pool){ $device = null; }
        $service = $this->service['id'] ?? 0 ;
        return $this->ipapi()->assign($service,$device,$ip6);
    }

    private function has_dhcp6(): string
    {
        $duid = $this->make_duid() ;
        return $duid && $this->ip(true);
    }

    private function make_duid(): ?string
    {
        $MAC_LENGTH = 12 ;
        $val = $this->service['duid'] ?? $this->service['mac'] ?? '' ;
        $stripped = preg_replace('/\W/','',strtolower($val));
        if(strlen($stripped) < 12) return null ;
        return '0x' . substr($stripped,strlen($stripped) - $MAC_LENGTH);
    }

    private function make_iaid(): int
    {
        $val = $this->service['iaid'] ?? 1 ;
        if(preg_match('/^0x/',$val)){ //value hex
            return hexdec($val);
        }
        return $val ;
    }


}