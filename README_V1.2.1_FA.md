# BlueVPN v1.2.1 — Migration Bridge Guard

این بسته برای رفع دائمی خطای «تست اتصال» WordPress → Railway ساخته شده است.

## تغییر اصلی

Workflow جدید `BlueVPN Migration Bridge Guard` روی هر تغییر `server/main.py` در شاخه `main` اجرا می‌شود. اگر Deploy Bot یا آپدیت بعدی Registration مربوط به Migration Bridge را حذف کند، Guard آن را بدون جایگزین‌کردن کل `main.py` دوباره اضافه می‌کند و فقط در صورت نیاز Commit می‌زند.

## WordPress 1.2.1

پیام خطای «تست اتصال» دقیق‌تر شده است:

- 404: Backend آنلاین است ولی Bridge روی Deployment فعلی Register نشده.
- 401: Token وردپرس و Railway یکی نیست.
- 503: `WORDPRESS_MIGRATION_TOKEN` در Railway تنظیم نشده/کوتاه است.
- خطای TLS/DNS/cURL: خطای ارتباط واقعی WordPress با Railway همراه URL نمایش داده می‌شود.

## نصب

محتویات ZIP را در ریشه Repository فعلی GitHub قرار بده و Commit کن. Workflow افزونه موجود، BlueVPN Manager 1.2.1 را Release می‌کند و WordPress Updater آن را می‌گیرد. Workflow Guard نیز Backend را بررسی/ترمیم می‌کند؛ پس از Commit ترمیمی، صبر کن Railway Deployment جدید Active شود و دوباره «تست اتصال» را بزن.
