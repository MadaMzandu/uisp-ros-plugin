"""Read-only audit probes against packaged PHP code; uses isolated dummy data."""
import argparse
import json
from pathlib import Path, PurePosixPath
import subprocess
import tempfile
import threading
import time
import zipfile
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ROOT = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--php', required=True, type=Path)
args = parser.parse_args()
received = []
entered = threading.Event()


class Sink(BaseHTTPRequestHandler):
    def do_GET(self):
        received.append({key.lower(): value for key, value in self.headers.items()
                         if key.lower() in ['x-auth-app-key', 'x-auth-token']})
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.end_headers()
        self.wfile.write(b'[]')

    def log_message(self, *unused):
        pass


sink = ThreadingHTTPServer(('127.0.0.1', 0), Sink)


class Source(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path.endswith('/hang'):
            entered.set()
            time.sleep(15)
            return
        self.send_response(302)
        self.send_header('Location', f'http://127.0.0.1:{sink.server_port}/sink')
        self.end_headers()

    def log_message(self, *unused):
        pass


source = ThreadingHTTPServer(('127.0.0.1', 0), Source)
for server in [sink, source]:
    threading.Thread(target=server.serve_forever, daemon=True).start()

try:
    with tempfile.TemporaryDirectory(prefix='uisp-deep-audit-') as folder:
        isolated = Path(folder)
        with zipfile.ZipFile(ROOT / 'src/ros-plugin.zip') as archive:
            for member in archive.infolist():
                if member.is_dir() or not member.filename.startswith(('vendor/', 'lib/')):
                    continue
                name = PurePosixPath(member.filename)
                if name.is_absolute() or '..' in name.parts or '\\' in member.filename:
                    raise ValueError('Unsafe archive member')
                destination = isolated.joinpath(*name.parts)
                destination.parent.mkdir(parents=True, exist_ok=True)
                destination.write_bytes(archive.read(member))
        (isolated / 'data').mkdir()
        url = f'http://127.0.0.1:{source.server_port}'
        (isolated / 'ucrm.json').write_text(json.dumps({
            'ucrmLocalUrl': url, 'unmsLocalUrl': url,
            'pluginAppKey': 'AUDIT_DUMMY_APP_KEY'
        }))
        (isolated / 'data/config.json').write_text('{}')
        probe = isolated / 'probe.php'
        probe.write_text(r'''<?php
set_include_path(__DIR__ . '/lib' . PATH_SEPARATOR . get_include_path());
require 'lib/api_ucrm.php';
if ($argv[1] === 'redirect') {
    $crm = Ubnt\UcrmPluginSdk\Service\UcrmApi::create();
    $property = new ReflectionProperty($crm, 'client');
    $client = $property->getValue($crm);
    echo json_encode(['sdk_config' => ['timeout' => $client->getConfig('timeout'),
        'connect_timeout' => $client->getConfig('connect_timeout'),
        'allow_redirects' => $client->getConfig('allow_redirects')]]), PHP_EOL;
    (new ApiUcrm())->get('redirect');
    (new ApiUcrm('AUDIT_DUMMY_NETWORK_TOKEN', false, true))->get('redirect');
    echo "redirect_requests_completed\n";
} elseif ($argv[1] === 'hang') {
    (new ApiUcrm())->get('hang');
    echo "hang_returned\n";
} else {
    require 'lib/api_setup.php';
    $db = new ApiSqlite(':memory:');
    $db->exec('CREATE TABLE config(key TEXT PRIMARY KEY,value TEXT,last TEXT,created TEXT)');
    $values = ['active_list' => '00123', 'disabled_profile' => '00123',
        'device_name_attr' => '00012', 'pppoe_user_attr' => '0007', 'pppoe_pass_attr' => '0008'];
    $db->saveConfig($values);
    echo json_encode(['config_original' => $values, 'config_loaded' => $db->readConfig()]), PHP_EOL;
    file_put_contents('data/state.json', '{"backup":');
    try {
        $method = new ReflectionMethod(ApiSetup::class, 'state');
        $method->invoke(new ApiSetup());
        echo "corrupt_state_recovered\n";
    } catch (Throwable $error) {
        echo json_encode(['corrupt_state_error' => get_class($error),
            'message' => $error->getMessage()]), PHP_EOL;
    }
}
''', encoding='utf-8')
        command = [str(args.php), '-n', '-d', f'extension_dir={args.php.parent / "ext"}',
                   '-d', 'extension=curl', '-d', 'extension=sqlite3', str(probe)]
        for mode in ['redirect', 'data']:
            result = subprocess.run(command + [mode], cwd=isolated, capture_output=True,
                                    text=True, timeout=10)
            print(json.dumps({'mode': mode, 'exit_code': result.returncode,
                              'stdout': result.stdout, 'stderr': result.stderr}))
        print(json.dumps({'redirect_cross_origin_dummy_headers': received}))
        started = time.monotonic()
        process = subprocess.Popen(command + ['hang'], cwd=isolated, stdout=subprocess.PIPE,
                                   stderr=subprocess.PIPE, text=True)
        try:
            output, errors = process.communicate(timeout=12)
            print(json.dumps({'hang_server_entered': entered.is_set(),
                              'elapsed': round(time.monotonic() - started, 2),
                              'exit_code': process.returncode, 'stdout': output, 'stderr': errors}))
        except subprocess.TimeoutExpired:
            process.kill()
            output, errors = process.communicate()
            print(json.dumps({'hang_server_entered': entered.is_set(),
                              'still_running_after_seconds': round(time.monotonic() - started, 2),
                              'probe_killed_by_audit': True, 'stdout': output, 'stderr': errors}))
finally:
    for server in [source, sink]:
        server.shutdown()
        server.server_close()
