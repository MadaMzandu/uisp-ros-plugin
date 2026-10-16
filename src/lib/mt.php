<?php

include_once 'routeros_api.class.php';
include_once 'device.php';

class MT extends Device
{

    protected ?stdClass $device = null ;
    protected ?array $batch = null;
    protected ?object $batch_device = null ;
    protected ?array $batch_failed = null;
    protected ?array $batch_success = null;
    protected ?object $api = null;
    /** Ownership observed before a deletion; sessions are disconnected only for that same account. */
    private array $sessionOwnership = [];
    private array $resolvedLocals = [];
    private ?string $ownedLocalFallback = null;
    private ?string $connectionFailure = null;
    private array $cancellationBootstrapGuards = [];
    private $ownershipResolver = null;
    private $verificationObserver = null;
    private array $verificationChecks = [];
    private ?float $verificationDeadline = null;
    private bool $retirementScope = false;
    private $retirementGuard = null;
    private array $retirementSessions = [];
    private array $retirementForeignAccounts = [];
    private bool $retirementVerifying = false;

    /** Internal terminal cleanup can observe a re-used identity without ever removing its new owner. */
    public function withRetirementScope(callable $operation,?callable $guard=null)
    {
        $previous=[$this->retirementScope,$this->retirementGuard,$this->retirementSessions,$this->retirementForeignAccounts,$this->retirementVerifying];
        $this->retirementScope=true; $this->retirementGuard=$guard; $this->retirementSessions=[]; $this->retirementForeignAccounts=[]; $this->retirementVerifying=false;
        try { return $operation(); }
        finally { [$this->retirementScope,$this->retirementGuard,$this->retirementSessions,$this->retirementForeignAccounts,$this->retirementVerifying]=$previous; }
    }

    /** Evidence collection cannot alter execution success or leak into a subsequent batch. */
    public function withVerificationScope(callable $observer, callable $operation)
    {
        $previous=$this->verificationObserver; $checks=$this->verificationChecks;
        $this->verificationObserver=$observer; $this->verificationChecks=[];
        try { return $operation(); }
        finally { $this->verificationObserver=$previous; $this->verificationChecks=$checks; }
    }

    private function captureVerification(array $command,array $effective,string $action,?string $skip=null): void
    {
        if ($this->verificationObserver===null) return;
        try {
            require_once __DIR__.'/api_provisioning_verification.php';
            $this->verificationChecks[]=ApiProvisioningVerification::expectation($command,$effective,$action,$skip);
        } catch (Throwable $ignored) {
            $this->verificationChecks[]=['path'=>'','kind'=>'resource','skip'=>'identity_unavailable'];
        }
    }

    /** Exact read-only checks; never call write, prepare, make-static or a queue executor. */
    public function readVerification(object $device,array $checks,?float $deadline=null): array
    {
        require_once __DIR__.'/api_provisioning_verification.php';
        $this->batch_device=$device; $previous=$this->verificationDeadline; $this->verificationDeadline=$deadline??microtime(true)+12;
        try {
            $this->api=$this->api_connect();
            if (!$this->api) return ApiProvisioningVerification::compare($checks,static fn()=>null,$this->verificationDeadline);
            return $this->api->withReadDeadline($this->verificationDeadline,fn()=>ApiProvisioningVerification::compare($checks,fn($path,$filter)=>$this->read($path,$filter),$this->verificationDeadline));
        } catch (Throwable $ignored) { return ApiProvisioningVerification::compare($checks,static fn()=>null,$this->verificationDeadline); }
        finally { if ($this->api) $this->api_disconnect(); $this->verificationDeadline=$previous; }
    }

    /** Permission exists only during this verified update, never in a stored command. */
    public function withOwnershipResolver(callable $resolver, callable $operation)
    {
        $previous = $this->ownershipResolver;
        $this->ownershipResolver = $resolver;
        try { return $operation(); }
        finally { $this->ownershipResolver = $previous; }
    }

    protected function write($post)
    {
        $opened = $this->xor_connect();
        try {
        if (!$this->api) throw new RuntimeException('RouterOS connection failed');
        //check and prepare
        $managedProfile=$this->isManagedProfile($post);
        $ownedProfile=$managedProfile ? $this->findManagedProfile($post) : null;
        $id = $managedProfile ? ($ownedProfile['.id'] ?? null) : $this->find_id($post);
        if (in_array(trim($post['path'] ?? '', '/'), ['ppp/active', 'ip/hotspot/active'], true)
            && ($post['action'] ?? null) === 'remove' && ($post['_reconnect_required'] ?? null) === false) {
            // The identity/ownership lookup above remains mandatory even for an unchanged session.
            $this->captureVerification($post,[],'remove','reconnect_not_required');
            return [];
        }
        $renamingProfile=$ownedProfile!==null && ($post['action'] ?? '')!=='remove'
            && ($ownedProfile['name'] ?? null)!==($post['name'] ?? null);
        if($id && isset($post['_owner']) && !$managedProfile){
            $matches=$this->read($post['path'],['name'=>$post['name']]);
            if(!is_array($matches) || count($matches)!==1
                || ($matches[0]['comment'] ?? '')!==$post['_owner'])
                throw new RuntimeException('A RouterOS resource with this reserved name is not owned by the plugin');
        }
        $action = $id ? 'set' : 'add';
        $wants = $post['action'] ?? null;
        if ($wants == 'remove') {
            if (!$id) { $this->captureVerification($post,[],'remove',in_array(trim($post['path'],'/'),['ppp/active','ip/hotspot/active'],true)?'reconnect_already_absent':null); return []; }
            if ($this->find_deps($post)) {
                $shared=$managedProfile || preg_match('/^uisp-ros-plugin:(?:plan-parent|ipv6-pool):/D',$post['_owner']??'')===1;
                $this->captureVerification($post,[],'remove',$shared?'shared_resource_retained':'required_resource_retained'); return null;
            }
            else $action = 'remove';
        }
        $path = $post['path'];
        $data = $this->prep_data($post);
        if ($managedProfile && $action !== 'remove') $data['comment'] = $this->profileLabel($post['_owner']);
        if ($action != 'add') $data['.id'] = $id;
        $destination=null;
        if($action!=='remove' && $this->isSuspensionCap($post)){
            // Simple queues stop at the first matching target. The suspension cap
            // must precede general and dynamically created queues for this address.
            $queues=$this->read('/queue/simple');
            if(!is_array($queues)) throw new RuntimeException($this->error() ?: 'Could not read suspension queue order');
            $first=$queues[0]['.id'] ?? null;
            if($queues && (!$first || !is_string($first))) throw new RuntimeException('RouterOS queue order has no valid first identity');
            if($first && $first!==$id){
                if($action==='add') $data['place-before']=$first;
                else $destination=$first;
            }
        }
        //begin write
        $this->api->write(sprintf("/%s/%s",
            trim($path, '/'), $action), false);
        foreach (array_keys($data) as $key) {
            $this->api->write('=' . $key . '=' . $data[$key], false);
        }
        $this->api->finishSentence();
        $read = $this->api->read() ;
        if($renamingProfile && !$this->find_error($read)){
            $renamed=$this->read($path, ['name'=>$post['name']]);
            if(!is_array($renamed) || count($renamed)!==1 || ($renamed[0]['.id'] ?? null)!==$id
                || !$this->profileOwnedBy($renamed[0], $post['_owner']))
                throw new RuntimeException('The owned RouterOS profile rename could not be verified');
        }
        if($destination && !$this->find_error($read)){
            $this->api->write('/queue/simple/move',false);
            $this->api->write('=numbers='.$id,false);
            $this->api->write('=destination='.$destination,false);
            $this->api->finishSentence();
            $read=$this->api->read();
        }
        if (!$this->find_error($read)) $this->captureVerification($post,$data,$action);
        return $read ;
        } finally {
            if($opened && $this->api) $this->api_disconnect();
        }
    }

