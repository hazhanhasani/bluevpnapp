from pathlib import Path
import base64,json,re
ROOT=Path(__file__).resolve().parents[1]

def test_release_361():
    release=json.loads((ROOT/'release.json').read_text())
    app=json.loads((ROOT/'branding/app.json').read_text())
    assert release['version']=='3.0.75'
    assert release['version_code']==30075
    assert app['version_name']=='3.0.75'
    assert app['version_code']==30075

def test_logout_stops_tunnel_and_is_non_blocking():
    source=(ROOT/'android-source/BlueVpnAccountManager.kt').read_text()
    logout=source.split('fun logout(c: Context)',1)[1].split('private fun invalidateSession',1)[0]
    assert 'CoreServiceManager.stopVService(appContext)' in logout
    assert 'BlueVpnPreferences.clearConnected(appContext)' in logout
    assert 'backgroundExecutor.execute' in logout
    assert logout.index('CoreServiceManager.stopVService') < logout.index('.clear()')

def test_auth_forms_preserve_drafts_and_avoid_resume_rerender():
    source=(ROOT/'android-source/BlueVpnSubscriptionsActivity.kt').read_text()
    for key in ['draftPhone','draftOtpCode','draftEmail','draftPassword','draftBindingPhone','draftBindingCode']:
        assert key in source
    assert 'remember(this){draftEmail=it}' in source
    assert 'remember(this){draftBindingPhone=it}' in source
    assert 'else if(BlueVpnAccountManager.hasSession(this)!=renderedSessionState){render()}' in source
    assert '(materiallyChanged||force)&&currentFocus !is EditText' in source

def test_auth_returns_before_subscription_import():
    source=(ROOT/'android-source/BlueVpnAccountManager.kt').read_text()
    assert 'scheduleInstall(url)' in source
    assert 'subscriptionInstallExecutor.execute' in source
    assert 'if (forceSubscriptions)' in source
    assert 'reconcileSubscriptionMode(' in source

def test_server_otp_and_password_work_do_not_block():
    source=(ROOT/'server/main.py').read_text()
    assert '_otp_fast_hash' in source
    assert '_otp_code_matches' in source
    assert "password_hash='phone_otp_only$'+secrets.token_hex(32)" in source
    assert 'await asyncio.to_thread(password_hash,password)' in source
    assert 'await asyncio.to_thread(password_ok,password,customer.password_hash)' in source

def test_embedded_sources_match():
    script=(ROOT/'scripts/prepare_android.py').read_text()
    for const,name in [('BLUEVPN_ACCOUNT_MANAGER_B64','BlueVpnAccountManager.kt'),('BLUEVPN_SUBSCRIPTIONS_ACTIVITY_B64','BlueVpnSubscriptionsActivity.kt')]:
        m=re.search(rf'{const} = "([^"]+)"',script)
        assert m
        assert base64.b64decode(m.group(1)).decode()==(ROOT/'android-source'/name).read_text()
