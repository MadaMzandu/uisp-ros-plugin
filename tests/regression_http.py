"""Exercise the real packaged UISP SDK using only isolated files and loopback HTTP."""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


ROOT = Path(__file__).resolve().parents[1]
APP_KEY = "REGRESSION_DUMMY_APP_KEY"
NMS_TOKEN = "REGRESSION_DUMMY_NMS_TOKEN"
SESSION = "REGRESSION-DUMMY-SESSION"


def check(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def copy_owned_tree(source: Path, destination: Path) -> None:
    """Never copy a link or a path escaping the selected source directory."""
    source = source.resolve(strict=True)
    destination.mkdir()
    for path in source.rglob("*"):
        check(not path.is_symlink(), f"Unexpected symbolic link in test input: {path.name}")
        relative = path.resolve(strict=True).relative_to(source)
        target = destination / relative
        if path.is_dir():
            target.mkdir(exist_ok=True)
        elif path.is_file():
            shutil.copy2(path, target)


PROBE = r'''<?php
set_include_path(__DIR__ . '/lib' . PATH_SEPARATOR . get_include_path());
require_once 'lib/api_common.php';
require_once 'lib/api_ucrm.php';
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
function checkHttp($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$_COOKIE = ['PHPSESSID'=>'REGRESSION-DUMMY-SESSION','nms-crm-php-session-id'=>'REGRESSION-DUMMY-SESSION',
    'nms-session'=>'REGRESSION-DUMMY-SESSION'];
$mode = $argv[1];
if ($mode === 'configuration') {
    $configuration = [];
    foreach (['crm'=>ApiUispClient::crm(), 'network'=>ApiUispClient::network('REGRESSION_DUMMY_NMS_TOKEN'),
        'security'=>ApiUispClient::security()] as $name=>$sdk) {
        $property = new ReflectionProperty($sdk, 'client');
        if (PHP_VERSION_ID < 80100) $property->setAccessible(true);
        $client = $property->getValue($sdk);
        checkHttp($client instanceof GuzzleHttp\Client, 'The test is not exercising a real Guzzle client');
        $configuration[$name] = ['timeout'=>$client->getConfig('timeout'),
            'connect_timeout'=>$client->getConfig('connect_timeout'), 'read_timeout'=>$client->getConfig('read_timeout'),
            'allow_redirects'=>$client->getConfig('allow_redirects'), 'verify'=>$client->getConfig('verify')];
        checkHttp($configuration[$name]['timeout']===15.0 && $configuration[$name]['connect_timeout']===5.0
            && $configuration[$name]['read_timeout']===15.0 && $configuration[$name]['allow_redirects']===false,
            'The active SDK client lost its bounded authenticated transport policy');
    }
    $versions=[];
    foreach (['guzzlehttp/guzzle','guzzlehttp/psr7','ubnt/ucrm-plugin-sdk'] as $package) {
        $versions[$package] = Composer\InstalledVersions::getPrettyVersion($package);
    }
    echo json_encode(['configuration'=>$configuration,'versions'=>$versions]), PHP_EOL;
} elseif ($mode === 'redirect') {
    $blocked=[];
    foreach (['crm'=>fn()=>(new ApiUcrm())->get('redirect'),
        'network'=>fn()=>(new ApiUcrm(null,false,true))->get('redirect'),
        'security'=>fn()=>ApiUispClient::security()->getUser()] as $name=>$request) {
        $exception=null;
        try { $request(); } catch (Throwable $error) { $exception=$error; }
        checkHttp($exception !== null, 'An authenticated API redirect was accepted: ' . $name);
        $redirectGuard=false;
        for ($error=$exception; $error !== null; $error=$error->getPrevious()) {
            if ($error->getMessage()==='UISP API redirects are not allowed') $redirectGuard=true;
        }
        checkHttp($redirectGuard, 'The request failed for a reason unrelated to redirect protection: ' . $name);
        $blocked[]=$name;
    }
    echo json_encode(['blocked'=>$blocked]), PHP_EOL;
} elseif ($mode === 'rejected') {
    $exception=null;
    try { (new ApiUcrm())->patch('rejected', ['attributes'=>[['customAttributeId'=>1,'value'=>'PRIVATE-REQUEST-IP']]]); }
    catch (Throwable $error) { $exception=$error; }
    checkHttp($exception instanceof RuntimeException && $exception->getPrevious() instanceof GuzzleHttp\Exception\ClientException,
        'A real UISP HTTP rejection did not preserve its exception chain');
    $log=(string)file_get_contents('data/plugin.log');
    checkHttp(strpos($log,'"http_status":422')!==false && strpos($log,'GuzzleHttp\\\\Exception\\\\ClientException')!==false,
        'The safe request log did not identify the actual HTTP rejection');
    foreach (['PRIVATE-REQUEST-IP','PRIVATE-REJECTED-BODY','REGRESSION_DUMMY_APP_KEY'] as $private)
        checkHttp(strpos($log,$private)===false,'The HTTP diagnostic leaked a request, response or credential');
    echo json_encode(['http_status'=>422,'private_data_logged'=>false]), PHP_EOL;
} elseif ($mode === 'timeout') {
    $started=microtime(true); $exception=null;
    $api=new ApiUcrm();
    try { $api->get('hang'); } catch (Throwable $error) { $exception=$error; }
    $elapsed=microtime(true)-$started;
    checkHttp($exception instanceof RuntimeException && $exception->getMessage()==='UISP request failed: get hang',
        'A bounded API failure did not propagate through the real plugin wrapper');
    $curlTimeout=false;
    for ($error=$exception; $error !== null; $error=$error->getPrevious()) {
        if (method_exists($error,'getHandlerContext') && ($error->getHandlerContext()['errno'] ?? null)===28) $curlTimeout=true;
    }
    checkHttp($curlTimeout && $elapsed>=14.0 && $elapsed<=17.0, 'The transport did not time out at its request deadline');
    $crm=$api->get('normal');
    $network=(new ApiUcrm(null,false,true))->get('normal');
    $user=ApiUispClient::security()->getUser();
    checkHttp(is_object($crm) && $crm->ok===true && $crm->fixture==='http-regression', 'CRM did not recover after timeout');
    checkHttp(is_object($network) && $network->ok===true && $network->fixture==='http-regression', 'NMS response decoding changed');
    checkHttp($user !== null && $user->userId===7 && $user->isClient===false
        && ($user->permissions['system/plugins'] ?? null)==='edit', 'The real authentication SDK response changed');
    echo json_encode(['curl_errno'=>28,'elapsed'=>round($elapsed,3),'recovered'=>['crm','network','security']]), PHP_EOL;
} else { throw new RuntimeException('Unknown isolated probe mode'); }
'''


PUBLIC_PROBE = r'''<?php
$_SERVER=['REQUEST_METHOD'=>'GET','REMOTE_ADDR'=>'127.0.0.1'];
$_GET=['download'=>'backup-0'];
$_COOKIE=['PHPSESSID'=>'REGRESSION-DUMMY-SESSION','nms-crm-php-session-id'=>'REGRESSION-DUMMY-SESSION',
    'nms-session'=>'REGRESSION-DUMMY-SESSION'];
register_shutdown_function(function(){ echo "\nHTTP=" . http_response_code(); });
require __DIR__ . '/public.php';
'''


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php", required=True, type=Path, help="PHP executable with the curl extension")
    args = parser.parse_args()
    php = args.php.resolve(strict=True)
    received: list[dict[str, str]] = []
    source_requests: list[dict[str, str]] = []
    hang_entered = threading.Event()
    hang_release = threading.Event()

    class Sink(BaseHTTPRequestHandler):
        def do_GET(self) -> None:
            received.append({key.lower(): value for key, value in self.headers.items()
                             if key.lower() in {"x-auth-app-key", "x-auth-token", "cookie"}})
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", "2")
            self.end_headers()
            self.wfile.write(b"[]")

        def log_message(self, *unused: object) -> None:
            pass

    sink = ThreadingHTTPServer(("127.0.0.1", 0), Sink)

    class Source(BaseHTTPRequestHandler):
        def do_PATCH(self) -> None:
            check(self.path.endswith('/rejected'), 'Unexpected write in isolated HTTP diagnostic test')
            self.rfile.read(int(self.headers.get('Content-Length', '0')))
            encoded = json.dumps({'message': 'PRIVATE-REJECTED-BODY'}).encode()
            self.send_response(422)
            self.send_header('Content-Type', 'application/json')
            self.send_header('Content-Length', str(len(encoded)))
            self.end_headers()
            self.wfile.write(encoded)

        def do_GET(self) -> None:
            source_requests.append({"path": self.path, **{key.lower(): value for key, value in self.headers.items()
                                                        if key.lower() in {"x-auth-app-key", "x-auth-token", "cookie"}}})
            if self.path.endswith("/hang"):
                hang_entered.set()
                hang_release.wait(25)
                return
            if self.path.endswith("/redirect") or (self.path.endswith("/current-user") and source.redirect_auth):
                self.send_response(302)
                self.send_header("Location", f"http://127.0.0.1:{sink.server_port}/sink")
                self.send_header("Content-Length", "0")
                self.end_headers()
                return
            if self.path.endswith("/current-user"):
                body = {"userId": 7, "username": "http-regression", "isClient": False,
                        "clientId": None, "userGroup": "fixture", "permissions": {"system/plugins": "edit"}, "locale": "es"}
            else:
                body = {"ok": True, "fixture": "http-regression"}
            encoded = json.dumps(body).encode()
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(encoded)))
            self.end_headers()
            self.wfile.write(encoded)

        def log_message(self, *unused: object) -> None:
            pass

    source = ThreadingHTTPServer(("127.0.0.1", 0), Source)
    source.redirect_auth = True
    for server in (sink, source):
        threading.Thread(target=server.serve_forever, daemon=True).start()
    try:
        with tempfile.TemporaryDirectory(prefix="uisp-http-regression-") as directory:
            isolated = Path(directory)
            for tree in ("vendor", "lib"):
                copy_owned_tree(ROOT / "src" / tree, isolated / tree)
            for filename in ("public.php", "manifest.json"):
                shutil.copy2(ROOT / "src" / filename, isolated / filename)
            (isolated / "data").mkdir()
            base_url = f"http://127.0.0.1:{source.server_port}"
            (isolated / "ucrm.json").write_text(json.dumps({"ucrmLocalUrl": base_url + "/crm", "ucrmPublicUrl": base_url + "/crm",
                                                          "unmsLocalUrl": base_url, "pluginAppKey": APP_KEY}), encoding="utf-8")
            (isolated / "data/config.json").write_text(json.dumps({"unmsToken": NMS_TOKEN}), encoding="utf-8")
            (isolated / "probe.php").write_text(PROBE, encoding="utf-8")
            (isolated / "public-probe.php").write_text(PUBLIC_PROBE, encoding="utf-8")
            command = [str(php), "-n", "-d", f"extension_dir={php.parent / 'ext'}", "-d", "extension=curl"]

            def run(script: str, *arguments: str, timeout: float = 10) -> str:
                result = subprocess.run(command + [str(isolated / script), *arguments], cwd=isolated,
                                        capture_output=True, text=True, timeout=timeout)
                check(result.returncode == 0 and not result.stderr,
                      f"The real SDK probe failed ({script}, {arguments}): {result.stdout} {result.stderr}")
                return result.stdout

            configured = json.loads(run("probe.php", "configuration"))
            locked = {item["name"]: item["version"] for item in json.loads((ROOT / "src/composer.lock").read_text())["packages"]}
            check(all(version == locked[name] for name, version in configured["versions"].items()),
                  "The real SDK fixture is not using the versions in the current lock")
            check(all(options["verify"] is True for options in configured["configuration"].values()),
                  "Configuring the bounded transport changed default certificate verification")
            blocked = json.loads(run("probe.php", "redirect"))
            check(blocked["blocked"] == ["crm", "network", "security"], "Not every authenticated client rejected redirects")
            public_output = run("public-probe.php")
            response_text, status = public_output.rsplit("\nHTTP=", 1)
            public_response = json.loads(response_text)
            check(status == "403" and public_response["error"] is True and public_response["message"] == "unauthorized",
                  "The public authentication path accepted a redirected session lookup")
            check(not (isolated / "data/data.db").exists(), "Failed redirected authentication initialized plugin data")
            check(received == [], "An authenticated redirect contacted another origin and could expose credentials")
            crm_requests = [request for request in source_requests if request["path"].endswith("/api/v1.0/redirect")]
            nms_requests = [request for request in source_requests if request["path"].endswith("/api/v2.1/redirect")]
            auth_requests = [request for request in source_requests if request["path"].endswith("/current-user")]
            check(crm_requests and crm_requests[0].get("x-auth-app-key") == APP_KEY,
                  "The CRM redirect test did not exercise an authenticated SDK request")
            check(nms_requests and nms_requests[0].get("x-auth-token") == NMS_TOKEN,
                  "The NMS redirect test did not exercise an authenticated SDK request")
            check(len(auth_requests) >= 2 and all(SESSION in request.get("cookie", "") for request in auth_requests),
                  "The security/public redirect tests did not exercise the dummy session cookies")
            source.redirect_auth = False
            rejected = json.loads(run('probe.php', 'rejected'))
            check(rejected == {'http_status': 422, 'private_data_logged': False},
                  'The real SDK rejection log lost the HTTP status or retained sensitive content')
            started = time.monotonic()
            deadline = json.loads(run("probe.php", "timeout", timeout=19))
            total = time.monotonic() - started
            check(hang_entered.is_set() and deadline["curl_errno"] == 28 and total <= 17,
                  "The hanging server did not produce a bounded cURL timeout followed by recovery")
            check(received == [], "A protected request later reached the credential sink")
            print("HTTP regression checks passed: real locked SDK versions; CRM/NMS/security timeout=15s, connect=5s; "
                  "cross-origin API/session redirects blocked; public authentication rejects redirected sessions; "
                  "safe HTTP rejection status without request/response bodies; "
                  f"cURL timeout 28 after {deadline['elapsed']:.3f}s; subsequent CRM/NMS/authentication requests recover.")
    finally:
        hang_release.set()
        for server in (source, sink):
            server.shutdown()
            server.server_close()


if __name__ == "__main__":
    main()
