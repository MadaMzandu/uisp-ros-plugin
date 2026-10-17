"""Local panel preview with disposable in-memory data; no UISP or router connections."""
import argparse
import json
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import unquote, urlsplit
import mimetypes
import re

ROOT = Path(__file__).resolve().parents[1]
PANEL = ROOT / 'src/public/panel_v2'
BASE = '/crm/_plugins/ros-plugin/public/panel_v2/'
API = '/crm/_plugins/ros-plugin/public.php'
FIELDS = json.loads((ROOT / 'src/includes/l10n/fields_es.json').read_text(encoding='utf-8'))
CONFIG = json.loads((ROOT / 'src/includes/conf_default.json').read_text(encoding='utf-8'))
CONFIG['plugin_language'] = 'es'
DEVICES = [
    {'id': 1, 'name': 'MikroTik principal', 'ip': '192.0.2.10', 'user': 'operador', 'port': 8728, 'type': 'mikrotik', 'users': 128, 'status': True, 'pool': '10.99.0.0/24', 'pool6': 'fd10:9900::/48', 'pfxLength': 64},
    {'id': 2, 'name': 'MikroTik acceso norte', 'ip': '192.0.2.20', 'user': 'operador', 'port': 8729, 'ssl': 1, 'type': 'mikrotik', 'users': 42, 'status': False, 'pool': '10.99.1.0/24', 'pool6': '', 'pfxLength': 64},
]
PLANS = [
    {'id': 1, 'name': 'Residencial 50 Mbps', 'uploadSpeed': 20, 'downloadSpeed': 50, 'ratio': 1, 'uploadOverride': None, 'downloadOverride': None, 'priorityUpload': 8, 'priorityDownload': 8},
    {'id': 2, 'name': 'Negocios 100 Mbps', 'uploadSpeed': 100, 'downloadSpeed': 100, 'ratio': 2, 'uploadOverride': None, 'downloadOverride': None, 'priorityUpload': 4, 'priorityDownload': 4},
]
JOBS = {'Q1': {'id': 101, 'action': 'update', 'device': 2, 'username': 'cliente-prueba', 'attempts': 2, 'error': 'No se pudo conectar al router. Se reintentará.'}}
BACKUPS = [{'name': 'backup-0', 'date': '2026-10-10T09:00:00-04:00'}]
REQUESTS = []


class Preview(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def send(self, data, content_type='application/json; charset=utf-8', status=200):
        body = json.dumps(data, ensure_ascii=False).encode() if not isinstance(data, bytes) else data
        self.send_response(status)
        self.send_header('Content-Type', content_type)
        self.send_header('Content-Length', str(len(body)))
        self.send_header('Cache-Control', 'no-store')
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        path = unquote(urlsplit(self.path).path)
        if path in ['/', API]:
            markup = (PANEL / 'index.php').read_text(encoding='utf-8')
            if self.server.legacy_panel:
                # Reproduce cached pre-template HTML paired with current JavaScript.
                markup = re.sub(r'<section class="burst-templates"[^>]*>.*?</section>', '', markup, flags=re.S)
                markup = re.sub(r'<div id="notifications"[^>]*></div>', '', markup)
            self.send(markup.encode('utf-8'), 'text/html; charset=utf-8')
        elif path == '/__preview/state':
            self.send({'config': CONFIG, 'plans': PLANS, 'requests': REQUESTS})
        elif path.startswith(BASE):
            target = (PANEL / path[len(BASE):]).resolve()
            if target.is_relative_to(PANEL.resolve()) and target.is_file():
                self.send(target.read_bytes(), mimetypes.guess_type(target.name)[0] or 'application/octet-stream')
            else:
                self.send({'error': True}, status=404)
        else:
            self.send({'error': True}, status=404)

    def do_POST(self):
        if urlsplit(self.path).path != API:
            self.send({'error': True}, status=404)
            return
        try:
            length = int(self.headers.get('Content-Length', '0'))
            if length > 1048576:
                raise ValueError('Payload too large')
            data = json.loads(self.rfile.read(length))
            REQUESTS.append(data)
            target, action, values = data.get('target'), data.get('action'), data.get('data', {})
            result = []
            if data.get('changeType') == 'list':
                locale = values.get('language', 'es')
                if locale not in ['es', 'en', 'pt', 'fr', 'de', 'it', 'af', 'sw']:
                    locale = 'es'
                fields_path = ROOT / f'src/includes/l10n/fields_{locale}.json'
                localized_fields = json.loads(fields_path.read_text(encoding='utf-8')) if fields_path.is_file() else FIELDS
                result = {'devices': DEVICES, 'plans': PLANS, 'config': CONFIG,
                          'messages': [{}, localized_fields], 'jobs': JOBS, 'backups': BACKUPS,
                          'sys': {'app_version': '2.4.9', 'php_version': '8.5.11', 'last_cache': '2026-10-10 09:00', 'next_cache': '2026-10-17 09:00'},
                          'orphans': []}.get(target, [])
            elif target == 'config' and action == 'edit':
                CONFIG.update(values)
            elif action == 'sync_attrs':
                result = {'created': 0, 'message': 'Atributos verificados.'}
            elif target == 'devices' and action == 'edit':
                for device in DEVICES:
                    if device['id'] == values.get('id'):
                        device.update(values)
            elif target == 'plans' and action == 'edit':
                for plan in PLANS:
                    if plan['id'] == values.get('id'):
                        plan.update(values)
            elif target == 'jobs' and action == 'run':
                for key in values:
                    JOBS.pop(key, None)
            self.send({'status': 'ok', 'error': False, 'message': 'ok', 'data': result})
        except (ValueError, TypeError, KeyError):
            self.send({'status': 'failed', 'error': True, 'message': 'invalid_preview_request'}, status=400)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--port', type=int, default=8765)
    parser.add_argument('--legacy-panel', action='store_true', help='Reproduce old HTML without burst controls or notifications')
    parser.add_argument('--legacy-ratio', action='store_true', help='Reproduce a saved zero ratio with contention disabled')
    args = parser.parse_args()
    if args.legacy_ratio:
        PLANS[0]['ratio'] = 0
        CONFIG['disable_contention'] = True
    server = ThreadingHTTPServer(('127.0.0.1', args.port), Preview)
    server.legacy_panel = args.legacy_panel
    print(f'Panel preview: http://127.0.0.1:{server.server_port}{API}?page=panel', flush=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()
