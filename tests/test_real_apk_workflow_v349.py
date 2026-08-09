from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/build-apk.yml"


def test_release_version_349():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.51"
    assert release["version_code"] == 30051
    assert app["version_name"] == "3.0.51"
    assert app["version_code"] == 30051


def test_workflow_really_builds_and_signs_apks():
    text = WORKFLOW.read_text(encoding="utf-8")
    required = [
        "Checkout official v2rayNG source",
        "python scripts/prepare_android.py",
        "./gradlew :app:assemblePlaystoreRelease",
        "Align and sign APKs permanently",
        '"$BUILD_TOOLS/apksigner" verify',
        "dist/*.apk",
        "Publish signed APKs to GitHub Release",
    ]
    for marker in required:
        assert marker in text


def test_workflow_cannot_report_success_without_an_apk():
    text = WORKFLOW.read_text(encoding="utf-8")
    assert 'if [ "${#APKS[@]}" -eq 0 ]' in text
    assert 'echo "No release APK was generated."' in text
    assert "exit 1" in text
    assert "if-no-files-found: error" in text


def test_workflow_persists_new_android_sources():
    text = WORKFLOW.read_text(encoding="utf-8")
    assert "BlueVpnAdsCarouselView.kt" in text
    assert "BlueVpnTheme.kt" in text
    assert "BlueVpnLiveReporter.kt" in text
    assert "BlueVpnBootstrap.kt" in text
