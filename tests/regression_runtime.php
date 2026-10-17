<?php
/** Isolated ZIP/deployment tests. No UISP, routers or production databases. */
if (!class_exists('ZipArchive')) throw new RuntimeException('Enable the ZIP extension for runtime regression checks.');
$sourceRoot = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/uisp-runtime-' . bin2hex(random_bytes(8));
if (!mkdir($fixture, 0700, true)) throw new RuntimeException('Could not create the runtime fixture.');
$fixtureReal = realpath($fixture);

function runtime_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function runtime_process(array $command, string $cwd, bool $success = true): string
{
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Could not start isolated runtime check.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($process);
    runtime_check($success ? $code === 0 : $code !== 0, 'Unexpected subprocess status: ' . $output . $error);
    return $output . $error;
}

function runtime_release(string $source, string $version): void
{
    file_put_contents($source . '/manifest.json', json_encode(['information' => ['version' => $version]]));
    file_put_contents($source . '/composer.json', json_encode(['version' => $version]));
    file_put_contents($source . '/lib/api_setup.php', "<?php\nconst MY_VERSION = '" . $version . "';\n");
}

try {
    $builderRoot = $fixture . '/builder';
    foreach (['tools', 'src/lib', 'src/includes', 'src/public'] as $directory) mkdir($builderRoot . '/' . $directory, 0700, true);
    copy($sourceRoot . '/tools/build_plugin.py', $builderRoot . '/tools/build_plugin.py');
    file_put_contents($builderRoot . '/src/composer.lock', json_encode(['packages' => []]));
    file_put_contents($builderRoot . '/src/includes/schema.sql', '-- runtime fixture');
    file_put_contents($builderRoot . '/src/public/index.php', '<!doctype html><p>Fixture</p>');
    file_put_contents($builderRoot . '/src/main.php', '<?php // fixture');
    file_put_contents($builderRoot . '/src/public.php', '<?php // fixture');
    $vendor = $builderRoot . '/vendor.zip';
    $zip = new ZipArchive();
    runtime_check($zip->open($vendor, ZipArchive::CREATE) === true, 'Could not create the vendor fixture.');
    $zip->addFromString('vendor/autoload.php', '<?php // fixture');
    $zip->addFromString('vendor/composer/installed.json', '[]');
    runtime_check($zip->close(), 'Could not publish the vendor fixture ZIP.');
    $bundledPython = (getenv('USERPROFILE') ?: '') . '/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe';
    $python = getenv('PLUGIN_BUILD_PYTHON') ?: (is_file($bundledPython) ? $bundledPython : (PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3'));
    $build = function (string $output, bool $success = true) use ($python, $builderRoot, $vendor): string {
        return runtime_process([$python, $builderRoot . '/tools/build_plugin.py', '--vendor-archive', $vendor, '--output', $output], $builderRoot, $success);
    };
    runtime_release($builderRoot . '/src', '2.4.3');
    $firstZip = $fixture . '/2.4.3-a.zip';
    $sameZip = $fixture . '/2.4.3-b.zip';
    $build($firstZip); $build($sameZip);
    runtime_check(hash_file('sha256', $firstZip) === hash_file('sha256', $sameZip), 'The same source/release must produce exactly the same ZIP bytes.');
    runtime_check($zip->open($firstZip) === true, 'Could not read the first release ZIP.');
    $oldTime = $zip->statName('lib/api_setup.php')['mtime'];
    $extract = $fixture . '/extracted';
    runtime_check($zip->extractTo($extract), 'Could not extract the first release.');
    $zip->close();
    clearstatcache(true, $extract . '/lib/api_setup.php');
    runtime_check(filemtime($extract . '/lib/api_setup.php') === $oldTime, 'PHP ZIP extraction must preserve the release mtime being tested.');
    runtime_release($builderRoot . '/src', '2.4.4');
    $newZip = $fixture . '/2.4.4.zip';
    $build($newZip);
    runtime_check($zip->open($newZip) === true, 'Could not read the next release ZIP.');
    $newTime = $zip->statName('lib/api_setup.php')['mtime'];
    runtime_check($newTime > $oldTime, 'A newer release must change the timestamp used by OPcache.');
    runtime_check($newTime < time() && (int) date('Y', $newTime) >= 2000, 'The reproducible timestamp must be a valid past ZIP date.');
    for ($index = 0; $index < $zip->numFiles; $index++) {
        runtime_check($zip->statIndex($index)['mtime'] === $newTime, 'Every entry must carry the release timestamp.');
    }
    runtime_check($zip->extractTo($extract), 'Could not extract the next release in place.');
    $zip->close();
    clearstatcache(true, $extract . '/lib/api_setup.php');
    runtime_check(filemtime($extract . '/lib/api_setup.php') === $newTime, 'An in-place upgrade must change the extracted PHP timestamp.');
    runtime_release($builderRoot . '/src', '2.4.4.1');
    $fourthZip = $fixture . '/2.4.4.1.zip';
    $build($fourthZip);
    runtime_check($zip->open($fourthZip) === true, 'Could not read the four-component release.');
    runtime_check($zip->statName('lib/api_setup.php')['mtime'] === $newTime + 2, 'The fourth version component must respect ZIP two-second resolution.');
    $zip->close();
    runtime_release($builderRoot . '/src', '99.99.99.99');
    $maximumZip = $fixture . '/maximum.zip';
    $build($maximumZip);
    runtime_check($zip->open($maximumZip) === true, 'Could not read the maximum supported release.');
    runtime_check($zip->statName('lib/api_setup.php')['mtime'] < time(), 'Even the maximum supported release must retain a past, valid ZIP timestamp.');
    $zip->close();
    runtime_release($builderRoot . '/src', '2.4.4');
    file_put_contents($builderRoot . '/src/composer.json', json_encode(['version' => '2.4.3']));
    runtime_check(strpos($build($fixture . '/mismatch.zip', false), 'versions must match') !== false, 'A mixed Composer/manifest release must be rejected.');
    runtime_release($builderRoot . '/src', '2.4.4');
    file_put_contents($builderRoot . '/src/lib/api_setup.php', "<?php const MY_VERSION = '2.4.3';");
    runtime_check(strpos($build($fixture . '/mixed-runtime.zip', false), 'versions must match') !== false, 'A mixed PHP/manifest release must be rejected.');
    runtime_release($builderRoot . '/src', '2.4.100');
    runtime_check(strpos($build($fixture . '/bad-version.zip', false), 'numeric components') !== false, 'Unsupported version components must fail before producing a ZIP.');
    foreach (['mismatch.zip', 'mixed-runtime.zip', 'bad-version.zip'] as $name) runtime_check(!file_exists($fixture . '/' . $name), 'Rejected builds must not publish an installer.');

    $runtimeRoot = $fixture . '/runtime-plugin';
    foreach (['lib', 'public', 'vendor', 'data'] as $directory) mkdir($runtimeRoot . '/' . $directory, 0700, true);
    copy($sourceRoot . '/src/lib/api_runtime.php', $runtimeRoot . '/lib/api_runtime.php');
    mkdir($runtimeRoot . '-other/lib', 0700, true);
    $child = $fixture . '/cached-check.php';
    file_put_contents($child, <<<'PHP'
<?php
require $argv[1] . '/lib/api_runtime.php';
function check(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
$root = $argv[1];
$files = [
    'own' => $root . '/lib/cached.php',
    'main' => $root . '/main.php',
    'panel' => $root . '/public/index.php',
    'vendor' => $root . '/vendor/cached.php',
    'data' => $root . '/data/cached.php',
    'other' => $root . '-other/lib/cached.php'
];
foreach ($files as $key => $file) {
    file_put_contents($file, '<?php return ' . var_export($key . '-old', true) . ';');
    touch($file, 946684800);
    clearstatcache(true, $file);
    check((include $file) === $key . '-old', 'Initial cached contents are incorrect.');
    check(opcache_is_script_cached($file), 'OPcache did not cache the fixture; the test cannot prove invalidation.');
    file_put_contents($file, '<?php return ' . var_export($key . '-new', true) . ';');
    touch($file, 946684802);
    clearstatcache(true, $file);
}
$result = refresh_plugin_runtime($root);
check($result['enabled'], 'OPcache must be active in this subprocess.');
if ($argv[2] === 'zero') {
    check(in_array('lib/cached.php', $result['unverifiable'], true), 'Timestamp-zero entries must be reported, not silently accepted.');
    check($result['needs_restart'] && !$result['invalidated'], 'Unavailable timestamps must not flush every script on every request.');
    check((include $files['own']) === 'own-old', 'Timestamp-zero cached data changed without an explicit refresh.');
} else {
    $invalidated = $result['invalidated']; sort($invalidated);
    check($invalidated === ['lib/cached.php', 'main.php', 'public/index.php'], 'Only modified, cached plugin PHP files should be invalidated: ' . json_encode($result));
    check(!$result['failed'] && !$result['unverifiable'], 'Selective invalidation unexpectedly failed.');
    check((include $files['own']) === 'own-new', 'Requiring the invalidated PHP file must read the new release.');
    check((include $files['main']) === 'main-new', 'The bootstrap must also refresh a cached plugin entry point.');
    $second = refresh_plugin_runtime($root);
    check($second['invalidated'] === [] && !$second['unverifiable'], 'Unchanged or already invalidated files must not be refreshed or flagged again: ' . json_encode($second));
}
foreach (['vendor', 'data', 'other'] as $key) check((include $files[$key]) === $key . '-old', 'The bootstrap invalidated a file outside its allowed scope: ' . $key);
echo 'Cached PHP check passed: ' . $argv[2] . PHP_EOL;
PHP
    );
    $phpOptions = [PHP_BINARY, '-n'];
    if (getenv('PLUGIN_TEST_OPCACHE_EXTENSION')) $phpOptions = array_merge($phpOptions, ['-d', 'zend_extension=' . getenv('PLUGIN_TEST_OPCACHE_EXTENSION')]);
    $phpOptions = array_merge($phpOptions, ['-d', 'opcache.enable_cli=1', '-d', 'opcache.revalidate_freq=3600', '-d', 'opcache.file_update_protection=0']);
    runtime_process(array_merge($phpOptions, ['-d', 'opcache.validate_timestamps=1', $child, $runtimeRoot, 'normal']), $fixture);
    runtime_process(array_merge($phpOptions, ['-d', 'opcache.validate_timestamps=0', $child, $runtimeRoot, 'zero']), $fixture);
    $disabledChild = $fixture . '/uncached-check.php';
    file_put_contents($disabledChild, <<<'PHP'
<?php
require $argv[1] . '/lib/api_runtime.php';
$result = refresh_plugin_runtime($argv[1]);
if ($result['enabled'] || $result['invalidated']) throw new RuntimeException('Disabled CLI caching must safely do nothing.');
echo 'Disabled CLI check passed.' . PHP_EOL;
PHP
    );
    runtime_process(array_merge($phpOptions, ['-d', 'opcache.enable_cli=0', $disabledChild, $runtimeRoot]), $fixture);
    echo 'Runtime regression checks passed: release ZIP reproducibility/mtime, extraction upgrades, version coherence, real selective OPcache refresh, isolation and timestamp-zero reporting.' . PHP_EOL;
} finally {
    // Delete only the newly created, resolved fixture directory.
    $resolved = realpath($fixture);
    if ($resolved === false || $resolved !== $fixtureReal || strpos(basename($resolved), 'uisp-runtime-') !== 0) {
        throw new RuntimeException('Refusing to clean an unexpected runtime fixture path.');
    }
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname());
        else unlink($entry->getPathname());
    }
    rmdir($resolved);
}
