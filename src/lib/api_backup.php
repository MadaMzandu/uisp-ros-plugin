<?php
require_once __DIR__ . '/api_sqlite.php';
require_once __DIR__ . '/api_job_queue.php';
require_once __DIR__ . '/api_setup.php';

class ApiBackup
{
    public static function rotate(): string
    {
        $lock = fopen('data/backup.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('backup_lock_failed');
        try {
            $cursor = (int) (mySqlite()->readConfig()->backup_cursor ?? -1);
            $next = ($cursor + 1) % 7;
            self::create('backup-' . $next);
            mySqlite()->saveConfig(['backup_cursor' => $next]);
            return 'backup-' . $next;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function path($name): string
    {
        if (!is_string($name) || !preg_match('/^backup-[0-6]$/D', $name)) throw new InvalidArgumentException('invalid_backup_name');
        return 'data/' . $name;
    }

    public static function validate(string $path): void
    {
        if (!is_file($path) || filesize($path) < 16 || file_get_contents($path, false, null, 0, 16) !== "SQLite format 3\0") throw new InvalidArgumentException('invalid_sqlite_backup');
        $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
        $db->enableExceptions(true);
        try {
            if ($db->querySingle('PRAGMA integrity_check') !== 'ok') throw new InvalidArgumentException('backup_integrity_failed');
            foreach (['services', 'network', 'plans', 'devices', 'config'] as $table) {
                $statement = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
                $statement->bindValue(1, $table, SQLITE3_TEXT);
                $result = $statement->execute();
                $exists = $result->fetchArray();
                $result->finalize();
                $statement->close();
                if (!$exists) throw new InvalidArgumentException('backup_schema_invalid');
                $columns = $db->query('PRAGMA table_info(' . ApiSqlite::identifier($table) . ')');
                $fields = [];
                $primary = [];
                while ($row = $columns->fetchArray(SQLITE3_ASSOC)) {
                    $fields[] = $row['name'];
                    if ($row['pk']) $primary[] = $row['name'];
                }
                $columns->finalize();
                $key = $table === 'config' ? 'key' : 'id';
                if ($primary !== [$key]) throw new InvalidArgumentException('backup_primary_key_invalid');
                $required = ['services' => ['id','device','clientId','planId','status'], 'network' => ['id','address'], 'plans' => ['id'], 'devices' => ['id','name','ip','user','password'], 'config' => ['key','value']];
                if (array_diff($required[$table], $fields)) throw new InvalidArgumentException('backup_schema_invalid');
            }
            $columns = $db->query('PRAGMA table_info(devices)');
            $names = [];
            while ($row = $columns->fetchArray(SQLITE3_ASSOC)) $names[] = $row['name'];
            $columns->finalize();
            if (array_diff(['id', 'name', 'ip', 'user', 'password'], $names)) throw new InvalidArgumentException('backup_schema_invalid');
        } finally { $db->close(); }
    }

    public static function create(string $name): void
    {
        $path = self::path($name);
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        try {
            if (!mySqlite()->backupTo($temporary)) throw new RuntimeException('backup_failed');
            self::validate($temporary);
            if (!rename($temporary, $path)) throw new RuntimeException('backup_publish_failed');
            @chmod($path, 0600);
        } finally { if (is_file($temporary)) unlink($temporary); }
    }

    public static function restore(string $path): void
    {
        self::validate($path);
        myJobQueue()->withMaintenance(function () use ($path) { self::restoreLocked($path); });
    }

    private static function restoreLocked(string $path): void
    {
        $source = new ApiSqlite($path);
        $temporary = 'data/restore-' . bin2hex(random_bytes(8)) . '.db';
        try {
            if (!$source->backupTo($temporary)) throw new RuntimeException('backup_restore_failed');
            $source->close();
            self::validate($temporary);
            $candidate = new ApiSqlite($temporary);
            try { (new ApiSetup($candidate, 'backup'))->migrate(); }
            finally { $candidate->close(); }
            self::validate($temporary);
            // Snapshot the selected backup before rotating backup-6, which may be that same file.
            self::create('backup-6');
            // SQLite's backup API replaces pages in a transaction and preserves open handles.
            // Renaming/unlinking a live database would strand other requests on the old inode.
            if (!mySqlite()->restoreFrom($temporary)) throw new RuntimeException('backup_restore_failed');
            @chmod('data/data.db', 0600);
            mySqlite()->saveConfig(['next_cache' => '2020-01-01']);
        } finally {
            $source->close();
            if (is_file($temporary)) unlink($temporary);
        }
    }

    public static function restoreUpload(array $file): void
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) throw new InvalidArgumentException('backup_upload_failed');
        self::restore($file['tmp_name']);
    }

    public static function download($name): void
    {
        require_once __DIR__ . '/api_job_recovery.php';
        if (ApiJobRecovery::backupName($name)) { ApiJobRecovery::download($name); return; }
        $path = self::path($name);
        self::validate($path);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '.sqlite"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        $stream = fopen($path, 'rb');
        if (!$stream) throw new RuntimeException('backup_download_failed');
        try {
            header('Content-Length: ' . fstat($stream)['size']);
            fpassthru($stream);
        } finally { fclose($stream); }
    }
}
