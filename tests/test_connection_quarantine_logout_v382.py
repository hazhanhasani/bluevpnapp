from __future__ import annotations

import base64
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def test_logout_exits_account_screen_and_home_falls_back_to_guest_free():
    activity = text("android-source/BlueVpnSubscriptionsActivity.kt")
    account = text("android-source/BlueVpnAccountManager.kt")
    home = text("android-source/BlueVpnHomeActivity.kt")

    logout_block = activity[activity.index('button("خروج از حساب"'): activity.index('private fun phoneBindingCard')]
    assert "BlueVpnAccountManager.logout(" in logout_block
    assert "finish()" in logout_block
    assert "render()" not in logout_block

    assert "BlueVpnPreferences.setAutomaticSelection(appContext)" in account
    assert "BlueVpnSmartSelector.clear(appContext)" in account
    assert "BlueVpnLocationUtil.invalidateCache()" in account
    assert "pruneInactiveManagedPools(appContext)" in account

    launcher = home[home.index("private val accountLauncher"): home.index("private enum class OrbVisualState")]
    assert "cancelFailover()" in launcher
    assert "renderConnectionState(false)" in launcher
    assert "prepareGuestFreeAccess(force = false)" in launcher


def test_failed_route_is_quarantined_for_current_connect_cycle_only():
    locations = text("android-source/BlueVpnLocationUtil.kt")
    home = text("android-source/BlueVpnHomeActivity.kt")

    health = locations[locations.index("fun beginHealthSession"): locations.index("fun markSessionInactive")]
    assert "SESSION_INACTIVE_PREFIX" in health
    assert "it.startsWith(FAILED_PREFIX)" not in health

    fail = home[home.index("private fun failCurrentAndTryNext"): home.index("private fun finishFailoverWithError")]
    assert "BlueVpnPreferences.markSessionInactive(this, failedGuid)" in fail
    assert "BlueVpnPreferences.markServerFailure(this, failedGuid)" in fail

    ordered = locations[locations.index("fun orderedCandidates"): locations.index("fun fastCandidates")]
    assert "if (sessionHealthy.isEmpty()) return emptyList()" in ordered
    assert "sessionHealthy.ifEmpty { scoped }" not in ordered

    fast = locations[locations.index("fun fastCandidates"): locations.index("fun instantCandidates")]
    assert not re.search(r"^\s*(?:val\s+\w+\s*=\s*)?scan\(skipSessionInactive = false\)", fast, re.M)


def test_new_connect_cycle_restores_temporarily_quarantined_routes():
    home = text("android-source/BlueVpnHomeActivity.kt")
    begin = home[home.index("private fun beginSmartConnection()"): home.index("private fun startSmartConnectionWithCandidates")]
    assert "BlueVpnPreferences.beginHealthSession(this)" in begin


def test_preflight_runs_before_xray_without_false_negative_endpoint_rejection():
    locations = text("android-source/BlueVpnLocationUtil.kt")
    home = text("android-source/BlueVpnHomeActivity.kt")

    assert "fun preflightCandidate(" in locations
    assert "InetAddress.getAllByName(host)" in locations
    assert "Socket().use" in locations
    assert "socket.connect(" in locations
    assert "DNS and raw TCP" in locations
    assert 'CandidatePreflight(false, "DNS سرور پاسخ نداد")' not in locations
    assert 'CandidatePreflight(false, "سرور روی پورت کانفیگ پاسخ نداد")' not in locations
    assert "take(3)" in locations
    assert "sortedBy { if (it.address.size == 4) 0 else 1 }" in locations

    current = home[home.index("private fun startCurrentCandidate"): home.index("private fun scheduleConnectionVerification")]
    assert "lifecycleScope.launch(Dispatchers.IO)" in current
    assert "BlueVpnLocationUtil.preflightCandidate(" in current
    assert current.index("BlueVpnLocationUtil.preflightCandidate(") < current.index("BlueVpnEngineManager.start(")
    assert "failCurrentAndTryNext(preflight.reason)" in current
    assert "statusCaption.text = preflight.reason" in current


def test_core_start_stop_commands_are_off_main_thread_and_generation_guarded():
    engine = text("android-source/BlueVpnEngineManager.kt")
    assert "Executors.newSingleThreadExecutor" in engine
    assert 'Thread(task, "bluevpn-engine-command")' in engine
    assert "AtomicLong(0L)" in engine
    assert "commandExecutor.execute" in engine
    assert "generation != commandGeneration.get()" in engine
    assert "CoreServiceManager.startVService(app)" in engine
    assert "CoreServiceManager.stopVService(app)" in engine


def test_modified_embedded_android_sources_stay_in_sync():
    script = text("scripts/prepare_android.py")
    pairs = {
        "BLUEVPN_HOME_ACTIVITY_B64": "android-source/BlueVpnHomeActivity.kt",
        "BLUEVPN_ACCOUNT_MANAGER_B64": "android-source/BlueVpnAccountManager.kt",
        "BLUEVPN_LOCATION_UTIL_B64": "android-source/BlueVpnLocationUtil.kt",
        "BLUEVPN_SUBSCRIPTIONS_ACTIVITY_B64": "android-source/BlueVpnSubscriptionsActivity.kt",
    }
    for constant, path in pairs.items():
        match = re.search(rf'^{constant} = "([^"]+)"$', script, re.M)
        assert match, constant
        assert base64.b64decode(match.group(1)).decode("utf-8") == text(path)
