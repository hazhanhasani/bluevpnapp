from __future__ import annotations

from dataclasses import dataclass
from typing import Final


@dataclass(frozen=True)
class SmsVariableSpec:
    name: str
    kind: str
    length: int


@dataclass(frozen=True)
class SmsTemplateSpec:
    key: str
    title: str
    category: str
    body: str
    variables: tuple[SmsVariableSpec, ...] = ()
    default_enabled: bool = False
    broadcast: bool = False


def v(name: str, kind: str, length: int) -> SmsVariableSpec:
    return SmsVariableSpec(name=name, kind=kind, length=length)


SMS_TEMPLATE_SPECS: Final[tuple[SmsTemplateSpec, ...]] = (
    SmsTemplateSpec('auth_otp', 'کد ورود یا ثبت‌نام', 'احراز هویت', 'کد ورود شما به بلوپنل: %code%\nاین کد را در اختیار دیگران قرار ندهید.', (v('code', 'متنی', 6),), True),
    SmsTemplateSpec('welcome', 'خوش‌آمدگویی', 'حساب کاربری', '%name% عزیز، عضویت شما در بلوپنل با موفقیت انجام شد.', (v('name', 'متنی', 30),)),
    SmsTemplateSpec('account_activated', 'فعال‌شدن حساب', 'حساب کاربری', 'حساب شما در بلوپنل فعال شد.\nاکنون می‌توانید وارد حساب خود شوید.'),
    SmsTemplateSpec('subscription_activated', 'فعال‌شدن اشتراک', 'اشتراک', 'اشتراک %plan% شما در بلوپنل فعال شد.\nاعتبار تا: %expire_date%', (v('plan', 'متنی', 40), v('expire_date', 'متنی', 10))),
    SmsTemplateSpec('admin_subscription_activated', 'فعال‌سازی دستی توسط مدیریت', 'اشتراک', 'اشتراک %plan% شما توسط مدیریت بلوپنل فعال شد.\nاعتبار تا: %expire_date%', (v('plan', 'متنی', 40), v('expire_date', 'متنی', 10))),
    SmsTemplateSpec('subscription_renewed', 'تمدید اشتراک', 'اشتراک', 'اشتراک %plan% شما در بلوپنل تمدید شد.\nاعتبار جدید: %expire_date%', (v('plan', 'متنی', 40), v('expire_date', 'متنی', 10))),
    SmsTemplateSpec('subscription_upgraded', 'ارتقای اشتراک', 'اشتراک', 'اشتراک شما در بلوپنل به پلن %plan% ارتقا یافت.\nاعتبار تا: %expire_date%', (v('plan', 'متنی', 40), v('expire_date', 'متنی', 10))),
    SmsTemplateSpec('subscription_plan_changed', 'تغییر پلن اشتراک', 'اشتراک', 'پلن حساب شما در بلوپنل به %plan% تغییر کرد.\nاعتبار تا: %expire_date%', (v('plan', 'متنی', 40), v('expire_date', 'متنی', 10))),
    SmsTemplateSpec('payment_success', 'پرداخت موفق', 'پرداخت', 'پرداخت %amount% تومان با موفقیت انجام شد.\nشماره فاکتور: %invoice_id%\nبلوپنل', (v('amount', 'عددی', 12), v('invoice_id', 'متنی', 40))),
    SmsTemplateSpec('payment_failed', 'پرداخت ناموفق', 'پرداخت', 'پرداخت فاکتور %invoice_id% ناموفق بود.\nدر صورت کسر وجه با پشتیبانی بلوپنل تماس بگیرید.', (v('invoice_id', 'متنی', 40),)),
    SmsTemplateSpec('invoice_created', 'ایجاد فاکتور', 'پرداخت', 'فاکتور %invoice_id% به مبلغ %amount% تومان ایجاد شد.\nبلوپنل', (v('invoice_id', 'متنی', 40), v('amount', 'عددی', 12))),
    SmsTemplateSpec('invoice_expired', 'لغو یا انقضای فاکتور', 'پرداخت', 'مهلت پرداخت فاکتور %invoice_id% به پایان رسید و فاکتور لغو شد.\nبلوپنل', (v('invoice_id', 'متنی', 40),)),
    SmsTemplateSpec('refund_success', 'بازگشت وجه', 'پرداخت', 'مبلغ %amount% تومان بابت فاکتور %invoice_id% بازگشت داده شد.\nبلوپنل', (v('amount', 'عددی', 12), v('invoice_id', 'متنی', 40))),
    SmsTemplateSpec('subscription_reminder', 'یادآوری پایان اشتراک', 'اشتراک', 'تنها %days_left% روز از اعتبار اشتراک شما باقی مانده است.\nبلوپنل', (v('days_left', 'عددی', 2),)),
    SmsTemplateSpec('subscription_expired', 'پایان اشتراک', 'اشتراک', 'اشتراک شما در بلوپنل به پایان رسید.\nبرای فعال‌سازی مجدد، اشتراک خود را تمدید کنید.'),
    SmsTemplateSpec('low_remaining_volume', 'هشدار کاهش حجم', 'اشتراک', 'حجم باقی‌مانده اشتراک شما کمتر از %remaining_volume% گیگابایت است.\nبلوپنل', (v('remaining_volume', 'عددی', 4),)),
    SmsTemplateSpec('volume_expired', 'پایان حجم اشتراک', 'اشتراک', 'حجم اشتراک شما به پایان رسید.\nبرای ادامه استفاده، اشتراک خود را تمدید کنید.'),
    SmsTemplateSpec('new_device_login', 'ورود از دستگاه جدید', 'امنیت', 'ورود جدید به حساب بلوپنل شما ثبت شد.\nدستگاه: %device%\nزمان: %date%', (v('device', 'متنی', 30), v('date', 'متنی', 16))),
    SmsTemplateSpec('suspicious_login', 'هشدار ورود مشکوک', 'امنیت', 'ورود مشکوکی به حساب بلوپنل شما ثبت شد.\nاگر شما نبودید، سریعاً با پشتیبانی تماس بگیرید.'),
    SmsTemplateSpec('device_connected', 'اتصال دستگاه جدید', 'امنیت', 'دستگاه %device% به حساب بلوپنل شما متصل شد.', (v('device', 'متنی', 30),)),
    SmsTemplateSpec('device_removed', 'حذف دستگاه', 'امنیت', 'دستگاه %device% از حساب بلوپنل شما حذف شد.', (v('device', 'متنی', 30),)),
    SmsTemplateSpec('phone_changed', 'تغییر شماره تلفن', 'امنیت', 'شماره تلفن حساب بلوپنل شما با موفقیت تغییر کرد.\nاگر شما نبودید، با پشتیبانی تماس بگیرید.'),
    SmsTemplateSpec('phone_change_otp', 'کد تأیید تغییر شماره', 'امنیت', 'کد تأیید تغییر شماره در بلوپنل: %code%\nاین کد را در اختیار دیگران قرار ندهید.', (v('code', 'متنی', 6),), True),
    SmsTemplateSpec('account_temporarily_blocked', 'مسدودشدن موقت حساب', 'امنیت', 'حساب بلوپنل شما به‌دلیل تلاش‌های ناموفق موقتاً مسدود شد.'),
    SmsTemplateSpec('account_unblocked', 'رفع مسدودی حساب', 'امنیت', 'محدودیت حساب شما در بلوپنل برداشته شد.\nاکنون می‌توانید وارد حساب شوید.'),
    SmsTemplateSpec('account_status_changed', 'تغییر وضعیت حساب توسط مدیر', 'حساب کاربری', 'وضعیت حساب شما در بلوپنل به «%status%» تغییر یافت.', (v('status', 'متنی', 20),)),
    SmsTemplateSpec('wallet_charged', 'افزایش موجودی کیف پول', 'کیف پول', 'کیف پول شما در بلوپنل به مبلغ %amount% تومان شارژ شد.\nموجودی: %balance% تومان', (v('amount', 'عددی', 12), v('balance', 'عددی', 12))),
    SmsTemplateSpec('wallet_deducted', 'کسر از کیف پول', 'کیف پول', 'مبلغ %amount% تومان از کیف پول بلوپنل شما کسر شد.\nموجودی: %balance% تومان', (v('amount', 'عددی', 12), v('balance', 'عددی', 12))),
    SmsTemplateSpec('wallet_insufficient', 'موجودی ناکافی کیف پول', 'کیف پول', 'موجودی کیف پول بلوپنل برای انجام این عملیات کافی نیست.\nموجودی: %balance% تومان', (v('balance', 'عددی', 12),)),
    SmsTemplateSpec('ticket_created', 'ثبت درخواست پشتیبانی', 'پشتیبانی', 'درخواست پشتیبانی شما با شماره %ticket_id% ثبت شد.\nبلوپنل', (v('ticket_id', 'متنی', 30),)),
    SmsTemplateSpec('ticket_replied', 'پاسخ پشتیبانی', 'پشتیبانی', 'به درخواست پشتیبانی %ticket_id% پاسخ داده شد.\nبرای مشاهده پاسخ وارد بلوپنل شوید.', (v('ticket_id', 'متنی', 30),)),
    SmsTemplateSpec('ticket_closed', 'بسته‌شدن درخواست پشتیبانی', 'پشتیبانی', 'درخواست پشتیبانی %ticket_id% بسته شد.\nبلوپنل', (v('ticket_id', 'متنی', 30),)),
    SmsTemplateSpec('service_disruption', 'اطلاع‌رسانی اختلال', 'اطلاع‌رسانی', 'کاربر گرامی، بخشی از خدمات بلوپنل دچار اختلال موقت شده است.\nدر حال رفع مشکل هستیم.', broadcast=True),
    SmsTemplateSpec('service_restored', 'رفع اختلال', 'اطلاع‌رسانی', 'اختلال خدمات بلوپنل برطرف شد.\nاز شکیبایی شما سپاسگزاریم.', broadcast=True),
    SmsTemplateSpec('scheduled_maintenance', 'تعمیرات برنامه‌ریزی‌شده', 'اطلاع‌رسانی', 'بلوپنل در تاریخ %date% از ساعت %start_time% تا %end_time% در حال به‌روزرسانی خواهد بود.', (v('date', 'متنی', 10), v('start_time', 'متنی', 5), v('end_time', 'متنی', 5)), broadcast=True),
    SmsTemplateSpec('new_version', 'انتشار نسخه جدید', 'اطلاع‌رسانی', 'نسخه جدید بلوپنل منتشر شد.\nبرای دریافت آخرین نسخه: %download_link%', (v('download_link', 'متنی', 100),), broadcast=True),
    SmsTemplateSpec('required_update', 'الزام به‌روزرسانی', 'اطلاع‌رسانی', 'برای ادامه استفاده از بلوپنل، برنامه را به آخرین نسخه به‌روزرسانی کنید.\n%download_link%', (v('download_link', 'متنی', 100),), broadcast=True),
    SmsTemplateSpec('admin_announcement', 'پیام عمومی مدیریت', 'اطلاع‌رسانی', 'اطلاعیه بلوپنل:\n%message%', (v('message', 'متنی', 120),), broadcast=True),
)

SMS_TEMPLATE_MAP: Final[dict[str, SmsTemplateSpec]] = {item.key: item for item in SMS_TEMPLATE_SPECS}
