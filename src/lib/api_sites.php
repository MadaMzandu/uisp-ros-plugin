<?php
include_once 'api_sqlite.php';
class ApiSites
{

    public function set($ids)
    {
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
        $uplinks = $this->get_uplinks($sid);
        if(!$uplinks){ return null; }
        $did = $device->identification->id ?? null ;
        if(!$did || $this->find_link($did,$uplinks[0])){ return null; }
        $post['deviceIdFrom'] =$uplinks[0];
        $post['deviceIdTo'] = $did;
        $post['interfaceIdFrom'] = 'br0';
        $post['interfaceIdTo'] = 'eth1';
        return $this->ucrm()->post('data-links',$post) ;
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
        $site['siteId'] = $sid ;
        $site['deviceIds'][] = $did ;
        $auth = $this->ucrm()->post('devices/authorize',$site);
        $done = $auth->result ?? false ;
        if($done){
            $id = $service['id'] ?? null ;
            $sql = sprintf("update sites set device='%s' where service=%s",$did,$id);
            if($this->cache()->exec($sql)){ return $device; }
        }
        return null ;
    }

    public function delete($ids)
    {
        $services = $this->find_services($ids) ?? [];
        MyLog()->Append('Deleting sites');
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
        $cache = $this->cache();
        $cache->exec('attach "data/data.db" as tmp');
        $sql = sprintf("select services.*,clients.company,clients.firstName,clients.lastName,".
            "network.address,network.address6,sites.id as site,sites.service,sites.link,sites.device as dev from services ".
            "left join sites on services.id=sites.service ".
            "left join clients on services.clientId=clients.id ".
            "left join tmp.network on services.id=network.id where services.id in (%s)",implode(',',$ids));
        $data = $cache->selectCustom($sql) ?? [];
        $map = [];
        foreach ($data as $item){
            $map[$item['id']] = $item ;
        }
        $cache->exec('detach tmp');
        return $map ;
    }

    private function get_uplinks($sid): array
    {
        $uplinks = $this->ucrm()->get("sites/$sid/uplink-devices",[]) ?? [];
        //$uplinks = $this->ucrm()->get('devices',['siteId' => $sid]) ?? [];
        $map = [];
        foreach ($uplinks as $dev){
            $did = $dev->identification->id ?? null ;
            $ifs = $this->get_ifs($did);
            foreach($ifs as $if){
                $type = $if->identification->type ?? "eth";
                if($type == 'br'){ $map[] = $did ;}
            }
        }
        return $map ;
    }

    private function get_ifs($did): ?array
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

    private function cache(){ return new ApiSqlite('data/cache.db'); }
}
