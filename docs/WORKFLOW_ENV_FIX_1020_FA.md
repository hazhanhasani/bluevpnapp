# BlueVPN 1.0.20 — رفع خطای Workflow

## خطا

GitHub فایل Workflow را اجرا نمی‌کرد:

`Line 370, Col 9: 'env' is already defined`

داخل Step ساخت اطلاعات Release دو بخش `env` وجود داشت.

## اصلاح

متغیر زیر به همان `env` اول منتقل شد:

`BUILD_PUBLISHED_AT: ${{ github.event.head_commit.timestamp }}`

بخش `env` دوم حذف شد. قابلیت‌های زیر بدون تغییر باقی مانده‌اند:

- نسخه خودکار بر اساس GitHub Run Number
- Version Code خودکار
- انتشار APKها در GitHub Releases
- ساخت release-manifest.json
- انتخاب APK مناسب معماری گوشی

Build ID بسته: 20260804144403
