# اصلاح بازیابی Pool پریمیوم و وضعیت سرور — BlueVPN

این Hotfix برای وضعیتی است که حساب Premium فعال است اما صفحه اصلی `0 مکان • 0 مسیر` نشان می‌دهد، صفحه مکان‌ها روی همگام‌سازی Pool می‌ماند و هم‌زمان یک سرور قدیمی/نامعتبر هنوز در کارت انتخاب خودکار دیده می‌شود.

## اصلاحات

- User-Agent پیش‌فرض Subscription دوباره به رفتار native خود v2rayNG 2.2.6 برگشت (`null` در SubscriptionItem تا خود v2rayNG هویت `v2rayNG/<version>` را بسازد).
- Retryهای User-Agent محدود شدند تا یک Subscription خراب صفحه مکان‌ها را برای چند دقیقه قفل نکند.
- GUIDهای شبح (server list موجود ولی Profile حذف‌شده) دیگر به‌عنوان سرور آماده شمرده نمی‌شوند.
- اگر سرور انتخاب‌شده متعلق به Pool فعلی نباشد و جایگزینی وجود نداشته باشد، GUID قدیمی پاک می‌شود؛ بنابراین UI دیگر در حالت `0 مسیر` نام یک کشور/سرور قدیمی را نمایش نمی‌دهد.
- خلاصه Smart Selector قبل از نمایش، عضویت سرور در Entitlement فعلی را دوباره بررسی می‌کند.
- جابه‌جایی Premium Pool تراکنشی شد: Pool قدیمی قبل از موفق شدن Import Pool جدید به‌صورت فیزیکی حذف نمی‌شود. ابتدا غیرفعال می‌شود و فقط بعد از تأیید حداقل یک Profile واقعی در Pool جدید پاک می‌شود.
- مسیر Xray/v2rayNG همچنان مرجع اصلی Import/Parse Subscription است؛ BlueVPN فقط هماهنگ‌کننده Entitlement و UI است.

## اعتبارسنجی

- pytest: 367 passed
- Dual Engine validation: 35/35 passed
- Generated Android validation: passed
- Build کامل Gradle/NDK/Go باید در GitHub Actions انجام شود.
