<?php
include_once 'api_common.php';
require_once __DIR__ . '/api_backup.php';
require_once __DIR__ . '/api_job_queue.php';
class ApiUpdate
{
    private string $mode ;
    private $data;
    private $result;
    private bool $fast = false ;
    private ?object $state = null;

    public function exec(): void
    {
        $operation = function (): void { $this->dispatch(); };
        $action = $this->data->action ?? null;
        if ($action === 'restore') { $operation(); return; } // Restore acquires its exclusive guard itself.
        if ($this->mode === 'devices' && $action === 'delete') {
            myJobQueue()->withMaintenance($operation);
        } else {
            myJobQueue()->withAdministrativeLock($operation);
        }
    }

    private function dispatch(): void
    {
        $allowed = 'services,config,devices,plans,jobs,backups,attributes,attrs,system';
        if(!in_array($this->mode,explode(',',$allowed))){
            fail('invalid_mode',$this->data);
        }
        $action = $this->data->action ?? null;
        switch ($action){
            case 'insert': $this->result = $this->insert(); break;
            case 'edit': $this->result = $this->edit(); break;
            case 'delete': $this->result = $this->delete(); break;
            case 'backup': $this->result = $this->backup(); break;
            case 'restore': $this->result =$this->restore(); break;
            case 'publish': $this->result =$this->publish(); break;
            case 'unpublish' : $this->result =$this->publish(true); break;
            case 'cache': $this->result =$this->cache_build(); break;
            case 'log_clear' : $this->result =$this->log_clear(); break;
            case 'run': $this->result = $this->run_jobs() ; break ;
            case 'sync_attrs': $this->result = $this->sync_attrs(); break;
            default: fail('invalid_action',$this->data);
        }
    }

    public function backup(): array
    {
        if(!in_array($this->mode,['backups','system'])){
            fail('invalid_mode',[$this->mode,$this->data]);
        }
        $next = 'data/' . ApiBackup::rotate();
        if(is_file($next)){
            MyLog()->Append(['backup_success',$next,date('c',filemtime($next))]);
            return [1];
        }
        fail('backup_fail',$this->data);
    }

    private function delete()
    {
        switch ($this->mode){
            case 'devices':
            case 'delete': return $this->delete_db();
            case 'services':
            case 'system': return $this->delete_services();
            case 'jobs': return $this->delete_jobs();
            default: fail('invalid_mode',[$this->mode,$this->data]);
        }
    }

    private function edit()
    {
        switch ($this->mode){
            case 'devices':
            case 'plans': return $this->edit_db();
            case 'config': return $this->edit_config();
            case 'attrs':
            case 'attributes': return $this->edit_attr();
            default: fail('invalid_mode',[$this->mode,$this->data]);
        }
    }

    private function edit_config()
    {
        $data = json_decode(json_encode($this->data->data),true);
        if (isset($data['disabled_profile']) && preg_match('/(?:^uisp-(?:plan-\d+|service-\d+-profile)$|\[UISP#\d+\]$)/D', (string) $data['disabled_profile'])) {
            throw new InvalidArgumentException('Usa un perfil de suspensión propio; ese nombre está reservado para los planes del plugin.');
        }
        require_once __DIR__ . '/api_configuration_apply.php';
        $applyRequested = false;
        $saved = $this->db()->transaction(function () use ($data, &$applyRequested): bool {
            // Compare the state actually updated under the write lock. A
            // concurrent network save must not turn an exclusions-only edit
            // into an unrelated service-wide reapplication.
            $conf = $this->db()->readConfig();
            foreach (array_keys($data) as $key) {
                if (!property_exists($conf, $key) && $key !== 'plugin_language') fail('invalid_config_key', $this->data);
            }
            if (!$this->db()->saveConfig($data)) return false;
            $applyRequested = ApiConfigurationApply::request((array) $conf, (array) $this->db()->readConfig());
            return true;
        });
        if($saved){
            MyLog()->Append('config_edit_success');
            // Saving allocator-only/UI settings must not drain work left by
            // earlier network edits. The scheduled worker owns that backlog.
            $scheduled = $applyRequested ? ApiConfigurationApply::drain() : ['queued'=>0, 'remaining'=>0];
            return ['scheduled' => $scheduled['queued'], 'remaining' => $scheduled['remaining'],
                'message' => $scheduled['queued'] || $scheduled['remaining']
                    ? 'Configuración guardada. Los cambios de red se aplicarán automáticamente; puedes ver el avance en Trabajos.'
                    : 'Configuración guardada.'];
        }
        fail('edit_config_fail',$this->data);
    }

