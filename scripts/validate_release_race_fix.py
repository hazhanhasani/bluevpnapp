from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
build = (ROOT / '.github/workflows/build-apk.yml').read_text(encoding='utf-8')
manual = (ROOT / '.github/workflows/bluevpn-manager-release.yml').read_text(encoding='utf-8')

checks = {
    'manual-only-manager-workflow': 'workflow_dispatch:' in manual and 'push:' not in manual and 'workflow_run:' not in manual,
    'single-inline-publisher': 'Publish synchronized BlueVPN Manager release barrier' in build,
    'race-recovery': 'if ! gh release create "$TAG"' in build and 'refresh_release()' in build,
    'diagnostic-log-initialized': ': > "$GITHUB_WORKSPACE/android-build.log"' in build,
    'persist-stage-logged': 'BUILD_STAGE=persist-version-metadata' in build,
    'publish-stage-logged': 'BUILD_STAGE=publish-wordpress-manager-release' in build,
    'wordpress-wait-stage-logged': 'BUILD_STAGE=wait-wordpress-auto-update' in build,
    'android-checkout-stage-logged': 'BUILD_STAGE=checkout-android-runtime' in build,
    'wordpress-six-minute-window': 'for attempt in $(seq 1 36); do' in build,
}
failed = [name for name, ok in checks.items() if not ok]
if failed:
    raise SystemExit('FAIL: ' + ', '.join(failed))
print(f"OK: release race/diagnostics fix validated ({len(checks)} checks)")
