<?php
include_once 'data.php';

class MtData extends Data
{
    private ?ApiIP $validationAddresses = null;
    private ?ApiSqlite $previewDatabase = null;
    private ?ApiSqlite $previewCache = null;
    private ?array $previewResourceContext = null;

    /** Build the ordinary desired commands without acquiring reservations or refreshing cache. */
    public static function previewCommands(array $service, array $plan, ApiIP $addresses, ApiSqlite $database, ApiSqlite $cache, array $resourceContext = []): array
    {
        $builder = new self(); $builder->validationAddresses = $addresses;
        $builder->previewDatabase = $database; $builder->previewCache = $cache;
        $builder->previewResourceContext = $resourceContext;
        $plan = ApiServiceQos::effectivePlan($service, $plan);
        $service['_plan'] = $plan;
        $data = $builder->get_data($service, $plan); $commands = [];
        foreach (['pool','parents','profiles','queues','accounts','dhcp6','disconn'] as $key) if (isset($data[$key])) $commands[] = $data[$key];
        $commands = self::safePreparedCommands($service, $commands, 'update');
        return self::retireUnusedServiceProfiles($service, $commands, $resourceContext['applied'] ?? null);
    }

    /** A private profile is retired after reassignment/reset, and only for this exact account identity. */
    public static function retireUnusedServiceProfiles(array $service, array $commands, ?array $applied): array
    {
        $sid = (int) ($service['id'] ?? 0);
        if ($sid < 1 || !$applied || (int) ($applied['id'] ?? 0) !== $sid) return $commands;
        foreach (['device','clientId','username','mac','hotspot','duid','iaid'] as $field)
            if (($applied[$field] ?? null) != ($service[$field] ?? null)) return $commands;
        foreach (['ip','port'] as $field)
            if (($applied['_device'][$field] ?? null) != ($service['_device'][$field] ?? null)) return $commands;
        $used = []; $usedOwners = []; $seen = [];
        foreach ($commands as $command) {
            $path = trim($command['path'] ?? '', '/');
            $identity = $path . ':' . ($command['name'] ?? '');
            if (($command['action'] ?? '') === 'remove') { $seen[$identity] = true; continue; }
            if (in_array($path, ['ppp/secret','ip/hotspot/user'], true))
                $used[($path === 'ppp/secret' ? 'ppp/profile' : 'ip/hotspot/user/profile') . ':' . ($command['profile'] ?? '')] = true;
        }
        foreach ($commands as $command) {
            $path = trim($command['path'] ?? '', '/');
            if (in_array($path, ['ppp/profile','ip/hotspot/user/profile'], true)
                && ($command['action'] ?? '') !== 'remove'
                && ($command['_owner'] ?? '') === 'uisp-ros-plugin:service-profile:' . $sid
                && isset($used[$path . ':' . ($command['name'] ?? '')])) $usedOwners[$path . ':' . $command['_owner']] = true;
        }
        foreach ($applied['_commands'] ?? [] as $command) {
            $path = trim($command['path'] ?? '', '/');
            $identity = $path . ':' . ($command['name'] ?? '');
            if (!in_array($path, ['ppp/profile','ip/hotspot/user/profile'], true)
                || ($command['action'] ?? '') === 'remove'
                || ($command['_owner'] ?? '') !== 'uisp-ros-plugin:service-profile:' . $sid
                || !self::serviceProfileNameMatches($command['name'] ?? null, $sid)
                // A display rename reuses the same owned RouterOS object.
                // Retire it only when the service stops using that profile.
                || isset($usedOwners[$path . ':' . $command['_owner']])
                || isset($used[$identity]) || isset($seen[$identity])) continue;
            $seen[$identity] = true;
            $commands[] = ['path' => '/' . $path, 'name' => $command['name'], '_owner' => $command['_owner'],
                'action' => 'remove', 'batch' => $service['batch'] ?? 'service-' . $sid];
        }
        return $commands;
    }

    protected function db(): ApiSqlite { return $this->previewDatabase ?? parent::db(); }
    protected function dbCache(): ApiSqlite { return $this->previewCache ?? parent::dbCache(); }

    /** Exercise the exact command-builder address paths before retiring a live account. */
    public function validateBeforeCleanup(array $service, array $plan, bool $releasesReservation): void
    {
        $validator = new self();
        $validator->validationAddresses = new ApiIP(true, $releasesReservation);
        $validator->get_data($service, $plan);
    }