    private function insert()
    {
        switch ($this->mode){
            case 'devices':
            case 'plans': return $this->insert_db();
            case 'services':
            case 'system': return $this->insert_services();
            case 'attrs':
            case 'attributes': return $this->insert_attr();
            default: fail('invalid_mode',[$this->mode,$this->data]);
        }
    }

    private function log_clear(): array
    {
        $fn = 'data/plugin.log';
        if(file_put_contents($fn,date('c') . " log_reset\n")){
            MyLog()->Append('log_clear_success');
            return [];
        }
        fail('log_clear_fail');
    }

    private function publish($clear = false): array
    {
        $name = $this->data->data->name ?? null;
        ApiBackup::path($name);
        if ($clear) {
            $path = 'public/' . $name;
            if (is_file($path) && !unlink($path)) throw new RuntimeException('backup_unpublish_failed');
            return [];
        }
        throw new InvalidArgumentException('use_authenticated_backup_download');
    }

    public function restore(): array
    {
        ApiBackup::restore(ApiBackup::path($this->data->data->name ?? null));
        return [];
    }

    private function run_jobs()
    {
        $data = $this->data->data ?? [];
        if (!is_array($data)) throw new InvalidArgumentException('invalid_job_ids');
        $requested = $this->jobKeys($data);
        require_once __DIR__ . '/api_configuration_apply.php';
        ApiConfigurationApply::drain();
        $queue = myJobQueue();
        if ($requested) $results = $queue->runSelected($requested);
        else {
            $results = $queue->run(null);
            $requested = array_keys($results);
            foreach ($results as &$result) $result['attempted'] = true;
            unset($result);
        }
        $jobs = $queue->selectionStatus(array_values(array_unique(array_merge($requested, array_keys($results)))));
        $summary = ['requested' => count($requested), 'attempted' => 0, 'succeeded' => 0, 'pending' => 0,
            'failed' => 0, 'deleted' => 0, 'refused' => 0];
        foreach ($results as $key => &$result) {
            $result += $jobs[$key] ?? [];
            if (!$result['attempted']) continue;
            $summary['attempted']++;
            $summary[$result['success'] ? 'succeeded' : 'failed']++;
        }
        unset($result);
        foreach ($requested as $key) if (($jobs[$key]['state'] ?? null) !== 'succeeded') $summary['pending']++;
        if ($summary['failed']) $this->state = (object) ['error' => true, 'message' => 'jobs_pending_after_failed_attempt'];
        elseif ($summary['pending']) $this->state = (object) ['error' => true, 'message' => 'jobs_are_pending_or_running'];
        return $this->jobActionResult('run', $requested, $results, $jobs, $summary);
    }

