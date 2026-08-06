from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BOT = ROOT / "server" / "deploy_bot_runtime.py"
WORKFLOW = ROOT / ".github" / "workflows" / "build-apk.yml"


def test_workflow_supports_manual_dispatch():
    text = WORKFLOW.read_text(encoding="utf-8")
    assert "workflow_dispatch:" in text


def test_bot_uses_explicit_workflow_dispatch_endpoint():
    text = BOT.read_text(encoding="utf-8")
    assert "/actions/workflows/{GITHUB_WORKFLOW}/dispatches" in text
    assert 'json_body={"ref": GIT_BRANCH}' in text
    assert "ensure_workflow_enabled" in text
    assert "Actions: write" in text


def test_bot_no_longer_creates_empty_trigger_commits():
    text = BOT.read_text(encoding="utf-8")
    assert "trigger_build_by_empty_commit" not in text
    assert '"commit",\n            "--allow-empty"' not in text


def test_new_run_selection_excludes_existing_run_ids():
    text = BOT.read_text(encoding="utf-8")
    assert 'int(run.get("id") or 0) not in previous_ids' in text
    assert "_matching_commit_runs" in text


def test_status_detects_unbuilt_branch_head():
    text = BOT.read_text(encoding="utf-8")
    assert "Commit فعلی شاخه" in text
    assert "Commit آخرین Build" in text
    assert "سورس GitHub از آخرین Build جدیدتر است" in text
    assert '"🚀 ساخت فوری"' in text
