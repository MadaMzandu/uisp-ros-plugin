<?php
include_once 'api_sqlite.php';
class ApiSites
{

    public function set($ids)
    {
        $map = $this->find_services($ids) ?? [];
        foreach($map as $service){
            $this->create($service);
        }
    }

    private function create($service)
    {
        $data = ['role' => 'router','enablePing' => false,'snmpCommunity' => 'public'];
        $ip = $service['address'] ?? null ;
        if(!$ip){ return; }
        $data['ip'] = $ip;
        $data['hostname'] = $service['company']
            ?? $service['firstName'] . " " . $service['lastName'] . '-' . $service['id'];
        $conn = $this->ucrm()->post('devices/connect/other',$data);
        $device = $conn->identification->id ?? null ;
        $site['siteId'] = $service['site'] ?? null ;
        $site['deviceIds'][] = $device ;
        $auth = $this->ucrm()->post('devices/authorize',$site);
        $done = $auth->result ?? false ;
        if($done){
            $id = $service['id'] ?? null ;
            $sql = sprintf("update sites set device='%s' where service=%s",$device,$id);
            $this->cache()->exec($sql);
        }
    }

    public function delete($ids)
    {
        $services = $this->find_services($ids) ?? [];
        $devices = [];
        foreach($services as $service){
            $device = $service['dev'] ?? null ;
            if($device) $devices[$service['id']] = $device; }
        if($devices){
           $res = $this->ucrm()->post('devices/bulkdelete',['ids' => array_values($devices)]);
           $this->cache()->exec(sprintf('update sites set device=null where service in (%s)',
               implode(',',$ids)));
           $failed = $res->undeletedIds ?? [];
           if($failed){
               $map = array_reverse($devices) ?? [];
               foreach($failed as $item){
                   $id = $map[$item] ?? null ;
                   if($id){
                       $sql = "update sites set device=$item where service=$id";
                       $this->cache()->exec($sql);
                   }
               }
           }
        }
    }

    private function find_services($ids): array
    {
        $cache = $this->cache() ;
        $cache->exec('attach "data/data.db" as tmp');
        $sql = sprintf("select services.*,clients.company,clients.firstName,clients.lastName,".
            "network.address,network.address6,sites.id as site,sites.service,sites.device as dev from services ".
            "left join sites on services.id=sites.service ".
            "left join clients on services.clientId=clients.id ".
            "left join tmp.network on services.id=network.id where services.id in (%s)",implode(',',$ids));
        $data = $cache->selectCustom($sql) ?? [];
        $map = [];
        foreach ($data as $item){
            $map[$item['id']] = $item ;
        }
        return $map ;
    }

    private function ucrm($unms = true)
    {
        $api = new WebUcrm();
        $api->unms = $unms ;
        return $api ;
    }

    private function cache(){ return new ApiSqlite('data/cache.db'); }
}
