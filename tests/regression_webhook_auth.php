<?php
// Isolated authorization contract and real public.php loopback rejection; no UISP or RouterOS connection.
require_once __DIR__ . '/../src/lib/api_security.php';
function checkWebhook(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function webhookFixture(string $entity = 'service', string $action = 'edit'): object {
    return (object) ['entity' => $entity, 'entityId' => $entity === 'client' ? '70' : '1353', 'changeType' => $action,
        'uuid' => 'REGRESSION-PRIVATE-UUID-0001', 'extraData' => (object) ['entity' => (object) ['id' => 1353,
            'attributes' => [(object) ['key' => 'password', 'value' => 'REGRESSION-PRIVATE-PASSWORD']]]]];
}
function webhookReason($event, array $server, array $query, array $config, ?string $expected): void {
    $actual = ApiSecurity::webhookRejectionReason($event, $server, $query, $config);
    checkWebhook($actual === $expected, 'Unexpected webhook rejection reason: ' . var_export($actual, true) . ' expected ' . var_export($expected, true));
    checkWebhook(ApiSecurity::webhookAuthorized($event, $server, $query, $config) === ($expected === null), 'Authorization differs from its rejection reason');
}
$secret = 'REGRESSION-DUMMY-SECRET-0123456789ABCDEF';
$wrong = 'REGRESSION-DUMMY-WRONG-TOKEN-0123456789';
$config = ['webhookSecret' => $secret];
foreach (['service', 'client'] as $entity) {
    foreach (['insert', 'edit', 'delete', 'suspend', 'unsuspend', 'suspend_cancel', 'end', 'activate'] as $action) {
        $event = webhookFixture($entity, $action);
        foreach ([['HTTP_AUTHORIZATION' => 'Bearer ' . $secret], ['HTTP_X_WEBHOOK_TOKEN' => $secret]] as $server) webhookReason($event, $server, [], $config, null);
        webhookReason($event, [], ['webhookToken' => $secret], $config, null);
    }
}
$event = webhookFixture();
foreach ([null, '', 'too-short', [], (object) [], 12345] as $unconfigured) {
    webhookReason($event, ['HTTP_X_WEBHOOK_TOKEN' => $secret], [], ['webhookSecret' => $unconfigured], 'webhook_secret_not_configured');
}
webhookReason($event, ['HTTP_X_WEBHOOK_TOKEN' => str_repeat('a', 32)], [], ['webhookSecret' => str_repeat('a', 32)], null);
webhookReason($event, [], [], $config, 'webhook_token_missing');
webhookReason($event, ['HTTP_X_WEBHOOK_TOKEN' => ''], [], $config, 'webhook_token_missing');
webhookReason($event, ['HTTP_X_WEBHOOK_TOKEN' => $wrong], [], $config, 'webhook_token_mismatch');
webhookReason($event, [], ['webhookToken' => []], $config, 'webhook_token_mismatch');
webhookReason($event, ['HTTP_X_WEBHOOK_TOKEN' => []], ['webhookToken' => $secret], $config, 'webhook_token_mismatch');
webhookReason($event, ['HTTP_X_WEBHOOK_TOKEN' => 12345], [], $config, 'webhook_token_mismatch');
// Only a syntactically valid Bearer value overrides the existing header/query fallback contract.
webhookReason($event, ['HTTP_AUTHORIZATION' => ['Bearer ' . $wrong], 'HTTP_X_WEBHOOK_TOKEN' => $secret], [], $config, null);
webhookReason($event, ['HTTP_AUTHORIZATION' => 'Bearer ', 'HTTP_X_WEBHOOK_TOKEN' => $secret], [], $config, null);
webhookReason($event, ['HTTP_AUTHORIZATION' => 'Bearer ' . $wrong, 'HTTP_X_WEBHOOK_TOKEN' => $secret], ['webhookToken' => $secret], $config, 'webhook_token_mismatch');
webhookReason($event, ['HTTP_AUTHORIZATION' => 'Bearer ' . $secret, 'HTTP_X_WEBHOOK_TOKEN' => $wrong], ['webhookToken' => $wrong], $config, null);
webhookReason($event, ['HTTP_X_WEBHOOK_TOKEN' => $wrong], ['webhookToken' => $secret], $config, 'webhook_token_mismatch');
webhookReason($event, ['HTTP_X_WEBHOOK_TOKEN' => ''], ['webhookToken' => $secret], $config, 'webhook_token_missing');
foreach ([false, 0, '0', 'false', 'off', 'no', null, [], (object) [], 1.0, 2, 'invalid', '2'] as $disabled) {
    webhookReason($event, [], [], ['webhookAllowWithoutSecret' => $disabled], 'webhook_secret_not_configured');
    webhookReason($event, [], [], $config + ['webhookAllowWithoutSecret' => $disabled], 'webhook_token_missing');
}
foreach ([true, 1, '1', 'true', 'TRUE', 'on', 'yes'] as $enabled) {
    foreach (['service', 'client'] as $entity) foreach (['edit', 'suspend', 'unsuspend'] as $action) {
        webhookReason(webhookFixture($entity, $action), [], [], ['webhookAllowWithoutSecret' => $enabled], null);
    }
}
foreach (['https://fixture.invalid/plugin/public.php?webhookToken=PRIVATE-URL-TOKEN', 'http://fixture.invalid', ' HTTPS://fixture.invalid/plugin '] as $url) {
    webhookReason($event, [], [], ['webhookSecret' => $url], 'webhook_secret_contains_url');
    webhookReason($event, [], [], ['webhookSecret' => $url, 'webhookAllowWithoutSecret' => true], null);
}
foreach ([null, [], (object) [], (object) ['entity' => 'devices', 'changeType' => 'edit', 'entityId' => 1, 'uuid' => $event->uuid]] as $invalid) {
    webhookReason($invalid, ['HTTP_X_WEBHOOK_TOKEN' => $secret], [], $config, 'invalid_webhook');
}
foreach (['rebuild', 'list', 'config', [], null] as $action) {
    $invalid = clone $event; $invalid->changeType = $action;
    webhookReason($invalid, ['HTTP_X_WEBHOOK_TOKEN' => $secret], [], $config, 'invalid_webhook');
    webhookReason($invalid, [], [], ['webhookAllowWithoutSecret' => true], 'invalid_webhook');
}
foreach (['', 'short', str_repeat('a', 129), 'invalid/REGRESSION-UUID-0001', []] as $uuid) {
    $invalid = clone $event; $invalid->uuid = $uuid;
    webhookReason($invalid, ['HTTP_X_WEBHOOK_TOKEN' => $secret], [], $config, 'invalid_webhook');
    webhookReason($invalid, [], [], ['webhookAllowWithoutSecret' => true], 'invalid_webhook');
}
foreach ([0, -1, '0', '1.5', '1e3', [], (object) [], true] as $id) {
    $invalid = clone $event; $invalid->entityId = $id;
    webhookReason($invalid, ['HTTP_X_WEBHOOK_TOKEN' => $secret], [], $config, 'invalid_webhook');
    webhookReason($invalid, [], [], ['webhookAllowWithoutSecret' => true], 'invalid_webhook');
}
checkWebhook(!ApiSecurity::isAdmin(null, true) && !ApiSecurity::isAdmin((object) ['isClient' => true, 'permissions' => ['system/plugins' => 'edit']], true),
    'A webhook validation failure granted administrator access');

$source = realpath(__DIR__ . '/../src');
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uisp-webhook-auth-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
foreach (['lib', 'data', 'vendor'] as $folder) mkdir($directory . '/' . $folder, 0700);
foreach (glob($source . '/lib/*.php') as $file) copy($file, $directory . '/lib/' . basename($file));
copy($source . '/public.php', $directory . '/public.php');
file_put_contents($directory . '/vendor/autoload.php', '<?php');
file_put_contents($directory . '/lib/api_uisp_client.php', <<<'PHP'
<?php
class ApiUispClient {
    public static function security() {
        return new class {
            public function getUser() { return null; }
        };
    }
}
PHP);
file_put_contents($directory . '/lib/api_setup.php', <<<'PHP'
<?php
if (!is_file(__DIR__ . '/../accepted-mode-enabled')) {
    file_put_contents(__DIR__ . '/../setup-called', 'unexpected');
    throw new RuntimeException('Unauthorized setup called');
}
file_put_contents(__DIR__ . '/../accepted-mode-entered', 'controlled');
http_response_code(200);
respond('controlled_webhook_flow');
exit;
PHP);
file_put_contents($directory . '/router.php', '<?php set_include_path(__DIR__ . "/lib" . PATH_SEPARATOR . get_include_path()); require __DIR__ . "/public.php";');
$process = null;
try {
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    checkWebhook($listener !== false, 'Could not allocate isolated loopback port');
    $address = stream_socket_get_name($listener, false); fclose($listener);
    $process = proc_open([PHP_BINARY, '-n', '-S', $address, '-t', $directory, $directory . '/router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server-out.log', 'a'], 2 => ['file', $directory . '/server-error.log', 'a']],
        $pipes, $directory, null, ['bypass_shell' => true, 'create_no_window' => true]);
    checkWebhook(is_resource($process), 'Could not start isolated PHP HTTP server'); fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @stream_socket_client('tcp://' . $address, $errorNumber, $errorMessage, 0.05);
        if ($socket !== false) { fclose($socket); $ready = true; break; }
        usleep(20000);
    }
    checkWebhook($ready, 'Isolated PHP HTTP server did not become ready');
    $request = static function (object $body, array $headers = [], string $query = '', string $method = 'POST', string $contentType = 'application/json') use ($address): array {
        $httpHeaders = ['Content-Type: ' . $contentType];
        foreach ($headers as $name => $value) $httpHeaders[] = $name . ': ' . $value;
        $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $httpHeaders),
            'content' => json_encode($body, JSON_THROW_ON_ERROR), 'ignore_errors' => true, 'timeout' => 3]]);
        $response = file_get_contents('http://' . $address . '/public.php' . $query, false, $context);
        $status = (int) (preg_match('/^HTTP\/\S+ (\d+)/', $http_response_header[0] ?? '', $match) ? $match[1] : 0);
        return [$status, json_decode($response, true, 512, JSON_THROW_ON_ERROR)];
    };
    $cases = [
        [[], webhookFixture('service', 'edit'), [], '', 'webhook_secret_not_configured'],
        [['webhookSecret' => 'too-short'], webhookFixture('service', 'suspend'), [], '', 'webhook_secret_not_configured'],
        [$config, webhookFixture('service', 'unsuspend'), [], '', 'webhook_token_missing'],
        [$config, webhookFixture('client', 'edit'), ['X-Webhook-Token' => $wrong], '', 'webhook_token_mismatch'],
        [$config, webhookFixture('service', 'edit'), [], '?webhookToken=' . rawurlencode($wrong), 'webhook_token_mismatch'],
        [$config, webhookFixture('service', 'edit'), ['Authorization' => 'Bearer ' . $wrong, 'X-Webhook-Token' => $secret],
            '?webhookToken=' . rawurlencode($secret), 'webhook_token_mismatch'],
        [['webhookAllowWithoutSecret' => false], webhookFixture('service', 'edit'), [], '', 'webhook_secret_not_configured'],
        [['webhookAllowWithoutSecret' => 'invalid'], webhookFixture('service', 'edit'), [], '', 'webhook_secret_not_configured'],
        [['webhookSecret' => 'https://fixture.invalid/plugin/public.php?webhookToken=PRIVATE-URL-TOKEN'], webhookFixture('service', 'edit'), [], '', 'webhook_secret_contains_url'],
    ];
    foreach ($cases as $index => [$settings, $body, $headers, $query, $reason]) {
        file_put_contents($directory . '/data/config.json', json_encode($settings + ['debugEnable' => false]));
        [$status, $response] = $request($body, $headers, $query);
        checkWebhook($status === 403 && ($response['message'] ?? null) === 'unauthorized' && ($response['error'] ?? null) === true,
            'A rejected webhook did not return HTTP 403');
        $lines = file($directory . '/data/plugin.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        checkWebhook(count($lines) === $index + 1, 'Rejected webhook logging depended on debug mode or duplicated the event');
        $entry = json_decode(substr($lines[$index], strpos($lines[$index], '{')), true, 512, JSON_THROW_ON_ERROR);
        checkWebhook($entry === ['webhook_rejected', 'reason' => $reason, 'entity' => $body->entity, 'entityId' => (int) $body->entityId, 'changeType' => $body->changeType],
            'The rejection log contains unexpected routing data');
    }
    // Valid secrets cannot promote an arbitrary administration action to a webhook.
    $invalidAction = webhookFixture(); $invalidAction->changeType = 'rebuild';
    [$status, $response] = $request($invalidAction, ['X-Webhook-Token' => $secret]);
    checkWebhook($status === 403 && count(file($directory . '/data/plugin.log', FILE_SKIP_EMPTY_LINES)) === count($cases),
        'An unsupported action gained webhook access or was logged as a valid webhook');
    checkWebhook(!is_file($directory . '/setup-called'), 'A rejected webhook entered setup');
    file_put_contents($directory . '/accepted-mode-enabled', 'fixture-only');
    foreach ([[$config, ['X-Webhook-Token' => $secret]], [['webhookAllowWithoutSecret' => true], []],
        [['webhookAllowWithoutSecret' => 'true'], []], [['webhookAllowWithoutSecret' => 1], []]] as [$settings, $headers]) {
        file_put_contents($directory . '/data/config.json', json_encode($settings + ['debugEnable' => false]));
        foreach (['service', 'client'] as $entity) {
            if (is_file($directory . '/accepted-mode-entered')) unlink($directory . '/accepted-mode-entered');
            [$status, $response] = $request(webhookFixture($entity, 'edit'), $headers);
            checkWebhook($status === 200 && ($response['message'] ?? null) === 'controlled_webhook_flow'
                && is_file($directory . '/accepted-mode-entered'), 'Explicit compatibility or authenticated webhook did not reach controlled flow');
        }
    }
    file_put_contents($directory . '/data/config.json', json_encode(['webhookAllowWithoutSecret' => true, 'debugEnable' => false]));
    foreach ([
        [webhookFixture(), '?page=panel', 'GET', 'application/json', 403],
        [webhookFixture(), '', 'POST', 'application/x-www-form-urlencoded', 400],
        [$invalidAction, '', 'POST', 'application/json', 403],
        [(object) ['entity' => 'config', 'changeType' => 'edit', 'data' => (object) ['disable_contention' => true]], '', 'POST', 'application/json', 403],
    ] as [$body, $query, $method, $type, $expectedStatus]) {
        if (is_file($directory . '/accepted-mode-entered')) unlink($directory . '/accepted-mode-entered');
        [$status, $response] = $request($body, [], $query, $method, $type);
        checkWebhook($status === $expectedStatus && !is_file($directory . '/accepted-mode-entered'),
            'Compatibility mode bypassed GET/form/schema/administration restrictions');
    }
    $log = file_get_contents($directory . '/data/plugin.log');
    foreach ([$secret, $wrong, $event->uuid, 'REGRESSION-PRIVATE-PASSWORD', 'webhookToken=', 'PRIVATE-URL-TOKEN', 'fixture.invalid', 'Bearer ', 'attributes', 'extraData'] as $privateValue) {
        checkWebhook(strpos($log, $privateValue) === false, 'A rejection log exposed credentials, UUID, URL or request body');
    }
    checkWebhook(!is_file($directory . '/setup-called') && glob($directory . '/data/*.db') === [] && !is_file($directory . '/data/queue.json'),
        'Rejected webhook authentication initialized or wrote provisioning state');
    echo "Webhook authorization regression passed: required-secret defaults, explicit compatibility, URL-secret diagnosis, channels/precedence, malformed input, public HTTP accept/reject, safe warning logs and zero provisioning writes.\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    $resolved = realpath($directory); $temporaryRoot = realpath(sys_get_temp_dir());
    if ($resolved && $temporaryRoot && strpos($resolved, $temporaryRoot . DIRECTORY_SEPARATOR . 'uisp-webhook-auth-') === 0) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($resolved);
    }
}
