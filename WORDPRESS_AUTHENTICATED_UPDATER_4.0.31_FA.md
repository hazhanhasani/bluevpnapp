# BlueVPN 4.0.31 — Authenticated WordPress Updater

علت Build #192 در مرحله `wait-wordpress-auto-update` این بود که APK Workflow تا مرحله انتظار WordPress جلو رفته بود، اما سایت هنوز روی BlueVPN Manager قدیمی مانده بود. خط `error: failed to push some refs` از retry قبلی git push در `android-build.log` انتخاب شده بود و علت واقعی مرحله انتظار نبود.

## اصلاحات

- GitHub Release API از `GITHUB_TOKEN` رمزگذاری‌شده و مهاجرت‌شده ربات WordPress استفاده می‌کند.
- دانلود Asset برای مخزن خصوصی از `releases/assets/{id}` با Authorization و `application/octet-stream` انجام می‌شود.
- `/health` وضعیت امن Updater را با `authenticated`, `status`, `target` و زمان آخرین بررسی نمایش می‌دهد؛ Token نمایش داده نمی‌شود.
- Workflow خطاهای `WORDPRESS_AUTOUPDATE_TIMEOUT`, `WORDPRESS_UPDATER_MESSAGE` و `WORDPRESS_BOOTSTRAP_REQUIRED` را دقیق گزارش می‌کند.
- خطاهای موقت non-fast-forward در retryهای git push دیگر به‌عنوان علت اصلی پیام تلگرام انتخاب نمی‌شوند.

## Bootstrap یک‌باره

سایتی که هنوز BlueVPN Manager 4.0.24 را اجرا می‌کند، کد احراز هویت جدید Updater را ندارد. بنابراین برای عبور از این شکاف فقط یک بار فایل `bluevpn-manager-v4.0.31.zip` را از پنل افزونه‌های WordPress روی نسخه فعلی جایگزین کنید. پس از آن، Updater از Token مهاجرت‌شده استفاده می‌کند و نسخه‌های بعدی باید خودکار نصب شوند.
