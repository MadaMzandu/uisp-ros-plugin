<?php
include_once 'data.php';

class MtData extends Data
{

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
        if($this->disabled() && $this->disabled_rate()){
            return [
                'path' => '/queue/simple',
                'name' => sprintf('%s - %s',$this->account_name(),$id),
                'target' => $this->ip(),
                'max-limit' => $this->disabled_rate(),
                'limit-at' => $this->disabled_rate(),
                'comment' => $this->account_comment(),
            ];
        }
        $data = [
            'path' => '/queue/simple',
            'name' => sprintf('%s - %s',$this->account_name(),$id),
            'target' => $this->ip(),
            'max-limit' => $this->to_pair($limits['rate']),
            'limit-at' => $this->to_pair($limits['limit']),
            'burst-limit' => $this->to_pair($limits['burst']),
            'burst-threshold' => $this->to_pair($limits['thresh']),
            'burst-time' => $this->to_pair($limits['time'],false),
            'priority' => $this->to_pair($limits['prio'],false),
            'parent' => $this->parent_name(),
            'comment' => $this->account_comment(),
        ];
        if($this->has_dhcp6()){
            $data['target'] .= ',' . $this->ip(true);
        }
        return $data ;
    }

    private function ppp(): array
    {
       $data = [
           'batch' => $this->service['batch'] ?? null ,
            'path' => '/ppp/secret',
            'remote-address' => $this->ip(),
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

    private function dhcp(): array
    {
        $lease = $this->conf->lease_time ?? $this->conf->dhcp_lease_time ?? 60;
        return [
            'batch' => $this->service['batch'] ?? null ,
            'path' => '/ip/dhcp-server/lease',
            'address' => $this->ip(),
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
        return [
            'batch' => $this->service['batch'] ?? null ,
            'path' => '/ipv6/dhcp-server/binding',
            'address' => $this->ip(true),
            'address-lists' => $this->addr_list(),
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
        if($this->conf()->disable_contention) return null ;
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
        if($this->conf()->disable_contention) return 'none' ;
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

    private function profile_name(): string
    {
        if($this->disabled())
            return $this->conf->disabled_profile ?? 'default';
        return $this->plan['name'] ?? 'default';
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