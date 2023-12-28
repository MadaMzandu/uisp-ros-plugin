<?php
include_once 'api_sqlite.php';
class ApiSites
{
    private $_sites ;
    private $_blackboxes ;

    public function set($ids)
    {
        $this->delete($ids); // just clean up before
        $services = $this->find_services($ids) ?? [];
        MyLog()->Append('Setting sites');
        foreach($services as $service){
            $device = $this->create_device($service);
            if($device){
                $this->create_link($service,$device);
            }
        }
    }

    private function create_link($service, $device)
    {
        $sid = $service['site'] ?? null ;
        $uplinks = $this->find_uplinks($sid);
        if(!$uplinks){ return; }
        $did = $device->identification->id ?? null ;
        $if = array_values($uplinks)[0] ;
        $uplink = array_keys($uplinks)[0];
        if(!$did){ return; }
        $post['deviceIdFrom'] =$uplink;
        $post['deviceIdTo'] = $did;
        $post['interfaceIdFrom'] = $if;
        $post['interfaceIdTo'] = 'eth1';
        $this->ucrm()->post('data-links',$post) ;
    }

    private function create_device($service): ?object
    {
        $data = ['role' => 'router','enablePing' => false,'snmpCommunity' => 'public'];
        $ip = $service['address'] ?? null ;
        $sid = $service['site'] ?? null ;
        if(!$ip || !$sid){ return null; }
        $data['ip'] = $ip;
        $name = $service['company'] ?? $service['firstName'] . ' ' . $service['lastName'];
        $data['hostname'] = "RosP_". $service['address'] . '_' . $name;
        $device = $this->ucrm()->post('devices/connect/other',$data);
        $did = $device->identification->id ?? null ;
        if(!$did){ return null; }
        $site['siteId'] = $sid ;
        $site['deviceIds'][] = $did ;
        $auth = $this->ucrm()->post('devices/authorize',$site);
        $done = $auth->result ?? false ;
        return $done ? $device : null ;
    }

    public function delete($ids)
    {
        $sites = $this->find_sites($ids);
        $blacks = $this->find_blackboxes($sites);
        foreach($blacks as $black){
            $this->ucrm()->delete("devices/$black");
        }
    }

    private function find_services($ids): array
    {
        $cache = $this->cache();
        $cache->exec('attach "data/data.db" as tmp');
        $sql = sprintf("select services.*,clients.company,clients.firstName,clients.lastName,".
            "network.address,network.address6 from services ".
            "left join clients on services.clientId=clients.id ".
            "left join tmp.network on services.id=network.id where services.id in (%s)",implode(',',$ids));
        $data = $cache->selectCustom($sql) ?? [];
        $map = [];
        $sites = $this->find_sites($ids);
        foreach ($data as $item){
            $item['site'] = $sites[$item['id']] ?? null ;
            $map[$item['id']] = $item ;
        }
        $cache->exec('detach tmp');
        return $map ;
    }

    private function find_sites($ids): array
    {
        if(empty($this->_sites)){
            $this->_sites = [];
            foreach($ids as $id){
                $service = $this->ucrm(false)->get("clients/services/$id");
                if(is_object($service)){
                    $site = $service->unmsClientSiteId ?? null ;
                    if($site) $this->_sites[$id] = $site ;
                }
            }
        }
        return $this->_sites;
    }

    private function find_blackboxes($sites): array
    {
        if(empty($this->_blackboxes)){
            $this->_blackboxes = [];
            foreach($sites as $site){
                $blacks = $this->ucrm()->get('devices',['siteId' => $site,'type' => 'blackBox']);
                foreach($blacks as $black){
                    $name = $black->identification->name ;
                    if(preg_match("#^\s*RosP#",$name)){
                        $this->_blackboxes[] = $black->identification->id ;
                    }
                }
            }
        }
        return $this->_blackboxes ;
    }

    private function find_uplinks($sid): array
    {
//        $uplinks = $this->ucrm()->get("sites/$sid/uplink-devices",[]) ?? [];
        $uplinks = $this->ucrm()->get('devices',['siteId' => $sid,'role' => 'station']) ?? [];
        $map = [];
        foreach ($uplinks as $uplink){
            $did = $uplink->identification->id ?? null ;
            $ifs = $this->find_ifs($did);
            foreach($ifs as $if){
                $status = $if->status->status ?? 'offline';
                $type = $if->identification->type ?? "br";
                $name = $if->identification->name ?? "eth0";
                if($type == 'eth' && $status == 'active'){ $map[$did] = $name ;}
            }
        }
        return $map ;
    }

    private function find_ifs($did): ?array
    {
        $data = $this->ucrm()->get("devices/$did/detail",['withStations' => 'false']);
        return $data->interfaces ?? null ;
    }

    private function find_link($router,$radio): bool
    {
        $links = $this->ucrm()->get("data-links/device/$router",[]) ?? [];
        foreach($links as $link){
            $from = $link->from->device->identification->id ?? null ;
            $to = $link->to->device->identification->id ?? null;
            if($from == $radio || $to == $radio){ return true; }
        }
        return false ;
    }

    private function ucrm($unms = true)
    {
        $api = new ApiUcrm();
        $api->unms = $unms ;
        return $api ;
    }

    private function cache(){ return myCache(); }
}
