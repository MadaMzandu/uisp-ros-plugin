<?php
include_once 'api_sqlite.php';
include_once 'api_ip.php';

/** Numeric corruption must stop its service before an unsafe RouterOS write. */
class InvalidProvisioningNumber extends InvalidArgumentException
{
    private array $details;

    public function __construct(int $serviceId, int $planId, string $field, string $reason = 'non_finite', string $stage = 'calculation', $value = null)
    {
        $this->details = ['serviceId' => $serviceId, 'planId' => $planId, 'field' => $field,
            'reason' => $reason, 'stage' => $stage, 'value' => self::safeValue($value)];
        parent::__construct('invalid_provisioning_number: service=' . $serviceId . ' plan=' . $planId . ' field=' . $field);
    }

    public function context(): array { return $this->details; }

    /** Numeric fields only: never publish arbitrary persisted strings or objects. */
    public static function safeValue($value)
    {
        if ($value === null || (is_string($value) && preg_match('/^[\x09-\x0d ]*$/D', $value))) return 'empty';
        if (is_float($value) && is_nan($value)) return 'NaN';
        if (is_numeric($value)) {
            $number = (float) $value;
            if (!is_finite($number)) return $number < 0 ? '-Infinity' : 'Infinity';
            return is_int($value) ? $value : $number;
        }
        if (is_string($value)) {
            $markers = ['inf' => 'Infinity', '+inf' => 'Infinity', 'infinity' => 'Infinity', '+infinity' => 'Infinity',
                '-inf' => '-Infinity', '-infinity' => '-Infinity', 'nan' => 'NaN'];
            if (isset($markers[strtolower(trim($value))])) return $markers[strtolower(trim($value))];
        }
        return 'non_numeric';
    }
}

class Data
{
    private const OPTIONAL_PLAN_NUMBERS = ['ratio','priorityUpload','priorityDownload','uploadOverride','downloadOverride',
        'limitUpload','limitDownload','burstUpload','burstDownload','threshUpload','threshDownload','timeUpload','timeDownload'];
    private const PLAN_NUMBERS = ['ratio','priorityUpload','priorityDownload','limitUpload','limitDownload','uploadSpeed',
        'downloadSpeed','uploadOverride','downloadOverride','burstUpload','burstDownload','threshUpload','threshDownload','timeUpload','timeDownload'];
    protected array  $service = [];
    protected array $plan = [] ;
    protected ?array $_devices = null ;
    protected ?object $_conf = null;

    public function get_data($service,$plan): array
    {
        $this->set_data($service,$plan) ;
        if (!$this->deleting() && !in_array($this->type(), ['dhcp', 'ppp', 'hotspot'], true)) {
            throw new InvalidArgumentException('Service requires a valid MAC address or username');
        }
        $data = [];
        $keys = "pool,profiles,parents,queues,accounts,dhcp6,disconn";
        foreach(explode(',',$keys) as $key){
            $item = null ;
            switch ($key){
                case 'pool': $item = $this->pool(); break ;
                case 'profiles': $item = $this->profile(); break;
                case 'parents': $item = $this->parent(); break;
                case 'queues': $item = $this->queue(); break;
                case 'accounts': $item = $this->account(); break;
                case 'dhcp6':  $item = $this->dhcp6(); break;
                case 'disconn': $item = $this->account_reset(); break;
            }
            if($item){
                $item['batch'] = $this->service['batch'] ?? null;
                $data[$key] = $item;
            }
        }
        if (!$this->deleting() && $this->type() === 'ppp' && empty($data['accounts'])) {
            throw new RuntimeException('No IPv4 address is available for the PPP service');
        }
        if (!$this->deleting() && $this->type() === 'dhcp' && empty($data['accounts']) && empty($data['dhcp6'])) {
            throw new RuntimeException('No address is available for the DHCP service');
        }
        return $data ;
    }

    public function set_data($service,$plan)
    {
        $plan = self::normalizePlanNumbers($plan);
        self::validatePlanNumbers($plan, (int) ($service['id'] ?? 0));
        $this->service = $service ;
        $this->ipapi()->beginService((int)($service['id']??0));
        $this->plan = $plan ;
        $this->_conf = null;
    }

    protected function find_service($addresses): int
    {
        $list = explode(',',$addresses);
        foreach($list as $a6){
            $a4 = preg_replace("#/\d+\s*$#", '', $a6);
            $q = 'select id from network where address=:address4 or address6=:address6';
            $id = mySqlite()->singleQuery($q, false, [':address4' => $a4, ':address6' => $a6]);
            if(!$id){ continue; }
            $q = 'select id from services where id=:id';
            $cid = myCache()->singleQuery($q, false, [':id' => $id]);
            return $cid ?  : -$id ;
        }
        return 0 ;
    }

