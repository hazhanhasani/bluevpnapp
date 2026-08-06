from datetime import datetime, timezone
from pathlib import Path

from server.main import account_json
from server.models import Customer
from server.time_locale import (
    TEHRAN_ZONE_NAME,
    fa_digits,
    format_jalali,
    gregorian_to_jalali,
    jalali_fields,
)


def test_nowruz_conversion_is_correct():
    assert gregorian_to_jalali(2026, 3, 21) == (1405, 1, 1)
    assert format_jalali(
        datetime(2026, 3, 21, 0, 0, tzinfo=timezone.utc),
        include_seconds=True,
    ) == "۱۴۰۵/۰۱/۰۱، ساعت ۰۳:۳۰:۰۰"


def test_tehran_midnight_rolls_jalali_date_forward():
    value = datetime(2026, 8, 6, 20, 30, tzinfo=timezone.utc)
    assert format_jalali(value, include_seconds=True) == (
        "۱۴۰۵/۰۵/۱۶، ساعت ۰۰:۰۰:۰۰"
    )


def test_naive_database_datetime_is_interpreted_as_utc():
    value = datetime(2026, 8, 6, 12, 0)
    assert format_jalali(value) == "۱۴۰۵/۰۵/۱۵، ساعت ۱۵:۳۰"


def test_jalali_fields_declare_tehran_timezone():
    fields = jalali_fields("2026-08-06T12:00:00Z")
    assert fields["timezone"] == TEHRAN_ZONE_NAME == "Asia/Tehran"
    assert fields["jalali"] == "۱۴۰۵/۰۵/۱۵، ساعت ۱۵:۳۰"
    assert fields["tehran_time"] == "۱۵:۳۰:۰۰"


def test_account_api_keeps_utc_and_adds_fa_display():
    customer = Customer(email="jalali@example.com", password_hash="x")
    customer.subscription_status = "active"
    customer.subscription_url = "https://example.com/sub"
    customer.subscription_expire = datetime(
        2026, 8, 6, 20, 30, tzinfo=timezone.utc
    )
    payload = account_json(customer)
    subscription = payload["subscription"]
    assert subscription["expire"].endswith("Z")
    assert subscription["expire_fa"] == "۱۴۰۵/۰۵/۱۶، ساعت ۰۰:۰۰"
    assert subscription["timezone"] == "Asia/Tehran"
    assert payload["calendar"] == "jalali"


def test_android_sources_use_shared_jalali_tehran_formatter():
    manager = Path("android-source/BlueVpnAccountManager.kt").read_text(
        encoding="utf-8"
    )
    screen = Path("android-source/BlueVpnSubscriptionsActivity.kt").read_text(
        encoding="utf-8"
    )
    assert 'TimeZone.getTimeZone("Asia/Tehran")' in manager
    assert "gregorianToJalali" in manager
    assert "expireFa" in manager
    assert "BlueVpnPersianDate.formatIso" in screen
    assert "اعتبار تا:" in screen


def test_persian_digits_are_used():
    assert fa_digits("1405/05/15 12:34") == "۱۴۰۵/۰۵/۱۵ ۱۲:۳۴"
