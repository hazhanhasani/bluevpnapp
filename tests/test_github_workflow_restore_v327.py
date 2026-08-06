from pathlib import Path
import json

ROOT = Path(__file__).resolve().parents[1]


def test_workflow_restores_known_good_static_runner():
    text = (ROOT / ".github/workflows/build-apk.yml").read_text(encoding="utf-8")
    assert "runs-on: ubuntu-latest" in text
    assert "workflow_dispatch:" in text
    assert "inputs.runner" not in text
    assert "ubuntu-22.04" not in text
    assert "ubuntu-24.04" not in text
    assert "queue: max" in text
    assert "cancel-in-progress: false" in text


def test_deploy_bot_does_not_dispatch_runner_fallback():
    text = (ROOT / "server/deploy_bot_runtime.py").read_text(encoding="utf-8")
    assert "recover_runner_acquisition_failure" not in text
    assert "dispatch_workflow_on_runner" not in text
    assert "runner_was_never_acquired" not in text
    assert 'BUILD_TRIGGER_MODE = "verified-source-commit-push-stable-workflow"' in text


def test_release_version_327():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.27"
    assert release["version_code"] == 30027
