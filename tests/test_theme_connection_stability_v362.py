from __future__ import annotations

import base64
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_release_version_362():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.70"
    assert release["version_code"] == 30070
    assert app["version_name"] == "3.0.70"
    assert app["version_code"] == 30070


def test_theme_is_committed_before_ui_rebuild():
    source = (ROOT / "android-source/BlueVpnTheme.kt").read_text(encoding="utf-8")
    assert 'KEY_CHANGED_AT = "theme_changed_at"' in source
    assert ".commit()" in source
    assert "fun isTransitionRecent" in source


def test_settings_applies_theme_in_place_without_activity_recreation():
    source = (ROOT / "android-source/BlueVpnSettingsActivity.kt").read_text(encoding="utf-8")
    chooser = source.split("private fun showThemeChooser", 1)[1].split("private fun showPrivacy", 1)[0]
    assert "applyThemeInPlace()" in chooser
    assert "recreate()" not in chooser
    assert "private fun applyThemeInPlace()" in source


def test_home_preserves_running_vpn_during_theme_transition():
    source = (ROOT / "android-source/BlueVpnHomeActivity.kt").read_text(encoding="utf-8")
    assert "themeConnectionGraceUntil" in source
    assert "isThemeConnectionGraceActive" in source
    assert "preserveServiceOnFailure = true" in source
    preserve_branch = source.split("else if (preserveServiceOnFailure", 1)[1].split("} else {", 1)[0]
    assert "CoreServiceManager.stopVService" not in preserve_branch
    assert "startupOptimizationShown = true" in source


def test_embedded_sources_match_snapshots_v362():
    script = (ROOT / "scripts/prepare_android.py").read_text(encoding="utf-8")
    pairs = {
        "BLUEVPN_HOME_ACTIVITY_B64": ROOT / "android-source/BlueVpnHomeActivity.kt",
        "BLUEVPN_THEME_B64": ROOT / "android-source/BlueVpnTheme.kt",
        "BLUEVPN_SETTINGS_ACTIVITY_B64": ROOT / "android-source/BlueVpnSettingsActivity.kt",
    }
    for name, path in pairs.items():
        match = re.search(rf'{name} = "([^"]+)"', script)
        assert match, name
        decoded = base64.b64decode(match.group(1)).decode("utf-8")
        assert decoded == path.read_text(encoding="utf-8")
