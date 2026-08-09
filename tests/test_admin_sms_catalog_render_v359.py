from __future__ import annotations

import json
from pathlib import Path

from fastapi.testclient import TestClient

from server.main import app

ROOT = Path(__file__).resolve().parents[1]


def test_release_version_359():
    release=json.loads((ROOT/"release.json").read_text(encoding="utf-8"))
    app_meta=json.loads((ROOT/"branding/app.json").read_text(encoding="utf-8"))
    assert release["version"]=="3.0.60"
    assert release["version_code"]==30060
    assert app_meta["version_name"]=="3.0.60"
    assert app_meta["version_code"]==30060


def test_admin_initializes_sms_provider_catalog_before_template_render():
    source=(ROOT/"server/main.py").read_text(encoding="utf-8")
    admin_start=source.index("def admin(request:Request,db:Session=Depends(get_db)):")
    context_pos=source.index("'sms_provider_lines':sms_provider_lines",admin_start)
    init_pos=source.index("sms_provider_lines,sms_provider_patterns=_sms_provider_cache(s)",admin_start)
    assert init_pos < context_pos


def test_authenticated_admin_dashboard_renders_without_500():
    with TestClient(app) as client:
        login=client.post(
            "/admin/login",
            data={"username":"admin","password":"CHANGE_THIS_PASSWORD"},
            follow_redirects=False,
        )
        assert login.status_code==303
        response=client.get("/admin")
        assert response.status_code==200
        assert "مرکز پیامک بلوپنل" in response.text
        assert "Internal Server Error" not in response.text
