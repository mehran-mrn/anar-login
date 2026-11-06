"""Validate a tagged release and extract only its installable runtime payload."""

import argparse
import os
from pathlib import Path
import re
import zipfile

ROOT = Path(__file__).resolve().parents[1]


def prepare(package: Path, output: Path, tag: str = '') -> str:
    header = (ROOT / 'anar-login.php').read_text(encoding='utf-8-sig')
    version = re.search(r'(?m)^\s*\* Version:\s*(\d+\.\d+\.\d+)\s*$', header).group(1)
    if tag and tag.removeprefix('v') != version:
        raise ValueError('Release tag must match the plugin version: ' + version)
    if output.exists():
        raise ValueError('Use a new output directory for each release.')
    with zipfile.ZipFile(package) as bundle:
        names = bundle.namelist()
        for name in names:
            parts = Path(name).parts
            if not name.startswith('anar-toolkit/') or '\\' in name or '..' in parts:
                raise ValueError('Unexpected package path: ' + name)
        for required in ('anar-login.php', 'readme.txt', 'src/Messaging/Providers/IPPanel.php', 'src/Messaging/Providers/Panelchi.php'):
            if 'anar-toolkit/' + required not in names:
                raise ValueError('Missing runtime file: ' + required)
        readme = bundle.read('anar-toolkit/readme.txt').decode('utf-8-sig')
        if not re.search(r'(?m)^Stable tag:\s*' + re.escape(version) + r'\s*$', readme):
            raise ValueError('Packaged stable tag must match the release version.')
        if bundle.read('anar-toolkit/anar-login.php').decode('utf-8-sig') != header:
            raise ValueError('Packaged plugin header differs from the released source.')
        bundle.extractall(output)
    build_dir = (output / 'anar-toolkit').resolve()
    if os.environ.get('GITHUB_OUTPUT'):
        with open(os.environ['GITHUB_OUTPUT'], 'a', encoding='utf-8') as stream:
            stream.write(f'version={version}\nbuild_dir={build_dir}\n')
    print(f'Validated Anar Toolkit {version}: {build_dir}')
    return version


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--tag', default='')
    parser.add_argument('--package', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    prepare(args.package, args.output, args.tag)
