from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_release_version_343():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.48"
    assert release["version_code"] == 30048
    assert app["version_name"] == "3.0.48"
    assert app["version_code"] == 30048
    assert "mobile-first-admin-dashboard" in release["features"]


def test_admin_has_mobile_navigation_drawer():
    html = (ROOT / "server/templates/admin.html").read_text(encoding="utf-8")
    assert 'class="secondary icon-button mobile-nav-toggle"' in html
    assert 'id="admin-tabs"' in html
    assert 'class="mobile-nav-search"' in html
    assert 'data-nav-close' in html
    assert "function setNavOpen(open)" in html
    assert "document.body.classList.toggle('nav-open', open)" in html


def test_admin_tables_receive_accessible_mobile_labels():
    html = (ROOT / "server/templates/admin.html").read_text(encoding="utf-8")
    assert "function enhanceResponsiveTables(root = document)" in html
    assert "cell.dataset.label = labels[index] || ''" in html
    assert "table.classList.add('responsive-table')" in html


def test_mobile_styles_remove_desktop_mode_requirement():
    css = (ROOT / "server/static/style.css").read_text(encoding="utf-8")
    required = [
        "/* BlueVPN 3.0.48 — mobile-first responsive admin */",
        "@media(max-width:860px)",
        ".tabs.open{transform:translateX(0)}",
        "table.responsive-table thead{display:none}",
        "content:attr(data-label)",
        ".form-grid,.manual-mini-form,.manual-link-form,.inline-form,.backup-form",
        "padding:14px 13px calc(24px + env(safe-area-inset-bottom))",
        "overflow-x:hidden",
    ]
    for item in required:
        assert item in css


def test_desktop_navigation_is_preserved():
    css = (ROOT / "server/static/style.css").read_text(encoding="utf-8")
    assert ".tabs{position:sticky" in css
    assert "@media(max-width:860px)" in css
