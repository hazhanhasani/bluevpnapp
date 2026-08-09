from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = (ROOT / ".github/workflows/build-apk.yml").read_text(encoding="utf-8")


def test_armv7_sing_box_uses_android_ndk_external_linker():
    assert "armv7a-linux-androideabi24-clang" in WORKFLOW
    assert "CGO_ENABLED=1 GOOS=android GOARCH=arm GOARM=7" in WORKFLOW
    assert "CGO_ENABLED=0 GOOS=android GOARCH=arm GOARM=7" not in WORKFLOW


def test_arm64_sing_box_uses_pinned_android_ndk_toolchain():
    assert "aarch64-linux-android24-clang" in WORKFLOW
    assert "CGO_ENABLED=1 GOOS=android GOARCH=arm64" in WORKFLOW


def test_sing_box_failure_has_real_stage_and_log():
    assert 'BUILD_STAGE=sing-box-native-build' in WORKFLOW
    assert 'singbox-build.log' in WORKFLOW
    assert "BlueVPN-sing-box-build-log" in WORKFLOW
    assert 'Path("singbox-build.log")' in WORKFLOW


def test_gradle_stage_is_persisted_for_later_failure_steps():
    assert 'echo "BUILD_STAGE=gradle-compile" >> "$GITHUB_ENV"' in WORKFLOW
