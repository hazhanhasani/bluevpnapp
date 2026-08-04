# تشخیص خودکار متغیر دیتابیس — نسخه 1.0.6

## علت خطای نسخه 1.0.5

لاگ نشان داد داخل Container سرویس BlueVPN هیچ‌یک از نام‌های استاندارد زیر
وجود نداشت یا مقدار واقعی PostgreSQL نداشت:

- DATABASE_URL
- DATABASE_PRIVATE_URL
- POSTGRES_URL
- POSTGRES_PRIVATE_URL
- PGHOST / PGUSER / PGPASSWORD / PGDATABASE

آنلاین‌بودن سرویس Postgres به‌تنهایی کافی نیست؛ Railway باید یک Reference
Variable را داخل سرویس `bluevpnapp` تزریق کند.

## تغییرات نسخه جدید

- هر متغیری که مقدارش با `postgres://` یا `postgresql://` شروع شود خودکار
  شناسایی می‌شود، حتی اگر نام متغیر سفارشی باشد.
- متغیرهای `POSTGRESQL_URL` و نام‌های مشابه پشتیبانی می‌شوند.
- گروه‌های سفارشی مانند `MYDB_HOST`, `MYDB_USER`, `MYDB_PASSWORD`,
  `MYDB_DATABASE` نیز قابل شناسایی‌اند.
- `/startup-status` فقط نام متغیرهای مرتبط را نشان می‌دهد و هیچ رمز یا URL
  محرمانه‌ای نمایش نمی‌دهد.
- پیام خطای تلگرام نیز مشخص می‌کند چه نام‌هایی واقعاً به Container رسیده‌اند.
- PostgreSQL و جدول‌ها همچنان خودکار ساخته یا Migration می‌شوند.
- SQLite موقت Railway همچنان ممنوع است.

## تنظیم قطعی و پیشنهادی

در سرویس `bluevpnapp`، بخش Variables:

نام:
`DATABASE_URL`

مقدار:
`${{Postgres.DATABASE_PRIVATE_URL}}`

بعد از ذخیره، Deployment جدید باید ساخته شود.
