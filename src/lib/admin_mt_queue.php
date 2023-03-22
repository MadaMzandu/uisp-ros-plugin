<?php
class Admin_Queue extends MT
{
    private $id ;
    private $services ;
    private $deviceData ;
    private $newData ;
    private $plan ;
    private $series ;

    public function fix(){
        if(true) return ; //disable entire thing
        $disabled = $this->conf->disable_contention ?? true ;
        if($disabled) return ;
        $this->batch = [];
        if(function_exists('fastcgi_finish_request'))
            fastcgi_finish_request();
        $devices = $this->db()->selectAllFromTable('devices') ?? [];
        $this->newData =[];
        foreach($devices as $device){
            try{
                $this->fixPlans($device);
            }
            catch (Exception $err){
                $this->setErr($err->getMessage());
            }
        }

    }

    private function fixPlans($device){
        $d = [];
        $plans = (new AdminPlans($d))->list();
        foreach ($plans as $plan){
            $this->plan = $plan ;
            $this->fixDevicePlan($device);
        }
        $this->setTargets();
        $this->writeAll();
    }

    private function writeAll()
    {
        $paths = $this->paths() ;
        $types = array_keys($this->newData) ;
        foreach ($types as $type){
            $path = $paths[$type] ?? '/queue/simple/';
            foreach($this->newData[$type] as $item){
                $item['path'] = $path ;
                $this->set_batch($item);
            }
        }
        $this->write_batch();
    }

    private function fixUnused()
    {
        $paths = $this->paths() ;
        foreach (['parents','profile','hprofile'] as $type){
            $path = $paths[$type] ?? '/queue/simple/';
            $name = $type == 'parents' ? 'servicePlan-' . $this->plan['id']
                : $this->plan['name'];
            $re = '/' . $name . '/' ;
            $keys = array_keys($this->deviceData[$type]);
            $items = preg_grep($re,$keys);
            foreach($items as $item){
                $used =  $this->newData[$type][$item] ?? null ;
                if(!$used){
                    $data = [
                        'path' => $path,
                        'action' => 'remove',
                        '.id' => $this->deviceData[$type][$item]['.id'],
                    ];
                    $this->set_batch($data);
                }
            }
        }
    }

    private function fixDevicePlan($device)
    {
        $plan = $this->plan ;
        $did = $device['id'];
        $this->data->device_id = $did;
        $this->services = $this->getServices($device) ;
        $plans = array_keys($this->services);
        if(in_array($plan['id'],$plans)){
            $this->fixSeries($device);
            $this->fixUnused();
        }
    }

    private function fixSeries($device)
    {
        $plan = $this->plan ;
        $limit = 128 ;
        $services = $this->services[$plan['id']] ?? [];
        if(sizeof($services) <= $limit) return ;
        $this->deviceData = $this->readDevice();
        $this->series = array_chunk($services,$limit);
        $len = sizeof($this->series);
        for($series=0;$series<$len;$series++){
            foreach($this->series[$series] as $user){
                $exists = null ;
                foreach(['ppp','dhcp','hs'] as $t){
                    $exists = $this->deviceData[$t][$user['id']] ?? [] ;
                    if($exists){
                        $type= $t ;
                        $this->fixUser($exists,$type,$series);
                        break ;
                    }
                }

            }
        }
    }

    private function setTargets()
    {
        $keys = array_keys($this->newData['parents']) ?? [];
        foreach ($keys as $key)
        {
            $ta = implode(',',$this->newData['parents'][$key]['target']) ;
            $this->newData['parents'][$key]['target'] = $ta ;
        }
    }

    private function fixUser($user,$type,$series)
    {
        $ip = $user['remote-address']
            ?? $user['address']
            ?? $user['target'] ?? null ;
        if(!$ip) return ;
        $this->fixParent($ip,$series);
        $this->fixProfile($type,$series);
        $data['action'] = 'set';
        $data['.id'] = $user['.id'];
        if($type == 'dhcp') $data['parent'] = $this->qName($series);
        else $data['profile'] = $this->pName($series);
        $this->newData[$type][] = $data;
    }

    private function fixProfile($type,$series)
    {
        if($type == 'dhcp')return ;
        $profile = $type == 'ppp' ? 'profile' : 'hprofile';
        $name = $this->pName($series);
        if(!in_array($profile,array_keys($this->newData)))
            $this->newData[$profile] = [];
        $new = $this->newData[$profile][$name] ?? [];
        if(!$new){
            $exists = $this->deviceData[$profile][$name] ?? null ;
            if($exists) $this->newData[$profile][$name] = [
                'action' => 'set',
                '.id' => $exists['.id'],
                'local-address' => $this->local_address(),
                'rate-limit' => $this->pRate(),
                'parent-queue' => $this->qName($series)
            ];
            else $this->newData[$profile][$name] = [
                'action' => 'add',
                'name' => $name,
                'local-address' => $this->local_address(),
                'rate-limit' => $this->pRate(),
                'parent-queue' => $this->qName($series)
            ];
        }
    }

