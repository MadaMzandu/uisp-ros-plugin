"""Run isolated regressions and verify a release archive against the current source."""
from __future__ import annotations

import argparse
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path, PurePosixPath
import re
import subprocess
import sys
import time
import zipfile

ROOT = Path(__file__).resolve().parents[1]


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', required=True)
    parser.add_argument('--node', default='node')
    parser.add_argument('--extension-dir')
    parser.add_argument('--archive', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    php = [args.php, '-n']
    if args.extension_dir:
        php += ['-d', 'extension_dir=' + args.extension_dir]
    php_tests = php + ['-d', 'extension=sqlite3', '-d', 'extension=gmp', '-d', 'extension=zip']
    suites = [
        'backend', 'jobs', 'router', 'burst', 'runtime', 'stability', 'client_queue',
        'locale', 'second_audit', 'ownership', 'shared_resources', 'shared_pool',
        'nonfinite', 'profile_names', 'unmanaged', 'reconnect', 'reconnect_router', 'webhook_auth', 'webhook_flow', 'billing_router', 'job_diagnostics', 'job_actions', 'cancelled_recovery', 'archived_plans',
    ]
    commands = [('regression_' + name, php_tests + ['tests/regression_' + name + '.php']) for name in suites]
    commands += [('regression_' + name, [args.node, 'tests/regression_' + name + '.js']) for name in ['panel', 'theme', 'i18n']]
    commands.append(('regression_http', [sys.executable, 'tests/regression_http.py', '--php', args.php]))

    def run(item: tuple[str, list[str]]) -> dict:
        name, command = item
        started = time.monotonic()
        try:
            result = subprocess.run(command, cwd=ROOT, capture_output=True, text=True, encoding='utf-8', errors='replace', timeout=180)
            record = {'name': name, 'exit_code': result.returncode, 'seconds': round(time.monotonic() - started, 2),
                      'output': (result.stdout + result.stderr).strip()}
        except subprocess.TimeoutExpired:
            record = {'name': name, 'exit_code': -1, 'seconds': 180, 'output': 'Timed out in an isolated regression.'}
        print(('PASS' if record['exit_code'] == 0 else 'FAIL') + ' ' + name, flush=True)
        return record

    with ThreadPoolExecutor(max_workers=3) as pool:
        regressions = list(pool.map(run, commands))
        owned_php = sorted(path for path in (ROOT / 'src').rglob('*.php') if 'vendor' not in path.relative_to(ROOT / 'src').parts)
        lint = list(pool.map(run, [('lint:' + str(path.relative_to(ROOT)), php + ['-l', str(path)]) for path in owned_php]))

    report = {'created_utc': datetime.now(timezone.utc).isoformat(), 'regressions': regressions,
              'php_lint_count': len(lint), 'php_lint_failures': [item for item in lint if item['exit_code'] != 0]}
    source = ROOT / 'src'
    manifest = json.loads((source / 'manifest.json').read_text(encoding='utf-8'))
    report['version'] = manifest['information']['version']
    errors = []
    with zipfile.ZipFile(args.archive) as archive:
        names = archive.namelist()
        report['archive_entries'] = len(names)
        if len(names) != len(set(names)):
            errors.append('Duplicate archive entries.')
        for name in names:
            path = PurePosixPath(name)
            if path.is_absolute() or '..' in path.parts or '\\' in name:
                errors.append('Unsafe archive path: ' + name)
            if re.search(r'(^|/)(?:data|tests|tools)(?:/|$)', name) or name in ['ucrm.json', 'unms.json', 'public/config.json', 'public/queue.json']:
                errors.append('Operational/test file in archive: ' + name)
            if name.startswith('vendor/') and not name.endswith('/'):
                file = source / name
                if not file.is_file() or file.read_bytes() != archive.read(name):
                    errors.append('Packaged dependency differs from installed runtime: ' + name)
        owned = [name for name in names if not name.startswith('vendor/') and not name.endswith('/')]
        report['owned_source_entries'] = len(owned)
        for name in owned:
            file = source / name
            if not file.is_file() or file.read_bytes() != archive.read(name):
                errors.append('Archive differs from current source: ' + name)
        for folder in ['includes', 'lib', 'public']:
            for file in (source / folder).rglob('*'):
                if file.is_file() and file.relative_to(source).as_posix() not in names:
                    errors.append('Missing source entry: ' + file.relative_to(source).as_posix())
        installed = json.loads(archive.read('vendor/composer/installed.json'))
        packages = installed.get('packages', []) if isinstance(installed, dict) else installed
        versions = {package['name']: package['version'] for package in packages}
        locked = json.loads((source / 'composer.lock').read_text(encoding='utf-8'))['packages']
        for package in locked:
            if versions.get(package['name']) != package['version']:
                errors.append('Dependency differs from lock: ' + package['name'])
        report['locked_dependencies'] = len(locked)
        if json.loads(archive.read('manifest.json'))['information']['version'] != report['version']:
            errors.append('Archive version mismatch.')
        report['archive_crc_ok'] = archive.testzip() is None
        if not report['archive_crc_ok']:
            errors.append('Archive CRC failed.')
    report['archive_sha256'] = hashlib.sha256(args.archive.read_bytes()).hexdigest()
    report['archive_errors'] = errors
    report['passed'] = not errors and not report['php_lint_failures'] and all(item['exit_code'] == 0 for item in regressions)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, indent=2, ensure_ascii=False) + '\n', encoding='utf-8')
    print(('PASS' if report['passed'] else 'FAIL') + ' release verification: ' + str(args.output), flush=True)
    return 0 if report['passed'] else 1


if __name__ == '__main__':
    raise SystemExit(main())
