from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


def test_android_disconnect_revokes_every_delayed_start():
    intent = (ROOT / "android-source/BlueVpnConnectionIntent.kt").read_text()
    controller = (ROOT / "android-source/BlueVpnSystemController.kt").read_text()
    home = (ROOT / "android-source/BlueVpnHomeActivity.kt").read_text()

    assert "KEY_DESIRED" in intent and "KEY_GENERATION" in intent
    assert "isCurrent(app, generation)" in controller
    assert "requestDisconnect(this)" in home
    assert "requestConnect(this)" in home
    assert "if (!BlueVpnConnectionIntent.isConnectionDesired(app)) return" in controller
    prepare = (ROOT / "scripts/prepare_android.py").read_text()
    assert 'BlueVpnConnectionIntent.kt' in prepare


def test_windows_disconnect_invalidates_inflight_connect():
    source = (ROOT / "bluevpn-windows/Services/ConnectionOrchestrator.cs").read_text()
    assert "_connectionDesired = false" in source
    assert "Interlocked.Increment(ref _connectionIntentGeneration)" in source
    assert "EnsureConnectionDesired(intentGeneration" in source
