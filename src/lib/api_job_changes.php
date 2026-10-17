<?php

/** Stored intent compared only with the plugin's last applied journal, never live router state. */
final class ApiJobChanges
{
    private const FIELDS = ['account','site','ipv4','ipv6','profile','state','rate','burst','contention','guarantee','priority','ownership'];
    private const ACCOUNT_PATHS = ['ppp/secret','ip/hotspot/user','ip/dhcp-server/lease'];
    private const MAX_COMMANDS = 256;

    public static function summarize(array $job, ?array $previousSnapshot = null): array
    {
        $sid = self::id($job['id'] ?? null);
        if ($sid === null || self::id($previousSnapshot['id'] ?? null) !== $sid) $previousSnapshot = null;
        $operation = in_array($job['action'] ?? '', ['update','delete','queues_set','queues_remove'], true)
            ? $job['action'] : 'unknown';
        $prepared = self::commands($job) !== [];
        $before = $previousSnapshot === null ? self::unknownFields() : self::values($previousSnapshot);
        $after = self::values($job);
        if ($operation === 'delete') {
            // Historical migration cleanup targets its own frozen identity,
            // which can differ from this service's newer applied account.
            $before = self::values($job, true);
            foreach (['account','ipv4','ipv6','profile','rate','burst','contention','guarantee','priority','ownership'] as $field) $after[$field] = self::known(null);
            $after['state'] = self::known('ended');
        } elseif (in_array($operation, ['queues_remove','queues_set'], true)) {
            // Removing queues does not remove or reassign the subscriber account.
            if ($operation === 'queues_remove') foreach (['rate','burst','contention','guarantee','priority'] as $field) $after[$field] = self::known(null);
            foreach (['account','site','ipv4','ipv6','profile','state','ownership'] as $field) $after[$field] = $before[$field];
        }
        $changes = []; $different = false;
        foreach (self::FIELDS as $field) {
            if (in_array($operation, ['queues_remove','queues_set'], true)
                && !in_array($field, ['rate','burst','contention','guarantee','priority'], true)) continue;
            $old = $before[$field]; $new = $after[$field];
            if ($old['known'] && $new['known']) {
                if ($old['value'] === $new['value']) continue;
                $different = true;
            }
            $changes[] = ['field'=>$field,'beforeKnown'=>$old['known'],'before'=>$old['value'],
                'afterKnown'=>$new['known'],'after'=>$new['value']];
        }
        return ['status'=>$prepared ? 'prepared' : 'queued','reference'=>$operation === 'delete' ? 'job_target' : ($previousSnapshot === null ? 'none' : 'last_applied'),
            'prepared'=>$prepared,'operation'=>$operation,'reconnect'=>in_array($operation, ['queues_remove','queues_set'], true) ? false : self::reconnect($job),
            'changes'=>$changes,'hasDifferences'=>$different];
    }

