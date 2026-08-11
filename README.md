# BlueVPN 3.0.70 — مهاجرت امن به معماری دو هسته

این نسخه، مرحله اول جداسازی واقعی BlueVPN از APIهای داخلی v2rayNG است و sing-box را بدون ایجاد تداخل میان دو Runtime گوموبایل وارد فرایند Build می‌کند.

## تغییرات اصلی

- تمام فرمان‌های اتصال و قطع اتصال رابط BlueVPN از مسیر `BlueVpnEngineManager` عبور می‌کنند.
- صفحه اصلی و مدیریت حساب دیگر مستقیماً `CoreServiceManager` را Import نمی‌کنند.
- State Machine مرکزی Runtime برای وضعیت‌های آماده‌سازی، اتصال، بررسی، تعویض، توقف و خطا اضافه شده است.
- sing-box پین‌شده در GitHub Actions برای `arm64-v8a` و `armeabi-v7a` به‌صورت Native PIE ساخته می‌شود.
- پروفایل کامل sing-box قبل از استفاده با فرمان Native خود هسته اعتبارسنجی می‌شود.
- برای جلوگیری از تداخل TUN، در این مرحله Xray تنها مالک مسیر VPN است و sing-box تا آماده‌شدن سرویس مستقل، به‌صورت Runtime آماده و Validator نگه داشته می‌شود.
- فایل‌های خوانای `android-source` منبع اصلی تزریق کد شده‌اند و Payloadهای قدیمی Base64 با آن‌ها همگام هستند.

جزئیات فنی در `docs/DUAL_ENGINE_RUNTIME_3070_FA.md` و نتیجه بررسی در `FINAL_DUAL_ENGINE_MIGRATION_FA.md` آمده است.

برای ساخت APK، پروژه را در مخزن GitHub بارگذاری و Workflow ساخت را اجرا کنید.
