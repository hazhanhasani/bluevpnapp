BlueVPN Build Error Reporter v1

این ZIP را مستقیم برای ربات Deploy ارسال کنید.

پس از نصب، اگر GitHub Actions شکست بخورد، ربات تلگرام خودکار دریافت می‌کند:
- خلاصه خطای کامپایل
- شماره Build و Commit
- لینک Run
- فایل کامل android-build.log
- فایل کوچک خلاصه خطا

برای تست، پس از نصب دکمه «ساخت دوباره» را بزنید.
Secretهای TELEGRAM_BOT_TOKEN و TELEGRAM_CHAT_ID باید از قبل در GitHub موجود باشند.
