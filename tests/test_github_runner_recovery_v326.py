from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_workflow_uses_explicit_runners_and_preserves_queue():
    text = (ROOT / ".github/workflows/build-apk.yml").read_text(encoding="utf-8")
    assert "default: ubuntu-22.04" in text
    assert "- ubuntu-24.04" in text
    assert "runs-on: ${{ inputs.runner || 'ubuntu-22.04' }}" in text
    assert "cancel-in-progress: false" in text
    assert "queue: max" in text
    assert "runs-on: ubuntu-latest" not in text


def test_deploy_bot_has_runner_fallback_and_filters_old_runs():
    text = (ROOT / "server/deploy_bot_runtime.py").read_text(encoding="utf-8")
    assert "recover_runner_acquisition_failure" in text
    assert "dispatch_workflow_on_runner" in text
    assert "runner_was_never_acquired" in text
    assert 'event="workflow_dispatch"' in text
    assert "run_id in previous_ids" in text
    assert 'FALLBACK_GITHUB_RUNNER' in text


def test_release_version_326():
    import json
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.26"
    assert release["version_code"] == 30026
