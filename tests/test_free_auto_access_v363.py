from __future__ import annotations

import base64
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_release_version_363():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.70"
    assert release["version_code"] == 30070
    assert app["version_name"] == "3.0.70"
    assert app["version_code"] == 30070


def test_server_exposes_managed_free_access_and_hidden_relay():
    source = (ROOT / "server/main.py").read_text(encoding="utf-8")
    template = (ROOT / "server/templates/admin.html").read_text(encoding="utf-8")
    assert "free_access_enabled" in source
    assert "free_subscription_items" in source
    assert "free_session_minutes" in source
    assert "@app.get('/api/v1/free/subscription')" in source
    assert "'auto_only':True" in source
    assert "'manual_selection_requires_subscription':True" in source
    assert "ساب‌های پلن رایگان" in template


def test_android_enforces_auto_only_free_mode_and_one_hour_alarm():
    account = (ROOT / "android-source/BlueVpnAccountManager.kt").read_text(encoding="utf-8")
    home = (ROOT / "android-source/BlueVpnHomeActivity.kt").read_text(encoding="utf-8")
    servers = (ROOT / "android-source/BlueVpnServersActivity.kt").read_text(encoding="utf-8")
    locations = (ROOT / "android-source/BlueVpnLocationUtil.kt").read_text(encoding="utf-8")
    prepare = (ROOT / "scripts/prepare_android.py").read_text(encoding="utf-8")
    assert "class BlueVpnFreeSessionReceiver" in account
    assert "setAndAllowWhileIdle" in account
    assert "sessionMinutes * 60_000L" in account
    assert "prepareFreeAccess" in home
    assert "BlueVpnPreferences.setSmartBalance(this, true)" in home
    assert "برای انتخاب دستی لوکیشن ابتدا اشتراک تهیه کنید" in servers
    assert "createServerEntry" in servers
    assert "candidate.profile.subscriptionId" in locations
    assert "BlueVpnFreeSessionReceiver" in prepare


def test_embedded_sources_match_modified_android_snapshots():
    prepare = (ROOT / "scripts/prepare_android.py").read_text(encoding="utf-8")
    mapping = {
        "BLUEVPN_ACCOUNT_MANAGER_B64": "BlueVpnAccountManager.kt",
        "BLUEVPN_LOCATION_UTIL_B64": "BlueVpnLocationUtil.kt",
        "BLUEVPN_HOME_ACTIVITY_B64": "BlueVpnHomeActivity.kt",
        "BLUEVPN_SERVERS_ACTIVITY_B64": "BlueVpnServersActivity.kt",
    }
    for constant, filename in mapping.items():
        match = re.search(rf'{constant} = "([^"]+)"', prepare)
        assert match, constant
        decoded = base64.b64decode(match.group(1)).decode("utf-8")
        assert decoded == (ROOT / "android-source" / filename).read_text(encoding="utf-8")
