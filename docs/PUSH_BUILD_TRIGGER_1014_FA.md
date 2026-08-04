# BlueVPN 1.0.14 — اصلاح HTTP 403 شروع Build

- Android: 1.0.14
- Version Code: 28
- Deploy Bot: 2.5-push-trigger
- Build ID: 20260804114542

## علت خطا

ربات فعال Railway هنوز workflow_dispatch را صدا می‌زد. توکن Fine-grained
برای این API به Actions: write نیاز دارد.

## اصلاح

- هیچ درخواست workflow_dispatch ارسال نمی‌شود.
- Build با `git commit --allow-empty` و `git push origin main` آغاز می‌شود.
- Workflow موجود روی Push شاخه main اجرا می‌شود.
- ربات از فایل `server/deploy_bot_runtime.py` اجرا می‌شود.
- نسخه قدیمی داخل Dockerfile محافظت‌شده دیگر اولویت ندارد.
- `/startup-status` نسخه ربات و روش Trigger را نمایش می‌دهد.

## نکته نصب

ممکن است ربات قدیمی پس از Push همین ZIP یک بار دیگر 403 نشان دهد. فایل‌ها
قبل از آن روی GitHub ثبت شده‌اند و Railway نسخه جدید را Deploy می‌کند.
پس از فعال‌شدن نسخه جدید، دکمه «ساخت دوباره» را بزن.
