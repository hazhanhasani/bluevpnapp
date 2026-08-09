from pathlib import Path
import json

ROOT = Path(__file__).resolve().parents[1]
BOT = ROOT / "server" / "deploy_bot_runtime.py"
WORKFLOW = ROOT / ".github" / "workflows" / "build-apk.yml"


def test_release_version_331():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding" / "app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.57"
    assert release["version_code"] == 30057
    assert app["version_name"] == "3.0.57"
    assert app["version_code"] == 30057


def test_workflow_accepts_repository_dispatch():
    text = WORKFLOW.read_text(encoding="utf-8")
    assert "repository_dispatch:" in text
    assert "- bluevpn_build" in text
    assert "BLUEVPN_TARGET_SHA" in text
    assert "github.event.client_payload.target_sha" in text
    assert 'git checkout -B "${BLUEVPN_TARGET_BRANCH}" "${BLUEVPN_TARGET_SHA}"' in text


def test_bot_uses_contents_write_repository_dispatch_first():
    text = BOT.read_text(encoding="utf-8")
    assert 'f"/repos/{OWNER}/{REPO}/dispatches"' in text
    assert '"event_type": GITHUB_REPOSITORY_DISPATCH_EVENT' in text
    assert '"target_sha": commit_sha' in text
    assert "مجوز Contents: write" in text
    assert "repository_result = await dispatch_repository_event(commit_sha)" in text


def test_bot_keeps_workflow_dispatch_as_fallback():
    text = BOT.read_text(encoding="utf-8")
    assert "/actions/workflows/{GITHUB_WORKFLOW}/dispatches" in text
    assert 'result["fallback_from"] = "repository_dispatch"' in text
    assert "هیچ‌یک از دو روش ساخت GitHub اجرا نشد" in text
