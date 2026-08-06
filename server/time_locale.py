from __future__ import annotations

from datetime import datetime, timezone
from typing import Any
from zoneinfo import ZoneInfo

TEHRAN_ZONE_NAME = "Asia/Tehran"
TEHRAN_TZ = ZoneInfo(TEHRAN_ZONE_NAME)
PERSIAN_DIGITS = str.maketrans("0123456789", "۰۱۲۳۴۵۶۷۸۹")
PERSIAN_MONTHS = (
    "فروردین", "اردیبهشت", "خرداد", "تیر", "مرداد", "شهریور",
    "مهر", "آبان", "آذر", "دی", "بهمن", "اسفند",
)


def as_utc(value: datetime | None) -> datetime | None:
    if value is None:
        return None
    if value.tzinfo is None:
        return value.replace(tzinfo=timezone.utc)
    return value.astimezone(timezone.utc)


def parse_datetime(value: Any) -> datetime | None:
    if value in (None, "", "null"):
        return None
    if isinstance(value, datetime):
        return as_utc(value)
    if isinstance(value, (int, float)):
        raw = float(value)
        if raw > 10_000_000_000:
            raw /= 1000.0
        try:
            return datetime.fromtimestamp(raw, timezone.utc)
        except (OverflowError, OSError, ValueError):
            return None
    text = str(value).strip()
    if not text:
        return None
    try:
        parsed = datetime.fromisoformat(text.replace("Z", "+00:00"))
    except ValueError:
        return None
    return as_utc(parsed)


def _div(a: int, b: int) -> int:
    return a // b


def gregorian_to_jalali(gy: int, gm: int, gd: int) -> tuple[int, int, int]:
    """Convert one Gregorian date to Solar Hijri without external packages."""
    g_days = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334]
    gy2 = gy + 1 if gm > 2 else gy
    days = (
        355666
        + 365 * gy
        + _div(gy2 + 3, 4)
        - _div(gy2 + 99, 100)
        + _div(gy2 + 399, 400)
        + gd
        + g_days[gm - 1]
    )
    jy = -1595 + 33 * _div(days, 12053)
    days %= 12053
    jy += 4 * _div(days, 1461)
    days %= 1461
    if days > 365:
        jy += _div(days - 1, 365)
        days = (days - 1) % 365
    if days < 186:
        jm = 1 + _div(days, 31)
        jd = 1 + days % 31
    else:
        jm = 7 + _div(days - 186, 30)
        jd = 1 + (days - 186) % 30
    return jy, jm, jd


def to_tehran(value: Any) -> datetime | None:
    parsed = parse_datetime(value)
    return parsed.astimezone(TEHRAN_TZ) if parsed else None


def fa_digits(value: Any) -> str:
    return str(value).translate(PERSIAN_DIGITS)


def format_jalali(
    value: Any,
    *,
    include_time: bool = True,
    include_seconds: bool = False,
    month_name: bool = False,
    fallback: str = "—",
    persian_digits: bool = True,
) -> str:
    local = to_tehran(value)
    if local is None:
        return fallback
    jy, jm, jd = gregorian_to_jalali(local.year, local.month, local.day)
    if month_name:
        date_part = f"{jd} {PERSIAN_MONTHS[jm - 1]} {jy}"
    else:
        date_part = f"{jy:04d}/{jm:02d}/{jd:02d}"
    if include_time:
        time_part = local.strftime("%H:%M:%S" if include_seconds else "%H:%M")
        result = f"{date_part}، ساعت {time_part}"
    else:
        result = date_part
    return fa_digits(result) if persian_digits else result


def jalali_fields(value: Any) -> dict[str, str]:
    local = to_tehran(value)
    if local is None:
        return {
            "jalali": "",
            "jalali_date": "",
            "tehran_time": "",
            "timezone": TEHRAN_ZONE_NAME,
        }
    return {
        "jalali": format_jalali(local, include_time=True),
        "jalali_date": format_jalali(local, include_time=False),
        "tehran_time": fa_digits(local.strftime("%H:%M:%S")),
        "timezone": TEHRAN_ZONE_NAME,
    }


def jalali_now() -> str:
    return format_jalali(datetime.now(timezone.utc), include_time=True, include_seconds=True)
