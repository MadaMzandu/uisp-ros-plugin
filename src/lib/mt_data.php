<?php
include_once 'data.php';

class MtData extends Data
{
    public function get_orphans($device): array
    { //find orphans

        $api = new MT();
        $list = $api->list($device,['/ppp/secret','/queue/simple','/ip/hotspot/user',
            '/ip/dhcp-server/lease','/ipv6/dhcp-server/binding']);
        $orphans = [];
        $fill = array_fill_keys(['.id','name','mac-address','target',
            'comment','remote-address','address','duid','path','comment'],null);
        $path = null ;
        foreach($list as $item){
            if(is_string($item)){ $path = $item; continue; }
            $comment = $item['comment'] ?? 'none';
            $sids = preg_split("#(\D+)#",$comment);
            $sids = array_diff($sids,[""]);
            if($sids){ //only entries with numeric comments
                $address = $item['address'] ?? $item['target']
                    ?? $item['remote-address'] ?? '0.0.0.0';
                $id = $this->find_service($address);
                $check = abs($id) ;
                if($id < 1 || !in_array($check,$sids))
                { //not cached or not matching comment
                    $trim = array_intersect_key($item,$fill);
                    $trim['device'] = $device->id ;
                    $trim['path'] = $path ;
                    if(in_array($check,$sids)) $trim['service'] = $check; //to delete from provisioned
                    $orphans[] = $trim ;
                }
            }

        }

        return $orphans ;
    }

    public function account(): ?array
    {
        switch($this->type()) {
            case 'dhcp': return $this->dhcp();
            case 'ppp': return $this->ppp();
            case 'hotspot': return $this->hotspot();
            default: return null;
        }
    }

    public function profile(): ?array
    {
        $type = $this->type();
        if($type == 'dhcp') return null ;
        // Bootstrap an absent suspension profile; an existing one remains administrator-owned.
        if($this->disabled()) return self::suspensionBootstrap($this->service);
        $path = $type == 'hotspot' ? '/ip/hotspot/user/profile/' : '/ppp/profile';
        $data = [
            'path' => $path,
            'name' => $this->profile_name(),
            'rate-limit' => $this->profile_limits(),
            'parent-queue' => $this->parent_name(),
            'address-list' => $this->addr_list(),
            'comment' => self::profileOwner($this->plan['id'],(int)$this->service['id']),
            '_owner' => self::profileOwner($this->plan['id'],(int)$this->service['id']),
        ];
        if($type == 'ppp') $data['local-address'] = null;
        return $data;
    }

