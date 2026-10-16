<?php
/** Called only after administrative authentication, edit-grant and same-origin checks. */
final class ApiPanelWorkflows
{
    public static function handle(string $method,?object $request,array $files,array $post,array $query): bool
    {
        if (isset($query['download']) && ApiFullBackup::backupName($query['download'])) {
            if ($method!=='GET') throw new InvalidArgumentException('invalid_download_method');
            ApiFullBackup::download($query['download']); return true;
        }
        if ($files && ($post['workflow']??null)==='full_backup') {
            if ($method!=='POST' || array_keys($files)!==['fullBackup'] || array_keys($post)!==['workflow']) throw new InvalidArgumentException('invalid_full_backup_request');
            respond('full_backup_preview',false,(new ApiFullBackup())->previewUpload($files['fullBackup'])); return true;
        }
        if ($method!=='POST' || !$request || !in_array($request->target??null,['full_backup','pool_capacity','service_reconciliation','sync_preview','device_sync','service_lifecycle'],true)) return false;
        if (!empty($request->entity) || !is_object($request->data??null)) throw new InvalidArgumentException('invalid_workflow_request');
        $data=$request->data; $keys=array_keys(get_object_vars($data)); $action=$request->action??'list'; $type=$request->changeType??null;
        $result=null;
        if ($request->target==='pool_capacity' && $type==='list' && $action==='list' && !$keys) {
            require_once __DIR__.'/api_pool_capacity.php'; $result=(new ApiPoolCapacity())->read();
        } elseif ($request->target==='service_reconciliation' && $type==='list' && $action==='list' && !$keys) {
            require_once __DIR__.'/api_service_reconciliation.php'; $result=(new ApiServiceReconciliation())->read();
        } elseif ($request->target==='service_lifecycle') {
            require_once __DIR__.'/api_service_lifecycle.php'; $api=new ApiServiceLifecycle();
            if ($type==='list' && $action==='preview' && $keys===['serviceId'] && is_int($data->serviceId) && $data->serviceId>0) $result=$api->preview($data->serviceId);
            elseif ($type==='update' && $action==='apply' && $keys===['previewToken'] && is_string($data->previewToken)) {
                if (is_file('data/data.db')) (new ApiSetup())->assertCompatible();
                $result=$api->apply($data->previewToken);
            } else throw new InvalidArgumentException('invalid_service_lifecycle_request');
        } elseif ($request->target==='full_backup') {
            $api=new ApiFullBackup();
            if ($type==='list' && $action==='list' && !$keys) $result=$api->listing();
            elseif ($type==='update' && $action==='create' && !$keys) $result=$api->create();
            elseif ($type==='update' && $action==='preview' && $keys===['name'] && is_string($data->name)) $result=$api->preview($data->name);
            elseif ($type==='update' && $action==='apply' && $keys===['previewToken'] && is_string($data->previewToken)) $result=$api->apply($data->previewToken);
            else throw new InvalidArgumentException('invalid_full_backup_request');
        } elseif ($request->target==='device_sync') {
            require_once __DIR__.'/api_device_sync.php';
            $begin=$type==='update' && $action==='begin' && $keys===['deviceId'] && is_int($data->deviceId) && $data->deviceId>0;
            $step=$type==='update' && $action==='step' && $keys===['syncToken'] && is_string($data->syncToken);
            if (!$begin && !$step) throw new InvalidArgumentException('invalid_device_sync_request');
            if (is_file('data/data.db')) {
                $setup=new ApiSetup(); $setup->assertCompatible();
                // Direct synchronization must retain the migration pause that
                // the ordinary router route checks before initializing jobs.
                $setup->assertNetworkAddressesUnique();
            }
            require_once __DIR__.'/api_job_queue.php';
            $api=new ApiDeviceSync();
            try { $result=myJobQueue()->withAdministrativeLock(fn():array=>$begin?$api->begin($data->deviceId):$api->step($data->syncToken)); }
            catch (RuntimeException $error) {
                $code=$error->getMessage();
                if (!in_array($code,['device_sync_router_unavailable','device_sync_router_ambiguous','device_sync_configuration_missing',
                    'device_sync_configuration_changed','device_sync_catalog_unavailable','device_sync_storage_unavailable',
                    'device_sync_limit','device_sync_session_limit','device_sync_busy','device_sync_expired'],true)) throw $error;
                http_response_code(in_array($code,['device_sync_configuration_changed','device_sync_busy','device_sync_expired'],true)?409:503);
                respond($code,true,[]); return true;
            }
        } elseif ($request->target==='sync_preview') {
            require_once __DIR__.'/api_sync_preview.php'; $api=new ApiSyncPreview();
            if ($type==='list' && $action==='begin' && $keys===['deviceId'] && is_int($data->deviceId) && $data->deviceId>0) $result=$api->begin($data->deviceId);
            elseif ($type==='list' && $action==='step' && $keys===['previewToken'] && is_string($data->previewToken)) $result=$api->step($data->previewToken);
            elseif ($type==='update' && $action==='apply' && $keys===['previewToken','mode'] && is_string($data->previewToken)
                && in_array($data->mode,['differences','force_reconnect'],true)) {
                if (is_file('data/data.db')) (new ApiSetup())->assertCompatible();
                $result=$api->applyStep($data->previewToken,$data->mode);
            } else throw new InvalidArgumentException('invalid_sync_preview_request');
        } else throw new InvalidArgumentException('invalid_workflow_request');
        respond($request->target,false,$result); return true;
    }
}
