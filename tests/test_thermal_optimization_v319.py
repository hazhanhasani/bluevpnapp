from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_tunnel_proof_is_sequential_and_cached():
    source = (ROOT / "android-source/BlueVpnAi.kt").read_text(encoding="utf-8")
    assert "Executors.newFixedThreadPool" not in source
    assert "for (target in targets)" in source
    assert "fun recentTunnelVerification" in source
    assert "PROBE_CACHE_MAX_AGE_MS = 125 * 1000L" in source


def test_only_background_reporter_owns_periodic_heartbeat():
    reporter = (ROOT / "android-source/BlueVpnLiveReporter.kt").read_text(encoding="utf-8")
    home = (ROOT / "android-source/BlueVpnHomeActivity.kt").read_text(encoding="utf-8")
    assert "ACTIVE_DELAY_SECONDS = 75L" in reporter
    assert "SCREEN_OFF_DELAY_SECONDS = 90L" in reporter
    assert "POWER_SAVE_DELAY_SECONDS = 120L" in reporter
    assert "BlueVpnAi.heartbeat(" in reporter
    assert "BlueVpnAi.heartbeat(" not in home


def test_ui_and_backend_use_low_power_live_windows():
    home = (ROOT / "android-source/BlueVpnHomeActivity.kt").read_text(encoding="utf-8")
    backend = (ROOT / "server/blueai.py").read_text(encoding="utf-8")
    assert "handler.postDelayed(this, 2_000L)" in home
    assert "setOrbPulseEnabled(state == OrbVisualState.CONNECTING)" in home
    assert "aiHealthCheckAt < 180_000L" in home
    assert "LIVE_TTL_SECONDS = 180" in backend
    assert "LIVE_PROBE_MAX_AGE_MS = 130_000" in backend
