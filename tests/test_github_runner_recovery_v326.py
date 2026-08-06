"""Regression tombstone for the removed v3.0.26 runner-recovery experiment.

Kept at the historical path so hotfix overlays replace the old assertions even
when the deploy bot does not delete files that disappeared from a ZIP.
"""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_v326_dynamic_runner_recovery_is_disabled():
    workflow = (ROOT / ".github/workflows/build-apk.yml").read_text(encoding="utf-8")
    bot = (ROOT / "server/deploy_bot_runtime.py").read_text(encoding="utf-8")
    assert "runs-on: ubuntu-latest" in workflow
    assert "inputs.runner" not in workflow
    assert "recover_runner_acquisition_failure" not in bot
