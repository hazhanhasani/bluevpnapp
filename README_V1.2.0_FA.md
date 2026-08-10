# BlueVPN v1.2.0 — GitHub Update Files

این بسته برای Repository فعلی `hazhanhasani/bluevpnapp` آماده شده است.

## کاری که انجام می‌دهد

- `wordpress/bluevpn-manager/` را به نسخه 1.2.0 می‌برد؛ Release Workflow قبلی، `bluevpn-manager-v1.2.0` را می‌سازد و WordPress Updater آن را می‌گیرد.
- `server/wordpress_migration_bridge.py` را به Backend اضافه می‌کند.
- Workflow دستی `Enable WordPress Migration Bridge` را اضافه می‌کند که `server/main.py` فعلی را بدون جایگزین‌کردن کل فایل patch می‌کند.

## ترتیب

1. محتویات این بسته را در ریشه GitHub Repository قرار بده و Commit کن.
2. صبر کن Workflow `BlueVPN Manager Release` نسخه 1.2.0 افزونه را Release کند؛ WordPress خودش آپدیت را می‌گیرد.
3. در GitHub Actions، Workflow `Enable WordPress Migration Bridge` را یک بار Run workflow بزن.
4. Railway بعد از Commit جدید Backend خودش Deploy می‌شود.
5. در Railway Variables یک متغیر `WORDPRESS_MIGRATION_TOKEN` بساز. بهترین راه: داخل WordPress → BlueVPN → ابزار مهاجرت روی «ساخت Migration Token» بزن و همان مقدار را در Railway قرار بده.
6. در WordPress آدرس Railway را وارد کن و «تست اتصال» بزن.
7. Manifest → شروع/ادامه انتقال → Resync کامل.

Railway را تا پایان Cutover خاموش نکن.
