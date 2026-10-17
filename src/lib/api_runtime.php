<?php

/** Refresh only changed, cached PHP files belonging to this plugin. */
function refresh_plugin_runtime(?string $pluginRoot = null): array
{
    $result = ['enabled' => false, 'invalidated' => [], 'failed' => [], 'unverifiable' => [], 'needs_restart' => false];
    $configured = filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN)
        && (PHP_SAPI !== 'cli' || filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOLEAN));
    if (!function_exists('opcache_get_status') || !function_exists('opcache_invalidate')) {
        $result['needs_restart'] = $configured;
        return $result;
    }
    // A CLI process without CLI caching cannot refresh the web worker's cache.
    if (PHP_SAPI === 'cli' && !filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOLEAN)) return $result;
    try { $status = @opcache_get_status(true); }
    catch (Throwable $ignored) { $status = false; }
    if (!is_array($status) || empty($status['opcache_enabled'])) {
        $result['needs_restart'] = $configured;
        return $result;
    }
    $result['enabled'] = true;
    $root = realpath($pluginRoot ?? dirname(__DIR__));
    if ($root === false) {
        $result['failed'][] = 'plugin_root_unavailable';
        $result['needs_restart'] = true;
        return $result;
    }
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $windows = DIRECTORY_SEPARATOR === '\\';
    $rootKey = $windows ? strtolower($root) : $root;
    $ownPHP = static function (string $relative) use ($windows): bool {
        $allowed = $windows ? strtolower($relative) : $relative;
        return in_array($allowed, ['main.php', 'public.php'], true)
            || preg_match('~^(?:lib|public)/.+\.php$~', $allowed) === 1;
    };
    $seen = [];
    foreach ($status['scripts'] ?? [] as $key => $script) {
        $cachedPath = $script['full_path'] ?? $key;
        if (!is_string($cachedPath)) continue;
        $cachedNormalized = str_replace('\\', '/', $cachedPath);
        $cachedKey = $windows ? strtolower($cachedNormalized) : $cachedNormalized;
        // Most scripts in a shared web pool belong to UISP or other plugins;
        // reject their paths without touching the filesystem.
        if (strpos($cachedKey, $rootKey . '/') !== 0 || !$ownPHP(substr($cachedNormalized, strlen($root) + 1))) continue;
        $file = @realpath($cachedPath);
        if ($file === false) continue;
        $file = str_replace('\\', '/', $file);
        $fileKey = $windows ? strtolower($file) : $file;
        // Resolving first also excludes symlinks to other plugins or secrets.
        if (strpos($fileKey, $rootKey . '/') !== 0 || isset($seen[$fileKey])) continue;
        $relative = substr($file, strlen($root) + 1);
        if (!$ownPHP($relative)) continue;
        $seen[$fileKey] = true;
        $timestamp = $script['timestamp'] ?? 0;
        if (!is_numeric($timestamp) || (int) $timestamp <= 0) {
            // OPcache retains metadata for invalidated scripts until they are
            // compiled again; those entries also have timestamp zero.
            if (function_exists('opcache_is_script_cached')) {
                try { $active = @opcache_is_script_cached($cachedPath); }
                catch (Throwable $ignored) { $active = null; }
                if ($active === false) continue;
            }
            // With timestamp validation disabled there is no reliable change
            // signal. Report it rather than flushing every script per request.
            $result['unverifiable'][] = $relative;
            $result['needs_restart'] = true;
            continue;
        }
        clearstatcache(true, $file);
        $current = @filemtime($file);
        if ($current === false) {
            $result['failed'][] = $relative;
            $result['needs_restart'] = true;
            continue;
        }
        if ((int) $timestamp === $current) continue;
        try { $invalidated = @opcache_invalidate($file, true); }
        catch (Throwable $ignored) { $invalidated = false; }
        $result[$invalidated ? 'invalidated' : 'failed'][] = $relative;
        if (!$invalidated) $result['needs_restart'] = true;
    }
    return $result;
}
