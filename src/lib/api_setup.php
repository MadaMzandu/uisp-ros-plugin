<?php
const MY_VERSION = '2.4.44';
const MAX_BACKUPS = 6 ;

include_once 'api_sqlite.php';
include_once 'api_logger.php';
include_once 'api_timer.php';

final class ApiVersionMismatchException extends InvalidArgumentException
{
    private array $versions;

    public function __construct(string $databaseVersion, string $context)
    {
        $this->versions = [
            'database_version' => $databaseVersion,
            'runtime_version' => MY_VERSION,
            'package_version' => ApiSetup::packageVersion(),
        ];
        parent::__construct($context === 'backup' ? 'backup_version_is_newer_than_plugin' : 'database_version_is_newer_than_plugin');
    }

    public function getVersions(): array { return $this->versions; }
}

final class ApiNetworkAddressConflictException extends InvalidArgumentException
{
    private array $details;

    public function __construct(array $details)
    {
        $this->details = $details;
        parent::__construct('duplicate_network_addresses_resolve_before_upgrade');
    }

    public function getDetails(): array { return $this->details; }
}

class ApiSetup
{
    private ?object $_db = null ;
    private ?object $_tmp = null ;
    private ?ApiSqlite $migrationDb = null;
    private string $context;

    public function __construct(?ApiSqlite $database = null, string $context = 'database')
    {
        $this->migrationDb = $database;
        $this->context = $context;
    }

    public function run(){
        if (SQLite3::version()['versionNumber'] < 3024000) throw new RuntimeException('SQLite 3.24 or newer is required');
        // Queue initialization can import/recover old jobs. A known-incompatible upgrade
        // must stop before that initialization as well as before backup rotation.
        if (is_file('data/data.db')) {
            $this->assertCompatible();
            $this->assertNetworkAddressesUnique();
        }
        if (!is_dir('data') && !mkdir('data', 0700, true) && !is_dir('data')) throw new RuntimeException('data_directory_failed');
        require_once __DIR__ . '/api_job_queue.php';
        myJobQueue()->withAdministrativeLock(function (): void { $this->runLocked(); });
    }

