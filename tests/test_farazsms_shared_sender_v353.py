from __future__ import annotations

from pathlib import Path

from server.models import SmsSetting
from server.security import encrypt
from server.sms import _sender, sms_notification_ready, sms_setting_ready

ROOT = Path(__file__).resolve().parents[1]


def test_shared_sender_requires_provider_line_number():
    setting = SmsSetting(
        id=1,
        active=True,
        notification_active=True,
        api_key_enc=encrypt("api-token"),
        from_number="",
        pattern_code="auth-pattern",
        parameter_name="code",
    )
    assert _sender(setting) == ""
    assert sms_setting_ready(setting) is False
    assert sms_notification_ready(setting) is False


def test_sms_settings_do_not_duplicate_login_pattern_fields():
    html = (ROOT / "server/templates/admin.html").read_text(encoding="utf-8")
    main = (ROOT / "server/main.py").read_text(encoding="utf-8")
    assert 'name="sender_mode"' not in html
    assert "شماره خط ارسال ایران‌پیامک (line_number)" in html
    assert 'name="pattern_code"' not in html
    assert 'name="parameter_name"' not in html
    assert "پترن ورود از بخش «پترن‌های پیامکی» خوانده می‌شود" in html
    assert "pattern_code:str=Form" not in main
    assert "parameter_name:str=Form" not in main
    assert "setting.pattern_code=auth_template.pattern_code" in main


def test_send_payload_uses_optional_line_number():
    source = (ROOT / "server/sms.py").read_text(encoding="utf-8")
    assert 'payload["line_number"] = line_number' in source
    assert 'if not line_number:' in source
    assert 'FARAZSMS_SHARED_FROM_NUMBER' not in source
