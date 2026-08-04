BlueVPN Database Environment Autodiscovery 1.0.6

این ZIP را مستقیم برای ربات Deploy ارسال کنید.

خطای 1.0.5 نشان داد متغیر اتصال Postgres به Container نرسیده بود.

نسخه جدید:
- هر متغیری با مقدار واقعی PostgreSQL را با هر نامی پیدا می‌کند.
- نام‌های بیشتر Railway و PostgreSQL را پشتیبانی می‌کند.
- متغیرهای Component سفارشی را نیز تشخیص می‌دهد.
- نام متغیرهای دیده‌شده را بدون نمایش رمز در /startup-status گزارش می‌دهد.
- خطای دقیق را برای ربات تلگرام می‌فرستد.
- ساخت خودکار جداول، Migration و ممنوعیت SQLite موقت حفظ شده است.

تنظیم پیشنهادی در سرویس bluevpnapp:
DATABASE_URL=${{Postgres.DATABASE_PRIVATE_URL}}

APK جدید لازم نیست.
