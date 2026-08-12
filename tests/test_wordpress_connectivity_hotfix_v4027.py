from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def text(p): return (ROOT/p).read_text(encoding='utf-8')

def test_managed_subscriptions_do_not_autoupdate():
    a=text('android-source/BlueVpnAccountManager.kt')
    assert 'autoUpdate = true' not in a
    assert 'autoUpdate = false' in a

def test_premium_pool_is_strict():
    a=text('android-source/BlueVpnAccountManager.kt')
    block=a[a.index('fun preferredServerGuids'):a.index('fun entitlementPoolFingerprint')]
    assert 'if (exact.isNotEmpty() || !active(c)) return exact' in block
    assert 'decodeAllServerList' in block
    assert 'it !in freeServerGuids' in block
    assert 'subscriptionId !in freeSubscriptionGuids' in block
    assert 'usableServerGuids(entitlementSubscriptionGuids(c))' in block

def test_resume_is_cache_first():
    h=text('android-source/BlueVpnHomeActivity.kt')
    assert 'lastForegroundAccountSyncAt > 120_000L' in h
    assert 'syncManagedAccount(force = false)' in h
    assert 'updateTestResultAction.observe' in h
    test_block=h[h.index('mainViewModel.updateTestResultAction.observe'):h.index('mainViewModel.updateListAction.observe')]
    assert 'warmCandidatesThenRefresh(force = true)' not in test_block

def test_wordpress_account_is_snapshot_only():
    api=text('bluevpn-manager/includes/class-bluevpn-api.php')
    assert "'source'=>'wordpress_snapshot'" in api
    account=api[api.index('public static function account('):api.index('public static function account_sync(')]
    assert 'sync_customer' not in account
    sync=api[api.index('public static function account_sync('):api.index('public static function resolve_locations(')]
    assert 'request_background_sync' in sync

def test_provider_sync_is_fail_open():
    p=text('bluevpn-manager/includes/class-bluevpn-providers.php')
    assert "'preserved_status'=>!isset($u['subscription_status'])" in p
    assert 'responses===$configured' in p
    auth=text('bluevpn-manager/includes/class-bluevpn-auth.php')
    assert 'provider_fail_open' in auth

def test_subscription_is_last_good_cached():
    p=text('bluevpn-manager/includes/class-bluevpn-providers.php')
    assert 'refresh_subscription_snapshot' in p
    assert 'stale-while-revalidate=300' in p
    assert 'profile-update-interval: 24' in p
    assert "empty($old['lines'])" in p

def test_blueai_guest_path_exists():
    api=text('bluevpn-manager/includes/class-bluevpn-api.php')
    ai=text('bluevpn-manager/includes/class-bluevpn-ai.php')
    account=text('android-source/BlueVpnAccountManager.kt')
    assert "'guest'=>(int)$c['id']===0" in api
    assert 'dashboard_device' in ai
    assert 'request(c, "GET", path, null, false)' in account
    assert 'request(c, "GET", "/api/v1/ai/dashboard", null, false)' in account