    private static function values(array $snapshot, bool $removing = false): array
    {
        $values = self::unknownFields(); $commands = self::commands($snapshot); $prepared = $commands !== [];
        $sid = self::id($snapshot['id'] ?? null); $cid = self::id($snapshot['clientId'] ?? null);
        $mac = self::mac($snapshot['mac'] ?? null); $username = self::text($snapshot['username'] ?? null);
        if ($mac !== null) $values['account'] = self::known($mac);
        elseif ($username !== null && $username !== '') $values['account'] = self::known($username);
        $did = self::id($snapshot['device'] ?? $snapshot['_device']['id'] ?? null);
        if ($did !== null) {
            $name = self::text($snapshot['_device']['name'] ?? null, 220);
            $values['site'] = self::known(($name !== null && $name !== '' ? $name.' ' : '').'#'.$did);
        }
        if ($sid !== null && $cid !== null) $values['ownership'] = self::known('C#'.$cid.' / S#'.$sid);
        $status = self::integer($snapshot['status'] ?? null, 0, 8);
        if ($status === 1) $values['state'] = self::known('active');
        elseif (in_array($status, [3,4], true)) $values['state'] = self::known('suspended');
        elseif (in_array($status, [2,5,8], true)) $values['state'] = self::known('ended');

        $accounts = [];
        foreach ($commands as $command) {
            $path = self::path($command);
            if (!in_array($path, self::ACCOUNT_PATHS, true) || (!$removing && ($command['action'] ?? '') === 'remove')) continue;
            $candidate = $path === 'ip/dhcp-server/lease' ? self::mac($command['mac-address'] ?? null) : self::text($command['name'] ?? null);
            if ($candidate === null || $candidate === '') continue;
            if ($path === 'ip/dhcp-server/lease' && $mac !== null && $candidate !== $mac) continue;
            if ($path !== 'ip/dhcp-server/lease' && $username !== null && $candidate !== $username) continue;
            $accounts[] = $command;
        }
        $account = count($accounts) === 1 ? $accounts[0] : null;
        $path = $account === null ? null : self::path($account);
        if ($account !== null) {
            $values['account'] = self::known($path === 'ip/dhcp-server/lease' ? self::mac($account['mac-address']) : self::text($account['name']));
            $key = $path === 'ppp/secret' ? 'remote-address' : 'address';
            if (array_key_exists($key, $account)) $values['ipv4'] = self::address($account[$key], false, true);
            if ($path === 'ppp/secret' && array_key_exists('remote-ipv6-prefix', $account)) $values['ipv6'] = self::address($account['remote-ipv6-prefix'], true, true);
            if (array_key_exists('profile', $account)) $values['profile'] = self::stringValue($account['profile']);
            elseif ($path === 'ip/dhcp-server/lease') $values['profile'] = self::known(null);
            if (!$values['state']['known'] && array_key_exists('disabled', $account)) {
                $disabled = self::boolean($account['disabled']);
                if ($disabled !== null) $values['state'] = self::known($disabled ? 'disabled' : 'enabled');
            }
        }
        foreach (['ipv4'=>'address','ipv6'=>'address6'] as $field=>$key) {
            if ($values[$field]['known']) continue;
            // An unprepared NULL asks the allocator to decide; it does not clear a current IP.
            if (array_key_exists($key, $snapshot)) $values[$field] = self::address($snapshot[$key], $field === 'ipv6', $prepared);
            elseif ($prepared && is_array($snapshot['network'] ?? null) && array_key_exists($key, $snapshot['network']))
                $values[$field] = self::address($snapshot['network'][$key], $field === 'ipv6', true);
        }
        $bindings = [];
        foreach ($commands as $command) {
            if (self::path($command) !== 'ipv6/dhcp-server/binding' || (!$removing && ($command['action'] ?? '') === 'remove')) continue;
            if (isset($command['_service_id']) && self::id($command['_service_id']) !== $sid) continue;
            if (array_key_exists('address', $command)) $bindings[] = self::address($command['address'], true, true);
        }
        if (count($bindings) === 1) $values['ipv6'] = $bindings[0];
        elseif (count($bindings) > 1) $values['ipv6'] = ['known'=>false,'value'=>null];

        $shapers = []; $suspensions = [];
        foreach ($commands as $command) {
            if (!$removing && ($command['action'] ?? '') === 'remove') continue;
            $commandPath = self::path($command);
            if ($account !== null && in_array($commandPath, ['ppp/profile','ip/hotspot/user/profile'], true)
                && ($command['_bootstrap_suspension'] ?? false) !== true
                && ($command['name'] ?? null) === ($account['profile'] ?? null)
                && $commandPath === ($path === 'ppp/secret' ? 'ppp/profile' : 'ip/hotspot/user/profile')) $shapers[] = $command;
            if ($commandPath === 'queue/simple' && array_key_exists('target', $command)) {
                $owner = $command['_owner'] ?? null;
                if ($owner === null && ($mac !== null || $path === 'ip/dhcp-server/lease')) $shapers[] = $command;
                elseif ($sid !== null && $owner === 'uisp-ros-plugin:suspension:'.$sid) $suspensions[] = $command;
            }
        }
        if ($suspensions) $shapers = $suspensions;
        if (count($shapers) === 1) self::shaping($values, $shapers[0], $commands);
        if (!$prepared && !$values['rate']['known'] && self::boolean($snapshot['trafficShapingOverrideEnabled'] ?? null) === true
            && array_key_exists('uploadSpeedOverride', $snapshot) && array_key_exists('downloadSpeedOverride', $snapshot)) {
            $upload = self::mbps($snapshot['uploadSpeedOverride']); $download = self::mbps($snapshot['downloadSpeedOverride']);
            if ($upload !== null && $download !== null) $values['rate'] = self::known($upload.'/'.$download);
        }
        return $values;
    }

    private static function shaping(array &$values, array $command, array $commands): void
    {
        $parentKey = self::path($command) === 'queue/simple' ? 'parent' : 'parent-queue';
        if (array_key_exists('rate-limit', $command)) {
            $rate = self::routerRate($command['rate-limit'], 6);
            if ($rate !== null) {
                $parts = explode(' ', $rate); $values['rate'] = self::known($parts[0] ?? null);
                $values['burst'] = self::known(count($parts) > 1 ? implode(' ', array_slice($parts, 1, 3)) : null);
                if (isset($parts[4])) $values['priority'] = self::known($parts[4]);
                if (isset($parts[5])) $values['guarantee'] = self::known($parts[5]);
            }
        } elseif (array_key_exists('max-limit', $command)) {
            $rate = self::routerRate($command['max-limit'], 1);
            if ($rate !== null) $values['rate'] = self::known($rate);
            $burst = [];
            foreach (['burst-limit','burst-threshold','burst-time'] as $key) {
                if (!array_key_exists($key, $command) || ($part = self::routerRate($command[$key], 1)) === null) { $burst = []; break; }
                $burst[] = $part;
            }
            if ($burst) $values['burst'] = self::known(implode(' ', $burst));
            foreach (['limit-at'=>'guarantee','priority'=>'priority'] as $key=>$field) {
                $part = self::routerRate($command[$key] ?? null, 1);
                if ($part !== null) $values[$field] = self::known($part);
            }
        }
        if (array_key_exists($parentKey, $command)) {
            $parent = self::stringValue($command[$parentKey]);
            if ($parent['known'] && $parent['value'] !== null && $parent['value'] !== 'none') {
                foreach ($commands as $candidate) {
                    if (self::path($candidate) !== 'queue/simple' || ($candidate['action'] ?? '') === 'remove'
                        || ($candidate['name'] ?? null) !== $parent['value']) continue;
                    $rate = self::routerRate($candidate['max-limit'] ?? null, 1);
                    if ($rate !== null) $parent = self::known(self::text($parent['value'].' / '.$rate));
                    break;
                }
            }
            $values['contention'] = $parent;
        }
    }

