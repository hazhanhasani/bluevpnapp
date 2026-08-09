from pathlib import Path
import json

ROOT = Path(__file__).resolve().parents[1]


def source(name: str) -> str:
    return (ROOT / name).read_text(encoding="utf-8")


def test_bluevpn_ui_uses_runtime_boundary():
    home = source("android-source/BlueVpnHomeActivity.kt")
    account = source("android-source/BlueVpnAccountManager.kt")
    assert "BlueVpnEngineManager.start" in home
    assert "BlueVpnEngineManager.stop" in home
    assert "import com.v2ray.ang.core.CoreServiceManager" not in home
    assert "import com.v2ray.ang.core.CoreServiceManager" not in account


def test_only_engine_boundary_imports_legacy_core_manager():
    direct_imports = []
    for path in (ROOT / "android-source").glob("*.kt"):
        if "import com.v2ray.ang.core.CoreServiceManager" in path.read_text(encoding="utf-8"):
            direct_imports.append(path.name)
    assert direct_imports == ["BlueVpnEngineManager.kt"]


def test_sing_box_is_pinned_and_built_for_supported_abis():
    app = json.loads(source("branding/app.json"))
    workflow = source(".github/workflows/build-apk.yml")
    assert app["sing_box_ref"] == "v1.13.16"
    assert "steps.config.outputs.sing_box_ref" in workflow
    assert "arm64-v8a/libbluevpn_singbox.so" in workflow
    assert "armeabi-v7a intentionally uses the Xray fallback" in workflow


def test_second_gomobile_aar_is_not_packaged():
    workflow = source(".github/workflows/build-apk.yml")
    assert "libbox.aar" not in workflow
    assert "libbluevpn_singbox.so" in workflow


def test_sing_box_profile_is_checked_natively():
    runtime = source("android-source/BlueVpnSingBoxProcess.kt")
    assert 'root.has("inbounds")' in runtime
    assert 'root.has("outbounds")' in runtime
    assert 'listOf("check", "-c"' in runtime
    assert "VERIFIED_MARKER" in runtime


def test_phase_one_keeps_single_tun_owner():
    manager = source("android-source/BlueVpnEngineManager.kt")
    assert "Do not launch a second TUN-capable process" in manager
    assert "CoreServiceManager.startVService" in manager
    assert "CoreServiceManager.stopVService" in manager
