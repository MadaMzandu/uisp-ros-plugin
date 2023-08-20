<?php
include_once 'er_client.php';
include_once 'er_objects.php';
include_once 'api_sqlite.php';
include_once 'api_ip.php';
class ER
{
    private ?array $batch = null;
    private ?object  $device = null;
    private array $batch_failed = [];
    private array $batch_success = [];
    private ?ErObject $_dhcp = null;
    private ?ErObject $_fw = null;
    private string $action = 'set';
    private array $delete_list =[];
    private array $set_list =[];
    private ?object $_conf = null;
    private ?ApiSqlite $_db = null;

    private function connect(): bool
    {
        $dev = &$this->device ;
        $pass = $dev->password ?? null;
        $port = $dev->port ?? 443;
        return $this->client()->connect($dev->user,$pass,$dev->ip,$port);
    }

    private function reset()
    {
        $this->batch_success = [];
        $this->batch_failed = [];
        $this->set_list = [];
        $this->delete_list = [];
    }

    private function client():ErClient { return erClient(); }

    public function __call($name, $arguments)
    {
        MyLog()->Append("unimplemented ER: ".$name);
        return 0;
    }

    public function do_batch($device,$data): int
    {

        $this->device = $device;
        $this->batch = $data ;
        $this->reset();
        $type = $this->type();
        return $this->$type();
    }

    public function dhcp(): int
    {
        if(!$this->connect() ||
            !$this->_dhcp()->read() ||
            !$this->prep_dhcp()) { return 0 ;}
        $api = $this->action == 'set' ? 'set.json' : 'delete.json';
        $ret = $this->client()->post($api,$this->_dhcp()->post());
        $success = $ret['SET']['success'] ?? $ret['DELETE']['success'];
        if(!$success){
            $error = $ret['SET']['error'] ?? $ret['DELETE']['error'] ?? 'Unknown error';
            $this->setErr(array_values($error)[0]);
        }
        else{ $this->set_fw(); }
        MyLog()->Append($ret);
        return sizeof($this->batch_success);
    }

    public function prep_dhcp(): int
    {//map accounts to server pool on device
        $servers = $this->_dhcp()->findKeys();
        $nets = [];
        foreach($servers as $s){ $nets[$s] = $this->_dhcp()->findKeys($s . '>subnet')[0];}
        if(!$nets){ return 0; }
        $ip = new ApiIP();
        foreach($this->batch as $i){
            $this->action = $i['action'] ?? 'set';
            $found = false ;
            $batch = $i['batch'] ?? 'nobatch';
            foreach($nets as $net){
                if($ip->in_subnet($i['ip-address'],$net)){
                    $this->batch_success[$batch] = 1;
                    $found = true ;
                    $server = array_flip($nets)[$net];
                    $path = sprintf('%s>subnet>%s>static-mapping',$server,$net);
                    if($this->action == 'remove'){
                        $this->_dhcp()->set($i['id'],null,$path);
                    }
                    else{
                        $this->_dhcp()->set($i['id'],$this->clean_data($i),$path);
                    }
                }
            }
            if(!$found){
                $this->batch_failed[$batch] = 'No matching subnet pool found';
                MyLog()->Append('No matching subnet found: '.$i['id'],6);
            }
            else{ $this->prep_fw($i); }
        }
        return sizeof($this->batch_success);
    }

    private function prep_fw($item)
    {
        $disabled = $this->conf()->disabled_list ?? 'disabled';
        $active = $this->conf()->active_list ?? null;
        $suspended = $item['disabled'] ?? false ;
        $ip = $item['ip-address'] ?? null ;
        if(!$ip){ return; }
        if($this->action == 'remove'){
            $this->delete_list[$disabled]['address'][] = $ip;
            if($active)$this->delete_list[$active]['address'][] = $ip;
        }
        else if($suspended){
            $this->set_list[$disabled]['address'][] = $ip;
            if($active)$this->delete_list[$active]['address'][] = $ip;
        }
        else{
            $this->delete_list[$disabled]['address'][] = $ip;
            if($active)$this->set_list[$active]['address'][] = $ip;
        }
    }

    private function set_fw()
    {
        $jobs['set'] = &$this->set_list;
        $jobs['delete'] = &$this->delete_list;
        foreach(array_keys($jobs) as $key){
            if(empty($jobs[$key])){ continue; }
            $this->_fw()->reset();
            $this->_fw()->set('address-group',$jobs[$key]);
            $this->client()->post($key.'.json',$this->_fw()->post());
        }
    }

    private function queue(): int
    {
        if(!$this->connect()){ return 0 ;}
        $t = new ApiTimer('Queue Write');
        $map = [];
        foreach($this->batch as $item){
            $map[$item['src']] = $this->clean_data($item); }
        $read = $this->read_queues();
        if(empty($map)){ $data = []; }
        else if($this->action() == 'remove'){
            $data = array_diff_key($read,$map); }
        else { $data = array_replace($read,$map); }
        $w = $this->write_queues($data);
        $t->stop();
        return $w ? sizeof($this->batch) : 0 ;
    }

    private function write_queues($data): bool
    {
        $post = ['data'=>["scenario" => '.Basic_Queue','action' => 'apply']];
        $post['data']['apply']['bq-config'] = array_values($data) ;
        $ret = $this->client()->post('feature.json',$post);
        $success = $ret['FEATURE']['success'] ?? '0';
        return (bool) $success;
    }

    private function _dhcp(): ErObject
    {
        if(empty($this->_dhcp)){
            $this->_dhcp = new ErObject($this->device,
                'service>dhcp-server>shared-network-name');
        }
        return $this->_dhcp ;
    }

    private function _fw(): ErObject
    {
        if(empty($this->_fw)){
            $this->_fw = new ErObject($this->device,
                'firewall>group');
        }
        return $this->_fw ;
    }

    private function read_queues(): array
    {
        $post = [
            'data' => [
                'scenario' => '.Basic_Queue',
                'action' => 'load',
            ]
        ];
        $data = $this->client()->post('feature.json',$post);
        $queues = $data['FEATURE']['data']['bq-config'] ?? [];
        $map = [];
        foreach ($queues as $queue){
            $map[$queue['src']] = $queue ;
        }
        return $map ;
    }

    private function unknown(): int
    {
        return 0;
    }

    private function action(): string
    {
        foreach ($this->batch as $item)
        {
            if(isset($item['action'])){ return $item['action']; }
        }
        return 'set';
    }

    private function clean_data($array): array
    {
        $diff = ['path' => null,'batch' => null,'disabled' => null,
            'action' => null,'server' => null,'id' => null];
        return array_diff_key($array,$diff);
    }

    private function type(): string
    {
        foreach ($this->batch as $item)
        {
            if(isset($item['path'])){ return $item['path']; }
        }
        return 'unknown';
    }

    private function conf(): object
    {
        if(empty($this->_conf)){
            $this->_conf = $this->db()->readConfig()
                ?? new stdClass();
        }
        return $this->_conf ;
    }

    protected function db(): ApiSqlite
    {
        if(empty($this->_db)){
            $this->_db = new ApiSqlite();
        }
        return $this->_db ;
    }


    private function setErr($err): void
    {
        foreach(array_keys($this->batch_success) as $batch){
            $this->batch_failed[$batch] = $err;
        }
        $this->batch_success = [];
    }

    public function success(): array { return $this->batch_success; }

    public function failed(): array { return $this->batch_failed; }
}