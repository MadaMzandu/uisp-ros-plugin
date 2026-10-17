<?php
require_once __DIR__ . '/api_sqlite.php';
require_once __DIR__ . '/api_job_queue.php';

/** Durable requests to apply saved settings, without overwriting billing events in flight. */
final class ApiConfigurationApply
{
    private const FIELDS = ['disable_contention','active_list','disabled_list','disabled_profile','disabled_rate',
        'lease_time','ppp_pool','router_ppp_pool'];

    /** Pool exclusions govern future allocations; they never request reapplication of existing services. */
    public static function request(array $before, array $after): bool
    {
        $changed = false;
        foreach (self::FIELDS as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) { $changed = true; break; }
        }
        if (!$changed) return false;
        $db = mySqlite();
        $ids = [];
        foreach ([$db, myCache()] as $source) {
            foreach ($source->selectCustom('SELECT id FROM services WHERE device IS NOT NULL AND status NOT IN (0,2,5,6,8)') as $service) {
                $ids[(int) $service['id']] = true;
            }
        }
        self::requestServices(array_keys($ids));
        return true;
    }

    public static function requestPlan(int $planId): void
    {
        $ids = [];
        foreach ([mySqlite(), myCache()] as $source) {
            foreach ($source->selectCustom('SELECT id FROM services WHERE planId=? AND device IS NOT NULL AND status NOT IN (0,2,5,6,8)', [$planId]) as $service) {
                $ids[(int) $service['id']] = true;
            }
        }
        self::requestServices(array_keys($ids));
    }

    private static function requestServices(array $ids): void
    {
        $db = mySqlite();
        $db->exec('CREATE TABLE IF NOT EXISTS configuration_apply (service_id INTEGER PRIMARY KEY, generation TEXT NOT NULL)');
        $generation = bin2hex(random_bytes(16));
        foreach ($ids as $id) {
            if (myJobQueue()->isServiceBlocked((int)$id)) continue;
            $db->execute('INSERT INTO configuration_apply(service_id,generation) VALUES(?,?)
                ON CONFLICT(service_id) DO UPDATE SET generation=excluded.generation', [$id, $generation]);
        }
    }

    public static function drain(int $limit = 100): array
    {
        $db = mySqlite();
        if (!$db->has_tables(['configuration_apply'])) return ['queued' => 0, 'remaining' => 0];
        require_once __DIR__ . '/batch.php';
        $queue = myJobQueue();
        $queued = 0;
        foreach ($db->selectCustom('SELECT service_id,generation FROM configuration_apply ORDER BY service_id LIMIT ?', [$limit]) as $row) {
            try {
                $queued += $queue->withServiceLock((int) $row['service_id'], function () use ($db, $row): int {
                    if (myJobQueue()->isServiceBlocked((int)$row['service_id'])) {
                        $db->execute('DELETE FROM configuration_apply WHERE service_id=? AND generation=?', [$row['service_id'], $row['generation']]);
                        return 0;
                    }
                    // Read latest billing state only while the service is exclusively gated.
                    $keys = (new Batch())->queue_accounts([(int) $row['service_id']]);
                    if (!$keys && $db->exists((int) $row['service_id'], 'services')) {
                        throw new RuntimeException('Awaiting a complete UISP service cache');
                    }
                    // A missing/deleted service has nothing left to configure. New generations remain pending.
                    $db->execute('DELETE FROM configuration_apply WHERE service_id=? AND generation=?', [$row['service_id'], $row['generation']]);
                    return count($keys);
                });
            } catch (Throwable $error) {
                $details=['configuration_application_deferred', 'service' => (int)$row['service_id']];
                if ($error instanceof InvalidProvisioningNumber) $details += $error->context();
                MyLog()->Append($details, LOG_WARNING);
            }
        }
        return ['queued' => $queued, 'remaining' => (int) $db->singleQuery('SELECT count(*) FROM configuration_apply')];
    }
}
