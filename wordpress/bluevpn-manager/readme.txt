=== BlueVPN Manager ===
Version: 1.2.1
Requires PHP: 8.0

زیرساخت مهاجرت Backend BlueVPN از Railway/PostgreSQL به WordPress/MySQL با Migration Bridge امن و قابل Resume.

امکانات این نسخه:
- Migration Bridge امن Railway → WordPress با Token اختصاصی
- انتقال مرحله‌ای و Resume خودکار بدون رکورد تکراری
- مقایسه تعداد رکوردهای PostgreSQL/MySQL و Resync
- Dual Sync آزمایشی با WP-Cron
- انتقال Secretها با رمزگذاری مجدد سمت WordPress
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
این نسخه Stage 2 است. هنوز Railway را خاموش نکنید و Base URL اپ اصلی را تغییر ندهید تا مهاجرت و Resync کامل شود.
یکپارچه‌سازی PasarGuard/Marzban/GuardCore، BluePay، OTP/SMS، AI/Telemetry و Telegram در مراحل بعد اضافه می‌شود.
