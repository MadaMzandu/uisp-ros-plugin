<?php
chdir(__DIR__);
set_time_limit(120);
ini_set('memory_limit', '512M');
ini_set('display_errors', '0');
// The panel HTML and authenticated responses must not survive a plugin update in browser/proxy caches.
header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
require_once 'lib/api_runtime.php';
$runtimeRefresh = refresh_plugin_runtime();
require_once 'vendor/autoload.php';
require_once 'lib/api_common.php';
require_once 'lib/api_security.php';
require_once 'lib/api_uisp_client.php';

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'POST'], true)) {
        http_response_code(405);
        header('Allow: GET, POST');
        respond('method_not_allowed', true);
        exit;
    }
    $raw = file_get_contents('php://input', false, null, 0, 1048577);
    if ($raw === false) throw new RuntimeException('request_read_failed');
    if (strlen($raw) > 1048576) throw new InvalidArgumentException('request_too_large');
    $data = $raw !== '' ? json_decode($raw, false, 512, JSON_THROW_ON_ERROR) : null;
    $config = is_file('data/config.json') ? json_decode(file_get_contents('data/config.json'), true) : [];
    $webhook = $method === 'POST' && ApiSecurity::webhookAuthorized($data, $_SERVER, $_GET, is_array($config) ? $config : []);
    $user = null;
    if (!$webhook) {
        try { $user = ApiUispClient::security()->getUser(); }
        catch (Throwable $ignored) {}
    }
    $write = isset($_GET['download']) || ($method === 'POST' && (($data->changeType ?? '') !== 'list' || !empty($_FILES)));
    if (!$webhook && !ApiSecurity::isAdmin($user, $write)) {
        if ($method === 'POST' && ApiSecurity::isWebhook($data)) {
            // Authentication failures occur before setup/queue creation. Log only
            // validated routing metadata, never the request body or credentials.
            MyLog()->Append([
                'webhook_rejected',
                'reason' => ApiSecurity::webhookRejectionReason($data, $_SERVER, $_GET, is_array($config) ? $config : []),
                'entity' => $data->entity,
                'entityId' => (int) $data->entityId,
                'changeType' => $data->changeType,
            ], LOG_WARNING);
        }
        http_response_code(403);
        respond('unauthorized', true);
        exit;
    }
    $options = is_file('ucrm.json') ? json_decode(file_get_contents('ucrm.json')) : null;
    if (!$webhook && $method === 'POST' && !ApiSecurity::sameOrigin($_SERVER, $options->ucrmPublicUrl ?? null)) {
        http_response_code(403);
        respond('invalid_origin', true);
        exit;
    }
    if ($method === 'POST' && empty($_FILES) && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
        throw new InvalidArgumentException('json_content_type_required');
    }
    set_error_handler('myErrorHandler');
    require_once 'lib/api_setup.php';
    require_once 'lib/api_cache.php';
    $setupComplete = false;
    if (!$webhook && empty($_FILES)) {
        // The monitor endpoint only reads its independent completed snapshot.
        // Polling never initializes jobs, refreshes CRM/cache, or gates writes.
        if ($method === 'POST' && is_object($data) && ($data->changeType ?? '') === 'list'
            && ($data->target ?? '') === 'crm_network_conflicts' && empty($data->entity)) {
            if (($data->action ?? 'list') !== 'list' || (isset($data->data)
                && (!is_object($data->data) || count(get_object_vars($data->data)) !== 0))) {
                throw new InvalidArgumentException('invalid_crm_monitor_request');
            }
            require_once 'lib/api_crm_network_monitor.php';
            respond('crm_network_conflicts', false, (new ApiCrmNetworkMonitor())->read());
            exit;
        }
        require_once 'lib/api_panel_recovery.php';
        require_once 'lib/api_crm_network_resolution.php';
        if (ApiCrmNetworkResolution::panelRequest($method, is_object($data) ? $data : null)) exit;
        if (ApiPanelRecovery::cleanupRequest($method, is_object($data) ? $data : null, $_GET)) exit;
        if (ApiPanelRecovery::diagnosticRequest($method, is_object($data) ? $data : null, $_GET)) exit;
    }
    run_setup();
    $setupComplete = true;
    cache_setup();
    require_once 'lib/api_router.php';
    require_once 'lib/api_backup.php';
    if (isset($_GET['download'])) {
        if ($method !== 'GET') throw new InvalidArgumentException('invalid_download_method');
        ApiBackup::download($_GET['download']);
        exit;
    }
    if (isset($_GET['sync'])) throw new InvalidArgumentException('use_authenticated_sync_action');
    if (!empty($_FILES)) {
        ApiBackup::restoreUpload($_FILES['backup'] ?? []);
        respond('backup_restored');
        exit;
    }
    if ($method === 'GET') {
        if (($_GET['page'] ?? '') !== 'panel') throw new InvalidArgumentException('page_not_found');
        header('Content-Type: text/html; charset=utf-8');
        // This file contains static HTML; read it from disk instead of caching it as PHP bytecode.
        if (readfile('public/panel_v2/index.php') === false) throw new RuntimeException('panel_read_failed');
        exit;
    }
    if (!is_object($data)) throw new InvalidArgumentException('request_empty');
    // Refresh failure does not block billing events: retain the last valid cache.
    try { cache_sync(); }
    catch (Throwable $error) { MyLog()->Append('Cache refresh failed; retained previous snapshot', LOG_ERR); }
    $api = new ApiRouter($data);
    $api->route();
    $api->http_response();
} catch (Throwable $error) {
    MyLog()->Append(['request_failed', 'type' => get_class($error), 'message' => $error->getMessage()], LOG_ERR);
    if (!headers_sent()) http_response_code($error instanceof InvalidArgumentException || $error instanceof JsonException ? 400 : 500);
    $details = $error instanceof ApiVersionMismatchException ? $error->getVersions() : [];
    if ($details) $details['runtime_cache_needs_restart'] = (bool) ($runtimeRefresh['needs_restart'] ?? false);
    if ($details) MyLog()->Append(['version_mismatch' => $details], LOG_ERR);
    if ($error instanceof ApiNetworkAddressConflictException) {
        $details = $error->getDetails();
        MyLog()->Append(['network_address_conflicts' => $details], LOG_ERR);
        // A billing webhook is not an administrative read grant, including when
        // compatibility mode explicitly accepts events without a secret.
        if ($webhook ?? false) $details = ['duplicate_groups' => $details['duplicate_groups']];
        if (!($webhook ?? false) && !($setupComplete ?? false)) {
            try {
                require_once 'lib/api_panel_recovery.php';
                if (ApiPanelRecovery::handle($method, is_object($data) ? $data : null, $_GET, $error)) exit;
            } catch (Throwable $recoveryError) {
                MyLog()->Append(['recovery_read_failed', 'type' => get_class($recoveryError)], LOG_ERR);
                http_response_code($recoveryError instanceof InvalidArgumentException ? 400 : 500);
                respond($recoveryError instanceof InvalidArgumentException ? $recoveryError->getMessage() : 'recovery_read_failed', true);
                exit;
            }
        }
    }
    $metadata = $error instanceof ApiNetworkAddressConflictException && !($webhook ?? false) && !($setupComplete ?? false)
        ? ['recovery' => ['readOnly' => true]] : [];
    respond($error instanceof InvalidArgumentException ? $error->getMessage() : 'operation_failed_check_log', true, $details, $metadata);
}
