<?php

/** Durable, ordered RouterOS operations. The payload is never replaced by a cache lookup. */
class ApiJobQueue
{
    private SQLite3 $db;
    private string $legacy;
    private bool $closed = false;
    private string $lockDirectory;
    /** @var array<string,array> Request-owned resources; PHP releases these even when an FPM PID survives. */
    private array $ownerLocks = [];
    private array $serviceOwners = [];
    private string $path;
    private $lifecycle = null;

    /** Server-side dependency injection; never populated from a panel or webhook payload. */
    public function setLifecycle($reviewer): void { $this->lifecycle = $reviewer; }
    public function lifecyclePaths(): array
    { return ['data'=>dirname($this->path).'/data.db','cache'=>dirname($this->path).'/cache.db','jobs'=>$this->path]; }
    public function lifecycleReviewer()
    {
        if ($this->lifecycle === null) {
            require_once __DIR__.'/api_service_lifecycle.php';
            $paths=$this->lifecyclePaths();
            $this->lifecycle=new ApiServiceLifecycle(null,$paths['data'],$paths['cache'],$paths['jobs']);
        }
        return $this->lifecycle;
    }

    public function lifecycleMeta(string $key, ?array $value=null): ?array
    {
        if (!preg_match('/^(cursor|service:[1-9][0-9]*)$/D',$key)) throw new InvalidArgumentException('lifecycle_invalid');
        $key='lifecycle:'.$key;
        if ($value!==null) $this->withAdministrativeLock(fn()=>$this->writeLifecycleMeta($key,$value));
        $rows=$this->query('SELECT value FROM queue_meta WHERE key=:key',['key'=>$key]);
        return $rows ? json_decode($rows[0]['value'],true,32,JSON_THROW_ON_ERROR) : null;
    }
    private function writeLifecycleMeta(string $key,array $value): void
    { $this->execute('INSERT INTO queue_meta(key,value) VALUES(:key,:value) ON CONFLICT(key) DO UPDATE SET value=excluded.value',['key'=>$key,'value'=>$this->encode($value)]); }

    /** Fresh retirement supersedes obsolete desired work, preserving every possible physical effect. */
    public function retireService(int $sid, array $snapshots, array $proof): array
    {
        if ($sid<1 || !$this->ownsServiceLock($sid) || ($proof['service_id']??null)!==$sid
            || ($proof['state']??null)!=='retired') throw new InvalidArgumentException('lifecycle_invalid');
        return $this->transaction(function () use ($sid,$snapshots,$proof): array {
            if ($this->query("SELECT id FROM jobs WHERE service_id=:sid AND status='running'",['sid'=>$sid]))
                throw new RuntimeException('lifecycle_service_busy');
            $existing=$this->query("SELECT id,payload,version FROM jobs WHERE service_id=:sid AND operation='delete' AND status='pending' ORDER BY version",['sid'=>$sid]);
            $keys=[]; $deleting=[];
            foreach ($existing as $row) {
                $snapshot=$this->payload($row);
                $snapshot['_terminal']=true;
                $snapshot['_lifecycle_retirement']=array_intersect_key($proof,array_flip(['service_id','client_id','reason','fingerprint','checked_at','identity_field']));
                $this->execute("UPDATE jobs SET payload=:payload,error=NULL,next_attempt=0,updated=:now WHERE id=:id AND status='pending'",['id'=>$row['id'],'payload'=>$this->encode($snapshot),'now'=>date('c')]);
                $keys[]='Q'.$row['id']; $deleting[$this->identity($snapshot)]=true;
            }
            foreach ($this->query("SELECT * FROM jobs WHERE service_id=:sid AND status='pending' AND operation IN ('update','queues_set')",['sid'=>$sid]) as $job) {
                $this->retainCancellationCleanups($job);
                $this->execute("UPDATE jobs SET status='superseded',error=NULL,next_attempt=0,updated=:now WHERE id=:id AND status='pending'",['id'=>$job['id'],'now'=>date('c')]);
            }
            // Preserved history is not immortal ownership after this service has ended.
            foreach ($this->query('SELECT * FROM retained_resources WHERE service_id=:sid AND slot<>\'source\'',['sid'=>$sid]) as $row) {
                $this->execute('INSERT OR IGNORE INTO cancellation_cleanups(source_job_id,service_id,source_version,slot,payload,identity) VALUES(:job,:sid,:version,:slot,:payload,:identity)',
                    ['job'=>$row['source_job_id'],'sid'=>$sid,'version'=>$row['source_version'],'slot'=>$row['slot'],'payload'=>$row['payload'],'identity'=>$row['identity']]);
            }
            $latestDelete=$existing?max(array_column($existing,'version')):0;
            $latestDebt=(int)$this->db->querySingle('SELECT max(source_version) FROM cancellation_cleanups WHERE service_id='.$sid);
            if ($latestDebt>=$latestDelete) $deleting=[];
            foreach ($snapshots as $snapshot) {
                if ((int)($snapshot['id']??0)!==$sid) throw new InvalidArgumentException('lifecycle_invalid');
                $snapshot['action']='delete'; $snapshot['_terminal']=true;
                $snapshot['_lifecycle_retirement']=array_intersect_key($proof,array_flip(['service_id','client_id','reason','fingerprint','checked_at','identity_field']));
                if (isset($deleting[$this->identity($snapshot)])) continue;
                $keys[]=$this->insertJob($snapshot,'delete');
            }
            if (!$keys) {
                $version=(int)$this->db->querySingle('SELECT version FROM service_versions WHERE service_id='.$sid);
                $this->execute('INSERT INTO service_tombstones(service_id,version) VALUES(:sid,:version) ON CONFLICT(service_id) DO UPDATE SET version=excluded.version',['sid'=>$sid,'version'=>$version]);
                $this->execute("UPDATE jobs SET status='cleaned',prior_cleared=1,cleanup_done=1,history_scrubbed=0 WHERE service_id=:sid AND status IN ('cancelled','superseded')",['sid'=>$sid]);
            }
            $this->writeLifecycleMeta('lifecycle:service:'.$sid,['state'=>$keys?'cleanup_pending':'retired','reason'=>$proof['reason'],'client_id'=>$proof['client_id']??null,'checked_at'=>$proof['checked_at'],'job_keys'=>$keys]);
            return $keys;
        });
    }

