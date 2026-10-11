<?php
chdir(__DIR__);
ini_set('display_errors', '0');
require_once __DIR__ . '/lib/api_runtime.php';
$runtimeRefresh = refresh_plugin_runtime();
require_once __DIR__ . '/lib/api_common.php';
set_error_handler('myErrorHandler');

try {
    require_once __DIR__ . '/vendor/autoload.php';
    require_once __DIR__ . '/lib/api_setup.php';
    require_once __DIR__ . '/lib/api_cache.php';
    require_once __DIR__ . '/lib/api_jobs.php';
    run_setup();
    try { cache_setup(); cache_sync(); }
    catch (Throwable $error) { MyLog()->Append('Scheduled cache setup deferred: ' . $error->getMessage(), LOG_ERR); }
    require_once __DIR__ . '/lib/api_configuration_apply.php';
    myJobQueue()->withAdministrativeLock(fn() => ApiConfigurationApply::drain());
    $results = (new ApiJobs())->run();
    $pending = count(array_filter($results, fn(array $result): bool => ($result['success'] ?? false) !== true));
    if ($pending) { MyLog()->Append('Scheduled router operations remain pending: ' . $pending, LOG_WARNING); }
    myJobQueue()->withAdministrativeLock(function () { include_once __DIR__ . '/lib/api_ip_mgmt.php'; });
} catch (Throwable $error) {
    MyLog()->Append('Scheduled operation failed: ' . $error->getMessage(), LOG_ERR);
    if ($error instanceof ApiVersionMismatchException) MyLog()->Append(['version_mismatch' => $error->getVersions()], LOG_ERR);
    exit(1);
}
