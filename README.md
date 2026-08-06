# BlueVPN 3.0.27 — بازگردانی Workflow پایدار GitHub

در این نسخه فایل `.github/workflows/build-apk.yml` دقیقاً به ساختار آخرین نسخه‌ای که Build موفق داشت بازگردانده شده است. انتخاب پویا میان Runnerها، `workflow_dispatch` دارای ورودی Runner، صف `queue: max` و تلاش خودکار روی Runner دوم حذف شده‌اند.

Build دوباره فقط با یک مسیر ساده اجرا می‌شود:

```yaml
jobs:
  build:
    runs-on: ubuntu-latest
```

ربات Deploy نیز فقط Commit تأییدشده را Push می‌کند و همان اجرای `push` را دنبال می‌کند؛ اجرای اضافه و Retry خودکار Workflow ایجاد نمی‌شود. ورود شماره تماس و OTP فراز اس‌ام‌اس نسخه 3.0.25 بدون تغییر حفظ شده است.
