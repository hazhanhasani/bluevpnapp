"""Regression tombstone for the flawed v3.0.27 queue experiment."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_v327_queue_experiment_is_removed():
    text = (ROOT / ".github/workflows/build-apk.yml").read_text(encoding="utf-8")
    assert "runs-on: ubuntu-latest" in text
    assert "queue: max" not in text
    assert "cancel-in-progress: true" in text
