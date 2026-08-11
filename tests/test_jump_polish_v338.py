from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_theme_and_minimal_home_contract():
    theme = (ROOT / "android-source/BlueVpnTheme.kt").read_text(encoding="utf-8")
    home = (ROOT / "android-source/BlueVpnHomeActivity.kt").read_text(encoding="utf-8")
    settings = (ROOT / "android-source/BlueVpnSettingsActivity.kt").read_text(encoding="utf-8")
    screen = home.split("private fun createScreen(): View {", 1)[1].split("private fun createHeader(): View {", 1)[0]

    assert "BlueVpnThemeMode" in theme
    assert 'LIGHT("light", "روشن")' in theme
    assert "BlueVpnDynamicBackgroundView" in theme
    assert "createModeRow()" not in screen
    assert "createFloatingStatCard(" not in screen
    assert "PowerGlyphView" in home
    assert "MotionEvent.ACTION_CANCEL" in home
    assert "statusCaption.visibility = View.GONE" in home
    assert "showThemeChooser()" in settings


def test_background_intelligence_and_schema_15():
    location = (ROOT / "android-source/BlueVpnLocationUtil.kt").read_text(encoding="utf-8")
    servers = (ROOT / "android-source/BlueVpnServersActivity.kt").read_text(encoding="utf-8")
    db = (ROOT / "server/database.py").read_text(encoding="utf-8")
    models = (ROOT / "server/models.py").read_text(encoding="utf-8")
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))

    assert "fun instantCandidates(" in location
    assert "successFreshnessScore" in location
    assert "bestDelay" not in servers
    assert 'SCHEMA_VERSION = "18"' in db
    assert "recent_score" in models
    assert "confidence_score" in models
    assert release["version"] == "4.0.0"


def test_exit_location_theme_launch_and_retired_presets():
    home = (ROOT / "android-source/BlueVpnHomeActivity.kt").read_text(encoding="utf-8")
    location = (ROOT / "android-source/BlueVpnLocationUtil.kt").read_text(encoding="utf-8")
    experience = (ROOT / "android-source/BlueVpnExperience.kt").read_text(encoding="utf-8")
    prepare = (ROOT / "scripts/prepare_android.py").read_text(encoding="utf-8")
    light = (ROOT / "android-source/bluevpn_screen_background.xml").read_text(encoding="utf-8")
    dark = (ROOT / "android-source/bluevpn_screen_background_night.xml").read_text(encoding="utf-8")

    assert "https://check-host.net/cdn-cgi/trace" in home
    assert 'firstOrNull { it.startsWith("loc=") }' in home
    assert "markVerifiedCountry" in location
    assert "locationForCountryCode" in location
    assert "return BlueVpnConnectionMode.BALANCED" in experience
    assert "BLUEVPN_SCREEN_BACKGROUND_NIGHT_B64" in prepare
    assert "#F8FAFF" in light
    assert "#08080C" in dark
    assert 'android:name=".ui.BlueVpnAiActivity"' not in prepare
