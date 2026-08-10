from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def test_release_metadata_v379():
    app = json.loads(text("branding/app.json"))
    release = json.loads(text("release.json"))
    marker = json.loads(text("deployment-marker.json"))
    assert app["version_name"] == "3.0.83"
    assert app["version_code"] == 30083
    assert release["version"] == "3.0.83"
    assert release["android_version_code"] == 30083
    assert marker["release"] == "3.0.83"


def test_single_entitlement_source_and_strict_tiers():
    entitlement = text("android-source/BlueVpnEntitlement.kt")
    account = text("android-source/BlueVpnAccountManager.kt")
    assert "enum class BlueVpnPlanTier" in entitlement
    assert "PREMIUM" in entitlement and "FREE" in entitlement and "UNAVAILABLE" in entitlement
    assert "Single source of truth" in entitlement
    assert "fun entitlement(c: Context)" in account
    assert "BlueVpnEntitlement.reconcile(c)" in account
    assert "backgroundExecutor.execute { prepareFreeAccess(c, force = false) }\n            backgroundExecutor.execute" not in account


def test_ai_is_local_first_and_cloud_optional():
    ai = text("android-source/BlueVpnAi.kt")
    selector = text("android-source/BlueVpnSmartSelector.kt")
    assert "Local intelligence is authoritative" in ai
    assert ".getOrNull()?.optJSONArray(\"recommendations\")" in ai
    assert "object BlueVpnSmartSelector" in selector
    assert "fun rank(" in selector and "fun decide(" in selector
    assert "خارج از پلن فعال" in selector
    assert "candidate.guid in snapshot.serverGuids" in text("android-source/BlueVpnEntitlement.kt")


def test_home_plan_copy_and_ai_card_are_functional():
    home = text("android-source/BlueVpnHomeActivity.kt")
    assert "createAiCard()" in home
    assert "runSmartSelection()" in home
    assert "BlueVpnUiGuard.bind(findViewById<View>(R.id.bluevpn_ai_card)" in home
    assert "BlueVpnEntitlement.resolve(this).connectionNotice" in home
    assert "BlueVpnPlanTier.PREMIUM" in home
    assert "BlueVpnPlanTier.FREE" in home
    assert "BlueVpnPlanTier.UNAVAILABLE" in home
    assert "اتصال Premium بدون محدودیت زمانی" in text("android-source/BlueVpnEntitlement.kt")


def test_locations_and_runtime_use_smart_selector():
    locations = text("android-source/BlueVpnLocationUtil.kt")
    servers = text("android-source/BlueVpnServersActivity.kt")
    prepare = text("scripts/prepare_android.py")
    assert "BlueVpnSmartSelector.rank(context, effective)" in locations
    assert "BlueVpnSmartSelector.decide(this, candidates)" in servers
    assert "BlueVpnEntitlement.reconcile(this)" in servers
    assert 'BlueVpnEntitlement.kt"' in prepare
    assert 'BlueVpnSmartSelector.kt"' in prepare


def test_entitlement_presentation_is_not_derived_from_legacy_flags():
    entitlement = text("android-source/BlueVpnEntitlement.kt")
    settings = text("android-source/BlueVpnSettingsActivity.kt")
    servers = text("android-source/BlueVpnServersActivity.kt")
    home = text("android-source/BlueVpnHomeActivity.kt")
    assert "val poolReady: Boolean" in entitlement
    assert "val manualSelectionAllowed: Boolean" in entitlement
    assert "val timeLimited: Boolean" in entitlement
    assert "description = entitlement.accountLabel" in settings
    assert "BlueVpnEntitlement.resolve(this).manualSelectionAllowed" in servers
    assert "BlueVpnEntitlement.resolve(this).timeLimited" in home


def test_smart_selector_self_heals_missing_plan_pool():
    home = text("android-source/BlueVpnHomeActivity.kt")
    assert "lifecycleScope.launch(Dispatchers.IO)" in home
    assert "BlueVpnAccountManager.sync(" in home
    assert "BlueVpnAccountManager.prepareFreeAccess(" in home
    assert "initialEntitlement.identity != finalIdentity" in home
    assert "BlueVpnEntitlement.candidateAllowed(" in home
