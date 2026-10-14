<?php
require_once __DIR__ . '/api_network_address_evidence.php';

/** Exact, explicit review of one ended service's historical address. */
final class ApiCrmNetworkHistory
{
    public static function markerPath(int $id, string $field, string $directory = 'data'): string
    {
        if ($id < 1 || !in_array($field, ['address', 'address6'], true)) throw new InvalidArgumentException('invalid_crm_resolution_history');
        return $directory . DIRECTORY_SEPARATOR . 'crm-network-history-' . $id . '-' . $field . '.json';
    }

    public static function fingerprint(array $service, array $descriptor, string $field): string
    {
        self::markerPath(self::id($service['id'] ?? null) ?? 0, $field);
        $attributes = [];
        foreach ($service['attributes'] ?? [] as $attribute) {
            $attribute = (array) $attribute;
            if (($attribute['key'] ?? null) === ($descriptor['key'] ?? null)
                || self::id($attribute['customAttributeId'] ?? null) === ($descriptor['id'] ?? null)) {
                $attributes[] = ['key' => $attribute['key'] ?? null, 'customAttributeId' => $attribute['customAttributeId'] ?? null,
                    'value' => $attribute['value'] ?? null, 'value_present' => array_key_exists('value', $attribute)];
            }
        }
        return hash('sha256', json_encode(['id' => self::id($service['id']), 'client_id' => self::id($service['clientId'] ?? null),
            'status' => $service['status'] ?? null, 'mapping' => $descriptor, 'attributes' => $attributes], JSON_THROW_ON_ERROR));
    }

    public static function reviewed(array $service, array $descriptor, string $field, string $directory = 'data'): bool
    {
        try {
            $id = self::id($service['id'] ?? null); $client = self::id($service['clientId'] ?? null);
            if ($id === null || $client === null || !in_array($service['status'] ?? null, [2, '2'], true)
                || !is_string($descriptor['key'] ?? null) || self::id($descriptor['id'] ?? null) === null) return false;
            $mapping = ['address' => null, 'address6' => null]; $mapping[$field] = $descriptor;
            $evidence = ApiNetworkAddressEvidence::parse($service, $mapping)[$field];
            if (!$evidence['known'] || $evidence['value'] === null) return false;
            $path = self::markerPath($id, $field, $directory);
            if (is_link($path) || !is_file($path) || filesize($path) > 2048) return false;
            $marker = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
            return is_array($marker) && ($marker['service_id'] ?? null) === $id && ($marker['client_id'] ?? null) === $client
                && ($marker['status'] ?? null) === 2 && ($marker['field'] ?? null) === $field
                && ($marker['attribute'] ?? null) === $descriptor && ($marker['value'] ?? null) === $evidence['value']
                && is_int($marker['created_at'] ?? null) && $marker['created_at'] >= 0
                && is_string($marker['fingerprint'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $marker['fingerprint']) === 1
                && hash_equals($marker['fingerprint'], self::fingerprint($service, $descriptor, $field));
        } catch (Throwable $ignored) { return false; }
    }

    private static function id($value): ?int
    {
        if (!is_int($value) && !is_string($value)) return null;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }
}
