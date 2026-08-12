from pathlib import Path
import json

ROOT = Path(__file__).resolve().parents[1]

def text(path):
    return (ROOT / path).read_text(encoding='utf-8')

def test_version_4032():
    app = json.loads(text('branding/app.json'))
    release = json.loads(text('release.json'))
    assert (app['version_name'], app['version_code']) == ('4.0.32', 40032)
    assert (release['version'], release['version_code']) == ('4.0.32', 40032)

def test_premium_lkg_and_free_isolation():
    account = text('android-source/BlueVpnAccountManager.kt')
    block = account[account.index('fun preferredServerGuids'):account.index('fun entitlementPoolFingerprint')]
    assert 'if (exact.isNotEmpty() || !active(c)) return exact' in block
    assert 'val preservedPremium = usableServerGuids(premiumRows)' in block
    assert 'it !in freeServerGuids' in block
    assert 'subscriptionId !in freeSubscriptionGuids' in block
    assert 'if (id in allFreeSubscriptionGuids()) return false' in account

def test_pool_is_frozen_during_connection():
    engine = text('android-source/BlueVpnEngineManager.kt')
    home = text('android-source/BlueVpnHomeActivity.kt')
    assert 'fun freezeEntitlementPool' in engine
    assert 'fun candidateAllowedForConnection' in engine
    assert 'State.CONNECTED' in engine[engine.index('fun isPoolMutationBlocked'):engine.index('fun addListener')]
    assert 'BlueVpnAccountManager.isSubscriptionRefreshRunning()' in home
    assert 'BlueVpnEngineManager.freezeEntitlementPool(connectionOwnership)' in home
    assert 'BlueVpnEngineManager.freezeEntitlementPool(failoverQueue)' in home
    assert home.count('BlueVpnEngineManager.candidateAllowedForConnection(this, guid, profile.subscriptionId)') >= 2

def test_no_background_forced_subscription_sync():
    home = text('android-source/BlueVpnHomeActivity.kt')
    servers = text('android-source/BlueVpnServersActivity.kt')
    subs = text('android-source/BlueVpnSubscriptionsActivity.kt')
    ai = home[home.index('private fun runSmartSelection'):home.index('private fun prepareGuestFreeAccess')]
    assert 'BlueVpnAccountManager.sync(' not in ai
    assert 'syncManagedAccount(force = false)' in home
    assert 'refreshEntitlementState(force = false)' in servers
    refresh = servers[servers.index('private fun refreshEntitlementState'):servers.index('private fun updateTabs')]
    assert 'force = force' in refresh
    assert 'sync(false)' in subs
    assert 'if(BlueVpnEngineManager.isPoolMutationBlocked())' in subs
    account = text('android-source/BlueVpnAccountManager.kt')
    assert 'if (BlueVpnEngineManager.isPoolMutationBlocked()) return@synchronized' in account
    assert 'val r=BlueVpnAccountManager.sync(this@BlueVpnSubscriptionsActivity,force)' in subs

def test_connection_budget_is_bounded():
    home = text('android-source/BlueVpnHomeActivity.kt')
    assert 'scoredQueue.take(5)' in home
    assert 'maxCandidates = 5' in home
    assert 'round < 2' in home
    assert 'handler.postDelayed(attemptTimeout, 10_000L)' in home
    assert 'maxWaitMs: Long = 1_800L' in home
    assert 'SystemClock.elapsedRealtime() + 3_200L' in home

def test_ai_repair_is_local_and_bounded():
    home = text('android-source/BlueVpnHomeActivity.kt')
    account = text('android-source/BlueVpnAccountManager.kt')
    ai = home[home.index('private fun runSmartSelection'):home.index('private fun prepareGuestFreeAccess')]
    assert 'awaitEntitlementServers(' in ai
    assert 'timeoutMs = 6_000L' in ai
    repair = account[account.index('fun awaitEntitlementServers'):account.index('private fun reconcileSubscriptionMode')]
    assert 'sync(appContext, force = true)' not in repair
    assert 'ENTITLEMENT_REPAIR_COOLDOWN_MS' in repair
