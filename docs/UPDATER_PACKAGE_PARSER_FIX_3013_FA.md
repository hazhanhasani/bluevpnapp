# BlueVPN 3.0.13 — رفع خطای تجزیه بسته

## علت

نصب از ربات موفق بود، اما نصب داخلی فایل را از FileProvider برنامه به Package Installer تحویل می‌داد. روی برخی نسخه‌های MIUI فقط FLAG_GRANT_READ_URI_PERMISSION کافی نیست و نصب‌کننده نمی‌تواند محتوای URI را کامل بخواند. علاوه بر آن، دانلودر قبلی قبل از نصب ساختار، اندازه و SHA-256 فایل را بررسی نمی‌کرد؛ بنابراین دانلود ناقص یا پاسخ غیر APK مستقیماً به نصب‌کننده می‌رسید و پیام «مشکلی در تجزیه این بسته وجود داشت» نمایش داده می‌شد.

## اصلاحات

- بررسی Content-Type و Content-Length پاسخ دانلود
- تطبیق اندازه فایل با متادیتای GitHub Release
- تطبیق SHA-256 با digest رسمی Release
- بررسی AndroidManifest.xml و classes.dex در APK
- بررسی packageName و versionName با PackageManager
- حذف فایل ناسالم و دانلود تمیز مجدد
- ClipData و grantUriPermission صریح برای تمام نصب‌کننده‌های قابل Resolve
- ACTION_INSTALL_PACKAGE و fallback به ACTION_VIEW

## نتیجه

نصب‌کننده فقط APK سالم و قابل‌خواندن را دریافت می‌کند؛ بنابراین خطای مبهم Package Parser به کاربر نمایش داده نمی‌شود.