    private function isSuspensionCap(array $post): bool
    {
        if(trim($post['path'] ?? '','/')!=='queue/simple'
            || !preg_match('/^uisp-ros-plugin:suspension:([1-9][0-9]*)$/D',$post['_owner'] ?? '',$match)) return false;
        return ($post['name'] ?? '')==='uisp-service-'.$match[1].'-suspension';
    }

    private function isManagedProfile(array $post): bool
    {
        return in_array(trim($post['path'] ?? '', '/'), ['ppp/profile','ip/hotspot/user/profile'], true)
            && preg_match('/^uisp-ros-plugin:(?:plan|service-profile):[1-9][0-9]*$/D', $post['_owner'] ?? '') === 1;
    }

    private function profileLabel(string $owner): string
    {
        if (preg_match('/^uisp-ros-plugin:plan:([1-9][0-9]*)$/D', $owner, $match)) return 'Plan #' . $match[1];
        if (preg_match('/^uisp-ros-plugin:service-profile:([1-9][0-9]*)$/D', $owner, $match)) return 'Servicio #' . $match[1];
        throw new RuntimeException('Invalid profile ownership');
    }

    private function profileOwnedBy(array $row, string $owner): bool
    {
        if (($row['comment'] ?? null) === $owner) return true;
        if (($row['comment'] ?? null) !== $this->profileLabel($owner)) return false;
        // A plain display label is only an identity marker with the matching reserved profile name.
        if (preg_match('/^uisp-ros-plugin:plan:([1-9][0-9]*)$/D', $owner, $match)) {
            return ($row['name'] ?? '') === 'uisp-plan-' . $match[1]
                || preg_match('/\\[UISP#' . $match[1] . '\\]$/D', $row['name'] ?? '') === 1;
        }
        return ($row['name'] ?? '') === 'uisp-service-' . substr($owner, strrpos($owner, ':') + 1) . '-profile';
    }

    /** Locate the same owned object when its display name changes; never adopt an external profile. */
    private function findManagedProfile(array $post): ?array
    {
        $named=$this->read($post['path'], ['name'=>$post['name']]);
        if(!is_array($named)) throw new RuntimeException($this->error() ?: 'RouterOS profile identity lookup failed');
        if(count($named)>1) throw new RuntimeException('Multiple RouterOS profiles match the requested name');
        if($named && !$this->profileOwnedBy($named[0], $post['_owner']))
            throw new RuntimeException('A RouterOS profile with this name is not owned by the plugin');
        if(($post['action'] ?? '')==='remove'){
            // A historical snapshot may refer to a profile that has since been
            // renamed. Do not redirect its delete to the replacement name.
            $record=$named[0] ?? null;
        } else {
            $owned=$this->read($post['path'], ['comment'=>$post['_owner']]);
            if(!is_array($owned)) throw new RuntimeException($this->error() ?: 'RouterOS profile ownership lookup failed');
            $labelled=$this->read($post['path'], ['comment'=>$this->profileLabel($post['_owner'])]);
            if(!is_array($labelled)) throw new RuntimeException($this->error() ?: 'RouterOS profile ownership lookup failed');
            foreach ($labelled as $row) {
                if ($this->profileOwnedBy($row, $post['_owner'])) $owned[] = $row;
            }
            if(count($owned)>1) throw new RuntimeException('Multiple RouterOS profiles claim the same plugin plan ownership');
            $record=$owned[0] ?? null;
            if($named && (!$record || ($record['.id'] ?? null)!==($named[0]['.id'] ?? null)))
                throw new RuntimeException('RouterOS profile identity changed during ownership lookup');
        }
        if($record===null) return null;
        $id=$record['.id'] ?? null;
        if(!is_string($id) || $id==='') throw new RuntimeException('The owned RouterOS profile has no valid identity');
        return $record;
    }

    protected function xor_connect(): bool
    {// connect if not already connected
        if(!$this->api){
            $this->api = $this->api_connect() ;
            if($this->api) return true;
        }
        return false ;
    }

