<?php
include_once 'mt.php';
include_once 'mt_data.php';
include_once 'api_ip.php';
include_once 'api_sqlite.php';
include_once 'api_sites.php';
require_once __DIR__ . '/api_job_queue.php';
require_once __DIR__ . '/api_shared_resources.php';
require_once __DIR__ . '/api_plan_catalog.php';
require_once __DIR__ . '/api_site_address_policy.php';
require_once __DIR__ . '/api_router_ownership.php';
require_once __DIR__ . '/api_provisioning_verification.php';

class Batch
{
    private ?array $_plans = null;
    private array $planMetadataErrors = [];
    private ?array $_devices = null ;
    private array $_apis = [];
    private array $batch_failed = [];
    private array $batch_success = [];
    private bool $forceReconnect = false;
    private bool $retirementScope = false;
    private array $retirementMarker = [];
    private int $retirementServiceId = 0;
    private float $lifecycleConflictBudget = 8.0;
    private array $submissionOutcomes = ['requested' => 0, 'completed' => 0, 'failed' => 0, 'failures' => []];

    /** Safe per-service results for a bulk operation; credentials never enter the response. */
    public function outcomes(): array { return $this->submissionOutcomes; }

    /** Validate every replacement family before an automatic wrong-site CRM clear. */
    public function validateSiteAddressReplacement(array $cleared, bool $releasesReservation = true): void
    {
        $candidate=$this->snapshot($cleared,'update');
        (new MtData())->validateBeforeCleanup($candidate,$candidate['_plan'],$releasesReservation);
    }

    /** Validate the exact effective SQL rates while the caller's save transaction can still roll back. */
    public function validateStoredPlan(int $planId): void
    {
        $plan = $this->find_plans(true)[$planId] ?? null;
        if ($plan === null) throw new InvalidArgumentException('Plan does not exist');
        Data::validatePlanNumbers($plan);
    }

    public function del_queues($ids): bool
    {
        return $this->submit($ids, 'queues_remove');
    }

    public function del_parents(): bool
    {
        $plans = $this->find_plans();
        $devices = $this->find_devices();
        $deviceData = [];
        foreach(array_keys($devices) as $did){
            foreach ($plans as $plan){
                $parent = [
                    'path' => '/queue/simple',
                    'action' => 'remove',
                    'name' => sprintf('uisp-plan-%s-parent',$plan['id']),
                    '_owner' => 'uisp-ros-plugin:plan-parent:' . (int)$plan['id'],
                ];
                $deviceData[$did]['parents'][] = $parent ;
            }
        }
        $this->run_batch($deviceData,true);
        return empty($this->batch_failed);
    }

    public function del_accounts(array $ids): bool
    {
        return $this->submit($ids, 'delete');
    }

    public function set_queues(array $ids,$on = true): bool
    {
        $deviceServices = $this->find_services($ids,'update');
        $device_ids = [];
        foreach (array_keys($deviceServices) as $did) {
            if ($did === 'nodev') { continue; }
            $device_ids[] = $did;
        }
        if (!$this->submit($ids, $on ? 'queues_set' : 'queues_remove')) { return false; }
        $db = $this->db();
        $db->transaction(function () use ($db, $on, $device_ids) {
            $conf = $db->readConfig()->disabled_routers ?? null;
            $routers = $conf ? explode(',', (string) $conf) : [];
            $routers = $on ? array_diff($routers, $device_ids) : array_merge($routers, $device_ids);
            $db->saveConfig(['disabled_routers' => implode(',', array_unique($routers))]);
        });
        return true;
    }

    public function set_accounts(array $ids, bool $forceReconnect = false): bool
    {
        $previous = $this->forceReconnect;
        $this->forceReconnect = $forceReconnect;
        try { return $this->submit($ids, 'update'); }
        finally { $this->forceReconnect = $previous; }
    }

    /** Schedule configuration changes without blocking the UI on router I/O. */
    public function queue_accounts(array $ids): array
    {
        $keys=[]; $queue=myJobQueue();
        foreach ($ids as $item) {
            $sid=(int)(is_array($item) ? ($item['id']??0) : $item);
            if ($sid<1) throw new InvalidArgumentException('Invalid service ID');
            $enqueue=fn(): array => $this->enqueueEntries([$item], 'update');
            $keys=array_merge($keys,$queue->ownsServiceLock($sid) ? $enqueue() : $queue->withServiceLock($sid,$enqueue));
        }
        return $keys;
    }

    /** Server-only reviewed synchronization: freeze allocation before durable enqueue. */
    public function queueReviewed(array $service, callable $verify, bool $forceReconnect = false): array
    {
        $sid = (int)($service['id'] ?? 0);
        if ($sid < 1) throw new InvalidArgumentException('Invalid service ID');
        $queue = myJobQueue();
        $enqueue = function () use ($service, $verify, $forceReconnect, $queue): array {
            if ($queue->isServiceBlocked((int)$service['id']) && empty($service['_clear_terminal']))
                throw new RuntimeException('sync_preview_state_changed');
            return $this->db()->transaction(function () use ($service, $verify, $forceReconnect, $queue): array {
                $priorForce = $this->forceReconnect; $this->forceReconnect = $forceReconnect;
                try {
                    $groups = $this->find_services([$service], 'update'); $entries = [];
                    foreach ($groups as $services) foreach ($services as $item) {
                        $snapshot = $this->snapshot($item, 'update');
                        $snapshot['batch'] = 'reviewed-' . (int)$snapshot['id'];
                        $device = $this->operationDevice($snapshot);
                        $snapshot = $this->prepareSnapshotCommands($snapshot, $device, 'update');
                        if ($verify($snapshot) !== true) throw new RuntimeException('sync_preview_state_changed');
                        $applied = $queue->appliedSnapshot((int)$snapshot['id']);
                        if ($applied && !$this->sameAccountIdentity($applied, $snapshot)) {
                            $applied['_event_id'] = $snapshot['_event_id'] ?? null;
                            $entries[] = ['snapshot'=>$this->snapshot($applied, 'delete'), 'operation'=>'delete'];
                        }
                        $entries[] = ['snapshot'=>$snapshot, 'operation'=>'update'];
                    }
                    return $entries ? $queue->enqueueBatch($entries) : [];
                } finally { $this->forceReconnect = $priorForce; }
            });
        };
        return $queue->ownsServiceLock($sid) ? $enqueue() : $queue->withServiceLock($sid, $enqueue);
    }

    /** Fresh terminal-state reviews enqueue cleanup through the ordinary ordered worker. */
    public function queue_deletions(array $ids): array
    {
        $keys=[]; $queue=myJobQueue();
        foreach ($ids as $item) {
            $sid=(int)(is_array($item)?($item['id']??0):$item);
            if ($sid<1) throw new InvalidArgumentException('Invalid service ID');
            $enqueue=fn():array=>$this->enqueueEntries([$item],'delete');
            $keys=array_merge($keys,$queue->ownsServiceLock($sid)?$enqueue():$queue->withServiceLock($sid,$enqueue));
        }
        return $keys;
    }

    public function migrate(array $previous, array $replacement): bool
    {
        $replacement = $this->snapshot($replacement, 'update');
        // A migration's delete is a separate prerequisite job. Validate the
        // replacement before that prerequisite can be queued or touch its account.
        if (!isset($replacement['_commands'])) {
            try { (new MtData())->validateBeforeCleanup($replacement,$replacement['_plan'],true); }
            catch (Throwable $error) {
                if (!$this->resolveLifecycleConflict($error,(int)$replacement['id'])) throw $error;
                (new MtData())->validateBeforeCleanup($replacement,$replacement['_plan'],true);
            }
        }
        $entries = [
            ['snapshot' => $this->snapshot($previous, 'delete'), 'operation' => 'delete'],
            ['snapshot' => $replacement, 'operation' => 'update']
        ];
        $queue = myJobQueue();
        $keys = $queue->enqueueBatch($entries);
        $queue->runSelected($keys);
        return $queue->completed($keys);
    }

