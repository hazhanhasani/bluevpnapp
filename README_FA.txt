BlueVPN Database Autopilot 1.0.3

این ZIP را مستقیم برای ربات Deploy ارسال کنید.
تغییر دستی Dockerfile یا GitHub لازم نیست؛ Railway از server/run_combined.py اجرا می‌شود.

اصلاح اصلی:
- حذف کامل SQLite موقت در Railway
- اتصال خودکار به PostgreSQL
- ساخت خودکار دیتابیس در صورت داشتن مجوز
- ساخت خودکار تمام جداول
- Migration خودکار ستون‌ها و نسخه‌های قبلی
- انتقال خودکار SQLite قدیمی به PostgreSQL، اگر فایل هنوز موجود باشد
- Fail کردن Deploy در صورت نبود دیتابیس دائمی
- نمایش وضعیت و تعداد رکوردها در پنل مدیریت

پس از Deploy فقط /health را باز کنید.
باید database.mode برابر postgres و persistent برابر true باشد.

این تغییر Backend است و APK جدید لازم ندارد.
