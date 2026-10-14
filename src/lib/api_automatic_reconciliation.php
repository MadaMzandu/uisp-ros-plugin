<?php

/** Small pre-worker maintenance pass. Every mutation uses the reviewed APIs' own guards and backups. */
final class ApiAutomaticReconciliation
{
    private const BUDGET = 12.0;
    private $request;
    private $clock;
    private $factory;
    private string $dataPath;
    private string $jobsPath;
    private string $legacyPath;
    private float $deadline = 0;

    public function __construct(?callable $request = null, string $dataPath = 'data/data.db',
        string $jobsPath = 'data/jobs.db', string $legacyPath = 'data/queue.json', ?callable $clock = null,
        ?callable $factory = null)
    {
        $this->request = $request; $this->dataPath = $dataPath; $this->jobsPath = $jobsPath;
        $this->legacyPath = $legacyPath; $this->clock = $clock; $this->factory = $factory;
    }

    /** Never invoke while holding a worker/service/maintenance lock. Failure must not stop jobs. */
    public function tick(): array
    {
        $result = ['repaired'=>0,'resolved'=>0,'blocked'=>0,'failed'=>0,'budget_exhausted'=>false,'busy'=>false];
        $handle = null;
        try {
            $this->deadline = $this->now() + self::BUDGET;
            $path = dirname($this->dataPath) . DIRECTORY_SEPARATOR . 'automatic-reconciliation.json';
            if (is_link($path) || (file_exists($path) && (!is_file($path) || filesize($path)>4096)))
                throw new RuntimeException('state_unavailable');
            $handle = fopen($path, 'c+b');
            if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) { $result['busy']=true; return $result; }
            if (!chmod($path,0600)) throw new RuntimeException('state_unavailable');
            $raw = stream_get_contents($handle); $state = $this->state($raw === false ? '' : $raw);
            $first = $state['next_kind']; $state['next_kind'] = $first === 'repair' ? 'recovery' : 'repair';
            $this->save($handle,$state);
            $request = function (string $path, array $parameters = []) {
                $this->budget();
                if (preg_match('~^(?:custom-attributes|clients/services(?:/[1-9][0-9]*)?|clients/[1-9][0-9]*)$~D',$path)!==1)
                    throw new RuntimeException('unsupported_read');
                $value = $this->request !== null ? ($this->request)($path,$parameters) : $this->get($path,$parameters);
                $this->budget(); return $value;
            };
            foreach ([$first,$first === 'repair' ? 'recovery' : 'repair'] as $kind) {
                if ($this->now() >= $this->deadline) { $result['budget_exhausted']=true; break; }
                try {
                    $api = $this->api($kind,$request);
                    if ($kind === 'repair') {
                        $selected = $this->repairSelection($api,$state['repair']);
                        if ($selected === null) continue;
                        $state['repair'] = ['service_id'=>$selected['serviceId'],'field'=>$selected['fields'][0]];
                        // Advance before a request: a blocked first record cannot starve later services.
                        $this->save($handle,$state); $this->budget();
                        $preview = $api->preview([$selected]); $this->budget();
                        if (!is_string($preview['previewToken'] ?? null) || count($preview['candidates'] ?? [])!==1) {
                            $result['blocked']++; continue;
                        }
                        $candidate = $preview['candidates'][0];
                        if (($candidate['service_id'] ?? null)!==$selected['serviceId']
                            || count($candidate['fields'] ?? [])!==1 || ($candidate['fields'][0]['field'] ?? null)!==$selected['fields'][0])
                            throw new RuntimeException('invalid_review');
                        $this->budget(); $applied = $api->apply($preview['previewToken']);
                        if (count($applied['repaired'] ?? [])!==1 || ($applied['repaired'][0]['service_id'] ?? null)!==$selected['serviceId']
                            || ($applied['jobs_preserved'] ?? null)!==true || ($applied['requires_sync'] ?? null)!==false)
                            throw new RuntimeException('invalid_result');
                        $result['repaired']++;
                    } else {
                        $selected = $this->recoverySelection($api,$state['recovery_source_id']);
                        if ($selected === null) continue;
                        $state['recovery_source_id']=$selected;
                        $this->save($handle,$state); $this->budget();
                        // Default resolve can prove redundant records or physical absence.
                        // Incomplete history is NEVER automatically preserved or dismissed.
                        $preview = $api->preview($selected); $this->budget();
                        $candidate = $preview['candidate'] ?? null;
                        if (!is_string($preview['previewToken'] ?? null) || !is_array($candidate)
                            || ($candidate['source_job_id'] ?? null)!==$selected
                            || !in_array($candidate['resolution'] ?? null,['redundant_current_resources','observed_absent_resources'],true)) {
                            $result['blocked']++; continue;
                        }
                        $this->budget(); $applied=$api->apply($preview['previewToken']);
                        if (count($applied['resolved'] ?? [])!==1 || ($applied['resolved'][0]['source_job_id'] ?? null)!==$selected
                            || ($applied['router_changes'] ?? null)!==false || ($applied['crm_changes'] ?? null)!==false
                            || !in_array($applied['resolution'] ?? null,['redundant_current_resources','observed_absent_resources'],true))
                            throw new RuntimeException('invalid_result');
                        $result['resolved']++;
                    }
                } catch (Throwable $ignored) { $result['failed']++; }
            }
            if ($this->now() >= $this->deadline) $result['budget_exhausted']=true;
        } catch (Throwable $ignored) { $result['failed']++; }
        finally { if (is_resource($handle)) { flock($handle,LOCK_UN); fclose($handle); } }
        return $result;
    }

    private function api(string $kind, callable $request)
    {
        if ($this->factory !== null) return ($this->factory)($kind,$request,$this->clock,$this->deadline);
        if ($kind === 'repair') {
            require_once __DIR__ . '/api_network_repair.php';
            return new ApiNetworkRepair($request,$this->dataPath,$this->jobsPath,$this->legacyPath,$this->clock);
        }
        require_once __DIR__ . '/api_job_recovery.php';
        return new ApiJobRecovery($request,$this->jobsPath,$this->dataPath,$this->legacyPath,$this->clock,null,null,$this->deadline);
    }

    private function repairSelection($api, array $cursor): ?array
    {
        foreach ([$cursor['service_id'],0] as $from) {
            $this->budget(); $report=$api->listing($from); $this->budget(); $available=[];
            foreach ($report['rows'] ?? [] as $row) {
                $sid=$row['service_id'] ?? null;
                if (!is_int($sid) || $sid<1) throw new RuntimeException('invalid_inventory');
                foreach ($row['fields'] ?? [] as $entry) {
                    $field=$entry['field'] ?? null;
                    if (!in_array($field,['address','address6'],true)) throw new RuntimeException('invalid_inventory');
                    if ($from!==0 && ($sid<$cursor['service_id'] || ($sid===$cursor['service_id'] && strcmp($field,$cursor['field'])<=0))) continue;
                    $available[]=['serviceId'=>$sid,'fields'=>[$field]];
                }
            }
            usort($available,static fn($a,$b): int => ($a['serviceId']<=>$b['serviceId']) ?: strcmp($a['fields'][0],$b['fields'][0]));
            if ($available) return $available[0];
            if ($from===0) break;
        }
        return null;
    }

    private function recoverySelection($api, int $cursor): ?int
    {
        foreach ([$cursor,0] as $from) {
            $this->budget(); $report=$api->listing($from); $this->budget();
            if (($report['available'] ?? null)!==true) throw new RuntimeException('inventory_unavailable');
            $available=[];
            foreach ($report['groups'] ?? [] as $row) {
                $source=$row['source_job_id'] ?? null;
                if (!is_int($source) || $source<1) throw new RuntimeException('invalid_inventory');
                if ($from===0 || $source>$cursor) $available[]=$source;
            }
            if ($available) return min($available);
            if ($from===0) break;
        }
        return null;
    }

    /** Same configured SDK/TLS/redirect policy, with a smaller per-read timeout. GET only. */
    private function get(string $path, array $parameters)
    {
        require_once __DIR__ . '/api_uisp_client.php';
        $api=ApiUispClient::crm(); $remaining=$this->deadline-$this->now();
        if ($remaining<0.05) throw new RuntimeException('deadline');
        if (class_exists(\GuzzleHttp\Client::class)) {
            $property=new ReflectionProperty($api,'client'); if (PHP_VERSION_ID<80100) $property->setAccessible(true);
            $client=$property->getValue($api);
            if (!$client instanceof \GuzzleHttp\Client) throw new RuntimeException('unsupported_transport');
            $options=$client->getConfig(); $options['timeout']=min(2.0,$remaining);
            $options['connect_timeout']=min(1.0,$remaining); $options['read_timeout']=min(2.0,$remaining);
            $property->setValue($api,new \GuzzleHttp\Client($options));
        }
        return $api->get($path,$parameters);
    }

    private function state(string $raw): array
    {
        $empty=['version'=>1,'next_kind'=>'repair','repair'=>['service_id'=>0,'field'=>'address6'],'recovery_source_id'=>0];
        if (trim($raw)==='') return $empty;
        try { $value=json_decode($raw,true,8,JSON_THROW_ON_ERROR); }
        catch (Throwable $ignored) { return $empty; }
        if (!is_array($value) || ($value['version'] ?? null)!==1 || !in_array($value['next_kind'] ?? null,['repair','recovery'],true)
            || !is_int($value['repair']['service_id'] ?? null) || $value['repair']['service_id']<0
            || !in_array($value['repair']['field'] ?? null,['address','address6'],true)
            || !is_int($value['recovery_source_id'] ?? null) || $value['recovery_source_id']<0) return $empty;
        return ['version'=>1,'next_kind'=>$value['next_kind'],
            'repair'=>['service_id'=>$value['repair']['service_id'],'field'=>$value['repair']['field']],
            'recovery_source_id'=>$value['recovery_source_id']];
    }
    private function save($handle, array $state): void
    {
        $json=json_encode($state,JSON_THROW_ON_ERROR); rewind($handle);
        if (!ftruncate($handle,0) || fwrite($handle,$json)!==strlen($json) || !fflush($handle)) throw new RuntimeException('state_unavailable');
    }
    private function budget(): void { if ($this->now()>=$this->deadline) throw new RuntimeException('deadline'); }
    private function now(): float
    {
        $value=$this->clock===null ? microtime(true) : (float)($this->clock)();
        if (!is_finite($value) || $value<0) throw new RuntimeException('invalid_clock'); return $value;
    }
}
