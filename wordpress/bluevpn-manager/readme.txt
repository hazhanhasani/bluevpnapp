=== BlueVPN Manager ===
Version: 1.1.0
Requires PHP: 8.0

نسخه اول زیرساخت مهاجرت Backend BlueVPN از Railway/PostgreSQL به WordPress/MySQL.

امکانات این نسخه:
- آپدیت مستقیم افزونه از GitHub Releases مخزن hazhanhasani/bluevpnapp
- تشخیص Releaseهای افزونه با tag مستقل bluevpn-manager-vX.Y.Z
- پشتیبانی از آپدیت خودکار وردپرس و بررسی دستی آپدیت
- ایجاد 21 جدول BlueVPN در MySQL/MariaDB
- پنل BlueVPN در wp-admin
- تنظیمات اپ
- مدیریت پایه پلن‌ها و کاربران
- Health API
- mobile/config API
- ثبت‌نام و ورود ایمیلی با PBKDF2 سازگار با Backend پایتون
- Session و Refresh Token
- plans و account API
- Server Location resolve/verify
- Alias مسیرهای قدیمی /api/v1/... و /health
- Cron پاکسازی پایه

مهم:
این نسخه Stage 1 است. هنوز Railway را خاموش نکنید و Base URL اپ اصلی را تغییر ندهید.
یکپارچه‌سازی PasarGuard/Marzban/GuardCore، BluePay، OTP/SMS، AI/Telemetry و Telegram در مراحل بعد اضافه می‌شود.
