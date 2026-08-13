from __future__ import annotations

import json
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def test_release_validator_passes() -> None:
    result = subprocess.run(
        ["python", "scripts/validate_release.py"],
        cwd=ROOT,
        text=True,
        capture_output=True,
        check=False,
    )
    assert result.returncode == 0, result.stdout + result.stderr


def test_short_version_and_core_pins() -> None:
    app = json.loads(text("branding/app.json"))
    assert app["version_name"] == "4.1.0"
    assert app["version_code"] == 40100
    assert int(app["version_name"].split(".")[-1]) <= 10
    assert app["upstream_ref"] == "2.2.6"
    assert app["xray_ref"] == "v26.7.28"


def test_free_premium_pool_isolation_contract() -> None:
    account = text("android-source/BlueVpnAccountManager.kt")
    auth = text("bluevpn-manager/includes/class-bluevpn-auth.php")
    assert "pool_identity" in auth
    assert "poolIdentity" in account
    assert "autoUpdate = false" in account
    assert "return guid in entitlementServerGuids" in account
    assert "scheduleInstall(" not in account


def test_ai_is_read_only_on_selection_tap() -> None:
    home = text("android-source/BlueVpnHomeActivity.kt")
    start = home.index("    private fun runSmartSelection()")
    end = home.index("    private fun showConnectingOverlay", start)
    block = home[start:end]
    assert "awaitEntitlementServers" not in block
    assert "prepareFreeAccess" not in block
    assert block.index("BlueVpnSmartSelector.decide") < block.index("BlueVpnAi.refreshRecommendations")


def test_ci_does_not_mutate_source_branch_or_store_generated_source() -> None:
    workflow = text(".github/workflows/build-apk.yml")
    assert "git push" not in workflow
    assert "git commit" not in workflow
    assert "android-source/generated" not in workflow
    assert 'git checkout --force "$XRAY_REF"' in workflow


def test_account_navigation_does_not_force_provider_sync() -> None:
    account_ui = text("android-source/BlueVpnSubscriptionsActivity.kt")
    start = account_ui.index(" override fun onResume()")
    end = account_ui.index(" override fun onPause()", start)
    block = account_ui[start:end]
    assert "sync(false)" in block
    assert "sync(true)" not in block


def test_subscription_mutation_is_atomic_and_deferred() -> None:
    account = text("android-source/BlueVpnAccountManager.kt")
    intelligence = text("android-source/BlueVpnSubscriptionIntelligence.kt")
    home = text("android-source/BlueVpnHomeActivity.kt")
    assert "private fun install(c: Context, url: String)" not in account
    assert "refreshWithinMutation" in intelligence
    assert "beginSubscriptionMutation" in account
    assert "reconcilePendingEntitlement" in account
    assert "reconcileDeferredEntitlementIfIdle(retryConnection = true)" in home
    assert "beginSmartConnection()\n                        beginSmartConnection()" not in home


def test_wordpress_account_read_is_snapshot_only_and_sync_is_background() -> None:
    api = text("bluevpn-manager/includes/class-bluevpn-api.php")
    providers = text("bluevpn-manager/includes/class-bluevpn-providers.php")
    account_start = api.index("    public static function account(WP_REST_Request $r)")
    sync_start = api.index("    public static function account_sync(WP_REST_Request $r)", account_start)
    next_start = api.index("    public static function resolve_locations", sync_start)
    account_block = api[account_start:sync_start]
    sync_block = api[sync_start:next_start]
    assert "request_background_sync" not in account_block
    assert "sync_customer" not in account_block
    assert "source'=>'wordpress_snapshot'" in account_block
    assert "request_background_sync" in sync_block
    assert "sync_customer" not in sync_block
    assert "wp_next_scheduled('bluevpn_sync_customer_async'" in providers
    assert "self::sync_customer($customerId,false)" in providers
    assert "private const SYNC_TTL_SECONDS = 300" in providers
