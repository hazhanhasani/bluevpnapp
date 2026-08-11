from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]

def source(path):
    return (ROOT / path).read_text(encoding='utf-8')

def test_manager_release_is_automatic_and_independent():
    wf = source('.github/workflows/bluevpn-manager-release.yml')
    assert 'push:' in wf
    assert "- 'bluevpn-manager/**'" in wf
    assert 'workflow_run:' in wf
    assert 'Build Signed BlueVPN APK' in wf
    assert 'workflow_dispatch:' in wf

def test_updater_contract_matches_release_contract():
    wf = source('.github/workflows/bluevpn-manager-release.yml')
    updater = source('bluevpn-manager/includes/class-bluevpn-github-updater.php')
    assert 'TAG="bluevpn-manager-v${VERSION}"' in wf
    assert 'bluevpn-manager.zip' in wf
    assert "DEFAULT_PREFIX = 'bluevpn-manager-v'" in updater
    assert "DEFAULT_ASSET = 'bluevpn-manager.zip'" in updater

def test_release_is_verified_after_publish():
    wf = source('.github/workflows/bluevpn-manager-release.yml')
    assert 'Verify published updater contract' in wf
    assert 'gh release download' in wf
    assert 'Published plugin version mismatch' in wf

def test_plugin_version_sources_are_synchronized():
    plugin = source('bluevpn-manager/bluevpn-manager.php')
    readme = source('bluevpn-manager/readme.txt')
    header = re.search(r'(?mi)^\s*\* Version:\s*(\d+\.\d+\.\d+)\s*$', plugin)
    const = re.search(r"BLUEVPN_MANAGER_VERSION'\s*,\s*'(\d+\.\d+\.\d+)'", plugin)
    rm = re.search(r'(?mi)^Version:\s*(\d+\.\d+\.\d+)\s*$', readme)
    assert header and const and rm
    assert header.group(1) == const.group(1) == rm.group(1) == '4.0.28'

def test_android_workflow_synchronizes_version_but_does_not_block_plugin_release():
    wf = source('.github/workflows/build-apk.yml')
    assert 'Synchronize WordPress Manager with BlueVPN version' in wf
    assert 'Build synchronized BlueVPN Manager release' not in wf
    assert 'Publish synchronized BlueVPN Manager release' not in wf
    assert 'python scripts/validate_wordpress_connectivity_hotfix.py' in wf
    assert 'python scripts/validate_wordpress_release_autopilot.py' in wf
