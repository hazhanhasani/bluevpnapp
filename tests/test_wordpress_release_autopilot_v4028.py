from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]

def source(path):
    return (ROOT / path).read_text(encoding='utf-8')


def test_normal_manager_publication_has_single_owner():
    manager = source('.github/workflows/bluevpn-manager-release.yml')
    build = source('.github/workflows/build-apk.yml')
    # The dedicated workflow remains available only for emergency/manual use.
    assert 'workflow_dispatch:' in manager
    assert 'push:' not in manager
    assert 'workflow_run:' not in manager
    # Normal publication happens inline before the Android runtime is checked out.
    assert 'Publish synchronized BlueVPN Manager release barrier' in build
    assert build.index('Publish synchronized BlueVPN Manager release barrier') < build.index('Checkout official v2rayNG source')


def test_updater_contract_matches_release_contract():
    build = source('.github/workflows/build-apk.yml')
    updater = source('bluevpn-manager/includes/class-bluevpn-github-updater.php')
    assert 'TAG="bluevpn-manager-v${VERSION}"' in build
    assert 'bluevpn-manager.zip' in build
    assert "DEFAULT_PREFIX = 'bluevpn-manager-v'" in updater
    assert "DEFAULT_ASSET = 'bluevpn-manager.zip'" in updater


def test_release_is_verified_after_publish():
    build = source('.github/workflows/build-apk.yml')
    assert 'gh release download' in build
    assert 'Published manager release mismatch' in build
    assert 'refresh_release()' in build
    assert 'checking whether another publisher created ${TAG}' in build


def test_plugin_version_sources_are_synchronized():
    plugin = source('bluevpn-manager/bluevpn-manager.php')
    readme = source('bluevpn-manager/readme.txt')
    header = re.search(r'(?mi)^\s*\* Version:\s*(\d+\.\d+\.\d+)\s*$', plugin)
    const = re.search(r"BLUEVPN_MANAGER_VERSION'\s*,\s*'(\d+\.\d+\.\d+)'", plugin)
    rm = re.search(r'(?mi)^Version:\s*(\d+\.\d+\.\d+)\s*$', readme)
    assert header and const and rm
    assert header.group(1) == const.group(1) == rm.group(1) == '4.0.30'


def test_pre_gradle_failures_have_diagnostics_and_precise_stage():
    build = source('.github/workflows/build-apk.yml')
    assert ': > "$GITHUB_WORKSPACE/android-build.log"' in build
    assert 'BUILD_STAGE=publish-wordpress-manager-release' in build
    assert 'BUILD_STAGE=wait-wordpress-auto-update' in build
    assert 'exec > >(tee -a "$GITHUB_WORKSPACE/android-build.log") 2>&1' in build