    protected function write_batch(?callable $beforeWrite = null): int
    {
        $api = $this->api_connect();
        if (!$api) {
            MyLog()->Append("mt failed to connect sending batch to queue");
            if ($this->cancellationBootstrapGuards) $this->batch_failed['cancellation-bootstrap'] = $this->connectionFailure ?? 'device connect failed';
            $this->send_to_queue($this->connectionFailure ?? 'device connect failed');
            return 0;
        }
        $this->api = $api ;
        $writes = 0;
        try {
            foreach ($this->cancellationBootstrapGuards as $profile) {
                if (!$this->isSuspensionBootstrap($profile) || ($profile['_bootstrap_required'] ?? false)!==true)
                    throw new RuntimeException('cancelled_resource_snapshot_incomplete');
                $records = $this->readSuspensionProfile($profile);
                // An absent profile is already retired. Never create or change a
                // suspension profile while reconciling a cancelled operation.
                if (!$records) continue;
                $this->validateSuspensionBootstrap($profile);
                if ($this->sessionFieldsDiffer($this->suspensionBootstrapData($profile), $records[0]))
                    throw $this->externalProfileFailure([],trim($profile['path'],'/'),$profile['name'],'bootstrap_failed');
            }
            // Check accounts before earlier commands can install a suspension cap or change a shared profile.
            foreach ($this->batch as $post) {
                if (isset($post['_service_id'])) $this->lookupOwnedRecords($post);
            }
            $prepared = $this->prepareReconnectDecisions($this->batch);
            if ($prepared !== $this->batch) {
                // Persist an elevated reconnect decision before any profile/account can change.
                if ($beforeWrite !== null) $beforeWrite($prepared);
                $this->batch = $prepared;
            }
            if ($this->retirementScope && $this->retirementGuard!==null) ($this->retirementGuard)();
        } catch (Throwable $error) {
            if ($this->cancellationBootstrapGuards) $this->batch_failed['cancellation-bootstrap'] = $error->getMessage();
            $this->send_to_queue($error->getMessage());
            $this->api = null;
            $api->disconnect();
            return 0;
        }
        foreach ($this->batch as $post) {
            $id = $post['batch'] ?? null ;
            if ($id && isset($this->batch_failed[$id])) continue;
            try {
                $result = $this->isSuspensionBootstrap($post) ? $this->writeSuspensionBootstrap($post) : $this->write($post);
            } catch (Throwable $error) {
                $result = ['!trap' => [['message' => $error->getMessage()]]];
            }
            if ($this->find_error($result)) {
                if($id) {
                    $this->batch_failed[$id] = json_encode($this->redact($result));
                    unset($this->batch_success[$id]);
                }
                MyLog()->Append('mt write error: ' . json_encode([$this->redact($post), $this->redact($result)]), 6);
            } else {
                if($id) $this->batch_success[$id] = $id;
                $writes++;
            }
        }
        if ($this->retirementScope && $writes===count($this->batch) && !$this->batch_failed) {
            // Acknowledgement alone is insufficient to release an address held by a retired account.
            try {
                $this->retirementVerifying=true;
                foreach ($this->batch as $post) {
                    if (($post['action']??null)!=='remove') continue;
                    if (isset($post['_service_id'])) {
                        if ($this->lookupOwnedRecords($post)) throw new RuntimeException('lifecycle_resources_remain');
                    } elseif (preg_match('/^uisp-ros-plugin:(?:suspension|service-parent|service-profile):[1-9][0-9]*$/D',$post['_owner']??'')===1) {
                        $rows=$this->read($post['path'],['name'=>$post['name']]);
                        if (!is_array($rows)) throw new RuntimeException('lifecycle_resources_unconfirmed');
                        foreach($rows as $row) {
                            if (($row['comment']??null)===$post['_owner']) throw new RuntimeException('lifecycle_resources_remain');
                            if (preg_match('/^uisp-ros-plugin:[a-z-]+:[1-9][0-9]*$/D',$row['comment']??'')!==1)
                                throw new RuntimeException('lifecycle_resources_unconfirmed');
                        }
                    }
                }
            } catch (Throwable $error) {
                $this->batch_failed['retirement']='lifecycle_resources_unconfirmed';
                $writes=0;
            } finally { $this->retirementVerifying=false; }
        }
        if ($this->verificationObserver!==null && $writes===count($this->batch) && !$this->batch_failed) {
            try { $api->withReadDeadline(microtime(true)+12,fn()=>($this->verificationObserver)($this->verificationChecks,fn($path,$filter)=>$this->read($path,$filter))); }
            catch (Throwable $ignored) { /* An acknowledged write is never replayed because evidence failed. */ }
        }
        $this->batch = null;
        $this->api = null ;
        $api->disconnect();
        return $writes;
    }