    public function __construct(string $path = 'data/jobs.db', ?string $legacy = 'data/queue.json')
    {
        $this->path=$path;
        $this->legacy = $legacy ?? '';
        $lockIdentity = $path === ':memory:' ? bin2hex(random_bytes(16))
            : (realpath($path) ?: (realpath(dirname($path)) ?: dirname($path)) . DIRECTORY_SEPARATOR . basename($path));
        if (PHP_OS_FAMILY === 'Windows') { $lockIdentity = strtolower($lockIdentity); }
        // Store file-backed guards beside the queue: cron and FPM may have different temporary directories.
        $this->lockDirectory = ($path === ':memory:' ? sys_get_temp_dir() : dirname($lockIdentity))
            . DIRECTORY_SEPARATOR . '.uisp-queue-locks-' . hash('sha256', $lockIdentity);
        if (!is_dir($this->lockDirectory) && !@mkdir($this->lockDirectory, 0700) && !is_dir($this->lockDirectory)) {
            throw new RuntimeException('Could not create the provisioning lock directory');
        }
        $this->db = new SQLite3($path);
        $this->db->enableExceptions(true);
        $this->db->busyTimeout(10000);
        $this->db->exec('PRAGMA synchronous=FULL');
        $this->db->exec('PRAGMA secure_delete=ON');
        $newTerminalSchema = $this->query("SELECT name FROM sqlite_master WHERE type='table' AND name='service_tombstones'") === [];
        $this->db->exec('CREATE TABLE IF NOT EXISTS jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, service_id INTEGER NOT NULL,
            operation TEXT NOT NULL, identity TEXT NOT NULL, version INTEGER NOT NULL,
            payload TEXT NOT NULL, fingerprint TEXT NOT NULL, legacy_key TEXT UNIQUE,
            status TEXT NOT NULL DEFAULT \'pending\', attempts INTEGER NOT NULL DEFAULT 0,
            owner TEXT, owner_pid INTEGER, lease_until INTEGER, error TEXT, next_attempt INTEGER NOT NULL DEFAULT 0,
            prior_cleared INTEGER NOT NULL DEFAULT 0,
            cleanup_done INTEGER NOT NULL DEFAULT 0,
            created TEXT NOT NULL, updated TEXT NOT NULL
        ); CREATE INDEX IF NOT EXISTS jobs_service_order ON jobs(service_id,version,status);
        CREATE TABLE IF NOT EXISTS service_versions (service_id INTEGER PRIMARY KEY, version INTEGER NOT NULL,
            applied_identity TEXT, applied_payload TEXT);
        CREATE TABLE IF NOT EXISTS service_tombstones (service_id INTEGER PRIMARY KEY, version INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS service_event_locks (service_id INTEGER PRIMARY KEY,
            owner TEXT NOT NULL, owner_pid INTEGER NOT NULL, lease_until INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS queue_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS webhook_fresh_reviews (service_id INTEGER PRIMARY KEY,
            attempts INTEGER NOT NULL DEFAULT 0, next_attempt INTEGER NOT NULL, last_error TEXT NOT NULL,
            received TEXT NOT NULL, updated TEXT NOT NULL)');
        $columns = array_column($this->query('PRAGMA table_info(service_versions)'), 'name');
        foreach (['applied_identity', 'applied_payload'] as $column) {
            if (!in_array($column, $columns, true)) { $this->db->exec('ALTER TABLE service_versions ADD COLUMN ' . $column . ' TEXT'); }
        }
        $jobColumns = array_column($this->query('PRAGMA table_info(jobs)'), 'name');
        if (!in_array('next_attempt', $jobColumns, true)) {
            $this->db->exec('ALTER TABLE jobs ADD COLUMN next_attempt INTEGER NOT NULL DEFAULT 0');
        }
        if (!in_array('prior_cleared', $jobColumns, true)) {
            $this->db->exec('ALTER TABLE jobs ADD COLUMN prior_cleared INTEGER NOT NULL DEFAULT 0');
        }
        if (!in_array('cleanup_done', $jobColumns, true)) {
            $this->db->exec('ALTER TABLE jobs ADD COLUMN cleanup_done INTEGER NOT NULL DEFAULT 0');
        }
        if (!in_array('owner_lock', $jobColumns, true)) {
            $this->db->exec('ALTER TABLE jobs ADD COLUMN owner_lock TEXT');
        }
        if (!in_array('history_scrubbed', $jobColumns, true)) {
            $this->db->exec('ALTER TABLE jobs ADD COLUMN history_scrubbed INTEGER NOT NULL DEFAULT 0');
        }
        $eventColumns = array_column($this->query('PRAGMA table_info(service_event_locks)'), 'name');
        if (!in_array('owner_lock', $eventColumns, true)) {
            $this->db->exec('ALTER TABLE service_event_locks ADD COLUMN owner_lock TEXT');
        }
        $this->db->exec('CREATE TABLE IF NOT EXISTS job_receipts (
            id INTEGER PRIMARY KEY, legacy_key TEXT UNIQUE NOT NULL,
            status TEXT NOT NULL, payload TEXT NOT NULL
        ); CREATE INDEX IF NOT EXISTS jobs_history ON jobs(status,history_scrubbed,updated);
        CREATE TABLE IF NOT EXISTS cancellation_cleanups (
            id INTEGER PRIMARY KEY AUTOINCREMENT, source_job_id INTEGER NOT NULL,
            service_id INTEGER NOT NULL, source_version INTEGER NOT NULL, slot TEXT NOT NULL,
            payload TEXT NOT NULL, identity TEXT NOT NULL, UNIQUE(source_job_id,slot)
        ); CREATE INDEX IF NOT EXISTS cancellation_service_order ON cancellation_cleanups(service_id,source_version,id);
        CREATE TABLE IF NOT EXISTS retained_resources (
            id INTEGER PRIMARY KEY AUTOINCREMENT, source_job_id INTEGER NOT NULL,
            service_id INTEGER NOT NULL, source_version INTEGER NOT NULL, slot TEXT NOT NULL,
            payload TEXT NOT NULL, identity TEXT NOT NULL, retained_at TEXT NOT NULL,
            UNIQUE(source_job_id,slot)
        ); CREATE INDEX IF NOT EXISTS retained_service_order ON retained_resources(service_id,source_version,id)');
        $this->withAdministrativeLock(function () use ($newTerminalSchema): void {
            $this->importLegacy();
            $this->transaction(function () use ($newTerminalSchema): void {
                if ($newTerminalSchema) $this->execute('DELETE FROM queue_meta WHERE key=:key', ['key' => 'terminal_intents_recovered_v1']);
                $this->recoverLegacyTerminalIntents();
                $this->seedCancellationCleanups();
                $this->compactAppliedHistory();
                $this->compactHistory();
            });
        });
    }

    public function enqueue(array $snapshot, string $operation = 'update'): string
    {
        return $this->enqueueBatch([['snapshot' => $snapshot, 'operation' => $operation]])[0];
    }

    public function deferFreshReview(int $serviceId,string $reason): void
    {
        if ($serviceId<1) throw new InvalidArgumentException('Invalid service ID');
        $reason=$this->freshReviewReason($reason);
        $this->withAdministrativeLock(fn()=>$this->transaction(function() use($serviceId,$reason):void {
            $now=date('c');
            $this->execute('INSERT INTO webhook_fresh_reviews(service_id,attempts,next_attempt,last_error,received,updated)
                VALUES(:sid,0,:next,:reason,:now,:now) ON CONFLICT(service_id) DO UPDATE SET last_error=excluded.last_error,updated=excluded.updated',
                ['sid'=>$serviceId,'next'=>time()+15,'reason'=>$reason,'now'=>$now]);
        }));
    }

    public function nextFreshReview(): ?array
    {
        $rows=$this->query('SELECT service_id,attempts,next_attempt,last_error FROM webhook_fresh_reviews WHERE next_attempt<=:now ORDER BY next_attempt,service_id LIMIT 1',['now'=>time()]);
        return $rows[0]??null;
    }

    public function hasFreshReview(int $serviceId): bool
    { return $this->query('SELECT service_id FROM webhook_fresh_reviews WHERE service_id=:sid',['sid'=>$serviceId])!==[]; }

    public function clearFreshReview(int $serviceId): void
    {
        $this->withAdministrativeLock(fn()=>$this->execute('DELETE FROM webhook_fresh_reviews WHERE service_id=:sid',['sid'=>$serviceId]));
    }

    public function backoffFreshReview(int $serviceId,string $reason): void
    {
        $reason=$this->freshReviewReason($reason);
        $this->withAdministrativeLock(fn()=>$this->transaction(function() use($serviceId,$reason):void {
            $rows=$this->query('SELECT attempts FROM webhook_fresh_reviews WHERE service_id=:sid',['sid'=>$serviceId]); if (!$rows) return;
            $attempt=min(20,(int)$rows[0]['attempts']+1);
            $this->execute('UPDATE webhook_fresh_reviews SET attempts=:attempt,next_attempt=:next,last_error=:reason,updated=:now WHERE service_id=:sid',
                ['sid'=>$serviceId,'attempt'=>$attempt,'next'=>time()+min(900,15*(2**min(6,$attempt))), 'reason'=>$reason,'now'=>date('c')]);
        }));
    }

    private function freshReviewReason(string $reason): string
    {
        return in_array($reason,['webhook_state_unconfirmed','webhook_service_absent','webhook_fresh_state_unavailable','webhook_fresh_state_invalid','webhook_fresh_review_blocked'],true)
            ?$reason:'webhook_fresh_state_unavailable';
    }

    public function close(): void
    {
        if (!$this->closed) {
            foreach (array_keys($this->ownerLocks) as $owner) { $this->releaseOwner($owner); }
            $this->db->close(); $this->closed = true;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /** Enqueue a migration's delete and replacement together, in their execution order. */
    public function enqueueBatch(array $entries): array
    {
        return $this->withAdministrativeLock(fn() => $this->transaction(function () use ($entries) {
            if ($this->maintenanceActive()) { throw new RuntimeException('Provisioning is paused for database maintenance'); }
            $keys = [];
            $previous = null;
            foreach ($entries as $entry) {
                $snapshot = $entry['snapshot'];
                $operation = $entry['operation'] ?? 'update';
                if ($operation === 'update' && isset($snapshot['_prior_network']) && $previous !== null
                    && ($previous['operation'] ?? 'update') === 'delete'
                    && ($previous['snapshot']['id'] ?? null) == ($snapshot['id'] ?? null)) {
                    $snapshot['_prior_identity'] = $this->identity($previous['snapshot']);
                }
                $keys[] = $this->insertJob($snapshot, $operation);
                $previous = $entry;
            }
            $this->compactHistory();
            return $keys;
        }));
    }

    public function eventKeys(string $eventId): array
    {
        $prefix = 'event:' . $eventId . ':';
        $rows = $this->query('SELECT id FROM jobs WHERE substr(legacy_key,1,:length)=:prefix
            UNION SELECT id FROM job_receipts WHERE substr(legacy_key,1,:length)=:prefix ORDER BY id',
            ['length' => strlen($prefix), 'prefix' => $prefix]);
        return array_map(fn(array $row): string => 'Q' . $row['id'], $rows);
    }

    public function eventSnapshot(string $eventId): array
    {
        $prefix = 'event:' . $eventId . ':';
        $rows = $this->query('SELECT id,payload FROM jobs WHERE substr(legacy_key,1,:length)=:prefix
            UNION SELECT id,payload FROM job_receipts WHERE substr(legacy_key,1,:length)=:prefix ORDER BY id DESC LIMIT 1',
            ['length' => strlen($prefix), 'prefix' => $prefix]);
        return $rows ? $this->payload($rows[0]) : [];
    }

    public function appliedSnapshot(int $serviceId): array
    {
        $rows = $this->query('SELECT applied_payload FROM service_versions WHERE service_id=:id', ['id' => $serviceId]);
        return !empty($rows[0]['applied_payload']) ? json_decode($rows[0]['applied_payload'], true, 512, JSON_THROW_ON_ERROR) : [];
    }

    /** Billing termination survives an offline router and cache refreshes. Only a fresh service event clears it. */
    public function isServiceBlocked(int $serviceId): bool
    {
        return $this->query('SELECT service_id FROM service_tombstones WHERE service_id=:sid', ['sid' => $serviceId]) !== [];
    }

    private function recoverLegacyTerminalIntents(): void
    {
        if ($this->query('SELECT key FROM queue_meta WHERE key=:key', ['key' => 'terminal_intents_recovered_v1'])) return;
        $deletions = $this->query("SELECT * FROM jobs WHERE operation='delete' AND status IN ('pending','running','succeeded','cleaned') ORDER BY version");
        foreach ($this->query("SELECT * FROM job_receipts WHERE status IN ('succeeded','cleaned') AND legacy_key LIKE 'event:%:delete:%'") as $receipt) {
            if (!preg_match('/^event:([^:]+):([1-9][0-9]*):delete:/D', $receipt['legacy_key'], $match)) continue;
            $receipt['service_id'] = (int)$match[2];
            $receipt['version'] = 0;
            $deletions[] = $receipt;
        }
        foreach ($deletions as $job) {
            $snapshot = $this->payload($job);
            $eventId = $snapshot['_event_id'] ?? null;
            if (!is_string($eventId) && preg_match('/^event:([^:]+):[1-9][0-9]*:delete:/D', $job['legacy_key'] ?? '', $match)) $eventId = $match[1];
            if (empty($snapshot['_terminal']) && (!is_string($eventId) || $eventId === '')) continue;
            $sid = (int)$job['service_id'];
            if (in_array($job['status'], ['succeeded','cleaned'], true) && $this->appliedSnapshot($sid) !== []) continue;
            if (is_string($eventId) && $eventId !== '') {
                // A migration commits delete + replacement for the same event; it is not a billing termination.
                $prefix = 'event:' . $eventId . ':' . $sid . ':update:';
                $paired = $this->query('SELECT id FROM jobs WHERE substr(legacy_key,1,:length)=:prefix
                    UNION SELECT id FROM job_receipts WHERE substr(legacy_key,1,:length)=:prefix',
                    ['length' => strlen($prefix), 'prefix' => $prefix]);
                if ($paired) continue;
            }
            $reactivated = false;
            foreach ($this->query("SELECT payload FROM jobs WHERE service_id=:sid AND id>:id AND operation='update'
                UNION ALL SELECT payload FROM job_receipts WHERE id>:id AND substr(legacy_key,1,6)='event:'",
                ['sid' => $sid, 'id' => (int)$job['id']]) as $later) {
                if ((int)($this->payload($later)['id'] ?? 0) !== $sid) continue;
                if (!empty($this->payload($later)['_clear_terminal'])) { $reactivated = true; break; }
            }
            if ($reactivated) continue;
            $this->execute('INSERT INTO service_tombstones(service_id,version) VALUES(:sid,:version)
                ON CONFLICT(service_id) DO UPDATE SET version=MAX(version,excluded.version)',
                ['sid' => $sid, 'version' => (int)$job['version']]);
            // Older automatic updates must wait for a fresh, explicit UISP service state.
            $this->execute("UPDATE jobs SET error=:error WHERE service_id=:sid AND version>:version
                AND operation IN ('update','queues_set') AND status='pending'",
                ['sid' => $sid, 'version' => (int)$job['version'],
                    'error' => 'Awaiting a fresh UISP service event after a legacy terminal cleanup']);
        }
        $this->execute('INSERT OR REPLACE INTO queue_meta(key,value) VALUES(:key,:value)',
            ['key' => 'terminal_intents_recovered_v1', 'value' => date('c')]);
    }

    /** Serialize physical-router resources shared by different service workers. */
    public function withResourceLock(string $identity, callable $operation)
    {
        $handle = $this->acquireFile('resource-' . hash('sha256', $identity), LOCK_EX);
        try { return $operation(); }
        finally { $this->releaseFile($handle); }
    }

    /** Revision of a physical router's owned profile, independent of its display name. */
    public function profileRevision(string $identity): int
    {
        $rows = $this->query('SELECT value FROM queue_meta WHERE key=:key',
            ['key' => 'profile_revision:' . hash('sha256', $identity)]);
        if (!$rows) return 0;
        $state = json_decode($rows[0]['value'], true, 512, JSON_THROW_ON_ERROR);
        $revision = filter_var($state['generation'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($revision === false) throw new RuntimeException('The managed profile revision is invalid');
        return $revision;
    }

    /** Each physically observed repair invalidates sessions that acknowledged the previous generation. */
    public function repairProfileRevision(string $identity): int
    {
        if ($identity === '') throw new InvalidArgumentException('A profile identity is required');
        return $this->withAdministrativeLock(fn(): int => $this->transaction(function () use ($identity): int {
            $profileKey = 'profile_revision:' . hash('sha256', $identity);
            $revision = $this->profileRevision($identity);
            if ($revision === PHP_INT_MAX) throw new RuntimeException('The managed profile revision cannot advance');
            $revision++;
            $state = $this->encode(['generation' => $revision]);
            $this->execute('INSERT INTO queue_meta(key,value) VALUES(:key,:value) ON CONFLICT(key) DO UPDATE SET value=excluded.value',
                ['key' => $profileKey, 'value' => $state]);
            return $revision;
        }));
    }

    /** Internal resource membership, including accounts that a partial retry may already have created. */
    public function resourceSnapshots(?int $serviceId = null): array
    {
        $snapshots = [];
        $filter = $serviceId === null ? '' : ' AND service_id=:sid';
        $parameters = $serviceId === null ? [] : ['sid' => $serviceId];
        foreach ($this->query('SELECT applied_payload AS payload FROM service_versions WHERE applied_payload IS NOT NULL' . $filter, $parameters) as $row) {
            $snapshots[] = $this->payload($row);
        }
        foreach ($this->query("SELECT payload,cleanup_done FROM jobs WHERE status IN ('pending','running','cancelled','superseded')" . $filter, $parameters) as $row) {
            $snapshot = $this->payload($row);
            if (isset($snapshot['_commands'])) $snapshots[] = $snapshot;
            if (isset($snapshot['_cleanup']) && !$row['cleanup_done']) $snapshots[] = $snapshot['_cleanup'];
        }
        foreach ($this->query('SELECT payload FROM cancellation_cleanups WHERE 1=1' . $filter, $parameters) as $row) {
            $snapshots[] = $this->withoutCredentials($this->payload($row));
        }
        // A reviewed cancellation can explicitly keep its resources. These are
        // ownership evidence, never commands for a later job's cancellation cleanup.
        foreach ($this->query('SELECT slot,payload FROM retained_resources WHERE 1=1' . $filter, $parameters) as $row) {
            $snapshot = $this->payload($row);
            if ($row['slot'] !== 'source' || isset($snapshot['_commands'])) $snapshots[] = $this->withoutCredentials($snapshot);
            if ($row['slot'] === 'source' && isset($snapshot['_cleanup']) && is_array($snapshot['_cleanup']))
                $snapshots[] = $this->withoutCredentials($snapshot['_cleanup']);
        }
        return $snapshots;
    }

    /** Include uncertain legacy attempts even when they predate frozen command journals. */
    public function lifecycleSnapshots(int $serviceId): array
    {
        $result=$this->resourceSnapshots($serviceId);
        foreach ($this->query("SELECT payload,attempts,legacy_key,operation FROM jobs WHERE service_id=:sid AND status IN ('pending','cancelled','superseded')",['sid'=>$serviceId]) as $row) {
            $snapshot=$this->payload($row);
            if (!isset($snapshot['_commands']) && ((int)$row['attempts']>0 || $row['legacy_key']!==null || $row['operation']==='delete')) $result[]=$snapshot;
        }
        foreach($this->query("SELECT payload FROM retained_resources WHERE service_id=:sid AND slot='source'",['sid'=>$serviceId]) as $row) {
            $snapshot=$this->payload($row);
            if (!empty($snapshot['_prior_network']) || !empty($snapshot['_previous_addresses'])) $result[]=$snapshot;
        }
        return $result;
    }

    public function cancelRetirement(int $sid,bool $legacyTerminal=false): void
    {
        if (!$this->ownsServiceLock($sid)) throw new RuntimeException('lifecycle_service_busy');
        $this->transaction(function () use($sid,$legacyTerminal): void {
            $changed=false;
            foreach($this->query("SELECT * FROM jobs WHERE service_id=:sid AND operation='delete' AND status='pending'",['sid'=>$sid]) as $job) {
                $snapshot=$this->payload($job);
                if (empty($snapshot['_lifecycle_retirement']) && (!$legacyTerminal || empty($snapshot['_terminal']))) continue;
                $this->retainCancellationCleanups($job);
                $this->execute("UPDATE jobs SET status='superseded',error=NULL,next_attempt=0,updated=:now WHERE id=:id AND status='pending'",['id'=>$job['id'],'now'=>date('c')]); $changed=true;
            }
            if ($changed) {
                $this->execute('DELETE FROM service_tombstones WHERE service_id=:sid',['sid'=>$sid]);
                $this->writeLifecycleMeta('lifecycle:service:'.$sid,['state'=>'reactivated','reason'=>'lifecycle_reactivated','checked_at'=>date('c'),'job_keys'=>[]]);
            }
        });
    }

    public function lifecycleMayRelease(array $job): bool
    {
        $current=$this->ownedCancellationJob($job); $sid=(int)$current['service_id'];
        if (empty($this->payload($current)['_lifecycle_retirement'])) return false;
        return !$this->query("SELECT id FROM jobs WHERE service_id=:sid AND id<>:id AND status IN ('pending','running') LIMIT 1",['sid'=>$sid,'id'=>$current['id']])
            && !$this->query('SELECT id FROM cancellation_cleanups WHERE service_id=:sid LIMIT 1',['sid'=>$sid]);
    }

    private function lifecycleReviewBeforeClaim(?array &$selected,array &$attempted,array &$results,array &$reviewed,int &$budget,float $deadline): void
    {
        $paths=$this->lifecyclePaths();
        if (!is_file($paths['data'])) return;
        $where=$selected===null?' AND next_attempt<='.time():' AND id IN ('.implode(',',$selected).')';
        if ($attempted) $where.=' AND id NOT IN ('.implode(',',$attempted).')';
        foreach ($this->query("SELECT * FROM jobs WHERE status='pending' $where ORDER BY id LIMIT 10000") as $job) {
            $sid=(int)$job['service_id']; if (isset($reviewed[$sid])) continue;
            $reviewer=$this->lifecycleReviewer(); $snapshot=$this->payload($job);
            $retiring=$job['operation']==='delete' && !empty($snapshot['_lifecycle_retirement']);
            // Its guarded Batch execution performs a fresh check immediately before removal.
            if ($retiring) continue;
            $legacyTerminal=$job['operation']==='delete' && !empty($snapshot['_terminal']);
            if (!$legacyTerminal && !$reviewer->requiresReview($job)) continue;
            if ($budget<=0 || microtime(true)>=$deadline) {
                $attempted[]=(int)$job['id'];
                $results['Q'.$job['id']]=['success'=>false,'error'=>'lifecycle_review_pending'];
                continue;
            }
            $reviewed[$sid]=true; $budget--;
            try {
                if ($retiring) { $reviewer->assertRetired($sid,$snapshot['_lifecycle_retirement']); continue; }
                $review=$reviewer->withDeadline($deadline,fn()=>$legacyTerminal?$reviewer->reviewTerminal($sid,$this,!empty($snapshot['_lifecycle_billing']),$snapshot['_lifecycle_identity_field']??null):$reviewer->review($sid,$this));
                if (($review['state']??'unknown')==='unknown') throw new RuntimeException('lifecycle_unavailable');
                if ($selected!==null) foreach ($review['jobKeys']??[] as $key) $selected[]=(int)substr($key,1);
                if ($selected!==null) $selected=array_values(array_unique($selected));
            } catch (Throwable $error) {
                $reason=in_array($error->getMessage(),['lifecycle_reactivated','lifecycle_identity_changed','lifecycle_router_identity_unknown','lifecycle_changed'],true)?$error->getMessage():'lifecycle_unavailable';
                foreach ($this->query("SELECT id FROM jobs WHERE service_id=:sid AND status='pending'",['sid'=>$sid]) as $blocked) {
                    $id=(int)$blocked['id']; $attempted[]=$id;
                    $this->execute('UPDATE jobs SET error=:reason,next_attempt=:retry,updated=:now WHERE id=:id AND status=\'pending\'',['id'=>$id,'reason'=>$reason,'retry'=>time()+30,'now'=>date('c')]);
                    $results['Q'.$id]=['success'=>false,'error'=>$reason];
                }
                if ($reason==='lifecycle_reactivated') $this->deferFreshReview($sid,'webhook_state_unconfirmed');
            }
        }
    }

    public function canClearApplied(array $snapshot): bool
    {
        $rows = $this->query('SELECT applied_identity FROM service_versions WHERE service_id=:id', ['id' => (int) $snapshot['id']]);
        return empty($rows[0]['applied_identity']) || hash_equals($rows[0]['applied_identity'], $this->identity($snapshot));
    }

    /** Keep confirmed and possibly partially applied addresses occupied until reconciliation completes. */
    public function reservedAddresses(bool $ipv6 = false): array
    {
        $rows = [];
        foreach ($this->query('SELECT service_id,applied_payload FROM service_versions WHERE applied_payload IS NOT NULL') as $row) {
            $snapshot = json_decode($row['applied_payload'], true, 512, JSON_THROW_ON_ERROR);
            $this->collectAddresses($rows, (int) $row['service_id'], $snapshot, $ipv6, false);
        }
        foreach ($this->query("SELECT service_id,payload,prior_cleared,cleanup_done FROM jobs
            WHERE status IN ('pending','running','cancelled','superseded')") as $row) {
            $snapshot = $this->payload($row);
            if (isset($snapshot['_commands']) && is_array($snapshot['_commands'])) {
                $this->collectAddresses($rows, (int) $row['service_id'], $snapshot, $ipv6, false);
            }
            if (!$row['prior_cleared']) {
                $this->collectAddresses($rows, (int) $row['service_id'], ['_prior_network' => $snapshot['_prior_network'] ?? []], $ipv6, !(bool) $row['prior_cleared']);
            }
            if (isset($snapshot['_cleanup']) && !$row['cleanup_done']) {
                $this->collectAddresses($rows, (int) $row['service_id'], $snapshot['_cleanup'], $ipv6, false);
            }
        }
        foreach ($this->query('SELECT service_id,payload FROM cancellation_cleanups') as $row) {
            $this->collectAddresses($rows, (int) $row['service_id'], $this->payload($row), $ipv6, true);
        }
        foreach ($this->query('SELECT service_id,slot,payload FROM retained_resources') as $row) {
            $snapshot = $this->payload($row); $sid = (int) $row['service_id'];
            if ($row['slot'] !== 'source') {
                $this->collectAddresses($rows, $sid, $snapshot, $ipv6, true);
                continue;
            }
            // An unprepared cancelled intent's desired IP was never an owned
            // resource. Retain only frozen commands and its historical reservations.
            if (isset($snapshot['_commands']) && is_array($snapshot['_commands']))
                $this->collectAddresses($rows, $sid, $snapshot, $ipv6, false);
            $this->collectAddresses($rows, $sid, ['_prior_network' => $snapshot['_prior_network'] ?? []], $ipv6, true);
            if (isset($snapshot['_cleanup']) && is_array($snapshot['_cleanup']))
                $this->collectAddresses($rows, $sid, $snapshot['_cleanup'], $ipv6, true);
        }
        return array_values($rows);
    }

    /** Hold replaced IPs until an in-place update succeeds, including interrupted retries and cancellation. */
    public function preserveReplacedAddresses(array $previous, array $next): array
    {
        $addresses = [];
        foreach ([false, true] as $ipv6) $this->collectAddresses($addresses, (int)$previous['id'], $previous, $ipv6, true);
        $values = $previous['_previous_addresses'] ?? [];
        foreach ($addresses as $row) $values[] = $row['address'] ?? $row['address6'];
        $next['_previous_addresses'] = array_values(array_unique($values));
        return $next;
    }

    public function list(): array
    {
        $rows = $this->query("SELECT * FROM jobs WHERE status IN ('pending','running') ORDER BY id");
        $status = $this->selectionStatus(array_column($rows, 'id'));
        $result = [];
        foreach ($rows as $row) {
            $item = $this->payload($row);
            foreach (['_device', '_plan', '_commands', '_batch', 'password'] as $secret) {
                unset($item[$secret]);
            }
            $item = $this->publicPayload($item);
            $item['action'] = $row['operation'];
            $item['jobId'] = 'Q' . $row['id'];
            $item['version'] = (int) $row['version'];
            $item['identity'] = $row['identity'];
            $item['attempts'] = (int) $row['attempts'];
            $item['state'] = $row['status'];
            $item['error'] = $row['error'];
            $item['last'] = $row['updated'];
            $item['nextRetry'] = $row['next_attempt'] ? date('c', (int) $row['next_attempt']) : null;
            $item = array_merge($item, $status['Q' . $row['id']]);
            $result['Q' . $row['id']] = $item;
        }
        return $result;
    }

    /** Observe selection barriers without recovering locks, changing jobs or reading private fields into the response. */
    public function selectionStatus(array $keys): array
    {
        $ids = $this->ids($keys);
        if (!$ids) return [];
        $allowedOwners = $this->serviceOwners ? " AND gate.owner NOT IN ('"
            . implode("','", array_values($this->serviceOwners)) . "')" : '';
        $rows = $this->query("SELECT j.*,
            (SELECT earlier.id FROM jobs earlier WHERE earlier.service_id=j.service_id
                AND earlier.version<j.version AND earlier.status IN ('pending','running')
                ORDER BY earlier.version,earlier.id LIMIT 1) AS blocked_by,
            EXISTS(SELECT 1 FROM service_tombstones terminal WHERE terminal.service_id=j.service_id
                AND j.version>terminal.version AND j.operation IN ('update','queues_set')) AS terminal_blocked,
            EXISTS(SELECT 1 FROM service_event_locks gate WHERE gate.service_id=j.service_id $allowedOwners) AS service_busy
            FROM jobs j WHERE j.id IN (" . implode(',', $ids) . ')');
        $byId = [];
        foreach ($rows as $row) $byId[(int) $row['id']] = $row;
        foreach ($this->query('SELECT id,status,payload FROM job_receipts WHERE id IN (' . implode(',', $ids) . ')') as $row) {
            if (!isset($byId[(int) $row['id']])) {
                $row['service_id'] = $this->payload($row)['id'] ?? null;
                $byId[(int) $row['id']] = $row;
            }
        }
        // This is an observation of stored guards; only the execution path may recover expired owners.
        $maintenance = $this->query("SELECT key FROM queue_meta WHERE key='maintenance'") !== [];
        $result = [];
        foreach ($ids as $id) {
            $row = $byId[$id] ?? null;
            $state = $row['status'] ?? 'missing';
            $pending = $state === 'pending';
            $snapshot = $row !== null ? $this->payload($row) : [];
            $blockedBy = ($pending || $state === 'running') && !empty($row['blocked_by']) ? 'Q' . $row['blocked_by'] : null;
            $blockedReason = null;
            if ($state === 'running') $blockedReason = 'running';
            elseif ($pending) {
                if ($blockedBy !== null) $blockedReason = 'earlier_job';
                elseif (!empty($row['terminal_blocked'])) $blockedReason = 'terminal';
                elseif (!empty($row['service_busy'])) $blockedReason = 'service_event';
                elseif ($maintenance) $blockedReason = 'maintenance';
            }
            $deleteBlockedReason = $row === null ? 'missing' : ($state === 'running' ? 'running'
                : (!$pending ? 'not_pending' : ($this->canCancelJob($row) ? null : 'prepared')));
            $device = filter_var($snapshot['device'] ?? $snapshot['_device']['id'] ?? null,
                FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $result['Q' . $id] = [
                'jobId' => 'Q' . $id, 'serviceId' => isset($row['service_id']) ? (int) $row['service_id'] : null,
                'deviceId' => $device === false ? null : $device, 'attempts' => (int) ($row['attempts'] ?? 0),
                'nextRetry' => !empty($row['next_attempt']) ? date('c', (int) $row['next_attempt']) : null,
                'error' => $row['error'] ?? null,
                'state' => $state, 'blockedBy' => $blockedBy, 'blockedReason' => $blockedReason,
                'canDelete' => $deleteBlockedReason === null, 'deleteBlockedReason' => $deleteBlockedReason,
            ];
            if ($state==='succeeded' && $this->path!==':memory:') {
                try {
                    $verification=$this->verificationStore()->read((int)$row['service_id']);
                    if ($verification['job_id']==='Q'.$id && $verification['current']===true
                        && $verification['version']===(int)($row['version']??0)) $result['Q'.$id]['verification']=$verification;
                } catch (Throwable $ignored) {}
            }
        }
        return $result;
    }

    /** Evidence has no role in claim/finish/retry decisions. */
    public function recordVerification(array $service,array $job,object $device,array $stages): array
    {
        if ($this->path===':memory:') return ['state'=>'unconfirmed','reason'=>'receipt_unavailable'];
        return $this->verificationStore()->record($service,$job,$device,$stages);
    }

    private function verificationStore(): ApiProvisioningVerification
    {
        require_once __DIR__.'/api_provisioning_verification.php';
        return new ApiProvisioningVerification(null,dirname($this->path).DIRECTORY_SEPARATOR.'data.db',$this->path);
    }

    /** Immediate and manual retries include unfinished prerequisites, never newer or unrelated work. */
    public function runSelected(array $keys, ?callable $executor = null): array
    {
        $selected = $this->ids($keys);
        if (!$selected) return [];
        $rows = $this->query("SELECT DISTINCT earlier.id FROM jobs earlier JOIN jobs selected
            ON selected.service_id=earlier.service_id AND earlier.version<=selected.version
            WHERE selected.id IN (" . implode(',', $selected) . ") AND selected.status IN ('pending','running')
                AND earlier.status IN ('pending','running') ORDER BY earlier.id");
        $eligible = array_map(static fn(array $row): int => (int) $row['id'], $rows);
        $expanded = array_values(array_unique(array_merge($selected, $eligible)));
        sort($expanded, SORT_NUMERIC);
        $attempts = $eligible ? $this->run($eligible, $executor) : [];
        $result = [];
        foreach ($this->selectionStatus($expanded) as $key => $status) {
            $attempt = $attempts[$key] ?? null;
            $result[$key] = [
                'success' => $attempt !== null ? $attempt['success'] : $status['state'] === 'succeeded',
                'error' => $attempt['error'] ?? null,
                'attempted' => $attempt !== null,
            ] + $status;
        }
        return $result;
    }

    /** Cancel locally; possible RouterOS effects remain separate durable cleanup debt for a later intent. */
    public function delete(array $keys): array
    {
        return $this->withAdministrativeLock(fn() => $this->transaction(function () use ($keys) {
            $result = [];
            $cancelled = [];
            foreach ($this->ids($keys) as $id) {
                $rows = $this->query('SELECT * FROM jobs WHERE id=:id', ['id' => $id]);
                if (!$rows || !$this->canCancelJob($rows[0])) {
                    $result['Q' . $id] = isset($cancelled[$id]);
                    continue;
                }
                $job = $rows[0];
                $this->retainCancellationCleanups($job);
                $this->execute("UPDATE jobs SET status='cancelled', updated=:now
                    WHERE id=:id AND status='pending'", ['now' => date('c'), 'id' => $id]);
                $result['Q' . $id] = $this->db->changes() > 0;
                if ($result['Q' . $id]) $cancelled[$id] = true;
                if ($result['Q' . $id] && $job['operation'] === 'delete') {
                    // A replacement must never become runnable merely because its cleanup barrier was cancelled.
                    $successors = $this->query("SELECT * FROM jobs
                        WHERE service_id=:sid AND version>:version AND status='pending'",
                        ['sid' => (int) $job['service_id'], 'version' => (int) $job['version']]);
                    foreach ($successors as $successor) $this->retainCancellationCleanups($successor);
                    $this->execute("UPDATE jobs SET status='cancelled',updated=:now
                        WHERE service_id=:sid AND version>:version AND status='pending'",
                        ['sid' => (int) $job['service_id'], 'version' => (int) $job['version'], 'now' => date('c')]);
                    foreach ($successors as $successor) $cancelled[(int) $successor['id']] = true;
                }
            }
            // Selected successors may have been visited before their cleanup barrier was cancelled.
            foreach ($result as $key => &$deleted) $deleted = $deleted || isset($cancelled[(int) substr($key, 1)]);
            unset($deleted);
            $this->compactHistory();
            return $result;
        }));
    }

    /** Each selected operation is attempted at most once per run; failures retain their payload. */
    public function run(?array $keys = null, ?callable $executor = null): array
    {
        $selected = $keys === null ? null : $this->ids($keys);
        if ($selected === []) { return []; }
        $attempted = [];
        $results = [];
        $reviewed=[]; $lifecycleBudget=8; $lifecycleDeadline=microtime(true)+8; $conflictBudget=8.0;
        while (true) {
            if ($executor===null) $this->lifecycleReviewBeforeClaim($selected,$attempted,$results,$reviewed,$lifecycleBudget,$lifecycleDeadline);
            $job=$this->claim($selected,$attempted); if (!$job) break;
            $id = (int) $job['id'];
            $attempted[] = $id;
            $key = 'Q' . $id;
            $error = null;
            try {
                if ($executor === null) {
                    require_once __DIR__ . '/batch.php';
                    $execute=fn()=>(new Batch())->applySnapshot($this->payload($job),$job['operation'],$this,$job);
                    $snapshot=$this->payload($job);
                    $sid=(int)$job['service_id'];
                    $ok=!empty($snapshot['_lifecycle_retirement']) && !$this->ownsServiceLock($sid)
                        ? $this->withServiceLock($sid,$execute) : $execute();
                } else {
                    $ok = $executor($this->payload($job), $job['operation'], $job, $this);
                }
                if ($ok !== true) { $error = 'Operation did not complete'; }
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
                if ($executor===null) {
                    $conflictStarted=microtime(true);
                    try {
                        if ($conflictBudget>0 && $this->lifecycleReviewer()->resolveConflict($exception,$this,(int)$job['service_id'],microtime(true)+$conflictBudget)) {
                            $current=$this->query("SELECT payload FROM jobs WHERE id=:id AND status='running' AND owner=:owner",['id'=>$job['id'],'owner'=>$job['owner']]);
                            if ($current && (new Batch())->applySnapshot($this->payload($current[0]),$job['operation'],$this,$job)===true) $error=null;
                        }
                    } catch (Throwable $retryError) { $error=$retryError->getMessage(); }
                    finally { $conflictBudget=max(0,$conflictBudget-(microtime(true)-$conflictStarted)); }
                }
            }
            $failureAttempt = null;
            try { $owned = $this->finish($job, $error, $failureAttempt); }
            finally { $this->releaseOwner($job['owner']); }
            if ($executor===null && $owned && $error==='lifecycle_reactivated') {
                try { $this->lifecycleReviewer()->review((int)$job['service_id'],$this); $this->deferFreshReview((int)$job['service_id'],'webhook_state_unconfirmed'); }
                catch (Throwable $ignored) {}
            }
            if ($owned && $error !== null && $failureAttempt !== null) $this->logFailedAttempt($job,$error,$failureAttempt);
            $results[$key] = ['success' => $owned && $error === null, 'error' => $error];
        }
        return $results;
    }

    /** Materialize commands once, before the first write, and retain them for every retry. */
    public function freezePrepared(array $job, array $snapshot): array
    {
        $this->validateSnapshotNumbers($snapshot);
        $cleanupStage = isset($snapshot['_cleanup']) && !isset($snapshot['_commands']);
        if ($cleanupStage) { $snapshot['_prior_identity'] = $this->identity($snapshot['_cleanup']); }
        $encoded = $this->encode($snapshot);
        $this->execute("UPDATE jobs SET payload=:payload, identity=:identity, updated=:now,
            prior_cleared=CASE WHEN :cleanup_stage=1 THEN 0 ELSE prior_cleared END
            WHERE id=:id AND status='running' AND owner=:owner",
            ['payload' => $encoded, 'identity' => $this->identity($snapshot), 'cleanup_stage' => (int) $cleanupStage,
                'now' => date('c'), 'id' => $job['id'], 'owner' => $job['owner']]);
        if ($this->db->changes() !== 1) {
            throw new RuntimeException('Operation ownership was lost before the router write');
        }
        return $snapshot;
    }

    public function cleanupCompleted(array $job): bool
    {
        $rows = $this->query("SELECT cleanup_done FROM jobs WHERE id=:id AND owner=:owner AND status='running'",
            ['id' => $job['id'], 'owner' => $job['owner']]);
        if (!$rows) { throw new RuntimeException('Operation ownership was lost during cleanup'); }
        return (bool) $rows[0]['cleanup_done'];
    }

    public function confirmCleanup(array $job, array $snapshot): void
    {
        $this->transaction(function () use ($job, $snapshot) {
            $this->execute("UPDATE jobs SET cleanup_done=1,prior_cleared=1,updated=:now
                WHERE id=:id AND owner=:owner AND status='running'",
                ['now' => date('c'), 'id' => $job['id'], 'owner' => $job['owner']]);
            if ($this->db->changes() !== 1) { throw new RuntimeException('Operation ownership was lost during cleanup confirmation'); }
            $this->releaseApplied((int) $snapshot['id'], $this->identity($snapshot));
        });
    }

    /** Read private cleanup material only while a newer job owns this service's execution. */
    public function cancellationCleanups(array $runningJob): array
    {
        $job = $this->ownedCancellationJob($runningJob);
        $result = [];
        foreach ($this->query('SELECT id,payload FROM cancellation_cleanups
            WHERE service_id=:sid AND source_version<:version ORDER BY source_version,id',
            ['sid' => (int) $job['service_id'], 'version' => (int) $job['version']]) as $row) {
            $result[] = ['id' => (int) $row['id'], 'snapshot' => $this->payload($row)];
        }
        return $result;
    }

    /** Confirm only the exact cancelled resource snapshot whose physical cleanup just succeeded. */
    public function confirmCancellationCleanup(array $runningJob, int $debtId): void
    {
        $this->transaction(function () use ($runningJob, $debtId): void {
            $job = $this->ownedCancellationJob($runningJob);
            $rows = $this->query('SELECT * FROM cancellation_cleanups WHERE id=:id
                AND service_id=:sid AND source_version<:version',
                ['id' => $debtId, 'sid' => (int) $job['service_id'], 'version' => (int) $job['version']]);
            if (!$rows) throw new RuntimeException('Cancelled resource cleanup does not belong to this operation');
            $debt = $rows[0];
            $this->releaseApplied((int) $debt['service_id'], $debt['identity']);
            $this->execute('DELETE FROM cancellation_cleanups WHERE id=:id', ['id' => $debtId]);
            if (!$this->query('SELECT id FROM cancellation_cleanups WHERE source_job_id=:id LIMIT 1', ['id' => $debt['source_job_id']])) {
                $this->execute("UPDATE jobs SET status='cleaned',history_scrubbed=0,updated=:now
                    WHERE id=:id AND status IN ('cancelled','superseded')",
                    ['id' => $debt['source_job_id'], 'now' => date('c')]);
            }
            $this->compactHistory();
        });
    }

    public function hasUnreconciled(int $serviceId): bool
    {
        return $this->query('SELECT id FROM cancellation_cleanups WHERE service_id=:sid LIMIT 1', ['sid' => $serviceId]) !== [];
    }

    private function ownedCancellationJob(array $job): array
    {
        $owner = $job['owner'] ?? null;
        if (!is_string($owner) || $owner === '') throw new RuntimeException('Operation ownership was lost during cancelled resource cleanup');
        $rows = $this->query("SELECT * FROM jobs WHERE id=:id AND status='running' AND owner=:owner",
            ['id' => (int) ($job['id'] ?? 0), 'owner' => $owner]);
        if (!$rows || (int) ($job['service_id'] ?? 0) !== (int) $rows[0]['service_id']
            || (int) ($job['version'] ?? 0) !== (int) $rows[0]['version']) {
            throw new RuntimeException('Operation ownership was lost during cancelled resource cleanup');
        }
        return $rows[0];
    }

    public function pending(array $keys): bool
    {
        $ids = $this->ids($keys);
        if (!$ids) { return false; }
        return (bool) $this->db->querySingle("SELECT COUNT(*) FROM jobs
            WHERE id IN (" . implode(',', $ids) . ") AND status IN ('pending','running')");
    }

    public function completed(array $keys): bool
    {
        $ids = $this->ids($keys);
        if (!$ids) { return true; }
        return count($ids) === (int) $this->db->querySingle("SELECT COUNT(*) FROM (
            SELECT id FROM jobs WHERE id IN (" . implode(',', $ids) . ") AND status='succeeded'
            UNION SELECT id FROM job_receipts WHERE id IN (" . implode(',', $ids) . ") AND status='succeeded')");
    }

    public function hasRunning(): bool
    {
        return (int) $this->db->querySingle("SELECT COUNT(*) FROM jobs WHERE status='running'") > 0
            || (int) $this->db->querySingle('SELECT COUNT(*) FROM service_event_locks') > 0;
    }

    /** Serialize desired-state cache changes and queue version assignment for one webhook service. */
    public function withServiceLock(int $serviceId, callable $operation)
    {
        if ($serviceId < 1) { throw new InvalidArgumentException('Invalid service lock ID'); }
        $owner = bin2hex(random_bytes(16));
        $this->holdOwner($owner);
        try {
            $this->transaction(function () use ($serviceId, $owner) {
                $this->recoverEventLocks();
                if ($this->maintenanceActive() || $this->query('SELECT service_id FROM service_event_locks WHERE service_id=:sid', ['sid' => $serviceId])) {
                    throw new RuntimeException('Another event or maintenance operation is handling this service; retry this webhook');
                }
                $this->execute('INSERT INTO service_event_locks(service_id,owner,owner_pid,lease_until,owner_lock)
                    VALUES(:sid,:owner,:pid,:lease,:owner)',
                    ['sid' => $serviceId, 'owner' => $owner, 'pid' => getmypid(), 'lease' => time() + 900]);
            });
            $this->serviceOwners[$serviceId] = $owner;
            return $operation();
        } finally {
            if (($this->serviceOwners[$serviceId] ?? null) === $owner) { unset($this->serviceOwners[$serviceId]); }
            try {
                $this->execute('DELETE FROM service_event_locks WHERE service_id=:sid AND owner=:owner',
                    ['sid' => $serviceId, 'owner' => $owner]);
            } finally { $this->releaseOwner($owner); }
        }
    }

    public function ownsServiceLock(int $serviceId): bool
    {
        return isset($this->serviceOwners[$serviceId]);
    }

    /** Take a client's service gates in stable order before any multi-service mutation. */
    public function withServiceLocks(array $serviceIds, callable $operation)
    {
        $ids = [];
        foreach ($serviceIds as $raw) {
            $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) { throw new InvalidArgumentException('Invalid service lock ID'); }
            $ids[$id] = $id;
        }
        sort($ids, SORT_NUMERIC);
        $acquire = function (int $index) use (&$acquire, $ids, $operation) {
            if ($index === count($ids)) { return $operation(); }
            return $this->withServiceLock($ids[$index], fn() => $acquire($index + 1));
        };
        try { return $acquire(0); }
        finally { $acquire = null; }
    }

    /** Serialize partial client edits before reading or merging cached names. */
    public function withClientLock(int $clientId, callable $operation)
    {
        if ($clientId < 1) { throw new InvalidArgumentException('Invalid client lock ID'); }
        $guard = $this->acquireFile('maintenance', LOCK_SH);
        try {
            if ($this->maintenanceActive()) { throw new RuntimeException('Provisioning is paused for database maintenance'); }
            // This stable file stays in place: unlinking it could split concurrent waiters onto different inodes.
            $handle = $this->acquireFile('client-' . hash('sha256', (string) $clientId), LOCK_EX);
            try { return $operation(); }
            finally { $this->releaseFile($handle); }
        } finally { $this->releaseFile($guard); }
    }

    /** Administrative writes share the same restore barrier as events and RouterOS workers. */
    public function withAdministrativeLock(callable $operation)
    {
        $handle = $this->acquireFile('maintenance', LOCK_SH);
        try {
            if ($this->maintenanceActive()) { throw new RuntimeException('Provisioning is paused for database maintenance'); }
            return $operation();
        } finally { $this->releaseFile($handle); }
    }

    /** Pending/reconciliation and confirmed resource snapshots must be cleaned before a router is deleted. */
    public function hasDeviceReferences(array $ids): bool
    {
        $ids = array_fill_keys(array_map('strval', $ids), true);
        if (!$ids) { return false; }
        $references = static function (array $snapshot) use (&$references, $ids): bool {
            foreach ([$snapshot['device'] ?? null, $snapshot['_device']['id'] ?? null] as $id) {
                if ($id !== null && isset($ids[(string) $id])) { return true; }
            }
            return isset($snapshot['_cleanup']) && is_array($snapshot['_cleanup']) && $references($snapshot['_cleanup']);
        };
        foreach ($this->query("SELECT payload FROM jobs WHERE status IN ('pending','running','cancelled','superseded')") as $row) {
            if ($references($this->payload($row))) { $references = null; return true; }
        }
        foreach ($this->query('SELECT payload FROM cancellation_cleanups') as $row) {
            if ($references($this->payload($row))) { $references = null; return true; }
        }
        foreach ($this->query('SELECT payload FROM retained_resources') as $row) {
            if ($references($this->payload($row))) { $references = null; return true; }
        }
        foreach ($this->query('SELECT applied_payload FROM service_versions WHERE applied_payload IS NOT NULL') as $row) {
            if ($references(json_decode($row['applied_payload'], true, 512, JSON_THROW_ON_ERROR))) { $references = null; return true; }
        }
        $references = null;
        return false;
    }

    /** Restore/maintenance can hold this guard while no worker owns a router operation. */
    public function withMaintenance(callable $operation)
    {
        $owner = bin2hex(random_bytes(16));
        $handle = $this->acquireFile('maintenance', LOCK_EX);
        try { $ownerHandle = $this->acquireFile($owner, LOCK_EX); }
        catch (Throwable $error) { $this->releaseFile($handle); throw $error; }
        try {
            $this->transaction(function () use ($owner) {
                $this->recoverEventLocks();
                $this->recoverJobs();
                if ($this->hasRunning() || $this->maintenanceActive()) {
                    throw new RuntimeException('Router operations or another maintenance action are still running');
                }
                $this->execute('INSERT OR REPLACE INTO queue_meta(key,value) VALUES(:key,:value)',
                    ['key' => 'maintenance', 'value' => $this->encode(['owner' => $owner, 'pid' => getmypid(),
                        'lease' => time() + 3600, 'owner_lock' => $owner])]);
            });
            return $operation();
        } finally {
            try {
                $this->transaction(function () use ($owner) {
                    $row = $this->query('SELECT value FROM queue_meta WHERE key=:key', ['key' => 'maintenance']);
                    $guard = $row ? json_decode($row[0]['value'], true, 512, JSON_THROW_ON_ERROR) : [];
                    if (($guard['owner'] ?? null) === $owner) {
                        $this->execute('DELETE FROM queue_meta WHERE key=:key', ['key' => 'maintenance']);
                    }
                });
            } finally {
                $this->releaseFile($ownerHandle); $this->releaseFile($handle);
                $path = $this->lockDirectory . DIRECTORY_SEPARATOR . $owner . '.lock';
                if (is_file($path)) { @unlink($path); }
            }
        }
    }

    private function insertJob(array $snapshot, string $operation, ?string $legacyKey = null): string
    {
        $this->validateSnapshotNumbers($snapshot);
        $sid = filter_var($snapshot['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$sid || $sid < 1) { throw new InvalidArgumentException('A job requires a positive service ID'); }
        if ($operation === 'remove') { $operation = 'delete'; }
        if (!in_array($operation, ['delete', 'update', 'queues_remove', 'queues_set'], true)) {
            $operation = 'update';
        }
        unset($snapshot['error'], $snapshot['last'], $snapshot['batch'], $snapshot['jobId']);
        $snapshot['action'] = $operation;
        if ($operation === 'update') {
            // A thin follow-up event can supersede an unstarted address-reset
            // intent. Carry that one-time generation until it is applied; a new
            // explicit fixed address replaces it instead of reviving it later.
            $pending = $this->query("SELECT payload FROM jobs WHERE service_id=:sid AND operation='update'
                AND status IN ('pending','running') ORDER BY version DESC LIMIT 1", ['sid'=>$sid]);
            $previous = $pending ? $this->payload($pending[0]) : [];
            if ((int)($previous['clientId'] ?? 0) === (int)($snapshot['clientId'] ?? -1)) {
                foreach (['address','address6'] as $field) {
                    $desired = array_key_exists($field,$snapshot['network'] ?? []) ? $snapshot['network'][$field] : ($snapshot[$field] ?? null);
                    $reset = $previous['_network_reset'][$field] ?? null;
                    if ($desired !== null && $desired !== '') { unset($snapshot['_network_reset'][$field]); continue; }
                    if (!is_array($reset) || !is_string($reset['generation'] ?? null) || preg_match('/^[a-f0-9]{32}$/D',$reset['generation']) !== 1
                        || !is_string($reset['previous'] ?? null) || trim($reset['previous']) === '') continue;
                    $incoming = $snapshot['_network_reset'][$field] ?? null;
                    if ($incoming !== null && (!is_array($incoming) || ($incoming['previous'] ?? null) !== $reset['previous'])) continue;
                    // UISP can deliver paired events for the same removal. The
                    // same previous address shares one allocation generation.
                    $snapshot['_network_reset'][$field] = $reset;
                }
            }
            if (empty($snapshot['_network_reset'])) unset($snapshot['_network_reset']);
        }
        if (isset($snapshot['_prior_network']) && !isset($snapshot['_prior_identity'])) {
            $applied = $this->query('SELECT applied_identity FROM service_versions WHERE service_id=:sid', ['sid' => $sid]);
            $snapshot['_prior_identity'] = $applied[0]['applied_identity'] ?? $this->identity($snapshot);
        }
        $eventKey = $snapshot['_event_id'] ?? null;
        if ($legacyKey === null && is_string($eventKey) && $eventKey !== '') {
            $legacyKey = 'event:' . $eventKey . ':' . $sid . ':' . $operation . ':' . $this->identity($snapshot);
        }
        $payload = $this->encode($snapshot);
        $fingerprint = hash('sha256', $operation . ':' . $this->canonical($snapshot));
        if ($legacyKey !== null) {
            $old = $this->query('SELECT id FROM jobs WHERE legacy_key=:key
                UNION SELECT id FROM job_receipts WHERE legacy_key=:key', ['key' => $legacyKey]);
            if ($old) { return 'Q' . $old[0]['id']; }
        }
        if ($legacyKey === null) {
            // Only the current intent is a duplicate. An earlier A cannot absorb a new A after B.
            $old = $this->query("SELECT j.id FROM jobs j JOIN service_versions v ON v.service_id=j.service_id
                AND v.version=j.version WHERE j.service_id=:sid AND j.fingerprint=:fingerprint
                AND j.status IN ('pending','running') LIMIT 1",
                ['sid' => $sid, 'fingerprint' => $fingerprint]);
            if ($old) { return 'Q' . $old[0]['id']; }
        }
        $version = 1 + (int) $this->db->querySingle('SELECT version FROM service_versions WHERE service_id=' . $sid);
        $this->execute('INSERT INTO service_versions(service_id,version) VALUES(:sid,:version)
            ON CONFLICT(service_id) DO UPDATE SET version=excluded.version',
            ['sid' => $sid, 'version' => $version]);
        // The latest desired state supersedes an unstarted update; cleanup operations remain ordered barriers.
        if ($operation === 'update' && ($legacyKey === null || str_starts_with($legacyKey, 'event:'))) {
            foreach ($this->query("SELECT id,payload,cleanup_done FROM jobs WHERE service_id=:sid AND operation='update' AND status='pending'", ['sid' => $sid]) as $pending) {
                $previousSnapshot = $this->payload($pending);
                // A completed old-account cleanup is already durably confirmed.
                // If no replacement commands were frozen before a later failure,
                // a newer desired state can replace this stale intent without
                // replaying cleanup or bypassing any possible replacement writes.
                $replaceable = !isset($previousSnapshot['_commands'])
                    && (!isset($previousSnapshot['_cleanup']) || (int)$pending['cleanup_done'] === 1);
                if ($replaceable) {
                    $this->execute("UPDATE jobs SET status='superseded',updated=:now WHERE id=:id AND status='pending'",
                        ['id' => $pending['id'], 'now' => date('c')]);
                }
            }
        }
        $identity = $this->identity($snapshot);
        $now = date('c');
        $this->execute('INSERT INTO jobs(service_id,operation,identity,version,payload,fingerprint,legacy_key,created,updated)
            VALUES(:sid,:operation,:identity,:version,:payload,:fingerprint,:legacy,:created,:updated)',
            ['sid' => $sid, 'operation' => $operation, 'identity' => $identity, 'version' => $version,
                'payload' => $payload, 'fingerprint' => $fingerprint, 'legacy' => $legacyKey,
                'created' => $now, 'updated' => $now]);
        $jobId = $this->db->lastInsertRowID();
        // The intent marker and its cleanup job commit in the same SQLite transaction.
        if ($operation === 'delete' && !empty($snapshot['_terminal'])) {
            // A terminal cleanup cannot wait forever behind an obsolete failed update.
            foreach ($this->query("SELECT * FROM jobs WHERE service_id=:sid AND status='pending' AND operation IN ('update','queues_set')",['sid'=>$sid]) as $obsolete) {
                $this->retainCancellationCleanups($obsolete);
                $this->execute("UPDATE jobs SET status='superseded',error=NULL,next_attempt=0,updated=:now WHERE id=:id AND status='pending'",['id'=>$obsolete['id'],'now'=>$now]);
            }
            $this->execute('INSERT INTO service_tombstones(service_id,version) VALUES(:sid,:version)
                ON CONFLICT(service_id) DO UPDATE SET version=excluded.version', ['sid' => $sid, 'version' => $version]);
        } elseif ($operation === 'update' && !empty($snapshot['_clear_terminal'])) {
            $this->execute('DELETE FROM service_tombstones WHERE service_id=:sid', ['sid' => $sid]);
        }
        return 'Q' . $jobId;
    }

    private function claim(?array $selected, array $attempted): ?array
    {
        $guard = $this->acquireFile('maintenance', LOCK_SH, false);
        if ($guard === null) { return null; }
        $claimedOwner = null;
        try { return $this->transaction(function () use ($selected, $attempted, $guard, &$claimedOwner) {
            if ($this->maintenanceActive()) { return null; }
            $this->recoverEventLocks();
            $this->recoverJobs();
            $where = '';
            if ($selected === null) { $where .= ' AND j.next_attempt <= ' . time(); }
            if ($selected !== null) { $where .= ' AND j.id IN (' . implode(',', $selected) . ')'; }
            if ($attempted) { $where .= ' AND j.id NOT IN (' . implode(',', $attempted) . ')'; }
            $allowedOwners = $this->serviceOwners ? " AND gate.owner NOT IN ('"
                . implode("','", array_values($this->serviceOwners)) . "')" : '';
            $rows = $this->query("SELECT j.* FROM jobs j WHERE j.status='pending' $where
                AND NOT EXISTS (SELECT 1 FROM service_tombstones terminal WHERE terminal.service_id=j.service_id
                    AND j.version>terminal.version AND j.operation IN ('update','queues_set'))
                AND NOT EXISTS (SELECT 1 FROM service_event_locks gate WHERE gate.service_id=j.service_id
                    $allowedOwners)
                AND NOT EXISTS (SELECT 1 FROM jobs earlier WHERE earlier.service_id=j.service_id
                    AND earlier.version<j.version AND earlier.status IN ('pending','running')) ORDER BY j.id LIMIT 1");
            if (!$rows) { return null; }
            $job = $rows[0];
            $job['owner'] = bin2hex(random_bytes(16));
            $this->holdOwner($job['owner'], $guard);
            $claimedOwner = $job['owner'];
            $this->execute("UPDATE jobs SET status='running',owner=:owner,owner_pid=:pid,owner_lock=:owner,
                lease_until=:lease,attempts=attempts+1,updated=:now WHERE id=:id AND status='pending'",
                ['owner' => $job['owner'], 'pid' => getmypid(), 'lease' => time() + 900,
                    'now' => date('c'), 'id' => $job['id']]);
            return $this->db->changes() === 1 ? $job : null;
        }); } catch (Throwable $error) {
            if ($claimedOwner !== null) { $this->releaseOwner($claimedOwner); }
            throw $error;
        } finally { if ($claimedOwner === null) { $this->releaseFile($guard); } }
    }

    private function finish(array $job, ?string $error, ?array &$failureAttempt = null): bool
    {
        return $this->transaction(function () use ($job, $error, &$failureAttempt) {
            $attempts = (int) $this->db->querySingle('SELECT attempts FROM jobs WHERE id=' . (int) $job['id']);
            $retry = $error === null ? 0 : time() + min(3600, 30 * (2 ** min(7, max(0, $attempts - 1))));
            $this->execute("UPDATE jobs SET status=:status,error=:error,next_attempt=:retry,owner=NULL,owner_pid=NULL,
                lease_until=NULL,owner_lock=NULL,updated=:now WHERE id=:id AND status='running' AND owner=:owner",
                ['status' => $error === null ? 'succeeded' : 'pending', 'error' => $error,
                    'retry' => $retry, 'now' => date('c'), 'id' => $job['id'], 'owner' => $job['owner']]);
            if ($this->db->changes() !== 1) { return false; }
            if ($error !== null) $failureAttempt = ['attempts'=>$attempts,'nextRetry'=>$retry];
            if ($error === null) {
                $current = $this->query('SELECT * FROM jobs WHERE id=:id', ['id' => $job['id']])[0];
                if ($current['operation'] === 'update') {
                    $applied = $this->payload($current);
                    // Confirmed state needs only current resources, never completed
                    // old-router cleanup or a one-time forced reconnect request.
                    unset($applied['_cleanup'], $applied['_prior_network'], $applied['_prior_identity'], $applied['_force_reconnect'], $applied['_previous_addresses'], $applied['_verification_stages']);
                    $this->execute('UPDATE service_versions SET applied_identity=:identity,applied_payload=:payload WHERE service_id=:sid',
                        ['identity' => $current['identity'], 'payload' => $this->encode($applied), 'sid' => (int) $current['service_id']]);
                } elseif ($current['operation'] === 'delete') {
                    $this->releaseApplied((int) $current['service_id'], $current['identity']);
                    $payload=$this->payload($current);
                    if (!empty($payload['_lifecycle_retirement'])) {
                        $sid=(int)$current['service_id'];
                        $others=$this->query("SELECT id FROM jobs WHERE service_id=:sid AND id<>:id AND status IN ('pending','running')",['sid'=>$sid,'id'=>$current['id']]);
                        if (!$others && !$this->query('SELECT id FROM cancellation_cleanups WHERE service_id=:sid LIMIT 1',['sid'=>$sid])) {
                            $this->execute('DELETE FROM retained_resources WHERE service_id=:sid',['sid'=>$sid]);
                            $this->execute('UPDATE service_versions SET applied_identity=NULL,applied_payload=NULL WHERE service_id=:sid',['sid'=>$sid]);
                            $this->execute("UPDATE jobs SET status='cleaned',prior_cleared=1,cleanup_done=1,history_scrubbed=0 WHERE service_id=:sid AND status IN ('cancelled','superseded')",['sid'=>$sid]);
                            $marker=$payload['_lifecycle_retirement'];
                            $this->writeLifecycleMeta('lifecycle:service:'.$sid,['state'=>'retired','reason'=>'lifecycle_retired','client_id'=>$marker['client_id']??null,'checked_at'=>date('c'),'job_keys'=>[]]);
                        }
                    }
                }
                if (in_array($current['operation'], ['update','delete'], true)) {
                    $this->execute("UPDATE jobs SET status='cleaned',history_scrubbed=0,updated=:now
                        WHERE service_id=:sid AND version<:version AND identity=:identity AND status IN ('cancelled','superseded')
                            AND NOT EXISTS (SELECT 1 FROM cancellation_cleanups debt WHERE debt.source_job_id=jobs.id)",
                        ['sid' => (int) $current['service_id'], 'version' => (int) $current['version'],
                            'identity' => $current['identity'], 'now' => date('c')]);
                }
            }
            $this->compactHistory();
            return true;
        });
    }

    /** A log failure cannot undo a persisted attempt or interfere with its retry. */
    private function logFailedAttempt(array $job,string $error,array $attempt): void
    {
        if (!function_exists('MyLog')) return;
        try {
            $snapshot=$this->payload($job);
            $device=filter_var($snapshot['device'] ?? $snapshot['_device']['id'] ?? null,
                FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $operation=in_array($job['operation'],['update','delete','queues_set','queues_remove'],true) ? $job['operation'] : 'unknown';
            MyLog()->Append([
                'event'=>'job_attempt_failed', 'jobId'=>'Q' . (int)$job['id'],
                'serviceId'=>(int)$job['service_id'], 'deviceId'=>$device===false ? null : $device,
                'operation'=>$operation, 'attempts'=>(int)$attempt['attempts'],
                'nextRetry'=>date('c',(int)$attempt['nextRetry']), 'errorCode'=>$this->safeFailureCode($error),
            ] + $this->networkFailureContext($error) + $this->ownershipFailureContext($error),LOG_ERR);
        } catch (Throwable $ignored) {}
    }

    private function safeFailureCode(string $error): string
    {
        if (in_array($error,['lifecycle_unavailable','lifecycle_invalid','lifecycle_identity_changed','lifecycle_reactivated',
            'lifecycle_router_identity_unknown','lifecycle_resources_unconfirmed','lifecycle_resources_remain',
            'lifecycle_changed','lifecycle_service_busy','lifecycle_resource_limit','lifecycle_review_pending'],true)) return $error;
        if ($this->networkFailureContext($error)) return strpos($error, 'network_address_conflict:') === 0 ? 'network_address_conflict' : 'invalid_network_address';
        if ($this->ownershipFailureContext($error)) return 'router_ownership_conflict';
        if (in_array($error, ['router_ownership_record_changed', 'site_address_resources_changed', 'site_address_prepared_guard_changed'], true)) return $error;
        foreach (['router_session_profile_missing','router_session_profile_lookup_failed',
            'router_session_profile_ambiguous','router_session_profile_invalid_identity',
            'router_session_profile_bootstrap_failed','router_session_profile_bootstrap_invalid_configuration',
            'router_tcp_connect_failed','router_tls_connect_failed','router_login_rejected','router_login_timeout',
            'router_login_connection_closed','router_login_protocol_error','router_login_write_failed'] as $code) {
            if ($error===$code || strpos($error,$code . ':')===0 || strpos($error,$code . ' ')===0) return $code;
        }
        $known=[
            'The configured session profile could not be verified'=>'router_session_profile_unverified',
            'device connect failed'=>'router_connection_failed',
            'RouterOS connection failed'=>'router_connection_failed',
            'The queued service has no valid RouterOS device'=>'router_device_invalid',
            'Operation did not complete'=>'operation_incomplete',
            'Not every router command completed'=>'router_command_incomplete',
            'No account could be prepared; verify its credentials and IP pool'=>'account_preparation_failed',
            'No IPv4 address is available for the PPP service'=>'ppp_ipv4_unavailable',
            'No address is available for the DHCP service'=>'dhcp_address_unavailable',
            'The matching RouterOS account or queue belongs to another service or was created manually'=>'router_ownership_failed',
            'A RouterOS profile with this name is not owned by the plugin'=>'router_ownership_failed',
            'A RouterOS resource with this reserved name is not owned by the plugin'=>'router_ownership_failed',
        ];
        if (isset($known[$error])) return $known[$error];
        if (strpos($error,'invalid_provisioning_number:')===0) return 'invalid_provisioning_number';
        return 'provisioning_failed';
    }

    private function ownershipFailureContext(string $error): array
    {
        if (!preg_match('/^router_ownership_conflict: current_service=([0-9]+) previous_service=([0-9]+) reason=([a-z_]{1,48})$/D', $error, $match)) return [];
        if (!in_array($match[3], ['account_claimed','backup_failed','catalog_incomplete','client_unverified',
            'identity_unverified','mapping_unverified','peer_site_unverified','previous_active','previous_changed',
            'previous_job_pending','previous_unverified','queue_unverified','router_changed','target_changed',
            'target_unverified','verification_unavailable'], true)) return [];
        $current = filter_var($match[1], FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
        $previous = filter_var($match[2], FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
        if ($current === false || $previous === false) return [];
        return ['requestedServiceId'=>$current, 'previousServiceId'=>$previous, 'ownershipReason'=>$match[3]];
    }

    private function networkFailureContext(string $error): array
    {
        if (!preg_match('/^(invalid_network_address|network_address_conflict): requested_service=([0-9]+) blocking_service=([0-9]+) source=(input|reserved|cache|applied) family=(IPv4|IPv6)$/D', $error, $match)) return [];
        if ($match[1] === 'invalid_network_address' && $match[4] === 'cache'
            || $match[1] === 'network_address_conflict' && $match[4] === 'input') return [];
        $requested = filter_var($match[2], FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
        $blocking = filter_var($match[3], FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
        if ($requested === false || $blocking === false) return [];
        return ['requestedServiceId'=>$requested, 'blockingServiceId'=>$blocking,
            'addressSource'=>$match[4], 'addressFamily'=>$match[5]];
    }

    private function releaseApplied(int $serviceId, string $identity): void
    {
        $this->execute('UPDATE service_versions SET applied_identity=NULL,applied_payload=NULL
            WHERE service_id=:sid AND (applied_identity=:identity OR applied_identity IS NULL)',
            ['sid' => $serviceId, 'identity' => $identity]);
        foreach ($this->query("SELECT id,payload FROM jobs WHERE service_id=:sid
            AND status IN ('pending','running','cancelled','superseded')", ['sid' => $serviceId]) as $held) {
            if (($this->payload($held)['_prior_identity'] ?? null) === $identity) {
                $this->execute('UPDATE jobs SET prior_cleared=1,history_scrubbed=0 WHERE id=:id', ['id' => $held['id']]);
            }
        }
    }

    private function prepared(array $snapshot): bool
    {
        return isset($snapshot['_commands']) || isset($snapshot['_cleanup']);
    }

    private function canCancelJob(array $job): bool
    {
        return ($job['status'] ?? null) === 'pending';
    }

    private function seedCancellationCleanups(): void
    {
        $key = 'cancellation_cleanups_seeded_v1';
        if ($this->query('SELECT key FROM queue_meta WHERE key=:key', ['key' => $key])) return;
        foreach ($this->query("SELECT * FROM jobs WHERE status IN ('cancelled','superseded') ORDER BY service_id,version") as $job) {
            $this->retainCancellationCleanups($job);
        }
        $this->execute('INSERT INTO queue_meta(key,value) VALUES(:key,:value)', ['key' => $key, 'value' => date('c')]);
    }

    private function retainCancellationCleanups(array $job): void
    {
        $snapshot = $this->payload($job);
        $legacyKey = $job['legacy_key'] ?? null;
        // Compacted event history has no payload event ID; its durable key retains the origin.
        $eventSuffix = ':' . $job['service_id'] . ':' . $job['operation'] . ':' . $job['identity'];
        $nativeEvent = is_string($legacyKey) && str_starts_with($legacyKey, 'event:')
            && strlen($legacyKey) > 6 + strlen($eventSuffix) && str_ends_with($legacyKey, $eventSuffix);
        $uncertainAttempt = (int) ($job['attempts'] ?? 0) > 0 || ($legacyKey !== null && !$nativeEvent);
        // An unstarted native update only borrowed a prior reservation. Its cancellation
        // cannot have changed RouterOS; applied state already tracks the actual account.
        if (!$this->prepared($snapshot) && !$uncertainAttempt) return;
        $parts = [];
        if (isset($snapshot['_cleanup']) && is_array($snapshot['_cleanup']) && empty($job['cleanup_done'])) {
            $parts['cleanup'] = $snapshot['_cleanup'];
        }
        $applied = $this->appliedSnapshot((int) $job['service_id']);
        if ($applied) $parts['applied'] = $applied;
        if (isset($snapshot['_commands'])) {
            $own = $snapshot;
            unset($own['_cleanup']);
            $parts['commands'] = $own;
        }
        foreach ($parts as $slot => $part) {
            $private = $this->withoutCredentials($part);
            // A retired endpoint may no longer match the current router. Keep only its transport
            // password in this private debt table; account secrets never enter reconciliation.
            if (isset($part['_device']) && is_array($part['_device']) && array_key_exists('password', $part['_device'])) {
                $private['_device']['password'] = $part['_device']['password'];
            }
            $this->execute('INSERT OR IGNORE INTO cancellation_cleanups
                (source_job_id,service_id,source_version,slot,payload,identity) VALUES(:job,:sid,:version,:slot,:payload,:identity)',
                ['job' => (int) $job['id'], 'sid' => (int) $job['service_id'], 'version' => (int) $job['version'],
                    'slot' => $slot, 'payload' => $this->encode($private), 'identity' => $this->identity($part)]);
        }
    }

    private function importLegacy(): void
    {
        if ($this->legacy === '' || !is_file($this->legacy)) { return; }
        $json = file_get_contents($this->legacy);
        if ($json === false) { throw new RuntimeException('Could not read the legacy job queue'); }
        if (trim($json) === '') { return; }
        $entries = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($entries)) { throw new RuntimeException('The legacy job queue must contain an array or object'); }
        if (!$entries) { return; }
        $source = 'legacy:' . hash('sha256', $json);
        if ($this->query('SELECT key FROM queue_meta WHERE key=:key', ['key' => $source])) {
            $this->retireLegacy($json);
            return;
        }
        $this->transaction(function () use ($entries, $source) {
            foreach ($entries as $key => $entry) {
                if (!is_array($entry)) { throw new RuntimeException('Invalid legacy job: ' . $key); }
                $this->validateSnapshotNumbers($entry);
                $legacyKey = (string) $key . ':' . hash('sha256', $this->canonical($entry));
                $this->insertJob($entry, $entry['action'] ?? 'update', $legacyKey);
            }
            $this->execute('INSERT OR IGNORE INTO queue_meta(key,value) VALUES(:key,:value)',
                ['key' => $source, 'value' => date('c')]);
        });
        $this->retireLegacy($json);
    }

    /** The SQLite transaction is durable first; never remove a source changed since that import. */
    private function retireLegacy(string $imported): void
    {
        if (!is_file($this->legacy)) { return; }
        $current = file_get_contents($this->legacy);
        if ($current === false || !hash_equals(hash('sha256', $imported), hash('sha256', $current))) { return; }
        if (!@unlink($this->legacy) && function_exists('MyLog')) {
            MyLog()->Append('Imported legacy queue could not be removed; its private file may still contain credentials', LOG_WARNING);
        }
    }

    private function identity(array $snapshot): string
    {
        $device = $snapshot['_device'] ?? [];
        return hash('sha256', $this->canonical([
            'device' => $snapshot['device'] ?? null, 'ip' => $device['ip'] ?? null,
            'port' => $device['port'] ?? null, 'username' => $snapshot['username'] ?? null,
            'mac' => $snapshot['mac'] ?? null, 'hotspot' => $snapshot['hotspot'] ?? null,
            'duid' => $snapshot['duid'] ?? null, 'iaid' => $snapshot['iaid'] ?? null
        ]));
    }

    public static function collectAddresses(array &$result, int $serviceId, array $snapshot, bool $ipv6, bool $includePrior): void
    {
        $values = [$snapshot['address'] ?? null, $snapshot['address6'] ?? null,
            $snapshot['network']['address'] ?? null, $snapshot['network']['address6'] ?? null];
        foreach ($snapshot['_previous_addresses'] ?? [] as $previous) $values[] = $previous;
        if ($includePrior) {
            $values[] = $snapshot['_prior_network']['address'] ?? null;
            $values[] = $snapshot['_prior_network']['address6'] ?? null;
        }
        foreach ($snapshot['_commands'] ?? [] as $command) {
            foreach (['remote-address','remote-ipv6-prefix'] as $field) { $values[] = $command[$field] ?? null; }
            $path = trim($command['path'] ?? '', '/');
            if (in_array($path, ['ip/dhcp-server/lease','ipv6/dhcp-server/binding','ip/hotspot/user'], true)) {
                $values[] = $command['address'] ?? null;
            }
            if ($path === 'queue/simple' && ($command['comment'] ?? '') !== 'do not delete'
                && !str_ends_with($command['name'] ?? '', '-parent')) { $values[] = $command['target'] ?? null; }
        }
        $field = $ipv6 ? 'address6' : 'address';
        foreach ($values as $value) {
            if (!is_string($value)) { continue; }
            foreach (explode(',', $value) as $candidate) {
                $parts = explode('/', trim($candidate));
                if (count($parts) > 2) { continue; }
                $host = $parts[0];
                $family = $ipv6 ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;
                if (!filter_var($host, FILTER_VALIDATE_IP, $family)) { continue; }
                if (isset($parts[1]) && (!ctype_digit($parts[1]) || (int) $parts[1] > ($ipv6 ? 128 : 32))) { continue; }
                $address = inet_ntop(inet_pton($host));
                if (isset($parts[1])) { $address .= '/' . (int) $parts[1]; }
                $result[$serviceId . ':' . $address] = ['id' => $serviceId, $field => $address];
            }
        }
    }

    private function processAlive(int $pid): bool
    {
        if ($pid < 1) { return false; }
        if ($pid === getmypid()) { return true; }
        if (function_exists('posix_kill')) { return @posix_kill($pid, 0); }
        if (PHP_OS_FAMILY === 'Linux') { return is_dir('/proc/' . $pid); }
        // On hosts without process inspection, do not duplicate a possibly active write.
        return true;
    }

    /** flock is tied to this request's open file, rather than to a reusable worker PID. */
    private function acquireFile(string $name, int $mode, bool $required = true)
    {
        if (!preg_match('/^(maintenance|[a-f0-9]{32}|(?:client|resource)-[a-f0-9]{64})$/D', $name)) { throw new InvalidArgumentException('Invalid lock name'); }
        $handle = fopen($this->lockDirectory . DIRECTORY_SEPARATOR . $name . '.lock', 'c');
        if ($handle === false) { throw new RuntimeException('Could not open a provisioning lock'); }
        if (!flock($handle, $mode | LOCK_NB)) {
            fclose($handle);
            if (!$required) { return null; }
            throw new RuntimeException('A provisioning or maintenance operation is already running; retry this action');
        }
        return $handle;
    }

    private function releaseFile($handle): void
    {
        if (is_resource($handle)) { flock($handle, LOCK_UN); fclose($handle); }
    }

    private function holdOwner(string $owner, $guard = null): void
    {
        $guard ??= $this->acquireFile('maintenance', LOCK_SH);
        try { $handle = $this->acquireFile($owner, LOCK_EX); }
        catch (Throwable $error) { $this->releaseFile($guard); throw $error; }
        $this->ownerLocks[$owner] = [$handle, $guard];
    }

    private function releaseOwner(string $owner): void
    {
        foreach ($this->ownerLocks[$owner] ?? [] as $handle) { $this->releaseFile($handle); }
        unset($this->ownerLocks[$owner]);
        $path = $this->lockDirectory . DIRECTORY_SEPARATOR . $owner . '.lock';
        if (is_file($path)) { @unlink($path); }
    }

    private function ownerAlive(?string $ownerLock, int $pid, int $lease): bool
    {
        if ($ownerLock === null || $ownerLock === '') {
            // Pre-upgrade owners do not have a request lock. Do not overlap a possibly active old write.
            return $lease >= time() || $this->processAlive($pid);
        }
        $probe = $this->acquireFile($ownerLock, LOCK_EX, false);
        if ($probe === null) { return true; }
        $this->releaseFile($probe);
        $path = $this->lockDirectory . DIRECTORY_SEPARATOR . $ownerLock . '.lock';
        if (is_file($path)) { @unlink($path); }
        return false;
    }

    private function recoverJobs(): void
    {
        foreach ($this->query("SELECT id,owner,owner_pid,owner_lock,lease_until FROM jobs WHERE status='running'") as $stale) {
            if (!$this->ownerAlive($stale['owner_lock'], (int) $stale['owner_pid'], (int) $stale['lease_until'])) {
                $this->execute("UPDATE jobs SET status='pending',owner=NULL,owner_pid=NULL,owner_lock=NULL,lease_until=NULL
                    WHERE id=:id AND owner=:owner", ['id' => $stale['id'], 'owner' => $stale['owner']]);
            }
        }
    }

    private function maintenanceActive(): bool
    {
        $row = $this->query('SELECT value FROM queue_meta WHERE key=:key', ['key' => 'maintenance']);
        if (!$row) { return false; }
        $guard = json_decode($row[0]['value'], true, 512, JSON_THROW_ON_ERROR);
        if (!$this->ownerAlive($guard['owner_lock'] ?? null, (int) ($guard['pid'] ?? 0), (int) ($guard['lease'] ?? 0))) {
            $this->execute('DELETE FROM queue_meta WHERE key=:key', ['key' => 'maintenance']);
            return false;
        }
        return true;
    }

    private function recoverEventLocks(): void
    {
        foreach ($this->query('SELECT service_id,owner,owner_pid,owner_lock,lease_until FROM service_event_locks') as $lock) {
            if (!$this->ownerAlive($lock['owner_lock'], (int) $lock['owner_pid'], (int) $lock['lease_until'])) {
                $this->execute('DELETE FROM service_event_locks WHERE service_id=:sid AND owner=:owner',
                    ['sid' => $lock['service_id'], 'owner' => $lock['owner']]);
            }
        }
    }

    /** One-time cleanup of already confirmed snapshots written by older versions. */
    private function compactAppliedHistory(): void
    {
        $key = 'applied_cleanup_compacted_v1';
        if ($this->query('SELECT key FROM queue_meta WHERE key=:key', ['key' => $key])) { return; }
        foreach ($this->query('SELECT service_id,applied_payload FROM service_versions WHERE applied_payload IS NOT NULL
            AND (instr(applied_payload,\'"_cleanup"\')>0 OR instr(applied_payload,\'"_prior_network"\')>0
                OR instr(applied_payload,\'"_prior_identity"\')>0)') as $row) {
            $applied = json_decode($row['applied_payload'], true, 512, JSON_THROW_ON_ERROR);
            unset($applied['_cleanup'], $applied['_prior_network'], $applied['_prior_identity']);
            $this->execute('UPDATE service_versions SET applied_payload=:payload WHERE service_id=:sid',
                ['payload' => $this->encode($applied), 'sid' => $row['service_id']]);
        }
        $this->execute('INSERT INTO queue_meta(key,value) VALUES(:key,:value)', ['key' => $key, 'value' => date('c')]);
    }

    /** Never retain retry credentials in finished audit rows; preserve only UUID receipts after 30 days. */
    private function compactHistory(): void
    {
        $cutoff = date('c', time() - 30 * 86400);
        foreach ($this->query("SELECT * FROM jobs WHERE status IN ('succeeded','cleaned','cancelled','superseded')
            AND (history_scrubbed=0 OR updated<:cutoff)", ['cutoff' => $cutoff]) as $row) {
            $snapshot = $this->payload($row);
            if (!empty($row['cleanup_done']) && in_array($row['status'], ['cancelled','superseded'], true)) {
                unset($snapshot['_cleanup']);
            }
            $terminal = in_array($row['status'], ['succeeded', 'cleaned'], true);
            $summary = $this->historySummary($snapshot);
            if ($terminal) {
                $replacement = $summary;
            } else {
                // A cancelled/superseded row may still reserve resources, so keep its addresses and identity.
                $replacement = $this->withoutCredentials($snapshot);
                $prior = $snapshot['_prior_network'] ?? [];
                $holdsPrior = !$row['prior_cleared'] && array_filter($prior, fn($value): bool => $value !== null && $value !== '');
                $terminal = !$this->prepared($snapshot) && !$holdsPrior
                    && !$this->query('SELECT id FROM cancellation_cleanups WHERE source_job_id=:id LIMIT 1', ['id' => $row['id']]);
                if ($terminal) { $replacement = $summary; }
            }
            $encoded = $this->encode($replacement);
            $this->execute('UPDATE jobs SET payload=:payload,error=NULL,history_scrubbed=1 WHERE id=:id',
                ['payload' => $encoded, 'id' => $row['id']]);
            if ($terminal && strcmp($row['updated'], $cutoff) < 0) {
                if ($row['legacy_key'] !== null) {
                    $this->execute('INSERT OR IGNORE INTO job_receipts(id,legacy_key,status,payload) VALUES(:id,:key,:status,:payload)',
                        ['id' => $row['id'], 'key' => $row['legacy_key'], 'status' => $row['status'], 'payload' => $this->encode($summary)]);
                }
                $this->execute('DELETE FROM jobs WHERE id=:id', ['id' => $row['id']]);
            }
        }
    }

    private function historySummary(array $snapshot): array
    {
        return array_intersect_key($snapshot, array_flip(['id', 'clientId', 'company', 'firstName', 'lastName', 'action', '_terminal', '_clear_terminal']));
    }

    private function withoutCredentials(array $snapshot): array
    {
        foreach ($snapshot as $key => $value) {
            if (is_string($key) && preg_match('/pass|token|secret|credential|authorization/i', $key)) { unset($snapshot[$key]); }
            elseif (is_array($value)) { $snapshot[$key] = $this->withoutCredentials($value); }
        }
        return $snapshot;
    }

    private function ids(array $keys): array
    {
        $ids = [];
        foreach ($keys as $key) {
            if (is_int($key) || (is_string($key) && preg_match('/^Q?[1-9][0-9]*$/D', $key))) {
                $id = (int) ltrim((string) $key, 'Q');
                if ($id > 0) { $ids[] = $id; }
            }
        }
        return array_values(array_unique($ids));
    }

    private function payload(array $job): array
    {
        return json_decode($job['payload'], true, 512, JSON_THROW_ON_ERROR);
    }

    private function canonical(array $value): string
    {
        $sort = static function (array $item) use (&$sort): array {
            ksort($item);
            foreach ($item as $key => $value) {
                if (is_array($value)) { $item[$key] = $sort($value); }
            }
            return $item;
        };
        $sorted = $sort($value);
        $sort = null;
        return $this->encode($sorted);
    }

    private function encode(array $value): string
    {
        $this->validateSnapshotNumbers($value);
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function validateSnapshotNumbers(array $snapshot): void
    {
        require_once __DIR__ . '/data.php';
        Data::validateSnapshotNumbers($snapshot, (int)($snapshot['id'] ?? 0), (int)($snapshot['_plan']['id'] ?? $snapshot['planId'] ?? 0));
    }

    private function publicPayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && (str_starts_with($key, '_') || preg_match('/pass|token|secret|credential/i', $key))) {
                unset($payload[$key]);
            } elseif (is_array($value)) {
                $payload[$key] = $this->publicPayload($value);
            }
        }
        return $payload;
    }

    private function transaction(callable $operation)
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $result = $operation();
            $this->db->exec('COMMIT');
            return $result;
        } catch (Throwable $error) {
            $this->db->exec('ROLLBACK');
            throw $error;
        }
    }

    private function execute(string $sql, array $values = []): SQLite3Result
    {
        $statement = $this->db->prepare($sql);
        foreach ($values as $key => $value) {
            $type = $value === null ? SQLITE3_NULL : (is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
            $statement->bindValue(':' . $key, $value, $type);
        }
        return $statement->execute();
    }

    private function query(string $sql, array $values = []): array
    {
        $result = $this->execute($sql, $values);
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $rows[] = $row; }
        $result->finalize();
        return $rows;
    }
}

function myJobQueue(): ApiJobQueue
{
    static $queue = null;
    if ($queue === null) { $queue = new ApiJobQueue(); }
    return $queue;
}
