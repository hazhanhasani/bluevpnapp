from __future__ import annotations
import base64, json, re
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def test_release_368():
    r=json.loads((ROOT/'release.json').read_text())
    a=json.loads((ROOT/'branding/app.json').read_text())
    assert r['version']=='3.0.70'
    assert r['version_code']==30070
    assert r['android_version']=='3.0.70'
    assert r['android_version_code']==30070
    assert a['version_name']=='3.0.70'
    assert a['version_code']==30070

def test_navigation_is_immediate_and_auto_unlocks():
    home=(ROOT/'android-source/BlueVpnHomeActivity.kt').read_text()
    exp=(ROOT/'android-source/BlueVpnExperience.kt').read_text()
    assert 'private val navigationUnlock = Runnable' in home
    assert 'handler.postDelayed(navigationUnlock, 520L)' in home
    assert 'intervalMs = 220L' in home
    assert 'overridePendingTransition(0, 0)' in home
    assert 'DEFAULT_NAVIGATION_WINDOW_MS = 360L' in exp
    assert 'DEFAULT_CLICK_WINDOW_MS = 280L' in exp

def test_fast_free_connection_timer_and_probe():
    home=(ROOT/'android-source/BlueVpnHomeActivity.kt').read_text()
    assert 'beginFreeTimerOnCoreStart()' not in home
    assert 'BlueVpnAccountManager.startFreeSession(this)' in home
    assert 'SystemClock.elapsedRealtime() + 1_050L' in home
    assert 'connection.connectTimeout = 650' in home
    assert 'connection.readTimeout = 650' in home
    assert 'handler.postDelayed(attemptTimeout, 2_100L)' in home

def test_secondary_screens_are_static_first_frame():
    for name in ['BlueVpnSettingsActivity.kt','BlueVpnSubscriptionsActivity.kt','BlueVpnServersActivity.kt']:
        text=(ROOT/'android-source'/name).read_text()
        assert 'window.setWindowAnimations(0)' in text
        assert 'BlueVpnDynamicBackgroundView(this)' not in text

def test_embedded_sources_match():
    script=(ROOT/'scripts/prepare_android.py').read_text()
    mapping={
      'BLUEVPN_HOME_ACTIVITY_B64':'BlueVpnHomeActivity.kt',
      'BLUEVPN_EXPERIENCE_B64':'BlueVpnExperience.kt',
      'BLUEVPN_SETTINGS_ACTIVITY_B64':'BlueVpnSettingsActivity.kt',
      'BLUEVPN_SUBSCRIPTIONS_ACTIVITY_B64':'BlueVpnSubscriptionsActivity.kt',
      'BLUEVPN_SERVERS_ACTIVITY_B64':'BlueVpnServersActivity.kt',
    }
    for const,fn in mapping.items():
        m=re.search(rf'{const} = "([^"]+)"',script)
        assert m
        assert base64.b64decode(m.group(1)).decode()==(ROOT/'android-source'/fn).read_text()
