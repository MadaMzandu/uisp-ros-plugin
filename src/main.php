<?php
chdir(__DIR__);
ini_set('display_errors', '0');
require_once __DIR__ . '/lib/api_runtime.php';
$runtimeRefresh = refresh_plugin_runtime();
require_once __DIR__ . '/lib/api_full_backup.php';
try { ApiFullBackup::recoverPending(); }
catch (Throwable $error) { error_log('Full backup recovery required before scheduled work'); exit(1); }
require_once __DIR__ . '/lib/api_automation_health.php';
// Only the scheduled entry point records this heartbeat. Manual queue actions do not.
$automationHealthToken = ApiAutomationHealth::schedulerStarted();
$automationHealthFinished = false;
$automationHealthErrorCode = null;
register_shutdown_function(static function () use (&$automationHealthFinished, $automationHealthToken): void {
    if (!$automationHealthFinished) ApiAutomationHealth::schedulerFinished($automationHealthToken, false, 'runtime_failure');
});
require_once __DIR__ . '/lib/api_common.php';
set_error_handler('myErrorHandler');

try {
    require_once __DIR__ . '/vendor/autoload.php';
    require_once __DIR__ . '/lib/api_setup.php';
    require_once __DIR__ . '/lib/api_cache.php';
    require_once __DIR__ . '/lib/api_jobs.php';
    run_setup();
    try { cache_setup(); cache_sync(); }
    catch (Throwable $error) {
        $automationHealthErrorCode = 'cache_refresh_failed';
        MyLog()->Append('Scheduled cache setup deferred: ' . $error->getMessage(), LOG_ERR);
    }
    // Repair proven malformed local reservations before any worker owns a job.
    // The bounded pass takes its own review/backup guards and cannot abort jobs.
    try {
        require_once __DIR__ . '/lib/api_automatic_reconciliation.php';
        $automatic = (new ApiAutomaticReconciliation())->tick();
        if ($automatic['repaired'] || $automatic['resolved']) MyLog()->Append([
            'event'=>'automatic_reconciliation_result','repaired'=>$automatic['repaired'],'resolved'=>$automatic['resolved']], LOG_INFO);
    } catch (Throwable $ignored) { MyLog()->Append(['event'=>'automatic_reconciliation_failed','errorCode'=>'check_unavailable'],LOG_WARNING); }
    require_once __DIR__ . '/lib/api_configuration_apply.php';
    myJobQueue()->withAdministrativeLock(fn() => ApiConfigurationApply::drain());
    $results = (new ApiJobs())->run();
    $pending = count(array_filter($results, fn(array $result): bool => ($result['success'] ?? false) !== true));
    if ($pending) { MyLog()->Append('Scheduled router operations remain pending: ' . $pending, LOG_WARNING); }
    // Fresh-state review and drift detection enqueue only verified current intent.
    // Their independent failures never prevent the remaining scheduled tasks.
    try {
        require_once __DIR__.'/lib/api_webhook_freshness.php';
        ApiWebhookFreshness::tick();
    } catch (Throwable $ignored) { MyLog()->Append(['event'=>'fresh_state_review_failed','errorCode'=>'review_unavailable'],LOG_WARNING); }
    try {
        require_once __DIR__.'/lib/api_service_reconciliation.php';
        (new ApiServiceReconciliation())->tick();
    } catch (Throwable $ignored) { MyLog()->Append(['event'=>'service_reconciliation_failed','errorCode'=>'review_unavailable'],LOG_WARNING); }
    // Observe CRM only after automatic jobs and outside their maintenance gate.
    // The monitor has its own bounded scan and lock; errors are informational.
    try {
        require_once __DIR__ . '/lib/api_crm_network_monitor.php';
        (new ApiCrmNetworkMonitor())->tick();
    } catch (Throwable $monitorError) {
        MyLog()->Append(['event' => 'crm_network_monitor_failed', 'errorCode' => 'monitor_unavailable'], LOG_WARNING);
    }
    myJobQueue()->withAdministrativeLock(function () { include_once __DIR__ . '/lib/api_ip_mgmt.php'; });
    ApiAutomationHealth::schedulerFinished($automationHealthToken, $automationHealthErrorCode === null, $automationHealthErrorCode);
    $automationHealthFinished = true;
} catch (Throwable $error) {
    ApiAutomationHealth::schedulerFinished($automationHealthToken, false,
        $error instanceof ApiVersionMismatchException ? 'version_mismatch' : 'scheduled_operation_failed');
    $automationHealthFinished = true;
    MyLog()->Append('Scheduled operation failed: ' . $error->getMessage(), LOG_ERR);
    if ($error instanceof ApiVersionMismatchException) MyLog()->Append(['version_mismatch' => $error->getVersions()], LOG_ERR);
    exit(1);
}
