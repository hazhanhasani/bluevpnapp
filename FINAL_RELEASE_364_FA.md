# BlueVPN 3.0.64 — رفع خطای Migration دیتابیس

- جلوگیری از تبدیل `otp_challenges.customer_id = NULL` به شناسه جعلی `0`
- حفظ `NULL` برای Foreign Keyهای اختیاری
- جلوگیری عمومی از ساخت مقدار پیش‌فرض جعلی برای تمام Foreign Keyها
- رفع خطای راه‌اندازی Backend و ربات Telegram روی PostgreSQL
- Version: 3.0.64
- Version Code: 30064
