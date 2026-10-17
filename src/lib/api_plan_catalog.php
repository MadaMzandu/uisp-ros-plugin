<?php

/** Recover CRM metadata without replacing locally configured shaping overrides. */
final class ApiPlanCatalog
{
    public static function needsHydration(?array $plan): bool
    {
        $name = $plan['name'] ?? null;
        if (!is_string($name) || trim($name) === '' || preg_match('/^(?:__)?uisp-plan-[0-9]+(?: \[UISP#[0-9]+\])?$/D', trim($name))) return true;
        foreach (['upload','download'] as $direction) {
            if (($plan[$direction . 'Speed'] ?? null) === null
                && !self::validSpeed($plan[$direction . 'Override'] ?? null)) return true;
        }
        return false;
    }

    public static function ensure(int $id): bool
    {
        if ($id < 1) return false;
        $stored = mySqlite()->singleQuery('SELECT * FROM plans WHERE id=?', true, [$id]);
        if (!self::needsHydration($stored ?: null)) return false;
        $plan = self::fetch($id);
        self::save([$plan]);
        return true;
    }

    public static function fetch(int $id): array
    {
        if ($id < 1) throw new InvalidArgumentException('invalid_service_plan_id');
        try {
            require_once __DIR__ . '/api_ucrm.php';
            $response = (new ApiUcrm(null, true))->get('service-plans/' . $id);
        } catch (Throwable $error) {
            self::logFailure($id, 'request_failed', 'request');
            throw new InvalidArgumentException('service_plan_metadata_unavailable: plan=' . $id, 0, $error);
        }
        try {
            return self::normalize($response, $id);
        } catch (InvalidArgumentException $error) {
            throw new InvalidArgumentException('service_plan_metadata_unavailable: plan=' . $id, 0, $error);
        }
    }

    public static function normalize($item, ?int $expectedId = null): array
    {
        if (!is_array($item)) self::reject($expectedId, 'response_not_object', 'response');
        $id = filter_var($item['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) self::reject($expectedId, 'invalid_id', 'id');
        if ($expectedId !== null && $id !== $expectedId) self::reject($expectedId, 'id_mismatch', 'id');
        if (!is_string($item['name'] ?? null) || trim($item['name']) === '') self::reject($id, 'missing_name', 'name');
        // CRM responses use "Internet"; the collection filter uses "internet".
        // Accept either spelling without allowing unrelated or non-string types.
        if (array_key_exists('servicePlanType', $item) && (!is_string($item['servicePlanType'])
            || strcasecmp(trim($item['servicePlanType']), 'internet') !== 0))
            self::reject($id, 'unsupported_type', 'servicePlanType');
        foreach (['uploadSpeed', 'downloadSpeed'] as $field)
            if (!self::validSpeed($item[$field] ?? null)) self::reject($id, 'invalid_speed', $field);
        return ['id' => $id, 'name' => $item['name'], 'uploadSpeed' => $item['uploadSpeed'],
            'downloadSpeed' => $item['downloadSpeed']];
    }

    private static function reject(?int $id, string $reason, string $field): void
    {
        self::logFailure($id, $reason, $field);
        throw new InvalidArgumentException('invalid_service_plan_metadata');
    }

    private static function logFailure(?int $id, string $reason, string $field): void
    {
        // Only controlled identifiers/codes are logged, never API bodies or exception messages.
        // Diagnostics must not change provisioning or catalogue retention when logging fails.
        try {
            MyLog()->Append(['event' => 'plan_metadata_failed', 'planId' => $id,
                'reason' => $reason, 'field' => $field], LOG_WARNING);
        } catch (Throwable $ignored) {}
    }

    public static function save(array $plans): void
    {
        $db = mySqlite();
        $db->transaction(function () use ($db, $plans): void {
            foreach ($plans as $plan) {
                // Never import CRM fields into percentages, bursts, overrides or priorities.
                $row = array_intersect_key($plan, array_flip(['id','name','uploadSpeed','downloadSpeed']));
                $row['last'] = date('c');
                if (!$db->singleQuery('SELECT id FROM plans WHERE id=?', false, [$row['id']])) $row['created'] = $row['last'];
                $db->edit($row, 'plans');
            }
        });
    }

    private static function validSpeed($value): bool
    {
        return (is_int($value) || is_float($value) || (is_string($value) && trim($value) !== '' && is_numeric($value)))
            && is_finite((float) $value) && (float) $value >= 0 && is_finite((float) $value * 1000000);
    }
}
