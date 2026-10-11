<?php
include_once 'mt.php';
include_once 'mt_data.php';
include_once 'api_ip.php';
include_once 'api_sqlite.php';
include_once 'api_sites.php';
require_once __DIR__ . '/api_job_queue.php';
require_once __DIR__ . '/api_shared_resources.php';
require_once __DIR__ . '/api_plan_catalog.php';

class Batch
{
    private ?array $_plans = null;
    private array $planMetadataErrors = [];
    private ?array $_devices = null ;
    private array $_apis = [];
    private array $batch_failed = [];
    private array $batch_success = [];
    private bool $forceReconnect = false;
    private array $submissionOutcomes = ['requested' => 0, 'completed' => 0, 'failed' => 0, 'failures' => []];

    /** Safe per-service results for a bulk operation; credentials never enter the response. */
    public function outcomes(): array { return $this->submissionOutcomes; }

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
        return $this->enqueueEntries($ids, 'update');
    }

    public function migrate(array $previous, array $replacement): bool
    {
        $entries = [
            ['snapshot' => $this->snapshot($previous, 'delete'), 'operation' => 'delete'],
            ['snapshot' => $this->snapshot($replacement, 'update'), 'operation' => 'update']
        ];
        $queue = myJobQueue();
        $keys = $queue->enqueueBatch($entries);
        $queue->runSelected($keys);
        return $queue->completed($keys);
    }

    private function submit(array $ids, string $operation): bool
    {
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
        $keys = $this->enqueueEntries($ids, $operation);
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

    private function enqueueEntries(array $ids, string $operation): array
    {
        $entries = [];
        foreach ($this->find_services($ids, $operation) as $services) {
            foreach ($services as $service) {
                if (in_array($operation, ['update','queues_set'], true)
                    && empty($service['_clear_terminal']) && myJobQueue()->isServiceBlocked((int)$service['id'])) continue;
                $snapshot = $this->snapshot($service, $operation);
                if ($operation === 'update') {
                    $applied = myJobQueue()->appliedSnapshot((int) $service['id']);
                    if ($applied && !$this->sameAccountIdentity($applied, $snapshot)) {
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
        if (in_array($operation, ['update','delete'], true)) {
            $debts = $queue->cancellationCleanups($job);
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
                $cleanup['_commands'] = $this->cancellationCommands($cleanup);
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
                    function () use ($cleanupDevice, $cleanup, $guards): void {
                        $this->writeCommands($cleanupDevice, $cleanup['_commands'], null, $guards);
                    });
                $this->batch_failed = [];
                $this->batch_success = [$batchId => $batchId];
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
                function () use ($cleanupDevice, $cleanup): void { $this->writeCommands($cleanupDevice, $cleanup['_commands']); });
            $cleanup['batch'] = $batchId;
            $this->batch_failed = [];
            $this->batch_success = [$batchId => $batchId];
            if ($queue->canClearApplied($cleanup)) { $this->unsave_batch([$cleanup['device'] => [$cleanup]]); }
            $queue->confirmCleanup($job, $cleanup);
        }
        if (!isset($service['_commands'])) {
            $service['_device'] = (array) $device;
            $api = new MtData();
            $plan = $service['_plan'] ?? $this->make_plan($service);
            $data = $api->get_data($service, $plan);
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
            // Address reservations are durable before any RouterOS command is sent.
            $this->ip_flush();
            if ($delete) { $service['_delete_frozen'] = true; }
            $service = $queue->freezePrepared($job, $service);
        }
        $commands = MtData::safePreparedCommands($service, $service['_commands'], $operation);
        if ($commands !== $service['_commands']) {
            $service['_commands'] = $commands;
            $service = $queue->freezePrepared($job, $service);
        }
        $queue->withResourceLock(ApiSharedResources::lockIdentity($device, $service), function () use ($device, $operation, $queuesOnly, $queue, $job, &$service, &$commands): void {
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
                } : null);
            elseif (!$queuesOnly) throw new RuntimeException('No router commands were prepared for this service');
        });
        $this->batch_failed = [];
        $this->batch_success = [$batchId => $batchId];
        if (!$queuesOnly) {
            $services = [$service['device'] ?? 'snapshot' => [$service]];
            if ($delete) {
                if ($queue->canClearApplied($service)) { $this->unsave_batch($services); }
            }
            else { $this->save_batch($services); }
        }
        return true;
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

    private function writeCommands(object $device, array $commands, ?callable $beforeWrite = null, array $cancellationGuards = []): void
    {
        // Different plans can still collide on the same account or DHCP identity.
        // Serialize lookup + ownership checks + mutation for this physical endpoint.
        myJobQueue()->withResourceLock(ApiSharedResources::endpointIdentity($device), function () use ($device, $commands, $beforeWrite, $cancellationGuards): void {
            $api = $this->device_api($device->type ?? 'mikrotik');
            $written = $cancellationGuards ? $api->do_cancellation_cleanup($device, $commands, $cancellationGuards)
                : $api->do_batch($device, $commands, $beforeWrite);
            $failed = $api->failed() ?? [];
            if ($failed || $written !== count($commands)) {
                throw new RuntimeException($failed ? implode('; ', array_map('strval', $failed)) : 'Not every router command completed');
            }
        });
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

    private function find_plans(bool $fresh = false): array
    {
        if ($fresh) $this->_plans = null;
        if($this->_plans === null){
            $this->_plans = [];
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
            $plans = $this->db()->selectCustom(implode(' ',$st)) ?? [];
            foreach ($plans as $plan){
                $this->_plans[$plan['id']] = $plan; }
        }
        return $this->_plans ;
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
