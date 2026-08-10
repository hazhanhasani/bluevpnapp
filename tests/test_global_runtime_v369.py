from __future__ import annotations

import ast
import base64
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ANDROID = ROOT / "android-source"
PREPARE = ROOT / "scripts/prepare_android.py"


def _embedded_sources() -> dict[str, str]:
    module = ast.parse(PREPARE.read_text(encoding="utf-8"))
    result: dict[str, str] = {}
    for node in module.body:
        if not isinstance(node, ast.Assign) or len(node.targets) != 1:
            continue
        target = node.targets[0]
        if not isinstance(target, ast.Name) or not target.id.endswith("_B64"):
            continue
        result[target.id] = base64.b64decode(ast.literal_eval(node.value)).decode("utf-8")
    return result


def test_release_is_global_runtime_bugfix_3069() -> None:
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.83"
    assert release["version_code"] == 30083
    assert app["version_name"] == "3.0.83"
    assert app["version_code"] == 30083


def test_startup_and_dashboard_are_coalesced_and_non_blocking() -> None:
    source = (ANDROID / "BlueVpnHomeActivity.kt").read_text(encoding="utf-8")
    assert "private fun scheduleStartupPipeline()" in source
    assert "private fun requestDashboardRefresh" in source
    assert "lifecycleScope.launch(Dispatchers.IO)" in source
    assert "val candidates = BlueVpnLocationUtil.cachedCandidates(this)" in source
    refresh = source.split("private fun refreshDashboard(", 1)[1].split("private fun", 1)[0]
    assert "BlueVpnLocationUtil.allCandidates" not in refresh


def test_shared_mobile_config_prevents_duplicate_remote_fetches() -> None:
    account = (ANDROID / "BlueVpnAccountManager.kt").read_text(encoding="utf-8")
    ads = (ANDROID / "BlueVpnAdsCarouselView.kt").read_text(encoding="utf-8")
    settings = (ANDROID / "BlueVpnSettingsActivity.kt").read_text(encoding="utf-8")
    assert "private val mobileConfigLock" in account
    assert "MOBILE_CONFIG_CACHE_MS" in account
    assert "fun mobileConfig(" in account
    assert "BlueVpnAccountManager.mobileConfig" in ads
    assert "BlueVpnAccountManager.mobileConfig" in settings


def test_auth_restore_and_catalog_fetches_are_cached() -> None:
    source = (ANDROID / "BlueVpnAccountManager.kt").read_text(encoding="utf-8")
    assert "private val primaryRestored = AtomicBoolean(false)" in source
    assert "if (primaryRestored.get()) return" in source
    assert "private var plansCacheRaw" in source
    assert "private var freeSnapshotCache" in source
    assert "private var freePrepareRunning" in source


def test_server_screen_loads_candidates_off_main_thread() -> None:
    source = (ANDROID / "BlueVpnServersActivity.kt").read_text(encoding="utf-8")
    assert "lifecycleScope.launch(Dispatchers.Default)" in source
    assert "candidateLoadInProgress" in source
    assert "BlueVpnLocationUtil.cachedCandidates(this)" in source


def test_account_sync_does_not_block_form_editing() -> None:
    source = (ANDROID / "BlueVpnSubscriptionsActivity.kt").read_text(encoding="utf-8")
    assert "syncInProgress" in source
    assert "if(syncInProgress)return" in source
    assert "currentFocus !is EditText" in source
    assert "handler.postDelayed({if(!isFinishing&&!isDestroyed)sync(true)},320L)" in source.replace(" ", "")


def test_modified_android_snapshots_match_generator_payloads() -> None:
    embedded = _embedded_sources()
    mapping = {
        "BLUEVPN_ACCOUNT_MANAGER_B64": "BlueVpnAccountManager.kt",
        "BLUEVPN_HOME_ACTIVITY_B64": "BlueVpnHomeActivity.kt",
        "BLUEVPN_LOCATION_UTIL_B64": "BlueVpnLocationUtil.kt",
        "BLUEVPN_SERVERS_ACTIVITY_B64": "BlueVpnServersActivity.kt",
        "BLUEVPN_SUBSCRIPTIONS_ACTIVITY_B64": "BlueVpnSubscriptionsActivity.kt",
        "BLUEVPN_SETTINGS_ACTIVITY_B64": "BlueVpnSettingsActivity.kt",
        "BLUEVPN_ADS_CAROUSEL_B64": "BlueVpnAdsCarouselView.kt",
    }
    for constant, filename in mapping.items():
        assert embedded[constant] == (ANDROID / filename).read_text(encoding="utf-8")
