from pathlib import Path
import base64
import json
import re

ROOT = Path(__file__).resolve().parents[1]

def read(path: str) -> str:
    return (ROOT / path).read_text(encoding='utf-8')

def require(ok: bool, message: str) -> None:
    if not ok:
        raise SystemExit('FAIL: ' + message)

account = read('android-source/BlueVpnAccountManager.kt')
home = read('android-source/BlueVpnHomeActivity.kt')
servers = read('android-source/BlueVpnServersActivity.kt')
subs = read('android-source/BlueVpnSubscriptionsActivity.kt')
engine = read('android-source/BlueVpnEngineManager.kt')
prepare = read('scripts/prepare_android.py')
plugin = read('bluevpn-manager/bluevpn-manager.php')
app = json.loads(read('branding/app.json'))
release = json.loads(read('release.json'))

pool = account[account.index('fun preferredServerGuids'):account.index('fun entitlementPoolFingerprint')]
checks = [
    (app['version_name'] == '4.0.32' and app['version_code'] == 40032, 'Android version must be 4.0.32/40032'),
    (release['version'] == '4.0.32' and release['version_code'] == 40032, 'release metadata must be 4.0.32/40032'),
    ('Version: 4.0.32' in plugin and "BLUEVPN_MANAGER_VERSION', '4.0.32'" in plugin, 'WordPress Manager version must match 4.0.32'),
    ('val exact = usableServerGuids(entitlementSubscriptionGuids(c))' in pool, 'Premium must prefer exact active pool'),
    ('if (exact.isNotEmpty() || !active(c)) return exact' in pool, 'Free mode must return only exact Free pool'),
    ('val preservedPremium = usableServerGuids(premiumRows)' in pool, 'Premium LKG pool must exist'),
    ('val freeServerGuids = allFreeServerGuids()' in pool and 'it !in freeServerGuids' in pool, 'Premium fallback must exclude Free server GUIDs'),
    ('subscriptionId.isBlank() || subscriptionId !in freeSubscriptionGuids' in pool, 'Premium fallback must exclude Free subscription IDs'),
    ('MmkvManager.decodeAllServerList()' in pool, 'pre-Free Premium compatibility fallback must remain available'),
    ('if (id in allFreeSubscriptionGuids()) return false' in account, 'candidateAllowed must reject Free row in Premium LKG bridge'),
    ('ENTITLEMENT_REPAIR_COOLDOWN_MS = 20_000L' in account, 'local entitlement repair must have cooldown'),
    ('if (BlueVpnEngineManager.isPoolMutationBlocked())' in account, 'local repair must not mutate pool while connecting/connected'),
    ('timeoutMs: Long = 7_000L' in account, 'entitlement repair must be bounded'),
    ('sync(appContext, force = true)' not in account[account.index('fun awaitEntitlementServers'):account.index('private fun reconcileSubscriptionMode')], 'entitlement repair must not force provider/account sync'),
    ('refreshEntitlementState(force = false)' in servers, 'Locations entry must be snapshot/non-forced'),
    ('force = force' in servers[servers.index('private fun refreshEntitlementState'):servers.index('private fun updateTabs')], 'Locations refresh must honor requested force flag'),
    ('BlueVpnEngineManager.isPoolMutationBlocked()' in servers, 'Locations must not refresh pool while engine owns it'),
    ('if (BlueVpnEngineManager.isPoolMutationBlocked()) return@synchronized' in account, 'subscription import must refuse to mutate an active connection pool'),
    ('subscriptionRefreshRunning = true' in account[account.index('private fun reconcileSubscriptionMode'):account.index('fun startFreeSession')], 'subscription mutation ownership must cover the whole reconcile transaction'),
    ('handler.postDelayed({if(!isFinishing&&!isDestroyed)sync(false)},320L)' in subs, 'Account screen resume must not force provider sync'),
    ('if(BlueVpnEngineManager.isPoolMutationBlocked())' in subs and 'val r=BlueVpnAccountManager.sync(this@BlueVpnSubscriptionsActivity,force)' in subs, 'Account sync must fully defer while the active tunnel owns the pool'),
    ('BlueVpnAccountManager.sync(\n                            this@BlueVpnHomeActivity,\n                            force = true' not in home[home.index('private fun runSmartSelection'):home.index('private fun applyAiDecision') if 'private fun applyAiDecision' in home else home.index('private fun prepareGuestFreeAccess')], 'BlueAI must not force account/provider sync'),
    ('syncManagedAccount(force = false)' in home[home.index('private fun startStartupOptimization'):home.index('private fun startStartupServerTest')], 'startup account sync must be non-forced'),
    ('if (BlueVpnAccountManager.isSubscriptionRefreshRunning())' in home, 'connect must not race an already-running v2rayNG subscription import'),
    ('BlueVpnEngineManager.freezeEntitlementPool(connectionOwnership)' in home, 'connect must freeze the entitlement snapshot before async candidate loading'),
    ('freezeEntitlementPool(failoverQueue)' in home, 'connection must freeze candidate ownership'),
    ('candidateAllowedForConnection(this, guid, profile.subscriptionId)' in home, 'connection attempts must validate against frozen pool'),
    ('scoredQueue.take(5)' in home, 'failover queue must be bounded to five candidates'),
    ('maxCandidates = 5' in home, 'candidate preparation must be bounded'),
    ('round < 2' in home and 'از ۲' in home, 'independent tunnel verifier must be bounded to two rounds'),
    ('handler.postDelayed(attemptTimeout, 10_000L)' in home, 'core start watchdog must be bounded to ten seconds'),
    ('maxWaitMs: Long = 1_800L' in home and 'SystemClock.elapsedRealtime() + 3_200L' in home, 'local proxy/internet proof must use bounded fast windows'),
    ('BlueVpnEngineManager.markConnected()' in home, 'verified tunnel must publish CONNECTED state'),
    ('clearEntitlementPoolFreeze()' in home, 'disconnect/failure must release frozen pool'),
    ('frozenEntitlementServerGuids' in engine and 'fun freezeEntitlementPool' in engine, 'engine must own frozen entitlement snapshot'),
    ('fun candidateAllowedForConnection' in engine, 'engine must validate exact frozen GUID'),
    ('fun isPoolMutationBlocked()' in engine and 'State.CONNECTED' in engine[engine.index('fun isPoolMutationBlocked'):engine.index('fun addListener')], 'engine must block pool mutations through CONNECTED state'),
]
for ok, msg in checks:
    require(ok, msg)

embedded = {
    'BLUEVPN_HOME_ACTIVITY_B64': 'android-source/BlueVpnHomeActivity.kt',
    'BLUEVPN_ACCOUNT_MANAGER_B64': 'android-source/BlueVpnAccountManager.kt',
    'BLUEVPN_SERVERS_ACTIVITY_B64': 'android-source/BlueVpnServersActivity.kt',
    'BLUEVPN_SUBSCRIPTIONS_ACTIVITY_B64': 'android-source/BlueVpnSubscriptionsActivity.kt',
}
for const, path in embedded.items():
    match = re.search(rf'^{const} = "([^"]+)"$', prepare, re.M)
    require(match is not None, f'{const} must exist in prepare_android.py')
    require(base64.b64decode(match.group(1)).decode('utf-8') == read(path), f'{path} embedded source must match canonical')

print(f'OK: BlueVPN 4.0.32 connectivity core validated ({len(checks) + len(embedded)} checks)')
