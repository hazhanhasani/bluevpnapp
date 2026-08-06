from pathlib import Path
import json

ROOT = Path(__file__).resolve().parents[1]


def test_only_latest_branch_build_is_kept():
    workflow = (ROOT / ".github/workflows/build-apk.yml").read_text(encoding="utf-8")
    assert "group: bluevpn-release-${{ github.ref }}" in workflow
    assert "cancel-in-progress: true" in workflow
    assert "queue: max" not in workflow
    assert "runs-on: ubuntu-latest" in workflow


def test_release_version_current():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.29"
    assert release["version_code"] == 30029
    assert app["version_name"] == "3.0.29"
    assert app["version_code"] == 30029
