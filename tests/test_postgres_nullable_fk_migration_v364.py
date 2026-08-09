from __future__ import annotations

import json
import os
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_release_version_364():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.79"
    assert release["version_code"] == 30079
    assert app["version_name"] == "3.0.79"
    assert app["version_code"] == 30079


def test_optional_otp_customer_foreign_key_has_no_synthetic_default(tmp_path):
    data_dir = tmp_path / "data"
    data_dir.mkdir()
    env = os.environ.copy()
    for name in list(env):
        if name.startswith("RAILWAY_") or name in {
            "DATABASE_URL",
            "DATABASE_PRIVATE_URL",
            "PGHOST",
            "PGPORT",
            "PGUSER",
            "PGPASSWORD",
            "PGDATABASE",
        }:
            env.pop(name, None)
    env.update(
        {
            "DATA_DIR": str(data_dir),
            "DB_REQUIRE_POSTGRES": "false",
            "ALLOW_SQLITE_FALLBACK": "true",
            "PYTHONPATH": str(ROOT),
        }
    )
    script = """
from server.database import _default_for_column
from server.models import OtpChallenge, CustomerSession

optional_fk = OtpChallenge.__table__.c.customer_id
required_fk = CustomerSession.__table__.c.customer_id
assert optional_fk.nullable is True
assert _default_for_column(optional_fk) is None
assert _default_for_column(required_fk) is None
print('nullable-fk-safe')
"""
    completed = subprocess.run(
        [sys.executable, "-c", script],
        cwd=ROOT,
        env=env,
        capture_output=True,
        text=True,
        timeout=60,
        check=False,
    )
    assert completed.returncode == 0, completed.stdout + completed.stderr
    assert "nullable-fk-safe" in completed.stdout


def test_migration_source_never_assigns_zero_to_nullable_foreign_keys():
    source = (ROOT / "server/database.py").read_text(encoding="utf-8")
    assert 'if bool(getattr(column, "nullable", True)):' in source
    assert 'if getattr(column, "foreign_keys", None):' in source
    assert "optional foreign keys such as otp_challenges.customer_id" in source
