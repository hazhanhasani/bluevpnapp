import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_release_metadata_is_v373():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.76"
    assert release["version_code"] == 30076
    assert app["version_name"] == "3.0.76"
    assert app["version_code"] == 30076


def test_locations_copy_is_bound_to_entitlement_snapshot():
    screen = (ROOT / "android-source/BlueVpnServersActivity.kt").read_text(encoding="utf-8")

    assert "private lateinit var entitlementSubtitle: TextView" in screen
    assert "private lateinit var automaticSubtitle: TextView" in screen
    assert "BlueVpnAccountManager.snapshot(this).subscriptionActive" in screen
    assert "انتخاب خودکار هوشمند • انتخاب دستی همه مکان‌ها" in screen
    assert "انتخاب خودکار رایگان • انتخاب دستی ویژه مشترکین" in screen
    assert 'textView("خودکار رایگان • انتخاب دستی برای مشترکین"' not in screen


def test_locations_force_refresh_entitlement_without_logout():
    screen = (ROOT / "android-source/BlueVpnServersActivity.kt").read_text(encoding="utf-8")

    assert "refreshEntitlementState(force = true)" in screen
    assert "BlueVpnAccountManager.sync(" in screen
    assert "force = true" in screen
    assert "mainViewModel.reloadServerList()" in screen
    assert "BlueVpnLocationUtil.invalidateCache()" in screen
    assert "!accountSyncInProgress" in screen
