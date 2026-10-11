"""Query public advisories for the locked packages; never uploads source or secrets."""
import argparse
from datetime import datetime, timezone
import json
from pathlib import Path
import re
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--output', type=Path, required=True)
args = parser.parse_args()


def version(value):
    match = re.fullmatch(r'v?(\d+)(?:\.(\d+))?(?:\.(\d+))?', value)
    return tuple(int(part or 0) for part in match.groups()) if match else None


def affected(installed, constraint):
    """Evaluate only the explicit comparator ranges returned by this audit.

    This is not a general Composer constraint parser. Unsupported ranges
    are marked unknown, never treated as evidence of safety.
    """
    current = version(installed)
    if current is None:
        return None
    alternatives = []
    for alternative in constraint.split('|'):
        terms = []
        for term in alternative.split(','):
            match = re.fullmatch(r'\s*(>=|<=|>|<|=)?\s*(v?\d+(?:\.\d+){0,2})\s*', term)
            if not match:
                terms.append(None)
                continue
            operator, target = match.groups()
            limit = version(target)
            terms.append({'<': current < limit, '<=': current <= limit,
                          '>': current > limit, '>=': current >= limit,
                          '=': current == limit, None: current == limit}[operator])
        alternatives.append(False if False in terms else None if None in terms else True)
    return True if True in alternatives else None if None in alternatives else False


packages = json.loads((ROOT / 'src/composer.lock').read_text())['packages']
endpoint = 'https://packagist.org/api/security-advisories/?' + urllib.parse.urlencode(
    [('packages[]', package['name']) for package in packages])
request = urllib.request.Request(endpoint, headers={
    'User-Agent': 'UISP-ROS-Plugin-local-readonly-audit/1.0'
})
with urllib.request.urlopen(request, timeout=20) as response:
    data = json.load(response)
report = {'checked_at': datetime.now(timezone.utc).isoformat(),
          'source': endpoint, 'packages': [],
          'note': 'Affected version ranges do not prove an exploitable path in the plugin.'}
for package in packages:
    advisories = data.get('advisories', {}).get(package['name'])
    item = {'name': package['name'], 'installed': package['version'],
            'data_available': advisories is not None, 'advisories': []}
    for advisory in advisories or []:
        item['advisories'].append({
            **{key: advisory.get(key) for key in ['advisoryId', 'title', 'cve', 'link',
                                                 'affectedVersions', 'reportedAt', 'severity']},
            'installed_version_affected': affected(package['version'], advisory['affectedVersions'])
        })
    report['packages'].append(item)
report['affected_advisories'] = sum(advisory['installed_version_affected'] is True
    for package in report['packages'] for advisory in package['advisories'])
args.output.parent.mkdir(parents=True, exist_ok=True)
args.output.write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding='utf-8')
print(json.dumps({'packages_checked': len(packages),
                  'affected_advisories': report['affected_advisories'],
                  'unknown_ranges': sum(advisory['installed_version_affected'] is None
                      for package in report['packages'] for advisory in package['advisories']),
                  'output': str(args.output.resolve())}))