    private function pqRate($series)
    {
        $u = $this->plan['uploadSpeed'] ?? 0 ;
        $d = $this->plan['downloadSpeed'] ?? 0;
        $ch = sizeof($this->series[$series]);
        $ra = $this->plan['ratio'];
        $sh = intdiv($ch,$ra);
        if($ch % $ra > 0) $sh++ ;
        $um = $u * $sh;
        $dm = $d * $sh;
        return $um . 'M/' . $dm . 'M';
    }

    private function pRate()
    {
        $u = $this->plan['uploadSpeed'] ?? 0 ;
        $d = $this->plan['downloadSpeed'] ?? 0;
        if($u && $d) return $u . 'M/' . $d . 'M';
        return null ;
    }

    private function pName($series)
    {
        $suffix = null ;
        $name = $this->plan['name'] ;
        if($series) $suffix = '-' . $series ;
        return $name . $suffix ;
    }

    private function fixParent($ip,$series){
        $name = $this->qName($series);
        if(!in_array('parents',array_keys($this->newData)))
            $this->newData['parents'] = [];
        if(!in_array($name,array_keys($this->newData['parents']))){
            $exists = $this->deviceData['parents'][$name] ?? null ;
            if($exists) $this->newData['parents'][$name] = [
                'action' => 'set',
                '.id' => $exists['.id']
            ];
            else $this->newData['parents'][$name] = [
                'action' => 'add',
                'name' => $name,
                'comment' => 'do not delete',
            ];
        }
        $rate = $this->pqRate($series);
        $this->newData['parents'][$name]['max-limit'] = $rate ;
        $this->newData['parents'][$name]['limit-at'] = $rate ;
        $this->newData['parents'][$name]['target'][$ip] = $ip ;
    }

    private function qName($series){
        $suffix = null ;
        $name = 'servicePlan-' . $this->plan['id'] . '-parent';
        if($series) $suffix = '-' . $series ;
        return $name . $suffix ;
    }

    private function local_address(): ?string
    { // get one address for profile local address
        $this->path = '/ip/address/';
        $address = null;
        if ($this->read()) {
            foreach ($this->read as $prefix) {
                if ($prefix['dynamic'] == 'true'
                    || $prefix['invalid'] == 'true'
                    || $prefix['disabled'] == 'true') {
                    continue;
                }
                $address = explode('/', $prefix['address'])[0];
                break ;
            }
        }
        return $address ?? (new API_IP())->local();  // or generate one
    }

    private function readDevice()
    {
        $paths = $this->paths() ;
        $data = [];
        foreach (array_keys($paths) as $key){
            $this->path = $paths[$key];
            $data[$key] = $this->read();
        }
        $data['parents'] = $this->mapParents($data['dhcp']);
        foreach(['ppp','dhcp','hs'] as $key){
            $found = $this->mapUsers($data[$key]) ;
            $data[$key] = $found ;
        }
        foreach (['profile','hprofile'] as $key){
            $found = $this->mapProfiles($data[$key]) ;
            $data[$key]= $found ;
        }
        return $data ;
    }

    private function mapProfiles($data)
    {
        $found = [];
        foreach ($data as $profile){
            $found[$profile['name']] = $profile ;
        }
        return $found ;
    }

    private function mapUsers($data)
    {
        $found = [];
        foreach($data as $user){
            $matches = [];
            $comment = $user['comment'] ?? null;
            $match = false ;
            if($comment)$match =
                preg_match('/\d+$/',$user['comment'],$matches) ;
            if($match) $found[$matches[0]] = $user ;
        }
        return $found;
    }

    private function  mapParents($data)
    {
        $parents = [];
        foreach ($data as $q){
            $comment = $q['name'] ?? null ;
            $match = false ;
            if($comment) $match = preg_match('/servicePlan/',$comment);
            if($match) $parents[$q['name']] = $q ;
        }
        return $parents ;
    }

    private function paths()
    {
        return [
            'ppp' => '/ppp/secret/',
            'dhcp' => '/queue/simple/',
            'profile' => '/ppp/profile/',
            'hs' => '/ip/hotspot/user/',
            'hprofile' => '/ip/hotspot/user/profile/',
        ];
    }

    private function  getServices($device)
    {
        $id = $device['id'];
        $recs = $this->db()->selectServicesOnDevice($id);
        $recsmap = [];
        foreach ($recs as $r){
            $recsmap[$r['planId']][$r['id']] = $r ;
        }
        return $recsmap ;
    }

    private function plans()
    {
        return $this->unms()->request('/service-plans') ?? [] ;
    }

    private function unms()
    {
        $api = new ApiUcrm();
        $api->assoc = true ;
        return $api ;
    }
}