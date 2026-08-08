from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_official_enamad_is_zero_config_and_exact():
    main = (ROOT / "server/main.py").read_text(encoding="utf-8")
    template = (ROOT / "server/templates/landing.html").read_text(encoding="utf-8")
    assert "DEFAULT_ENAMAD_ID='748781'" in main
    assert "DEFAULT_ENAMAD_CODE='HuvEauyphrDRR17dhwoisDFNoMFMkDC0'" in main
    assert "https://trustseal.enamad.ir/?id={enamad_id}&Code={enamad_code}" in main
    assert "https://trustseal.enamad.ir/logo.aspx?id={enamad_id}&Code={enamad_code}" in main
    assert 'referrerpolicy="origin"' in template
    assert 'code="{{ enamad_code }}"' in template


def test_enamad_environment_values_are_optional_overrides():
    main = (ROOT / "server/main.py").read_text(encoding="utf-8")
    env = (ROOT / ".env.example").read_text(encoding="utf-8")
    assert "os.getenv('ENAMAD_VERIFY_URL') or default_verify" in main
    assert "os.getenv('ENAMAD_LOGO_URL') or default_logo" in main
    assert "official eNamad seal for bot.blluepanel.ir is built in automatically" in env
