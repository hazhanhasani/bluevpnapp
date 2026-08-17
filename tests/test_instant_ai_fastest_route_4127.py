from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
HOME = (ROOT / "android-source/BlueVpnHomeActivity.kt").read_text()
ROUTE = (ROOT / "android-source/BlueVpnRouteIntelligence.kt").read_text()

def test_connect_gesture_does_not_wait_for_foreground_sweep():
    assert "Connect-first policy" in HOME
    assert "startSmartConnectionWithCandidates(candidates, selectionMode)" in HOME
    assert "BlueVpnBackgroundOptimizer.markPending(this)" in HOME

def test_real_throughput_is_learned_and_ranked():
    assert "throughputEwmaBps" in ROUTE
    assert "fun recordThroughput" in ROUTE
    assert "سرعت واقعی" in ROUTE
    assert "BlueVpnRouteIntelligence.recordThroughput" in HOME

def test_known_bad_routes_do_not_win_first_attempt():
    assert "connectionReadyQueue" in HOME
    assert "BlueVpnPreferences.failedRecently" in HOME
    assert "BlueVpnRouteIntelligence.isCoolingDown" in HOME
