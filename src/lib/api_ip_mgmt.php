<?php
require_once __DIR__ . '/api_ucrm.php';
require_once __DIR__ . '/api_sqlite.php';

function sync_ip_attributes(): void
{
    $lock = fopen('data/ip-sync.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) fclose($lock);
        return;
    }
    try {
        $config = is_file('data/config.json') ? json_decode(file_get_contents('data/config.json'), true) : [];
        $value = $config['syncAttrHour'] ?? null;
        if ($value === null || $value === '') return;
        $hour = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 23]]);
        if ($hour === false) throw new RuntimeException('invalid_ip_sync_hour');
        $now = new DateTimeImmutable('now', new DateTimeZone(($config['syncTimezone'] ?? '') ?: date_default_timezone_get()));
        $date = $now->format('Y-m-d');
        $db = mySqlite();
        if ((int) $now->format('G') !== $hour || ($db->readConfig()->sync_attr_date ?? $config['syncAttrDate'] ?? null) === $date) return;
        $key = $db->readConfig()->ip_addr_attr ?? null;
        if (!$key) return;
        $api = new ApiUcrm(null, true);
        $attribute = null;
        foreach ($api->get('custom-attributes') as $item) {
            if (($item['key'] ?? null) === $key && ($item['attributeType'] ?? 'service') === 'service') $attribute = $item['id'];
        }
        if (!$attribute) throw new RuntimeException('ip_attribute_missing');
        $rows = $db->selectCustom('SELECT network.id,network.address FROM network JOIN services ON network.id=services.id WHERE services.status NOT IN (0,2,5,6,8)');
        foreach ($rows as $row) {
            if (!$row['address']) continue;
            $result = $api->patch('clients/services/' . (int) $row['id'], ['attributes' => [['customAttributeId' => $attribute, 'value' => $row['address']]]]);
            if (!is_array($result) || !isset($result['id'])) throw new RuntimeException('ip_attribute_patch_failed');
        }
        // Keep runtime checkpoints out of UISP's native configuration file.
        $db->saveConfig(['sync_attr_date' => $date]);
    } catch (Throwable $error) {
        MyLog()->Append(['ip_attribute_sync_failed', 'message' => $error->getMessage()], LOG_ERR);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
sync_ip_attributes();