    private function submit(array $ids, string $operation): bool
    {
        $this->lifecycleConflictBudget=8.0;
        $this->submissionOutcomes = ['requested' => count($ids), 'completed' => 0, 'failed' => 0, 'failures' => []];
        if (!$ids) { return true; }
        if (!is_array(reset($ids))) {
            $complete = true;
            foreach ($ids as $id) {
                $sid = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($sid === false) throw new InvalidArgumentException('Invalid service ID');
                $queue = myJobQueue();
                $failure = ['serviceId' => $sid];
                try {
                    $ok = $queue->ownsServiceLock($sid) ? $this->submitLocked([$sid], $operation)
                        : $queue->withServiceLock($sid, fn(): bool => $this->submitLocked([$sid], $operation));
                } catch (InvalidArgumentException $error) {
                    // One corrupt legacy plan must not prevent every following service from provisioning.
                    $event = $error instanceof InvalidProvisioningNumber ? 'provisioning_invalid_number' : 'provisioning_invalid_input';
                    $failure = $error instanceof InvalidProvisioningNumber ? $error->context() : $failure;
                    $failure['message'] = $error->getMessage();
                    MyLog()->Append([$event] + $failure, LOG_ERR);
                    $ok = false;
                } catch (Throwable $error) {
                    // A replacement can fail its read-only preflight before any
                    // job exists (for example a genuine address collision).
                    // Keep that failure local to this service in a bulk sync.
                    $known = ['The service address or IPv6 prefix is already reserved by another service'=>'network_address_conflict',
                        'No IPv4 address is available for the PPP service'=>'ppp_ipv4_unavailable',
                        'No address is available for the DHCP service'=>'dhcp_address_unavailable'];
                    if ($error instanceof NetworkAddressConflict) $failure=array_merge($failure,$error->context());
                    $failure['errorCode'] = $error instanceof NetworkAddressConflict ? 'network_address_conflict'
                        : ($known[$error->getMessage()] ?? 'provisioning_failed');
                    $failure['message'] = $error instanceof NetworkAddressConflict || isset($known[$error->getMessage()]) ? $error->getMessage()
                        : 'Provisioning could not be completed for this service';
                    MyLog()->Append(['event'=>'provisioning_service_failed','serviceId'=>$sid,
                        'errorCode'=>$failure['errorCode'],'exceptionType'=>get_class($error)], LOG_ERR);
                    $ok = false;
                }
                $this->submissionOutcomes[$ok ? 'completed' : 'failed']++;
                if (!$ok) $this->submissionOutcomes['failures'][] = $failure;
                $complete = $ok && $complete;
            }
            return $complete;
        }
        // Webhooks and migrations already hold the service gate and carry an explicit frozen identity.
        $completed = $this->submitLocked($ids, $operation);
        $this->submissionOutcomes[$completed ? 'completed' : 'failed'] = count($ids);
        return $completed;
    }

    private function submitLocked(array $ids, string $operation): bool
    {
        try { $keys = $this->enqueueEntries($ids, $operation); }
        catch (Throwable $error) {
            $sid=(int)(is_array($ids[0]??null)?($ids[0]['id']??0):($ids[0]??0));
            if ($operation!=='update' || $sid<1 || !$this->resolveLifecycleConflict($error,$sid)) throw $error;
            $keys=$this->enqueueEntries($ids,$operation);
        }
        if (!$keys) {
            if (in_array($operation, ['update','queues_set'], true)) {
                foreach ($ids as $item) {
                    $sid = (int)(is_array($item) ? ($item['id'] ?? 0) : $item);
                    if ($sid < 1 || !myJobQueue()->isServiceBlocked($sid)) return false;
                }
                return true;
            }
            return false;
        }
        $queue = myJobQueue();
        // An immediate request also retries its own unfinished prerequisites, in version order.
        $queue->runSelected($keys);
        return $queue->completed($keys);
    }

    private function resolveLifecycleConflict(Throwable $error,int $sid): bool
    {
        if ($this->lifecycleConflictBudget<=0) return false;
        $started=microtime(true);
        try { return myJobQueue()->lifecycleReviewer()->resolveConflict($error,myJobQueue(),$sid,microtime(true)+$this->lifecycleConflictBudget); }
        finally { $this->lifecycleConflictBudget=max(0,$this->lifecycleConflictBudget-(microtime(true)-$started)); }
    }

    private function enqueueEntries(array $ids, string $operation): array
    {
        $entries = [];
        foreach ($this->find_services($ids, $operation) as $services) {
            foreach ($services as $service) {
                if (in_array($operation, ['update','queues_set'], true)
                    && empty($service['_clear_terminal']) && myJobQueue()->isServiceBlocked((int)$service['id'])) continue;
                if ($operation==='update' && !isset($service['_commands'])) {
                    $service=ApiSiteAddressPolicy::reconcile($service,
                        ($currentDevice=$this->find_device($service['device']??null)) ? (array)$currentDevice : null,
                        null,'data/data.db',[$this,'validateSiteAddressReplacement']);
                }
                $snapshot = $this->snapshot($service, $operation);
                if ($operation === 'update') {
                    $applied = myJobQueue()->appliedSnapshot((int) $service['id']);
                    if ($applied && !$this->sameAccountIdentity($applied, $snapshot)) {
                        if (!isset($snapshot['_commands'])) (new MtData())->validateBeforeCleanup($snapshot, $snapshot['_plan'], true);
                        $applied['_event_id'] = $snapshot['_event_id'] ?? null;
                        $entries[] = ['snapshot' => $this->snapshot($applied, 'delete'), 'operation' => 'delete'];
                    }
                }
                $entries[] = ['snapshot' => $snapshot, 'operation' => $operation];
            }
        }
        if (!$entries) { return []; }
        $queue = myJobQueue();
        return $queue->enqueueBatch($entries);
    }

