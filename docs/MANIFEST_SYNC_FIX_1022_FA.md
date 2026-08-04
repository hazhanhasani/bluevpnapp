# BlueVPN 1.0.22

## رفع Build شماره 39

خطا در مرحله `processPlaystoreReleaseMainManifest` رخ داد.

نسخه قبلی محل درج مجوز نصب APK را با اولین `>` پیدا می‌کرد. اولین `>` فایل
AndroidManifest متعلق به XML declaration است، نه تگ manifest. در نتیجه
`uses-permission` بیرون ریشه manifest قرار می‌گرفت.

اصلاح:
- پیدا کردن تگ واقعی `<manifest ...>`
- درج مجوز داخل ریشه manifest
- Parse کردن XML پیش از Gradle
- مرحله مستقل اعتبارسنجی Manifest در Workflow

## تکمیل حذف همگام‌سازی یک‌دقیقه‌ای

- Thread سراسری BlueVpnBootstrap حذف شد.
- autoSync صفحه حساب و اشتراک حذف شد.
- همگام‌سازی خودکار فقط هنگام اجرای تازه صفحه اصلی انجام می‌شود.

Build ID: 20260804153005
