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
check('Publish synchronized BlueVPN Manager release barrier' in build, 'Android workflow must publish manager release before Gradle')
check('Manager release barrier passed:' in build, 'Android workflow must verify published manager asset')
check(build.index('Publish synchronized BlueVPN Manager release barrier') < build.index('Checkout official v2rayNG source'), 'manager release barrier must run before Android build preparation')
check('Wait for WordPress control-plane auto-update' in build, 'Android workflow must wait for WordPress to install the manager release')
check('WordPress did not auto-update to ${VERSION} before Android build' in build, 'WordPress convergence must be a hard release gate')
check(build.index('Wait for WordPress control-plane auto-update') < build.index('Checkout official v2rayNG source'), 'WordPress convergence must complete before Android build preparation')
check("private const DEFAULT_PREFIX = 'bluevpn-manager-v';" in updater, 'WordPress updater tag prefix differs from workflow')
check("private const DEFAULT_ASSET = 'bluevpn-manager.zip';" in updater, 'WordPress updater asset differs from workflow')
header = re.search(r'(?mi)^\s*\* Version:\s*(\d+\.\d+\.\d+)\s*$', plugin)
const = re.search(r"BLUEVPN_MANAGER_VERSION'\s*,\s*'(\d+\.\d+\.\d+)'", plugin)
rm = re.search(r'(?mi)^Version:\s*(\d+\.\d+\.\d+)\s*$', readme)
check(bool(header and const and rm), 'plugin versions could not be parsed')
check(header.group(1) == const.group(1) == rm.group(1), 'plugin Header/constant/readme versions must match')
print('OK: BlueVPN WordPress Manager release autopilot validated')