    public static function suspensionBootstrap(array $service): ?array
    {
        if (!in_array($service['status'] ?? 1,[3,4,5,2,8])
            || in_array($service['action'] ?? '',['delete','remove','queues_remove'],true)
            || filter_var($service['mac'] ?? '',FILTER_VALIDATE_MAC)) return null;
        if (!isset($service['username']) || (string)$service['username']==='') return null;
        $configuration=(array)($service['_config'] ?? mySqlite()->readConfig());
        $name=$configuration['disabled_profile'] ?? 'default';
        $list=$configuration['disabled_list'] ?? '';
        $rate=$configuration['disabled_rate'] ?? 0;
        if (!is_string($name) || trim($name)==='' || !is_string($list)
            || !is_numeric($rate) || (float)$rate<0 || !is_finite((float)$rate*1000000))
            throw new InvalidArgumentException('invalid_suspension_profile_configuration');
        $rateLimit=(float)$rate>0 ? sprintf('%sM/%sM',$rate,$rate) : '';
        $hotspot=!empty($service['hotspot']);
        $profile=['path'=>$hotspot ? '/ip/hotspot/user/profile' : '/ppp/profile',
            'name'=>$name,'address-list'=>$list,'comment'=>'Suspendidos',
            '_bootstrap_suspension'=>true,'_bootstrap_required'=>false,
            '_suspension_configuration'=>['profileName'=>$name,'addressList'=>$list,'rateLimit'=>$rateLimit]];
        if ($rateLimit!=='') $profile['rate-limit']=$rateLimit;
        if ($hotspot) $profile['address-pool']='none';
        else { $profile['parent-queue']='none'; $profile['local-address']=null; }
        if (isset($service['batch'])) $profile['batch']=$service['batch'];
        return $profile;
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
        if(in_array($this->type(),['ppp','hotspot'],true)){
            $id=(int)$this->service['id'];
            $queue=['path'=>'/queue/simple','name'=>self::suspensionQueueName($id),
                'comment'=>self::suspensionOwner($id),'_owner'=>self::suspensionOwner($id)];
            if(!$this->disabled() || !$this->disabled_rate()){
                $queue['action']='remove';
                return $queue;
            }
            $targets=array_filter([$this->ip(),$this->ip(true)]);
            if(!$targets) return null;
            return $queue + ['target'=>implode(',',$targets),'max-limit'=>$this->disabled_rate(),
                'limit-at'=>'0/0','parent'=>'none','burst-limit'=>'0/0','burst-threshold'=>'0/0','burst-time'=>'0/0'];
        }
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
                'target' => implode(',', array_filter([$ip, $ipv6])),
                'max-limit' => $this->disabled_rate(),
                'limit-at' => $this->disabled_rate(),
                'parent' => 'none',
                'burst-limit' => '0/0',
                'burst-threshold' => '0/0',
                'burst-time' => '0/0',
                'comment' => $this->account_comment(),
            ];
        }
        $data = [
            'path' => '/queue/simple',
            'name' => sprintf('%s - %s',$this->account_name(),$id),
            'target' => implode(',', array_filter([$ip, $this->has_dhcp6() ? $ipv6 : null])),
            'max-limit' => $this->to_pair($limits['rate']),
            'limit-at' => $this->to_pair($limits['limit'],false),
            'burst-limit' => $this->to_pair($limits['burst'],false),
            'burst-threshold' => $this->to_pair($limits['thresh'],false),
            'burst-time' => $this->to_pair($limits['time'],false),
            'priority' => $this->to_pair($limits['prio'],false),
            'parent' => $this->parent_name(),
            'comment' => $this->account_comment(),
        ];
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
        $lease = $this->conf()->lease_time ?? $this->conf()->dhcp_lease_time ?? 60;
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
        $lease = $this->conf()->lease_time ?? $this->conf()->dhcp_lease_time ?? 60;
        $ipv6 = $this->ip(true);
        if(!$ipv6){ return null; }
        return [
            'batch' => $this->service['batch'] ?? null ,
            'path' => '/ipv6/dhcp-server/binding',
            'address' => $ipv6,
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
        $device = $this->find_device();
        $pool_str = $device->pool6 ?? null ;
        if(!$pool_str) return null ;
        $pool = explode(',',$pool_str)[0] ;
        $len = $device->pfxLength ?? 64 ;
        return [
            'path' => '/ipv6/pool',
            'name' => $this->pool_name(),
            'prefix' => $pool,
            'prefix-length' => $len,
            'comment' => 'uisp-ros-plugin:ipv6-pool:' . $this->pool_name(),
            '_owner' => 'uisp-ros-plugin:ipv6-pool:' . $this->pool_name(),
        ];
    }

    private function pool_name(): string
    {
        $device = $this->find_device();
        $prefix = trim(explode(',', (string)($device->pool6 ?? ''))[0]);
        return self::managedPoolName((int)($this->service['device'] ?? 0), $prefix, (int)($device->pfxLength ?? 64));
    }

    public static function managedPoolName(int $deviceId, string $prefix, int $length): string
    {
        // Different device-pool revisions coexist so an old retry cannot change a newer binding's pool.
        $parts = explode('/', trim($prefix), 2);
        $packed = @inet_pton($parts[0]);
        $network = $packed !== false ? bin2hex($packed) : strtolower($parts[0]);
        $revision = substr(hash('sha256', $network . '/' . ($parts[1] ?? '') . ':' . $length), 0, 24);
        return 'uisp-ipv6-' . $deviceId . '-' . $revision;
    }

    private static function safePoolCommands(array $service, array $commands): array
    {
        $renamed = [];
        foreach ($commands as $command) {
            if (trim($command['path'] ?? '', '/') !== 'ipv6/pool' || ($command['action'] ?? '') === 'remove') continue;
            $oldName = (string)($command['name'] ?? '');
            if ($oldName !== 'uisp-pool' && empty($command['_owner'])) throw new RuntimeException('A queued IPv6 pool has no verified ownership');
            $renamed[$oldName] = self::managedPoolName((int)($service['device'] ?? 0),
                (string)($command['prefix'] ?? ''), (int)($command['prefix-length'] ?? 64));
        }
        $safe = [];
        foreach ($commands as $command) {
            $path = trim($command['path'] ?? '', '/');
            if ($path === 'ipv6/pool') {
                if (($command['action'] ?? '') === 'remove' && empty($command['_owner'])) continue;
                if (isset($renamed[$command['name'] ?? ''])) $command['name'] = $renamed[$command['name']];
                if (($command['action'] ?? '') !== 'remove') {
                    $command['comment'] = 'uisp-ros-plugin:ipv6-pool:' . $command['name'];
                    $command['_owner'] = $command['comment'];
                }
            }
            if (isset($command['prefix-pool']) && isset($renamed[$command['prefix-pool']])) $command['prefix-pool'] = $renamed[$command['prefix-pool']];
            $safe[] = $command;
        }
        return $safe;
    }

    public function parent(): ?array
    {
        if($this->conf()->disable_contention) return null ;
        if($this->disabled()) return null ;
        $parent=[
            'path' => '/queue/simple',
            'name' => $this->parent_name(),
            'target' => $this->parent_target(),
            'max-limit' => $this->parent_total(),
            'limit-at' => $this->parent_total(),
            'comment' => 'uisp-ros-plugin:plan-parent:'.(int)$this->plan['id'],
            '_owner' => 'uisp-ros-plugin:plan-parent:'.(int)$this->plan['id'],
        ];
        if((int)($this->plan['id'] ?? 0)<1){
            $parent['comment']='uisp-ros-plugin:service-parent:'.(int)$this->service['id'];
            $parent['_owner']=$parent['comment'];
        }
        return $parent;
    }

    private function parent_target(): ?string
    {
        if((int)($this->plan['id'] ?? 0)<1) return implode(',',array_filter([$this->ip(),$this->ip(true)]));
        $did = $this->service['device'] ?? 0 ;
        $sql = 'SELECT network.address,network.address6 FROM services LEFT JOIN network '
            . 'ON services.id=network.id WHERE services.planId=:plan AND services.device=:device';
        $data = $this->dbCache()->selectCustom($sql, [':plan' => $this->plan['id'], ':device' => $did]) ?? [];
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
        if((int)($this->plan['id'] ?? 0)<1) return 'uisp-service-'.(int)$this->service['id'].'-parent';
        return sprintf('uisp-plan-%s-parent',$this->plan['id']);
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
        Data::validatePlanNumbers(['id'=>$this->plan['id'],'uploadSpeed'=>$upload,'downloadSpeed'=>$download],(int)$this->service['id']);
        return sprintf("%sM/%sM",$upload,$download);
    }

    private function parent_children(): int
    {
        if((int)($this->plan['id'] ?? 0)<1) return 1;
        $did = $this->service['device'] ?? 0 ;
        $sql = 'SELECT COUNT(services.id) FROM services WHERE services.planId=:plan AND services.device=:device';
        $count = $this->dbCache()->singleQuery($sql, false, [':plan' => $this->plan['id'], ':device' => $did]);
        return max($count,1);
    }

    private function profile_name(): string
    {
        if($this->disabled())
            return $this->conf()->disabled_profile ?? 'default';
        $name=self::managedProfileName($this->plan['id'] ?? null,(int)$this->service['id'],$this->plan['name'] ?? null);
        if($name===($this->conf()->disabled_profile ?? null))
            throw new RuntimeException('The suspension profile cannot use a plugin-managed active plan name');
        return $name;
    }

    public static function managedProfileName($planId,?int $serviceId=null,?string $planName=null): string
    {
        if(!ctype_digit((string)$planId) || (int)$planId<1){
            if($serviceId!==null && $serviceId>0) return 'uisp-service-'.$serviceId.'-profile';
            throw new InvalidArgumentException('A valid plan or service ID is required for a managed RouterOS profile');
        }
        // The immutable plan ID remains part of both the visible name and its
        // ownership marker, so plans with equal labels cannot share a profile.
        // Keep the historical ID-only fallback for old jobs without a plan name.
        $name=$planName===null ? '' : preg_replace('/[\x00-\x20\x7F]+/u',' ',trim($planName));
        if($name===null || trim($name)==='') return 'uisp-plan-'.(int)$planId;
        return trim($name).' [UISP#'.(int)$planId.']';
    }

    private static function profileOwner($planId,int $serviceId): string
    {
        return ctype_digit((string)$planId) && (int)$planId>0
            ? 'uisp-ros-plugin:plan:'.(int)$planId : 'uisp-ros-plugin:service-profile:'.$serviceId;
    }
    private static function suspensionQueueName(int $serviceId): string { return 'uisp-service-'.$serviceId.'-suspension'; }
    private static function suspensionOwner(int $serviceId): string { return 'uisp-ros-plugin:suspension:'.$serviceId; }

    // Upgrade retries prepared by older plugin versions without changing account
    // identity, router identity, addresses or their frozen speed settings.
    public static function safePreparedCommands(array $service,array $commands,string $operation='update'): array
    {
        $commands = self::safePoolCommands($service, $commands);
        $disabled=in_array($service['status'] ?? 1,[3,4,5,2,8]);
        $planId=$service['_plan']['id'] ?? $service['planId'] ?? null;
        $result=[];
        $suspensionRate=null;
        $account=null;
        $hasSuspensionQueue=false;
        $addressTargets=[];
        $deleting=in_array($operation,['delete','remove','queues_remove'],true)
            || in_array($service['action'] ?? '',['delete','remove','queues_remove'],true);
        $bootstrap=$deleting ? null : self::suspensionBootstrap($service);
        if ($bootstrap!==null) foreach ($commands as $prepared) {
            if (($prepared['_bootstrap_suspension'] ?? false)!==true || ($prepared['_bootstrap_required'] ?? false)!==true
                || ($prepared['name'] ?? null)!==$bootstrap['name'] || ($prepared['path'] ?? null)!==$bootstrap['path']
                || ($prepared['_suspension_configuration'] ?? null)!==$bootstrap['_suspension_configuration']) continue;
            $bootstrap['_bootstrap_required']=true;
            if (isset($prepared['local-address']) && is_string($prepared['local-address'])
                && filter_var($prepared['local-address'],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) $bootstrap['local-address']=$prepared['local-address'];
        }
        foreach($commands as $command){
            $path=trim($command['path'] ?? '','/');
            $remove=($command['action'] ?? '')==='remove';
            // A deletion/identity cleanup must retire active sessions even when
            // the preceding successful update did not require a reconnect.
            if ($deleting && in_array($path, ['ppp/active','ip/hotspot/active'], true))
                $command['_reconnect_required'] = true;
            if(in_array($path,['ppp/secret','ip/hotspot/user','ip/dhcp-server/lease',
                'ipv6/dhcp-server/binding','ppp/active','ip/hotspot/active'],true)
                || ($path==='queue/simple' && empty($command['_owner'])
                    && !in_array($command['name'] ?? '', ['servicePlan-'.$planId.'-parent',
                        'uisp-plan-'.(int)$planId.'-parent'], true))){
                // Retried snapshots from earlier releases receive the same ownership guard.
                // Human-readable legacy comments remain compatible without trusting any matching username.
                $command['_service_id']=(int)$service['id'];
                $command['_client_id']=(int)($service['clientId'] ?? 0);
            }
            if(in_array($path,['ppp/secret','ip/hotspot/user','ip/dhcp-server/lease','ipv6/dhcp-server/binding'],true)){
                foreach(['remote-address','remote-ipv6-prefix','address'] as $field)
                    if(!empty($command[$field])) $addressTargets[]=$command[$field];
            }
            if(in_array($path,['ppp/profile','ip/hotspot/user/profile'],true)){
                if($remove){
                    // Old snapshots do not establish ownership of named profiles.
                    if(empty($command['_owner'])) continue;
                } elseif($disabled){
                    $suspensionRate=$command['rate-limit'] ?? null;
                    continue;
                } else {
                    $command['name']=self::managedProfileName($planId,(int)$service['id'],$service['_plan']['name'] ?? null);
                    $command['comment']=self::profileOwner($planId,(int)$service['id']);
                    $command['_owner']=self::profileOwner($planId,(int)$service['id']);
                }
            }
            if(in_array($path,['ppp/secret','ip/hotspot/user'],true)){
                $account=$command;
                if(!$remove && !$disabled && isset($command['profile']))
                    $command['profile']=self::managedProfileName($planId,(int)$service['id'],$service['_plan']['name'] ?? null);
            }
            if($path==='queue/simple' && ($command['name'] ?? '')===self::suspensionQueueName((int)($service['id'] ?? 0)))
                $hasSuspensionQueue=true;
            if((int)$planId>0){
                $legacyParent='servicePlan-'.$planId.'-parent';
                $parent='uisp-plan-'.(int)$planId.'-parent';
                if($path==='queue/simple' && ($command['name'] ?? '')===$legacyParent){
                    // A historical name/comment does not prove ownership of a shared queue.
                    if($remove) continue;
                    $command['name']=$parent;
                }
                if($path==='queue/simple' && ($command['name'] ?? '')===$parent){
                    $command['comment']='uisp-ros-plugin:plan-parent:'.(int)$planId;
                    $command['_owner']=$command['comment'];
                }
                foreach(['parent','parent-queue'] as $field)
                    if(($command[$field] ?? '')===$legacyParent) $command[$field]=$parent;
            }
            if((int)$planId<1){
                $legacyParent='servicePlan-'.$planId.'-parent';
                $parent='uisp-service-'.(int)$service['id'].'-parent';
                if($path==='queue/simple' && ($command['name'] ?? '')===$legacyParent){
                    if($remove) continue; // Legacy fallback parent may serve other accounts.
                    $command['name']=$parent;
                    $command['comment']='uisp-ros-plugin:service-parent:'.(int)$service['id'];
                    $command['_owner']=$command['comment'];
                }
                foreach(['parent','parent-queue'] as $field)
                    if(($command[$field] ?? '')===$legacyParent) $command[$field]=$parent;
            }
            $result[]=$command;
        }
        if((int)$planId<1 && $addressTargets){
            $parent='uisp-service-'.(int)$service['id'].'-parent';
            foreach($result as &$command)
                if(trim($command['path'] ?? '','/')==='queue/simple' && ($command['name'] ?? '')===$parent
                    && ($command['action'] ?? '')!=='remove')
                    $command['target']=implode(',',array_unique($addressTargets));
            unset($command);
        }
        if($account && !$hasSuspensionQueue && (int)($service['id'] ?? 0)>0){
            $id=(int)$service['id'];
            $queue=['path'=>'/queue/simple','name'=>self::suspensionQueueName($id),
                'comment'=>self::suspensionOwner($id),'_owner'=>self::suspensionOwner($id),'batch'=>$account['batch'] ?? null];
            $targets=array_filter([$account['remote-address'] ?? $account['address'] ?? null,$account['remote-ipv6-prefix'] ?? null]);
            if(!$deleting && $disabled && $suspensionRate && $targets){
                $queue+=['target'=>implode(',',$targets),'max-limit'=>$suspensionRate,'limit-at'=>'0/0',
                    'parent'=>'none','burst-limit'=>'0/0','burst-threshold'=>'0/0','burst-time'=>'0/0'];
            } else $queue['action']='remove';
            // Install a suspension cap before changing/disconnecting its account.
            array_unshift($result,$queue);
        }
        if ($bootstrap!==null && !$deleting && $account!==null) {
            $bootstrap['batch']=$account['batch'] ?? $service['batch'] ?? null;
            array_unshift($result,$bootstrap);
        }
        return $result;
    }

    private function profile_limits(): ?string
    {
        if($this->disabled()) return $this->disabled_rate();
        $limits = $this->limits();
        $values = [];
        foreach (['rate','burst','thresh','time','prio','limit'] as $key) {
            $limit = $limits[$key];
            $mbps = $key == 'rate';
            if($key == 'prio'){ $values[] = $limit[0] ?? 8 ;}
            else{ $values[] = $this->to_pair($limit, $mbps); }
        }
        return implode(' ', $values);
    }

    private function has_dhcp6(): bool
    {
        $duid = $this->make_duid() ;
        return $duid && $this->ip(true);
    }

    private function make_duid(): ?string
    {
        $val = $this->service['duid'] ?? $this->service['mac'] ?? '' ;
        $stripped = preg_replace('/[:\s-]/', '', strtolower((string)$val));
        $stripped = preg_replace('/^0x/', '', $stripped);
        if (!preg_match('/^(?:[0-9a-f]{2}){6,}$/', $stripped)) return null;
        return '0x' . $stripped;
    }

    private function make_iaid(): int
    {
        $val = $this->service['iaid'] ?? 1 ;
        if (is_string($val) && preg_match('/^0x[0-9a-f]+$/i', $val)) $val = hexdec($val);
        if (!is_numeric($val) || (float)$val < 0 || (float)$val > 4294967295 || (float)$val != floor((float)$val)) {
            throw new InvalidArgumentException('DHCPv6 IAID must be an integer between 0 and 4294967295');
        }
        return (int)$val;
    }


}