    protected function ipapi(): ApiIP
    {
        return $this->validationAddresses ?? parent::ipapi();
    }

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
            'comment' => self::profileOwner($this->plan['id'],(int)$this->service['id'],ApiServiceQos::isOverridden($this->plan)),
            '_owner' => self::profileOwner($this->plan['id'],(int)$this->service['id'],ApiServiceQos::isOverridden($this->plan)),
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
        $member = $this->service;
        if (!isset($member['_commands'])) {
            $member['address'] = $this->ip(); $member['address6'] = $this->ip(true);
        }
        $targets = [$member['address'] ?? ($member['network']['address'] ?? null),
            $member['address6'] ?? ($member['network']['address6'] ?? null)];
        foreach ($member['_commands'] ?? [] as $command) {
            if (!in_array(trim($command['path'] ?? '', '/'), ['ppp/secret','ip/hotspot/user','ip/dhcp-server/lease','ipv6/dhcp-server/binding'], true)) continue;
            foreach (['remote-address','remote-ipv6-prefix','address'] as $field) $targets[] = $command[$field] ?? null;
        }
        $parent=[
            'path' => '/queue/simple',
            'name' => $this->parent_name(),
            'target' => implode(',', array_unique(array_filter($targets))),
            'max-limit' => '0/0',
            'limit-at' => '0/0',
            'comment' => 'uisp-ros-plugin:plan-parent:'.(int)$this->plan['id'],
            '_owner' => 'uisp-ros-plugin:plan-parent:'.(int)$this->plan['id'],
        ];
        if((int)($this->plan['id'] ?? 0)<1){
            $parent['comment']='uisp-ros-plugin:service-parent:'.(int)$this->service['id'];
            $parent['_owner']=$parent['comment'];
        }
        if (!class_exists('ApiSharedResources', false)) require_once __DIR__ . '/api_shared_resources.php';
        $context = $this->previewResourceContext;
        return ApiSharedResources::parentMembership($parent, $member, $this->plan,
            $this->db(), $this->dbCache(), $context === null ? null : ($context['resourceSnapshots'] ?? []),
            $context === null ? null : ($context['blocked'] ?? []));
    }

    private function parent_name(): ?string
    {
        if($this->conf()->disable_contention) return 'none' ;
        if($this->disabled()) return 'none';
        if((int)($this->plan['id'] ?? 0)<1) return 'uisp-service-'.(int)$this->service['id'].'-parent';
        return sprintf('uisp-plan-%s-parent',$this->plan['id']);
    }

    private function profile_name(): string
    {
        if($this->disabled())
            return $this->conf()->disabled_profile ?? 'default';
        $name=self::managedProfileName($this->plan['id'] ?? null,(int)$this->service['id'],$this->plan['name'] ?? null,ApiServiceQos::isOverridden($this->plan));
        if($name===($this->conf()->disabled_profile ?? null))
            throw new RuntimeException('The suspension profile cannot use a plugin-managed active plan name');
        return $name;
    }

    public static function managedProfileName($planId,?int $serviceId=null,?string $planName=null,bool $serviceOverride=false): string
    {
        $name=$planName===null ? '' : preg_replace('/[\x00-\x20\x7F]+/u',' ',trim($planName));
        if($serviceOverride || !ctype_digit((string)$planId) || (int)$planId<1){
            if($serviceId!==null && $serviceId>0) {
                if ($serviceOverride && $name!==null && trim($name)!=='') return trim($name).' [Override S#'.$serviceId.']';
                return 'uisp-service-'.$serviceId.'-profile';
            }
            throw new InvalidArgumentException('A valid plan or service ID is required for a managed RouterOS profile');
        }
        // The immutable plan ID remains part of both the visible name and its
        // ownership marker, so plans with equal labels cannot share a profile.
        // Keep the historical ID-only fallback for old jobs without a plan name.
        if($name===null || trim($name)==='') return 'uisp-plan-'.(int)$planId;
        return trim($name).' [UISP#'.(int)$planId.']';
    }

    /** A visible service marker is ownership evidence only inside its exact reserved name format. */
    public static function serviceProfileNameMatches($name, int $serviceId): bool
    {
        if ($serviceId<1 || !is_string($name) || preg_match('//u',$name)!==1
            || preg_match('/[\x00-\x1F\x7F]/',$name)===1) return false;
        if ($name==='uisp-service-'.$serviceId.'-profile') return true;
        $suffix=' [Override S#'.$serviceId.']';
        return strlen($name)>strlen($suffix) && substr($name,-strlen($suffix))===$suffix
            && trim(substr($name,0,-strlen($suffix)))!=='';
    }

    private static function profileOwner($planId,int $serviceId,bool $serviceOverride=false): string
    {
        return !$serviceOverride && ctype_digit((string)$planId) && (int)$planId>0
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
        $serviceOverride=ApiServiceQos::isOverridden($service['_plan'] ?? []);
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
                    $command['name']=self::managedProfileName($planId,(int)$service['id'],$service['_plan']['name'] ?? null,$serviceOverride);
                    $command['comment']=self::profileOwner($planId,(int)$service['id'],$serviceOverride);
                    $command['_owner']=self::profileOwner($planId,(int)$service['id'],$serviceOverride);
                }
            }
            if(in_array($path,['ppp/secret','ip/hotspot/user'],true)){
                // A frozen legacy payload must not restore an unrelated service ID
                // after its ownership guards have been upgraded to this snapshot.
                // This changes only the outgoing marker; router ownership is still
                // checked independently before any write or reassociation.
                $sid=(int)($service['id'] ?? 0); $cid=(int)($service['clientId'] ?? 0);
                if(!$deleting && !$remove && $sid>0 && $cid>0){
                    $label='Client-'.$cid;
                    if(is_string($command['comment'] ?? null)
                        && preg_match('/^[1-9][0-9]* - (.+) - [1-9][0-9]*$/sD',$command['comment'],$marker)===1)
                        $label=$marker[1];
                    $command['comment']=$cid.' - '.$label.' - '.$sid;
                }
                $account=$command;
                if(!$remove && !$disabled && isset($command['profile']))
                    $command['profile']=self::managedProfileName($planId,(int)$service['id'],$service['_plan']['name'] ?? null,$serviceOverride);
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
