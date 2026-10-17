<?php

/** Read-only, fail-closed evidence from a complete UISP service response. */
final class ApiNetworkAddressEvidence
{
    public static function mapping(SQLite3 $database): array
    {
        $mapping = ['address' => null, 'address6' => null];
        $keys = ['ip_addr_attr' => 'address', 'ip_addr6_attr' => 'address6'];
        $seen = [];
        try {
            $result = $database->query("SELECT key,value FROM config WHERE key IN ('ip_addr_attr','ip_addr6_attr')");
            try {
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $field = $keys[$row['key']] ?? null;
                    if ($field === null) continue;
                    if (isset($seen[$field])) { $mapping[$field] = null; continue; }
                    $seen[$field] = true;
                    $mapping[$field] = self::key($row['value'] ?? null);
                }
            } finally { $result->finalize(); }
        } catch (Throwable $error) { return ['address' => null, 'address6' => null]; }
        return self::validMapping($mapping);
    }

    public static function mappingFingerprint(array $mapping): string
    {
        $canonical = [];
        foreach (['address', 'address6'] as $field) {
            $value = $mapping[$field] ?? null;
            $canonical[$field] = is_array($value) ? self::descriptor($value) : self::key($value);
        }
        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }

    /** Configured names alone cannot establish absence after a renamed attribute. */
    public static function validateMapping(array $mapping, $catalog): array
    {
        $mapping = self::validMapping($mapping);
        $valid = ['address' => null, 'address6' => null];
        if (!is_array($catalog) || ($catalog && array_keys($catalog) !== range(0, count($catalog) - 1))) return $valid;
        $keys = [];
        $ids = [];
        foreach ($catalog as $attribute) {
            if (!is_array($attribute) && !is_object($attribute)) return $valid;
            $attribute = (array) $attribute;
            $key = self::key($attribute['key'] ?? null);
            $id = self::positiveId($attribute['id'] ?? null);
            if ($key === null || $id === null) return $valid;
            $keys[$key][] = $attribute;
            $ids[$id] = ($ids[$id] ?? 0) + 1;
        }
        foreach ($mapping as $field => $key) {
            if ($key === null || count($keys[$key] ?? []) !== 1) continue;
            $attribute = $keys[$key][0];
            $id = self::positiveId($attribute['id']);
            if (($attribute['attributeType'] ?? null) === 'service' && $ids[$id] === 1) $valid[$field] = ['key' => $key, 'id' => $id];
        }
        return $valid;
    }

    /**
     * Each family is known only with a configured key and a complete attributes
     * list. In a full service GET, a present empty list (or absent key within
     * that list) explicitly has no configured address. Omitted attributes may
     * be a partial response and never establish absence. No transport or cache
     * population occurs here; callers decide when a CRM GET is authorized.
     */
    public static function parse(array $service, array $mapping): array
    {
        $mapping = ['address' => self::descriptor($mapping['address'] ?? null), 'address6' => self::descriptor($mapping['address6'] ?? null)];
        if ($mapping['address'] !== null && $mapping['address6'] !== null
            && ($mapping['address']['key'] === $mapping['address6']['key'] || $mapping['address']['id'] === $mapping['address6']['id'])) {
            $mapping = ['address' => null, 'address6' => null];
        }
        $evidence = [];
        foreach ($mapping as $field => $key) $evidence[$field] = self::unknown($key === null ? 'mapping_unknown' : 'attributes_unknown');
        if (!array_key_exists('attributes', $service) || !is_array($service['attributes'])) return $evidence;
        $attributes = $service['attributes'];
        if ($attributes && array_keys($attributes) !== range(0, count($attributes) - 1)) return $evidence;
        $values = [];
        $duplicates = [];
        foreach ($attributes as $attribute) {
            if (!is_array($attribute) && !is_object($attribute)) return $evidence;
            $attribute = (array) $attribute;
            $key = self::key($attribute['key'] ?? null);
            if ($key === null) return $evidence;
            if (array_key_exists('customAttributeId', $attribute) && self::positiveId($attribute['customAttributeId']) === null) return $evidence;
            foreach ($mapping as $field => $configured) {
                if ($configured === null) continue;
                if ($key !== $configured['key']) {
                    if (array_key_exists('customAttributeId', $attribute) && self::positiveId($attribute['customAttributeId']) === $configured['id']) {
                        $values[$field] = self::unknown('attribute_ambiguous');
                    }
                    continue;
                }
                if (array_key_exists($field, $values)) { $duplicates[$field] = true; continue; }
                if (array_key_exists('customAttributeId', $attribute) && self::positiveId($attribute['customAttributeId']) !== $configured['id']) {
                    $values[$field] = self::unknown('attribute_ambiguous');
                    continue;
                }
                // Missing value is not an explicit empty value.
                if (!array_key_exists('value', $attribute) || (!is_string($attribute['value']) && $attribute['value'] !== null)) {
                    $values[$field] = self::unknown('value_unknown');
                    continue;
                }
                try {
                    $value = $attribute['value'] === null ? null : self::normalize($attribute['value'], $field);
                    $values[$field] = ['known' => true, 'value' => $value, 'reason' => null];
                } catch (InvalidArgumentException $error) { $values[$field] = self::unknown('value_invalid'); }
            }
        }
        foreach ($mapping as $field => $key) {
            if ($key === null) continue;
            $evidence[$field] = isset($duplicates[$field]) ? self::unknown('attribute_ambiguous')
                : ($values[$field] ?? ['known' => true, 'value' => null, 'reason' => null]);
        }
        return $evidence;
    }

    /** IPv4 host syntax permits /32; IPv6 requires an explicit prefix length. */
    public static function normalize(string $value, string $field): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        $parts = explode('/', $value);
        if (count($parts) > 2) throw new InvalidArgumentException('invalid_diagnostic_address');
        if ($field === 'address') {
            if (!filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                || (isset($parts[1]) && $parts[1] !== '32')) throw new InvalidArgumentException('invalid_diagnostic_address');
            return inet_ntop(inet_pton($parts[0]));
        }
        if ($field !== 'address6' || !filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            || !isset($parts[1]) || !ctype_digit($parts[1]) || (int) $parts[1] < 1 || (int) $parts[1] > 128) {
            throw new InvalidArgumentException('invalid_diagnostic_address');
        }
        $length = (int) $parts[1];
        $binary = inet_pton($parts[0]);
        for ($index = 0; $index < 16; $index++) {
            $bits = max(0, min(8, $length - $index * 8));
            $binary[$index] = chr(ord($binary[$index]) & ($bits === 0 ? 0 : (255 << (8 - $bits))));
        }
        return inet_ntop($binary) . '/' . $length;
    }

    public static function compare($saved, array $evidence, string $field): string
    {
        if (($evidence['known'] ?? false) !== true || !is_string($saved)) return 'unknown';
        try { $saved = self::normalize($saved, $field); }
        catch (InvalidArgumentException $error) { return 'unknown'; }
        if ($saved === null) return 'unknown';
        if (($evidence['value'] ?? null) === null) return 'removed';
        return $saved === $evidence['value'] ? 'same' : 'changed';
    }

    private static function unknown(string $reason): array
    {
        return ['known' => false, 'value' => null, 'reason' => $reason];
    }

    private static function validMapping(array $mapping): array
    {
        $mapping = ['address' => self::key($mapping['address'] ?? null), 'address6' => self::key($mapping['address6'] ?? null)];
        if ($mapping['address'] !== null && $mapping['address'] === $mapping['address6']) return ['address' => null, 'address6' => null];
        return $mapping;
    }

    private static function key($value): ?string
    {
        if (!is_string($value) || $value === '' || trim($value) !== $value || strlen($value) > 255
            || preg_match('/[\x00-\x1f\x7f]/', $value) || preg_match('//u', $value) !== 1) return null;
        return $value;
    }

    private static function descriptor($value): ?array
    {
        if (!is_array($value)) return null;
        $key = self::key($value['key'] ?? null);
        $id = self::positiveId($value['id'] ?? null);
        return $key === null || $id === null ? null : ['key' => $key, 'id' => $id];
    }

    private static function positiveId($value): ?int
    {
        if (!is_int($value) && !is_string($value)) return null;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }
}