    protected function disabled(): bool
    {
        $status = $this->service['status'] ?? 1 ;
        return in_array($status,[3,4,5,2,8]);
    }

    protected function ip($ip6 = false): ?string
    {
        $assigned = $this->find_address($ip6);
        if($assigned){ return $assigned; }
        if ($this->deleting()) return null;
        return $this->assign_address($ip6);
    }

    protected function find_address($ip6): ?string
    {
        $type = $ip6 ? 'address6' : 'address';
        $service = $this->service['id'] ?? 0 ;
        // An explicit null is UISP's current intent, not permission to revive a nested cached value.
        $fixed = array_key_exists($type,$this->service) ? $this->service[$type] : ($this->service['network'][$type] ?? null);
        if($fixed!==null && $fixed!==''){
            $length=$ip6 ? (int)($this->find_device()->pfxLength ?? 64) : 64;
            if ($this->deleting()) return ApiIP::normalize((string)$fixed, $ip6, $length);
            $this->ipapi()->set($service,$fixed,$ip6,$length);
            return $this->ipapi()->find_assigned($service, $ip6);
        }

        if ($this->deleting()) return $this->ipapi()->find_assigned($service,$ip6);
        return $this->assign_address($ip6);
    }

    protected function deleting(): bool
    {
        return in_array($this->service['action'] ?? '', ['delete', 'remove', 'queues_remove'], true);
    }

    protected function assign_address($ip6): ?string
    {
        $device = $this->find_device();
        $router_pool = filter_var($this->conf()->router_ppp_pool ?? true, FILTER_VALIDATE_BOOLEAN);
        if($this->type() == 'ppp' && !$router_pool){ $device = null; }
        $service = $this->service['id'] ?? 0 ;
        $field=$ip6 ? 'address6' : 'address';
        $reset=$this->service['_network_reset'][$field]??null;
        $address=$this->ipapi()->assign($service,$device,$ip6,$this->conf(),is_array($reset) ? $reset : null);
        if ($reset!==null && !$address) throw new RuntimeException($ip6
            ? 'No IPv6 prefix is available for the service address reset'
            : 'No IPv4 address is available for the service address reset');
        return $address;
    }

    public function poolAllocations(): array
    {
        return $this->ipapi()->allocationProofs((int)($this->service['id']??0));
    }

    protected function type(): string
    {
        $mac = $this->service['mac'] ?? null ;
        $user = $this->service['username'] ?? null ;
        $hotspot = $this->service['hotspot'] ?? null ;
        if(filter_var($mac,FILTER_VALIDATE_MAC)) return 'dhcp' ;
        $hasUser=$user!==null && (string)$user!=='';
        if($hasUser && $hotspot) return 'hotspot' ;
        if($hasUser) return 'ppp';
        return 'invalid';
    }

    protected function pool(): ?array { return null; }
    protected function account(): ?array { return null; }
    protected function dhcp6(): ?array { return null; }
    protected function account_reset(): ?array { return null; }
    protected function parent(): ?array { return null; }
    protected function queue(): ?array { return null; }
    protected function profile(): ?array { return null; }
    public function get_orphans($device): ?array { return null; }

    protected function ipapi(): ApiIP
    {
        return myIPClass();
    }


    protected function to_pair($array, $mbps = true): ?string
    {
        $str = [];
        foreach($array as $value){
            if ($value !== null && $value !== '' && (!is_numeric($value) || !is_finite((float) $value)
                || ($mbps && !is_finite((float) $value * 1000000)))) {
                $reason = !is_numeric($value) ? 'non_numeric' : (!is_finite((float) $value) ? 'non_finite' : 'calculation_non_finite');
                throw new InvalidProvisioningNumber((int) ($this->service['id'] ?? 0), (int) ($this->plan['id'] ?? 0), 'router_rate', $reason, 'router_rate', $value);
            }
            $unit = $mbps ? 'M' : null;
            if(!$value){ $value = 0; $unit = null; }
            $str[] = $value . $unit ;
        }
        return implode('/',$str);
    }

    /** Old optional blank inputs mean unset, just as the original database writer handled them. */
    public static function normalizePlanNumbers(array $row): array
    {
        foreach (self::OPTIONAL_PLAN_NUMBERS as $field) {
            foreach ([$field, '_source' . ucfirst($field)] as $key) {
                if (isset($row[$key]) && is_string($row[$key]) && preg_match('/^[\x09-\x0d ]*$/D', $row[$key])) $row[$key] = null;
            }
        }
        return $row;
    }

