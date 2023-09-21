<?php
include_once 'api_sqlite.php';
include_once 'api_ip.php';
class Data
{
    protected array  $service = [];
    protected array $plan = [] ;
    protected ?array $_devices = null ;
    protected ?ApiSqlite $_db = null;
    protected ?ApiSqlite $_cache = null ;
    protected ?object $_conf = null;
    protected ?ApiIP $_ipapi = null ;


    public function set_data($service,$plan)
    {
        $this->service = $service ;
        $this->plan = $plan ;
    }

    protected function disabled(): bool
    {
        $status = $this->service['status'] ?? 1 ;
        return in_array($status,[3,5,2,8]);
    }

    protected function ip($ip6 = false): ?string
    {
        MyLog()->Append('checking for assigned address');
        $assigned = $this->find_address($ip6);
        if($assigned){ return $assigned; }
        MyLog()->Append('requesting address assignment');
        return $this->assign_address($ip6);
    }

    protected function find_address($ip6): ?string
    {
        $type = $ip6 ? 'address6' : 'address';
        $service = $this->service['id'] ?? 0 ;
        $fixed = $this->service[$type] ?? null ;
        if($fixed){
            $this->ipapi()->set_ip($service,$fixed,$ip6);
            return $fixed;
        }

        return $this->ipapi()->find_used($service,$ip6);
    }

    protected function assign_address($ip6): ?string
    {
        $device = $this->find_device();
        $router_pool = $this->conf->router_ppp_pool ?? true ;
        if($this->type() == 'ppp' && !$router_pool){ $device = null; }
        $service = $this->service['id'] ?? 0 ;
        return $this->ipapi()->assign($service,$device,$ip6);
    }

    protected function type(): string
    {
        $mac = $this->service['mac'] ?? null ;
        $user = $this->service['username'] ?? null ;
        $hotspot = $this->service['hotspot'] ?? null ;
        if(filter_var($mac,FILTER_VALIDATE_MAC)) return 'dhcp' ;
        if($user && $hotspot) return 'hotspot' ;
        if($user) return 'ppp';
        return 'invalid';
    }

    protected function ipapi(): ApiIP
    {
        if(empty($this->_ipapi)){
            $this->_ipapi = new ApiIP();
        }
        return $this->_ipapi ;
    }


    protected function to_pair($array, $mbps = true): ?string
    {
        $str = [];
        foreach($array as $value){
            $unit = $mbps ? 'M' : null;
            if(!$value){ $value = 0; $unit = null; }
            $str[] = $value . $unit ;
        }
        return implode('/',$str);
    }

    protected function limits(): array
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

    protected function conf(): object
    {
        if(empty($this->_conf)){
            $this->_conf = $this->db()->readConfig()
                ?? new stdClass();
        }
        return $this->_conf ;
    }

    protected function find_device(): ?object
    {
        $id = $this->service['device'] ?? 0 ;
        $device = $this->find_devices()[$id] ?? null;
        return $device ? (object) $device : null ;
    }

    protected function disabled_rate(): ?string
    {
        $rate = $this->conf()->disabled_rate ?? 0;
        if(!$rate) return null ;
        return $this->to_pair([$rate,$rate]);
    }

    protected function find_devices(): array
    {
        if(empty($this->_devices)){
            $devs = $this->db()->selectAllFromTable('devices') ?? [];
            $this->_devices = [];
            foreach ($devs as $dev){
                $this->_devices[$dev['id']] = $dev;
            }
        }
        return $this->_devices;
    }

    protected function db(): ApiSqlite
    {
        if(empty($this->_db)){
            $this->_db = new ApiSqlite();
        }
        return $this->_db ;
    }

    protected function dbCache(): ApiSqlite
    {
        if(empty($this->_cache)){
            $this->_cache = new ApiSqlite('data/cache.db');
        }
        return $this->_cache ;
    }

    protected function account_comment(): string
    {
        $id = $this->service['id'];
        return $this->service['clientId'] . " - "
            . $this->account_name() . " - "
            . $id;
    }

    protected function addr_list()
    {
        if($this->disabled()){
            return $this->conf()->disabled_list ?? null ;
        }
        return $this->conf()->active_list ?? null ;
    }

    protected function account_name(): string
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

}