<?php
// Isolated regression tests. No UISP API or router is contacted.
require_once __DIR__ . '/../src/lib/api_sqlite.php';
require_once __DIR__ . '/../src/lib/api_ip.php';
require_once __DIR__ . '/../src/lib/mt.php';
require_once __DIR__ . '/../src/lib/mt_data.php';

final class RouterTestLog {
    public array $entries = [];
    public function Append($message, $level = 0): void { $this->entries[] = $message; }
}
$routerTestLog = new RouterTestLog();
function MyLog() { global $routerTestLog; return $routerTestLog; }
function checkRouter($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function expectRouterError(callable $operation, string $message): void {
    try { $operation(); } catch (Throwable $error) { return; }
    throw new RuntimeException($message);
}
function localRouterStreams(): array {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$server) throw new RuntimeException($error);
    $address = stream_socket_get_name($server, false);
    $writer = stream_socket_client('tcp://' . $address, $errno, $error, 2);
    $reader = stream_socket_accept($server, 2);
    fclose($server);
    if (!$writer || !$reader) throw new RuntimeException('Cannot create local test sockets');
    return [$reader, $writer];
}
function encodeRouterResponse(RouterosAPI $api, array $words): string {
    $frame = '';
    foreach ($words as $word) $frame .= $api->encodeLength(strlen($word)) . $word;
    return $frame . chr(0);
}

class RecordingRouterAPI extends RouterosAPI {
    public array $words = [];
    public array $answers = [];
    public function write($word, $param2 = true) { $this->words[] = $word; return true; }
    public function finishSentence(): void { $this->words[] = '<end>'; }
    public function read($parse = true) { return array_shift($this->answers) ?? []; }
    public function disconnect() { $this->connected = false; }
}
class LookupTestMT extends MT {
    public RecordingRouterAPI $recording;
    public function __construct() { parent::__construct(); $this->recording = new RecordingRouterAPI(); }
    protected function api_connect(): ?RouterosAPI { $this->recording->connected = true; return $this->recording; }
    public function lookup(array $post): ?string { return $this->find_id($post); }
}
class BatchTestMT extends LookupTestMT {
    public array $calls = [];
    protected function write($post) {
        $this->calls[] = $post['name'];
        return $post['name'] === 'failed' ? ['!fatal' => [['message' => 'connection lost']]] : [];
    }
}
class AddressTestData extends MtData {
    public function allocate(): ?string { return $this->assign_address(false); }
}
class OfflineTestMT extends MT {
    protected function api_connect(): ?RouterosAPI { return null; }
}
class LoginTestAPI extends RouterosAPI {
    public array $sockets = [];
    public array $contexts = [];
    public array $commands = [];
    public bool $skipWrites = false;
    public bool $failWrites = false;
    protected function openSocket(string $address, $context) {
        $this->contexts[] = [$address,stream_context_get_options($context)];
        return array_shift($this->sockets) ?: false;
    }
    public function write($word,$param2=true) {
        if (strpos($word,'/')===0) $this->commands[]=$word;
        if ($this->failWrites) throw new RuntimeException('RouterOS connection failed while writing');
        if ($this->skipWrites) return true;
        return parent::write($word,$param2);
    }
}
class LoginTestMT extends MT {
    public LoginTestAPI $loginApi;
    public array $writes = [];
    public function __construct(LoginTestAPI $api) { parent::__construct(); $this->loginApi=$api; }
    protected function createApi(): RouterosAPI { return $this->loginApi; }
    protected function write($post) { $this->writes[]=$post; return []; }
}
class OwnershipTestMT extends LookupTestMT {
    public function execute(array $command) { return $this->write($command); }
}

if (($argv[1] ?? '') === '--worker') {
    $apiSqlite = new ApiSqlite($argv[2]);
    $apiSqliteCache = new ApiSqlite($argv[3]);
    $worker = new ApiIP();
    echo $worker->assign((int)$argv[4], (object)['pool' => '10.8.0.0/30']);
    $apiSqlite->close();
    $apiSqliteCache->close();
    exit;
}

