from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_public_landing_is_truthful_and_complete():
    template = (ROOT / "server/templates/landing.html").read_text(encoding="utf-8")
    lowered = template.lower()
    assert "مدیریت یکپارچه حساب، سفارش، پرداخت و پشتیبانی" in template
    assert "پیامک فقط برای احراز هویت و اطلاع‌رسانی خدمات" in template
    assert "/terms" in template
    assert "/privacy" in template
    assert "/refund-policy" in template
    assert "/contact" in template
    assert "vpn" not in lowered
    assert "فیلترشکن" not in template


def test_public_routes_and_indexing_support_exist():
    main = (ROOT / "server/main.py").read_text(encoding="utf-8")
    for route in (
        "@app.get('/',response_class=HTMLResponse)",
        "@app.get('/terms',response_class=HTMLResponse)",
        "@app.get('/privacy',response_class=HTMLResponse)",
        "@app.get('/refund-policy',response_class=HTMLResponse)",
        "@app.get('/contact',response_class=HTMLResponse)",
        "@app.get('/robots.txt')",
        "@app.get('/sitemap.xml')",
    ):
        assert route in main


def test_public_site_configuration_is_documented():
    env = (ROOT / ".env.example").read_text(encoding="utf-8")
    for key in (
        "PUBLIC_SITE_URL",
        "PUBLIC_SITE_NAME",
        "PUBLIC_SUPPORT_PHONE",
        "PUBLIC_SUPPORT_EMAIL",
        "PUBLIC_BUSINESS_OWNER",
        "PUBLIC_BUSINESS_ADDRESS",
        "ENAMAD_VERIFY_URL",
        "ENAMAD_LOGO_URL",
    ):
        assert key in env


def test_public_api_docs_are_disabled_by_default():
    main = (ROOT / "server/main.py").read_text(encoding="utf-8")
    assert "PUBLIC_API_DOCS=env_bool('PUBLIC_API_DOCS',False)" in main
    assert "title='BluePanel Digital Services Platform'" in main
    assert "'service':'bluepanel-platform'" in main