    private function runLocked(): void
    {
        $lock = fopen('data/setup.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('setup_lock_failed');
        try {
        if (!$this->needs_db()) {
            $this->assertCompatible();
            $this->assertNetworkAddressesUnique();
        }
        if($this->needs_db()){
            $this->db_create();
        }
        if($this->needs_update()){
            $this->db_update();
        }
        if($this->needs_backup()){
            $this->db_backup();
        }
        if($this->needs_cleanup()){
            $this->file_cleanup();
        }
        foreach (array_merge(['config.json','queue.json','data.db'], array_map(function ($index) { return 'backup-' . $index; }, range(0,6))) as $name) {
            $path = 'public/' . $name;
            if (is_file($path) || is_link($path)) {
                if (!unlink($path)) throw new RuntimeException('published_secret_cleanup_failed');
            }
        }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function db_update(): void
    {
        // Reject incompatible versions before rotating any existing recovery snapshot.
        $this->assertCompatible();
        // Failed upgrades must not cycle through and replace the operator's recovery copies.
        $this->assertNetworkAddressesUnique();
        if(!$this->db_backup()){
            fail('backup_failure');
        }
        $this->migrate();
    }

    public function migrate(): void
    {
        $this->assertCompatible();
        $this->dbApi()->transaction(function () {
            // Check the effective legacy import under the write lock before changing any columns.
            $this->assertNetworkAddressesUnique();
            // Migrate by column name in place; never guess column order or tolerate SQL failures.
            foreach (['network','services','plans','devices','config'] as $table) {
                $desired = $this->get_cols($table);
                $current = $this->get_cols($table, false);
                if (!$current) {
                    $sql = $this->tmp()->querySingle("SELECT sql FROM sqlite_master WHERE type='table' AND name='" . $table . "'");
                    $this->dbApi()->exec($sql);
                    continue;
                }
                foreach (array_diff_key($desired, $current) as $name => $type) {
                    $this->dbApi()->exec('ALTER TABLE ' . ApiSqlite::identifier($table) . ' ADD COLUMN ' . ApiSqlite::identifier($name) . ' ' . $type);
                    if ($table === 'plans' && in_array($name, ['priorityUpload','priorityDownload'], true) && isset($current['priority'])) {
                        $this->dbApi()->exec('UPDATE plans SET ' . ApiSqlite::identifier($name) . '=priority');
                    }
                }
                if ($table === 'services' && isset($current['address'])) {
                    $prefix = isset($current['prefix6']) ? 'prefix6' : 'NULL';
                    $this->dbApi()->exec('INSERT OR IGNORE INTO network(id,address,address6) SELECT id,address,' . $prefix . ' FROM services');
                }
            }
            $this->do_conversions();
            $this->dbApi()->exec(file_get_contents('includes/schema.sql'));
            $this->dbApi()->execute("UPDATE devices SET type='mikrotik' WHERE type IS NULL OR type=''");
            $this->set_version();
            $this->config_load();
        });
    }

    public function assertCompatible(): void
    {
        $version = $this->databaseVersion();
        if (version_compare($version, MY_VERSION, '>')) throw new ApiVersionMismatchException($version, $this->context);
    }

    public function assertNetworkAddressesUnique(): void
    {
        $db = $this->dbApi();
        $network = $this->get_cols('network', false);
        $services = $this->get_cols('services', false);
        $details = ['duplicate_groups' => 0, 'conflicts' => [], 'conflicts_truncated' => false];
        foreach (['address' => 'IPv4', 'address6' => 'IPv6'] as $field => $family) {
            $parts = [];
            if (isset($network['id'], $network[$field])) {
                $parts[] = 'SELECT id,' . $field . ' AS address FROM network';
            }
            // INSERT OR IGNORE imports only missing IDs. The existing network row wins,
            // including its NULL address, and legacy IPv6 lives in services.prefix6.
            $legacyField = $field === 'address' ? 'address' : 'prefix6';
            if (isset($services['id'], $services['address'], $services[$legacyField])) {
                $parts[] = 'SELECT s.id,s.' . $legacyField . ' AS address FROM services s'
                    . (isset($network['id']) ? ' WHERE NOT EXISTS (SELECT 1 FROM network n WHERE n.id=s.id)' : '');
            }
            if (!$parts) continue;
            $effective = '(' . implode(' UNION ALL ', $parts) . ')';
            $groups = 'SELECT address,count(*) AS service_count FROM ' . $effective
                . " WHERE address IS NOT NULL AND address<>'' GROUP BY address HAVING count(*)>1";
            $count = (int) $db->singleQuery('SELECT count(*) FROM (' . $groups . ')');
            $details['duplicate_groups'] += $count;
            $remaining = 20 - count($details['conflicts']);
            if (!$count || !$remaining) continue;
            foreach ($db->selectCustom($groups . ' ORDER BY address LIMIT ' . $remaining) as $group) {
                // Separate limited lookup avoids building an unbounded group_concat result.
                $owners = $db->selectCustom('SELECT id FROM ' . $effective . ' WHERE address=? ORDER BY id LIMIT 50', [$group['address']]);
                $details['conflicts'][] = [
                    'family' => $family,
                    'address' => (string) $group['address'],
                    'service_ids' => array_map(static function (array $row): int { return (int) $row['id']; }, $owners),
                    'service_count' => (int) $group['service_count'],
                    'services_truncated' => (int) $group['service_count'] > count($owners),
                ];
            }
        }
        if (!$details['duplicate_groups']) return;
        $details['conflicts_truncated'] = $details['duplicate_groups'] > count($details['conflicts']);
        throw new ApiNetworkAddressConflictException($details);
    }

    private function databaseVersion(): string
    {
        if (!$this->dbApi()->has_tables(['config'])) return '0.0.0';
        // A release such as "2.40" must remain text, rather than becoming the float 2.4.
        $version = $this->dbApi()->singleQuery('SELECT value FROM config WHERE key=?', false, ['version']);
        return $version === null || trim((string) $version) === '' ? '0.0.0' : trim((string) $version);
    }

    public static function packageVersion(): string
    {
        $path = dirname(__DIR__) . '/manifest.json';
        if (!is_file($path)) return 'unknown';
        $manifest = json_decode(file_get_contents($path), true);
        $version = $manifest['information']['version'] ?? null;
        return is_string($version) ? $version : 'unknown';
    }

    private function db_create(): void
    {
        $source = 'includes/schema.sql';
        $schema = file_get_contents($source);
        if($this->dbApi()->exec($schema)){
            if($this->config_load()){
                $this->set_version();
            }
        }
    }

    public function config_load(): bool
    {
        $file = file_get_contents('includes/conf_default.json');
        $default = json_decode($file,true);
        $conf = (array) $this->dbApi()->readConfig();
        $diff = array_diff_key($default,$conf);
        return $this->dbApi()->saveConfig($diff);
    }

    private function get_cols($t,$tmp = true): array
    {
        $q = "select name,type from pragma_table_info('$t')";
        if (!$tmp) {
            $rows = $this->dbApi()->selectCustom('PRAGMA table_info(' . ApiSqlite::identifier($t) . ')');
            $columns = [];
            foreach ($rows as $row) $columns[$row['name']] = $row['type'];
            return $columns;
        }
        $db = $this->tmp();
        $f = $db->query($q);
        $m = [];
        while($r = $f->fetchArray(SQLITE3_ASSOC)){
            $m[$r['name']] = $r['type'] ;
        }
        return $m ;
    }

    private function tmp(): SQLite3
    {//memory db for col check
        if(empty($this->_tmp))
        {
            $this->_tmp = new SQLite3(':memory:');
            $fn = 'includes/schema.sql';
            if(is_file($fn)) {
                $this->_tmp->exec(file_get_contents($fn));
            }
        }
        return $this->_tmp ;
    }

    private  function set_version(): void
    {

        $this->dbApi()->saveConfig(['version' => MY_VERSION]);
    }

    private function db_backup(): bool
    {
        require_once __DIR__ . '/api_backup.php';
        $state = $this->state();
        $backup = ApiBackup::rotate();
        $copy = is_file('data/' . $backup);
        $state->backup = date('c');
        $this->save($state);
        return $copy ;
    }

    private function file_cleanup():void
    {
        $state = $this->state() ;
        $files = 'plugin.log';
        foreach(explode(',',$files) as $file){
            file_put_contents('data/'.$file,'');
        }
        $state->cleanup = date('c');
        $this->save($state);
    }

    /**
     * @throws Exception
     */
    private function needs_cleanup(): bool
    {
        $date = $this->state()->cleanup ?? '2020-01-01';
        $last = new DateTime($date);
        $interval = DateInterval::createFromDateString('30 day');
        $next = $last->add($interval);
        $now = new DateTime();
        return $now > $next ;
    }

    /**
     * @throws Exception
     */
    private function needs_backup(): bool
    {
        $date = $this->state()->backup ?? '2020-01-01';
        $last = new DateTime($date);
        $interval = DateInterval::createFromDateString('1 day');
        $next = $last->add($interval);
        $now = new DateTime();
        return $now > $next ;
    }

    private function needs_update(): bool
    {
        if($this->needs_cols()) return true ;
        return $this->databaseVersion() !== MY_VERSION;
    }

    private function needs_db(): bool
    {
        return !is_file('data/data.db');
    }

    public function needs_cols(): bool
    { //check for missing columns
        $tables = 'services,plans,network,devices,config';
        foreach(explode(',',$tables) as $table){
            $new = $this->get_cols($table);
            $current = $this->get_cols($table,false);
            if(array_diff_key($new,$current)){
                return true ;
            }
        }
        return false ;
    }

    private function state(): stdClass
    {
        $file = 'data/state.json';
        if (!is_file($file)) {
            $state = $this->default_state();
            $this->save($state);
            return $state;
        }
        $raw = file_get_contents($file);
        $state = $raw === false ? null : json_decode($raw);
        $valid = $state instanceof stdClass;
        if ($valid) {
            foreach (['backup', 'cleanup'] as $key) {
                if (!isset($state->$key) || !is_string($state->$key)) { $valid = false; break; }
                try { new DateTime($state->$key); }
                catch (Throwable $error) { $valid = false; break; }
            }
        }
        if ($valid) return $state;
        // Auxiliary state is recoverable. Keep the original and avoid rotating backups or clearing logs now.
        $archive = 'data/state-corrupt-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.json';
        if (!copy($file, $archive)) throw new RuntimeException('setup_state_archive_failed');
        @chmod($archive, 0600);
        $state = $this->default_state();
        $state->backup = $state->cleanup = date('c');
        $this->save($state);
        MyLog()->Append('setup_state_recovered; original retained privately', LOG_ERR);
        return $state;
    }

    private function do_conversions()
    {
        $version = $this->databaseVersion();
        foreach($this->conversions_data() as $ver => $update){
            if(version_compare(trim($version), $ver, '<=')){
                $this->dbApi()->exec($update);
            }
        }
    }

    private function conversions_data(): array
    {
        return [
            '2.0.2' => "UPDATE plans SET limitUpload = ((uploadSpeed-limitUpload)*100)/uploadSpeed, limitDownload = ((downloadSpeed-limitDownload)*100)/downloadSpeed, burstUpload = ((burstUpload-uploadSpeed)*100)/uploadSpeed, burstDownload = ((burstDownload-downloadSpeed)*100)/downloadSpeed, threshUpload = ((uploadSpeed-threshUpload)*100)/uploadSpeed, threshDownload = ((downloadSpeed-threshDownload)*100)/downloadSpeed",

        ];
    }

    private function save($state): void
    {
        $file = 'data/state.json';
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $temporary = $file . '.tmp-' . bin2hex(random_bytes(8));
        try {
            if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) throw new RuntimeException('setup_state_write_failed');
            @chmod($temporary, 0600);
            if (!rename($temporary, $file)) throw new RuntimeException('setup_state_publish_failed');
        } finally { if (is_file($temporary)) unlink($temporary); }
    }

    private function default_state(): stdClass
    {
        return json_decode(
            '{
            "version":"1.0.0",
            "backup": "2020-01-01",
            "backups":0,
            "cleanup": "2020-01-01"
            }'
        );
    }

    private function dbApi(): ApiSqlite
    {
        return $this->migrationDb ?? mySqlite();
    }
}

function run_setup(){ $setup = new ApiSetup(); $setup->run(); }
