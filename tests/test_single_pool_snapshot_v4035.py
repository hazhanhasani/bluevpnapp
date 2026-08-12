from pathlib import Path
import json
ROOT = Path(__file__).resolve().parents[1]

def text(rel):
    return (ROOT/rel).read_text(encoding='utf-8')

def test_version_4035():
    app=json.loads(text('branding/app.json'))
    assert app['version_name']=='4.0.35'
    assert app['version_code']==40035

def test_cold_connect_publishes_same_ui_snapshot():
    loc=text('android-source/BlueVpnLocationUtil.kt')
    assert 'publishFastCandidateSnapshot(context, ranked)' in loc
    assert 'BlueVpnSmartSelector.rankTrusted(context, result)' in loc

def test_home_does_not_resurrect_stale_mmkv_selection():
    home=text('android-source/BlueVpnHomeActivity.kt')
    assert 'val profile = selected?.profile' in home
    assert 'selectedAllowed' not in home
    assert 'در انتظار دریافت سرورهای مجاز پلن فعلی' in home

def test_smart_summary_uses_current_pool_generation():
    smart=text('android-source/BlueVpnSmartSelector.kt')
    assert 'val currentPool = BlueVpnLocationUtil.cachedCandidates(context)' in smart
    assert 'currentPool.isEmpty()' in smart

def test_premium_pool_recovery_is_account_scoped():
    acct=text('android-source/BlueVpnAccountManager.kt')
    assert 'rememberPremiumOwnerSubscriptions' in acct
    assert 'premiumOwnedSubscriptionGuids' in acct
    assert 'val owned = usableServerGuids(premiumOwnedSubscriptionGuids(c))' in acct
    assert 'if (active(c) && keep.isEmpty()) return 0' in acct

def test_subscription_url_comparison_is_stable():
    acct=text('android-source/BlueVpnAccountManager.kt')
    assert 'canonicalSubscriptionUrl' in acct
    assert 'sameSubscriptionUrl' in acct
