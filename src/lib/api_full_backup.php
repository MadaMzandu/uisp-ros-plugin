<?php

require_once __DIR__ . '/api_backup.php';

/** Complete local state. No method contacts UISP or RouterOS or executes a job. */
final class ApiFullBackup
{
    private const FORMAT = 'uisp-ros-full-backup';
    private const MAX_FILES = 2048;
    private const MAX_FILE = 268435456;
    private const MAX_TOTAL = 536870912;
    private const TTL = 600;
    private string $data;
    private $clock;
    private $failure;

    /** The optional failure callback is used only by isolated rollback regressions. */
    public function __construct(string $dataDirectory = 'data', ?callable $clock = null, ?callable $failure = null)
    { $this->data = rtrim($dataDirectory, '/\\'); $this->clock = $clock; $this->failure = $failure; }

    public static function backupName($name): bool
    { return is_string($name) && preg_match('/^full-backup-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{32}\.zip$/D', $name) === 1; }

    public function create(): array
    {
        $locks = $this->locks();
        try {
            $this->assertIdle();
            return $this->createLocked();
        } finally { self::unlock($locks); }
    }

    public function listing(): array
    {
        $items = [];
        try {
            if (!is_dir($this->data . '/full-backups')) return ['available'=>true,'items'=>[],'hasMore'=>false];
            $this->privateDirectory(false);
            foreach (new DirectoryIterator($this->data . '/full-backups') as $entry) {
                if (!$entry->isFile() || $entry->isLink() || !self::backupName($entry->getFilename())) continue;
                $items[] = ['name'=>$entry->getFilename(),'bytes'=>$entry->getSize(),'createdAt'=>gmdate('Y-m-d\TH:i:s\Z',$entry->getMTime())];
                if (count($items)>100) break;
            }
            usort($items,static fn(array $a,array $b):int=>strcmp($b['name'],$a['name']));
            return ['available'=>true,'items'=>array_slice($items,0,20),'hasMore'=>count($items)>20];
        } catch (Throwable $ignored) { return ['available'=>false,'items'=>[],'hasMore'=>false]; }
    }

    public function preview(string $name): array
    {
        $path = $this->archivePath($name);
        $working = $this->working();
        $locks = [];
        try {
            $manifest = $this->unpack($path,$working . '/candidate');
            $summary = $this->validateSet($working . '/candidate',$manifest['files']);
            $locks = $this->locks(); $this->assertIdle();
            $fingerprint = $this->fingerprint();
            $current = $this->summary($this->data);
            $token = bin2hex(random_bytes(32));
            $this->prunePreviews();
            $state = ['schema'=>1,'expires'=>(int)$this->now()+self::TTL,'name'=>$name,'sha256'=>hash_file('sha256',$path),
                'identity'=>$this->identity(),'current'=>$fingerprint,'work'=>basename($working)];
            $this->atomic($this->previewPath($token),json_encode($state,JSON_THROW_ON_ERROR));
            return ['previewToken'=>$token,'expiresAt'=>gmdate('Y-m-d\TH:i:s\Z',$state['expires']),
                'candidate'=>$summary+['createdAt'=>$manifest['createdAt'],'pluginVersion'=>$manifest['pluginVersion'],'name'=>$name],
                'current'=>$current,'changes'=>['replacesLocalState'=>true,'pendingJobsRestored'=>$summary['pendingJobs']],
                'preservedHostIntegration'=>true,'routerChanges'=>false,'crmChanges'=>false,
                'jobsMayResumeOnNextScheduledRun'=>true];
        } catch (Throwable $error) { self::removeTree($working); throw $error; }
        finally { self::unlock($locks); }
    }

