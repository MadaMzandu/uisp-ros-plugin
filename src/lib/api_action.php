<?php
include_once 'batch.php';

class ApiAction
{
    private ?object $request;
    private ?object $status ;
    private ?array $normalizedEntity = null;
    private bool $hydratedBillingState = false;
    private array $resultData = [];
    private ?string $diagnosticEventId = null;
    private array $diagnosticServiceIds = [];

    public function exec($request = null)
    {
        if($request){ $this->request = $request; }
        $this->status = (object) ['error' => false, 'status' => 'ok'];
        $this->normalizedEntity = null;
        $this->hydratedBillingState = false;
        $this->diagnosticServiceIds = [];
        $eventId = $this->req()->uuid ?? null;
        $eventId = is_string($eventId) && $eventId !== '' ? $eventId : null;
        $this->diagnosticEventId = $eventId;
        $this->initializeResult();
        try {
            $this->updateRoutingResult();
            if ($this->type() === 'service') {
                // Only the request ID is inspected before the guard: cached identity and previous state are read inside it.
                $raw = $this->req()->extraData->entity ?? new stdClass();
                if (!is_object($raw)) { throw new InvalidArgumentException('Invalid service entity'); }
                $id = filter_var($raw->id ?? $this->req()->entityId ?? null, FILTER_VALIDATE_INT);
                if (!$id || $id < 1) { throw new InvalidArgumentException('Missing or invalid service ID'); }
                if (isset($this->req()->entityId) && filter_var($this->req()->entityId, FILTER_VALIDATE_INT) !== $id) {
                    throw new InvalidArgumentException('Webhook entity IDs differ');
                }
                $done = myJobQueue()->withServiceLock($id, fn(): bool => $this->processWebhook($eventId, $id));
            } else {
                $raw = $this->req()->extraData->entity ?? new stdClass();
                $clientId = filter_var($raw->id ?? $this->req()->entityId ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($clientId === false) throw new InvalidArgumentException('Missing or invalid client ID');
                $done = myJobQueue()->withClientLock($clientId, fn(): bool => $this->processWebhook($eventId));
            }
            $action = $this->action();
            $name = $this->name();
            $message = "{$action}_success $name" ;
            if(!$done){
                $message = "{$action}_failed $name";
                $this->status->error = true ;
                $this->status->status = 'failed';
            }
            $this->status->message = $message;
            $this->updateResultEntity();
            $this->updateJobResult();
            if (!$done) $this->resultData['provisioning'] = $this->resultData['jobCounts']['pending'] > 0 ? 'pending' : 'failed';
            MyLog()->Append(['event' => 'webhook_result'] + $this->resultData, LOG_INFO);
        } catch (Throwable $error) {
            $this->updateResultEntity();
            // Diagnostic failures must never obscure the original processing failure.
            try { $this->updateJobResult(); } catch (Throwable $ignored) {}
            $this->resultData['provisioning'] = 'failed';
            $this->status = (object) ['error' => true, 'status' => 'failed', 'message' => $this->safeErrorCode($error)];
            MyLog()->Append(['event' => 'webhook_processing_failed'] + $this->resultData + [
                'exceptionType' => get_class($error), 'errorCode' => $this->safeErrorCode($error),
            ], LOG_ERR);
            throw $error;
        }
    }

    private function processWebhook(?string $eventId, ?int $serviceId = null): bool
    {
        $existing = $eventId !== null ? myJobQueue()->eventKeys($eventId) : [];
        if ($existing) {
            $this->normalizedEntity = myJobQueue()->eventSnapshot($eventId);
            if (myJobQueue()->completed($existing)) return $this->batchOutcome(true, 'duplicate_completed');
            $states = myJobQueue()->selectionStatus($existing);
            $cancelled = false; $terminal = count($states) === count($existing);
            foreach ($states as $state) {
                $value = $state['state'] ?? null;
                if (!in_array($value, ['succeeded','cleaned','cancelled','superseded'], true)) $terminal = false;
                if (in_array($value, ['cleaned','cancelled','superseded'], true)) $cancelled = true;
            }
            if ($terminal && $cancelled) {
                // A delivery receipt acknowledges cancellation, not provisioning.
                // Re-delivery cannot recreate this intent or consume its resource debt.
                $this->resultData['provisioning'] = 'cancelled';
                return true;
            }
            myJobQueue()->runSelected($existing);
            return $this->batchOutcome(myJobQueue()->completed($existing), 'duplicate_completed');
        }
        $data = myTrimmer()->trim($this->type(), $this->request);
        if (!$data || !isset($data['entity'])) { throw new InvalidArgumentException('Unsupported webhook entity'); }
        if ($serviceId !== null) {
            if (isset($data['entity']['clientId'])) {
                $clientId = filter_var($data['entity']['clientId'], FILTER_VALIDATE_INT);
                if (!$clientId || $clientId < 1) { throw new InvalidArgumentException('Invalid client ID'); }
                $data['entity']['clientId'] = $clientId;
            }
            if (isset($data['previous']['id']) && filter_var($data['previous']['id'], FILTER_VALIDATE_INT) !== $serviceId) {
                throw new InvalidArgumentException('Previous and current service IDs differ');
            }
            $cached = $this->cachedService($serviceId);
            $raw = $this->entity();
            $attributeNetwork = property_exists($raw, 'attributes') ? ($data['entity']['network'] ?? []) : [];
            if (!property_exists($raw, 'attributes')) {
                foreach (['device','username','password','mac','duid','iaid','hotspot','callerId','network'] as $field) { unset($data['entity'][$field]); }
            }
            $known = $this->mergeServiceState($data['previous'] ?? [], $cached);
            $data['entity'] = $this->mergeServiceState($known, $data['entity'], ['id' => $serviceId]);
            $deleting = in_array($this->action(), ['delete','end'], true);
            $states = ['suspend' => 3, 'unsuspend' => 1, 'suspend_cancel' => 1, 'activate' => 1, 'end' => 2];
            $missingStatus = !array_key_exists('status', $data['entity']) && !isset($states[$this->action()]);
            $missingPlan = !$deleting && !array_key_exists('planId', $data['entity']);
            $missingIdentity = !$known && !property_exists($raw, 'attributes');
            if (!isset($data['entity']['clientId']) || $missingPlan || $missingStatus || $missingIdentity) {
                $hydrated = $this->completeService($serviceId);
                $this->hydratedBillingState = $this->validBillingState($hydrated['status'] ?? null);
                $data['entity'] = $this->mergeServiceState($hydrated, $data['entity']);
            }
            $clientId = filter_var($data['entity']['clientId'] ?? null, FILTER_VALIDATE_INT);
            if (!$clientId || $clientId < 1) { throw new InvalidArgumentException('Invalid client ID'); }
            $data['entity']['clientId'] = $clientId;
            if (isset($data['previous'])) { $data['previous'] = $this->mergeServiceState($known, $data['previous']); }
            if ($this->action() === 'edit' && !isset($data['previous']) && $cached) { $data['previous'] = $cached; }
            if ($attributeNetwork && !in_array($this->action(), ['delete','end'], true)) {
                $beforeNetwork = $data['previous']['network'] ?? $known['network'] ?? [];
                $appliedNetwork = myJobQueue()->appliedSnapshot($serviceId)['network'] ?? [];
                foreach (['address','address6'] as $field) {
                    if (!array_key_exists($field,$attributeNetwork) || $attributeNetwork[$field] !== null) continue;
                    $previousAddress = array_key_exists($field,$beforeNetwork) ? $beforeNetwork[$field] : ($appliedNetwork[$field] ?? null);
                    if (!is_string($previousAddress) || trim($previousAddress) === '') continue;
                    // The clear is a one-time allocation request, separate from
                    // the nullable desired cache. It survives a deferred worker
                    // without turning later partial edits into repeated resets.
                    $data['entity']['_network_reset'][$field] = [
                        'generation'=>bin2hex(random_bytes(16)), 'previous'=>$previousAddress,
                    ];
                }
            }
            if (isset($states[$this->action()])) { $data['entity']['status'] = $states[$this->action()]; }
            if ($this->action() === 'delete') {
                $data['entity'] = array_replace($cached, $data['previous'] ?? [], $data['entity']);
                foreach (['device','username','mac','duid','iaid','hotspot','network'] as $field) {
                    if (($data['entity'][$field] ?? null) === null && isset($cached[$field])) { $data['entity'][$field] = $cached[$field]; }
                }
            }
            if (!in_array($this->action(), ['delete','end'], true)
                && in_array((int)($data['entity']['status'] ?? -1), [1,3,4,7], true)
                && $this->managedIdentity($data['entity'])) {
                // The policy returns immediately for ordinary addresses. An IP
                // proven to belong to another configured Site is cleared only
                // after fresh UISP identity checks, before saving desired state
                // or preparing any router cleanup/replacement operation.
                require_once __DIR__ . '/api_site_address_policy.php';
                $data['entity'] = ApiSiteAddressPolicy::reconcile($data['entity'], null, null, 'data/data.db',
                    fn(array $replacement) => $this->batch()->validateSiteAddressReplacement($replacement));
            }
            if ($eventId !== null) {
                $data['entity']['_event_id'] = $eventId;
                if (isset($data['previous'])) { $data['previous']['_event_id'] = $eventId; }
            }
        }
        $this->normalizedEntity = $data['entity'];
        return $this->route($data);
    }

    private function completeService(int $id): array
    {
        // A thin event with no prior state must not invent a plan or provision from empty attributes.
        $service = $this->ucrm()->get('clients/services/' . $id);
        if (!is_object($service) || filter_var($service->id ?? null, FILTER_VALIDATE_INT) !== $id
            || !property_exists($service, 'attributes') || !property_exists($service, 'servicePlanId')) {
            throw new RuntimeException('incomplete_service_event');
        }
        return myTrimmer()->trim('service', $service)['entity'];
    }

    /** Omitted families are partial state; explicit NULLs replace that family. */
    private function mergeServiceState(array ...$parts): array
    {
        $merged = array_replace(...$parts);
        $network = [];
        $hasNetwork = false;
        foreach ($parts as $part) {
            if (!isset($part['network']) || !is_array($part['network'])) continue;
            $hasNetwork = true;
            $network = array_replace($network, $part['network']);
        }
        if ($hasNetwork) $merged['network'] = $network;
        return $merged;
    }

    private function route($data): bool
    { // only working with edits/inserts to avoid duplicates
        $action = $this->action() ;
        if($this->type() == 'client')
        {
            if ($action === 'delete') { throw new InvalidArgumentException('Delete the client services before deleting its provisioning data'); }
            return $this->set_client($data['entity']);
        }
        elseif (!$this->hasSite($data['entity'])) {
            // Credentials/IPs may document a service supplied by another provider. A Site is the opt-in to provisioning.
            $cleanup = $this->managedCleanups($data);
            if ($cleanup) { return $this->delete($cleanup[0], $cleanup); }
            $this->resultData['provisioning'] = 'ignored_unmanaged';
            if ($action === 'delete') { return $this->unsave($data['entity']); }
            return $this->save($data['entity']);
        }
        elseif ($action === 'delete') { return $this->delete($data['entity']); }
        elseif (in_array($action, ['suspend','unsuspend','suspend_cancel','activate'], true)) {
            return $this->set($data['entity'], $action);
        }
        elseif ($action === 'end' || in_array((int)($data['entity']['status'] ?? -1), [2,5,8], true)) {
            return $this->delete($data['previous'] ?? $data['entity']);
        }
        elseif ($action === 'edit' && myJobQueue()->isServiceBlocked((int) $data['entity']['id'])
            && !$this->hasExplicitBillingState()) {
            // A cache refresh or a metadata-only event cannot revoke a pending terminal billing intent.
            $this->resultData['provisioning'] = 'deferred';
            return $this->save($data['entity']);
        }
        else if(in_array($data['entity']['status'] ?? 0,[0,6]))
        { //deferred
            MyLog()->Append(sprintf("Deferred insert client: %s service: %s",
                $data['entity']['clientId'] ?? 0,$data['entity']['id'] ?? 0),6);
            $this->resultData['provisioning'] = 'deferred';
            return true ;
        }
        elseif ($check = $this->is_unset($data))
        {//unset attributes
            if($check < 0)
            { //had prior settings
                return $this->delete($data['previous']);
            }
            return $this->requestCredentials($data['entity']);
        }
        elseif($action == 'insert')
        {
            return $this->set($data['entity'],$action);
        }
        elseif($action == 'edit')
        {
            return $this->edit($data);
        }
        $this->resultData['provisioning'] = 'already_applied';
        return true ;
    }

    private function edit($data): bool
    {
        $entity = $data['entity'] ;
        $previous = $data['previous'] ?? [];
        $diff = $this->changes($entity,$previous);
        $changes = array_keys($diff);
        $upgrade = array_intersect(['clientId','device','mac','username','hotspot','duid','iaid'],$changes);
        if ($changes === ['status'] && $this->completedBillingEdit($entity)) {
            // UISP can deliver suspend/unsuspend and its matching edit separately.
            // The first event already confirmed this exact state; preserve its cache
            // without issuing another account write or disconnect.
            $this->resultData['provisioning'] = 'already_applied';
            return $this->save($entity);
        }
        if(in_array('status',$changes) && in_array($this->state(),[0,6]))
        { //deferred changes
            MyLog()->Append(sprintf("Deferred edit for service: %s client: %s",
                $this->entity()->id,$this->entity()->clientId),6);
            $this->resultData['provisioning'] = 'deferred';
            return true ;
        }
        elseif($upgrade)
        { //changes requiring previous delete
            if (!$previous || !$this->managedIdentity($previous)) { return $this->set($entity, 'insert'); }
            MyLog()->Append(["Update edit requires delete changes: ",$changes,$this->entity()->id]);
            $dev = $entity['device'] ?? null ;
            $user = $entity['username'] ?? $entity['mac'] ?? null ;
            if($dev && $user !== null && $user !== '') {
                $client = $this->client();
                $oldClient = $this->client($previous['clientId'] ?? null);
                if (!$this->save($entity)) { return false; }
                $replacement = array_replace($entity, $client);
                $replacement['_clear_terminal'] = true;
                return $this->batchOutcome($this->batch()->migrate(array_replace($previous, $oldClient), $replacement));
            }
            return $this->delete($previous);
        }
        elseif(in_array('status',$changes) && in_array($this->state(),[2,5]))
        {// obsolete status requires delete
            return $this->delete($data['previous']);
        }
        else
        {//normal edit
            if($changes || $this->needsAppliedReconciliation($entity))
            {//ignore network flapping
                return $this->set($data['entity'],'edit');
            }
        }
        $this->resultData['provisioning'] = 'already_applied';
        return  true ;
    }

    private function validBillingState($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 8]]) !== false;
    }

    private function completedBillingEdit(array $entity): bool
    {
        if (!in_array((int) ($entity['status'] ?? -1), [1,3], true)) return false;
        $sid = (int) $entity['id'];
        $queue = myJobQueue();
        if ($queue->isServiceBlocked($sid) || $queue->hasUnreconciled($sid)) return false;
        foreach ($queue->list() as $job) if ((int) ($job['id'] ?? 0) === $sid) return false;
        $applied = $queue->appliedSnapshot($sid);
        foreach (['id','clientId','device','planId','status'] as $field) if (!array_key_exists($field,$applied)) return false;
        foreach (['_commands','_device','_plan','_config'] as $field) {
            if (!isset($applied[$field]) || !is_array($applied[$field]) || !$applied[$field]) return false;
        }
        foreach (['username','password','mac','hotspot','duid','iaid','callerId','uploadSpeed','downloadSpeed'] as $field)
            if (array_key_exists($field,$entity) && !array_key_exists($field,$applied)) return false;
        if ($this->needsAppliedReconciliation($entity)) return false;
        $principal = filter_var($entity['mac'] ?? '', FILTER_VALIDATE_MAC) ? 'ip/dhcp-server/lease'
            : (empty($entity['hotspot']) ? 'ppp/secret' : 'ip/hotspot/user');
        $accounts = array_filter($applied['_commands'], static function (array $command) use ($principal,$sid,$entity): bool {
            return trim($command['path'] ?? '', '/') === $principal && ($command['action'] ?? '') !== 'remove'
                && (int) ($command['_service_id'] ?? 0) === $sid
                && (int) ($command['_client_id'] ?? 0) === (int) $entity['clientId'];
        });
        if (count($accounts) !== 1) return false;
        if (!$this->appliedProfileRevisionCurrent($applied,reset($accounts),$queue)) return false;
        // Do not suppress an edit after a router/configuration/plan change which
        // has not reached this service, even when the billing state already matches.
        $device = mySqlite()->selectDeviceById((int) $entity['device']);
        $deviceData = (array) $device;
        // operationDevice materializes the legacy NULL certificate option as false.
        $deviceData['allowSelfSigned'] ??= false;
        if (!$device || $deviceData !== $applied['_device']) return false;
        $configuration = (array) $this->conf();
        if (count($configuration) !== count($applied['_config'])) return false;
        foreach ($configuration as $key=>$value) {
            if (!array_key_exists($key,$applied['_config'])) return false;
            $actual = $applied['_config'][$key];
            // JSON snapshots turn integral floats into ints; configuration strings
            // (including numeric-looking identifiers) must remain literal.
            if ((is_int($value) || is_float($value)) && (is_int($actual) || is_float($actual))) {
                if (!$this->sameOptionalNumber($value,$actual)) return false;
            } elseif ($value !== $actual) return false;
        }
        $current = mySqlite()->singleQuery('SELECT * FROM plans WHERE id=?',true,[(int) $entity['planId']]);
        if (!$current || Data::planNumericIssues($current)) return false;
        $current = Data::normalizePlanNumbers($current);
        if (($current['name'] ?? null) !== ($applied['_plan']['name'] ?? null)) return false;
        foreach (['uploadSpeed','downloadSpeed','uploadOverride','downloadOverride','limitUpload','limitDownload',
            'burstUpload','burstDownload','threshUpload','threshDownload'] as $field) {
            $source = '_source' . ucfirst($field);
            if (!array_key_exists($source,$applied['_plan'])
                || !$this->sameOptionalNumber($current[$field] ?? null,$applied['_plan'][$source])) return false;
        }
        foreach (['ratio'=>null,'priorityUpload'=>8,'priorityDownload'=>8,'timeUpload'=>1,'timeDownload'=>1] as $field=>$default) {
            if (!array_key_exists($field,$applied['_plan'])
                || !$this->sameOptionalNumber($current[$field] ?? $default,$applied['_plan'][$field])) return false;
        }
        return true;
    }

    private function appliedProfileRevisionCurrent(array $applied,array $account,ApiJobQueue $queue): bool
    {
        $path=trim($account['path'] ?? '', '/');
        if (!in_array($path,['ppp/secret','ip/hotspot/user'],true)) return true;
        $profilePath=$path==='ppp/secret' ? 'ppp/profile' : 'ip/hotspot/user/profile';
        $activePath=$path==='ppp/secret' ? 'ppp/active' : 'ip/hotspot/active';
        $profiles=array_values(array_filter($applied['_commands'],static function (array $command) use ($profilePath,$account): bool {
            return trim($command['path'] ?? '', '/')===$profilePath && ($command['action'] ?? '')!=='remove'
                && ($command['name'] ?? null)===($account['profile'] ?? null);
        }));
        if ((int)$applied['status']===3 && count($profiles)===1 && ($profiles[0]['_bootstrap_suspension'] ?? false)===true) {
            $expected=MtData::suspensionBootstrap($applied);
            if ($expected===null) return false;
            foreach ($expected as $field=>$value) {
                if (in_array($field,['batch','local-address','_bootstrap_required'],true)) continue;
                if (!array_key_exists($field,$profiles[0]) || $profiles[0][$field]!==$value) return false;
            }
            // The create-only descriptor does not give this administrator-owned
            // suspension profile a shared managed-plan revision.
            return true;
        }
        // Suspended accounts refer to an externally administered profile, which has no plugin generation.
        if (!$profiles) return (int)$applied['status']===3;
        if (count($profiles)!==1 || preg_match('/^uisp-ros-plugin:(?:plan|service-profile):[1-9][0-9]*$/D',
            $profiles[0]['_owner'] ?? '')!==1) return false;
        $active=array_values(array_filter($applied['_commands'],static function (array $command) use ($activePath,$account): bool {
            return trim($command['path'] ?? '', '/')===$activePath && ($command['name'] ?? null)===($account['name'] ?? null);
        }));
        if (count($active)!==1 || !array_key_exists('_profile_revision',$active[0])) return false;
        $revision=filter_var($active[0]['_profile_revision'],FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        if ($revision===false) return false;
        $identity=ApiSharedResources::endpointIdentity((object)$applied['_device']) . ':profile:'
            . $profilePath . ':' . $profiles[0]['_owner'];
        return $queue->profileRevision($identity)===$revision;
    }

    private function sameOptionalNumber($first,$second): bool
    {
        // In particular a NULL override inherits UISP speed; an explicit zero does not.
        return $first === null || $second === null ? $first === $second : $first == $second;
    }

    private function hasExplicitBillingState(): bool
    {
        $raw = $this->req()->extraData->entity ?? null;
        return $this->hydratedBillingState || (is_object($raw) && property_exists($raw, 'status') && $this->validBillingState($raw->status));
    }

    private function needsAppliedReconciliation(array $entity): bool
    {
        if (!empty($entity['_network_reset'])) return true;
        $sid = (int) $entity['id'];
        $queue = myJobQueue();
        if ($queue->isServiceBlocked($sid)) return $this->hasExplicitBillingState();
        if ($queue->hasUnreconciled($sid)) return true;
        $applied = $queue->appliedSnapshot($sid);
        if (!$applied) {
            // Older installations can record applied billing/router state without a full command snapshot.
            $applied = mySqlite()->singleQuery('SELECT * FROM services WHERE id=?', true, [$sid]) ?: [];
            if (!$applied) return true;
        }
        foreach (['clientId','device','planId','status','username','password','mac','hotspot','duid','iaid','callerId','uploadSpeed','downloadSpeed'] as $field) {
            if (!array_key_exists($field, $applied)) continue;
            $desired = $entity[$field] ?? null;
            $actual = $applied[$field] ?? null;
            if (in_array($field, ['username','password','mac','duid','iaid','callerId'], true)) {
                // Numeric-looking usernames and passwords are literal identities, not numeric quantities.
                if ($desired !== $actual && ($desired === null || $actual === null || (string) $desired !== (string) $actual)) return true;
            } elseif (($desired === null || $actual === null) ? $desired !== $actual : $desired != $actual) return true;
        }
        // Only explicit address attributes are desired values. An automatically allocated address
        // in the applied command snapshot is not a request to repopulate an empty UISP attribute.
        foreach (['address', 'address6'] as $field) {
            if (!isset($entity['network'][$field]) || $entity['network'][$field] === '') continue;
            $actual = $applied['network'][$field] ?? $applied[$field] ?? null;
            if ($entity['network'][$field] != $actual) return true;
        }
        return false;
    }

    private function name(): string
    {
        $client = $this->client() ;
        $id = $this->cid() ;
        $fn = $client['firstName'] ?? 'Client';
        $ln = $client['lastName'] ?? null ;
        $cn = $client['company'] ?? null ;
        return $cn ? "$cn ($id)" : "$fn $ln ($id)" ;
    }

    private function cid(): int
    {//client id
        $id = filter_var($this->type() === 'client' ? ($this->entity()->id ?? null) : ($this->entity()->clientId ?? null), FILTER_VALIDATE_INT);
        return $id && $id > 0 ? $id : 0;
    }

    private function client($id = null): ?array
    {
        if(!$id){ $id = $this->entity()->clientId ?? $this->entity()->id ?? 0; }
        $id = filter_var($id, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) { return ['company' => null, 'firstName' => null, 'lastName' => null]; }
        $q = 'select company,firstName,lastName from clients where id=:id';
        $client = myCache()->singleQuery($q,true,['id' => $id]);
        if($client){ return $client; }
        return $this->get_client($id);
    }

    private  function get_client($id): array
    {
        $fallback = ['company' => null, 'firstName' => null, 'lastName' => null];
        try { $client = $this->ucrm()->get("clients/$id"); }
        catch (Throwable $error) {
            MyLog()->Append('Client name refresh deferred for client ' . (int) $id);
            return $fallback;
        }
        if(!is_array($client) && !is_object($client)){ return $fallback; }
        $client = (array) $client;
        $ret['company'] = $client['companyName'] ?? null;
        $ret['firstName'] = $client['firstName'] ?? null;
        $ret['lastName'] = $client['lastName'] ?? null ;
        return $ret ;
    }

    private function  set_client($entity): bool
    {
        $id = filter_var($entity['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) { throw new InvalidArgumentException('Missing or invalid client ID'); }
        $entity['id'] = $id;
        $client = $this->client($id);
        $entity = array_replace($client, $entity);
        $changes = array_diff_assoc(array_diff_key($entity, ['id' => true]), $client);
        if (!$changes) { $this->resultData['provisioning'] = 'already_applied'; return true; }
        $services = mySqlite()->selectCustom('select id from services where clientId=:id', ['id' => $id]);
        if(!$services){ $this->resultData['provisioning'] = 'ignored_unmanaged'; return $this->save($entity); } //has no services - just save
        $ids = [];
        foreach($services as $service){ $ids[] = $service['id']; }
        $this->diagnosticServiceIds = array_map('intval', $ids);
        return $this->batchOutcome(myJobQueue()->withServiceLocks($ids, function () use ($ids, $entity): bool {
            if (!$this->batch()->del_queues($ids)) return false;
            if (!$this->save($entity)) return false;
            return $this->batch()->set_accounts($ids);
        }));
    }

    private function set($entity,$action = 'set'): bool
    {
        if (!$this->managedIdentity($entity)) return $this->requestCredentials($entity);
        $save = $entity ;
        if (!$this->save($save)) { return false; }
        $client = $this->client() ;
        $data = array_replace($entity,$client);
        $data['action'] = $action ;
        $data['_clear_terminal'] = true;
        if (in_array($action, ['suspend','unsuspend','suspend_cancel','activate'], true)) $data['_force_reconnect'] = true;
        return $this->batchOutcome($this->batch()->set_accounts([$data]));
    }

    private function delete($entity, array $cleanups = []): bool
    {
        if (!$cleanups) {
            $cleanups = $this->managedCleanups(['entity' => $this->normalizedEntity ?? $entity, 'previous' => $entity]);
        }
        if ($cleanups) { $entity = $cleanups[0]; }
        else { $this->resultData['provisioning'] = 'ignored_unmanaged'; return $this->unsave($entity); }
        $deletions = [];
        foreach ($cleanups ?: [$entity] as $cleanup) {
            $client = $this->client($cleanup['clientId'] ?? null);
            $data = array_replace($cleanup, $client);
            $data['action'] = 'delete';
            $data['_terminal'] = true;
            $deletions[] = $data;
        }
        // The cache describes desired UISP state; successful RouterOS cleanup is tracked separately.
        $desired = $this->normalizedEntity ?? $entity;
        if (in_array($this->action(), ['delete','end'], true)) $desired['status'] = 2;
        if (!$this->save($desired)) return false;
        if($this->batch()->del_accounts($deletions)){
            return $this->batchOutcome($this->unsave($entity), 'cleared');
        }
        $this->resultData['provisioning'] = 'failed';
        return false ;
    }

    private function requestCredentials(array $entity): bool
    {
        if (!$this->is_auto($entity)) throw new InvalidArgumentException('service_credentials_missing');
        myAttr()->set_user((int) $entity['id'], (int) $entity['clientId']);
        $this->resultData['provisioning'] = 'credentials_requested';
        return true;
    }

    private function batchOutcome(bool $completed, string $success = 'applied'): bool
    {
        $this->resultData['provisioning'] = $completed ? $success : 'failed';
        return $completed;
    }

    /** Only validated routing identifiers belong in responses and ordinary logs. */
    private function initializeResult(): void
    {
        $raw = $this->req()->extraData->entity ?? null;
        $raw = is_object($raw) ? $raw : new stdClass();
        $action = $this->action();
        $this->resultData = [
            'action' => in_array($action, ['insert','edit','delete','suspend','unsuspend','suspend_cancel','end','activate'], true) ? $action : 'unknown',
            'desiredStatus' => $this->billingStatus($raw->status ?? null),
            'resolvedDeviceId' => null,
            'provisioning' => 'failed',
            'jobKeys' => [], 'jobCounts' => ['known' => 0, 'completed' => 0, 'pending' => 0],
        ];
        $id = $this->positiveId($raw->id ?? $this->req()->entityId ?? null);
        if ($this->type() === 'service') {
            $this->resultData['serviceId'] = $id;
            $this->resultData['clientId'] = $this->positiveId($raw->clientId ?? null);
            $this->resultData['appliedStatus'] = null;
            $this->resultData['routing'] = ['configuredSiteAttribute' => null, 'siteAttributePresent' => false];
            if ($id !== null) $this->diagnosticServiceIds = [$id];
        } else {
            $this->resultData['clientId'] = $id;
        }
    }

    private function positiveId($value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    private function billingStatus($value): ?int
    {
        $status = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 8]]);
        return $status === false ? null : $status;
    }

    private function updateRoutingResult(): void
    {
        if ($this->type() !== 'service') return;
        $key = $this->conf()->device_name_attr ?? null;
        // Attribute keys are identifiers; reject arbitrary configuration text from diagnostic output.
        $key = is_string($key) && preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D', $key) ? $key : null;
        $present = false;
        $raw = $this->req()->extraData->entity ?? null;
        if ($key !== null && is_object($raw) && is_array($raw->attributes ?? null)) {
            foreach ($raw->attributes as $attribute) {
                if (is_object($attribute) && ($attribute->key ?? null) === $key) { $present = true; break; }
            }
        }
        $this->resultData['routing'] = ['configuredSiteAttribute' => $key, 'siteAttributePresent' => $present];
    }

    private function updateResultEntity(): void
    {
        if ($this->normalizedEntity === null) return;
        $entity = $this->normalizedEntity;
        if ($this->type() === 'service') {
            $this->resultData['serviceId'] = $this->positiveId($entity['id'] ?? null);
            $this->resultData['clientId'] = $this->positiveId($entity['clientId'] ?? null);
            $this->resultData['desiredStatus'] = $this->billingStatus($entity['status'] ?? null);
            $this->resultData['resolvedDeviceId'] = $this->positiveId($entity['device'] ?? null);
        }
    }

    private function updateJobResult(): void
    {
        $queue = myJobQueue();
        $keys = $this->diagnosticEventId !== null ? $queue->eventKeys($this->diagnosticEventId) : [];
        if ($this->diagnosticEventId === null || $this->type() === 'client') {
            foreach ($queue->list() as $key => $job) {
                if (in_array((int) ($job['id'] ?? 0), $this->diagnosticServiceIds, true)) $keys[] = $key;
            }
        }
        $keys = array_values(array_unique(array_filter($keys, fn($key): bool => is_string($key) && preg_match('/^Q[1-9][0-9]*$/D', $key))));
        $counts = ['known' => count($keys), 'completed' => 0, 'pending' => 0];
        foreach ($keys as $key) {
            if ($queue->completed([$key])) $counts['completed']++;
            elseif ($queue->pending([$key])) $counts['pending']++;
        }
        $this->resultData['jobKeys'] = $keys;
        $this->resultData['jobCounts'] = $counts;
        if ($this->type() === 'service' && ($id = $this->resultData['serviceId'] ?? null) !== null) {
            $applied = $queue->appliedSnapshot($id);
            $this->resultData['appliedStatus'] = $this->billingStatus($applied['status'] ?? null);
        }
    }

    private function safeErrorCode(Throwable $error): string
    {
        $known = ['service_credentials_missing','service_site_not_configured','incomplete_service_event',
            'invalid_service_attribute_value','credential_attribute_missing','credential_update_failed',
            'site_address_device_changed','site_address_mapping_unknown','site_address_identity_changed',
            'site_address_site_changed','site_address_evidence_unknown','site_address_changed',
            'site_address_review_required','site_address_write_unconfirmed','site_address_write_rejected',
            'site_address_preflight_unavailable','site_address_pool_unavailable',
            'site_address_verification_unavailable','site_address_receipt_failed'];
        return in_array($error->getMessage(), $known, true) ? $error->getMessage() : 'webhook_processing_failed';
    }

    private function hasSite(array $entity): bool
    {
        // An absent Site is distinct from a configured but missing/invalid router, which must remain an error.
        return ($entity['device'] ?? null) !== null && $entity['device'] !== '' && $entity['device'] !== false && $entity['device'] !== 0 && $entity['device'] !== '0';
    }

    private function managedIdentity(array $entity): bool
    {
        if (!$this->hasSite($entity)) { return false; }
        foreach (['mac', 'username'] as $field) {
            if (isset($entity[$field]) && $entity[$field] !== '') { return true; }
        }
        return false;
    }

    /** Retrieve resources that may already exist when the desired service has lost its Site. */
    private function managedCleanups(array $data): array
    {
        $entity = $data['entity'];
        $sid = (int) $entity['id'];
        $queue = myJobQueue();
        $candidates = [$queue->appliedSnapshot($sid)];
        foreach ($queue->resourceSnapshots($sid) as $snapshot) {
            if ((int) ($snapshot['id'] ?? 0) === $sid) { $candidates[] = $snapshot; }
        }
        $previous = $data['previous'] ?? [];
        // Recorded endpoints take precedence over an unfrozen previous cache row for the same identity.
        $hasRecordedPrevious = false;
        foreach ($candidates as $snapshot) {
            $matches = $this->managedIdentity($snapshot);
            foreach (['device', 'username', 'mac', 'hotspot', 'duid', 'iaid'] as $field) {
                if (($snapshot[$field] ?? null) != ($previous[$field] ?? null)) { $matches = false; break; }
            }
            if ($matches) { $hasRecordedPrevious = true; break; }
        }
        if (!$hasRecordedPrevious) { $candidates[] = $previous; }
        $cleanups = []; $identities = [];
        foreach ($candidates as $snapshot) {
            if (!$this->managedIdentity($snapshot)) { continue; }
            $identity = [];
            foreach (['device', 'username', 'mac', 'hotspot', 'duid', 'iaid'] as $field) { $identity[$field] = $snapshot[$field] ?? null; }
            foreach (['ip', 'port'] as $field) { $identity[$field] = $snapshot['_device'][$field] ?? null; }
            $key = json_encode($identity, JSON_THROW_ON_ERROR);
            if (isset($identities[$key])) { continue; }
            $identities[$key] = true;
            if (isset($entity['_event_id'])) { $snapshot['_event_id'] = $entity['_event_id']; }
            // A partial job can have written these commands before appliedSnapshot was published.
            // Delete precisely its frozen resources; do not replay its original set commands.
            if (isset($snapshot['_commands'])) {
                $commands = []; $disconnect = [];
                foreach ($snapshot['_commands'] as $command) {
                    $command['action'] = 'remove';
                    if (in_array(trim($command['path'], '/'), ['ppp/active', 'ip/hotspot/active'], true)) { $disconnect[] = $command; }
                    else { $commands[] = $command; }
                }
                $snapshot['_commands'] = array_merge(array_reverse($commands), $disconnect);
            }
            $cleanups[] = $snapshot;
        }
        return $cleanups;
    }

    private function save($entity): bool
    {
        $table = $this->type() . 's';
        foreach (array_keys($entity) as $key) { if (str_starts_with($key, '_')) { unset($entity[$key]); } }
        return myCache()->transaction(function ($cache) use ($entity, $table): bool {
            $net = $entity['network'] ?? [];
            if ($net) {
                $net['id'] = $entity['id'];
                if (!$cache->insert($net, 'network', INSERT_REPLACE)) { throw new RuntimeException('Could not save service network attributes'); }
            } elseif ($table === 'services') { $cache->delete((int) $entity['id'], 'network'); }
            unset($entity['network']);
            if (!$cache->insert($entity, $table, INSERT_REPLACE)) { throw new RuntimeException('Could not save service attributes'); }
            return true;
        });
    }

    private function unsave($entity): bool
    {
        $id = $entity['id'] ;
        return myCache()->transaction(function ($cache) use ($id, $entity): bool {
            $current = $this->cachedService((int) $id);
            foreach (['device','username','mac','hotspot'] as $field) {
                if ($current && ($current[$field] ?? null) != ($entity[$field] ?? null)) { return true; }
            }
            foreach(['services','network'] as $table) {
                if (!$cache->delete($id, $table)) { throw new RuntimeException('Could not delete cached provisioning data'); }
            }
            return true;
        });
    }

    private function cachedService(int $id): array
    {
        $row = myCache()->singleQuery('SELECT services.*,network.address,network.address6,network.id AS _network_row_id FROM services
            LEFT JOIN network ON services.id=network.id WHERE services.id=:id', true, ['id' => $id]) ?: [];
        $knownNetwork = isset($row['_network_row_id']);
        unset($row['_network_row_id']);
        if (!$row) {
            $row = myJobQueue()->appliedSnapshot($id);
            foreach (array_keys($row) as $key) { if (str_starts_with($key, '_')) { unset($row[$key]); } }
            unset($row['action'], $row['batch'], $row['company'], $row['firstName'], $row['lastName']);
        }
        $network = isset($row['network']) && is_array($row['network']) ? $row['network'] : [];
        foreach (['address','address6'] as $field) {
            // An applied snapshot can also hold an automatically allocated
            // top-level address. Its explicit desired NULL remains authoritative
            // when rebuilding a missing cache; do not turn that allocation into
            // a fixed UISP attribute merely because the cache was removed.
            if ($knownNetwork || (!array_key_exists($field,$network) && !empty($row[$field]))) {
                $network[$field] = $row[$field] ?? null;
            }
            unset($row[$field]);
        }
        if ($network) { $row['network'] = $network; }
        return $row;
    }

    private function is_unset($data): int
    {
        $entity = $data['entity'];
        $previous = $data['previous'] ?? [];
        $dev = $entity['device'] ?? null;
        $user = $entity['mac'] ?? $entity['username'] ?? null ;
        if($dev && $user !== null && $user !== ''){ return 0 ; }
        if(!$previous){ return 1; }
        $changes = $this->changes($entity,$previous);
        if(array_intersect(['device','mac','username'],array_keys($changes)))
        { //has prior settings - delete
            return -1;
        }
        return 1;
    }

    private function changes($entity,$previous)
    {
        unset($entity['_network_reset'], $previous['_network_reset']);
        $net0 = $entity['network'] ?? []; $net1 = $previous['network'] ?? [];
        $netdiff = '';
        if($net0 || $net1){
            if(array_diff_assoc($net0,$net1)){ $netdiff = 'network'; }
            if(array_diff_assoc($net1,$net0)){ $netdiff = 'netunset'; }
            $entity['network'] =  null ; $previous['network'] = null ;
        }
        $diff = array_diff_assoc($entity,$previous)
            ? : array_diff_assoc($previous,$entity);
        if($netdiff){ $diff[$netdiff] = $net0 ; }
        return $diff;
    }

    private function is_auto($entity): bool
    {
        $auto = $this->conf()->auto_ppp_user ?? false;
        $hs = $entity['hotspot'] ?? 0 ;
        if($hs){ $auto = $this->conf()->auto_hs_user ?? false; }
        return $auto ;
    }

    private function state(): int
    {//service status
        return $this->entity()->status ?? 0 ;
    }

    public function status(): object
    {
        return $this->status ;
    }

    public function result(): array
    {
        return $this->resultData;
    }

    private function entity(): object
    {
        if ($this->normalizedEntity !== null) { return (object) $this->normalizedEntity; }
        return $this->req()->extraData->entity ?? new stdClass();
    }

    private function type(): string
    {
        return $this->req()->entity ?? 'no_entity';
    }

    private function action(): string
    {
        return $this->req()->changeType ?? 'no_change' ;
    }

    private function conf(): object
    {
        return mySqlite()->readConfig() ;
    }

    private function batch(): Batch
    {
        return new Batch() ;
    }

    private function  req(): object
    {
        if(is_object($this->request)){
            return $this->request; }
        return new stdClass();
    }

    private function ucrm(): ApiUcrm { return new ApiUcrm(); }

    public function __construct($data = null)
    {
        $this->request = $data ;
        $this->status = new stdClass();
    }
}