    /** Verify RouterOS drift before writes and report shared profile repairs for durable propagation. */
    private function prepareReconnectDecisions(array $commands): array
    {
        foreach ($commands as &$disconnect) {
            $activePath = trim($disconnect['path'] ?? '', '/');
            if (!in_array($activePath, ['ppp/active', 'ip/hotspot/active'], true)
                || ($disconnect['action'] ?? null) !== 'remove'
                || !array_key_exists('_reconnect_required', $disconnect)
                || !is_bool($disconnect['_reconnect_required'])) continue;
            unset($disconnect['_profile_repair']);
            $principalPath = $activePath === 'ppp/active' ? 'ppp/secret' : 'ip/hotspot/user';
            $name = $disconnect['name'] ?? null;
            $batch = $disconnect['batch'] ?? null;
            if (!is_string($name) || $name === '' || !is_string($batch) || $batch === ''
                || !isset($disconnect['_service_id'], $disconnect['_client_id']))
                throw new RuntimeException('An unchanged session has no verifiable provisioning identity');
            $accounts = array_values(array_filter($commands, static function (array $command) use ($principalPath, $name, $batch): bool {
                return trim($command['path'] ?? '', '/') === $principalPath && ($command['name'] ?? null) === $name
                    && ($command['batch'] ?? null) === $batch;
            }));
            if (!$accounts && $disconnect['_reconnect_required'] === true) continue;
            if (count($accounts) !== 1) throw new RuntimeException('An unchanged session has no unique desired account');
            $account = $accounts[0];
            if (($account['_service_id'] ?? null) !== $disconnect['_service_id']
                || ($account['_client_id'] ?? null) !== $disconnect['_client_id'])
                throw new RuntimeException('The session and desired account have different ownership');
            if (($account['action'] ?? null) === 'remove') { $disconnect['_reconnect_required'] = true; continue; }
            $records = $this->lookupOwnedRecords($account);
            if (!$records) $disconnect['_reconnect_required'] = true;
            $actual = $records[0] ?? [];
            if ($records && (!is_string($actual['.id'] ?? null) || $actual['.id'] === ''))
                throw new RuntimeException('The current account has no verifiable RouterOS identity');
            $desired = $this->prep_data($account);
            $profileName = $desired['profile'] ?? null;
            if (!is_string($profileName) || $profileName === '')
                throw new RuntimeException('The desired account has no verifiable RouterOS profile');
            $profilePath = $principalPath === 'ppp/secret' ? 'ppp/profile' : 'ip/hotspot/user/profile';
            $profiles = array_values(array_filter($commands, static function (array $command) use ($profilePath, $profileName): bool {
                return trim($command['path'] ?? '', '/') === $profilePath && ($command['name'] ?? null) === $profileName
                    && ($command['action'] ?? null) !== 'remove';
            }));
            if (count($profiles) > 1) throw new RuntimeException('The desired session profile is ambiguous');
            $profileChanged = false;
            if ($profiles && !$this->isSuspensionBootstrap($profiles[0])) {
                $profile = $profiles[0];
                if (!$this->isManagedProfile($profile)) throw new RuntimeException('The desired session profile has no verified ownership');
                $currentProfile = $this->findManagedProfile($profile);
                if ($currentProfile === null) $profileChanged = true;
                else {
                    $profileChanged = $this->sessionFieldsDiffer($this->prep_data($profile), $currentProfile);
                    // A display rename of the same owned profile does not change its effective session configuration.
                    if (($actual['profile'] ?? null) === ($currentProfile['name'] ?? null)) $actual['profile'] = $profileName;
                }
            } else {
                // Suspension profiles are configured by the administrator and are never rewritten by the plugin.
                try {
                    $external = $this->read('/' . $profilePath, ['name' => $profileName]);
                } catch (Throwable $error) {
                    throw $this->externalProfileFailure($account, $profilePath, $profileName, 'lookup_failed');
                }
                if (!is_array($external)) throw $this->externalProfileFailure($account, $profilePath, $profileName, 'lookup_failed');
                if (!$external) {
                    if (!$profiles || !$this->isSuspensionBootstrap($profiles[0]))
                        throw $this->externalProfileFailure($account, $profilePath, $profileName, 'missing');
                    $this->validateSuspensionBootstrap($profiles[0],$account);
                    $bootstrapData=$this->suspensionBootstrapData($profiles[0]);
                    foreach ($commands as &$candidate) {
                        if (!$this->isSuspensionBootstrap($candidate) || ($candidate['name'] ?? null)!==$profileName
                            || trim($candidate['path'],'/')!==$profilePath || ($candidate['batch'] ?? null)!==$batch) continue;
                        $candidate['_bootstrap_required']=true;
                        if (isset($bootstrapData['local-address'])) $candidate['local-address']=$bootstrapData['local-address'];
                    }
                    unset($candidate);
                    $disconnect['_reconnect_required']=true;
                }
                if (!$external) continue;
                if (count($external) !== 1) throw $this->externalProfileFailure($account, $profilePath, $profileName, 'ambiguous');
                if (!is_string($external[0]['.id'] ?? null) || $external[0]['.id'] === '')
                    throw $this->externalProfileFailure($account, $profilePath, $profileName, 'invalid_identity');
                if ($profiles && ($profiles[0]['_bootstrap_required'] ?? false)===true) {
                    $this->validateSuspensionBootstrap($profiles[0],$account);
                    if ($this->sessionFieldsDiffer($this->suspensionBootstrapData($profiles[0]),$external[0]))
                        throw $this->externalProfileFailure($account,$profilePath,$profileName,'bootstrap_failed');
                }
            }
            if ($profileChanged) $disconnect['_profile_repair'] = true;
            if ($profileChanged || $this->sessionFieldsDiffer($desired, $actual)) $disconnect['_reconnect_required'] = true;
        }
        unset($disconnect);
        return $commands;
    }

    private function isSuspensionBootstrap(array $command): bool
    {
        return ($command['_bootstrap_suspension'] ?? false)===true && ($command['action'] ?? '')!=='remove'
            && in_array(trim($command['path'] ?? '', '/'),['ppp/profile','ip/hotspot/user/profile'],true);
    }

    private function validateSuspensionBootstrap(array $command,array $account=[]): void
    {
        $configuration=$command['_suspension_configuration'] ?? null;
        $name=$command['name'] ?? null; $path=trim($command['path'] ?? '', '/');
        $list=$command['address-list'] ?? '';
        $rate=$command['rate-limit'] ?? '';
        $valid=is_array($configuration) && is_string($name) && trim($name)!=='' && is_string($list) && is_string($rate)
            && ($configuration['profileName'] ?? null)===$name && ($configuration['addressList'] ?? null)===$list
            && ($configuration['rateLimit'] ?? null)===$rate && ($command['comment'] ?? null)==='Suspendidos'
            && !isset($command['_owner']) && !in_array(strtolower($name),['default','default-encryption'],true)
            && preg_match('/(?:^uisp-(?:plan-\d+|service-\d+-profile)$|\[UISP#\d+\]$)/D',$name)!==1
            && preg_match('/[\p{C}\x{2028}\x{2029}]/u',$name)===0
            && preg_match('/[\p{C}\x{2028}\x{2029}]/u',$list)===0
            && (trim($list)!=='' || $rate!=='');
        if ($rate!=='') {
            $valid=$valid && preg_match('/^([0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)M\/\1M$/D',$rate,$rateMatch)===1;
            $number=isset($rateMatch[1]) ? (float)$rateMatch[1] : 0;
            $valid=$valid && $number>0 && is_finite($number*1000000);
        }
        $allowed=['path','name','address-list','rate-limit','comment','batch','_bootstrap_suspension','_bootstrap_required','_suspension_configuration'];
        if ($path==='ppp/profile') {
            $allowed=array_merge($allowed,['parent-queue','local-address']);
            $valid=$valid && ($command['parent-queue'] ?? null)==='none' && array_key_exists('local-address',$command);
        } elseif ($path==='ip/hotspot/user/profile') {
            $allowed[]='address-pool'; $valid=$valid && ($command['address-pool'] ?? null)==='none';
        } else $valid=false;
        if (!$valid || array_diff(array_keys($command),$allowed))
            throw $this->externalProfileFailure($account,$path,(string)$name,'bootstrap_invalid_configuration');
    }