    private static function rawNumberIssue(string $field, $value): ?array
    {
        if ($value === null) return null;
        $reason = null;
        if (is_string($value) && preg_match('/^[\x09-\x0d ]*$/D', $value)) $reason = 'empty';
        elseif (!is_numeric($value)) $reason = 'non_numeric';
        elseif (!is_finite((float) $value)) $reason = 'non_finite';
        else {
            $number = (float) $value;
            if (in_array($field, ['ratio','priorityUpload','priorityDownload'], true)) {
                $integer = is_int($value) ? $value : (is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false);
                if ($integer === false && ($number >= (float) PHP_INT_MAX || floor($number) !== $number)) $reason = 'invalid_integer';
            }
            $minimum = in_array($field, ['burstUpload','burstDownload'], true) ? -100 :
                (in_array($field, ['priorityUpload','priorityDownload'], true) ? 1 : 0);
            $maximum = in_array($field, ['priorityUpload','priorityDownload'], true) ? 8 :
                (in_array($field, ['limitUpload','limitDownload','threshUpload','threshDownload'], true) ? 100 : null);
            if ($reason === null && ($number < $minimum || ($maximum !== null && $number > $maximum))) $reason = 'out_of_range';
        }
        return $reason === null ? null : ['field' => $field, 'reason' => $reason, 'value' => InvalidProvisioningNumber::safeValue($value)];
    }

    /** Inspect raw database columns without serializing, altering or silently resetting them. */
    public static function planNumericIssues(array $rawPlan): array
    {
        $plan = self::normalizePlanNumbers($rawPlan);
        $issues = [];
        foreach (self::PLAN_NUMBERS as $field) {
            $issue = self::rawNumberIssue($field, $plan[$field] ?? null);
            if ($issue !== null) $issues[$field] = $issue;
        }
        foreach (['Upload' => 'upload', 'Download' => 'download'] as $direction => $prefix) {
            $speedField = $prefix . 'Speed';
            $overrideField = $prefix . 'Override';
            $effectiveField = ($plan[$overrideField] ?? null) !== null ? $overrideField : $speedField;
            $speed = $plan[$effectiveField] ?? null;
            if ($speed === null || isset($issues[$effectiveField])) continue;
            $bps = (float) $speed * 1000000;
            if (!is_finite($bps)) {
                $issues[$effectiveField] = ['field' => $effectiveField, 'reason' => 'calculation_non_finite', 'value' => InvalidProvisioningNumber::safeValue($speed)];
                continue;
            }
            foreach (['limit','thresh','burst'] as $kind) {
                $field = $kind . $direction;
                $percentage = $plan[$field] ?? null;
                if ($percentage === null || isset($issues[$field]) || (float) $percentage === 0.0) continue;
                // Match the persisted SQL evaluation order, including intermediate overflow.
                $adjustment = ((float) $percentage * (float) $speed * 1000000) / 100;
                $result = $kind === 'burst' ? $bps + $adjustment : $bps - $adjustment;
                if (!is_finite($result)) $issues[$field] = ['field' => $field, 'reason' => 'calculation_non_finite', 'value' => InvalidProvisioningNumber::safeValue($percentage)];
            }
        }
        return array_values($issues);
    }

    public static function validatePlanNumbers(array $plan, int $serviceId = 0): void
    {
        $plan = self::normalizePlanNumbers($plan);
        $rawPlan = [];
        $hasSources = false;
        foreach (self::PLAN_NUMBERS as $field) {
            $sourceField = '_source' . ucfirst($field);
            if (array_key_exists($sourceField, $plan)) {
                $rawPlan[$field] = $plan[$sourceField];
                $hasSources = true;
            } elseif (!in_array($field, ['limitUpload','limitDownload','burstUpload','burstDownload','threshUpload','threshDownload'], true)) {
                $rawPlan[$field] = $plan[$field] ?? null;
            }
        }
        if ($hasSources) {
            $issues = self::planNumericIssues($rawPlan);
            if ($issues) {
                $issue = $issues[0];
                $stage = $issue['reason'] === 'calculation_non_finite' ? 'calculation' : 'source';
                throw new InvalidProvisioningNumber($serviceId, (int) ($plan['id'] ?? 0), $issue['field'], $issue['reason'], $stage, $rawPlan[$issue['field']] ?? null);
            }
        }
        foreach (self::PLAN_NUMBERS as $field) {
            $sourceField = '_source' . ucfirst($field);
            if (array_key_exists($sourceField, $plan)) {
                $issue = self::rawNumberIssue($field, $plan[$sourceField]);
                if ($issue !== null) throw new InvalidProvisioningNumber($serviceId, (int) ($plan['id'] ?? 0), $field, $issue['reason'], 'source', $plan[$sourceField]);
            }
            $value = $plan[$field] ?? null;
            // NULL is the historical unset value; builders apply the existing defaults.
            if ($value === null) continue;
            if (!is_numeric($value) || !is_finite((float) $value)) {
                $reason = !is_numeric($value) ? 'non_numeric' : (array_key_exists($sourceField, $plan) ? 'calculation_non_finite' : 'non_finite');
                throw new InvalidProvisioningNumber($serviceId, (int) ($plan['id'] ?? 0), $field, $reason, 'effective', $value);
            }
            if (in_array($field, ['uploadSpeed', 'downloadSpeed'], true) && !is_finite((float) $value * 1000000)) {
                // Normal rates are stored in Mbps. Validate the actual bps conversion too;
                // SQLite can otherwise turn subsequent Infinity-Infinity arithmetic into NULL.
                throw new InvalidProvisioningNumber($serviceId, (int) ($plan['id'] ?? 0), $field, 'calculation_non_finite', 'bps_conversion', $value);
            }
            // Percentages in this structure have already become bps; range-check their source only.
            if (in_array($field, ['ratio','priorityUpload','priorityDownload','uploadSpeed','downloadSpeed','uploadOverride','downloadOverride','timeUpload','timeDownload'], true)) {
                $issue = self::rawNumberIssue($field, $value);
                if ($issue !== null) throw new InvalidProvisioningNumber($serviceId, (int) ($plan['id'] ?? 0), $field, $issue['reason'], 'effective', $value);
            }
        }
    }

