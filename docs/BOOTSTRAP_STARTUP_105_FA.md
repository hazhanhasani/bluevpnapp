# BlueVPN Bootstrap Startup 1.0.5

## مشکل نسخه قبلی

هرچند Uvicorn قرار بود پیش از Polling تلگرام اجرا شود، این دو Import هنوز
پیش از بازشدن پورت انجام می‌شدند:

- `deploy_bot`
- `server.main`

هر یک از این Importها می‌توانست به علت متغیر ناقص ربات، اتصال PostgreSQL یا
Migration خطا بدهد. در آن حالت Uvicorn شروع نمی‌شد و Railway فقط
`Healthcheck failure` نمایش می‌داد.

## معماری جدید

1. فایل `server/run_combined.py` بدون Import کردن Backend یا Bot اجرا می‌شود.
2. یک ASGI Bootstrap فوراً روی `$PORT` گوش می‌دهد.
3. Railway مسیر `/live` را بررسی می‌کند.
4. Backend و PostgreSQL در Task جداگانه بارگذاری می‌شوند.
5. دیتابیس، جداول و Migrationها همچنان اجباری‌اند؛ تا آماده‌شدن، سایر مسیرها
   پاسخ 503 می‌دهند و پنل خالی ساخته نمی‌شود.
6. در صورت خطا، Bootstrap هر ۱۵ ثانیه تلاش مجدد می‌کند.
7. خطای پاک‌سازی‌شده مستقیم برای مدیر تلگرام ارسال می‌شود.
8. وضعیت کامل از مسیر `/startup-status` قابل مشاهده است.
9. پس از آماده‌شدن Backend، تمام درخواست‌ها به FastAPI اصلی تحویل داده می‌شوند.
10. ربات تلگرام نیز مستقل است و خطایش وب و دیتابیس را خاموش نمی‌کند.

## مسیرها

- `/live`: زنده‌بودن Process؛ مناسب Healthcheck Railway
- `/startup-status`: وضعیت Backend، PostgreSQL، Migration و ربات
- `/health`: سلامت واقعی Backend و دیتابیس پس از آماده‌شدن

## امنیت داده

SQLite موقت Railway همچنان ممنوع است. Bootstrap فقط جلوی شکست Healthcheck را
می‌گیرد؛ هرگز پنل خالی یا دیتابیس موقت را جایگزین PostgreSQL نمی‌کند.