    /** Reject malformed selections before an empty selection can mean automatic processing. */
    private function jobKeys(array $keys): array
    {
        $normalized = [];
        foreach ($keys as $key) {
            if (!is_int($key) && !(is_string($key) && preg_match('/^Q?[1-9][0-9]*$/D', $key)))
                throw new InvalidArgumentException('invalid_job_ids');
            $id = filter_var(ltrim((string) $key, 'Q'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) throw new InvalidArgumentException('invalid_job_ids');
            $normalized['Q' . $id] = 'Q' . $id;
        }
        return array_values($normalized);
    }

    private function jobActionResult(string $operation, array $requested, array $results, array $jobs, array $summary): array
    {
        $attempted = [];
        if ($operation === 'run') foreach ($results as $key => $result) if ($result['attempted']) $attempted[] = $key;
        try {
            MyLog()->Append(['event' => 'job_action_result', 'operation' => $operation, 'jobIds' => $requested,
                'attemptedJobIds' => $attempted] + $summary,
                ($summary['failed'] || $summary['pending'] || $summary['refused']) ? LOG_ERR : LOG_INFO);
        } catch (Throwable $ignored) { /* Reporting cannot undo completed or pending work. */ }
        return ['operation' => $operation, 'requested' => $requested, 'results' => $results ?: new stdClass(),
            'jobs' => $jobs ?: new stdClass(), 'summary' => $summary];
    }


    private function sync_attrs(): array
    {
        $api = new ApiUcrm(null, true);
        $existing = $api->get('custom-attributes');
        if (!is_array($existing)) throw new RuntimeException('attributes_list_failed');
        $map = [];
        foreach ($existing as $attribute) $map[$attribute['key']] = $attribute;
        $config = $this->db()->readConfig();
        $keys = ['device_name_attr','pppoe_user_attr','pppoe_pass_attr','mac_addr_attr','ip_addr_attr','ip_addr6_attr','pppoe_caller_attr','dhcp6_duid_attr','dhcp6_iaid_attr','hs_attr'];
        $count = 0;
        foreach ($keys as $key) {
            $name = $config->$key ?? null;
            if (!$name) continue;
            if (isset($map[$name])) {
                if (($map[$name]['attributeType'] ?? '') !== 'service') throw new RuntimeException('attribute_must_belong_to_service: ' . $name);
                continue;
            }
            $res = $api->post('custom-attributes', ['name' => $name, 'type' => 'string', 'clientZoneVisible' => false, 'attributeType' => 'service']);
            if (!is_array($res) || empty($res['id']) || empty($res['key'])) throw new RuntimeException('attribute_create_failed');
            $this->db()->saveConfig([$key => $res['key']]);
            $map[$res['key']] = $res;
            $count++;
        }
        return ['created' => $count, 'message' => "Atributos de servicio creados: $count"];
    }

    private function delete_db(): ?array
    {
        $data = json_decode(json_encode($this->data->data),true);
        $id = $data['id'] ?? null ;
        if($id){ $data = [$id]; }
        $pk = $this->find_pk();
        if(!$data || !$pk){ return null; }
        $data = array_map([$this, 'positiveId'], $data);
        $ids = implode(',', array_fill(0, count($data), '?'));
        $table = $this->mode ;
        $s = "select * from $table where $pk in ($ids)";
        $before = $this->db()->selectCustom($s, $data);
        if ($this->mode === 'devices') {
            foreach ($data as $deviceId) {
                if ((int) $this->db()->countServicesByDeviceId($deviceId) > 0
                    || (int) myCache()->singleQuery('SELECT count(*) FROM services WHERE device=?', false, [$deviceId]) > 0) {
                    throw new InvalidArgumentException('El router tiene servicios asociados. Retira o migra sus servicios antes de eliminarlo.');
                }
            }
            if (myJobQueue()->hasDeviceReferences($data)) {
                throw new InvalidArgumentException('El router tiene trabajos o recursos pendientes. Complétalos antes de eliminarlo.');
            }
        }
        $d = "delete from $table where $pk in ($ids)";
        if($this->db()->execute($d, $data)){
            foreach ($before as &$item) unset($item['password']);
            $this->result = $before ;
            // No UISP refresh is needed: associated services were rejected before deleting.
            return $this->result ;
        }
        fail('db_delete_fail',$this->data);
    }

    private function delete_jobs(): array
    {
        $del = $this->data->data ?? [];
        if (!is_array($del)) throw new InvalidArgumentException('invalid_job_ids');
        $requested = $this->jobKeys($del);
        $queue = myJobQueue();
        $before = $queue->selectionStatus($requested);
        $results = $queue->delete($requested);
        $jobs = $queue->selectionStatus($requested);
        $summary = ['requested' => count($requested), 'attempted' => 0, 'succeeded' => 0, 'pending' => 0,
            'failed' => 0, 'deleted' => 0, 'refused' => 0];
        foreach ($requested as $key) {
            $deleted = ($results[$key] ?? false) === true;
            $summary[$deleted ? 'deleted' : 'refused']++;
            if (!$deleted && empty($jobs[$key]['deleteBlockedReason']) && isset($before[$key]['deleteBlockedReason'])) {
                // Retain the observed refusal if a concurrent worker changed its state immediately afterwards.
                $jobs[$key]['deleteBlockedReason'] = $before[$key]['deleteBlockedReason'];
            }
        }
        if ($summary['refused']) $this->state = (object) ['error' => true, 'message' => 'jobs_delete_refused'];
        return $this->jobActionResult('delete', $requested, $results, $jobs, $summary);
    }

    private function delete_services(): array
    {
        $this->fast_finish();
        $ids = $this->find_services() ;
        if(!$ids){ return [] ; }
        $batch = new Batch();
        if($batch->del_accounts($ids)){
            MyLog()->Append(['delete_services_success','items: '.sizeof($ids)]);
            return [];
        }
        fail('delete_services_fail',$this->data);
    }

    private function insert_attr()
    {
        $an = $this->data->data->value ?? null;
        $key = $this->data->data->key ?? 'nokey' ;
        $conf = $this->db()->readConfig();
        if(!$an || !property_exists($conf,$key)){
            fail('insert_attr_fail',$this->data);
        }
        $data = [
            'name' => $an,
            'type' => 'string',
            'clientZoneVisible' => false,
            'attributeType' => 'service',
        ];
        $done = $this->ucrm()->post('custom-attributes',$data);
        $value = $done->key ?? null ;
        if($key && $this->db()->saveConfig([$key => $value])){
            MyLog()->Append(['insert_attr_success',$key,$value]);
            return [];
        }
        fail('insert_attr_fail',$data);
    }

    private function insert_db()
    {
        $data = json_decode(json_encode($this->data->data),true);
        $cols = $this->find_columns() ;
        $qos = $this->qos_change();
        if(!$data || !$cols){ fail('insert_invalid',$this->data); }
        $fill = array_fill_keys($cols,null);
        $trim = array_intersect_key($data,$fill);
        $trim = $this->validateRow($trim, false);
        if($this->mode === 'devices' && empty($trim['port'])) { $trim['port'] = !empty($trim['ssl']) ? 8729 : 8728; }
        $now = date('c');
        $trim['last'] = $now ;
        $trim['created'] = $now ;
        $saved=$this->db()->transaction(function () use ($trim): bool {
            if (!$this->db()->insert($trim,$this->mode)) return false;
            if ($this->mode==='plans') {
                require_once __DIR__ . '/batch.php';
                (new Batch())->validateStoredPlan((int)($trim['id'] ?? $this->db()->lastInsertId()));
            }
            return true;
        });
        if($saved){
            $id = $data['id'] ?? $this->db()->lastInsertId();
            $this->result = $this->find_last($id);
            if($this->mode == 'devices')
            {
                $this->cache_build(true) && $this->set_qos($qos);
            }
            return $this->result;
        }
        fail('db_insert_fail',$this->data);
    }

    private function insert_services($type = null): array
    {
        $this->fast_finish();
        $ids = $this->find_services($type) ;
        if(!$ids){ return ['requested'=>0,'completed'=>0,'failed'=>0,'failures'=>[]]; }
        // Keep confirmed address reservations while the router operation is pending.
        $batch = new Batch();
        // A manual router synchronization deliberately refreshes all its managed
        // sessions, even when account/profile settings already match. Other
        // rebuilds and automatic updates keep their change-based reconnects.
        $scope = $this->data->data->type ?? $type ?? 'device';
        $forceReconnect = in_array($scope, ['device', 'devices'], true);
        $completed = $batch->set_accounts($ids, $forceReconnect);
        $outcome = $batch->outcomes();
        // A manual synchronization has no webhook_result. Keep its aggregate
        // outcome visible without debugging or exposing service credentials.
        try {
            MyLog()->Append([
                'event' => 'provisioning_result',
                'scope' => in_array($scope, ['device','devices','plan','plans','service','services','all'], true) ? $scope : 'unknown',
                'scopeId' => filter_var($this->data->data->id ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null,
                'forceReconnect' => $forceReconnect,
                'requested' => (int) $outcome['requested'],
                'completed' => (int) $outcome['completed'],
                'pendingOrFailed' => (int) $outcome['failed'],
            ], $completed ? LOG_INFO : LOG_ERR);
        } catch (Throwable $ignored) {
            // Logging must not change a confirmed job result or its retry state.
        }
        if($completed){
            MyLog()->Append(['insert_services_success','items: '.sizeof($ids)]);
            return $outcome;
        }
        $this->state=(object)['error'=>true,'message'=>'provisioning_incomplete'];
        return $outcome;
    }

    private function edit_attr()
    {
        $key = $this->data->data->key ?? 'NoKey';
        $value = $this->data->data->value ?? null ;
        $conf = $this->db()->readConfig();
        if(property_exists($conf,$key)
            && $this->db()->saveConfig([$key => $value])){
            MyLog()->Append(['edit_attr_success',$key,$value]);
            return [];
        }
        fail('edit_attr_fail',$this->data);
    }

    private function edit_db(): ?array
    {
        $data = json_decode(json_encode($this->data->data),true);
        $pk = $this->find_pk();
        $id = $data[$pk] ?? null ;
        $qos = $this->qos_change() ;
        $cols = array_diff($this->find_columns(),['created']) ;
        if(!$data || !$pk || !$id || !$cols){
            fail('edit_invalid',$data);  }
        $fill = array_fill_keys($cols,null);
        $trim = array_intersect_key($data,$fill);
        $trim = $this->validateRow($trim, true);
        $trim['last'] = date('c') ;
        $nameChanged = false;
        $persist = function () use ($trim,$pk,&$nameChanged):bool { return $this->db()->transaction(function () use ($trim, $pk, &$nameChanged): bool {
            if ($this->mode === 'devices') {
                $before = $this->db()->singleQuery('SELECT * FROM devices WHERE id=?', true, [$trim[$pk]]);
                if (!$before) throw new InvalidArgumentException('device_not_found');
                $after = array_replace($before, $trim);
                $nameChanged = $after['name'] !== $before['name'];
                require_once __DIR__ . '/api_site_address_policy.php';
                ApiSiteAddressPolicy::recordPoolChange($before, $after, $this->db());
            }
            if (!$this->db()->edit($trim, $this->mode)) return false;
            if ($this->mode === 'plans') {
                require_once __DIR__ . '/batch.php';
                (new Batch())->validateStoredPlan((int)$trim[$pk]);
                require_once __DIR__ . '/api_configuration_apply.php';
                ApiConfigurationApply::requestPlan((int) $trim[$pk]);
            }
            return true;
        }); };
        if ($this->mode === 'devices') {
            require_once __DIR__.'/api_shared_resources.php';
            $beforeGuard=$this->db()->singleQuery('SELECT * FROM devices WHERE id=?',true,[$trim[$pk]]);
            if (!$beforeGuard) throw new InvalidArgumentException('device_not_found');
            $identities=array_values(array_unique([ApiSharedResources::endpointIdentity((object)$beforeGuard),
                ApiSharedResources::endpointIdentity((object)array_replace($beforeGuard,$trim))]));
            sort($identities,SORT_STRING);
            $saveGuarded=function(int $index) use(&$saveGuarded,$identities,$persist,$trim,$pk,$beforeGuard):bool {
                if ($index<count($identities)) return myJobQueue()->withResourceLock($identities[$index],fn():bool=>$saveGuarded($index+1));
                $current=$this->db()->singleQuery('SELECT * FROM devices WHERE id=?',true,[$trim[$pk]]);
                if (!$current || ApiSharedResources::endpointIdentity((object)$current)!==ApiSharedResources::endpointIdentity((object)$beforeGuard))
                    throw new RuntimeException('site_address_device_changed');
                return $persist();
            };
            try {$saved=$saveGuarded(0);} finally {$saveGuarded=null;}
        } else $saved=$persist();
        if($saved){
            $this->result = $this->find_last($trim[$pk]);
            switch ($this->mode){
                case 'devices':
                    // Saving one router must not depend on refreshing every UISP
                    // service. Direct sync resolves its current Site independently.
                    if ($nameChanged) $this->db()->saveConfig(['next_cache' => '2020-01-01']);
                    $this->set_qos($qos);
                    break;
                case 'plans':
                    require_once __DIR__ . '/api_configuration_apply.php';
                    ApiConfigurationApply::drain();
                    break;
            }
            MyLog()->Append(['edit_db_success','mode: '. $this->mode,'id: '. $id]);
            return $this->result ;
        }
        fail('db_edit_fail',$this->data);
    }

    private function find_last($id = null)
    {
        $table = $this->mode ;
        $pk = $this->find_pk() ;
        if(!$id){
            $id = $this->db()->singleQuery("select max($pk) from $table");
        }
        $q = 'SELECT * FROM ' . ApiSqlite::identifier($table) . ' WHERE ' . ApiSqlite::identifier($pk) . '=?';
        $last = $this->db()->singleQuery($q,true,[$this->positiveId($id)]);
        return array_diff_key($last ?? [],['password' => true]);
    }

    private function find_columns(): array
    {
        $table = $this->mode ;
        $q = "select * from pragma_table_info('$table')";
        $r = $this->db()->selectCustom($q) ;
        $cols = [];
        foreach($r as $i){
            $cols[] = $i['name'] ;
        }
        return $cols ;
    }

    private function find_pk(): string
    {
        $table = $this->mode ;
        $q = "select name from pragma_table_info('$table') where pk=1";
        return $this->db()->singleQuery($q);
    }

    private function find_services($type = null): array
    {
        $id = $this->data->data->id ?? 0 ;
        // A caller's explicit scope takes precedence over a device row's
        // technology field (for example type=mikrotik in the edit form).
        $type = $type ?? $this->data->data->type ?? 'device' ;
        if($type != 'all' && !$id){ return []; }
        if (!in_array($type, ['device','devices','plan','plans','service','services','all'], true)) throw new InvalidArgumentException('invalid_service_filter');
        $field = in_array($type, ['device','devices'], true) ? 'device' : 'planId';
        if (in_array($type, ['service','services'], true)) $field = 'id';
        $where = "where $field=? and" ;
        if($type == 'all') $where = 'where' ;
        $q = "select id,device from services $where device>0 and status not in (0,2,5,6,8)";
        $r = $this->cachedb()->selectCustom($q, $type === 'all' ? [] : [$this->positiveId($id)]) ?? [];
        $devices=[];
        foreach ($this->db()->selectAllFromTable('devices') ?? [] as $device) $devices[(int)$device['id']]=true;
        $ids = [];
        foreach($r as $i){ if (isset($devices[(int)$i['device']]) && !myJobQueue()->isServiceBlocked((int)$i['id'])) $ids[] = $i['id']; }
        MyLog()->Append(['find_services','items: '. sizeof($ids)]);
        return $ids ;
    }

    private function fast_finish()
    {
        // Return a response only after the requested operation has a known result.
    }

    private function set_qos($type = 0): array
    {
        if (!$type) return [];
        $this->fast_finish();
        MyLog()->Append("set_qos type: ". $type);
        $ids = $this->find_services('device') ;
        if(!$ids){ return []; }
        $batch = new Batch();
        if($type > 0){
           $done = $batch->set_accounts($ids);
        }
        else{
            $done = $batch->del_queues($ids);
        }
        if($done){MyLog()->Append(['set_qos_success',"items: ".sizeof($ids)]);}
        else { $this->state=(object)['error'=>true,'message'=>'provisioning_incomplete']; }
        return $batch->outcomes();
    }

    private function cache_build($edit = false): array
    {
        $this->fast_finish();
        if($edit && !$this->name_change()){ return [1]; }
        $api = new ApiCache() ;
        $api->sync(true);
        MyLog()->Append("device_cache_success");
        return [1] ;
    }

    private function qos_change(): int
    {
        if($this->mode != 'devices'){ return 0; }
        $edit = $this->data->data->qos ?? null ;
        if(!is_int($edit)){ return 0 ; }
        $id = $this->data->data->id ?? 0 ;
        if (!$id) return $edit ? 1 : -1;
        $saved = $this->db()->singleQuery('SELECT qos FROM devices WHERE id=?',false,[$this->positiveId($id)]);
        if($edit === $saved){ return 0 ;}
        return $edit ? 1 : -1;
    }

    private function name_change(): bool
    {//if device name has changed
        $name = $this->data->data->name ?? null ;
        return $this->mode == 'devices' && $name ;
    }

    private function positiveId($value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) throw new InvalidArgumentException('invalid_id');
        return $id;
    }

    private function validateRow(array $row, bool $edit): array
    {
        if (isset($row['id'])) $row['id'] = $this->positiveId($row['id']);
        if ($this->mode === 'devices') {
            if (!$edit && empty($row['type'])) $row['type'] = 'mikrotik';
            if (isset($row['type']) && $row['type'] !== 'mikrotik') throw new InvalidArgumentException('device_type_unsupported');
            foreach (['name','ip','user'] as $field) {
                if (!$edit && empty($row[$field])) throw new InvalidArgumentException('device_field_required: ' . $field);
                if (isset($row[$field]) && (!is_string($row[$field]) || trim($row[$field]) === '' || preg_match('/[\r\n\x00]/', $row[$field]))) throw new InvalidArgumentException('device_field_invalid');
            }
            foreach (['pool' => false, 'pool6' => true] as $field => $ipv6) {
                if (!array_key_exists($field, $row)) continue;
                if ($row[$field] === null || $row[$field] === '') { $row[$field] = null; continue; }
                if (!is_string($row[$field])) throw new InvalidArgumentException('invalid_device_pool');
                $prefixes = preg_split('/\s*,\s*/', trim($row[$field]));
                foreach ($prefixes as $prefix) {
                    $parts = explode('/', $prefix);
                    if (count($parts) !== 2 || !ctype_digit($parts[1]) || (int)$parts[1] < 1
                        || (int)$parts[1] > ($ipv6 ? 128 : 32)
                        || !filter_var($parts[0], FILTER_VALIDATE_IP, $ipv6 ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4))
                        throw new InvalidArgumentException('invalid_device_pool');
                }
                $row[$field] = implode(',', array_values(array_unique($prefixes)));
            }
            if (array_key_exists('port', $row)) {
                if ($edit && ($row['port'] === '' || $row['port'] === null)) unset($row['port']);
                else {
                    $row['port'] = filter_var($row['port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
                    if ($row['port'] === false) throw new InvalidArgumentException('invalid_port');
                }
            }
            foreach (['qos','ssl','allowSelfSigned'] as $field) {
                if (array_key_exists($field, $row)) {
                    if (!in_array($row[$field], [0,1,'0','1',false,true], true)) throw new InvalidArgumentException('invalid_device_flag');
                    $row[$field] = (int) $row[$field];
                }
            }
            if (isset($row['pfxLength'])) {
                if ($row['pfxLength'] === '') $row['pfxLength'] = null;
                else {
                    $row['pfxLength'] = filter_var($row['pfxLength'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 128]]);
                    if ($row['pfxLength'] === false) throw new InvalidArgumentException('invalid_prefix_length');
                }
            }
        } elseif ($this->mode === 'plans') {
            require_once __DIR__ . '/data.php';
            $row = Data::normalizePlanNumbers($row);
            foreach ($row as $key => &$value) {
                if (in_array($key, ['id','name','last','created'], true) || $value === null) continue;
                $minimum = in_array($key, ['burstUpload','burstDownload'], true) ? -100 : 0;
                if (!is_numeric($value) || !is_finite((float) $value) || $value < $minimum) throw new InvalidArgumentException('invalid_plan_value');
                if (in_array($key, ['limitUpload','limitDownload','threshUpload','threshDownload'], true) && $value > 100) throw new InvalidArgumentException('invalid_plan_percentage');
                if (in_array($key, ['ratio','priorityUpload','priorityDownload'], true)) {
                    if ((float) $value != (int) $value || $value < 1 || ($key !== 'ratio' && $value > 8)) throw new InvalidArgumentException('invalid_plan_priority_or_ratio');
                    $value = (int) $value;
                } else $value = (float) $value;
            }
        }
        return $row;
    }

    private function set_mode($mode)
    {
        if(preg_match("#(service|device|plan)#",$mode)){ //append ending "s"
            $mode = preg_replace("#s\s*$#",'',$mode) . 's';
        }
        $this->mode = $mode ;
    }

    private function ucrm(): ApiUcrm { return new ApiUcrm(); }

    private function db(): ApiSqlite { return mySqlite(); }

    private function cachedb(): ApiSqlite { return myCache(); }

    public function status(): object { return $this->state ?? new stdClass(); }

    public function result() { return $this->result; }

    public function __construct($data = null,$mode = 'services')
    {
        $this->set_mode($mode);
        if(is_array($data)){ $data = json_decode(json_encode($data));}
        if(!$data){$data = new stdClass(); }
        $this->data = $data ;
    }
}
