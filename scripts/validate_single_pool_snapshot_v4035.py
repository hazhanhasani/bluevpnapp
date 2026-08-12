from pathlib import Path
import json

ROOT = Path(__file__).resolve().parents[1]

def require(condition, message):
    if not condition:
        raise AssertionError(message)

home = (ROOT/'android-source/BlueVpnHomeActivity.kt').read_text(encoding='utf-8')
loc = (ROOT/'android-source/BlueVpnLocationUtil.kt').read_text(encoding='utf-8')
acct = (ROOT/'android-source/BlueVpnAccountManager.kt').read_text(encoding='utf-8')
smart = (ROOT/'android-source/BlueVpnSmartSelector.kt').read_text(encoding='utf-8')
app = json.loads((ROOT/'branding/app.json').read_text(encoding='utf-8'))
release = json.loads((ROOT/'release.json').read_text(encoding='utf-8'))

checks = {
    'version': app['version_name'] == '4.0.35' and app['version_code'] == 40035,
    'release': release['version'] == '4.0.35' and release['android_version_code'] == 40035,
    'fast_snapshot_published': 'publishFastCandidateSnapshot(context, ranked)' in loc,
    'fast_snapshot_dirty': 'contextCandidateCacheDirty = true' in loc,
    'trusted_fast_rank': 'BlueVpnSmartSelector.rankTrusted(context, result)' in loc,
    'no_stale_home_profile': 'val profile = selected?.profile' in home and 'selectedAllowed' not in home,
    'empty_pool_ai_clear': 'در انتظار دریافت سرورهای مجاز پلن فعلی' in home,
    'smart_summary_bound_to_pool': 'val currentPool = BlueVpnLocationUtil.cachedCandidates(context)' in smart,
    'url_canonicalization': 'sameSubscriptionUrl' in acct and 'canonicalSubscriptionUrl' in acct,
    'premium_owner_mapping': 'rememberPremiumOwnerSubscriptions' in acct and 'premiumOwnedSubscriptionGuids' in acct,
    'premium_owned_fallback': 'val owned = usableServerGuids(premiumOwnedSubscriptionGuids(c))' in acct,
    'no_disable_all_premium': 'if (active(c) && keep.isEmpty()) return 0' in acct,
    'transactional_stale_disable': 'Transactional swap: stale Premium rows are disabled only after' in acct,
    'auto_failure_wording': 'مسیر قابل اتصال پیدا نشد' in home,
}
for name, ok in checks.items():
    require(ok, f'4.0.35 regression check failed: {name}')
print(f'BlueVPN 4.0.35 single-pool snapshot validation passed ({len(checks)}/{len(checks)}).')
