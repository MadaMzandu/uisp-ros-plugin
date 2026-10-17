<?php
// Isolated locale reads: no UISP SDK calls, router access or installation data.
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
function checkLocale(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function removeLocaleFixture(string $directory, string $root): void {
    $resolved = realpath($directory);
    checkLocale($resolved !== false && ($resolved === $root || strpos($resolved, $root . DIRECTORY_SEPARATOR) === 0), 'Fixture cleanup escaped its temporary root');
    foreach (scandir($directory) as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (is_dir($path) && !is_link($path)) removeLocaleFixture($path, $root);
        else unlink($path);
    }
    rmdir($directory);
}
function expectedLocale(string $language): array {
    $result = [];
    foreach (['messages', 'fields'] as $name) {
        $path = 'includes/l10n/' . $name . '_' . $language . '.json';
        $value = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        checkLocale(is_array($value) && count($value) > 0, 'A shipped locale is empty: ' . $path);
        $result[] = $value;
    }
    return $result;
}
function readLocale($requested, bool $includeRequest = true): array {
    $before = mySqlite()->selectAllFromTable('config');
    $data = (object) ['data' => (object) []];
    if ($includeRequest) $data->data->language = $requested;
    $response = (new ApiList($data, 'messages'))->list_msg();
    $after = mySqlite()->selectAllFromTable('config');
    checkLocale($before === $after, 'A browser language read mutated shared configuration');
    return json_decode(json_encode($response, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
}

$originalDirectory = getcwd();
$originalAccept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
$source = realpath(__DIR__ . '/../src');
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-locale-test-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$root = realpath($directory);
foreach (['lib', 'includes', 'includes/l10n', 'data', 'vendor'] as $folder) mkdir($directory . '/' . $folder, 0700);
foreach (glob($source . '/lib/*.php') as $file) copy($file, $directory . '/lib/' . basename($file));
foreach (glob($source . '/includes/l10n/*.json') as $file) copy($file, $directory . '/includes/l10n/' . basename($file));
file_put_contents($directory . '/vendor/autoload.php', '<?php // Locale reads must never construct the real SDK.');
chdir($directory);
require_once 'lib/api_sqlite.php';
require_once 'lib/api_list.php';
try {
    $apiSqlite = new ApiSqlite(':memory:');
    $apiSqlite->exec('CREATE TABLE config(key TEXT PRIMARY KEY,value TEXT,last TEXT,created TEXT)');
    $apiSqlite->saveConfig(['plugin_language' => 'en', 'active_list' => '00123', 'disable_contention' => true]);
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de-DE,de;q=0.9';
    foreach (['es', 'en', 'pt', 'fr', 'de', 'it'] as $language) {
        checkLocale(readLocale($language) === expectedLocale($language), 'Browser preference returned the wrong locale: ' . $language);
        checkLocale(mySqlite()->readConfig()->plugin_language === 'en', 'Browser locale leaked into a saved global setting');
    }

    $apiSqlite->saveConfig(['plugin_language' => 'es']);
    file_put_contents('outside.json', '{"unexpected":"outside the locale directory"}');
    foreach (['../../outside', '../en', '/outside.json', 'php://filter/resource=outside.json', 'it/../en', 'unknown', '', null, 1, ['it'], (object) ['value' => 'it']] as $invalid) {
        checkLocale(readLocale($invalid) === expectedLocale('es'), 'An invalid request escaped the saved-language fallback');
    }
    foreach (['../../outside', 'php://filter/resource=outside.json', 'unknown'] as $invalid) {
        $apiSqlite->saveConfig(['plugin_language' => $invalid]);
        checkLocale(readLocale(null, false) === expectedLocale('en'), 'An unsafe saved locale escaped the English fallback');
    }

    foreach (['af' => 'af-ZA,sw;q=0.9', 'sw' => 'sw-TZ,af;q=0.9'] as $language => $accept) {
        $apiSqlite->saveConfig(['plugin_language' => 'system']);
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $accept;
        checkLocale(readLocale(null, false) === expectedLocale($language), 'A supported legacy HTTP locale stopped working: ' . $language);
        $apiSqlite->saveConfig(['plugin_language' => $language]);
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US';
        checkLocale(readLocale(null, false) === expectedLocale($language), 'A supported legacy saved locale stopped working: ' . $language);
    }
    $apiSqlite->saveConfig(['plugin_language' => 'system']);
    unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
    checkLocale(readLocale(null, false) === expectedLocale('en'), 'A missing HTTP language must default to English');
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = '../../outside';
    checkLocale(readLocale(null, false) === expectedLocale('en'), 'Unsafe HTTP language escaped the English fallback');
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'es-419,en-US;q=0.9';
    checkLocale(readLocale(null, false) === expectedLocale('es'), 'A numeric regional language tag lost its primary Spanish locale');
    foreach ([
        'pt-BR;q=0,fr-FR;q=0.8' => 'fr',
        'fr-FR;q=0.2,es-419;q=0.9' => 'es',
        'ja-JP;q=1,de-DE;q=0.8,en-US;q=0.5' => 'de',
        'pt-BR;q=0.7,fr-FR;q=0.7' => 'pt',
        'es;q=0.2,it-IT' => 'it',
        'de-DE;q=0,es;q=0' => 'en'
    ] as $accept => $expected) {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $accept;
        checkLocale(readLocale(null, false) === expectedLocale($expected), 'HTTP locale quality, supported fallback or stable ordering failed: ' . $accept);
    }

    $spanish = expectedLocale('es');
    $italian = expectedLocale('it');
    foreach ([0, 1] as $section) {
        $spanishKeys = array_keys($spanish[$section]);
        $italianKeys = array_keys($italian[$section]);
        sort($spanishKeys); sort($italianKeys);
        checkLocale($spanishKeys === $italianKeys, 'Italian translation keys differ from Spanish');
    }
    foreach ($spanish[1] as $field => $metadata) {
        $spanishKeys = array_keys($metadata);
        $italianKeys = array_keys($italian[1][$field]);
        sort($spanishKeys); sort($italianKeys);
        checkLocale($spanishKeys === $italianKeys, 'Italian field structure differs: ' . $field);
        foreach ($metadata as $key => $value) {
            if (in_array($key, ['label', 'description', 'hint'], true)) continue;
            checkLocale($italian[1][$field][$key] === $value, 'Italian operational field metadata changed: ' . $field . '.' . $key);
        }
    }
    checkLocale($italian[0]['menuSettings'] === 'Impostazioni' && $italian[1]['device_name']['label'] === 'Nome del dispositivo', 'Italian messages or fields were not translated');
    echo 'PASS locale: six browser preferences, unchanged settings, safe fallbacks, regional tags and quality priorities, legacy HTTP/config locales, Italian structure.' . PHP_EOL;
} finally {
    if (isset($apiSqlite)) $apiSqlite->close();
    chdir($originalDirectory);
    if ($originalAccept === null) unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
    else $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $originalAccept;
    removeLocaleFixture($directory, $root);
}
