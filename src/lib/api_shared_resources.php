<?php
include_once 'mt_data.php';

/** Shared parents and ordinary profiles are refreshed without thawing account commands. */
final class ApiSharedResources
{
    public static function lockIdentity(object $device, array $service): string
    {
        $planId = (int) ($service['_plan']['id'] ?? $service['planId'] ?? 0);
        $resource = $planId > 0 ? 'plan:' . $planId : 'service:' . (int) $service['id'];
        return self::endpointIdentity($device) . ':' . $resource;
    }

    public static function endpointIdentity(object $device): string
    {
        $host = strtolower(rtrim(trim((string) ($device->ip ?? '')), '.'));
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            $packed = inet_pton(trim($host, '[]'));
            if ($packed !== false) $host = bin2hex($packed);
        }
        $port = (int) ($device->port ?? 8728);
        return 'router:' . $host . ':' . $port;
    }

    public static function refresh(array $service, array $commands, ?array $currentPlan, array $configuration): array
    {
        $planId = (int) ($service['_plan']['id'] ?? $service['planId'] ?? 0);
        // A fallback profile or parent with a service-specific name is not shared.
        if ($planId < 1) return $commands;
        if ($currentPlan === null) throw new RuntimeException('The shared plan no longer exists; refresh its configuration before retrying');
        if ((int) ($currentPlan['id'] ?? 0) !== $planId) throw new RuntimeException('Shared resource plan identity changed');

        $currentService = $service;
        $currentService['_config'] = $configuration;
        $data = new MtData();
        $data->set_data($currentService, $currentPlan);
        // These two builders do not allocate or rewrite account addresses.
        $parent = $data->parent();
        // Suspension bootstrap commands retain their frozen configuration and
        // account reference; they do not represent a mutable shared plan profile.
        $profile = in_array($service['status'] ?? 1,[3,4,5,2,8]) ? null : $data->profile();
        $individual = [];
        $retiredProfiles = [];
        foreach ($commands as $command) {
            $path = trim($command['path'] ?? '', '/');
            if (in_array($path, ['ppp/profile', 'ip/hotspot/user/profile'], true)) {
                if (($command['_bootstrap_suspension'] ?? false)===true && ($command['action'] ?? '')!=='remove') $individual[]=$command;
                // A service override can return to the shared profile. Its exact
                // old owned profile is retired only after the account moves;
                // RouterOS still checks actual references before removing it.
                if (($command['action'] ?? '') === 'remove'
                    && ($command['_owner'] ?? '') === 'uisp-ros-plugin:service-profile:' . (int) $service['id']) $retiredProfiles[] = $command;
                continue;
            }
            if ($path === 'queue/simple' && in_array($command['name'] ?? '', ['servicePlan-' . $planId . '-parent', 'uisp-plan-' . $planId . '-parent'], true)) continue;
            // A retry keeps the account's frozen identity and address, but its
            // profile follows the current policy, including a private override.
            if ($profile !== null && ($command['action'] ?? '') !== 'remove' && in_array($path, ['ppp/secret', 'ip/hotspot/user'], true)) {
                $command['profile'] = $profile['name'];
            }
            $individual[] = $command;
        }
        $shared = [];
        foreach ([$parent, $profile] as $command) {
            if ($command === null) continue;
            $command['batch'] = $service['batch'] ?? 'service-' . (int) $service['id'];
            $shared[] = $command;
        }
        // Preserve individual values except the current shared profile reference. Pools are
        // installed before resources that may reference them, as during prepare.
        $prefix = [];
        while ($individual && trim($individual[0]['path'] ?? '', '/') === 'ipv6/pool') $prefix[] = array_shift($individual);
        return array_merge($prefix, $shared, $individual, $retiredProfiles);
    }

    /** Injected observations keep preview construction read-only and identical to execution. */
    public static function parentMembership(array $parent, array $service, array $plan,
        ?ApiSqlite $db = null, ?ApiSqlite $cache = null, ?array $resourceSnapshots = null, ?array $blocked = null): array
    {
        $plan = $plan['_serviceQosBase'] ?? $plan;
        Data::validatePlanNumbers($plan, (int) $service['id']);
        $members = [];
        if ((int) ($plan['id'] ?? 0) < 1) {
            self::addMember($members, $service, $plan, true);
            // Fallback parents have exactly this service. Keep the already
            // allocated IPv4/IPv6 target provided by their ordinary builder.
            return array_replace($parent, self::parentRates($members, 1, (int) $service['id'], (int) ($plan['id'] ?? 0)));
        }
        $db = $db ?? mySqlite();
        $cache = $cache ?? myCache();
        $devices = [];
        $aliases = [];
        foreach ($db->selectAllFromTable('devices') ?? [] as $device) {
            $devices[(int) $device['id']] = $device;
        }
        $destination = self::endpointIdentity((object) ($service['_device'] ?? $devices[(int) ($service['device'] ?? 0)] ?? []));
        foreach ($devices as $device) {
            if (self::endpointIdentity((object) $device) === $destination) $aliases[] = (int) $device['id'];
        }
        if ($aliases) {
            $parameters = array_merge([(int) $plan['id']], $aliases);
            $sql = 'SELECT s.*,n.address,n.address6 FROM services s LEFT JOIN network n ON s.id=n.id '
                . 'WHERE s.planId=? AND s.device IN (' . implode(',', array_fill(0, count($aliases), '?')) . ')';
            foreach ([$cache, $db] as $index => $database) {
                foreach ($database->selectCustom($sql, $parameters) ?? [] as $member) {
                    // Suspended services use a separate profile/cap with no
                    // contention parent; their unlimited override must not
                    // make an unrelated active parent's direction unlimited.
                    if (in_array((int) ($member['status'] ?? 1), [0, 2, 3, 4, 5, 6, 8], true)) continue;
                    if ($index === 0 && ($blocked === null ? myJobQueue()->isServiceBlocked((int) $member['id']) : isset($blocked[(int) $member['id']]))) continue;
                    self::addMember($members, $member, $plan);
                }
            }
        }
        // Actual/prepared resources can outlive a cache refresh or a router edit.
        // Include their physical destination and IPs until cleanup is confirmed.
        foreach ($resourceSnapshots ?? myJobQueue()->resourceSnapshots() as $snapshot) {
            $candidates = [$snapshot];
            if (isset($snapshot['_cleanup']) && is_array($snapshot['_cleanup'])) $candidates[] = $snapshot['_cleanup'];
            foreach ($candidates as $candidate) {
                if ((int) ($candidate['_plan']['id'] ?? $candidate['planId'] ?? 0) !== (int) $plan['id']) continue;
                $device = $candidate['_device'] ?? $devices[(int) ($candidate['device'] ?? 0)] ?? null;
                if (!$device || self::endpointIdentity((object) $device) !== $destination) continue;
                if (!self::retainsParent($candidate, (int) $plan['id'])) continue;
                self::addMember($members, $candidate, $plan);
            }
        }
        // This operation is about to replace its own old settings. Other
        // unconfirmed snapshots retain enough capacity until their own cleanup.
        self::addMember($members, $service, $plan, true);
        $targets = [];
        foreach ($members as $member) foreach ($member['addresses'] as $address => $ignored) $targets[$address] = true;
        ksort($targets, SORT_STRING);
        $parent['target'] = implode(',', array_keys($targets));
        return array_replace($parent, self::parentRates($members, (int) ($plan['ratio'] ?? 1), (int) $service['id'], (int) $plan['id']));
    }

    private static function retainsParent(array $snapshot, int $planId): bool
    {
        if (!in_array((int) ($snapshot['status'] ?? 1), [0, 2, 3, 4, 5, 6, 8], true)) return true;
        $names = ['servicePlan-' . $planId . '-parent', 'uisp-plan-' . $planId . '-parent'];
        foreach ($snapshot['_commands'] ?? [] as $command) {
            // A terminal/pending cleanup can still carry the exact old active
            // resources. Retain those until the ordinary worker confirms them.
            if (in_array($command['parent'] ?? null, $names, true)
                || in_array($command['parent-queue'] ?? null, $names, true)
                || (trim($command['path'] ?? '', '/') === 'queue/simple' && in_array($command['name'] ?? null, $names, true))) return true;
        }
        return false;
    }

    /** Pure rate calculation for already resolved, de-duplicated service members. */
    private static function parentRates(array $members, int $ratio, int $serviceId, int $planId): array
    {
        $count = max(count($members), 1);
        $shares = (int) ceil($count / max($ratio, 1));
        $rates = []; $peaks = [];
        foreach (['uploadSpeed', 'downloadSpeed'] as $field) {
            if (in_array(true, array_column($members, $field . 'Unlimited'), true)) {
                // UISP represents an enabled override without a direction
                // value as unlimited; RouterOS uses zero for the same policy.
                $rates[$field] = $peaks[$field] = 0;
                continue;
            }
            $values = array_column($members, $field);
            // Equal-rate plans retain their historical ceil(N/ratio) shares.
            // Mixed rates share that budget proportionally, but a lone larger
            // service must still be able to reach its own configured limit.
            $rates[$field] = max(max($values), array_sum($values) * $shares / $count);
            $peaks[$field] = max($rates[$field], max(array_column($members, $field . 'Peak')));
        }
        Data::validatePlanNumbers($rates + ['id' => $planId], $serviceId);
        Data::validatePlanNumbers($peaks + ['id' => $planId], $serviceId);
        return ['max-limit' => self::rateToken($peaks['uploadSpeed']) . '/' . self::rateToken($peaks['downloadSpeed']),
            'limit-at' => self::rateToken($rates['uploadSpeed']) . '/' . self::rateToken($rates['downloadSpeed'])];
    }

    private static function rateToken(float $rate): string
    {
        // RouterOS receives ordinary decimal Mbps, rounded up to a whole bit.
        return rtrim(rtrim(number_format(ceil($rate * 1000000) / 1000000, 6, '.', ''), '0'), '.') . 'M';
    }

    private static function addMember(array &$members, array $snapshot, array $plan, bool $replaceRates = false): void
    {
        $id = (int) ($snapshot['id'] ?? 0);
        if ($id < 1) return;
        $members[$id] ??= ['addresses' => []];
        $effective = ApiServiceQos::effectivePlan($snapshot, $plan);
        foreach (['uploadSpeed' => 'burstUpload', 'downloadSpeed' => 'burstDownload'] as $field => $burst) {
            $rate = (float) $effective[$field];
            $peak = max($rate, (float) ($effective[$burst] ?? 0) / 1000000);
            $unlimited = $rate === 0.0;
            $members[$id][$field . 'Unlimited'] = $replaceRates ? $unlimited
                : (($members[$id][$field . 'Unlimited'] ?? false) || $unlimited);
            $members[$id][$field] = $replaceRates ? $rate : max($members[$id][$field] ?? 0, $rate);
            $members[$id][$field . 'Peak'] = $replaceRates ? $peak : max($members[$id][$field . 'Peak'] ?? 0, $peak);
        }
        $addresses = [$snapshot['address'] ?? null, $snapshot['address6'] ?? null,
            $snapshot['network']['address'] ?? null, $snapshot['network']['address6'] ?? null];
        foreach ($snapshot['_commands'] ?? [] as $command) {
            $path = trim($command['path'] ?? '', '/');
            if (in_array($path, ['ppp/secret', 'ip/hotspot/user', 'ip/dhcp-server/lease', 'ipv6/dhcp-server/binding'], true)) {
                foreach (['remote-address', 'remote-ipv6-prefix', 'address'] as $field) $addresses[] = $command[$field] ?? null;
            } elseif ($path === 'queue/simple' && !preg_match('/^(?:servicePlan-|uisp-plan-).*parent$/D', $command['name'] ?? '')) {
                foreach (explode(',', $command['target'] ?? '') as $address) $addresses[] = $address;
            }
        }
        foreach ($addresses as $address) {
            if (!is_string($address) || $address === '') continue;
            $parts = explode('/', trim($address), 2);
            if (!filter_var($parts[0], FILTER_VALIDATE_IP)) continue;
            $ipv6 = strpos($parts[0], ':') !== false;
            if (isset($parts[1]) && (!ctype_digit($parts[1]) || (int) $parts[1] > ($ipv6 ? 128 : 32))) continue;
            $canonical = inet_ntop(inet_pton($parts[0]));
            if (isset($parts[1])) $canonical .= '/' . (int) $parts[1];
            $members[$id]['addresses'][$canonical] = true;
        }
    }
}
