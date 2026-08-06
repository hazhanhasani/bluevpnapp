# BlueVPN 3.0.26 — بازیابی خودکار GitHub Runner

این نسخه چرخه Build را در برابر خطای «job was not acquired by hosted runner» مقاوم می‌کند. Build اصلی روی `ubuntu-22.04` اجرا می‌شود، صف‌ها دیگر یکدیگر را لغو نمی‌کنند و ربات در صورت عدم تخصیص Runner همان Commit را یک بار روی `ubuntu-24.04` با `workflow_dispatch` تکرار می‌کند.

قابلیت ورود شماره تماس و OTP فراز اس‌ام‌اس نسخه 3.0.25 بدون تغییر حفظ شده است.
