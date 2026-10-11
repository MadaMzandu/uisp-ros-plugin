<?php
const INSERT_NORMAL = 0;
const INSERT_IGNORE = 1;
const INSERT_REPLACE = 2;
class ApiSqlite
{

    private string $path;
    private ?SQLite3 $_db = null;
    private int $transactionDepth = 0;

    public static function identifier(string $name): string
    {
        $parts = explode('.', $name);
        foreach ($parts as &$part) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $part)) throw new InvalidArgumentException('Invalid SQL identifier');
            $part = '"' . $part . '"';
        }
        return implode('.', $parts);
    }

    private function prepare(string $sql, array $params): SQLite3Stmt
    {
        $statement = $this->db()->prepare($sql);
        if (!$statement) throw new RuntimeException('Database query preparation failed');
        foreach ($params as $key => $value) {
            $type = SQLITE3_TEXT;
            if ($value === null) $type = SQLITE3_NULL;
            elseif (is_bool($value) || is_int($value)) $type = SQLITE3_INTEGER;
            elseif (is_float($value)) $type = SQLITE3_FLOAT;
            $statement->bindValue(is_int($key) ? $key + 1 : ':' . ltrim($key, ':'), is_bool($value) ? (int) $value : $value, $type);
        }
        return $statement;
    }

    public function execute(string $sql, array $params = []): bool
    {
        $statement = $this->prepare($sql, $params);
        try {
            $result = $statement->execute();
            if ($result) $result->finalize();
            return $result !== false;
        } finally { $statement->close(); }
    }

    public function transaction(callable $callback)
    {
        $level = $this->transactionDepth++;
        $savepoint = 'plugin_' . $level;
        try {
            $this->db()->exec($level === 0 ? 'BEGIN IMMEDIATE' : 'SAVEPOINT ' . $savepoint);
            $value = $callback($this);
            $this->db()->exec($level === 0 ? 'COMMIT' : 'RELEASE SAVEPOINT ' . $savepoint);
            return $value;
        } catch (Throwable $error) {
            try {
                $this->db()->exec($level === 0 ? 'ROLLBACK' : 'ROLLBACK TO SAVEPOINT ' . $savepoint);
                if ($level > 0) $this->db()->exec('RELEASE SAVEPOINT ' . $savepoint);
            } catch (Throwable $ignored) {}
            throw $error;
        } finally { $this->transactionDepth--; }
    }

    public function insert($data,$table = 'services',$replace = INSERT_NORMAL): bool
    { //insert single row or batch
        $data = $this->to_array($data);
        if(!$data) return false ;
        $INSERT = 'INSERT';
        if($replace != INSERT_NORMAL){
            $INSERT = $replace == INSERT_IGNORE ? 'INSERT OR IGNORE' : 'INSERT OR REPLACE';
        }
        return $this->transaction(function () use ($data, $table, $INSERT) {
            foreach ($data as $row) {
                $fields = array_map([self::class, 'identifier'], array_keys($row));
                $query = $INSERT . ' INTO ' . self::identifier($table) . ' (' . implode(',', $fields) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')';
                if (!$this->execute($query, array_values($row))) throw new RuntimeException('Database insert failed');
            }
            return true;
        });
    }

    private function to_array($data): ?array
    {
        $data = json_decode(json_encode($data),true);
        if (empty($data)) return [];
        if(is_array($data)){
            $first = array_values($data)[0] ?? null;
            if(is_array($first)) return $data ;
            return [$data];
        }
        return null ;
    }

    public function edit_old($data,$table = 'services'): bool
    {
        return $this->edit($data, $table);
    }


    public function edit($data,$table = 'onts'): bool
    {
        $data = $this->to_array($data);
        if (!$data) return false;
        $pks = $this->find_pk($table);
        if (!$pks) throw new InvalidArgumentException('Missing table primary key');
        return $this->transaction(function () use ($data, $table, $pks) {
            foreach ($data as $row) {
                foreach ($pks as $pk) if (!isset($row[$pk])) throw new InvalidArgumentException('Missing row primary key');
                $this->insert($row, $table, INSERT_IGNORE);
                $values = array_diff_key($row, array_fill_keys($pks, true));
                if (!$values) continue;
                $pairs = array_map(function ($key) { return self::identifier($key) . '=?'; }, array_keys($values));
                $where = array_map(function ($key) { return self::identifier($key) . '=?'; }, $pks);
                $params = array_values($values);
                foreach ($pks as $pk) $params[] = $row[$pk];
                $this->execute('UPDATE ' . self::identifier($table) . ' SET ' . implode(',', $pairs) . ' WHERE ' . implode(' AND ', $where), $params);
            }
            return true;
        });
    }

    private function find_pk($table = 'onts')
    {
        $columns = $this->find_columns($table) ;
        $pks = array_diff_assoc($columns,array_fill_keys(array_keys($columns),0));
        return array_keys($pks);
    }

    private function find_columns($table = 'service_ports')
    {
        $data = $this->selectCustom('PRAGMA TABLE_INFO(' . self::identifier($table) . ')') ?? [];
        $columns= [];
        foreach ($data as $item){
            $columns[$item['name']] = $item['pk'] ;
        }
        return $columns;
    }


    public function has_tables($tables = ['services','devices','config']): bool
    {
        foreach ($tables as $table){
            if (!$this->singleQuery("SELECT name FROM sqlite_master WHERE type='table' AND name=?", false, [$table])) return false;
        }
        return true ;
    }

    public function exists($id,$table = 'network'): ?int
    {
        return $this->singleQuery('SELECT id FROM ' . self::identifier($table) . ' WHERE id=?', false, [$id]);
    }

    public function exec($sql): ?bool
    {
//        MyLog()->Append("sqlite query: ".$sql);
        return $this->db()->exec($sql);
    }

    public function ifDeviceNameIsUsed($name)
    {
        return $this->singleQuery('SELECT id FROM devices WHERE name=? COLLATE NOCASE', false, [$name]);
    }

    public function countServicesByDeviceId($id)
    {
        return $this->singleQuery('SELECT count(id) FROM services WHERE device=?', false, [$id]);
    }

    public function selectDeviceByDeviceName($name): object
    {
        return (object) $this->singleQuery('SELECT * FROM devices WHERE name=? COLLATE NOCASE', true, [$name]);
    }

    public function selectDeviceById($id): object
    {
        return (object) $this->singleQuery('SELECT * FROM devices WHERE id=?', true, [$id]);
    }

    public function selectServices(): ?array
    {
        $fields = 'services.*,devices.name as deviceName,network.address,network.address6';
        $sql = sprintf("select %s from services left join devices on services.device=devices.id ".
            "left join network on services.id=network.id",$fields);
        $res = $this->db()->query($sql);
        $return = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $return[] = $row;
        }
        return $return;
    }

    public function deleteAll($table = 'services'): bool
    {
        return $this->execute('DELETE FROM ' . self::identifier($table));
    }

    public function delete($id, $table = 'services'): bool
    {
        return $this->execute('DELETE FROM ' . self::identifier($table) . ' WHERE id=?', [$id]);
    }

    public function selectCustom($sql, array $params = []) : ?array
    {
        $statement = $this->prepare($sql, $params);
        try {
            $res = $statement->execute();
            $rows = [];
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
            $res->finalize();
            return $rows;
        } finally { $statement->close(); }
    }

    public function selectAllFromTable($table = 'services'): ?array
    {
        return $this->selectCustom('SELECT * FROM ' . self::identifier($table));
    }

    private function unStringify($value, string $key)
    {
        if ($value === null || $value === '') return null;
        // Storage is TEXT: infer types from the setting, never from a user's value or identifier.
        if (in_array($key, ['router_ppp_pool','auto_ppp_user','auto_hs_user','hs_enable',
            'disable_contention','unsuspend_date_fix','debugEnable'], true)) {
            $flag = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($flag === null) throw new InvalidArgumentException('invalid_boolean_config_' . $key);
            return $flag;
        }
        if (in_array($key, ['lease_time','dhcp_lease_time','disabled_rate','unsuspend_fix_wait','backup_cursor'], true)) {
            if (!is_numeric($value) || !is_finite((float) $value)) throw new InvalidArgumentException('invalid_numeric_config_' . $key);
            return $key === 'backup_cursor' ? (int) $value : (float) $value;
        }
        return $value ;
    }

    private function stringify($value)
    {
        if(is_bool($value)) return $value ? 'true' : 'false';
        if(is_numeric($value)) return (string) $value;
        if($value === null || $value === '') return null ;
        if (!is_scalar($value)) throw new InvalidArgumentException('invalid_config_value');
        return $value ;
    }

    public function readConfig(): ?object
    {
        $read = $this->selectAllFromTable('config') ?? [];
        $cfg = new stdClass();
        foreach ($read as $row) {
            $key = $row['key'] ?? 'nokey';
            $cfg->$key  = $this->unStringify($row['value'], $key);
        }
        return $cfg ;
    }

    public function saveConfig($fields): bool
    {
        $rows = [];
        foreach ((array) $fields as $key => $value) $rows[] = ['key' => $key, 'value' => $this->stringify($value), 'last' => date('c'), 'created' => '2020-01-01'];
        return !$rows || $this->insert($rows, 'config', INSERT_REPLACE);
    }

    private function db(): SQLite3
    {
        if(empty($this->_db)){
            $this->_db = new SQLite3($this->path);
            $this->_db->busyTimeout(5000);
            $this->_db->enableExceptions(true);
        }
        return $this->_db ;
    }

    public function selectIsUsed($address, $ipv6 = false):bool
    {
        $field = $ipv6 ? 'address6' : 'address';
        $id = $this->singleQuery('SELECT id FROM network WHERE ' . $field . '=?', false, [$address]);
        return (bool) $id ;
    }

    public function selectAddress($sid,$ipv6 = false): ?string
    {
        $field = $ipv6 ? 'address6': 'address';
        return $this->singleQuery('SELECT ' . $field . ' FROM network WHERE id=?', false, [$sid]);
    }

    public function singleQuery($sql,$entireRow=false, array $params = [])
    {
        $rows = $this->selectCustom($sql, $params);
        if (!$rows) return null;
        return $entireRow ? $rows[0] : reset($rows[0]);
    }

    public function close(): void
    {
        if ($this->transactionDepth) throw new RuntimeException('Active database transaction');
        if ($this->_db) $this->_db->close();
        $this->_db = null;
    }

    public function backupTo(string $path): bool
    {
        $destination = new SQLite3($path);
        try { return $this->db()->backup($destination); }
        finally { $destination->close(); }
    }

    public function restoreFrom(string $path): bool
    {
        $source = new SQLite3($path, SQLITE3_OPEN_READONLY);
        $source->enableExceptions(true);
        $source->busyTimeout(10000);
        try { return $source->backup($this->db()); }
        finally { $source->close(); }
    }

    public function lastInsertId(): int { return $this->db()->lastInsertRowID(); }

    public function __construct($path = null) { $this->path = $path ?? 'data/data.db';}

}

$apiSqlite = null ;
$apiSqliteCache = null ;

function mySqlite(): ApiSqlite
{
    global $apiSqlite ;
    if(empty($apiSqlite)){
        $apiSqlite = new ApiSqlite();
    }
    return $apiSqlite ;
}

function myCache(): ApiSqlite
{
    global $apiSqliteCache ;
    $fn = 'data/cache.db';
    if(empty($apiSqliteCache)){
        $apiSqliteCache = new ApiSqlite($fn);
    }
    return $apiSqliteCache ;
}