    public function previewUpload(array $file): array
    {
        if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_string($file['tmp_name']??null)
            || !is_uploaded_file($file['tmp_name']) || filesize($file['tmp_name'])>self::MAX_TOTAL)
            throw new InvalidArgumentException('full_backup_upload_invalid');
        $name = $this->newName(); $path = $this->archivePath($name,false);
        if (!move_uploaded_file($file['tmp_name'],$path) || !chmod($path,0600)) throw new RuntimeException('full_backup_upload_failed');
        try { return $this->preview($name); }
        catch (Throwable $error) { @unlink($path); throw $error; }
    }

    public function apply(string $token): array
    {
        $tokenPath = $this->previewPath($token); $locks = []; $stream = null; $journal = false;
        try {
            self::regular($tokenPath,8192); $stream = fopen($tokenPath,'rb');
            if (!$stream || !flock($stream,LOCK_EX|LOCK_NB)) throw new InvalidArgumentException('full_backup_preview_invalid');
            $state = json_decode((string)stream_get_contents($stream),true,12,JSON_THROW_ON_ERROR);
            if (!is_array($state) || ($state['schema']??null)!==1 || !is_int($state['expires']??null)
                || $state['expires']<=$this->now() || ($state['identity']??null)!==$this->identity()
                || !is_string($state['current']??null) || !is_string($state['sha256']??null)
                || !preg_match('/^work-[a-f0-9]{32}$/D',$state['work']??'')) throw new InvalidArgumentException('full_backup_preview_invalid');
            $path = $this->archivePath($state['name']??'');
            if (!hash_equals($state['sha256'],hash_file('sha256',$path))) throw new InvalidArgumentException('full_backup_changed');
            $working = $this->data . '/full-backups/' . $state['work'];
            if (is_link($working) || !is_dir($working)) throw new InvalidArgumentException('full_backup_preview_invalid');
            $candidatePath = $working . '/apply-' . bin2hex(random_bytes(8));
            $manifest = $this->unpack($path,$candidatePath);
            $this->validateSet($candidatePath,$manifest['files']);
            $locks = $this->locks(); $this->assertIdle();
            if (!hash_equals($state['current'],$this->fingerprint())) throw new InvalidArgumentException('full_backup_live_state_changed');
            // Consume before mutation. An ambiguous browser retry cannot repeat a restore.
            flock($stream,LOCK_UN); fclose($stream); $stream = null;
            if (!unlink($tokenPath)) throw new InvalidArgumentException('full_backup_preview_invalid');
            $before = $this->createLocked();
            $previous = $this->capture($working . '/rollback');
            $all = array_values(array_unique(array_merge(array_keys($previous),array_keys($manifest['files']))));
            sort($all,SORT_STRING);
            if (count($all)>self::MAX_FILES) throw new InvalidArgumentException('full_backup_file_limit');
            $record = ['schema'=>1,'work'=>$state['work'],'previous'=>$previous,'files'=>$all,'backupName'=>$before['name']];
            $this->atomic($this->journalPath(),json_encode($record,JSON_THROW_ON_ERROR)); $journal = true;
            foreach ($all as $index=>$file) {
                if ($this->failure!==null) ($this->failure)($file,$index);
                $this->replace($file,isset($manifest['files'][$file])?$candidatePath . '/' . $file:null);
            }
            $this->validateSet($this->data,array_combine(array_map(static fn($file)=>'data/'.$file,$this->files()),array_fill(0,count($this->files()),[])),true);
            if (!unlink($this->journalPath())) throw new RuntimeException('full_backup_restore_finalize_failed');
            $journal = false; self::removeTree($working);
            return ['restored'=>true,'backupName'=>$before['name'],'requiresReload'=>true,'preservedHostIntegration'=>true,
                'jobsMayResumeOnNextScheduledRun'=>true,'routerChanges'=>false,'crmChanges'=>false];
        } catch (Throwable $error) {
            if ($journal) {
                try { $this->rollbackLocked(); }
                catch (Throwable $rollback) { throw new RuntimeException('full_backup_recovery_required',0,$rollback); }
            }
            throw $error;
        } finally {
            if (is_resource($stream)) { flock($stream,LOCK_UN); fclose($stream); }
            self::unlock($locks);
        }
    }

    /** Call before setup, queue initialization, cache reads or any request mutation. */
    public static function recoverPending(string $dataDirectory = 'data'): bool
    {
        $api = new self($dataDirectory);
        if (!file_exists($api->journalPath()) && !is_link($api->journalPath())) return false;
        $locks = $api->locks(true);
        try { $api->rollbackLocked(); return true; }
        finally { self::unlock($locks); }
    }

    public static function download(string $name, string $dataDirectory = 'data'): void
    {
        $api = new self($dataDirectory); $path = $api->archivePath($name);
        $stream = fopen($path,'rb'); if (!$stream) throw new RuntimeException('full_backup_download_failed');
        try {
            header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="'.$name.'"');
            header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
            header('Content-Length: '.fstat($stream)['size']); fpassthru($stream);
        } finally { fclose($stream); }
    }

    private function createLocked(): array
    {
        $working = $this->working(); $temporary = null;
        try {
            $files = $this->capture($working . '/snapshot',true);
            $summary = $this->validateSet($working . '/snapshot',$files);
            $manifest = ['schema'=>1,'format'=>self::FORMAT,'createdAt'=>gmdate('Y-m-d\TH:i:s\Z',(int)$this->now()),
                'pluginVersion'=>MY_VERSION,'files'=>$files,'preservedHostIntegration'=>true];
            $name = $this->newName(); $path = $this->archivePath($name,false); $temporary = $path . '.tmp';
            $zip = new ZipArchive();
            if ($zip->open($temporary,ZipArchive::CREATE|ZipArchive::EXCL)!==true) throw new RuntimeException('full_backup_create_failed');
            try {
                if (!$zip->addFromString('manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES))) throw new RuntimeException('full_backup_create_failed');
                foreach ($files as $file=>$info) if (!$zip->addFile($working . '/snapshot/' . $file,$file)) throw new RuntimeException('full_backup_create_failed');
            } finally { if (!$zip->close()) throw new RuntimeException('full_backup_create_failed'); }
            if (!chmod($temporary,0600) || !rename($temporary,$path)) throw new RuntimeException('full_backup_create_failed');
            $temporary = null;
            return ['name'=>$name,'createdAt'=>$manifest['createdAt'],'bytes'=>filesize($path),'summary'=>$summary];
        } finally { if ($temporary!==null && is_file($temporary)) @unlink($temporary); self::removeTree($working); }
    }

    /** Files use archive-relative data/... names, never paths supplied by a manifest. */
    private function capture(string $destination, bool $ensureCache = false): array
    {
        if (!mkdir($destination,0700,true) || !mkdir($destination . '/data',0700)) throw new RuntimeException('full_backup_storage_failed');
        $files = []; $total = 0;
        foreach ($this->files() as $file) {
            $source = $this->data . '/' . $file; self::regular($source,self::MAX_FILE);
            $target = $destination . '/data/' . $file;
            if (substr($file,-3)==='.db') self::sqliteCopy($source,$target);
            elseif (!copy($source,$target) || !chmod($target,0600)) throw new RuntimeException('full_backup_snapshot_failed');
            $size = filesize($target); $total += $size;
            if ($total>self::MAX_TOTAL) throw new InvalidArgumentException('full_backup_size_limit');
            $files['data/'.$file] = ['size'=>$size,'sha256'=>hash_file('sha256',$target)];
        }
        if ($ensureCache && !isset($files['data/cache.db'])) {
            $cache = $destination . '/data/cache.db'; $db = new SQLite3($cache);
            try { if (!$db->exec(file_get_contents(__DIR__ . '/../includes/cache.sql'))) throw new RuntimeException('full_backup_snapshot_failed'); }
            finally { $db->close(); }
            chmod($cache,0600); $files['data/cache.db']=['size'=>filesize($cache),'sha256'=>hash_file('sha256',$cache)];
        }
        if (!isset($files['data/data.db'],$files['data/jobs.db'])) throw new InvalidArgumentException('full_backup_state_missing');
        ksort($files); return $files;
    }

    private function files(): array
    {
        if (is_link($this->data) || !is_dir($this->data)) throw new InvalidArgumentException('full_backup_state_missing');
        $files = [];
        foreach (new DirectoryIterator($this->data) as $entry) {
            if (!$this->allowed('data/'.$entry->getFilename())) continue;
            if ($entry->isLink() || !$entry->isFile()) throw new InvalidArgumentException('full_backup_unsafe_path');
            $files[] = $entry->getFilename();
            if (count($files)>self::MAX_FILES) throw new InvalidArgumentException('full_backup_file_limit');
        }
        sort($files,SORT_STRING); return $files;
    }

    private function allowed(string $name): bool
    {
        return in_array($name,['data/data.db','data/jobs.db','data/cache.db','data/config.json','data/state.json','data/queue.json'],true)
            || preg_match('~^data/router-resolution-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}\.json$~D',$name)===1
            || preg_match('~^data/(?:provisioning-verification-[1-9][0-9]*|crm-network-(?:history|correction)-[1-9][0-9]*-(?:address|address6))\.json$~D',$name)===1;
    }

    private function unpack(string $archive, string $destination): array
    {
        self::regular($archive,self::MAX_TOTAL);
        if (file_exists($destination) || !mkdir($destination,0700,true)) throw new InvalidArgumentException('full_backup_preview_invalid');
        $zip = new ZipArchive();
        if ($zip->open($archive)!==true) throw new InvalidArgumentException('full_backup_archive_invalid');
        try {
            if ($zip->numFiles<3 || $zip->numFiles>self::MAX_FILES+1) throw new InvalidArgumentException('full_backup_file_limit');
            $raw = $zip->getFromName('manifest.json',524289);
            if (!is_string($raw) || strlen($raw)>524288) throw new InvalidArgumentException('full_backup_manifest_invalid');
            $manifest = json_decode($raw,true,12,JSON_THROW_ON_ERROR);
            if (!is_array($manifest) || ($manifest['schema']??null)!==1 || ($manifest['format']??null)!==self::FORMAT
                || !is_string($manifest['createdAt']??null) || strlen($manifest['createdAt'])>32
                || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$manifest['createdAt'])
                || !is_string($manifest['pluginVersion']??null) || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D',$manifest['pluginVersion'])
                || version_compare($manifest['pluginVersion'],MY_VERSION,'>') || !is_array($manifest['files']??null)
                || !isset($manifest['files']['data/data.db'],$manifest['files']['data/jobs.db'],$manifest['files']['data/cache.db'])) throw new InvalidArgumentException('full_backup_manifest_invalid');
            $seen = []; $total = 0;
            for ($index=0;$index<$zip->numFiles;$index++) {
                $info = $zip->statIndex($index); $name = $info['name'];
                if (isset($seen[$name]) || ($name!=='manifest.json' && !$this->allowed($name)) || strpos($name,'\\')!==false
                    || ($info['size']??self::MAX_FILE+1)>self::MAX_FILE || !empty($info['encryption_method'])) throw new InvalidArgumentException('full_backup_unsafe_entry');
                $seen[$name] = true; $total += $info['size'];
                if ($total>self::MAX_TOTAL) throw new InvalidArgumentException('full_backup_size_limit');
                $os = 0; $attributes = 0;
                if ($zip->getExternalAttributesIndex($index,$os,$attributes) && (($attributes>>16)&0170000)===0120000)
                    throw new InvalidArgumentException('full_backup_unsafe_entry');
                if ($name==='manifest.json') continue;
                $expected = $manifest['files'][$name]??null;
                if (!is_array($expected) || !is_int($expected['size']??null) || $expected['size']!==$info['size']
                    || !is_string($expected['sha256']??null) || !preg_match('/^[a-f0-9]{64}$/D',$expected['sha256'])) throw new InvalidArgumentException('full_backup_manifest_invalid');
                if (!is_dir($destination . '/data') && !mkdir($destination . '/data',0700)) throw new RuntimeException('full_backup_storage_failed');
                $source = $zip->getStream($name); $target = fopen($destination . '/' . $name,'xb');
                if (!$source || !$target) throw new InvalidArgumentException('full_backup_archive_invalid');
                try {
                    chmod($destination . '/' . $name,0600);
                    if (stream_copy_to_stream($source,$target,self::MAX_FILE+1)!==$expected['size'] || !fflush($target)) throw new InvalidArgumentException('full_backup_archive_invalid');
                } finally { fclose($source); fclose($target); }
                if (!hash_equals($expected['sha256'],hash_file('sha256',$destination . '/' . $name))) throw new InvalidArgumentException('full_backup_hash_mismatch');
            }
            if (count($seen)!==count($manifest['files'])+1 || array_diff_key($manifest['files'],$seen)) throw new InvalidArgumentException('full_backup_manifest_invalid');
            return $manifest;
        } finally { $zip->close(); }
    }

    private function validateSet(string $directory, array $files, bool $live = false): array
    {
        $root = $live ? $directory : $directory . '/data';
        ApiBackup::validate($root . '/data.db');
        $database = new ApiSqlite($root . '/data.db');
        try { (new ApiSetup($database,'backup'))->assertCompatible(); (new ApiSetup($database,'backup'))->assertNetworkAddressesUnique(); }
        finally { $database->close(); }
        foreach ($files as $file=>$entry) {
            if (!$this->allowed($file)) throw new InvalidArgumentException('full_backup_unsafe_entry');
            $path = $root . '/' . substr($file,5);
            if (substr($file,-3)==='.db') $this->validateDatabase($path,basename($file));
            else {
                self::regular($path,min(self::MAX_FILE,16777216));
                $value = json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
                if (!is_array($value)) throw new InvalidArgumentException('full_backup_json_invalid');
            }
        }
        return $this->summary($root);
    }

    private function validateDatabase(string $path,string $kind): void
    {
        self::regular($path,self::MAX_FILE); $db = new SQLite3($path,SQLITE3_OPEN_READONLY); $db->enableExceptions(true); $db->busyTimeout(1000);
        try {
            if ($db->querySingle('PRAGMA integrity_check')!=='ok' || $db->querySingle("SELECT count(*) FROM sqlite_master WHERE type IN ('view','trigger') OR upper(sql) LIKE '%CREATE VIRTUAL TABLE%'")) throw new InvalidArgumentException('full_backup_schema_invalid');
            $required = $kind==='jobs.db' ? ['jobs'=>['id','service_id','status','version','payload','operation','identity','fingerprint','legacy_key',
                'attempts','owner','owner_pid','lease_until','error','next_attempt','prior_cleared','cleanup_done','created','updated','owner_lock','history_scrubbed'],
                'service_versions'=>['service_id','version','applied_payload','applied_identity'],'service_tombstones'=>['service_id','version'],
                'job_receipts'=>['id','legacy_key','status','payload'],'service_event_locks'=>['service_id','owner','owner_pid','lease_until','owner_lock'],
                'queue_meta'=>['key','value'],'cancellation_cleanups'=>['id','source_job_id','service_id','source_version','payload','identity','slot'],
                'retained_resources'=>['id','source_job_id','service_id','source_version','payload','identity','slot','retained_at']]
                : ($kind==='cache.db' ? ['services'=>['id','clientId','username'],'clients'=>['id'],'network'=>['id'],'sites'=>['id']]
                : ['services'=>['id','clientId'],'devices'=>['id','name','ip'],'plans'=>['id'],'network'=>['id','address'],'config'=>['key','value']]);
            if ($kind==='jobs.db' && $db->querySingle("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='webhook_fresh_reviews'"))
                $required['webhook_fresh_reviews']=['service_id','attempts','next_attempt','last_error','received','updated'];
            foreach ($required as $table=>$fields) {
                $columns = []; $primary = []; $rows = $db->query('PRAGMA table_info("'.$table.'")');
                try { while ($row = $rows->fetchArray(SQLITE3_ASSOC)) { $columns[] = $row['name']; if ($row['pk']) $primary[(int)$row['pk']]=$row['name']; } }
                finally { $rows->finalize(); }
                if (array_diff($fields,$columns)) throw new InvalidArgumentException('full_backup_schema_invalid');
                ksort($primary);
                $key=in_array($table,['config','queue_meta'],true)?'key':(in_array($table,['service_versions','service_tombstones','service_event_locks','webhook_fresh_reviews'],true)?'service_id':'id');
                if (array_values($primary)!==[$key]) throw new InvalidArgumentException('full_backup_schema_invalid');
                if ((int)$db->querySingle('SELECT count(*) FROM "'.$table.'"')>250000) throw new InvalidArgumentException('full_backup_row_limit');
            }
            if ($kind==='jobs.db') {
                if ($db->querySingle("SELECT count(*) FROM jobs WHERE status='running'") || $db->querySingle('SELECT count(*) FROM service_event_locks')
                    || $db->querySingle("SELECT count(*) FROM queue_meta WHERE key='maintenance'")) throw new InvalidArgumentException('full_backup_queue_busy');
                foreach (['jobs','job_receipts','cancellation_cleanups','retained_resources'] as $table) {
                    $rows = $db->query('SELECT * FROM "'.$table.'"');
                    try { while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
                        if (!is_int($row['id']) || $row['id']<1 || !is_string($row['payload']) || strlen($row['payload'])>2097152) throw new InvalidArgumentException('full_backup_identity_invalid');
                        $payload = json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
                        if (!is_array($payload) || filter_var($payload['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false
                            || isset($row['service_id']) && (int)$row['service_id']!==$payload['id']) throw new InvalidArgumentException('full_backup_identity_invalid');
                        if ($table==='jobs' && (!in_array($row['status'],['pending','succeeded','cancelled','superseded','cleaned'],true)
                            || !in_array($row['operation'],['update','delete','queues_set','queues_remove'],true)
                            || !is_int($row['version']) || $row['version']<1)) throw new InvalidArgumentException('full_backup_identity_invalid');
                    } } finally { $rows->finalize(); }
                }
                $rows = $db->query('SELECT service_id,applied_payload FROM service_versions WHERE applied_payload IS NOT NULL');
                try { while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
                    $payload = json_decode($row['applied_payload'],true,64,JSON_THROW_ON_ERROR);
                    if (!is_array($payload) || (int)($payload['id']??0)!==$row['service_id']) throw new InvalidArgumentException('full_backup_identity_invalid');
                } } finally { $rows->finalize(); }
                if (isset($required['webhook_fresh_reviews'])) {
                    $rows=$db->query('SELECT * FROM webhook_fresh_reviews');
                    try { while ($row=$rows->fetchArray(SQLITE3_ASSOC)) {
                        if (!is_int($row['service_id']) || $row['service_id']<1 || !is_int($row['attempts']) || $row['attempts']<0 || $row['attempts']>20
                            || !is_int($row['next_attempt']) || $row['next_attempt']<0
                            || !in_array($row['last_error'],['webhook_state_unconfirmed','webhook_service_absent','webhook_fresh_state_unavailable','webhook_fresh_state_invalid','webhook_fresh_review_blocked'],true))
                            throw new InvalidArgumentException('full_backup_identity_invalid');
                        foreach (['received','updated'] as $field) {
                            if (!is_string($row[$field]) || strlen($row[$field])>35
                                || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D',$row[$field])!==1)
                                throw new InvalidArgumentException('full_backup_identity_invalid');
                        }
                    } } finally { $rows->finalize(); }
                }
            }
        } finally { $db->close(); }
    }

    private function summary(string $directory): array
    {
        $result = ['devices'=>0,'services'=>0,'pendingJobs'=>0,'appliedServices'=>0,'recoveries'=>0,'retainedRecords'=>0];
        foreach (['data.db'=>['devices'=>'devices','services'=>'services'],'jobs.db'=>['appliedServices'=>'service_versions','recoveries'=>'cancellation_cleanups','retainedRecords'=>'retained_resources']] as $file=>$tables) {
            if (!is_file($directory . '/' . $file)) continue;
            $db = new SQLite3($directory . '/' . $file,SQLITE3_OPEN_READONLY); $db->enableExceptions(true);
            try {
                foreach ($tables as $label=>$table) $result[$label] = (int)$db->querySingle('SELECT count(*) FROM "'.$table.'"');
                if ($file==='jobs.db') $result['pendingJobs'] = (int)$db->querySingle("SELECT count(*) FROM jobs WHERE status='pending'");
            } finally { $db->close(); }
        }
        return $result;
    }

    private function fingerprint(): string
    {
        $context = hash_init('sha256');
        foreach ($this->files() as $file) {
            hash_update($context,$file."\0"); $path = $this->data . '/' . $file;
            if (substr($file,-3)!=='.db') { hash_update_file($context,$path); continue; }
            $db = new SQLite3($path,SQLITE3_OPEN_READONLY); $db->enableExceptions(true); $db->busyTimeout(1000); $db->exec('BEGIN');
            try {
                $schema = $db->query("SELECT type,name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type,name"); $tables = [];
                try { while ($row=$schema->fetchArray(SQLITE3_ASSOC)) { hash_update($context,json_encode($row,JSON_THROW_ON_ERROR)); if ($row['type']==='table') $tables[]=$row['name']; } }
                finally { $schema->finalize(); }
                foreach ($tables as $table) {
                    $rows=$db->query('SELECT * FROM '.ApiSqlite::identifier($table).' ORDER BY rowid'); $count=0;
                    try { while ($row=$rows->fetchArray(SQLITE3_ASSOC)) { if (++$count>250000) throw new InvalidArgumentException('full_backup_row_limit'); hash_update($context,json_encode($row,JSON_THROW_ON_ERROR)); } }
                    finally { $rows->finalize(); }
                }
            } finally { $db->close(); }
        }
        return hash_final($context);
    }

    private function replace(string $file, ?string $source): void
    {
        if (!$this->allowed($file)) throw new RuntimeException('full_backup_journal_invalid');
        $target = $this->data . '/' . substr($file,5);
        if (is_link($target) || (file_exists($target) && !is_file($target))) throw new RuntimeException('full_backup_unsafe_path');
        if ($source===null) { if (file_exists($target) && !unlink($target)) throw new RuntimeException('full_backup_replace_failed'); return; }
        self::regular($source,self::MAX_FILE);
        if (substr($file,-3)==='.db') self::sqliteCopy($source,$target);
        else $this->atomic($target,file_get_contents($source));
    }

    private function rollbackLocked(): void
    {
        self::regular($this->journalPath(),524288);
        $journal=json_decode(file_get_contents($this->journalPath()),true,16,JSON_THROW_ON_ERROR);
        if (!is_array($journal) || ($journal['schema']??null)!==1 || !preg_match('/^work-[a-f0-9]{32}$/D',$journal['work']??'')
            || !is_array($journal['previous']??null) || !is_array($journal['files']??null) || count($journal['files'])>self::MAX_FILES)
            throw new RuntimeException('full_backup_journal_invalid');
        if (array_keys($journal['files'])!==array_keys(array_values($journal['files']))
            || count(array_unique($journal['files'],SORT_REGULAR))!==count($journal['files'])
            || !isset($journal['previous']['data/data.db'],$journal['previous']['data/jobs.db'])) throw new RuntimeException('full_backup_journal_invalid');
        $working=$this->data . '/full-backups/' . $journal['work'];
        if (is_link($working) || !is_dir($working)) throw new RuntimeException('full_backup_journal_invalid');
        foreach ($journal['files'] as $file) {
            if (!is_string($file) || !$this->allowed($file)) throw new RuntimeException('full_backup_journal_invalid');
            $previous=$journal['previous'][$file]??null;
            if ($previous!==null) {
                $path=$working . '/rollback/' . $file; self::regular($path,self::MAX_FILE);
                if (!is_array($previous) || !is_int($previous['size']??null) || $previous['size']!==filesize($path)
                    || !is_string($previous['sha256']??null) || !preg_match('/^[a-f0-9]{64}$/D',$previous['sha256'])
                    || !hash_equals($previous['sha256'],hash_file('sha256',$path))) throw new RuntimeException('full_backup_journal_invalid');
            }
        }
        if (array_diff_key($journal['previous'],array_fill_keys($journal['files'],true))) throw new RuntimeException('full_backup_journal_invalid');
        foreach ($journal['files'] as $file) $this->replace($file,isset($journal['previous'][$file])?$working . '/rollback/' . $file:null);
        if (!unlink($this->journalPath())) throw new RuntimeException('full_backup_recovery_required');
        self::removeTree($working);
    }

    private static function sqliteCopy(string $source,string $target): void
    {
        if (is_link($source) || is_link($target)) throw new RuntimeException('full_backup_unsafe_path');
        $reader=new SQLite3($source,SQLITE3_OPEN_READONLY); $reader->enableExceptions(true); $reader->busyTimeout(1000);
        $destination=new SQLite3($target,SQLITE3_OPEN_READWRITE|SQLITE3_OPEN_CREATE); $destination->enableExceptions(true); $destination->busyTimeout(1000);
        try { if (!$reader->backup($destination) || !chmod($target,0600)) throw new RuntimeException('full_backup_snapshot_failed'); }
        finally { $destination->close(); $reader->close(); }
    }

    private function assertIdle(): void
    {
        if (file_exists($this->journalPath()) || is_link($this->journalPath())) throw new RuntimeException('full_backup_recovery_required');
        if (!is_file($this->data . '/jobs.db')) throw new InvalidArgumentException('full_backup_state_missing');
        $this->validateDatabase($this->data . '/jobs.db','jobs.db');
    }

    private function locks(bool $recover = false): array
    {
        if (is_link($this->data) || !is_dir($this->data)) throw new InvalidArgumentException('full_backup_state_missing');
        $identity=realpath($this->data . '/jobs.db') ?: realpath($this->data) . DIRECTORY_SEPARATOR . 'jobs.db';
        if (PHP_OS_FAMILY==='Windows') $identity=strtolower($identity);
        $directory=dirname($identity) . DIRECTORY_SEPARATOR . '.uisp-queue-locks-' . hash('sha256',$identity);
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory,0700))) throw new RuntimeException('full_backup_busy');
        $paths=[$directory . '/maintenance.lock',$this->data . '/ip-sync.lock',$this->data . '/setup.lock',$this->data . '/backup.lock']; $locks=[];
        try {
            foreach ($paths as $path) {
                if (is_link($path) || (file_exists($path) && !is_file($path))) throw new RuntimeException('full_backup_busy');
                $handle=fopen($path,'c+b');
                if (!$handle || !flock($handle,LOCK_EX|LOCK_NB)) { if (is_resource($handle)) fclose($handle); throw new RuntimeException('full_backup_busy'); }
                chmod($path,0600); $locks[]=$handle;
            }
            return $locks;
        } catch (Throwable $error) { self::unlock($locks); throw $error; }
    }

    private function privateDirectory(bool $create = true): string
    {
        if (is_link($this->data) || !is_dir($this->data)) throw new RuntimeException('full_backup_storage_failed');
        $path=$this->data . '/full-backups';
        if (is_link($path) || (file_exists($path) && !is_dir($path)) || (!is_dir($path) && (!$create || !mkdir($path,0700)))) throw new RuntimeException('full_backup_storage_failed');
        if ($create && !chmod($path,0700)) throw new RuntimeException('full_backup_storage_failed');
        return $path;
    }
    private function working(): string { $path=$this->privateDirectory() . '/work-' . bin2hex(random_bytes(16)); if (!mkdir($path,0700)) throw new RuntimeException('full_backup_storage_failed'); return $path; }
    private function archivePath($name,bool $exists = true): string { if (!self::backupName($name)) throw new InvalidArgumentException('full_backup_name_invalid'); $path=$this->privateDirectory() . '/' . $name; if ($exists) self::regular($path,self::MAX_TOTAL); return $path; }
    private function previewPath(string $token): string { if (!preg_match('/^[a-f0-9]{64}$/D',$token)) throw new InvalidArgumentException('full_backup_preview_invalid'); return $this->privateDirectory() . '/preview-' . $token . '.json'; }
    private function journalPath(): string { return $this->data . '/full-backup-restore.json'; }
    private function identity(): string { return hash('sha256',(string)realpath($this->data)); }
    private function newName(): string { return 'full-backup-' . gmdate('Ymd\THis\Z',(int)$this->now()) . '-' . bin2hex(random_bytes(16)) . '.zip'; }
    private function now(): float { return $this->clock===null ? microtime(true) : (float)($this->clock)(); }
    private function prunePreviews(): void
    {
        $count=0;
        foreach (glob($this->privateDirectory() . '/preview-*.json')?:[] as $path) {
            if (is_link($path) || !preg_match('/^preview-[a-f0-9]{64}\.json$/D',basename($path))) continue;
            if (filemtime($path)+self::TTL+60<$this->now()) {
                if (is_file($path) && filesize($path)<=8192) {
                    try {
                        $expired=json_decode(file_get_contents($path),true,12,JSON_THROW_ON_ERROR);
                        if (is_array($expired) && preg_match('/^work-[a-f0-9]{32}$/D',$expired['work']??'')) self::removeTree($this->privateDirectory() . '/' . $expired['work']);
                    } catch (Throwable $ignored) {}
                }
                @unlink($path); continue;
            }
            if (++$count>=16) throw new InvalidArgumentException('full_backup_preview_limit');
        }
    }
    private function atomic(string $path,string $bytes): void
    {
        if (is_link($path)) throw new RuntimeException('full_backup_unsafe_path');
        $temporary=$path . '.tmp-' . bin2hex(random_bytes(8)); $stream=fopen($temporary,'xb');
        if (!$stream) throw new RuntimeException('full_backup_storage_failed');
        try {
            if (!chmod($temporary,0600) || fwrite($stream,$bytes)!==strlen($bytes) || !fflush($stream)) throw new RuntimeException('full_backup_storage_failed');
            if (function_exists('fsync') && !fsync($stream)) throw new RuntimeException('full_backup_storage_failed');
        } finally { fclose($stream); }
        try { if (!rename($temporary,$path)) throw new RuntimeException('full_backup_storage_failed'); }
        finally { if (is_file($temporary)) @unlink($temporary); }
    }
    private static function regular(string $path,int $max): void { if (is_link($path) || !is_file($path) || filesize($path)>$max) throw new InvalidArgumentException('full_backup_file_invalid'); }
    private static function unlock(array $locks): void { foreach (array_reverse($locks) as $handle) if (is_resource($handle)) { flock($handle,LOCK_UN); fclose($handle); } }
    private static function removeTree(string $path): void
    {
        if (is_link($path) || !is_dir($path) || !preg_match('/^work-[a-f0-9]{32}$/D',basename($path))) return;
        $remove=static function(string $directory) use (&$remove): void {
            foreach (new FilesystemIterator($directory,FilesystemIterator::SKIP_DOTS) as $entry) {
                if ($entry->isDir() && !$entry->isLink()) $remove($entry->getPathname()); else unlink($entry->getPathname());
            }
            rmdir($directory);
        };
        $remove($path);
    }
}