    private function snapshot(array $service, string $operation): array
    {
        $hadFrozenDevice = isset($service['_device']);
        unset($service['batch']);
        foreach (['address','address6'] as $field) {
            if (array_key_exists($field,$service)) $service['network'][$field]=$service[$field];
        }
        // An explicit synchronization/reconnection request is part of this job's
        // durable intent, including failures before RouterOS commands are prepared.
        if ($operation === 'update' && ($this->forceReconnect || ($service['_force_reconnect'] ?? null) === true)) {
            $service['_force_reconnect'] = true;
        } else unset($service['_force_reconnect']);
        if ($operation !== 'update') { unset($service['_cleanup'], $service['_prior_network'], $service['_prior_identity']); }
        $device = isset($service['_device']) ? (object) $service['_device'] : $this->find_device($service['device'] ?? null);
        if ($device && !isset($service['_device'])) { $service['_device'] = (array) $device; }
        $service['_config'] ??= (array) $this->db()->readConfig();
        if (in_array($operation, ['update','queues_set'], true)) $this->ensurePlanMetadata((int) ($service['planId'] ?? 0));
        $plans = $this->find_plans();
        $service['_plan'] ??= $plans[$service['planId'] ?? 0] ?? $this->make_plan($service);
        if ($operation === 'update') {
            $applied=myJobQueue()->appliedSnapshot((int)$service['id']);
            foreach (['address'=>false,'address6'=>true] as $field=>$ipv6) {
                $current=array_key_exists($field,$service) ? $service[$field] : ($service['network'][$field]??null);
                $previous=is_array($applied['network']??null) && array_key_exists($field,$applied['network'])
                    ? $applied['network'][$field] : ($applied[$field]??null);
                if (($current===null || $current==='') && is_string($previous) && $previous!==''
                    && !isset($service['_network_reset'][$field])) {
                    // A full synchronization also sees attribute removal when its webhook was missed.
                    // Only a previously literal CRM address implies reset: an already automatic
                    // account's frozen command address must continue to be reused.
                    $service['_network_reset'][$field]=['previous'=>$previous,'generation'=>substr(hash('sha256',
                        json_encode([(int)$service['id'],$field,$previous,$applied,$service['_device']??[]])),0,32)];
                }
            }
            $service['_prior_network'] = [
                'address' => $this->db()->selectAddress((int) $service['id'], false),
                'address6' => $this->db()->selectAddress((int) $service['id'], true)
            ];
        }
        if (in_array($operation, ['delete', 'queues_remove'], true)) {
            foreach (['address' => false, 'address6' => true] as $field => $ipv6) {
                if (empty($service[$field]) && empty($service['network'][$field])) {
                    $service[$field] = $this->db()->selectAddress((int) $service['id'], $ipv6);
                }
            }
        }
        if ($operation === 'delete') {
            $applied = myJobQueue()->appliedSnapshot((int) $service['id']);
            $sameIdentity = (bool) $applied;
            foreach (['device','username','mac','hotspot','duid','iaid'] as $field) {
                if (($applied[$field] ?? null) != ($service[$field] ?? null)) { $sameIdentity = false; break; }
            }
            if ($hadFrozenDevice && !$this->sameAccountIdentity($applied, $service)) { $sameIdentity = false; }
            if ($sameIdentity && isset($applied['_commands'])) {
                // Delete the resources that were actually created, including their original profile and queue names.
                if (isset($applied['_device'])) { $service['_device'] = $applied['_device']; }
                $disconnect = [];
                $commands = [];
                foreach ($applied['_commands'] as $command) {
                    $command['action'] = 'remove';
                    if (in_array(trim($command['path'], '/'), ['ppp/active','ip/hotspot/active'], true)) { $disconnect[] = $command; }
                    else { $commands[] = $command; }
                }
                $service['_commands'] = array_merge(array_reverse($commands), $disconnect);
            }
        }
        Data::validatePlanNumbers($service['_plan'], (int) $service['id']);
        Data::validateSnapshotNumbers($service, (int) $service['id'], (int) ($service['_plan']['id'] ?? 0));
        return $service;
    }

    private function sameAccountIdentity(array $first, array $second): bool
    {
        foreach (['device','clientId','username','mac','hotspot','duid','iaid'] as $field) {
            if (($first[$field] ?? null) != ($second[$field] ?? null)) { return false; }
        }
        foreach (['ip','port'] as $field) {
            if (($first['_device'][$field] ?? null) != ($second['_device'][$field] ?? null)) { return false; }
        }
        return true;
    }

    /** Keep an unapplied-to-CRM allocation receipt through later billing edits. */
    private function retainPoolAllocationProofs(array $service,array $commands,array $allocations): array
    {
        $applied=myJobQueue()->appliedSnapshot((int)$service['id']);
        if (!$applied || !$this->sameAccountIdentity($applied,$service)) return $allocations;
        foreach (['address'=>false,'address6'=>true] as $field=>$ipv6) {
            if (isset($allocations[$field])) continue;
            $fixed=array_key_exists($field,$service) ? $service[$field] : ($service['network'][$field]??null);
            if ($fixed!==null && $fixed!=='') continue;
            $proof=$applied['_pool_allocations'][$field]??null;
            if (!is_array($proof) || !is_string($proof['value']??null) || !array_key_exists('previous',$proof)
                || (!is_string($proof['previous']) && $proof['previous']!==null)
                || !is_string($proof['generation']??null) || !preg_match('/^[a-f0-9]{32}$/D',$proof['generation'])) continue;
            try {
                $value=ApiIP::normalize($proof['value'],$ipv6);
                if ($value!==$proof['value'] || $this->db()->selectAddress((int)$service['id'],$ipv6)!==$value
                    || ($proof['previous']!==null && ApiIP::normalize($proof['previous'],$ipv6)===$value)) continue;
                $values=[];
                foreach ($commands as $command) {
                    if (($command['action']??'set')==='remove'
                        || (int)($command['_service_id']??0)!==(int)$service['id']
                        || (int)($command['_client_id']??0)!==(int)$service['clientId']) continue;
                    $path=trim($command['path']??'','/');
                    $key=$ipv6 ? ($path==='ppp/secret' ? 'remote-ipv6-prefix' : ($path==='ipv6/dhcp-server/binding' ? 'address' : null))
                        : ($path==='ppp/secret' ? 'remote-address' : (in_array($path,['ip/hotspot/user','ip/dhcp-server/lease'],true) ? 'address' : null));
                    if ($key!==null && is_string($command[$key]??null)) $values[ApiIP::normalize($command[$key],$ipv6)]=true;
                }
                if (count($values)===1 && isset($values[$value])) $allocations[$field]=$proof;
            } catch (InvalidArgumentException $error) { continue; }
        }
        return $allocations;
    }

    /** Only settings consumed by a PPP/Hotspot session, independent of job timestamps/comments. */
    private function sessionSettings(array $commands): ?array
    {
        $accounts = array_values(array_filter($commands, static function (array $command): bool {
            return in_array(trim($command['path'] ?? '', '/'), ['ppp/secret', 'ip/hotspot/user'], true)
                && ($command['action'] ?? '') !== 'remove';
        }));
        if (count($accounts) !== 1) return null;
        $account = $accounts[0];
        $profilePath = trim($account['path'], '/') === 'ppp/secret' ? 'ppp/profile' : 'ip/hotspot/user/profile';
        $profiles = array_values(array_filter($commands, static function (array $command) use ($account, $profilePath): bool {
            return trim($command['path'] ?? '', '/') === $profilePath && ($command['action'] ?? '') !== 'remove'
                && ($command['_bootstrap_suspension'] ?? false) !== true
                && ($command['name'] ?? null) === ($account['profile'] ?? null);
        }));
        if (count($profiles) > 1) return null;
        $normalize = static function (array $command): array {
            $values = [];
            foreach ($command as $field => $value) {
                if (str_starts_with((string) $field, '_') || in_array($field, ['.id', 'comment', 'batch', 'action'], true)) continue;
                $values[$field] = $field === 'path' ? trim((string) $value, '/') : (string) ($value ?? '');
            }
            ksort($values, SORT_STRING);
            return $values;
        };
        $accountValues = $normalize($account);
        $profileValues = $profiles ? $normalize($profiles[0]) : null;
        $ownedProfile = null;
        if ($profiles && preg_match('/^uisp-ros-plugin:(?:plan|service-profile):[1-9][0-9]*$/D', $profiles[0]['_owner'] ?? '') === 1) {
            $ownedProfile = $profilePath . ':' . $profiles[0]['_owner'];
            // Human-readable plan renames preserve the same RouterOS profile object and session settings.
            $accountValues['profile'] = '@owned:' . $ownedProfile;
            $profileValues['name'] = '@owned:' . $ownedProfile;
        }
        return ['account' => $accountValues, 'profile' => $profileValues, 'ownedProfile' => $ownedProfile];
    }

    private function sessionProfileIdentity(object $device, array $commands): ?string
    {
        $settings = $this->sessionSettings($commands);
        $profile = $settings['ownedProfile'] ?? null;
        return is_string($profile) ? ApiSharedResources::endpointIdentity($device) . ':profile:' . $profile : null;
    }

