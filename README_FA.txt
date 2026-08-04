BlueVPN Bootstrap Startup Fix 1.0.5

این ZIP را مستقیم برای ربات Deploy ارسال کنید.

اصلاحات:
- Railway مسیر /live را بررسی می‌کند و پورت فوراً باز می‌شود.
- ربات، Backend، PostgreSQL و Migration دیگر قبل از بازشدن PORT اجرا نمی‌شوند.
- PostgreSQL و ساخت جداول در پس‌زمینه با تلاش مجدد انجام می‌شود.
- تا آماده‌شدن PostgreSQL، پنل خالی یا SQLite موقت نمایش داده نمی‌شود.
- /startup-status علت واقعی راه‌اندازی را نشان می‌دهد.
- خطای پاک‌سازی‌شده راه‌اندازی برای مدیر تلگرام ارسال می‌شود.
- /health بعد از آماده‌شدن Backend سلامت واقعی دیتابیس را نشان می‌دهد.

متغیر درست:
DATABASE_URL=${{Postgres.DATABASE_PRIVATE_URL}}
DB_REQUIRE_POSTGRES=true
ALLOW_SQLITE_FALLBACK=false

APK جدید لازم نیست.
