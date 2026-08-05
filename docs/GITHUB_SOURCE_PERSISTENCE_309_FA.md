# BlueVPN 3.0.9 — ثبت قطعی سورس در GitHub

در این نسخه Build تنها زمانی ادامه پیدا می‌کند که فایل‌های پروژه واقعاً روی شاخه مقصد GitHub ثبت شده باشند.

## رفتار جدید ربات Deploy

1. ZIP استخراج و همه فایل‌ها به‌جز `.env` و فایل‌های Keystore در مخزن کپی می‌شوند.
2. `Dockerfile`، Workflowها، Backend، اسکریپت اندروید و فایل‌های مستندات قابل به‌روزرسانی هستند.
3. ربات فهرست فایل‌های تغییرکرده را Commit و Push می‌کند.
4. پس از Push، SHA شاخه با `git ls-remote` مستقیماً از GitHub خوانده می‌شود.
5. Build فقط وقتی شروع می‌شود که SHA محلی و SHA GitHub دقیقاً برابر باشند.
6. در صورت Branch Protection، کمبود دسترسی Token، تعارض یا شکست Push، APK ساخته و ارسال نمی‌شود.

## رفتار جدید GitHub Actions

- خطای ثبت `release.json` و `branding/app.json` دیگر نادیده گرفته نمی‌شود.
- پس از تزریق کد اندروید، ۲۳ فایل اصلی Kotlin/XML و Manifest/Gradle در مسیر `android-source/generated/` ذخیره می‌شوند.
- Snapshot شامل SHA-256 هر فایل است.
- GitHub Release به آخرین Commit تأییدشده سورس متصل می‌شود، نه Commit قدیمی شروع Workflow.

## دسترسی لازم برای GITHUB_TOKEN ربات Railway

برای Fine-grained Personal Access Token:

- Repository access: مخزن `bluevpnapp`
- Contents: Read and write
- Actions: Read
- Workflows: Read and write، فقط اگر ZIP قرار است فایل `.github/workflows/build-apk.yml` را هم تغییر دهد

Secretها و Keystore همچنان وارد GitHub نمی‌شوند.
