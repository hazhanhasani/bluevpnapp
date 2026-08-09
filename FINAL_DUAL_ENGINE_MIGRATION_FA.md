# نتیجه مهاجرت مرحله اول Xray + sing-box

## انجام‌شده

- لایه مرکزی `BlueVpnEngineManager` اضافه شد.
- وابستگی مستقیم Home و Account به `CoreServiceManager` حذف شد.
- State Machine Runtime اضافه شد.
- Runtime بومی sing-box برای arm64-v8a و armeabi-v7a وارد Workflow شد.
- نسخه sing-box از `branding/app.json` خوانده و در Workflow پین می‌شود.
- اعتبارسنجی Native پروفایل sing-box اضافه شد.
- از افزودن `libbox.aar` دوم و تداخل gomobile جلوگیری شد.
- Xray به‌عنوان تنها مالک TUN در مرحله اول حفظ شد تا Connected جعلی یا Loop شبکه ایجاد نشود.

## تست‌ها

- مجموعه تست پروژه: `261 passed`
- Validator اختصاصی مهاجرت: `35 passed`
- Parse ساختار YAML Workflow: موفق
- بررسی Syntax اسکریپت Python: موفق
- خطا: صفر

## محدودیت صادقانه

Build کامل APK در محیط فعلی اجرا نشد، چون پروژه سورس v2rayNG، ماژول‌های Go، Android SDK/NDK و AAR هسته را هنگام GitHub Actions دریافت می‌کند و این محیط دسترسی شبکه Build را نداشت. Workflow برای ساخت واقعی و توقف در صورت شکست sing-box یا نبود فایل‌های Native آماده شده است.

این خروجی «حذف کامل v2rayNG» نیست؛ مرحله اول مهاجرت امن و قابل Build است. مسیر ترافیک فعلی هنوز Xray است و فعال‌سازی مستقیم sing-box نیازمند سرویس مستقل TUN در مرحله بعد است.
