<?php
include_once 'mt_data.php';

/** Plan profiles and parent queues are shared; account commands remain frozen. */
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
        if ($parent !== null) $parent = self::parentMembership($parent, $service, $currentPlan);
        // Suspension bootstrap commands retain their frozen configuration and
        // account reference; they do not represent a mutable shared plan profile.
        $profile = in_array($service['status'] ?? 1,[3,4,5,2,8]) ? null : $data->profile();
        $individual = [];
        foreach ($commands as $command) {
            $path = trim($command['path'] ?? '', '/');
            if (in_array($path, ['ppp/profile', 'ip/hotspot/user/profile'], true)) {
                if (($command['_bootstrap_suspension'] ?? false)===true && ($command['action'] ?? '')!=='remove') $individual[]=$command;
                continue;
            }
            if ($path === 'queue/simple' && in_array($command['name'] ?? '', ['servicePlan-' . $planId . '-parent', 'uisp-plan-' . $planId . '-parent'], true)) continue;
            // A retry keeps the account's frozen identity and address, but its
            // shared profile must follow the current plan name on the router.
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
        return array_merge($prefix, $shared, $individual);
    }

    private static function parentMembership(array $parent, array $service, array $plan): array
    {
        Data::validatePlanNumbers($plan, (int) $service['id']);
        $destination = self::endpointIdentity((object) ($service['_device'] ?? []));
        $devices = [];
        $aliases = [];
        foreach (mySqlite()->selectAllFromTable('devices') ?? [] as $device) {
            $devices[(int) $device['id']] = $device;
            if (self::endpointIdentity((object) $device) === $destination) $aliases[] = (int) $device['id'];
        }
        $members = [];
        if ($aliases) {
            $parameters = array_merge([(int) $plan['id']], $aliases);
            $sql = 'SELECT s.id,s.status,n.address,n.address6 FROM services s LEFT JOIN network n ON s.id=n.id '
                . 'WHERE s.planId=? AND s.device IN (' . implode(',', array_fill(0, count($aliases), '?')) . ')';
            foreach ([myCache(), mySqlite()] as $index => $database) {
                foreach ($database->selectCustom($sql, $parameters) ?? [] as $member) {
                    if ($index === 0 && (in_array((int) ($member['status'] ?? 1), [0, 2, 5, 6, 8], true)
                        || myJobQueue()->isServiceBlocked((int) $member['id']))) continue;
                    self::addMember($members, $member);
                }
            }
        }
        // Actual/prepared resources can outlive a cache refresh or a router edit.
        // Include their physical destination and IPs until cleanup is confirmed.
        foreach (myJobQueue()->resourceSnapshots() as $snapshot) {
            $candidates = [$snapshot];
            if (isset($snapshot['_cleanup']) && is_array($snapshot['_cleanup'])) $candidates[] = $snapshot['_cleanup'];
            foreach ($candidates as $candidate) {
                if ((int) ($candidate['_plan']['id'] ?? $candidate['planId'] ?? 0) !== (int) $plan['id']) continue;
                $device = $candidate['_device'] ?? $devices[(int) ($candidate['device'] ?? 0)] ?? null;
                if (!$device || self::endpointIdentity((object) $device) !== $destination) continue;
                self::addMember($members, $candidate);
            }
        }
        self::addMember($members, $service);
        $targets = [];
        foreach ($members as $addresses) foreach ($addresses as $address => $ignored) $targets[$address] = true;
        ksort($targets, SORT_STRING);
        $parent['target'] = implode(',', array_keys($targets));
        $shares = (int) ceil(max(count($members), 1) / max((int) ($plan['ratio'] ?? 1), 1));
        $rates = ['uploadSpeed' => $plan['uploadSpeed'] * $shares, 'downloadSpeed' => $plan['downloadSpeed'] * $shares];
        Data::validatePlanNumbers($rates + ['id' => $plan['id']], (int) $service['id']);
        $parent['max-limit'] = sprintf('%sM/%sM', $rates['uploadSpeed'], $rates['downloadSpeed']);
        $parent['limit-at'] = $parent['max-limit'];
        return $parent;
    }

    private static function addMember(array &$members, array $snapshot): void
    {
        $id = (int) ($snapshot['id'] ?? 0);
        if ($id < 1) return;
        $members[$id] ??= [];
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
            $members[$id][$canonical] = true;
        }
    }
}
