from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def test_release_metadata_v381():
    app = json.loads(text("branding/app.json"))
    release = json.loads(text("release.json"))
    marker = json.loads(text("deployment-marker.json"))
    assert app["version_name"] == "3.0.83"
    assert app["version_code"] == 30083
    assert release["version"] == "3.0.83"
    assert release["android_version_code"] == 30083
    assert marker["release"] == "3.0.83"


def test_explicit_selection_modes_exist_and_have_atomic_writers():
    locations = text("android-source/BlueVpnLocationUtil.kt")
    assert "enum class BlueVpnSelectionMode" in locations
    assert "AUTO" in locations
    assert "MANUAL_LOCATION" in locations
    assert "MANUAL_SERVER" in locations
    assert "fun setAutomaticSelection" in locations
    assert "fun setManualLocationSelection" in locations
    assert "fun setManualServerSelection" in locations
    assert 'KEY_MANUAL_SERVER_GUID = "manual_server_guid"' in locations


def test_manual_server_selection_is_exact_and_not_re_ranked():
    servers = text("android-source/BlueVpnServersActivity.kt")
    locations = text("android-source/BlueVpnLocationUtil.kt")
    home = text("android-source/BlueVpnHomeActivity.kt")
    assert "BlueVpnPreferences.setManualServerSelection(" in servers
    assert "candidates.filter { it.guid == manualGuid }" in locations
    assert "exactManualCandidate()" in home
    assert "startSmartConnectionWithCandidates(listOf(exact), selectionMode)" in home
    assert "BlueVpnSelectionMode.MANUAL_SERVER ->" in home
    assert "listOf(BlueVpnSmartSelector.score(this, exact))" in home


def test_connect_flow_does_not_unconditionally_force_auto():
    home = text("android-source/BlueVpnHomeActivity.kt")
    begin = home[home.index("private fun beginSmartConnection()") : home.index("private fun startSmartConnectionWithCandidates")]
    assert "BlueVpnPreferences.setSmartBalance(this, true)" not in begin
    assert "val selectionMode = if (entitlement.isFree)" in begin
    assert "BlueVpnPreferences.selectionMode(this)" in begin
    assert "entitlement.isFree" in begin and "setAutomaticSelection(this)" in begin


def test_manual_location_failover_never_leaves_location():
    locations = text("android-source/BlueVpnLocationUtil.kt")
    home = text("android-source/BlueVpnHomeActivity.kt")
    assert "BlueVpnSelectionMode.MANUAL_LOCATION ->" in locations
    assert "candidates.filter { it.location.key == wanted }" in locations
    assert "BlueVpnSelectionMode.MANUAL_LOCATION -> BlueVpnSmartSelector.rank" in home
    assert "Failover فقط داخل همین لوکیشن انجام می‌شود" in home


def test_background_entitlement_refresh_preserves_valid_premium_manual_choice():
    entitlement = text("android-source/BlueVpnEntitlement.kt")
    assert "manualServerStillAllowed" in entitlement
    assert "manualLocationStillAllowed" in entitlement
    assert "background entitlement refresh must never silently flip" in entitlement
    assert "current.isFree || current.isUnavailable || tierChanged" in entitlement


def test_auto_connection_uses_desktop_style_stickiness_without_breaking_ownership():
    selector = text("android-source/BlueVpnSmartSelector.kt")
    intelligence = text("android-source/BlueVpnRouteIntelligence.kt")
    home = text("android-source/BlueVpnHomeActivity.kt")
    assert "fun connectionOrder(" in selector
    assert "BlueVpnRouteIntelligence.stickyCandidate(context, ranked)" in selector
    assert "scoreTolerance: Int = 7" in intelligence
    assert "latencyToleranceMs: Long = 60L" in intelligence
    assert "fun recordAutomaticConnectionChoice(" in selector
    assert "BlueVpnSmartSelector.connectionOrder(this, isolatedCandidates)" in home
    assert "BlueVpnSmartSelector.recordAutomaticConnectionChoice(" in home


def test_startup_optimizer_cannot_overwrite_exact_manual_server():
    home = text("android-source/BlueVpnHomeActivity.kt")
    assert "selectionMode != BlueVpnSelectionMode.MANUAL_SERVER" in home
    assert "explicit MANUAL_SERVER choice is owned by the user" in home
