from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")


def test_account_install_keeps_context_for_subscription_intelligence():
    src = read("android-source/BlueVpnAccountManager.kt")
    assert "scheduleInstall(c, url)" in src
    assert "private fun scheduleInstall(context: Context, url: String)" in src
    assert "runCatching { install(appContext, url) }" in src
    assert "private fun install(c: Context, url: String)" in src
    assert "recommendedUserAgent(context = c, url = url)" in src
    assert "recommendedUserAgent(c = c, url = url)" not in src


def test_home_imports_route_intelligence_used_by_runtime():
    src = read("android-source/BlueVpnHomeActivity.kt")
    assert "import com.v2ray.ang.bluevpn.BlueVpnRouteIntelligence" in src
    assert "BlueVpnRouteIntelligence.recordSuccess(" in src
    assert "BlueVpnRouteIntelligence.recordFailure(" in src
    assert "BlueVpnRouteIntelligence.recordExitTrace(" in src


def test_generator_copies_route_intelligence_source():
    src = read("scripts/prepare_android.py")
    assert 'bluevpn_dir / "BlueVpnRouteIntelligence.kt"' in src
    assert 'ROOT / "android-source/BlueVpnRouteIntelligence.kt"' in src
