from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]

def text(path: str) -> str:
    return (ROOT / path).read_text(encoding='utf-8')

def check(ok: bool, message: str) -> None:
    if not ok:
        raise SystemExit('FAIL: ' + message)

wf = text('.github/workflows/bluevpn-manager-release.yml')
build = text('.github/workflows/build-apk.yml')
plugin = text('bluevpn-manager/bluevpn-manager.php')
readme = text('bluevpn-manager/readme.txt')
updater = text('bluevpn-manager/includes/class-bluevpn-github-updater.php')

check('push:' in wf and "- 'bluevpn-manager/**'" in wf, 'manager release must run automatically on plugin changes')
check('workflow_run:' in wf and 'Build Signed BlueVPN APK' in wf, 'manager release must reconcile after Android build workflow')
check('permissions:\n  contents: write' in wf, 'release workflow needs contents write permission')
check('TAG="bluevpn-manager-v${VERSION}"' in wf, 'release tag contract is missing')
check('bluevpn-manager.zip' in wf, 'release asset contract is missing')
check('gh release download' in wf and 'Published plugin version mismatch' in wf, 'published asset must be downloaded and verified')
check('python scripts/validate_wordpress_connectivity_hotfix.py' in build, '4.0.27 connectivity gate must remain in Android workflow')
check('Synchronize WordPress Manager with BlueVPN version' in build, 'Android workflow must keep plugin version synchronized')
check('Build synchronized BlueVPN Manager release' not in build and 'Publish synchronized BlueVPN Manager release' not in build, 'Android build must not own plugin publishing')
check("private const DEFAULT_PREFIX = 'bluevpn-manager-v';" in updater, 'WordPress updater tag prefix differs from workflow')
check("private const DEFAULT_ASSET = 'bluevpn-manager.zip';" in updater, 'WordPress updater asset differs from workflow')
header = re.search(r'(?mi)^\s*\* Version:\s*(\d+\.\d+\.\d+)\s*$', plugin)
const = re.search(r"BLUEVPN_MANAGER_VERSION'\s*,\s*'(\d+\.\d+\.\d+)'", plugin)
rm = re.search(r'(?mi)^Version:\s*(\d+\.\d+\.\d+)\s*$', readme)
check(bool(header and const and rm), 'plugin versions could not be parsed')
check(header.group(1) == const.group(1) == rm.group(1), 'plugin Header/constant/readme versions must match')
print('OK: BlueVPN WordPress Manager release autopilot validated')
