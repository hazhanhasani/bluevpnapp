BlueVPN Healthcheck Bot Fix 1.0.4

این ZIP را مستقیم برای ربات Deploy ارسال کنید.

اصلاح:
- وب‌سرور و /health قبل از ربات تلگرام اجرا می‌شوند.
- تداخل Polling نسخه قدیمی و جدید دیگر Healthcheck را خراب نمی‌کند.
- ربات در Task جداگانه هر ۱۵ ثانیه تلاش مجدد می‌کند.
- خطای تلگرام باعث خاموش‌شدن وب، API یا دیتابیس نمی‌شود.
- اتصال PostgreSQL و Database Autopilot نسخه قبل حفظ شده است.

APK جدید لازم نیست.
پس از Deploy خروجی /health باید version=1.0.4 و database.mode=postgres باشد.