    private function prepareSessionReconnect(array $service, array $commands, ApiJobQueue $queue, object $device): array
    {
        $applied = $queue->appliedSnapshot((int) $service['id']);
        $desired = $this->sessionSettings($commands);
        $previous = $this->sessionSettings($applied['_commands'] ?? []);
        $required = ($service['_force_reconnect'] ?? null) === true
            || !$applied || !$this->sameAccountIdentity($applied, $service)
            || $desired === null || $previous === null || $desired !== $previous;
        $profileIdentity = $this->sessionProfileIdentity($device, $commands);
        $revision = $profileIdentity !== null ? $queue->profileRevision($profileIdentity) : null;
        foreach ($commands as &$command) {
            if (!in_array(trim($command['path'] ?? '', '/'), ['ppp/active', 'ip/hotspot/active'], true)) continue;
            $previousRevision = 0;
            foreach ($applied['_commands'] ?? [] as $previousCommand) {
                if (trim($previousCommand['path'] ?? '', '/') !== trim($command['path'], '/')
                    || ($previousCommand['name'] ?? null) !== ($command['name'] ?? null)) continue;
                if (array_key_exists('_profile_revision', $previousCommand)) {
                    $previousRevision = filter_var($previousCommand['_profile_revision'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                }
                break;
            }
            if ($revision !== null) {
                // Another service may already have repaired the shared profile while this live session kept its old settings.
                $required = $required || $previousRevision === false || $revision !== $previousRevision;
                $command['_profile_revision'] = $revision;
            } else unset($command['_profile_revision']);
            // Once an attempt may have changed the router, retain the reconnect until
            // the whole job succeeds, including retries after partial writes/timeouts.
            $command['_reconnect_required'] = ($command['_reconnect_required'] ?? null) === true || $required;
        }
        unset($command);
        return $commands;
    }

    /** Execute exactly this job's identity and commands, never current cached service credentials. */
    public function applySnapshot(array $service, string $operation, ApiJobQueue $queue, array $job): bool
    {
        $this->retirementScope=$operation==='delete' && !empty($service['_lifecycle_retirement']);
        $this->retirementMarker=$this->retirementScope?$service['_lifecycle_retirement']:[];
        $this->retirementServiceId=(int)$service['id'];
        if ($this->retirementScope) $queue->lifecycleReviewer()->assertRetired((int)$service['id'],$service['_lifecycle_retirement']);
        $verificationStages=ApiProvisioningVerification::boundedStages(is_array($service['_verification_stages']??null)?$service['_verification_stages']:[]);
        $verificationDeadline=microtime(true)+12;
        // Previous cleanup was acknowledged before a later stage failed. Re-read
        // its safe journal, without replaying the old removal/disconnection.
        foreach ($verificationStages as &$stage) {
            $oldDevice=$this->find_device($stage['device_id']??0);
            try {
                if (!$oldDevice || ApiSharedResources::endpointIdentity($oldDevice)!==($stage['endpoint']??null)) throw new RuntimeException('router_changed');
                $stage['results']=$queue->withResourceLock($stage['endpoint'],fn()=>$this->device_api($oldDevice->type??'mikrotik')->readVerification($oldDevice,$stage['checks'],$verificationDeadline));
            } catch (Throwable $ignored) { $stage['results']=ApiProvisioningVerification::compare($stage['checks'],static fn()=>null,$verificationDeadline); }
        }
        unset($stage);
        $observe=function(object $target) use (&$verificationStages): callable {
            return function(array $checks,callable $read) use ($target,&$verificationStages): void {
                $verificationStages[]=ApiProvisioningVerification::stage($target,$checks,$read);
            };
        };
        if (in_array($operation, ['update','queues_set'], true)) {
            $planId = (int) ($service['_plan']['id'] ?? $service['planId'] ?? 0);
            $this->ensurePlanMetadata($planId);
            // Unprepared old work must not freeze a nameless/zero-rate fallback.
            // Prepared accounts keep their identity, credentials and allocated addresses.
            if (!isset($service['_commands']) && $planId > 0) $service['_plan'] = $this->find_plans()[$planId];
        }
        $device = $this->operationDevice($service);
        $batchId = 'service-' . (int) $service['id'];
        $service['batch'] = $batchId;
        $delete = $operation === 'delete';
        $queuesOnly = in_array($operation, ['queues_remove', 'queues_set'], true);
        if ($operation === 'update') {
            $refresh = ApiSiteAddressPolicy::refreshForExecution($service, (array)$device, null, 'data/data.db',
                fn(array $candidate) => $this->validateSiteAddressReplacement($candidate));
            if ($refresh['changed']) {
                $next = $queue->preserveReplacedAddresses($service, $refresh['snapshot']);
                if (isset($service['_commands'])) {
                    // Replace addresses in the same named resources; never lose a
                    // partially written account or introduce untracked cleanup.
                    $next = $this->db()->transaction(function () use ($next, $service, $device, $operation): array {
                        unset($next['_commands'], $next['_pool_allocations']);
                        $next = $this->prepareSnapshotCommands($next, $device, $operation);
                        $next['_commands'] = $this->preservePreparedGuards($service['_commands'], $next['_commands'], $service);
                        if ($this->resourceFootprint(MtData::safePreparedCommands($service, $service['_commands'], $operation))
                            !== $this->resourceFootprint($next['_commands'])) throw new RuntimeException('site_address_resources_changed');
                        return $next;
                    });
                }
                $service = $queue->freezePrepared($job, $next);
            }
        }
        if (in_array($operation, ['update','delete'], true)) {
            $debts = $queue->cancellationCleanups($job);
            if ($operation === 'update' && !isset($service['_commands'])) {
                $applied = $queue->appliedSnapshot((int) $service['id']);
                $newCleanup = $applied && !$this->sameAccountIdentity($applied, $service);
                if ($debts || isset($service['_cleanup']) || $newCleanup) {
                    // Cleanup can disconnect an existing account. Validate the
                    // replacement's actual address paths first, without writing
                    // reservations or freezing/replaying its old resources. The
                    // real builder still acquires and revalidates ownership later.
                    $releasesReservation = (bool) $newCleanup;
                    foreach ($debts as $debt) if ($queue->canClearApplied($debt['snapshot'])) $releasesReservation = true;
                    if (isset($service['_cleanup']) && is_array($service['_cleanup']) && !$queue->cleanupCompleted($job)
                        && $queue->canClearApplied($service['_cleanup'])) $releasesReservation = true;
                    (new MtData())->validateBeforeCleanup($service, $service['_plan'] ?? $this->make_plan($service), $releasesReservation);
                }
            }
            if ($debts && $operation === 'update') {
                // Cleanup can retire a live session even when this job already has
                // prepared commands. Persist its reconnect before the first cleanup write.
                $service['_force_reconnect'] = true;
                $service = $queue->freezePrepared($job, $service);
            }
            foreach ($debts as $debt) {
                $cleanup = $debt['snapshot'];
                if ((int) ($cleanup['id'] ?? 0) !== (int) $service['id'])
                    throw new RuntimeException('cancelled_resource_snapshot_incomplete');
                $cleanup['_commands'] = $this->retirementScope ? $this->terminalCommands($cleanup) : $this->cancellationCommands($cleanup);
                $guards = [];
                $remove = []; $disconnect = [];
                $alreadyDeleting = in_array($cleanup['action'] ?? '', ['delete','remove','queues_remove'], true);
                $allRemoval = true;
                foreach ($cleanup['_commands'] as $command) {
                    if (($command['action'] ?? '') !== 'remove') $allRemoval = false;
                    if (($command['_bootstrap_suspension'] ?? false) === true
                        && ($command['_bootstrap_required'] ?? false) === true) $guards[] = $command;
                    // Retire exactly the frozen resources; never late-bind this debt
                    // to another applied snapshot or replay its original set commands.
                    $command['action'] = 'remove';
                    $command['batch'] = $batchId;
                    if (in_array(trim($command['path'] ?? '', '/'), ['ppp/active','ip/hotspot/active'], true)) $disconnect[] = $command;
                    else $remove[] = $command;
                }
                $cleanup['action'] = 'delete';
                $cleanup['_delete_frozen'] = true;
                $cleanup['batch'] = $batchId;
                $cleanup['_commands'] = MtData::safePreparedCommands($cleanup,
                    array_merge($alreadyDeleting || $allRemoval ? $remove : array_reverse($remove), $disconnect), 'delete');
                $cleanupDevice = $this->operationDevice($cleanup);
                $queue->withResourceLock(ApiSharedResources::lockIdentity($cleanupDevice, $cleanup),
                    function () use ($cleanupDevice, $cleanup, $guards, $observe): void {
                        $this->writeCommands($cleanupDevice, $cleanup['_commands'], null, $guards, null, $observe($cleanupDevice));
                    });
                $this->batch_failed = [];
                $this->batch_success = [$batchId => $batchId];
                $service['_verification_stages']=$verificationStages=ApiProvisioningVerification::boundedStages($verificationStages);
                $service=$queue->freezePrepared($job,$service);
                if ($queue->canClearApplied($cleanup)) $this->unsave_batch([$cleanup['device'] => [$cleanup]]);
                $queue->confirmCancellationCleanup($job, (int) $debt['id']);
            }
        }
        if ($delete && empty($service['_delete_frozen'])) {
            $applied = $queue->appliedSnapshot((int) $service['id']);
            if ($applied && $this->sameAccountIdentity($applied, $service)) {
                // Refresh resources before the first write, while keeping the frozen account and physical router identity.
                $current = $this->snapshot($applied, 'delete');
                if (isset($current['_commands'])) {
                    $service['_commands'] = $current['_commands'];
                }
            }
            if (isset($service['_commands'])) {
                $service['_delete_frozen'] = true;
                $service = $queue->freezePrepared($job, $service);
            }
        }
        if ($operation === 'update' && !isset($service['_commands']) && !isset($service['_cleanup'])) {
            $applied = $queue->appliedSnapshot((int) $service['id']);
            if ($applied && !$this->sameAccountIdentity($applied, $service)) {
                // Earlier prepared work may have completed after this intent was queued; clean its actual identity.
                $service['_cleanup'] = $this->snapshot($applied, 'delete');
                $service = $queue->freezePrepared($job, $service);
            }
        }
        if ($operation === 'update' && isset($service['_cleanup']) && !$queue->cleanupCompleted($job)) {
            $cleanup = $service['_cleanup'];
            if (empty($cleanup['_commands'])) { throw new RuntimeException('Applied account has no confirmed resource snapshot for cleanup'); }
            $safeCleanup = MtData::safePreparedCommands($cleanup, $cleanup['_commands'], 'delete');
            if ($safeCleanup !== $cleanup['_commands']) {
                $cleanup['_commands'] = $safeCleanup;
                $service['_cleanup'] = $cleanup;
                $service = $queue->freezePrepared($job, $service);
            }
            $cleanupDevice = $this->operationDevice($cleanup);
            $queue->withResourceLock(ApiSharedResources::lockIdentity($cleanupDevice, $cleanup),
                function () use ($cleanupDevice, $cleanup, $observe): void { $this->writeCommands($cleanupDevice, $cleanup['_commands'], null, [], null, $observe($cleanupDevice)); });
            $cleanup['batch'] = $batchId;
            $this->batch_failed = [];
            $this->batch_success = [$batchId => $batchId];
            $service['_verification_stages']=$verificationStages=ApiProvisioningVerification::boundedStages($verificationStages);
            $service=$queue->freezePrepared($job,$service);
            if ($queue->canClearApplied($cleanup)) { $this->unsave_batch([$cleanup['device'] => [$cleanup]]); }
            $queue->confirmCleanup($job, $cleanup);
        }
        if (!isset($service['_commands'])) {
            $service = $this->db()->transaction(fn(): array => $this->prepareSnapshotCommands($service, $device, $operation));
            $service = $queue->freezePrepared($job, $service);
        }
        $commands = MtData::safePreparedCommands($service, $service['_commands'], $operation);
        if ($commands !== $service['_commands']) {
            $service['_commands'] = $commands;
            $service = $queue->freezePrepared($job, $service);
        }
        $queue->withResourceLock(ApiSharedResources::lockIdentity($device, $service), function () use ($device, $operation, $queuesOnly, $queue, $job, &$service, &$commands, $observe): void {
            if ($operation === 'update') {
                // Re-read while holding the physical router/plan gate. An old
                // customer's retry must not overwrite a newer shared plan.
                $planId = (int) ($service['_plan']['id'] ?? $service['planId'] ?? 0);
                $plans = $this->find_plans(true);
                $commands = ApiSharedResources::refresh($service, $commands, $plans[$planId] ?? null, (array) $this->db()->readConfig());
                $commands = $this->prepareSessionReconnect($service, $commands, $queue, $device);
                if ($commands !== $service['_commands']) {
                    $service['_commands'] = $commands;
                    $service = $queue->freezePrepared($job, $service);
                }
            }
            if ($commands) $this->writeCommands($device, $commands, $operation === 'update'
                ? function (array &$prepared) use ($queue, $job, $device, &$service, &$commands): void {
                    // Router drift may require a reconnect even with an unchanged
                    // applied snapshot. Persist that decision before any router write.
                    $profileIdentity = $this->sessionProfileIdentity($device, $prepared);
                    $repair = false;
                    foreach ($prepared as $command) {
                        if (in_array(trim($command['path'] ?? '', '/'), ['ppp/active', 'ip/hotspot/active'], true)
                            && ($command['_profile_repair'] ?? false) === true) $repair = true;
                    }
                    if ($repair && $profileIdentity === null) throw new RuntimeException('A shared profile repair has no verified profile identity');
                    $revision = $profileIdentity === null ? null : ($repair
                        ? $queue->repairProfileRevision($profileIdentity)
                        : $queue->profileRevision($profileIdentity));
                    foreach ($prepared as &$command) {
                        if (!in_array(trim($command['path'] ?? '', '/'), ['ppp/active', 'ip/hotspot/active'], true)) continue;
                        unset($command['_profile_repair']);
                        if ($revision !== null) $command['_profile_revision'] = $revision;
                        else unset($command['_profile_revision']);
                    }
                    unset($command);
                    if ($prepared !== $commands) {
                        $commands = $prepared;
                        $service['_commands'] = $prepared;
                        $service = $queue->freezePrepared($job, $service);
                    }
                } : null, [], $operation === 'update' ? $service : null, $observe($device));
            elseif (!$queuesOnly) throw new RuntimeException('No router commands were prepared for this service');
        });
        $this->batch_failed = [];
        $this->batch_success = [$batchId => $batchId];
        if (!$queuesOnly) {
            $services = [$service['device'] ?? 'snapshot' => [$service]];
            if ($delete) {
                if ($queue->canClearApplied($service) || ($this->retirementScope && $queue->lifecycleMayRelease($job))) { $this->unsave_batch($services); }
            }
            else { $this->save_batch($services); }
        }
        // Verification is a separate receipt, never an execution exception/retry condition.
        try {
            if ($verificationStages || method_exists($this->device_api($device->type??'mikrotik'),'withVerificationScope'))
                $queue->recordVerification($service,$job,$device,$verificationStages);
        } catch (Throwable $ignored) {}
        return true;
    }

    private function prepareSnapshotCommands(array $service, object $device, string $operation): array
    {
        $batchId = $service['batch'];
        $delete = $operation === 'delete';
        $queuesOnly = in_array($operation, ['queues_remove', 'queues_set'], true);
        $service['_device'] = (array) $device;
        if ($delete && !empty($service['_lifecycle_retirement'])) {
            $service['_commands']=$this->terminalCommands($service);
            foreach ($service['_commands'] as &$command) $command['batch']=$batchId;
            unset($command);
            $service['_commands']=MtData::safePreparedCommands($service,$service['_commands'],'delete');
            return $service;
        }
        $api = new MtData();
        $plan = $service['_plan'] ?? $this->make_plan($service);
        // A dual-stack build either acquires every requested family or leaves
        // the previous reservations intact. No cleanup/write follows a failed build.
        $data = $api->get_data($service, $plan);
        unset($service['_pool_allocations']);
        $allocations=$api->poolAllocations();
        if ($allocations) $service['_pool_allocations']=$allocations;
        if (!$queuesOnly && !isset($data['accounts'])) {
            if (!$delete) { throw new RuntimeException('No account could be prepared; verify its credentials and IP pool'); }
            $account = $this->deleteIdentity($service);
            if ($account === null) { throw new RuntimeException('No account identity is available for deletion'); }
            $data['accounts'] = $account;
        }
        $keys = $queuesOnly ? ['queues'] : ['pool', 'parents', 'profiles', 'queues', 'accounts', 'dhcp6', 'disconn'];
        if ($delete) { $keys = array_merge(array_reverse(array_diff($keys, ['disconn'])), ['disconn']); }
        $service['_commands'] = [];
        foreach ($keys as $key) {
            if (!isset($data[$key])) { continue; }
            $command = $data[$key];
            $command['batch'] = $batchId;
            if ($delete || $operation === 'queues_remove') { $command['action'] = 'remove'; }
            $service['_commands'][] = $command;
        }
        $service['_commands'] = MtData::safePreparedCommands($service, $service['_commands'], $operation);
        if ($operation==='update') {
            $allocations=$this->retainPoolAllocationProofs($service,$service['_commands'],$allocations);
            if ($allocations) $service['_pool_allocations']=$allocations;
        }
        // Address reservations are durable before any RouterOS command is sent.
        $this->ip_flush();
        if ($delete) { $service['_delete_frozen'] = true; }
        return $service;
    }

    private function operationDevice(array $service): object
    {
        $device = isset($service['_device']) ? (object) $service['_device'] : $this->find_device($service['device'] ?? null);
        if (!$device || ($device->type ?? 'mikrotik') !== 'mikrotik') {
            throw new RuntimeException('The queued service has no valid RouterOS device');
        }
        $current = $this->find_device($service['device'] ?? null);
        if (!$current) {
            throw new RuntimeException('El router del trabajo ya no existe. Revisa su retirada antes de reintentar.');
        }
        if ($current && ($current->ip ?? null) === ($device->ip ?? null)
            && (int) ($current->port ?? 8728) === (int) ($device->port ?? 8728)) {
            $device->user = $current->user ?? $device->user ?? null;
            $device->password = $current->password ?? null;
            $device->ssl = $current->ssl ?? null;
            $device->allowSelfSigned = $current->allowSelfSigned ?? false;
        }
        return $device;
    }

    private function writeCommands(object $device, array $commands, ?callable $beforeWrite = null, array $cancellationGuards = [], ?array $ownershipService = null, ?callable $verificationObserver = null): void
    {
        // Different plans can still collide on the same account or DHCP identity.
        // Serialize lookup + ownership checks + mutation for this physical endpoint.
        myJobQueue()->withResourceLock(ApiSharedResources::endpointIdentity($device), function () use ($device, $commands, $beforeWrite, $cancellationGuards, $ownershipService, $verificationObserver): void {
            $api = $this->device_api($device->type ?? 'mikrotik');
            $write = fn() => $cancellationGuards ? $api->do_cancellation_cleanup($device, $commands, $cancellationGuards)
                : $api->do_batch($device, $commands, $beforeWrite);
            if ($this->retirementScope && method_exists($api,'withRetirementScope')) {
                $execute=$write;
                $guard=fn()=>myJobQueue()->lifecycleReviewer()->withDeadline(microtime(true)+8,
                    fn()=>myJobQueue()->lifecycleReviewer()->assertRetired($this->retirementServiceId,$this->retirementMarker));
                $write=fn()=>$api->withRetirementScope($execute,$guard);
            }
            if ($verificationObserver!==null && method_exists($api,'withVerificationScope')) {
                $execute=$write; $write=fn()=>$api->withVerificationScope($verificationObserver,$execute);
            }
            if ($ownershipService !== null && method_exists($api, 'withOwnershipResolver')) {
                $verifier = new ApiRouterOwnership($ownershipService, (array)$device);
                $proofs = [];
                $resolve = function (array $command, array $row) use ($verifier, $device, &$proofs): bool {
                    $key = hash('sha256', json_encode($command, JSON_THROW_ON_ERROR));
                    if (!isset($proofs[$key])) $proofs[$key] = $verifier->authorize($command, $row);
                    if (!$verifier->matches($proofs[$key], $command, $row, (array)$device))
                        throw new RuntimeException('router_ownership_record_changed');
                    return true;
                };
                $written = $api->withOwnershipResolver($resolve, $write);
            } else $written = $write();
            $failed = $api->failed() ?? [];
            if ($failed || $written !== count($commands)) {
                throw new RuntimeException($failed ? implode('; ', array_map('strval', $failed)) : 'Not every router command completed');
            }
        });
    }

    /** An address refresh cannot erase evidence of partial profile creation or a pending reconnect. */
    private function preservePreparedGuards(array $previous, array $next, array $service): array
    {
        $indices = [];
        foreach ($next as $index => $command) $indices[$this->resourceFootprint([$command])[0]][] = $index;
        $seen = [];
        foreach ($previous as $command) {
            $bootstrap = ($command['_bootstrap_suspension'] ?? false) === true;
            $active = in_array(trim($command['path'] ?? '', '/'), ['ppp/active', 'ip/hotspot/active'], true);
            if (!$bootstrap && !$active) continue;
            if ($active) {
                // Apply the same identity upgrade as safePreparedCommands to legacy snapshots.
                $command['_service_id'] = (int)$service['id'];
                $command['_client_id'] = (int)($service['clientId'] ?? 0);
            }
            $identity = $this->resourceFootprint([$command])[0];
            if (isset($seen[$identity]) || count($indices[$identity] ?? []) !== 1)
                throw new RuntimeException('site_address_prepared_guard_changed');
            $seen[$identity] = true; $index = $indices[$identity][0];
            if ($bootstrap) {
                if (($next[$index]['_bootstrap_suspension'] ?? false) !== true
                    || !is_array($command['_suspension_configuration'] ?? null)
                    || $command['_suspension_configuration'] !== ($next[$index]['_suspension_configuration'] ?? null)
                    || !is_bool($command['_bootstrap_required'] ?? null))
                    throw new RuntimeException('site_address_prepared_guard_changed');
                $next[$index]['_bootstrap_required'] = $command['_bootstrap_required'] || ($next[$index]['_bootstrap_required'] ?? false) === true;
                if (array_key_exists('local-address', $command) && $command['local-address'] !== null) {
                    if (trim($command['path'], '/') !== 'ppp/profile' || !is_string($command['local-address'])
                        || !filter_var($command['local-address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
                        throw new RuntimeException('site_address_prepared_guard_changed');
                    $next[$index]['local-address'] = $command['local-address'];
                }
            }
            if ($active) {
                if (array_key_exists('_reconnect_required', $command)) {
                    if (!is_bool($command['_reconnect_required'])) throw new RuntimeException('site_address_prepared_guard_changed');
                    $next[$index]['_reconnect_required'] = $command['_reconnect_required'] || ($next[$index]['_reconnect_required'] ?? false) === true;
                }
                if (array_key_exists('_profile_revision', $command)) {
                    if (!is_int($command['_profile_revision']) || $command['_profile_revision'] < 0)
                        throw new RuntimeException('site_address_prepared_guard_changed');
                    $next[$index]['_profile_revision'] = $command['_profile_revision'];
                }
            }
        }
        return $next;
    }

    private function resourceFootprint(array $commands): array
    {
        $result = [];
        foreach ($commands as $command) {
            $identity = ['path' => trim($command['path'] ?? '', '/')];
            foreach (['name','mac-address','duid','iaid','server','_owner','_service_id','_client_id','action'] as $field)
                $identity[$field] = $command[$field] ?? null;
            $result[] = json_encode($identity, JSON_THROW_ON_ERROR);
        }
        sort($result);
        return $result;
    }

    private function deleteIdentity(array $service): ?array
    {
        if (filter_var($service['mac'] ?? '', FILTER_VALIDATE_MAC)) {
            return ['path' => '/ip/dhcp-server/lease', 'mac-address' => strtoupper($service['mac'])];
        }
        if (isset($service['username']) && (string) $service['username'] !== '') {
            return ['path' => empty($service['hotspot']) ? '/ppp/secret' : '/ip/hotspot/user', 'name' => $service['username']];
        }
        return null;
    }

    /** Deletion addresses resources by identity, never by allocating or validating an obsolete IP. */
    private function terminalCommands(array $snapshot): array
    {
        if (isset($snapshot['_commands']) && is_array($snapshot['_commands'])) return $snapshot['_commands'];
        $principal=$this->deleteIdentity($snapshot);
        if ($principal===null) throw new RuntimeException('lifecycle_router_identity_unknown');
        $sid=(int)$snapshot['id']; $cid=(int)($snapshot['clientId']??0);
        if ($sid<1 || $cid<1) throw new RuntimeException('lifecycle_router_identity_unknown');
        $commands=[]; $principal['action']='remove'; $commands[]=$principal;
        if (isset($principal['name'])) $commands[]=['path'=>empty($snapshot['hotspot'])?'/ppp/active':'/ip/hotspot/active','name'=>$principal['name'],'action'=>'remove','_reconnect_required'=>true];
        if (!empty($snapshot['duid']) && isset($snapshot['iaid'])) $commands[]=['path'=>'/ipv6/dhcp-server/binding','duid'=>$snapshot['duid'],'iaid'=>$snapshot['iaid'],'action'=>'remove'];
        // This service-specific cap never shares a selector with another service.
        $commands[]=['path'=>'/queue/simple','name'=>'uisp-service-'.$sid.'-suspension','_owner'=>'uisp-ros-plugin:suspension:'.$sid,'action'=>'remove'];
        if (filter_var($snapshot['mac']??'',FILTER_VALIDATE_MAC)) {
            $name=$snapshot['company']??null;
            if (!$name && !empty($snapshot['firstName']) && !empty($snapshot['lastName'])) $name=$snapshot['firstName'].' '.$snapshot['lastName'];
            $commands[]=['path'=>'/queue/simple','name'=>($name?:'Client-'.$cid).' - '.$sid,'action'=>'remove','_service_id'=>$sid,'_client_id'=>$cid];
        }
        foreach($commands as &$command) { $command['batch']=$snapshot['batch']??'service-'.$sid; }
        unset($command);
        return MtData::safePreparedCommands($snapshot,$commands,'delete');
    }

    /** Older confirmed rows can record only a PPP/Hotspot identity, never guessed auxiliary resources. */
    private function cancellationCommands(array $snapshot): array
    {
        if (isset($snapshot['_commands']) && is_array($snapshot['_commands'])) return $snapshot['_commands'];
        // Without frozen commands, a plan, DHCP service, suspended cap or
        // contention parent would leave resources whose exact identity is unknown.
        if (array_key_exists('_commands',$snapshot) || !empty($snapshot['_plan']) || !empty($snapshot['planId'])
            || !empty($snapshot['mac']) || !empty($snapshot['duid']) || !empty($snapshot['iaid'])
            || !empty($snapshot['_cleanup']) || in_array((int)($snapshot['status'] ?? 1),[2,3,4,5,8],true)
            || empty($snapshot['_config']['disable_contention'])
            || !is_array($snapshot['_device'] ?? null) || empty($snapshot['_device']['ip'])
            || (int)($snapshot['clientId'] ?? 0)<1)
            throw new RuntimeException('cancelled_resource_snapshot_incomplete');
        $account=$this->deleteIdentity($snapshot);
        if ($account===null || !in_array(trim($account['path'],'/'),['ppp/secret','ip/hotspot/user'],true))
            throw new RuntimeException('cancelled_resource_snapshot_incomplete');
        $account['action']='remove';
        return [$account,['path'=>trim($account['path'],'/')==='ppp/secret' ? '/ppp/active' : '/ip/hotspot/active',
            'name'=>$account['name'],'action'=>'remove','_reconnect_required'=>true]];
    }

    private function set_sites($ids,$delete = false)
    {
        // Topology writes remain disabled until site/device ownership is verified in a UISP laboratory.
        // Reading the NMS cache remains available; RouterOS provisioning must not mutate unrelated NMS nodes.
        return;
    }

    private function find_unms_key()
    {
        if(!is_file('data/config.json')){ return null; }
        $read = json_decode(file_get_contents('data/config.json'));
        if(!is_object($read)){ return null; }
        return $read->unmsToken ?? null ;
    }

    private function run_batch($deviceData,$delete = false)
    {
        $timer = new ApiTimer("device batch write");
        $sent = 0 ;
        $writes = 0 ;
        $this->batch_failed = [];
        $this->batch_success = [];
        foreach (array_keys($deviceData) as $did)
        {
            $device  = $this->find_device($did);
            if(!is_object($device)){
                $this->batch_failed['device-' . $did] = 'Invalid device';
                continue;
            }
            $type = $device->type ?? 'mikrotik';
            $api = $this->device_api($type);
            $keys = ['pool','parents','profiles','queues','accounts','dhcp6','disconn'];
            if($delete) {
                $keys = array_reverse(array_diff($keys,['disconn']));
                $keys[] = 'disconn'; //disconnect at the end
            }
            $batch = [];
            foreach ($keys as $key){
                $item = $deviceData[$did][$key] ?? [];
                foreach ($item as $command) {
                    $command['batch'] ??= 'router-' . $did . '-command-' . count($batch);
                    $batch[] = $command;
                }
            }
            if (!$batch) { continue; }
            $sent += count($batch);
            try {
                $writes += myJobQueue()->withResourceLock(ApiSharedResources::endpointIdentity($device),
                    fn(): int => $api->do_batch($device, $batch));
            } catch (Throwable $error) {
                $this->batch_failed['device-' . $did] = $error->getMessage();
                continue;
            }
            $this->batch_success = array_replace($this->batch_success, $api->success() ?? []);
            $this->batch_failed = array_replace($this->batch_failed, $api->failed() ?? []);

        }
        $timer->stop();
        MyLog()->Append(sprintf('batch sent: %s written: %s',$sent,$writes));
    }

    private function save_batch($deviceServices)
    {
        if(empty($this->batch_success)){ return ;}
        $fill = array_fill_keys(['id','device','clientId',
            'planId','status'],'%%$#');
        $save = [];
        $sites = [];
        $now = date('c');
        foreach ($deviceServices as $services){
            foreach ($services as $service){
                $id = $service['batch'] ?? null ;
                $success = $this->batch_success[$id] ?? null ;
                if($success && !isset($this->batch_failed[$id])){
                    $sites[] = $service['id'] ;
                    $values = array_intersect_key($service,$fill);
                    $values['last'] = $now ;
                    $save[] = $values;
                }
            }
        }
        $this->ip_flush();
        if (!$save || !$this->db()->insert($save,'services',INSERT_REPLACE)) {
            throw new RuntimeException('Could not persist the applied service state');
        }
        $this->set_sites($sites);
    }

    private function unsave_batch($deviceServices): void
    {
        if(empty($this->batch_success)) return ;
        $ids = [];
        foreach ($deviceServices as $services){
            foreach ($services as $service){
                $id = $service['batch'] ?? null ;
                $success = $this->batch_success[$id] ?? null ;
                if($success && !isset($this->batch_failed[$id])){
                    $ids[] = $service['id'];
                }
            }
        }
        if (!$ids) { return; }
        $this->ip_flush();
        $this->db()->transaction(function ($db) use ($ids) {
            foreach(['services','network'] as $table) {
                $sql = sprintf("delete from %s where id in (%s)",$table, implode(',', array_map('intval', $ids)));
                $db->exec($sql);
            }
        });
        $this->set_sites($ids,true);
    }

    private function device_api($type): ?object
    {
        if(!isset($this->_apis[$type])){
            if($type != 'mikrotik'){ throw new RuntimeException('Unsupported device type'); }
            $this->_apis['mikrotik'] = new MT();
        }
        return $this->_apis[$type] ?? null ;
    }

    private function datapi($did): MtData
    {
        $device = $this->find_device($did);
        if(!is_object($device)){ throw new RuntimeException('Invalid device'); }
        $type = $device->type ?? null ;
        if($type != 'mikrotik'){ throw new RuntimeException('Unsupported device type'); }
        return new MtData();
    }

    private function ensurePlanMetadata(int $planId): void
    {
        if ($planId < 1) return;
        if (isset($this->planMetadataErrors[$planId])) throw new InvalidArgumentException($this->planMetadataErrors[$planId]);
        try { if (ApiPlanCatalog::ensure($planId)) $this->_plans = null; }
        catch (InvalidArgumentException $error) {
            $this->planMetadataErrors[$planId] = $error->getMessage();
            throw $error;
        }
    }

    /** The same effective rates used by execution, without schema refresh or writes. */
    public static function previewPlans(ApiSqlite $database): array
    {
    // Normalize legacy optional blanks in a read-only subquery BEFORE SQLite can
    // coerce them to numeric zero and accidentally generate the full guaranteed rate.
    $columns = ['id','name','last','created','uploadSpeed','downloadSpeed'];
    foreach (['ratio','priorityUpload','priorityDownload','uploadOverride','downloadOverride',
        'limitUpload','limitDownload','burstUpload','burstDownload','threshUpload','threshDownload','timeUpload','timeDownload'] as $field) {
        $columns[] = "CASE WHEN typeof($field)='text' AND trim($field,char(9)||char(10)||char(11)||char(12)||char(13)||' ')='' THEN NULL ELSE $field END AS $field";
    }
    $normalized = 'SELECT ' . implode(',', $columns) . ' FROM plans';
    $st =[
        'SELECT id,name,ratio,uls AS uploadSpeed,dls AS downloadSpeed,IFNULL(priorityUpload,8) as priorityUpload,',
        'IFNULL(priorityDownload,8) as priorityDownload,IFNULL(timeUpload,1) as timeUpload,',
        'IFNULL(timeDownload,1) as timeDownload,last,created,',
        'plans.uploadSpeed AS _sourceUploadSpeed,plans.downloadSpeed AS _sourceDownloadSpeed,',
        'plans.uploadOverride AS _sourceUploadOverride,plans.downloadOverride AS _sourceDownloadOverride,',
        'plans.limitUpload AS _sourceLimitUpload,plans.limitDownload AS _sourceLimitDownload,',
        'plans.burstUpload AS _sourceBurstUpload,plans.burstDownload AS _sourceBurstDownload,',
        'plans.threshUpload AS _sourceThreshUpload,plans.threshDownload AS _sourceThreshDownload,',
        'CASE WHEN IFNULL(limitUpload,0) = 0 THEN 0 ELSE (uls * 1000000) - ((limitUpload * uls * 1000000) / 100) END as limitUpload,',
        'CASE WHEN IFNULL(limitDownload,0) = 0 THEN 0 ELSE (dls * 1000000) - ((limitDownload * dls * 1000000) / 100) end as limitDownload, ',
        'CASE WHEN IFNULL(threshUpload,0) = 0 THEN 0 ELSE (uls * 1000000) - ((threshUpload * uls * 1000000) / 100) END as threshUpload,',
        'CASE WHEN IFNULL(threshDownload,0) = 0 THEN 0 ELSE (dls * 1000000) - ((threshDownload * dls * 1000000) / 100) END as threshDownload,',
        'CASE WHEN IFNULL(burstUpload,0) = 0 THEN 0 ELSE (uls * 1000000) + ((burstUpload * uls * 1000000) / 100) END as burstUpload,',
        'CASE WHEN IFNULL(burstDownload,0) = 0 THEN 0 ELSE (dls * 1000000) + ((burstDownload * dls* 1000000) / 100) END as burstDownload',
        'FROM (SELECT *,IFNULL(uploadOverride,uploadSpeed) AS uls,',
        'IFNULL(downloadOverride,downloadSpeed) AS dls FROM (' . $normalized . ')) AS plans'

    ];
        $result = [];
        foreach ($database->selectCustom(implode(' ', $st)) ?? [] as $plan) $result[$plan['id']] = $plan;
        return $result;
    }

    private function find_plans(bool $fresh = false): array
    {
        if ($fresh || $this->_plans === null) $this->_plans = self::previewPlans($this->db());
        return $this->_plans;
    }

    private function find_services(array $ids, $action): array
    {
        if (!$ids) { return []; }
        $data = $ids ;
        $first = array_values($ids)[0] ;
        if(!is_array($first)){
            $fields = 'services.*,clients.company,clients.firstName,clients.lastName,'.
                'network.address,network.address6';
            $sql = sprintf("SELECT %s FROM services LEFT JOIN clients ON services.clientId=clients.id ".
                "LEFT JOIN network ON services.id=network.id ".
                "WHERE services.id IN (%s) ",$fields,implode(',', array_map('intval', $ids)));
            $data = $this->dbCache()->selectCustom($sql) ?? [];
        }
        $deviceMap = [];
        $count = 0;
        $date = date('c');
        foreach ($data as $item){
            $item['action'] ??= $action ;
            $item['batch'] = $date . '-' . ++$count;
            $id = $item['device'] ?? 'nodev';
            $deviceMap[$id][] = $item ;
        }
        return $deviceMap ;
    }

    private function find_device($did): ?object
    {
        $id = filter_var($did, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) { return null; }
        $dev = $this->find_devices()[$id] ?? null ;
        return $dev ? (object) $dev : null ;
    }

    private function find_devices(): array
    {
        if(empty($this->_devices)){
            $this->_devices = [];
            $devs = $this->db()->selectAllFromTable('devices') ?? [];
            foreach ($devs as $dev){ $this->_devices[$dev['id']] = $dev; }
        }
        return $this->_devices ;
    }

    private function ip_flush(): void
    {
        myIPClass()->flush();
    }

    private function make_plan($service): array
    {
        $keys = ['limitUpload','limitDownload', 'burstUpload','burstDownload',
            'threshUpload','threshDownload'];
        $ul = $service['uploadSpeed'] ?? 1 ;
        $dl = $service['downloadSpeed'] ?? 1 ;
        $defaults = ['ratio' => 1, 'priorityUpload' => 8,'priorityDownload' => 8,
            'timeUpload' => 1,'timeDownload' => 1, 'uploadSpeed' => $ul,'downloadSpeed' => $dl];
        $fill = array_fill_keys($keys,0);
        $plan = array_replace($fill,$defaults);
        $plan['id'] = $service['planId'] ?? -(int) ($service['id'] ?? 1);
        $plan['name'] = '__uisp-plan-' . $plan['id'];
        return $plan ;
    }

    private function db(): ApiSqlite
    {
        return mySqlite();
    }

    private function dbCache(): ApiSqlite
    {
        return myCache();
    }

}
