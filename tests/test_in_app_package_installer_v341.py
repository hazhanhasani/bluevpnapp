from __future__ import annotations

import ast
import base64
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PREPARE = ROOT / "scripts/prepare_android.py"


def _embedded(name: str) -> str:
    module = ast.parse(PREPARE.read_text(encoding="utf-8"))
    for node in module.body:
        if not isinstance(node, ast.Assign) or len(node.targets) != 1:
            continue
        target = node.targets[0]
        if isinstance(target, ast.Name) and target.id == name:
            return base64.b64decode(ast.literal_eval(node.value)).decode("utf-8")
    raise AssertionError(f"missing {name}")


def test_updater_uses_package_installer_session_as_primary_path():
    source = (ROOT / "android-source/BlueVpnUpdateManager.kt").read_text(encoding="utf-8")
    assert "PackageInstaller.SessionParams" in source
    assert 'session.openWrite(' in source
    assert 'session.fsync(output)' in source
    assert 'session.commit(statusReceiver.intentSender)' in source
    assert 'PendingIntent.FLAG_MUTABLE' in source
    assert 'installWithPackageInstaller(' in source


def test_updater_validates_version_code_and_signing_certificate():
    source = (ROOT / "android-source/BlueVpnUpdateManager.kt").read_text(encoding="utf-8")
    assert 'archiveCode <= installedCode' in source
    assert 'signingCertificateDigests' in source
    assert 'currentSigners.intersect(updateSigners).isEmpty()' in source
    assert 'امضای بروزرسانی با نسخه نصب‌شده یکسان نیست' in source


def test_installer_callback_and_provider_are_embedded_and_manifested():
    activity = _embedded("BLUEVPN_UPDATE_INSTALL_ACTIVITY_B64")
    provider = _embedded("BLUEVPN_UPDATE_FILE_PROVIDER_B64")
    prepare = PREPARE.read_text(encoding="utf-8")
    assert "handlePackageInstallerStatus" in activity
    assert "class BlueVpnUpdateFileProvider : FileProvider()" in provider
    assert '.ui.BlueVpnUpdateInstallActivity' in prepare
    assert 'com.v2ray.ang.bluevpn.BlueVpnUpdateFileProvider' in prepare


def test_embedded_updater_matches_snapshot():
    embedded = _embedded("BLUEVPN_UPDATE_MANAGER_B64")
    source = (ROOT / "android-source/BlueVpnUpdateManager.kt").read_text(encoding="utf-8")
    assert embedded == source
