from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_release_version_346():
    release=json.loads((ROOT/'release.json').read_text(encoding='utf-8'))
    app=json.loads((ROOT/'branding/app.json').read_text(encoding='utf-8'))
    assert release['version']=='4.0.0'
    assert release['version_code']==40000
    assert app['version_name']=='4.0.0'
    assert app['version_code']==40000


def test_admin_post_refresh_is_recovered_without_raw_405():
    main=(ROOT/'server/main.py').read_text(encoding='utf-8')
    assert "request.method in {'GET','HEAD'}" in main
    assert "path.startswith('/admin/')" in main
    assert "not path.startswith('/admin/api/')" in main
    assert "RedirectResponse('/admin?recovered=1',status_code=302)" in main
    assert "Cache-Control','no-store, private'" in main
    assert 'صفحه مدیریت پس از رفرش بازیابی شد.' in main
