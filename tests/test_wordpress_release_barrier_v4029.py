from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]

def source(path: str) -> str:
    return (ROOT / path).read_text(encoding='utf-8')


def test_version_is_4030_everywhere():
    plugin = source('bluevpn-manager/bluevpn-manager.php')
    readme = source('bluevpn-manager/readme.txt')
    app = source('branding/app.json')
    assert '* Version: 4.0.30' in plugin
    assert "BLUEVPN_MANAGER_VERSION', '4.0.30'" in plugin
    assert 'Version: 4.0.30' in readme
    assert '"version_name": "4.0.30"' in app


def test_main_build_publishes_manager_before_android_checkout():
    wf = source('.github/workflows/build-apk.yml')
    publish = wf.index('Publish synchronized BlueVPN Manager release barrier')
    converge = wf.index('Wait for WordPress control-plane auto-update')
    checkout = wf.index('Checkout official v2rayNG source')
    assert publish < converge < checkout
    assert 'TAG="bluevpn-manager-v${VERSION}"' in wf
    assert "--pattern 'bluevpn-manager.zip'" in wf
    assert 'Published manager release mismatch' in wf


def test_apk_build_is_blocked_until_wordpress_is_same_version_and_schema():
    wf = source('.github/workflows/build-apk.yml')
    assert '${API_BASE_URL}/health' in wf
    assert 'BLUEVPN_MANAGER_SCHEMA_VERSION' in wf
    assert 'WordPress did not auto-update to ${VERSION} before Android build' in wf
    assert 'WP_CRON_URL' in wf
    assert 'LAST_VERSION" = "$VERSION' in wf
    assert 'LAST_SCHEMA" = "$EXPECTED_SCHEMA' in wf
    assert 'LAST_READY" = "true' in wf


def test_dedicated_manager_workflow_is_manual_fallback_only():
    wf = source('.github/workflows/bluevpn-manager-release.yml')
    assert 'name: Release BlueVPN Manager' in wf
    assert 'workflow_dispatch:' in wf
    assert 'push:' not in wf
    assert 'workflow_run:' not in wf
    assert 'bluevpn-manager.zip' in wf


def test_release_create_race_is_recovered():
    wf = source('.github/workflows/build-apk.yml')
    assert 'refresh_release()' in wf
    assert 'if ! gh release create "$TAG"' in wf
    assert 'checking whether another publisher created ${TAG}' in wf
    assert 'Could not create or recover ${TAG}' in wf
