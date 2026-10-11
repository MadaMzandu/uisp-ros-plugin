"""Build an installer from current source and the existing, locked vendor archive."""
import argparse
from datetime import datetime, timedelta
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import zipfile

ROOT = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--vendor-archive', type=Path, default=ROOT / 'src/ros-plugin.zip')
parser.add_argument('--vendor-dir', type=Path, help='Use dependencies installed by Composer instead of the previous archive')
parser.add_argument('--output', type=Path, required=True)
args = parser.parse_args()
source = ROOT / 'src'
manifest = json.loads((source / 'manifest.json').read_text(encoding='utf-8'))
release = manifest.get('information', {}).get('version')
composer_release = json.loads((source / 'composer.json').read_text(encoding='utf-8')).get('version')
setup_source = (source / 'lib/api_setup.php').read_text(encoding='utf-8')
runtime_match = re.search(r"\bconst\s+MY_VERSION\s*=\s*(['\"])([^'\"]+)\1\s*;", setup_source)
runtime_release = runtime_match.group(2) if runtime_match else None
if not isinstance(release, str) or release != composer_release or release != runtime_release:
    raise ValueError('Plugin versions must match in manifest.json, composer.json and MY_VERSION')
# ZIP extractors preserve this timestamp. A universal fixed date made changed
# PHP scripts appear unchanged to OPcache when upgrading the plugin in place.
# Four base-100 components keep each release deterministic and distinct while
# all supported timestamps remain safely in the past and inside ZIP limits.
if not re.fullmatch(r'(?:0|[1-9][0-9]?)\.(?:0|[1-9][0-9]?)\.(?:0|[1-9][0-9]?)(?:\.(?:0|[1-9][0-9]?))?', release):
    raise ValueError('Release must have three or four numeric components from 0 to 99')
components = [int(component) for component in release.split('.')]
components += [0] * (4 - len(components))
serial = 0
for component in components:
    serial = serial * 100 + component
release_timestamp = (datetime(2000, 1, 1) + timedelta(seconds=serial * 2)).timetuple()[:6]
entries = {}

def vendor_test_file(name):
    # Composer distributions may include SDK fixtures even with --no-dev.
    # They are not part of the runtime autoload and can contain dummy API keys.
    return 'tests' in PurePosixPath(name).parts[1:]

if args.vendor_dir is not None:
    vendor = args.vendor_dir.resolve(strict=True)
    for file in vendor.rglob('*'):
        if file.is_symlink():
            raise ValueError('Vendor symlinks are unsupported')
        if file.is_file():
            relative = file.relative_to(vendor)
            if vendor_test_file('vendor/' + relative.as_posix()):
                continue
            mode = 0o100755 if relative.parts[0] == 'bin' else 0o100644
            entries['vendor/' + relative.as_posix()] = (file.read_bytes(), mode << 16)
else:
    with zipfile.ZipFile(args.vendor_archive) as archive:
        for member in archive.infolist():
            path = PurePosixPath(member.filename)
            if '..' in path.parts or path.is_absolute() or '\\' in member.filename:
                raise ValueError('Unsafe archive entry')
            if member.filename.startswith('vendor/') and not member.is_dir():
                if vendor_test_file(member.filename):
                    continue
                entries[member.filename] = (archive.read(member), member.external_attr)
if 'vendor/autoload.php' not in entries:
    raise ValueError('Vendor archive has no autoloader')
installed = json.loads(entries['vendor/composer/installed.json'][0])
installed = installed.get('packages', []) if isinstance(installed, dict) else installed
locked = json.loads((source / 'composer.lock').read_text(encoding='utf-8'))['packages']
versions = {item['name']: item['version'] for item in installed}
for package in locked:
    if versions.get(package['name']) != package['version']:
        raise ValueError('Vendor archive does not match composer.lock: ' + package['name'])
for folder in ['includes', 'lib', 'public']:
    for path in (source / folder).rglob('*'):
        if path.is_file():
            relative = path.relative_to(source)
            if relative.parts[0] == 'public' and len(relative.parts) == 2:
                name = path.name
                if name in ['config.json', 'queue.json', 'data.db', 'cache.db', 'jobs.db'] or name.startswith('backup-'):
                    continue
            entries[relative.as_posix()] = (path.read_bytes(), 0o100644 << 16)
for name in ['composer.json', 'composer.lock', 'manifest.json', 'main.php', 'public.php']:
    entries[name] = ((source / name).read_bytes(), 0o100644 << 16)
output = args.output.resolve()
output.parent.mkdir(parents=True, exist_ok=True)
temporary = output.with_name(output.name + '.tmp')
try:
    with zipfile.ZipFile(temporary, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for name, (contents, attributes) in sorted(entries.items()):
            info = zipfile.ZipInfo(name, date_time=release_timestamp)
            info.create_system = 3
            info.external_attr = attributes
            info.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(info, contents, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    with zipfile.ZipFile(temporary) as archive:
        if archive.testzip() is not None:
            raise ValueError('Generated archive is corrupt')
    os.replace(temporary, output)
finally:
    if temporary.exists():
        temporary.unlink()
print(json.dumps({'output': str(output), 'files': len(entries), 'version': release,
    'archive_timestamp': release_timestamp, 'sha256': hashlib.sha256(output.read_bytes()).hexdigest()}))
