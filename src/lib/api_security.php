<?php

class ApiSecurity
{
    public static function isAdmin($user, bool $write = false): bool
    {
        if (!is_object($user) || !isset($user->isClient) || $user->isClient) return false;
        $permissions = (array) ($user->permissions ?? []);
        return in_array($permissions['system/plugins'] ?? 'denied', $write ? ['edit'] : ['view', 'edit'], true);
    }

    public static function isWebhook($data): bool
    {
        if (!is_object($data) || !in_array($data->entity ?? null, ['service', 'client'], true)) return false;
        if (!in_array($data->changeType ?? null, ['insert', 'edit', 'delete', 'suspend', 'unsuspend', 'suspend_cancel', 'end', 'activate'], true)) return false;
        if (!is_int($data->entityId ?? null) && !is_string($data->entityId ?? null)) return false;
        return is_string($data->uuid ?? null) && preg_match('/^[a-zA-Z0-9-]{16,128}$/D', $data->uuid)
            && filter_var($data->entityId ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
    }

    public static function webhookAuthorized($data, array $server, array $query, array $config): bool
    {
        return self::webhookRejectionReason($data, $server, $query, $config) === null;
    }

    public static function webhookRejectionReason($data, array $server, array $query, array $config): ?string
    {
        if (!self::isWebhook($data)) return 'invalid_webhook';
        $allowWithoutSecret = $config['webhookAllowWithoutSecret'] ?? false;
        if ((is_bool($allowWithoutSecret) || is_int($allowWithoutSecret) || is_string($allowWithoutSecret))
            && filter_var($allowWithoutSecret, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true) return null;
        $secret = $config['webhookSecret'] ?? '';
        if (is_string($secret) && preg_match('/^https?:\/\//i', trim($secret))) return 'webhook_secret_contains_url';
        if (!is_string($secret) || strlen($secret) < 32) return 'webhook_secret_not_configured';
        $provided = $server['HTTP_X_WEBHOOK_TOKEN'] ?? $query['webhookToken'] ?? '';
        $authorization = $server['HTTP_AUTHORIZATION'] ?? null;
        if (is_string($authorization) && preg_match('/^Bearer (.+)$/D', $authorization, $match)) $provided = $match[1];
        if ($provided === '') return 'webhook_token_missing';
        return is_string($provided) && hash_equals($secret, $provided) ? null : 'webhook_token_mismatch';
    }

    public static function sameOrigin(array $server, ?string $publicUrl = null): bool
    {
        // Browsers supply Origin for state-changing requests; webhooks use their secret instead.
        $origin = $server['HTTP_ORIGIN'] ?? null;
        if (!is_string($origin) || $origin === '') return false;
        $host = $server['HTTP_HOST'] ?? '';
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['host'])) return false;
        $expected = $publicUrl ? parse_url($publicUrl) : null;
        $scheme = $expected['scheme'] ?? ((!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') || ($server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http');
        if (($parts['scheme'] ?? '') !== $scheme) return false;
        $originHost = strtolower($parts['host']) . ':' . ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (isset($expected['host'])) $host = strtolower($expected['host']) . ':' . ($expected['port'] ?? ($scheme === 'https' ? 443 : 80));
        elseif (!preg_match('/:\d+$/D', $host)) $host .= ':' . ($scheme === 'https' ? 443 : 80);
        return $host !== '' && hash_equals(strtolower($host), $originHost);
    }
}
