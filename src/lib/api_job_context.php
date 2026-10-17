<?php

/** Persisted identity is a navigation hint, never proof that a reservation may be released. */
final class ApiJobContext
{
    private array $paths;
    private array $owners = [];
    public function __construct(string $dataPath='data/data.db',string $cachePath='data/cache.db',string $jobsPath='data/jobs.db')
    { $this->paths=[$dataPath,$cachePath,$jobsPath]; }

    public function enrich(array $jobs): array
    {
        $remaining=100;
        foreach ($jobs as &$job) {
            if (!is_array($job)) continue;
            $sid=self::id($job['serviceId']??$job['id']??null); $ids=$sid===null?[]:[$sid];
            $error=$job['error']??null;
            if (is_string($error)) {
                foreach (['/^network_address_conflict: requested_service=([1-9]\d*) blocking_service=([1-9]\d*) source=(?:reserved|cache|applied) family=IPv[46]$/D',
                    '/^invalid_network_address: requested_service=([1-9]\d*) blocking_service=([1-9]\d*) source=(?:input|reserved|applied) family=IPv[46]$/D',
                    '/^router_ownership_conflict: current_service=([1-9]\d*) previous_service=([1-9]\d*) reason=[a-z_]+$/D'] as $pattern) {
                    if (preg_match($pattern,$error,$match) && self::id($match[1])===$sid && self::id($match[2])!==null) $ids[]=self::id($match[2]);
                }
            }
            $job['relatedServices']=[];
            foreach (array_unique($ids) as $id) {
                if (!array_key_exists($id,$this->owners)) {
                    if ($remaining--<=0) continue;
                    $this->owners[$id]=$this->owner($id);
                }
                $client=$this->owners[$id];
                $job['relatedServices'][]=['serviceId'=>$id,'clientId'=>$client,'identitySource'=>$client===null?'unknown':'stored'];
            }
        }
        unset($job); return $jobs;
    }

    public function isBlockingReservation(int $serviceId): bool
    {
        $db=null;
        try {
            $path=$this->paths[2]; if (!is_file($path) || is_link($path)) return false;
            $db=new SQLite3($path,SQLITE3_OPEN_READONLY); $db->enableExceptions(true); $db->busyTimeout(200);
            if (!$this->table($db,'jobs')) return false;
            $q=$db->query("SELECT service_id,error FROM jobs WHERE status='pending' AND error IS NOT NULL LIMIT 10001"); $count=0;
            while ($row=$q->fetchArray(SQLITE3_ASSOC)) {
                if (++$count>10000) return false;
                if (is_string($row['error']) && preg_match('/^network_address_conflict: requested_service=([1-9]\d*) blocking_service=([1-9]\d*) source=(?:reserved|cache|applied) family=IPv[46]$/D',$row['error'],$match)
                    && self::id($match[1])===self::id($row['service_id']) && self::id($match[2])===$serviceId) return true;
            }
            $q->finalize(); return false;
        } catch (Throwable $ignored) { return false; }
        finally { if ($db!==null) $db->close(); }
    }

    private function owner(int $sid): ?int
    {
        $ids=[];
        foreach ($this->paths as $index=>$path) {
            $db=null;
            try {
                if (!is_file($path) || is_link($path)) continue;
                $db=new SQLite3($path,SQLITE3_OPEN_READONLY); $db->enableExceptions(true); $db->busyTimeout(200);
                if ($index<2) {
                    if (!$this->table($db,'services')) continue;
                    $columns=[]; $q=$db->query('PRAGMA table_info(services)');
                    while ($row=$q->fetchArray(SQLITE3_ASSOC)) $columns[]=$row['name']; $q->finalize();
                    if (!in_array('clientId',$columns,true) || !in_array('id',$columns,true)) continue;
                    $stmt=$db->prepare('SELECT clientId FROM services WHERE id=? LIMIT 2'); $stmt->bindValue(1,$sid,SQLITE3_INTEGER);
                    $q=$stmt->execute(); while ($row=$q->fetchArray(SQLITE3_ASSOC)) if (($client=self::id($row['clientId']))!==null) $ids[$client]=true;
                    $q->finalize(); $stmt->close();
                } else {
                    foreach (['service_versions'=>['applied_payload','service_id'],'jobs'=>['payload','service_id']] as $table=>$fields) {
                        if (!$this->table($db,$table)) continue;
                        $stmt=$db->prepare('SELECT '.$fields[0].' AS payload FROM '.$table.' WHERE '.$fields[1].'=? LIMIT 20');
                        $stmt->bindValue(1,$sid,SQLITE3_INTEGER); $q=$stmt->execute();
                        while ($row=$q->fetchArray(SQLITE3_ASSOC)) {
                            if (!is_string($row['payload']) || strlen($row['payload'])>1048576) continue;
                            $value=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
                            if (is_array($value) && self::id($value['id']??null)===$sid && ($client=self::id($value['clientId']??null))!==null) $ids[$client]=true;
                        }
                        $q->finalize(); $stmt->close();
                    }
                }
            } catch (Throwable $ignored) { return null; }
            finally { if ($db!==null) $db->close(); }
        }
        return count($ids)===1 ? (int)array_key_first($ids) : null;
    }
    private function table(SQLite3 $db,string $name): bool
    {
        $q=$db->prepare("SELECT type FROM sqlite_master WHERE name=? AND type IN ('table','view')"); $q->bindValue(1,$name);
        $r=$q->execute(); $row=$r->fetchArray(SQLITE3_ASSOC); $r->finalize(); $q->close();
        return $row && $row['type']==='table';
    }
    private static function id($value): ?int
    { if (!is_int($value) && !is_string($value)) return null; $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]); return $id===false?null:$id; }
}