$temp = sys_get_temp_dir() . '/uisp-router-regression-' . bin2hex(random_bytes(6));
mkdir($temp);
$mainFile = $temp . '/data.db';
$cacheFile = $temp . '/cache.db';
$apiSqlite = new ApiSqlite($mainFile);
$apiSqliteCache = new ApiSqlite($cacheFile);
$originalDirectory = getcwd();
$heldQueue = null;
try {
    $apiSqlite->exec(file_get_contents(__DIR__ . '/../src/includes/schema.sql'));
    $apiSqliteCache->exec(file_get_contents(__DIR__ . '/../src/includes/cache.sql'));
    $apiSqlite->saveConfig([
        'ppp_pool' => '10.9.0.0/24,fd09::/48', 'router_ppp_pool' => false,
        'disabled_rate' => 2, 'disabled_profile' => 'disabled', 'disabled_list' => 'disabled',
        'disable_contention' => true, 'lease_time' => 12,
    ]);

    $parser = new RouterosAPI();
    $result = $parser->parseResponse(['!re', '=name=cliente=oficina', '=comment=a=b=c', '!done']);
    checkRouter($result[0]['name'] === 'cliente=oficina' && $result[0]['comment'] === 'a=b=c', 'Parser truncated an equals sign');
    $parser->socket = fopen('php://temp', 'w+');
    $word = "=name=first\n=disabled=no";
    $parser->write($word);
    rewind($parser->socket);
    checkRouter(stream_get_contents($parser->socket) === $parser->encodeLength(strlen($word)) . $word . chr(0), 'A newline became an injected API word');
    $parser->disconnect();

    [$reader, $writer] = localRouterStreams();
    $parser->socket = $reader;
    $parser->connected = true;
    fwrite($writer, chr(5) . 'abc');
    fclose($writer);
    expectRouterError(function () use ($parser) { $parser->read(); }, 'Truncated response did not fail');
    $parser->disconnect();

    [$reader, $writer] = localRouterStreams();
    $parser->socket = $reader;
    $parser->connected = true;
    stream_set_timeout($reader, 1);
    $started = microtime(true);
    expectRouterError(function () use ($parser) { $parser->read(); }, 'Timeout did not fail');
    checkRouter(microtime(true) - $started < 3, 'Timeout did not terminate promptly');
    fclose($writer);
    $parser->disconnect();

    [$reader, $writer] = localRouterStreams();
    $parser->socket = $reader;
    $parser->connected = true;
    fwrite($writer, encodeRouterResponse($parser, ['!fatal', '=message=session terminated']));
    $fatal = $parser->read();
    checkRouter(isset($fatal['!fatal']) && !$parser->connected, 'Fatal response waited for a done response');
    fclose($writer);
    $parser->disconnect();

    // A reachable TCP socket is not evidence of API login success.
    foreach ([['!trap','=message=PRIVATE-LOGIN-DETAIL','!done'],['!fatal','=message=PRIVATE-LOGIN-DETAIL'],
        ['!done','=ret=not-a-valid-challenge']] as $response) {
        [$reader,$writer]=localRouterStreams();
        $loginApi=new LoginTestAPI(); $loginApi->sockets=[$reader]; $loginApi->attempts=1; $loginApi->timeout=1;
        fwrite($writer,encodeRouterResponse($loginApi,$response));
        $expected=$response[0]==='!trap' ? 'router_login_rejected'
            : ($response[0]==='!fatal' ? 'router_login_connection_closed' : 'router_login_protocol_error');
        $loginApi->debug=true; ob_start();
        $accepted=$loginApi->connect('127.0.0.1','PRIVATE-LOGIN-USER','PRIVATE-LOGIN-PASSWORD');
        $debug=ob_get_clean();
        checkRouter(!$accepted && $loginApi->connectionFailure()===$expected && $loginApi->error_str===$expected
            && $loginApi->socket===null && $loginApi->commands===['/login'], 'Reachable socket login failure was misclassified or left open');
        checkRouter(strpos($debug,'PRIVATE-LOGIN-')===false,'Login diagnostics exposed credentials or raw router response');
        $loginApi->debug=false;
        fclose($writer);
    }
    foreach ([false,true] as $tls) {
        $loginApi=new LoginTestAPI(); $loginApi->sockets=[false]; $loginApi->ssl=$tls; $loginApi->attempts=1;
        checkRouter(!$loginApi->connect('127.0.0.1','PRIVATE-LOGIN-USER','PRIVATE-LOGIN-PASSWORD')
            && $loginApi->connectionFailure()===($tls ? 'router_tls_connect_failed' : 'router_tcp_connect_failed')
            && $loginApi->commands===[], 'Transport failure was treated as authentication rejection or sent API commands');
        $sslOptions=$loginApi->contexts[0][1]['ssl'];
        checkRouter($sslOptions['verify_peer']===true && $sslOptions['verify_peer_name']===true
            && $sslOptions['allow_self_signed']===false && $sslOptions['peer_name']==='127.0.0.1',
            'Connection diagnostics weakened certificate validation or changed the peer name');
    }
    foreach (['timeout','closed','protocol','write'] as $fault) {
        [$reader,$writer]=localRouterStreams();
        $loginApi=new LoginTestAPI(); $loginApi->sockets=[$reader]; $loginApi->attempts=1; $loginApi->timeout=1;
        if ($fault==='closed') { $loginApi->skipWrites=true; fclose($writer); }
        elseif ($fault==='protocol') fwrite($writer,chr(255));
        elseif ($fault==='write') $loginApi->failWrites=true;
        $expected=['timeout'=>'router_login_timeout','closed'=>'router_login_connection_closed',
            'protocol'=>'router_login_protocol_error','write'=>'router_login_write_failed'][$fault];
        $started=microtime(true);
        checkRouter(!$loginApi->connect('127.0.0.1','PRIVATE-LOGIN-USER','PRIVATE-LOGIN-PASSWORD')
            && $loginApi->connectionFailure()===$expected && $loginApi->socket===null
            && microtime(true)-$started<3, 'API login failure lost its safe reason: ' . $fault . ' => ' . ($loginApi->connectionFailure() ?? 'none'));
        if (is_resource($writer)) fclose($writer);
    }
    [$rejectedReader,$rejectedWriter]=localRouterStreams();
    [$successReader,$successWriter]=localRouterStreams();
    $loginApi=new LoginTestAPI(); $loginApi->sockets=[$rejectedReader,$successReader];
    $loginApi->attempts=2; $loginApi->delay=0; $loginApi->timeout=1;
    fwrite($rejectedWriter,encodeRouterResponse($loginApi,['!trap','=message=PRIVATE-LOGIN-DETAIL','!done']));
    fwrite($successWriter,encodeRouterResponse($loginApi,['!done']));
    checkRouter($loginApi->connect('127.0.0.1','PRIVATE-LOGIN-USER','PRIVATE-LOGIN-PASSWORD')
        && $loginApi->connectionFailure()===null && $loginApi->error_str===null && $loginApi->error_no===0
        && $loginApi->commands===['/login','/login'], 'A successful connection retry retained its previous login rejection');
    $loginApi->disconnect(); fclose($rejectedWriter); fclose($successWriter);
    [$reader,$writer]=localRouterStreams();
    $loginApi=new LoginTestAPI(); $loginApi->sockets=[$reader]; $loginApi->attempts=1;
    fwrite($writer,encodeRouterResponse($loginApi,['!done','=ret=0123456789abcdef0123456789abcdef'])
        . encodeRouterResponse($loginApi,['!done']));
    checkRouter($loginApi->connect('127.0.0.1','PRIVATE-LOGIN-USER','PRIVATE-LOGIN-PASSWORD')
        && $loginApi->connectionFailure()===null && $loginApi->commands===['/login','/login'],
        'Safe login diagnostics broke the legacy RouterOS challenge protocol');
    $loginApi->disconnect(); fclose($writer);

    foreach ([false,true] as $transportFailed) {
        $loginApi=new LoginTestAPI(); $writer=null;
        if ($transportFailed) $loginApi->sockets=[false];
        else {
            [$reader,$writer]=localRouterStreams(); $loginApi->sockets=[$reader];
            fwrite($writer,encodeRouterResponse($loginApi,['!trap','=message=PRIVATE-LOGIN-DETAIL','!done']));
        }
        $loginMt=new LoginTestMT($loginApi);
        $code=$transportFailed ? 'router_tcp_connect_failed' : 'router_login_rejected';
        checkRouter($loginMt->do_batch((object)['id'=>6,'ip'=>'127.0.0.1','user'=>'PRIVATE-LOGIN-USER','password'=>'PRIVATE-LOGIN-PASSWORD'],
            [['path'=>'/ppp/secret','name'=>'fixture-account','batch'=>'login-test']])===0
            && ($loginMt->failed()['login-test'] ?? null)===$code && $loginMt->writes===[]
            && $loginMt->status()->message===$code,'MT replaced the API login reason with a generic error or wrote after failed authentication');
        if (is_resource($writer)) fclose($writer);
        [$reader,$writer]=localRouterStreams(); $loginApi->sockets=[$reader];
        fwrite($writer,encodeRouterResponse($loginApi,['!done']));
        checkRouter($loginMt->do_batch((object)['id'=>6,'ip'=>'127.0.0.1','user'=>'PRIVATE-LOGIN-USER','password'=>'PRIVATE-LOGIN-PASSWORD'],
            [['path'=>'/ppp/secret','name'=>'fixture-account','batch'=>'login-retry']])===1
            && $loginMt->failed()===[] && count($loginMt->writes)===1 && !$loginMt->status()->error
            && $loginApi->connectionFailure()===null, 'A subsequent successful MT batch retained connection failure or skipped its account write');
        fclose($writer);
    }
    checkRouter(strpos(json_encode($routerTestLog->entries),'PRIVATE-LOGIN-')===false,
        'MT connection diagnostics exposed credentials or raw login response');

    $lookup = new LookupTestMT();
    $lookup->recording->answers = [[['.id' => '*hotspot']]];
    checkRouter($lookup->lookup(['path' => '/ip/hotspot/active', 'name' => 'alice']) === '*hotspot', 'Hotspot lookup failed');
    checkRouter(in_array('?user=alice', $lookup->recording->words, true), 'Hotspot was filtered by name instead of user');
    $lookup->recording->answers = [[['.id' => '*one'], ['.id' => '*two']]];
    checkRouter($lookup->lookup(['path' => '/ppp/active', 'name' => 'alice']) === '*one,*two', 'Suspension must disconnect every active session');
    $lookup->recording->words = [];
    $lookup->recording->answers = [[['.id' => '*queue']]];
    $lookup->lookup(['path' => '/queue/simple', 'name' => 'Juan - 123']);
    checkRouter(in_array('?name=Juan - 123', $lookup->recording->words, true) && !in_array('?name=Juan', $lookup->recording->words, true), 'Queue lookup preferred an unrelated legacy name');
    $lookup->recording->words = [];
    $lookup->recording->answers = [[['.id' => '*binding']]];
    $lookup->lookup(['path' => '/ipv6/dhcp-server/binding', 'duid' => '0x00030001dc2c6ee7106a', 'iaid' => 21]);
    checkRouter(in_array('?iaid=21', $lookup->recording->words, true), 'DHCPv6 lookup omitted IAID');
    $lookup->recording->words = [];
    $lookup->recording->answers = [[['.id'=>'*zero']]];
    checkRouter($lookup->lookup(['path'=>'/ppp/secret','name'=>'0'])==='*zero'
        && in_array('?name=0',$lookup->recording->words,true),'Literal username zero lost its RouterOS identity');

    $offline=new OfflineTestMT();
    expectRouterError(function() use($offline){ $offline->list((object)['id'=>1],['/ppp/secret']); },'Offline router was reported as a successful empty list');
    checkRouter($offline->status()->error,'Offline list did not preserve failure status');
    $partial=new LookupTestMT();
    $partial->recording->answers=[[['name'=>'first']],['!trap'=>[['message'=>'permission denied']]]];
    expectRouterError(function() use($partial){ $partial->list((object)['id'=>1],['/ppp/secret','/queue/simple']); },'Partial router read was reported as a complete list');
    checkRouter(!$partial->recording->connected && $partial->status()->error,'Partial read did not close its connection');
    $empty=new LookupTestMT();
    checkRouter($empty->list((object)['id'=>1],['/ppp/secret'])===[] && !$empty->status()->error,'A valid empty router response became an error');

    $batch = new BatchTestMT();
    $batch->do_batch((object)['id' => 1], [
        ['name' => 'ok', 'batch' => 'first', 'password' => 'test-secret'],
        ['name' => 'failed', 'batch' => 'first', 'password' => 'test-secret'],
        ['name' => 'skip', 'batch' => 'first'],
        ['name' => 'next', 'batch' => 'second'],
    ]);
    checkRouter($batch->calls === ['ok', 'failed', 'next'], 'Partial failure did not stop the service');
    checkRouter(!isset($batch->success()['first']) && isset($batch->failed()['first']) && isset($batch->success()['second']), 'A failed service was marked successful');
    checkRouter(strpos(json_encode($routerTestLog->entries), 'test-secret') === false, 'Password appeared in an error log');

    $device = (object)['id' => 1, 'pool' => '10.0.0.0/30', 'pool6' => '2001:db8::/48', 'pfxLength' => 56];
    $addresses = new ApiIP();
    checkRouter($addresses->assign(1, $device) === '10.0.0.1', 'First IPv4 reservation is wrong');
    $otherWorker = new ApiIP();
    checkRouter($otherWorker->assign(2, $device) === '10.0.0.2', 'Independent worker reused a reserved address');
    checkRouter($otherWorker->assign(3, $device) === null, 'Exhausted pool allocated an address');
    $workers = $apiSqlite->transaction(function () use ($mainFile, $cacheFile) {
        $workers = [];
        foreach ([81, 82] as $serviceId) {
            $command = [PHP_BINARY, '-n', '-d', 'error_reporting=24575', '-d', 'extension_dir=' . ini_get('extension_dir'),
                '-d', 'extension=sqlite3', '-d', 'extension=gmp', __FILE__, '--worker',
                $mainFile, $cacheFile, (string)$serviceId];
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Cannot start IP reservation worker');
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
        }
        // Both workers contend for the same write lock and the same two-address pool.
        usleep(200000);
        return $workers;
    });
    $workerAddresses = [];
    foreach ($workers as [$process, $pipes]) {
        $workerAddresses[] = trim(stream_get_contents($pipes[1]));
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        checkRouter(proc_close($process) === 0, 'Reservation worker failed: ' . $errors);
    }
    sort($workerAddresses);
    checkRouter($workerAddresses === ['10.8.0.1', '10.8.0.2'], 'Concurrent workers allocated duplicate IPs');
    checkRouter($addresses->assign(3, $device, true) === '2001:db8::/56', 'First IPv6 reservation is wrong');
    $device64 = clone $device;
    $device64->pfxLength = 64;
    checkRouter($otherWorker->assign(4, $device64, true) === '2001:db8:0:100::/64', 'IPv6 allocation overlaps a larger reserved prefix');
    expectRouterError(function () use ($addresses) { $addresses->set(5, '2001:0db8:0000:0100::1/64', true); }, 'Equivalent IPv6 prefix was accepted twice');
    $addresses->set(6, 'fd00:6::/64', true);
    $addresses->flush();
    checkRouter($apiSqlite->selectAddress(6, true) === 'fd00:6::/64', 'IPv6-only service was lost');
    $addresses->set(6, '10.0.1.6');
    $addresses->flush();
    checkRouter($apiSqlite->selectAddress(6, true) === 'fd00:6::/64', 'IPv4 update erased IPv6');
    checkRouter(!$addresses->in_subnet('2001:db8:1::', '2001:db8::/64'), 'Subnet check includes its exclusive upper boundary');
    $addresses->set(94,'2001:db8:94:1::1',true);
    checkRouter($apiSqlite->selectAddress(94,true)==='2001:db8:94:1::/64','A manual IPv6 prefix inherited the previous /56 allocation');
    $apiSqlite->insert(['id'=>95,'address'=>'10.56.0.1'],'network');
    $apiSqliteCache->insert(['id'=>95,'address'=>'not-an-ip'],'network');
    $isolated=new ApiIP();
    checkRouter($isolated->assign(96,(object)['pool'=>'10.56.0.0/29'])==='10.56.0.2','An invalid cached attribute blocked another service or released its durable address');
    checkRouter($apiSqliteCache->selectAddress(95)==='not-an-ip' && $apiSqlite->selectAddress(95)==='10.56.0.1','Quarantine discarded the invalid attribute or changed a durable reservation');
    expectRouterError(function() use($isolated){ $isolated->set(95,'not-an-ip'); },'Invalid manual IP was accepted');
    checkRouter($apiSqlite->selectAddress(95)==='10.56.0.1','A failed manual IP edit released its previous reservation');
    $addresses->set(95,'fd56::/64',true);
    $apiSqliteCache->edit(['id'=>95,'address6'=>'not-an-ipv6'],'network');
    checkRouter((new ApiIP())->assign(196,(object)['pool6'=>'fd56::/62','pfxLength'=>64],true)==='fd56:0:0:1::/64',
        'An invalid cached IPv6 attribute blocked another service or released its durable prefix');
    expectRouterError(function() use($isolated){ $isolated->set(95,'not-an-ipv6',true); },'Invalid manual IPv6 was accepted');
    checkRouter($apiSqlite->selectAddress(95,true)==='fd56::/64','A failed manual IPv6 edit released its previous prefix');
    $apiSqliteCache->delete(95,'network');

    $apiSqlite->saveConfig(['excl_addr'=>'2001:db9::/33']);
    $started=microtime(true);
    $large=(new ApiIP())->assign(97,(object)['pool6'=>'2001:db9::/32','pfxLength'=>64],true);
    checkRouter($large==='2001:db9:8000::/64' && microtime(true)-$started<2,'IPv6 exclusion enumerated billions of client prefixes instead of jumping');
    $apiSqlite->saveConfig(['excl_addr'=>'10.57.0.1-10.57.0.3,10.57.0.3-10.57.0.4,2001:dba::1-2001:dba::ffff']);
    checkRouter((new ApiIP())->assign(98,(object)['pool'=>'10.57.0.0/29'])==='10.57.0.5','Overlapping IPv4 exclusions skipped the wrong boundary');
    checkRouter((new ApiIP())->assign(199,(object)['pool6'=>'2001:dba::/62','pfxLength'=>64],true)==='2001:dba:0:1::/64','An unaligned IPv6 exclusion did not skip its whole client prefix');
    $apiSqlite->saveConfig(['excl_addr'=>null]);
    $allocatorSnapshot=(object)['ppp_pool'=>'10.61.0.0/29','excl_addr'=>'10.61.0.1-10.61.0.2'];
    $sharedAllocator=new ApiIP();
    checkRouter($sharedAllocator->assign(297,null,false,$allocatorSnapshot)==='10.61.0.3','Queued allocation did not preserve its pool/exclusions');
    $apiSqlite->saveConfig(['ppp_pool'=>'10.62.0.0/29']);
    checkRouter($sharedAllocator->assign(298)==='10.62.0.1','An allocation without a snapshot reused the preceding job configuration');
    $apiSqlite->saveConfig(['ppp_pool'=>'10.9.0.0/24,fd09::/48']);

    $plan = ['id' => 1, 'name' => 'Basic', 'ratio' => 1, 'uploadSpeed' => 20, 'downloadSpeed' => 50,
        'priorityUpload' => 4, 'priorityDownload' => 6, 'limitUpload' => 0, 'limitDownload' => 0,
        'burstUpload' => 0, 'burstDownload' => 0, 'threshUpload' => 0, 'threshDownload' => 0,
        'timeUpload' => 1, 'timeDownload' => 1];
    $service = ['id' => 10, 'clientId' => 20, 'planId' => 1, 'device' => 1, 'status' => 3,
        'company' => '', 'firstName' => 'Alice', 'lastName' => 'Test', 'mac' => 'DC:2C:6E:E7:10:6A',
        'duid' => '0x00030001dc2c6ee7106a', 'iaid' => '0x15', 'address' => '10.0.3.10',
        'address6' => 'fd00:10::/64', 'batch' => 'service-10', '_device' => (array)$device64];
    $data = new MtData();
    $commands = $data->get_data($service, $plan);
    checkRouter($commands['queues']['target'] === '10.0.3.10,fd00:10::/64', 'Reduced queue lost IPv6');
    checkRouter($commands['queues']['parent'] === 'none' && $commands['queues']['burst-limit'] === '0/0', 'Reduced queue retained stale parent/burst');
    checkRouter($commands['accounts']['lease-time'] === '12m' && $commands['dhcp6']['life-time'] === '12m', 'Lease time setting was ignored');
    checkRouter($commands['dhcp6']['duid'] === '0x00030001dc2c6ee7106a' && $commands['dhcp6']['iaid'] === 21, 'DHCPv6 identifier was truncated');
    foreach ($commands as $command) checkRouter($command['batch'] === 'service-10', 'An auxiliary command lacks service tracking');

    $addresses->set(10, '10.0.4.10');
    $addresses->set(10, 'fd00:40::/64', true);
    $delete = $service;
    $delete['action'] = 'delete';
    $oldCommands = (new MtData())->get_data($delete, $plan);
    checkRouter($oldCommands['accounts']['address'] === '10.0.3.10', 'Deletion lost its immutable old address');
    checkRouter($apiSqlite->selectAddress(10) === '10.0.4.10' && $apiSqlite->selectAddress(10, true) === 'fd00:40::/64', 'Deletion overwrote a newer address reservation');
    $delete['id'] = 99;
    unset($delete['address'], $delete['address6']);
    (new MtData())->get_data($delete, $plan);
    checkRouter($apiSqlite->selectAddress(99) === null && $apiSqlite->selectAddress(99, true) === null, 'Deletion allocated a new address');

    $ppp = $service;
    $ppp['id'] = 11;
    $ppp['status'] = 1;
    $ppp['username'] = 'alice';
    $ppp['password'] = 'test-secret';
    unset($ppp['mac'], $ppp['duid'], $ppp['iaid'], $ppp['address'], $ppp['address6']);
    $data = new AddressTestData();
    $data->set_data($ppp, $plan);
    checkRouter($data->allocate() === '10.9.0.1', 'Global PPP pool setting was ignored');
    $ppp['address'] = '10.9.0.1';
    $commands = $data->get_data($ppp, $plan);
    checkRouter(explode(' ', $commands['profiles']['rate-limit'])[4] === '4', 'PPP priority must be scalar');
    checkRouter($commands['profiles']['name']==='Basic [UISP#1]' && $commands['profiles']['comment']==='uisp-ros-plugin:plan:1','Active plan profile lacks its readable name or stable identity/ownership');
    $secondPlan=array_replace($plan,['id'=>2,'name'=>'Basic','uploadSpeed'=>100]);
    $secondService=array_replace($ppp,['id'=>12,'username'=>'0','password'=>'0','address'=>'10.9.0.12']);
    $secondCommands=(new MtData())->get_data($secondService,$secondPlan);
    checkRouter($secondCommands['profiles']['name']==='Basic [UISP#2]' && $secondCommands['accounts']['name']==='0'
        && $secondCommands['accounts']['password']==='0','Same-name plans collide or literal credentials are lost');
    $fallbackA=(new MtData())->get_data(array_replace($ppp,['id'=>13,'address'=>'10.9.0.13']),array_replace($plan,['id'=>-1]));
    $fallbackB=(new MtData())->get_data(array_replace($ppp,['id'=>14,'address'=>'10.9.0.14']),array_replace($plan,['id'=>-1]));
    checkRouter($fallbackA['profiles']['name']!==$fallbackB['profiles']['name'],'Services without a real plan share a fallback profile');
    $fallbackService=array_replace($ppp,['id'=>17,'address'=>'10.9.0.17','planId'=>null,
        '_plan'=>array_replace($plan,['id'=>-1]),'_config'=>(array)$apiSqlite->readConfig()]);
    $fallbackService['_config']['disable_contention']=false;
    $fallbackParent=(new MtData())->get_data($fallbackService,$fallbackService['_plan']);
    checkRouter($fallbackParent['parents']['name']==='uisp-service-17-parent'
        && $fallbackParent['parents']['target']===implode(',',array_filter([$fallbackParent['accounts']['remote-address'],$fallbackParent['accounts']['remote-ipv6-prefix'] ?? null]))
        && $fallbackParent['profiles']['parent-queue']==='uisp-service-17-parent','Fallback plan contention still shares a parent or unrelated targets');
    $suspended=(new MtData())->get_data(array_replace($ppp,['id'=>15,'address'=>'10.9.0.15','status'=>3]),$plan);
    checkRouter($suspended['profiles']['path']==='/ppp/profile'
        && $suspended['profiles']['name']==='disabled'
        && $suspended['profiles']['address-list']==='disabled'
        && $suspended['profiles']['rate-limit']==='2M/2M'
        && $suspended['profiles']['_bootstrap_suspension']===true
        && $suspended['profiles']['_bootstrap_required']===false
        && !isset($suspended['profiles']['_owner'])
        && $suspended['accounts']['profile']==='disabled'
        && $suspended['queues']['max-limit']==='2M/2M','Suspension lost its create-only bootstrap policy, configured list or individual speed cap');
    $cap=$suspended['queues'];
    $cap['batch']='suspension-test';
    $general=['.id'=>'*general','name'=>'general'];
    $capWriter=new OwnershipTestMT();
    $capWriter->recording->answers=[[],[$general],[]];
    $capWriter->execute($cap);
    checkRouter(in_array('/queue/simple/add',$capWriter->recording->words,true)
        && in_array('=place-before=*general',$capWriter->recording->words,true)
        && !in_array('/queue/simple/move',$capWriter->recording->words,true)
        && !$capWriter->recording->connected,'A new suspension cap did not precede the first matching queue');
    $ownedCap=['.id'=>'*cap','name'=>$cap['name'],'comment'=>$cap['_owner']];
    $capWriter->recording->words=[];
    $capWriter->recording->answers=[[$ownedCap],[$ownedCap],[$general,$ownedCap],[],[]];
    $capWriter->execute($cap);
    checkRouter(in_array('/queue/simple/set',$capWriter->recording->words,true)
        && in_array('/queue/simple/move',$capWriter->recording->words,true)
        && in_array('=numbers=*cap',$capWriter->recording->words,true)
        && in_array('=destination=*general',$capWriter->recording->words,true)
        && !in_array('=place-before=*general',$capWriter->recording->words,true),'An existing suspension cap was not moved ahead using stable RouterOS identities');
    $capWriter->recording->words=[];
    $capWriter->recording->answers=[[$ownedCap],[$ownedCap],[$ownedCap,$general],[]];
    $capWriter->execute($cap);
    checkRouter(!in_array('/queue/simple/move',$capWriter->recording->words,true),'Retrying a cap already first reordered queues unnecessarily');
    $capFailure=new OwnershipTestMT();
    $capFailure->recording->answers=[[$ownedCap],[$ownedCap],[$general,$ownedCap],[],['!trap'=>[['message'=>'move denied']]]];
    checkRouter($capFailure->do_batch((object)['id'=>1],[$cap])===0
        && isset($capFailure->failed()['suspension-test']) && !$capFailure->recording->connected,'A failed cap move was marked successful or leaked its connection');
    $capReadFailure=new OwnershipTestMT();
    $capReadFailure->recording->answers=[[],['!trap'=>[['message'=>'read denied']]]];
    checkRouter($capReadFailure->do_batch((object)['id'=>1],[$cap])===0
        && !in_array('/queue/simple/add',$capReadFailure->recording->words,true),'Queue order read failure still installed an ineffective cap');
    checkRouter($commands['queues']['action']==='remove','Reactivation did not remove the individual suspension cap');
    $frozenService=array_replace($ppp,['id'=>299,'address'=>null,'address6'=>null,'_config'=>(array)$apiSqlite->readConfig()]);
    $frozenService['_config']['ppp_pool']='10.63.0.0/29';
    $frozenService['_config']['excl_addr']='10.63.0.1-10.63.0.2';
    checkRouter((new MtData())->get_data($frozenService,$plan)['accounts']['remote-address']==='10.63.0.3','Data did not pass the queued configuration to global PPP allocation');
    $manual6=array_replace($service,['id'=>16,'status'=>1,'address'=>'10.0.3.16','address6'=>'2001:dbb:16:1::1']);
    checkRouter((new MtData())->get_data($manual6,$plan)['dhcp6']['address']==='2001:dbb:16:1::/64','Router-specific manual IPv6 prefix inherited another service length');

    $legacy=[['path'=>'/ppp/profile','name'=>'Basic','rate-limit'=>'20M/50M','parent-queue'=>'none','address-list'=>'active'],
        ['path'=>'/ppp/secret','name'=>'alice','password'=>'test-secret','profile'=>'Basic','remote-address'=>'10.9.0.1']];
    $safe=MtData::safePreparedCommands($ppp,$legacy);
    $profile=array_values(array_filter($safe,static function($c){ return $c['path']==='/ppp/profile'; }))[0];
    $account=array_values(array_filter($safe,static function($c){ return $c['path']==='/ppp/secret'; }))[0];
    checkRouter($profile['name']==='uisp-plan-1' && $account['profile']==='uisp-plan-1'
        && $profile['rate-limit']==='20M/50M' && $account['remote-address']==='10.9.0.1','Legacy retry migration changed frozen speeds or addresses');
    $legacyFallback=$legacy;
    $legacyFallback[0]['parent-queue']='servicePlan--1-parent';
    array_unshift($legacyFallback,['path'=>'/queue/simple','name'=>'servicePlan--1-parent',
        'target'=>'10.9.0.1,10.9.0.200','max-limit'=>'20M/50M']);
    $safeFallback=MtData::safePreparedCommands($fallbackService,$legacyFallback);
    $migratedParent=array_values(array_filter($safeFallback,static function($c){ return ($c['name'] ?? '')==='uisp-service-17-parent'; }))[0];
    checkRouter($migratedParent['target']==='10.9.0.1' && $migratedParent['max-limit']==='20M/50M'
        && isset($migratedParent['_owner']),'Legacy fallback migration changed rates or retained another account in its parent');
    $legacy[0]['name']='disabled';
    $legacy[0]['rate-limit']='2M/2M';
    $legacy[1]['profile']='disabled';
    $safeSuspended=MtData::safePreparedCommands(array_replace($ppp,['status'=>3]),$legacy);
    $suspensionProfiles=array_values(array_filter($safeSuspended,static function($c){ return $c['path']==='/ppp/profile'; }));
    $suspensionCaps=array_values(array_filter($safeSuspended,static function($c){ return $c['path']==='/queue/simple'; }));
    checkRouter(count($suspensionProfiles)===1
        && $suspensionProfiles[0]['name']==='disabled'
        && $suspensionProfiles[0]['_bootstrap_suspension']===true
        && $suspensionProfiles[0]['_bootstrap_required']===false
        && !isset($suspensionProfiles[0]['_owner'])
        && count($suspensionCaps)===1 && $suspensionCaps[0]['max-limit']==='2M/2M',
        'Legacy suspension retry lost its create-only external-profile bootstrap or frozen individual cap');
    foreach($legacy as &$command) $command['action']='remove';
    unset($command);
    $safeDelete=MtData::safePreparedCommands($ppp,$legacy,'delete');
    checkRouter(!array_filter($safeDelete,static function($c){ return $c['path']==='/ppp/profile'; }),'Legacy deletion still removes external profiles');
    $owner=new OwnershipTestMT();
    $foreign=['.id'=>'*foreign','comment'=>'administrator'];
    $owner->recording->answers=[[$foreign],[$foreign]];
    expectRouterError(function() use($owner,$profile){ $owner->execute($profile); },'A foreign profile with a reserved name was overwritten');
    checkRouter(!in_array('/ppp/profile/set',$owner->recording->words,true),'Foreign profile guard ran after mutation');
    $owned=['.id'=>'*owned','name'=>$profile['name'],'comment'=>$profile['_owner']];
    $owner->recording->words=[];
    $owner->recording->answers=[[$owned],[$owned],[]];
    $owner->execute($profile);
    checkRouter(in_array('/ppp/profile/set',$owner->recording->words,true)
        && !array_filter($owner->recording->words,static function($w){ return strpos($w,'=_owner=')===0; }),'Managed profile update lost ownership or leaked internal command metadata');

    // A request-scoped reassociation must reread the account, update in place,
    // reject drift, and never authorize a later deletion or unrelated invocation.
    $relink=['path'=>'/ppp/secret','name'=>'fixture-relink','comment'=>'71 - Fixture - 501',
        '_service_id'=>501,'_client_id'=>71,'remote-address'=>'198.51.100.2'];
    $legacyRow=['.id'=>'*A1','name'=>'fixture-relink','comment'=>'71 - Fixture - 499'];
    $writer=new OwnershipTestMT(); $checks=0;
    $resolver=function(array $command,array $row) use($relink,$legacyRow,&$checks): bool {
        $checks++; return $command===$relink && $row===$legacyRow;
    };
    $writer->recording->answers=[[$legacyRow],[$legacyRow],[]];
    $writer->withOwnershipResolver($resolver,fn()=>$writer->execute($relink));
    checkRouter($checks===2 && in_array('/ppp/secret/set',$writer->recording->words,true)
        && in_array('=.id=*A1',$writer->recording->words,true),'Verified reassociation did not reread and set the same account');
    $writer->recording->words=[]; $writer->recording->answers=[[$legacyRow]];
    expectRouterError(fn()=>$writer->execute($relink),'Ownership permission escaped its request scope');
    checkRouter(!in_array('/ppp/secret/set',$writer->recording->words,true),'Expired ownership permission mutated an account');
    $drift=array_replace($legacyRow,['.id'=>'*A2']);
    $writer->recording->words=[]; $writer->recording->answers=[[$legacyRow],[$drift]];
    expectRouterError(fn()=>$writer->withOwnershipResolver($resolver,fn()=>$writer->execute($relink)),'Changed account accepted stale reassociation proof');
    checkRouter(!in_array('/ppp/secret/set',$writer->recording->words,true),'Reassociation drift was detected after writing');
    $writer->recording->words=[]; $writer->recording->answers=[[$legacyRow]]; $checks=0;
    expectRouterError(fn()=>$writer->withOwnershipResolver($resolver,fn()=>$writer->execute($relink+['action'=>'remove'])),
        'Reassociation permission authorized deletion of another service account');
    checkRouter($checks===0 && !in_array('/ppp/secret/remove',$writer->recording->words,true),'Deletion invoked the update-only resolver');

    // A failed edit must keep the old applied address and the new prepared address reserved.
    mkdir($temp . '/data');
    chdir($temp);
    require_once __DIR__ . '/../src/lib/api_job_queue.php';
    $heldQueue = myJobQueue();
    $oldHeld = ['id' => 91, 'device' => 1, 'username' => 'held', 'address' => '10.7.0.1', 'address6' => 'fd07::/64'];
    $addresses->set(91, $oldHeld['address']);
    $addresses->set(91, $oldHeld['address6'], true);
    $oldKey = $heldQueue->enqueue($oldHeld);
    $heldQueue->run([$oldKey], function ($snapshot, $operation, $job, $queue): bool {
        $snapshot['_commands'] = [['path' => '/ppp/secret', 'name' => 'held',
            'remote-address' => $snapshot['address'], 'remote-ipv6-prefix' => $snapshot['address6']]];
        $queue->freezePrepared($job, $snapshot);
        return true;
    });
    $newHeld = array_replace($oldHeld, ['address' => '10.7.0.2', 'address6' => 'fd07:0:0:1::/64']);
    $newKey = $heldQueue->enqueue($newHeld);
    $heldQueue->run([$newKey], function ($snapshot, $operation, $job, $queue) use ($addresses): bool {
        $addresses->set(91, $snapshot['address']);
        $addresses->set(91, $snapshot['address6'], true);
        $snapshot['_commands'] = [['path' => '/ppp/secret', 'name' => 'held',
            'remote-address' => $snapshot['address'], 'remote-ipv6-prefix' => $snapshot['address6']]];
        $queue->freezePrepared($job, $snapshot);
        return false;
    });
    $heldAllocator = new ApiIP();
    $heldDevice = (object)['pool' => '10.7.0.0/29', 'pool6' => 'fd07::/62', 'pfxLength' => 64];
    checkRouter($heldAllocator->assign(92, $heldDevice) === '10.7.0.3', 'A failed edit released its old or prepared IPv4 address');
    checkRouter($heldAllocator->assign(92, $heldDevice, true) === 'fd07:0:0:2::/64', 'A failed edit released its old or prepared IPv6 prefix');
    $heldQueue->delete([$newKey]);
    $afterCancellation = new ApiIP();
    checkRouter($afterCancellation->assign(93, $heldDevice) === '10.7.0.4', 'Cancelling a partial operation released router-owned addresses');

    echo "Router/network regression checks passed.\n";
} finally {
    if ($heldQueue) $heldQueue->close();
    chdir($originalDirectory);
    $apiSqlite->close();
    $apiSqliteCache->close();
    @unlink($mainFile);
    @unlink($cacheFile);
    @unlink($temp . '/data/jobs.db');
    @rmdir($temp . '/data');
    @rmdir($temp);
}
