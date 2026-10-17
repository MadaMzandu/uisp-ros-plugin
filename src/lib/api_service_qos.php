<?php

/** Resolve service shaping independently of the shared commercial plan. */
class ApiServiceQos
{
    public static function enabled(array $service): bool
    {
        $flag = $service['trafficShapingOverrideEnabled'] ?? null;
        if (!in_array($flag, [null, true, false, 1, 0, '1', '0'], true))
            throw new InvalidArgumentException('invalid_service_shaping_override');
        return in_array($flag, [true, 1, '1'], true);
    }

    public static function isOverridden(array $plan): bool
    {
        return ($plan['_serviceQos']['enabled'] ?? false) === true;
    }

    /**
     * Catalog plans already contain Mbps normal rates and bps burst/guaranteed
     * rates. Keep the untouched prepared base so repeated builds never compound
     * an override, and disabling it returns to the current shared policy.
     */
    public static function effectivePlan(array $service, array $plan): array
    {
        if (isset($plan['_serviceQosBase']) && is_array($plan['_serviceQosBase'])) $plan = $plan['_serviceQosBase'];
        unset($plan['_serviceQos'], $plan['_serviceQosBase']);
        if (!self::enabled($service)) return $plan;
        // An explicit NULL is a supported unlimited direction; an omitted
        // property is incomplete evidence and must never silently remove a cap.
        foreach (['uploadSpeedOverride', 'downloadSpeedOverride'] as $field)
            if (!array_key_exists($field, $service)) throw new InvalidArgumentException('invalid_service_shaping_override');

        $serviceId = (int)($service['id'] ?? 0);
        $planId = (int)($plan['id'] ?? $service['planId'] ?? 0);
        if ($serviceId < 1) throw new InvalidArgumentException('A service ID is required for a traffic shaping override');
        $base = $plan;
        $details = ['enabled' => true, 'serviceId' => $serviceId];
        foreach (['Upload' => 'upload', 'Download' => 'download'] as $direction => $prefix) {
            $field = $prefix . 'Speed';
            $overrideField = $field . 'Override';
            // UISP explicitly defines a NULL override speed as unlimited;
            // service uploadSpeed/downloadSpeed still describe the base plan.
            $override = $service[$overrideField] ?? null;
            $speed = $override === null ? 0 : $override;
            self::validateSpeed($speed, $serviceId, $planId, $overrideField);
            $plan[$field] = 0 + $speed;
            $details[$field] = $plan[$field];
            $details[$prefix . 'Unlimited'] = (float)$speed === 0.0;
            foreach (['limit', 'burst', 'thresh'] as $kind) {
                $rateField = $kind . $direction;
                if ((float)$speed === 0.0) { $plan[$rateField] = 0; continue; }
                $source = '_source' . ucfirst($rateField);
                if (array_key_exists($source, $base)) {
                    $percentage = $base[$source];
                    if ($percentage === null || (is_string($percentage) && preg_match('/^[\x09-\x0d ]*$/D', $percentage))
                        || (is_numeric($percentage) && (float)$percentage === 0.0)) {
                        $plan[$rateField] = 0;
                        continue;
                    }
                    if (!is_numeric($percentage) || !is_finite((float)$percentage))
                        throw new InvalidProvisioningNumber($serviceId, $planId, $rateField, 'non_numeric', 'source', $percentage);
                    $bps = (float)$speed * 1000000;
                    $adjustment = (float)$percentage * (float)$speed * 1000000 / 100;
                    $plan[$rateField] = $kind === 'burst' ? $bps + $adjustment : $bps - $adjustment;
                } else {
                    // Historical prepared jobs may predate source percentages.
                    // Scale their already computed rates once from the saved base.
                    $oldRate = $base[$rateField] ?? 0;
                    $oldSpeed = $base[$field] ?? 0;
                    $plan[$rateField] = (is_numeric($oldSpeed) && (float)$oldSpeed > 0 && is_numeric($oldRate))
                        ? (float)$oldRate * ((float)$speed / (float)$oldSpeed) : $oldRate;
                }
                if (!is_numeric($plan[$rateField]) || !is_finite((float)$plan[$rateField]))
                    throw new InvalidProvisioningNumber($serviceId, $planId, $rateField, 'calculation_non_finite', 'service_override', $plan[$rateField]);
            }
        }
        $plan['_serviceQos'] = $details;
        $plan['_serviceQosBase'] = $base;
        return $plan;
    }

    private static function validateSpeed($value, int $serviceId, int $planId, string $field): void
    {
        if ($value === null) return;
        $reason = !is_numeric($value) ? 'non_numeric' : (!is_finite((float)$value) ? 'non_finite'
            : ((float)$value < 0 ? 'out_of_range' : (!is_finite((float)$value * 1000000) ? 'calculation_non_finite' : null)));
        if ($reason !== null) throw new InvalidProvisioningNumber($serviceId, $planId, $field, $reason, 'service_override', $value);
    }
}