    public static function validateSnapshotNumbers($value, int $serviceId, int $planId, string $path = 'snapshot'): void
    {
        if (is_float($value) && !is_finite($value)) {
            throw new InvalidProvisioningNumber($serviceId, $planId, $path, 'non_finite', 'snapshot', $value);
        }
        if (!is_array($value) && !is_object($value)) return;
        foreach ($value as $field => $item) {
            // Report the field path only; account credentials and values stay private.
            self::validateSnapshotNumbers($item, $serviceId, $planId, $path . '.' . $field);
        }
    }

    protected function limits(): array
    {
        $keys = [
            'ratio',
            'priorityUpload',
            'priorityDownload',
            'limitUpload',
            'limitDownload',
            'uploadSpeed',
            'downloadSpeed',
            'burstUpload',
            'burstDownload',
            'threshUpload',
            'threshDownload',
            'timeUpload',
            'timeDownload',
        ];
        $values = [];
        foreach($keys as $key){
            switch ($key)
            {
                case 'priorityUpload':
                case 'priorityDownload': $values['prio'][] = $this->plan[$key]; break;
                case 'limitUpload':
                case 'limitDownload': $values['limit'][] = $this->plan[$key];break;
                case 'uploadSpeed':
                case 'downloadSpeed': $values['rate'][] = $this->plan[$key];break;
                case 'burstUpload':
                case 'burstDownload': $values['burst'][] = $this->plan[$key];break;
                case 'threshUpload':
                case 'threshDownload': $values['thresh'][] = $this->plan[$key];break;
                case 'timeUpload':
                case 'timeDownload': $values['time'][] = $this->plan[$key];break;
            }
        }
        return $values ;
    }

    protected function conf(): object
    {
        if(empty($this->_conf)){
            $this->_conf = isset($this->service['_config']) ? (object)$this->service['_config'] : $this->db()->readConfig();
            $this->_conf = $this->_conf
                ?? new stdClass();
        }
        return $this->_conf ;
    }

    protected function find_device(): ?object
    {
        if (!empty($this->service['_device'])) return (object)$this->service['_device'];
        $id = $this->service['device'] ?? 0 ;
        $device = $this->find_devices()[$id] ?? null;
        return $device ? (object) $device : null ;
    }

    protected function disabled_rate(): ?string
    {
        $rate = $this->conf()->disabled_rate ?? 0;
        if(!$rate) return null ;
        return $this->to_pair([$rate,$rate]);
    }

    protected function find_devices(): array
    {
        if(empty($this->_devices)){
            $devs = $this->db()->selectAllFromTable('devices') ?? [];
            $this->_devices = [];
            foreach ($devs as $dev){
                $this->_devices[$dev['id']] = $dev;
            }
        }
        return $this->_devices;
    }

    protected function db(): ApiSqlite
    {
        return mySqlite();
    }

    protected function dbCache(): ApiSqlite
    {
        return myCache();
    }

    protected function account_comment(): string
    {
        $id = $this->service['id'];
        return $this->service['clientId'] . " - "
            . $this->account_name() . " - "
            . $id;
    }

    protected function addr_list()
    {
        if($this->disabled()){
            return $this->conf()->disabled_list ?? null ;
        }
        return $this->conf()->active_list ?? null ;
    }

    protected function account_name(): string
    {
        $name = sprintf('Client-%s',$this->service['clientId']);
        $co = $this->service['company'];
        $fn = $this->service['firstName'];
        $ln = $this->service['lastName'];
        if($co){
            $name = $co ;
        }
        else if($fn && $ln){
            $name = sprintf('%s %s',$fn,$ln);
        }
        return $name ;
    }

}
