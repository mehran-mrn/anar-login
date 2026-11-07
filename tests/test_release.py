"""Verify shipped adapters and lint the actual WordPress.org payload."""

from pathlib import Path
import importlib.util
import subprocess
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('anar_package', ROOT / 'tools/package.py')
package = importlib.util.module_from_spec(spec)
spec.loader.exec_module(package)
release_spec = importlib.util.spec_from_file_location('anar_prepare_release', ROOT / 'tools/prepare-release.py')
release = importlib.util.module_from_spec(release_spec)
release_spec.loader.exec_module(release)
checks = 0

def verify(condition, message):
    global checks
    if not condition:
        raise AssertionError(message)
    checks += 1

with tempfile.TemporaryDirectory(prefix='anar-release-qa-') as folder:
    temporary = Path(folder).resolve()
    for profile in ('wordpress', 'development'):
        archive = temporary / (profile + '.zip')
        package.build(archive, profile)
        with zipfile.ZipFile(archive) as bundle:
            names = bundle.namelist()
            verify(all(name.startswith('anar-toolkit/') for name in names), 'Release folder matches proposed slug')
            verify(not any('/vendor/' in name or '/tests/' in name or '/tools/' in name for name in names), 'Development dependencies excluded')
            verify(not any(name.endswith(('.po', '.pot')) for name in names), 'Translation editing sources excluded')
            verify('anar-toolkit/languages/anar-toolkit-fa_IR.mo' in names, 'Persian runtime MO is available without a separate language pack')
            verify('anar-toolkit/languages/anar-toolkit-fa_IR.l10n.php' in names, 'Persian PHP catalog is included for modern WordPress')
            verify(not any('/languages/anar-login-' in name for name in names), 'Unused legacy text-domain catalogs are excluded')
            verify('anar-toolkit/src/Messaging/Providers/Melipayamak.php' in names, 'Meli Payamak retained')
            verify('anar-toolkit/src/Messaging/Providers/IPPanel.php' in names, 'IPPanel included in every profile')
            verify('anar-toolkit/src/Messaging/Providers/Panelchi.php' in names, 'Panelchi included in every profile')
            verify('anar-toolkit/src/Messaging/Providers/MaxSms.php' in names, 'MaxSMS included in every profile')
            header = bundle.read('anar-toolkit/anar-login.php').decode('utf-8')
            verify('Text Domain:       anar-toolkit' in header, 'Gettext domain matches release folder')
            verify('Marketing for WooCommerce' in header, 'WooCommerce name uses the allowed for pattern')
            sender = bundle.read('anar-toolkit/src/Messaging/NotificationSender.php').decode('utf-8')
            verify('edge.ippanel.com' in sender, 'IPPanel commerce enabled')
            verify('Providers\\Panelchi' in sender, 'Panelchi commerce enabled')
            verify('Providers\\MaxSms' in sender, 'MaxSMS commerce enabled')
            verify('payamak-panel.com' in sender, 'Meli Payamak commerce endpoint retained')
            registry = bundle.read('anar-toolkit/src/Messaging/ProviderRegistry.php').decode('utf-8')
            verify('IPPanel::class' in registry and 'Panelchi::class' in registry, 'Registry matches shipped adapters')
            verify('MaxSms::class' in registry, 'MaxSMS registered in the public runtime')
            readme = bundle.read('anar-toolkit/readme.txt').decode('utf-8')
            verify('https://www.melipayamak.com/rules/' in readme, 'Official Meli Payamak terms retained')
            verify('= IPPanel =' in readme and '= Panelchi =' in readme, 'Public documentation matches shipped providers')
            verify('https://docs.panelchi.com/redoc/' in readme, 'Panelchi API documented')
            verify('= MaxSMS =' in readme and 'https://maxsms.co/webservice/' in readme, 'MaxSMS service and shared infrastructure disclosed')
            if profile == 'wordpress':
                bundle.extractall(temporary / 'public')
                try:
                    release.prepare(archive, temporary / 'wrong-tag', '999.999.999')
                    verify(False, 'Mismatched release tag must fail')
                except ValueError:
                    verify(True, 'Mismatched release tag rejected')
                verify(not (temporary / 'wrong-tag').exists(), 'Invalid release cannot produce a deployable directory')
                release.prepare(archive, temporary / 'release')
                verify((temporary / 'release/anar-toolkit/src/Messaging/Providers/Panelchi.php').is_file(), 'Validated runtime includes Panelchi')
    verify((ROOT / 'src/Messaging/Providers/IPPanel.php').is_file(), 'Development source still retains IPPanel')
    for php in (temporary / 'public').rglob('*.php'):
        result = subprocess.run(['php', '-l', str(php)], capture_output=True, text=True)
        verify(result.returncode == 0, 'PHP lint: ' + str(php.relative_to(temporary)))

print(str(checks) + ' release checks passed, including lint of the packaged PHP files.')
