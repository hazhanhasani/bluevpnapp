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

# A single automatic publisher avoids the 4.0.29 race where the dedicated
# workflow and the inline release barrier could create the same tag together.
check('workflow_dispatch:' in wf, 'manual manager release fallback must remain available')
check('push:' not in wf, 'dedicated manager workflow must not auto-publish on push')
check('workflow_run:' not in wf, 'dedicated manager workflow must not auto-publish after APK build')
check('permissions:\n  contents: write' in wf, 'manual release workflow needs contents write permission')
check('TAG="bluevpn-manager-v${VERSION}"' in wf, 'manual release tag contract is missing')
check('bluevpn-manager.zip' in wf, 'manual release asset contract is missing')
check('gh release download' in wf and 'Published plugin version mismatch' in wf, 'manual published asset must be downloaded and verified')

check('python scripts/validate_wordpress_connectivity_hotfix.py' in build, '4.0.27 connectivity gate must remain in Android workflow')
check('Synchronize WordPress Manager with BlueVPN version' in build, 'Android workflow must keep plugin version synchronized')
check('Publish synchronized BlueVPN Manager release barrier' in build, 'Android workflow must publish manager release before Gradle')
check('Manager release barrier passed:' in build, 'Android workflow must verify published manager asset')
check(build.index('Publish synchronized BlueVPN Manager release barrier') < build.index('Checkout official v2rayNG source'), 'manager release barrier must run before Android build preparation')
check('Wait for WordPress control-plane auto-update' in build, 'Android workflow must wait for WordPress to install the manager release')
check('WordPress did not auto-update to ${VERSION} before Android build' in build, 'WordPress convergence must be a hard release gate')
check(build.index('Wait for WordPress control-plane auto-update') < build.index('Checkout official v2rayNG source'), 'WordPress convergence must complete before Android build preparation')
check('refresh_release()' in build and 'if ! gh release create "$TAG"' in build, 'inline publisher must recover release creation races')
check('BUILD_STAGE=publish-wordpress-manager-release' in build, 'publish stage telemetry is missing')
check('BUILD_STAGE=wait-wordpress-auto-update' in build, 'WordPress wait stage telemetry is missing')
check(': > "$GITHUB_WORKSPACE/android-build.log"' in build, 'pre-Gradle diagnostic log must be initialized')
check('exec > >(tee -a "$GITHUB_WORKSPACE/android-build.log") 2>&1' in build, 'pre-Gradle critical steps must write android-build.log')

check("private const DEFAULT_PREFIX = 'bluevpn-manager-v';" in updater, 'WordPress updater tag prefix differs from workflow')
check("private const DEFAULT_ASSET = 'bluevpn-manager.zip';" in updater, 'WordPress updater asset differs from workflow')
header = re.search(r'(?mi)^\s*\* Version:\s*(\d+\.\d+\.\d+)\s*$', plugin)
const = re.search(r"BLUEVPN_MANAGER_VERSION'\s*,\s*'(\d+\.\d+\.\d+)'", plugin)
rm = re.search(r'(?mi)^Version:\s*(\d+\.\d+\.\d+)\s*$', readme)
check(bool(header and const and rm), 'plugin versions could not be parsed')
check(header.group(1) == const.group(1) == rm.group(1), 'plugin Header/constant/readme versions must match')
print('OK: BlueVPN WordPress Manager single-publisher release autopilot validated')
