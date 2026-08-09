from pathlib import Path
import json

ROOT = Path(__file__).resolve().parents[1]
BOT = ROOT / "server" / "deploy_bot_runtime.py"


def test_release_version_331():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding" / "app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.50"
    assert release["version_code"] == 30050
    assert app["version_name"] == "3.0.50"
    assert app["version_code"] == 30050


def test_bot_has_manual_and_automatic_unlock_paths():
    text = BOT.read_text(encoding="utf-8")
    assert '"🔓 آزادسازی عملیات"' in text
    assert 'CommandHandler("unlock", unlock)' in text
    assert "_deploy_lock_watchdog_loop" in text
    assert "RUNNER_QUEUE_TIMEOUT_SECONDS" in text
    assert "_cancel_github_run" in text


def test_token_guard_prevents_old_task_from_clearing_new_job():
    text = BOT.read_text(encoding="utf-8")
    assert "ACTIVE_JOB_TOKENS.get(job_key) != token" in text
    assert "_clear_job(job_key, job_token)" in text
    assert "name=f\"bluevpn-deploy-{job_key}-{job_token[:6]}\"" in text


def test_new_zip_can_replace_waiting_runner_job():
    text = BOT.read_text(encoding="utf-8")
    assert "_job_is_waiting(old_status)" in text
    assert 'reason="جایگزینی با ZIP جدید"' in text
    assert "ZIP جدید جای آن نصب می‌شود" in text
