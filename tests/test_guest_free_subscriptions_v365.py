from __future__ import annotations

import base64
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_release_version_365():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.66"
    assert release["version_code"] == 30066
    assert app["version_name"] == "3.0.66"
    assert app["version_code"] == 30066


def test_admin_has_independent_multi_source_free_subscription_manager():
    source = (ROOT / "server/main.py").read_text(encoding="utf-8")
    template = (ROOT / "server/templates/admin.html").read_text(encoding="utf-8")
    assert "free_subscription_items" in source
    assert "@app.post('/admin/free-subscriptions')" in source
    assert "@app.post('/admin/free-subscriptions/{item_id}/edit')" in source
    assert "@app.post('/admin/free-subscriptions/{item_id}/toggle')" in source
    assert "@app.post('/admin/free-subscriptions/{item_id}/delete')" in source
    assert "@app.get('/api/v1/free/subscriptions/{item_id}')" in source
    assert "'subscriptions':public_items" in source
    assert 'id="free-subs"' in template
    assert "ساب‌های پلن رایگان" in template
    assert "افزودن ساب رایگان" in template


def test_mobile_config_declares_optional_account_and_guest_free_access():
    source = (ROOT / "server/main.py").read_text(encoding="utf-8")
    assert "'account_required':False" in source
    assert "'account_optional':True" in source
    assert "'guest_allowed':True" in source
    assert "'account_required_for_free':False" in source


def test_android_guest_starts_home_and_free_mode_does_not_require_session():
    account = (ROOT / "android-source/BlueVpnAccountManager.kt").read_text(encoding="utf-8")
    home = (ROOT / "android-source/BlueVpnHomeActivity.kt").read_text(encoding="utf-8")
    servers = (ROOT / "android-source/BlueVpnServersActivity.kt").read_text(encoding="utf-8")
    assert "fun isFreeMode(c: Context): Boolean =\n        !active(c) && freeAccessEnabled(c)" in account
    assert "val subscriptions: List<BlueVpnFreeSubscription>" in account
    assert "installFreeSubscriptions" in account
    assert 'putStringSet("subscription_guids"' in account
    assert "prepareGuestFreeAccess(force = false)" in home
    assert "First install must land directly on the connection screen" in home
    assert "if (!BlueVpnAccountManager.hasSession(this)) {\n            openAccount()" not in home
    assert "openSubscriptionForPremium" in servers
    assert "BlueVpnSubscriptionsActivity::class.java" in servers


def test_embedded_android_sources_match_v365_snapshots():
    script = (ROOT / "scripts/prepare_android.py").read_text(encoding="utf-8")
    mapping = {
        "BLUEVPN_ACCOUNT_MANAGER_B64": "BlueVpnAccountManager.kt",
        "BLUEVPN_HOME_ACTIVITY_B64": "BlueVpnHomeActivity.kt",
        "BLUEVPN_SERVERS_ACTIVITY_B64": "BlueVpnServersActivity.kt",
    }
    for constant, filename in mapping.items():
        match = re.search(rf'{constant} = "([^"]+)"', script)
        assert match, constant
        decoded = base64.b64decode(match.group(1)).decode("utf-8")
        assert decoded == (ROOT / "android-source" / filename).read_text(encoding="utf-8")
