from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = (ROOT / ".github/workflows/build-apk.yml").read_text(encoding="utf-8")


def test_sing_box_is_built_only_for_supported_android_arm64_runtime():
    assert "CGO_ENABLED=0" in WORKFLOW
    assert "GOOS=android" in WORKFLOW
    assert "GOARCH=arm64" in WORKFLOW
    assert "GOARCH=arm GOARM=7" not in WORKFLOW
    assert "-buildmode=pie" not in WORKFLOW


def test_32_bit_apk_keeps_explicit_xray_fallback():
    assert "armeabi-v7a intentionally uses the Xray fallback" in WORKFLOW
    assert 'rm -f "$ARM64_OUTPUT" "$ARMV7_OUTPUT"' in WORKFLOW


def test_failure_report_uses_durable_stage_file_and_correct_log():
    assert '.bluevpn-build-stage' in WORKFLOW
    assert 'stage_file = Path(".bluevpn-build-stage")' in WORKFLOW
    assert 'LOG_FILE="singbox-build.log"' in WORKFLOW
    assert 'Log: {log_path.name}' in WORKFLOW
