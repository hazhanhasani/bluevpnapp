BlueVPN 3.0.13 — In-App Updater Integrity Fix
Build ID: 20260806-admin-security-dashboard-v3012

فایل ZIP را از طریق ربات Deploy نصب کنید تا ابتدا در GitHub Commit و سپس APK ساخته شود.

اصلاحات این نسخه:
- داشبورد Dark Glass با فونت Vazirmatn و آیکون‌های SVG
- نمودار زنده نرخ موفقیت اپراتورها
- Rate Limit برای login/register و ورود مدیر
- Session امن‌تر و چرخش نشست پس از ورود
- دانلود ZIP پشتیبان PostgreSQL/SQLite با CSRF و SHA-256

فایل‌های اصلی تغییرکرده:
- server/main.py
- server/blueai.py
- server/templates/admin.html
- server/static/style.css
- Dockerfile
- .env.example
- release.json
- branding/app.json
