"""Build the installable WordPress plugin package."""

import argparse
from pathlib import Path
import re
import zipfile


ROOT = Path(__file__).resolve().parents[1]
FILES = ('anar-login.php', 'readme.txt', 'LICENSE', 'NOTICE.txt', 'guide-fa.txt', 'services.txt', 'uninstall.php', 'languages/anar-toolkit-fa_IR.mo', 'languages/anar-toolkit-fa_IR.l10n.php')
DIRECTORIES = ('src', 'templates', 'assets')


def build(destination: Path, profile: str = 'wordpress') -> None:
    header = (ROOT / 'anar-login.php').read_text(encoding='utf-8-sig')
    readme = (ROOT / 'readme.txt').read_text(encoding='utf-8-sig')
    version = re.search(r'(?m)^\s*\* Version:\s*(\d+\.\d+\.\d+)\s*$', header).group(1)
    if not re.search(r"define\(\s*'ANAR_LOGIN_VERSION',\s*'" + re.escape(version) + r"'\s*\)", header):
        raise ValueError('Runtime version must match the plugin header.')
    if not re.search(r'(?m)^Stable tag:\s*' + re.escape(version) + r'\s*$', readme):
        raise ValueError('Readme Stable tag must match the plugin header.')
    if not re.search(r'(?m)^Contributors:\s*mrnwp\s*$', readme):
        raise ValueError('The WordPress.org owner must be listed as a contributor.')
    paths = [ROOT / name for name in FILES]
    paths += [path for name in DIRECTORIES for path in (ROOT / name).rglob('*') if path.is_file() and path.suffix.lower() not in {'.po', '.pot'}]
    for path in paths:
        if not path.is_file() or path.is_symlink() or not path.resolve().is_relative_to(ROOT):
            raise ValueError('Invalid release file: ' + str(path))
        if path.name.startswith('.') or path.suffix.lower() not in {'.php', '.js', '.css', '.png', '.svg', '.json', '.mo', '.po', '.pot', '.txt', ''}:
            raise ValueError('Unexpected release file: ' + str(path))
    destination.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(destination, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path in sorted(paths):
            relative = path.relative_to(ROOT).as_posix()
            content = path.read_bytes()
            archive.writestr('anar-toolkit/' + relative, content)
    print(str(destination.resolve()))
    print('Version ' + version + ', profile ' + profile + ': ' + str(len(paths)) + ' runtime files')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', required=True, type=Path)
    parser.add_argument('--profile', choices=('wordpress', 'development'), default='wordpress')
    args = parser.parse_args()
    build(args.output, args.profile)
