# راه‌اندازی آپدیت خودکار BlueVPN Manager از GitHub

این بسته برای مخزن زیر آماده شده است:

`hazhanhasani/bluevpnapp`

## فقط یک بار

محتویات این ZIP را در ریشه همان Repository قرار بده و Commit کن:

- `.github/workflows/bluevpn-manager-release.yml`
- `wordpress/bluevpn-manager/`

Workflow با هر تغییر در پوشه افزونه روی شاخه `main` اجرا می‌شود.

## قرارداد نسخه‌ها

نسخه افزونه در فایل زیر قرار دارد:

`wordpress/bluevpn-manager/bluevpn-manager.php`

مثال:

`Version: 1.2.0`

Workflow Release زیر را می‌سازد:

- Tag: `bluevpn-manager-v1.2.0`
- Asset: `bluevpn-manager.zip`

افزونه نصب‌شده در WordPress فقط همین Tag/Asset را به عنوان آپدیت معتبر می‌شناسد.

## برای آپدیت‌های بعدی

1. فایل‌های تغییرکرده افزونه را در `wordpress/bluevpn-manager/` جایگزین کن.
2. شماره Version افزونه را بالا ببر، مثلاً از `1.1.0` به `1.2.0`.
3. Commit/Push کن.
4. GitHub Actions ZIP Release را می‌سازد.
5. WordPress نسخه جدید را در سیستم Updates نمایش می‌دهد و اگر Auto Update روشن باشد، اجازه آپدیت خودکار دارد.

## مهم

Releaseهای Android/Backend تداخلی ایجاد نمی‌کنند، چون Updater فقط Tagهایی با پیشوند `bluevpn-manager-v` و Asset با نام `bluevpn-manager.zip` را می‌پذیرد.