    private function suspensionBootstrapData(array $command): array
    {
        if (isset($command['local-address']) && is_string($command['local-address'])
            && filter_var($command['local-address'],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) {
            $local=$command['local-address']; unset($command['local-address']);
            return $this->prep_data($command) + ['local-address'=>$local];
        }
        return $this->prep_data($command);
    }

    /** Create-only: never change an existing administrator-owned suspension profile. */
    private function writeSuspensionBootstrap(array $command): array
    {
        $path=trim($command['path'],'/'); $name=$command['name'];
        $records=$this->readSuspensionProfile($command);
        if ($records) {
            if (($command['_bootstrap_required'] ?? false)===true) {
                $this->validateSuspensionBootstrap($command);
                if ($this->sessionFieldsDiffer($this->suspensionBootstrapData($command),$records[0]))
                    throw $this->externalProfileFailure([],$path,$name,'bootstrap_failed');
            }
            $this->captureVerification($command,[],'set','external_profile');
            return [];
        }
        // An absence first discovered during writing has no durable creation decision yet.
        // Retry through preflight so creation debt is frozen before any profile mutation.
        if (($command['_bootstrap_required'] ?? false)!==true)
            throw $this->externalProfileFailure([],$path,$name,'bootstrap_failed');
        $this->validateSuspensionBootstrap($command);
        $data=$this->suspensionBootstrapData($command);
        $this->api->write('/' . $path . '/add',false);
        foreach ($data as $field=>$value) $this->api->write('=' . $field . '=' . $value,false);
        $this->api->finishSentence(); $reply=$this->api->read();
        if ($this->find_error($reply)) throw $this->externalProfileFailure([],$path,$name,'bootstrap_failed');
        $created=$this->readSuspensionProfile($command);
        if (count($created)!==1 || $this->sessionFieldsDiffer($data,$created[0]))
            throw $this->externalProfileFailure([],$path,$name,'bootstrap_failed');
        try { MyLog()->Append(['event'=>'suspension_profile_created','deviceId'=>(int)($this->batch_device->id ?? 0),
            'protocol'=>$path==='ppp/profile' ? 'ppp' : 'hotspot'],LOG_INFO); } catch (Throwable $ignored) {}
        $this->captureVerification($command,$data,'add');
        return [];
    }

    private function readSuspensionProfile(array $command): array
    {
        $path=trim($command['path'],'/'); $name=$command['name'];
        try { $records=$this->read('/' . $path,['name'=>$name]); }
        catch (Throwable $error) { throw $this->externalProfileFailure([],$path,$name,'lookup_failed'); }
        if (!is_array($records)) throw $this->externalProfileFailure([],$path,$name,'lookup_failed');
        if (count($records)>1) throw $this->externalProfileFailure([],$path,$name,'ambiguous');
        if ($records && (!is_string($records[0]['.id'] ?? null) || $records[0]['.id']===''))
            throw $this->externalProfileFailure([],$path,$name,'invalid_identity');
        return $records;
    }

    /** Keep diagnostic context useful without exposing account data or untrusted exception text. */
    private function externalProfileFailure(array $account, string $profilePath, string $profileName, string $reason): RuntimeException
    {
        $safeName = preg_replace_callback('/[\p{C}\x{2028}\x{2029}]/u', static function (array $match): string {
            return '[0x' . strtoupper(bin2hex($match[0])) . ']';
        }, $profileName);
        if ($safeName === null) $safeName = '[invalid_utf8]';
        preg_match('/^.{0,160}/us', $safeName, $match);
        $boundedName = $match[0] ?? '[invalid_utf8]';
        if (strlen($boundedName) < strlen($safeName)) $boundedName .= '...';
        $path = '/' . trim($profilePath, '/');
        $context = [
            'event' => 'router_session_profile_error',
            'serviceId' => isset($account['_service_id']) ? (int) $account['_service_id'] : null,
            'deviceId' => isset($this->batch_device->id) ? (int) $this->batch_device->id : null,
            'protocol' => $profilePath === 'ppp/profile' ? 'ppp' : 'hotspot',
            'profilePath' => $path,
            'profileName' => $boundedName,
            'reason' => $reason,
        ];
        try { MyLog()->Append($context, LOG_ERR); } catch (Throwable $logError) { /* Preserve the router failure if logging is unavailable. */ }
        return new RuntimeException('router_session_profile_' . $reason . ': profilePath=' . $path
            . ' profileName=' . json_encode($boundedName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function sessionFieldsDiffer(array $desired, array $actual): bool
    {
        foreach ($desired as $field => $value) {
            if (in_array($field, ['name', 'comment', 'action', 'path', 'batch', '.id'], true)
                || (is_string($field) && strpos($field, '_') === 0)) continue;
            if ($this->sessionFieldValue((string) $field, $value) !== $this->sessionFieldValue((string) $field, $actual[$field] ?? null)) return true;
        }
        return false;
    }

    private function sessionFieldValue(string $field, $value): string
    {
        if ($value === null || $value === '') return '';
        if ($field === 'password') return (string) $value;
        if (in_array($field, ['disabled', 'only-one', 'change-tcp-mss'], true)) {
            if (in_array($value, [true, 1, '1', 'true', 'yes'], true)) return 'true';
            if (in_array($value, [false, 0, '0', 'false', 'no'], true)) return 'false';
        }
        if (in_array($field, ['local-address', 'remote-address', 'remote-ipv6-prefix', 'address'], true)) {
            $parts = explode('/', (string) $value);
            $packed = @inet_pton($parts[0]);
            if ($packed !== false) return bin2hex($packed) . (isset($parts[1]) ? '/' . $parts[1] : '');
        }
        if ($field === 'rate-limit') {
            $tokens = preg_split('/\s+/', trim((string) $value));
            foreach ($tokens as $index => &$token) {
                $parts = explode('/', $token);
                foreach ($parts as &$part) {
                    if (preg_match('/^([0-9]+(?:\.[0-9]+)?)([kKmMgG]?)$/D', $part, $match)) {
                        $factor = in_array($index, [0, 1, 2, 5], true) ? (['' => 1, 'k' => 1000, 'm' => 1000000, 'g' => 1000000000][strtolower($match[2])]) : 1;
                        $part = sprintf('%.17g', (float) $match[1] * $factor);
                    }
                }
                unset($part);
                $token = implode('/', $parts);
            }
            unset($token);
            return implode(' ', $tokens);
        }
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }

    protected function send_to_queue($error): void
    {
        foreach ($this->batch as $item) {
            $id = $item['batch'] ?? null ;
            if($id) $this->batch_failed[$id] = $error;
        }
        $this->batch = null ;
    }

    protected function find_error($result): bool
    {
        if(is_array($result) && !empty($result)){
            if (isset($result['!trap']) || isset($result['!fatal'])) {
                $error = $result['!trap'][0]['message'] ?? $result['!fatal'][0]['message'] ?? 'RouterOS command failed';
                MyLog()->Append('mt write error: '.$error);
                $this->setErr($error);
                return true ;
            }
        }
        return false;
    }

    private function redact($value)
    {
        if (!is_array($value)) return $value;
        foreach ($value as $key => $item) {
            $value[$key] = preg_match('/password|token|secret|response/i', (string)$key)
                ? '[redacted]' : $this->redact($item);
        }
        return $value;
    }

    public function list($device,$paths)
    {
        $this->batch_device = $device ;
        $data = [];
        $count = 0 ;
        try {
            $api = $this->api_connect();
            if(!$api) throw new RuntimeException($this->connectionFailure ?? 'RouterOS connection failed while reading accounts');
            $this->api = $api ;
            foreach($paths as $path){
                $r = $this->read($path) ;
                if(!is_array($r)) throw new RuntimeException($this->error() ?: 'RouterOS account read failed');
                if($r){
                    $count += sizeof($r);
                    $data[] = $path ;
                    $data = array_merge($data,$r);
                }
            }
        } catch (Throwable $error) {
            $this->setErr($error->getMessage());
            throw $error;
        } finally {
            if ($this->api) $this->api_disconnect();
        }
        MyLog()->Append(['mt_list',"items: $count"]);
        return $data ;
    }

    protected function read($path,$filter = null)
    {  //implements mikrotik print
        $opened = $this->xor_connect() ;
        try {
        if(!$this->api) return  null ;
        $this->api->write(sprintf('/%s/print',trim($path,'/')), false);
        if ($filter) {
            foreach($this->format_filter($filter) as $item){
                $this->api->write($item,false);
            }
        }
        $this->api->finishSentence();
        $read = $this->api->read() ;
        return $this->find_error($read) ? null : $read;
        } finally {
            if($opened && $this->api) $this->api_disconnect();
        }
    }

    protected function api_connect(): ?RouterosAPI
    {
        $this->connectionFailure = null;
        if(!$this->find_device()){
            MyLog()->Append('mt_device_invalid');
            $this->setErr($this->connectionFailure = 'router_device_invalid');
            return null ;
        }
        $api = $this->createApi();
        $api->timeout = 5;
        $api->attempts = 1;
//        $api->debug = true;
        $port = $this->device->port ?? 8728 ;
        $api->port = (int)$port;
        $api->ssl = isset($this->device->ssl)
            ? filter_var($this->device->ssl, FILTER_VALIDATE_BOOLEAN) : $api->port === 8729;
        $api->allow_self_signed = filter_var($this->device->allowSelfSigned ?? false, FILTER_VALIDATE_BOOLEAN);
        $connect=fn()=>$api->connect($this->device->ip,$this->device->user,$this->device->password??null);
        $connected=$this->verificationDeadline===null ? $connect() : $api->withReadDeadline($this->verificationDeadline,$connect);
        if (!$connected) {
            $this->setErr($this->connectionFailure = $api->connectionFailure() ?? 'router_connection_failed');
            try { MyLog()->Append(['event'=>'router_connection_failed','deviceId'=>(int)($this->device->id ?? 0),
                'protocol'=>$api->ssl ? 'tls' : 'tcp','port'=>(int)$api->port,
                'errorCode'=>$this->connectionFailure],LOG_ERR); } catch (Throwable $ignored) {}
            return null ;
        }
        $this->setMess('ok');
        return $api;
    }

    protected function createApi(): RouterosAPI { return new RouterosAPI(); }

    protected function api_disconnect(): void
    {
        $this->api->disconnect();
        $this->api = null ;
    }

    protected function find_deps($data): bool
    {
        $action = $data['action'];
        if($action != 'remove') return false ;
        $deps = $this->dep_paths($data['path']) ;
        $name = $data['name'] ?? null ;
        if(!$deps || !$name) return false ; //profile/parent will have a name
        foreach($deps as $path)
        {
            $filter = sprintf('?%s=%s',$this->dep_key($path),$name);
            $read = $this->read($path,$filter);
            if ($read === null) throw new RuntimeException($this->error() ?? 'RouterOS lookup failed');
            if(!empty($read)){
                return true ;
            }
        }
        return false ;
    }

    protected function dep_key($path): string
    {
        switch (trim($path, '/')) {
            case 'ppp/secret':
            case 'ip/hotspot/user': return 'profile';
            case 'queue/simple': return 'parent';
            case 'ipv6/dhcp-server/binding': return 'prefix-pool';
            default: return 'parent-queue';
        }
    }

    protected function dep_paths($path): ?array
    {
        switch (trim($path,'/'))
        {
            case 'ppp/profile': return ['/ppp/secret'];
            case 'ip/hotspot/user/profile': return ['/ip/hotspot/user'];
            case 'ipv6/pool': return ['/ipv6/dhcp-server/binding'];
            case 'queue/simple': return ['/ppp/profile','/ip/hotspot/user/profile','/queue/simple'];
            default: return null;
        }
    }

    protected function find_id($data): ?string
    {
        if (isset($data['_service_id'])) {
            $records = $this->lookupOwnedRecords($data);
            $path = trim($data['path'], '/');
            if (in_array($path, ['ppp/active','ip/hotspot/active'], true)) {
                $ids = array_column($records, '.id');
                return $ids ? implode(',', $ids) : null;
            }
            $item = $records[0] ?? [];
            $id = $item['.id'] ?? null;
            if ($id && is_string($id)) {
                if (in_array($path, ['ip/dhcp-server/lease','ipv6/dhcp-server/binding'], true)
                    && ($data['action'] ?? '') !== 'remove'
                    && in_array($item['dynamic'] ?? false, [true,'true','yes','1',1], true)) {
                    $this->make_static_lease($id, $data['path']);
                }
                return $id;
            }
            return null;
        }
        $name = $data['name'] ?? null ;
        $mac = $data['mac-address'] ?? null ;
        $duid = $data['duid'] ?? null;
        $filter = [] ;
        if($name!==null && (string)$name!=='') $filter[trim($data['path'], '/') === 'ip/hotspot/active' ? 'user' : 'name'] = $name;
        if($mac) $filter['mac-address'] = $mac ;
        if($duid) $filter['duid'] = $duid ;
        if($duid && isset($data['iaid'])) $filter['iaid'] = $data['iaid'];
        if($filter){
            $path = $data['path'];
            $read = $this->read($path,$filter);
            if ($read === null) throw new RuntimeException($this->error() ?? 'RouterOS identity lookup failed');
            if (in_array(trim($path, '/'), ['ppp/active', 'ip/hotspot/active'], true)) {
                $ids = array_column($read, '.id');
                return $ids ? implode(',', $ids) : null;
            }
            if (count($read) > 1) throw new RuntimeException('Multiple RouterOS records match the service identity');
            $item  = $read[0] ?? [];
            $id = $item['.id'] ?? null ;
            if($id && is_string($id)){
                if(preg_match("/(binding|lease)/",$path)){ //convert dynamic lease
                    $dynamic = $item['dynamic'] ?? 'false' ;
                    if($dynamic == 'true'){ $this->make_static_lease($id,$path);}
                }
                return $id ;
            }
        }
        return null ;
    }

    /** Read and validate before any make-static, set, remove, or active-session mutation. */
    private function lookupOwnedRecords(array $data): array
    {
        $path = trim($data['path'] ?? '', '/');
        $serviceId = (int)($data['_service_id'] ?? 0);
        $clientId = (int)($data['_client_id'] ?? 0);
        if ($serviceId < 1 || $clientId < 1) throw new RuntimeException('The RouterOS account has no valid service ownership');
        $active = in_array($path, ['ppp/active','ip/hotspot/active'], true);
        if ($active) {
            $principal = $data;
            $principal['path'] = $path === 'ppp/active' ? '/ppp/secret' : '/ip/hotspot/user';
            $key = trim($principal['path'], '/') . ':' . $serviceId . ':' . ($data['name'] ?? '');
            if ($this->retirementScope && array_key_exists($key,$this->sessionOwnership)) {
                // Recheck the principal even after preflight; a re-used username cannot inherit permission to disconnect.
                $this->lookupOwnedRecords($principal);
            }
            if (!array_key_exists($key, $this->sessionOwnership)) {
                $this->sessionOwnership[$key] = $this->lookupOwnedRecords($principal) !== [];
            }
            if (!$this->sessionOwnership[$key]) {
                if ($this->retirementScope && empty($this->retirementForeignAccounts[$key])) {
                    $sessions=$this->read($data['path'],[$path==='ip/hotspot/active'?'user':'name'=>$data['name']]);
                    if (!is_array($sessions) || $sessions) throw new RuntimeException('lifecycle_resources_unconfirmed');
                }
                return [];
            }
        }
        $filter = [];
        if (isset($data['name']) && (string)$data['name'] !== '') $filter[$path === 'ip/hotspot/active' ? 'user' : 'name'] = $data['name'];
        foreach (['mac-address','duid','iaid'] as $field) if (isset($data[$field])) $filter[$field] = $data[$field];
        if (!$filter) throw new RuntimeException('The RouterOS account has no verifiable identity');
        $read = $this->read($data['path'], $filter);
        if (!is_array($read)) throw new RuntimeException($this->error() ?: 'RouterOS account ownership lookup failed');
        if ($active) {
            if ($this->retirementScope && !$this->retirementVerifying) {
                if (!array_key_exists($key,$this->retirementSessions)) $this->retirementSessions[$key]=array_column($read,'.id');
                $ids=$this->retirementSessions[$key];
                return array_values(array_filter($read,static fn(array $row): bool=>in_array($row['.id']??null,$ids,true)));
            }
            return $read;
        }
        if (count($read) > 1) throw new RuntimeException('Multiple RouterOS records match the service identity');
        foreach ($read as $record) {
            $comment = (string)($record['comment'] ?? '');
            $owned = preg_match('/^' . preg_quote((string)$clientId, '/') . ' - .+ - '
                . preg_quote((string)$serviceId, '/') . '$/sD', $comment) === 1;
            $lease = in_array($path, ['ip/dhcp-server/lease','ipv6/dhcp-server/binding'], true);
            $dynamic = $lease && in_array($record['dynamic'] ?? false, [true,'true','yes','1',1], true);
            $canAdopt = $dynamic && $comment === '' && ($data['action'] ?? '') !== 'remove';
            if ($canAdopt) {
                foreach (['mac-address','duid','iaid'] as $field) {
                    if (!isset($data[$field])) continue;
                    if (!isset($record[$field]) || strcasecmp((string)$record[$field], (string)$data[$field]) !== 0) {
                        $canAdopt = false; break;
                    }
                }
            }
            if (!$owned && !$canAdopt && $this->ownershipResolver !== null
                && in_array($path, ['ppp/secret','ip/hotspot/user'], true) && ($data['action'] ?? '') !== 'remove') {
                $canAdopt = ($this->ownershipResolver)($data, $record) === true;
                if ($canAdopt) {
                    // UISP verification may take time. Re-read the exact account
                    // before permitting either preflight or the actual set.
                    $fresh = $this->read($data['path'], $filter);
                    $canAdopt = is_array($fresh) && count($fresh) === 1
                        && ($this->ownershipResolver)($data, $fresh[0]) === true;
                    if (!$canAdopt) throw new RuntimeException('router_ownership_record_changed');
                }
            }
            if (!$owned && !$canAdopt) {
                // A well-formed, different owner proves this is no longer the retired service's row.
                // Manual/unknown rows remain incidents; they never become a permission to erase or adopt.
                if ($this->retirementScope && ($data['action']??null)==='remove'
                    && preg_match('/^([1-9][0-9]*) - .+ - ([1-9][0-9]*)$/sD',$comment,$foreign)===1
                    && ((int)$foreign[1]!==$clientId || (int)$foreign[2]!==$serviceId)) {
                    if (in_array($path,['ppp/secret','ip/hotspot/user'],true)) {
                        $key=$path.':'.$serviceId.':'.($data['name']??'');
                        $this->sessionOwnership[$key]=false; $this->retirementForeignAccounts[$key]=true;
                    }
                    return [];
                }
                throw new RuntimeException('The matching RouterOS account or queue belongs to another service or was created manually');
            }
            if (in_array($path, ['ppp/secret','ip/hotspot/user'], true)) {
                $this->sessionOwnership[$path . ':' . $serviceId . ':' . ($data['name'] ?? '')] = true;
            }
        }
        return $read;
    }

    protected function is_queue($path): bool
    {
        return str_contains($path,"queue/simple");
    }

    protected function find_local(): ?string
    {
        $path = '/ip/address/';
        $filter = '?disabled=false,?dynamic=false,?invalid=false';
        $list = $this->read($path,$filter);
        if ($list === null) throw new RuntimeException($this->error() ?? 'RouterOS local address lookup failed');
        $prefix = $list[0]['address'] ?? '';
        $address = explode('/', $prefix)[0];
        return $address !== '' ? $address : ($this->ownedLocalFallback ?? myIPClass()->local());
    }

    protected  function make_static_lease($id,$path): void
    {
        $command = sprintf('/%s/make-static',trim($path,'/'));
        $this->api->write($command,false);
        $this->api->write('=.id=' . $id);
        if ($this->find_error($this->api->read())) throw new RuntimeException($this->error());
    }

    protected function prep_data($data): array
    {
        $clean = [];
        $action = $data['action'] ?? null;
        if($action == 'remove') { return $clean ;} // return blank for remove
        $diff = array_fill_keys(['.id','action','path','batch','_owner','_service_id','_client_id','_reconnect_required'],'#$%&');
        $clean = array_diff_key($data,$diff) ;
        foreach (array_keys($clean) as $key) if (is_string($key) && strpos($key, '_') === 0) unset($clean[$key]);
        if(key_exists('local-address',$data))
            $clean['local-address'] = $this->resolvedLocalAddress($data);
        return $clean ;
    }

    private function resolvedLocalAddress(array $command): ?string
    {
        $key = ($command['batch'] ?? '') . ':' . trim($command['path'] ?? '', '/') . ':'
            . ($command['_owner'] ?? $command['name'] ?? '');
        if (array_key_exists($key, $this->resolvedLocals)) return $this->resolvedLocals[$key];
        $fallback = null;
        if ($this->isManagedProfile($command)) {
            $owned = $this->findManagedProfile($command);
            $candidate = $owned['local-address'] ?? null;
            if (is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $fallback = $candidate;
        }
        $this->ownedLocalFallback = $fallback;
        try { $address = $this->find_local(); }
        finally { $this->ownedLocalFallback = null; }
        $this->resolvedLocals[$key] = $address;
        return $address;
    }

    private function get_data($property)
    { // check and return data object property
        return $this->data->$property ?? null;
    }

    protected function find_device(): bool
    {
        $this->device = null ;
        if($this->batch_device){
            $this->device = $this->batch_device ;
        }
        elseif ($this->svc) {
            $this->device = $this->svc->device();
        }
        elseif ($id = $this->get_data('device_id')) {
            $this->device = $this->db()->selectDeviceById($id);
        }
        elseif ($dev = $this->get_data('device')) {
            $this->device = $this->db()->selectDeviceByDeviceName($dev);
        }
        return (bool)$this->device ;
    }

    public function do_batch($device,$data,?callable $beforeWrite = null): int
    {
        $this->verificationChecks=[];
        $this->sessionOwnership = [];
        $this->resolvedLocals = [];
        $this->ownedLocalFallback = null;
        $this->batch_device = $device ;
        $this->batch = $data ;
        $this->batch_success = [];
        $this->batch_failed = [];
        return $this->write_batch($beforeWrite);
    }

    /** Verify partial bootstrap evidence on the same connection, before any cleanup mutation. */
    public function do_cancellation_cleanup($device,array $commands,array $profiles): int
    {
        $this->cancellationBootstrapGuards = $profiles;
        try { return $this->do_batch($device,$commands); }
        finally { $this->cancellationBootstrapGuards = []; }
    }

    public function failed(): ?array { return $this->batch_failed; }

    public function success(): ?array { return array_diff_key($this->batch_success ?? [], $this->batch_failed ?? []); }

    protected function format_filter($filter): array
    {
        $return = [];
        if(is_string($filter)){
            foreach(explode(',',$filter . ',') as $item){
                if(!empty($item)) $return[] = $item ;
            }
        }
        if(is_array($filter)){
            foreach(array_keys($filter) as $key){
                $return[] = sprintf('?%s=%s',$key,$filter[$key]);
            }
        }
        return $return ;
    }

}
