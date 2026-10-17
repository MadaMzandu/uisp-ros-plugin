"""Authenticated workflow routes against disposable SQLite files and fake CRM only."""
from __future__ import annotations

import argparse
from contextlib import closing
import gc
import json
from pathlib import Path
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
from urllib.error import HTTPError
from urllib.request import Request, urlopen

from regression_operations_http import ROOT, SDK, check, populate


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', type=Path, required=True)
    parser.add_argument('--extension-dir', type=Path)
    args = parser.parse_args()
    php = args.php.resolve(strict=True)
    extension = (args.extension_dir or php.parent / 'ext').resolve(strict=True)
    command = [str(php), '-n', '-d', f'extension_dir={extension}', '-d', 'extension=sqlite3',
               '-d', 'extension=gmp', '-d', 'extension=zip']
    with tempfile.TemporaryDirectory(prefix='uisp-workflow-http-') as temporary:
        directory = Path(temporary)
        populate(directory)
        gc.collect()
        shutil.rmtree(directory / 'vendor')
        shutil.copytree(ROOT / 'src/vendor', directory / 'vendor')
        for name in ('api_setup.php', 'api_backup.php'):
            shutil.copy2(ROOT / 'src/lib' / name, directory / 'lib' / name)
        setup = (directory / 'lib/api_setup.php').read_text(encoding='utf-8')
        setup = setup.replace('function run_setup(){ $setup = new ApiSetup(); $setup->run(); }',
                              "function run_setup(){ file_put_contents('setup-called','unexpected'); }")
        (directory / 'lib/api_setup.php').write_text(setup, encoding='utf-8')
        sdk = SDK.replace("if ($path==='custom-attributes')", """if ($path==='clients/services/9807') throw new \\GuzzleHttp\\Exception\\ClientException('PRIVATE-404',
                    new \\GuzzleHttp\\Psr7\\Request('GET','https://fixture.invalid'),new \\GuzzleHttp\\Psr7\\Response(404));
                if ($path==='clients/services') return [];
                if ($path==='clients/services/771') return ['id'=>771,'clientId'=>17,'status'=>2,'servicePlanId'=>9,'attributes'=>[]];
                if ($path==='custom-attributes')""")
        sdk = sdk.replace("'lastName'=>'Customer'];", "'lastName'=>'Customer','isArchived'=>false];")
        # Bounded CRM readers inspect the SDK transport while this override still
        # answers every GET locally; its HTTP handler must never be invoked.
        sdk = sdk.replace("return new class {\n            public function get($path", "return new class {\n            protected $client;\n            public function __construct() { $this->client=new \\GuzzleHttp\\Client(['handler'=>function () { throw new \\RuntimeException('Fixture network prohibited'); }]); }\n            public function get($path")
        (directory / 'lib/api_uisp_client.php').write_text(sdk, encoding='utf-8')
        (directory / 'initialize.php').write_text("""<?php
set_include_path(__DIR__.'/lib'.PATH_SEPARATOR.get_include_path());
require 'lib/api_backup.php';
$db=new ApiSqlite('data/data.db');$db->saveConfig(['version'=>MY_VERSION]);$db->close();
$q=new ApiJobQueue('data/jobs.db',null);$q->close();
""", encoding='utf-8')
        initialized = subprocess.run(command + [str(directory / 'initialize.php')], cwd=directory,
                                     capture_output=True, text=True, timeout=15)
        check(initialized.returncode == 0, 'Could not initialize isolated queue: ' + initialized.stdout + initialized.stderr)
        with closing(sqlite3.connect(directory / 'data/data.db')) as db, db:
            db.execute("INSERT INTO devices(id,name,ip,user,password,pool) VALUES(1,'Before restore','192.0.2.1','fixture','PRIVATE-ROUTER','192.0.2.0/24')")
            db.execute("INSERT INTO devices(id,name,ip,user,password,pool) VALUES(2,'Empty fixture','192.0.2.2','fixture','PRIVATE-ROUTER','198.51.100.0/24')")
            db.execute("INSERT INTO services(id,clientId,device,status) VALUES(9807,29,1,2)")
            db.execute("INSERT INTO network(id,address) VALUES(9807,'192.0.2.50')")
            db.execute("INSERT INTO network(id,address) VALUES(771,'192.0.2.51')")
        with closing(sqlite3.connect(directory / 'data/jobs.db')) as db, db:
            # A pending requester without any applied ownership for the obsolete blocker.
            db.execute("INSERT INTO jobs(service_id,version,operation,status,payload,identity,fingerprint,error,created,updated,next_attempt) VALUES(7279,1,'update','pending',?, 'fixture','fixture',?,1,1,1)",
                       (json.dumps({'id': 7279, 'clientId': 48781}), 'network_address_conflict: requested_service=7279 blocking_service=9807 source=reserved family=IPv4'))
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            port = listener.getsockname()[1]
        base = f'http://127.0.0.1:{port}'
        (directory / 'ucrm.json').write_text(json.dumps({'ucrmPublicUrl': base}), encoding='utf-8')
        flags = subprocess.CREATE_NO_WINDOW if hasattr(subprocess, 'CREATE_NO_WINDOW') else 0
        with (directory / 'server.log').open('w', encoding='utf-8') as log:
            server = subprocess.Popen(command + ['-S', f'127.0.0.1:{port}', '-t', str(directory), str(directory / 'router.php')],
                                      cwd=directory, stdout=log, stderr=log, stdin=subprocess.DEVNULL, creationflags=flags)
            try:
                for _ in range(100):
                    if server.poll() is not None:
                        raise RuntimeError('Isolated workflow server failed: ' + (directory / 'server.log').read_text())
                    try:
                        with socket.create_connection(('127.0.0.1', port), timeout=.05):
                            break
                    except OSError:
                        time.sleep(.02)

                def request(body=None, *, role='edit', origin=base, query='', raw=None, content_type='application/json', method='POST'):
                    data = raw if raw is not None else None if body is None else json.dumps(body).encode()
                    headers = {'X-Fixture-Role': role, 'Content-Type': content_type}
                    if origin is not None:
                        headers['Origin'] = origin
                    try:
                        response = urlopen(Request(base + '/public.php' + query, data=data, headers=headers, method=method), timeout=20)
                    except HTTPError as error:
                        response = error
                    with response:
                        payload = response.read()
                        result = json.loads(payload) if 'application/json' in response.headers.get('Content-Type', '') else payload
                        return response.status, result, response.headers

                def operation(target, action='list', values=None, change='list'):
                    return {'target': target, 'action': action, 'data': {} if values is None else values, 'changeType': change}

                reads = [('pool_capacity', 'list', {}), ('service_reconciliation', 'list', {}), ('full_backup', 'list', {})]
                for target, action, values in reads:
                    status, body, headers = request(operation(target, action, values), role='view')
                    check(status == 200 and body['status'] == 'ok', f'Operational workflow read failed: {target}: {status} {body}')
                    check('no-store' in headers.get('Cache-Control', ''), 'Workflow response can be cached')
                    for role in ('none', 'client'):
                        check(request(operation(target), role=role)[0] == 403, 'Unauthorized workflow read accepted')
                    for origin in (None, 'http://other.invalid'):
                        check(request(operation(target), origin=origin)[0] == 403, 'Workflow read accepted a foreign/missing origin')
                writes = [operation('full_backup', 'create', change='update'),
                          operation('service_resolution', 'apply', {'previewToken': 'f' * 64, 'selection': []}, 'update'),
                          operation('device_sync', 'begin', {'deviceId': 1}, 'update'),
                          operation('device_sync', 'step', {'syncToken': 'f' * 64}, 'update'),
                          operation('full_backup', 'apply', {'previewToken': 'f' * 64}, 'update'),
                          operation('sync_preview', 'apply', {'previewToken': 'f' * 64, 'mode': 'differences'}, 'update'),
                          operation('service_lifecycle', 'apply', {'previewToken': 'f' * 64}, 'update'),
                          operation('job_reservation', 'preview', {'serviceIds': [9807]}, 'cleanup')]
                for body in writes:
                    check(request(body, role='view')[0] == 403, 'View role obtained workflow mutation permission')
                    check(request(body, origin='http://other.invalid')[0] == 403, 'Foreign-origin workflow write accepted')
                for body in [operation('pool_capacity', values={'write': True}),
                             operation('service_reconciliation', change='update'),
                             operation('sync_preview', 'begin', {'deviceId': '1'}),
                             operation('device_sync', 'begin', {'deviceId': 1}),
                             operation('device_sync', 'begin', {'deviceId': '1'}, 'update'),
                             operation('device_sync', 'begin', {'deviceId': 1, 'force': True}, 'update'),
                             operation('device_sync', 'step', {'syncToken': 1}, 'update'),
                             operation('sync_preview', 'apply', {'previewToken': 'f'*64, 'mode': 'anything'}, 'update'),
                             operation('full_backup', 'preview', {'name': '../../config.json'}, 'update'),
                             operation('service_lifecycle', 'preview', {'serviceId': '771'}),
                             operation('service_lifecycle', 'preview', {'serviceId': 771, 'force': True}),
                             operation('service_resolution', 'preview', {'serviceId': '771'}),
                             operation('service_resolution', 'preview', {'serviceId': 771, 'force': True}),
                             operation('service_resolution', 'apply', {'previewToken': 'f' * 64, 'selection': []}),
                             operation('service_resolution', 'apply', {'previewToken': 'f' * 64, 'selection': [1]}, 'update'),
                             operation('job_reservation', 'preview', {'serviceIds': [48781]}, 'cleanup')]:
                    check(request(body)[0] == 400, 'Invalid workflow request accepted: ' + str(body))
                check(not (directory / 'setup-called').exists() and not (directory / 'cache-sync-called').exists(),
                      'Read/validation workflows entered normal provisioning bootstrap')

                status, body, _ = request(operation('device_sync', 'begin', {'deviceId': 2}, 'update'))
                check(status == 200 and body['status'] == 'ok' and body['data']['phase'] == 'catalog',
                      'Immediate device synchronization could not start: ' + str(body))
                sync_token = body['data']['syncToken']
                status, body, _ = request(operation('device_sync', 'step', {'syncToken': sync_token}, 'update'))
                check(status == 200 and body['data']['done'] and body['data']['requested'] == 0,
                      'An empty router did not finish without running unrelated services: ' + str(body))
                check('PRIVATE' not in json.dumps(body), 'Synchronization exposed fixture credentials')
                status, body, _ = request(operation('device_sync', 'begin', {'deviceId': 999}, 'update'))
                check(status == 503 and body['message'] == 'device_sync_router_unavailable' and 'PRIVATE' not in json.dumps(body),
                      'A known synchronization failure lost its safe actionable code: ' + str(body))

                status, body, _ = request(operation('service_lifecycle', 'preview', {'serviceId': 42}), role='view')
                check(status == 200 and body['status'] == 'ok' and body['data']['state'] == 'active' and not body['data']['eligible'],
                      'A live service was eligible for retirement: ' + str(body))
                status, body, _ = request(operation('service_lifecycle', 'preview', {'serviceId': 999}), role='view')
                check(status == 200 and body['data']['state'] == 'unknown' and not body['data']['eligible']
                      and 'PRIVATE' not in json.dumps(body), 'Unavailable CRM details leaked through lifecycle preview: ' + str(body))
                for role in ('none', 'client'):
                    check(request(operation('service_lifecycle', 'preview', {'serviceId': 771}), role=role)[0] == 403,
                          'Unauthorized lifecycle preview was accepted')
                status, body, _ = request(operation('service_lifecycle', 'preview', {'serviceId': 771}), role='view')
                check(status == 200 and body['data']['eligible'] and body['data']['previewToken'],
                      'An ended service with only an old local reservation could not be reviewed: ' + str(body))
                lifecycle_token = body['data']['previewToken']
                with closing(sqlite3.connect(directory / 'data/jobs.db')) as db, db:
                    running = db.execute("INSERT INTO jobs(service_id,version,operation,status,payload,identity,fingerprint,created,updated,next_attempt) VALUES(771,1,'update','running',?,'fixture-running','fixture-running',1,1,1)",
                                         (json.dumps({'id': 771, 'clientId': 17}),)).lastrowid
                status, body, _ = request(operation('service_lifecycle', 'apply', {'previewToken': lifecycle_token}, 'update'))
                check(status == 409 and body['error'] and body['message'] == 'lifecycle_service_busy'
                      and 'PRIVATE' not in json.dumps(body), 'A running service lost its actionable retirement rejection: ' + str(body))
                status, body, _ = request(operation('service_lifecycle', 'apply', {'previewToken': lifecycle_token}, 'update'))
                check(status == 409 and body['message'] == 'lifecycle_invalid_token', 'A rejected retirement proof was reusable: ' + str(body))
                with closing(sqlite3.connect(directory / 'data/data.db')) as db:
                    check(db.execute('SELECT address FROM network WHERE id=771').fetchone() == ('192.0.2.51',),
                          'A busy retirement released the old reservation')
                with closing(sqlite3.connect(directory / 'data/jobs.db')) as db, db:
                    check(db.execute('SELECT status,payload FROM jobs WHERE id=?', (running,)).fetchone()
                          == ('running', json.dumps({'id': 771, 'clientId': 17})), 'A busy retirement rewrote the running job')
                    db.execute('DELETE FROM jobs WHERE id=?', (running,))
                status, body, _ = request(operation('service_lifecycle', 'preview', {'serviceId': 771}), role='view')
                check(status == 200 and body['data']['eligible'] and body['data']['previewToken'] != lifecycle_token,
                      'A stopped running job did not permit a fresh explicit review: ' + str(body))
                lifecycle_token = body['data']['previewToken']
                with closing(sqlite3.connect(directory / 'data/jobs.db')) as db, db:
                    db.execute("CREATE TRIGGER fixture_private_retirement BEFORE INSERT ON service_event_locks BEGIN SELECT RAISE(ABORT,'PRIVATE-QUEUE-ERROR'); END")
                status, body, _ = request(operation('service_lifecycle', 'apply', {'previewToken': lifecycle_token}, 'update'))
                check(status == 500 and body['message'] == 'operation_failed_check_log' and 'PRIVATE' not in json.dumps(body),
                      'An unrecognized retirement failure disclosed private storage details: ' + str(body))
                with closing(sqlite3.connect(directory / 'data/jobs.db')) as db, db:
                    db.execute('DROP TRIGGER fixture_private_retirement')
                status, body, _ = request(operation('service_lifecycle', 'apply', {'previewToken': lifecycle_token}, 'update'))
                check(status == 409 and body['message'] == 'lifecycle_invalid_token', 'An uncertain retirement proof was reusable: ' + str(body))
                status, body, _ = request(operation('service_lifecycle', 'preview', {'serviceId': 771}), role='view')
                check(status == 200 and body['data']['eligible'] and body['data']['previewToken'] != lifecycle_token,
                      'A fresh retirement review could not recover after an uncertain write: ' + str(body))
                lifecycle_token = body['data']['previewToken']
                status, body, _ = request(operation('service_lifecycle', 'apply', {'previewToken': lifecycle_token}, 'update'))
                check(status == 200 and body['data']['retired'] and body['data']['released'],
                      'The reviewed ended reservation was not released: ' + str(body))
                status, body, _ = request(operation('service_lifecycle', 'apply', {'previewToken': lifecycle_token}, 'update'))
                check(status == 409 and body['message'] == 'lifecycle_invalid_token', 'Lifecycle preview authorization was reusable: ' + str(body))
                with closing(sqlite3.connect(directory / 'data/data.db')) as db:
                    check(db.execute('SELECT address FROM network WHERE id=771').fetchone() in (None, (None,)), 'Ended local reservation stayed occupied')
                    check(db.execute('SELECT address FROM network WHERE id=9807').fetchone() == ('192.0.2.50',),
                          'Retiring the requester changed the unrelated protected reservation')

                status, body, _ = request(operation('job_reservation', 'preview', {'serviceIds': [9807]}, 'cleanup'))
                check(status == 200 and body['status'] == 'ok' and len(body['data']['candidates']) == 1,
                      'A missing blocker could not enter deliberate cleanup: ' + str(body))
                token = body['data']['previewToken']
                status, body, _ = request(operation('job_reservation', 'apply', {'serviceIds': [9807], 'previewToken': token}, 'cleanup'))
                check(status == 200 and body['data']['removed_service_ids'] == [9807], 'Reviewed orphan reservation was not retired: ' + str(body))
                check('recovery' not in body, 'A job-only reservation review forced the whole panel into recovery')
                check(len(list((directory / 'data').glob('network-cleanup-*'))) > 0, 'Orphan retirement omitted its backup')

                status, body, _ = request(operation('full_backup', 'create', change='update'))
                check(status == 200 and body['status'] == 'ok', 'Full backup create failed: ' + str(body))
                name = body['data']['name']
                status, archive, headers = request(None, method='GET', query='?download=' + name)
                check(status == 200 and archive.startswith(b'PK') and 'application/zip' in headers.get('Content-Type', ''), 'Saved full backup download failed')
                check(request(None, role='view', method='GET', query='?download=' + name)[0] == 403, 'View role downloaded credentials from full backup')
                with closing(sqlite3.connect(directory / 'data/data.db')) as db, db:
                    db.execute("UPDATE devices SET name='Changed' WHERE id=1")
                status, body, _ = request(operation('full_backup', 'preview', {'name': name}, 'update'))
                check(status == 200 and body['data']['candidate']['devices'] == 2 and 'PRIVATE' not in json.dumps(body), 'Full backup preview failed or leaked credentials')
                token = body['data']['previewToken']
                status, body, _ = request(operation('full_backup', 'apply', {'previewToken': token}, 'update'))
                check(status == 200 and body['data']['requiresReload'], 'Full restore failed: ' + str(body))
                check(request(operation('full_backup', 'apply', {'previewToken': token}, 'update'))[0] == 400, 'HTTP restore token was reusable')
                with closing(sqlite3.connect(directory / 'data/data.db')) as db, db:
                    check(db.execute('SELECT name FROM devices WHERE id=1').fetchone()[0] == 'Before restore', 'HTTP restore left replaced state')

                status, body, _ = request(operation('service_resolution', 'preview', {'serviceId': 771}), role='view')
                check(status == 200 and body['data']['mode'] == 'blocked',
                      'Service resolution preview did not preserve read permission or its terminal-service block: ' + str(body))
                check('PRIVATE' not in json.dumps(body), 'Resolution preview exposed stored credentials')
                for role in ('none', 'client'):
                    check(request(operation('service_resolution', 'preview', {'serviceId': 771}), role=role)[0] == 403,
                          'Unauthorized service resolution read accepted')
                check(request(operation('service_resolution', 'apply', {'previewToken': 'f' * 64, 'selection': []}, 'update'))[0] == 400,
                      'Unknown service resolution token was accepted')

                boundary = 'WORKFLOW-FIXTURE-BOUNDARY'
                upload = (f'--{boundary}\r\nContent-Disposition: form-data; name="workflow"\r\n\r\nfull_backup\r\n'
                          f'--{boundary}\r\nContent-Disposition: form-data; name="fullBackup"; filename="backup.zip"\r\nContent-Type: application/zip\r\n\r\n').encode() + archive + f'\r\n--{boundary}--\r\n'.encode()
                check(request(raw=upload, content_type='multipart/form-data; boundary=' + boundary, role='view')[0] == 403, 'View role uploaded a full restore')
                status, body, _ = request(raw=upload, content_type='multipart/form-data; boundary=' + boundary)
                check(status == 200 and body['data']['previewToken'] and not body['data']['routerChanges'], 'Multipart full backup did not stop at preview: ' + str(body))
                check(not (directory / 'setup-called').exists() and not (directory / 'cache-sync-called').exists(), 'Workflow routes executed provisioning bootstrap')
                with closing(sqlite3.connect(directory / 'data/data.db')) as db, db:
                    db.execute('DROP INDEX unique_address')
                    db.executemany('INSERT INTO network(id,address) VALUES(?,?)', [(8801, '192.0.2.123'), (8802, '192.0.2.123')])
                    db.execute("UPDATE config SET value='2.4.38' WHERE key='version'")
                with closing(sqlite3.connect(directory / 'data/jobs.db')) as db:
                    before_jobs = db.execute('SELECT service_id,operation,status,payload FROM jobs ORDER BY id').fetchall()
                status, body, _ = request(operation('device_sync', 'begin', {'deviceId': 2}, 'update'))
                check(status == 400 and body.get('recovery', {}).get('readOnly') is True and body['data']['duplicate_groups'] == 1,
                      'Immediate synchronization bypassed the paused duplicate-address migration: ' + str(body))
                status, body, _ = request(operation('service_resolution', 'apply', {'previewToken': 'f' * 64, 'selection': []}, 'update'))
                check(status == 400 and body.get('recovery', {}).get('readOnly') is True,
                      'Service resolution bypassed the paused duplicate-address migration: ' + str(body))
                with closing(sqlite3.connect(directory / 'data/jobs.db')) as db:
                    check(db.execute('SELECT service_id,operation,status,payload FROM jobs ORDER BY id').fetchall() == before_jobs,
                          'A paused migration initialized synchronization work')
                print('Workflow HTTP passed: authenticated reads, strict writes, origin checks, orphan reservation review with backup, complete restore/download/upload preview and one-use tokens.')
            finally:
                server.terminate()
                try:
                    server.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait(timeout=5)


if __name__ == '__main__':
    main()
