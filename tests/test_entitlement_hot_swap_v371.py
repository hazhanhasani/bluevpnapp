from pathlib import Path

from server.integrations import normalize_bluepay_invoice, verify_webhook


ROOT = Path(__file__).resolve().parents[1]


def test_free_server_shortlist_prioritizes_entitlement_sources():
    account = (ROOT / "android-source/BlueVpnAccountManager.kt").read_text()
    locations = (ROOT / "android-source/BlueVpnLocationUtil.kt").read_text()
    assert "fun preferredServerGuids" in account
    assert "MmkvManager.decodeServerList(guid)" in account
    assert "val entitlementGuids = BlueVpnAccountManager.preferredServerGuids(context)" in locations
    assert "scan(skipSessionInactive = false)" in locations


def test_premium_transition_is_reconciled_before_sync_returns():
    account = (ROOT / "android-source/BlueVpnAccountManager.kt").read_text()
    assert "private fun reconcileSubscriptionMode" in account
    assert "forceSubscriptions = force" in account
    assert "BlueVpnSubscriptionIntelligence.refresh(" in account
    assert "it.subscription.remarks.startsWith(FREE_SUB)" in account
    assert "MmkvManager.setSelectServer(it)" in account


def test_foreground_refresh_does_not_require_logout():
    home = (ROOT / "android-source/BlueVpnHomeActivity.kt").read_text()
    subscriptions = (ROOT / "android-source/BlueVpnSubscriptionsActivity.kt").read_text()
    assert "syncManagedAccount(force = true)" in home
    assert "accountSyncForcePending" in home
    assert "sync(true)" in subscriptions
    assert "(materiallyChanged||force)" in subscriptions


def test_bluepay_wrapped_invoice_is_normalized():
    payload = normalize_bluepay_invoice(
        {
            "success": True,
            "data": {
                "invoice_id": "inv-371",
                "checkout_url": "https://pay.example/371",
                "payment_status": "pending",
                "amount_toman": 150000,
            },
        }
    )
    assert payload["payment_id"] == "inv-371"
    assert payload["payment_url"] == "https://pay.example/371"
    assert payload["status"] == "pending"
    assert payload["amount_toman"] == 150000


def test_bluepay_prefixed_hmac_signature_is_accepted():
    import hashlib
    import hmac
    import json

    secret = "secret-371"
    raw = json.dumps({"payment_id": "p-371", "status": "paid"}, separators=(",", ":")).encode()
    signature = hmac.new(secret.encode(), raw, hashlib.sha256).hexdigest()
    valid, payload = verify_webhook(raw, f"sha256={signature}", secret)
    assert valid is True
    assert payload["payment_id"] == "p-371"