    private static function reconnect(array $snapshot): ?bool
    {
        $commands = self::commands($snapshot); $seen = false; $unknown = false; $subscriberSession = false;
        foreach ($commands as $command) {
            if (in_array(self::path($command), ['ppp/secret','ip/hotspot/user'], true)) $subscriberSession = true;
            if (!in_array(self::path($command), ['ppp/active','ip/hotspot/active'], true) || ($command['action'] ?? '') !== 'remove') continue;
            $seen = true;
            if (($command['_reconnect_required'] ?? null) === true) return true;
            if (($command['_reconnect_required'] ?? null) !== false) $unknown = true;
        }
        if (($snapshot['_force_reconnect'] ?? null) === true && ($subscriberSession || self::mac($snapshot['mac'] ?? null) === null)
            && self::text($snapshot['username'] ?? null) !== null && $snapshot['username'] !== '') return true;
        if ($seen) return $unknown ? null : false;
        return $commands !== [] && !$subscriberSession && self::mac($snapshot['mac'] ?? null) !== null ? false : null;
    }

    private static function commands(array $snapshot): array
    {
        $commands = $snapshot['_commands'] ?? null;
        if (!is_array($commands) || count($commands) > self::MAX_COMMANDS) return [];
        foreach ($commands as $command) if (!is_array($command) || self::path($command) === '') return [];
        return $commands;
    }
    private static function path(array $command): string
    { return is_string($command['path'] ?? null) ? trim($command['path'], '/') : ''; }
    private static function unknownFields(): array { return array_fill_keys(self::FIELDS, ['known'=>false,'value'=>null]); }
    private static function known(?string $value): array { return ['known'=>true,'value'=>$value]; }
    private static function stringValue($value): array
    {
        if ($value === null || $value === '') return self::known(null);
        $text = self::text($value); return $text === null ? ['known'=>false,'value'=>null] : self::known($text);
    }
    private static function address($value, bool $ipv6, bool $nullable): array
    {
        if ($value === null || $value === '') return $nullable ? self::known(null) : ['known'=>false,'value'=>null];
        if (!is_string($value) || strlen($value) > 128) return ['known'=>false,'value'=>null];
        $parts = explode('/', $value); $ip = $parts[0]; $bits = $ipv6 ? 128 : 32;
        if (count($parts) > 2 || !filter_var($ip, FILTER_VALIDATE_IP, $ipv6 ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4)
            || (isset($parts[1]) && self::integer($parts[1], 0, $bits) === null)) return ['known'=>false,'value'=>null];
        $ip = (string)inet_ntop(inet_pton($ip));
        return self::known($ip.(isset($parts[1]) && (int)$parts[1] !== $bits ? '/'.(int)$parts[1] : ''));
    }
    private static function routerRate($value, int $tokens): ?string
    {
        if (!is_string($value) || strlen($value) > 240) return null;
        $value = preg_replace('/ +/', ' ', trim($value));
        $number = '[0-9]+(?:\.[0-9]+)?[kMGT]?'; $pair = $number.'(?:/'.$number.')?';
        return preg_match('~^'.$pair.'(?: '.$pair.'){0,'.($tokens - 1).'}$~D', $value) === 1 ? $value : null;
    }
    private static function mbps($value): ?string
    {
        if ($value === null) return '0';
        if (is_bool($value) || !is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 || (float)$value > 1000000000) return null;
        if ((float)$value === 0.0) return '0';
        return rtrim(rtrim(sprintf('%.6F', (float)$value), '0'), '.').'M';
    }
    private static function mac($value): ?string
    { return is_string($value) && filter_var($value, FILTER_VALIDATE_MAC) ? strtoupper($value) : null; }
    private static function boolean($value): ?bool
    {
        if (in_array($value, [true,1,'1','true','yes'], true)) return true;
        if (in_array($value, [false,0,'0','false','no'], true)) return false;
        return null;
    }
    private static function integer($value, int $min, int $max): ?int
    {
        if (!is_int($value) && !is_string($value)) return null;
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>$min,'max_range'=>$max]]);
        return $number === false ? null : $number;
    }
    private static function id($value): ?int { return self::integer($value, 1, PHP_INT_MAX); }
    private static function text($value, int $limit = 256): ?string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $value)) return null;
        if (strlen($value) <= $limit) return $value;
        $value = substr($value, 0, $limit);
        while ($value !== '' && preg_match('//u', $value) !== 1) $value = substr($value, 0, -1);
        return $value;
    }
}
