# BlueVPN 1.0.19 — GitHub Release Autopilot

## رفتار جدید

- شماره نسخه Android از شماره Run گیت‌هاب ساخته می‌شود:
  `1.0.<GITHUB_RUN_NUMBER>`
- Version Code نیز خودکار است:
  `10000 + GITHUB_RUN_NUMBER`
- هر Build موفق یک GitHub Release منتشر می‌کند.
- فایل‌های arm64-v8a، armeabi-v7a، SHA256 و manifest داخل Release قرار می‌گیرند.
- برنامه اطلاعات آپدیت را از آخرین Release می‌گیرد.
- معماری گوشی شناسایی و APK مناسب انتخاب می‌شود.
- نسخه، لینک APK، عنوان و توضیحات آپدیت از پنل مدیریت حذف شدند.
- Backend نتیجه GitHub را ۵ دقیقه Cache می‌کند.
- در خطای موقت GitHub آخرین Release موفق حفظ می‌شود.

## تنظیم لازم

مخزن GitHub باید عمومی باشد یا دانلود Asset برای کاربران قابل دسترس باشد.
Workflow با `permissions: contents: write` و `GITHUB_TOKEN` خود GitHub،
Release را منتشر می‌کند.

Build ID بسته: 20260804141957
