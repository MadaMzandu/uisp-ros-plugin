<?php
require_once __DIR__ . '/api_setup.php';
require_once __DIR__ . '/api_network_diagnostics.php';

/** Administrative consultation while the live database cannot complete migration. */
final class ApiPanelRecovery
{
    public static function diagnosticRequest(string $method, ?object $request, array $query): bool
    {
        $download = $method === 'GET' && ($query['download'] ?? '') === 'network-conflicts';
        $list = $method === 'POST' && ($request->changeType ?? '') === 'list'
            && ($request->target ?? '') === 'network_conflicts' && empty($request->entity);
        if (!$download && !$list) return false;
        if ($list && ($request->action ?? 'list') !== 'list') throw new InvalidArgumentException('invalid_diagnostic_action');
        $details = ['duplicate_groups' => 0, 'conflicts' => [], 'conflicts_truncated' => false];
        if (is_file('data/data.db')) {
            $setup = new ApiSetup();
            $setup->assertCompatible();
            try { $setup->assertNetworkAddressesUnique(); }
            catch (ApiNetworkAddressConflictException $error) { $details = $error->getDetails(); }
        }
        $data = $request->data ?? new stdClass();
        if (!is_object($data)) throw new InvalidArgumentException('invalid_diagnostic_data');
        $refresh = $list && ($data->refresh ?? false) === true;
        $ids = $data->serviceIds ?? [];
        if (!is_array($ids)) throw new InvalidArgumentException('invalid_diagnostic_service_ids');
        $details = (new ApiNetworkDiagnostics())->enrich($details, $refresh, $ids);
        http_response_code(200);
        if ($download) header('Content-Disposition: attachment; filename="ros-plugin-ip-conflicts.json"');
        respond('network_diagnostics', false, $details, ['recovery' => ['readOnly' => $details['duplicate_groups'] > 0]]);
        return true;
    }

    public static function handle(string $method, ?object $request, array $query, ApiNetworkAddressConflictException $error): bool
    {
        if ($method === 'GET' && ($query['page'] ?? '') === 'panel' && !isset($query['download'])) {
            $details = (new ApiNetworkDiagnostics())->enrich($error->getDetails());
            self::page(['readOnly' => true, 'details' => $details]);
            return true;
        }
        if ($method === 'GET' && isset($query['download'])) {
            require_once __DIR__ . '/api_backup.php';
            // The entry point has already required edit permission for every download.
            // Existing snapshots can be read without initializing jobs or migrating live data.
            http_response_code(200);
            ApiBackup::download($query['download']);
            return true;
        }
        if ($method !== 'POST' || !empty($_FILES) || !is_object($request)
            || ($request->changeType ?? '') !== 'list' || !empty($request->entity)) return false;
        require_once __DIR__ . '/api_recovery_reads.php';
        $result = (new ApiRecoveryReads())->list($request);
        http_response_code(200);
        respond('recovery_read_only', false, $result, ['recovery' => ['readOnly' => true]]);
        return true;
    }

    public static function page(array $state): void
    {
        $html = file_get_contents('public/panel_v2/index.php');
        if ($html === false) throw new RuntimeException('panel_read_failed');
        $position = strpos($html, '<body>');
        if ($position === false) throw new RuntimeException('panel_bootstrap_marker_missing');
        $json = json_encode($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        // Read the static shell directly; do not put dynamic PHP into its cached source.
        $html = substr_replace($html, '<script type="application/json" id="network-recovery-state">' . $json . '</script>', $position + 6, 0);
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
    }
}
