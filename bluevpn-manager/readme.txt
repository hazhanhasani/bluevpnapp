=== BlueVPN Manager ===
Version: 4.0.16
Requires PHP: 8.0

زیرساخت مهاجرت Backend BlueVPN از Railway/PostgreSQL به WordPress/MySQL با Migration Bridge امن و قابل Resume.

امکانات این نسخه:
- Migration Bridge امن Railway → WordPress با Token اختصاصی
- انتقال کاملاً خودکار در پس‌زمینه با Resume و Retry بدون رکورد تکراری
- Runner یک‌دقیقه‌ای برای ادامه خودکار Batchها
- حالت Turbo: دریافت ۵۰۰۰ رکوردی ai_connection_events و نوشتن Bulk چندصدتایی در MySQL
- حذف هزاران Query تکی هنگام مهاجرت و Resync افزایشی جدول AI
- مقایسه تعداد رکوردهای PostgreSQL/MySQL و Resync
- حفظ Progress تجمعی در Resync؛ تکمیل مهاجرت دیگر به صفر برنمی‌گردد
- جلوگیری از حلقه Resync وقتی MySQL رکوردهای محلی اضافه دارد
- Retry هدفمند فقط برای جدول‌های دارای کسری واقعی، حداکثر سه بار
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

- Runner زنجیره‌ای داخل صفحه مدیریت برای ادامه مهاجرت حتی در صورت اختلال WP-Cron

نسخه 4.0.6 — Migration Control Center:
- ماشین حالت ۶ مرحله‌ای: Scan → Copy → Initial Verify → Resync/Repair → Final Verify → Ready
- Resume واقعی از Cursor قبلی؛ توقف موقت Progress را پاک نمی‌کند
- Retry محدود و توقف ایمن پس از ۵ خطای متوالی یا ۴ Verify ناموفق
- ترمیم فقط جدول‌های دارای اختلاف/خطا، بدون Resync کامل بی‌دلیل
- تشخیص Stall پس از ۳ دقیقه بدون Progress
- نمایش درصد پوشش واقعی، جدول جاری، کسری، سرعت، ETA و زمان آخرین Verify
- Runner مرورگر با Work Slice کوتاه برای هاست اشتراکی و موبایل
- Cutover فقط پس از Resync ایمنی + Verify واقعی و بدون خطای جدول فعال می‌شود
- بررسی مجدد Cutover، Manifest تازه Railway را می‌خواند تا Ready قدیمی/کاذب باقی نماند


== 4.0.8 ==
* Exact ID Audit برای پیدا کردن رکوردهای واقعاً گمشده به‌جای Resync کور.
* ترمیم دقیق customers و جدول‌های کوچک با دریافت فقط IDهای مفقود.
* تشخیص تعارض Unique به‌جای چهار دور Retry بی‌نتیجه.
* نمایش جدول‌های همگام به شکل «X از Y» برای جلوگیری از جابه‌جایی RTL.

= 4.0.12 =
* Full Admin Control Center restored in WordPress with Railway-era tabs.
* PasarGuard/Marzban/GuardCore management and provider connection tests.
* Manual GuardCore queue, customer sync, manual provision/renew, BluePay and SMS settings.
* BlueAI live/route dashboards, orders, users, plans and database backup.
* Native WordPress subscription bridge for /sub/{token}; PasarGuard/Marzban subscription sources are merged.
* Cutover safety: Railway should remain available until end-to-end order/payment tests pass.

== 4.0.13 ==
* تبدیل تمام تب‌های Control Center به زیرمنوهای مستقل وردپرس.
* حذف نوار تب داخلی؛ هر بخش صفحه مدیریتی مستقل خود را دارد.
* حفظ Dashboard اصلی BlueVPN فقط برای نمای کلی.

= 4.0.16 =
* ورود OTP واقعی شش‌رقمی با IranPayamak روی WordPress/MySQL اضافه شد.
* رابط ورود جدید بر پایه Archive.zip و پنل مدیریت یکپارچه بر پایه admin.zip اضافه شد.
* Endpointهای /auth/otp/request و /auth/otp/verify و صفحه /bluevpn-login/ اضافه شدند.
* مقدار OTP دیتابیس از ۵ به ۶ ارتقا داده می‌شود و auth_mode به phone_otp همگرا می‌شود.

= 4.0.15 =
* انتقال Runtime ربات تلگرام از Railway به WordPress/MySQL با Telegram Webhook.
* انتقال خودکار BOT_TOKEN / GITHUB_TOKEN / ADMIN_IDS از Migration Bridge امن.
* صف Job بومی MySQL برای ZIP deploy، Build و پیگیری GitHub Actions.
* نصب ZIP روی GitHub از طریق Git Data API بدون نیاز به git/Python/Docker روی هاست وردپرس.
* دستورات Status / Build / Unlock / Latest APK / Signing Status و صف دستی GuardCore.
* Railway برای اجرای ربات دیگر لازم نیست.
