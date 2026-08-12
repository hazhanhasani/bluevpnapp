=== BlueVPN Manager ===
Version: 4.0.34
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
نسخه 4.0.24 مسیرهای عملیاتی اپ، تبلیغات، BlueAI، BluePay، Providerها، OTP، اعلان‌های پیامکی و ربات را روی WordPress/MySQL در اختیار دارد. Railway فقط تا زمانی نگه داشته شود که Final Verify مهاجرت و تست End-to-End APK جدید سبز شوند؛ بعد از آن Backend اصلی می‌تواند WordPress باشد.

- Runner زنجیره‌ای داخل صفحه مدیریت برای ادامه مهاجرت حتی در صورت اختلال WP-Cron

= 4.0.34 =
- رفع ANR صفحه مکان‌ها با حذف enumeration/decode سرورهای MMKV از Main Thread
- resolveUi سریع برای وضعیت پلن؛ resolve کامل فقط در Worker
- رتبه‌بندی SmartSelector با یک snapshot مجاز به‌جای resolve مجدد برای هر سرور
- آماده‌سازی Pool و scoring اتصال در Dispatchers.Default
- Failover با مجموعه GUID فریز‌شده و بدون اسکن مجدد subscription در Main Thread

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


= 4.0.22 =
* بازطراحی Native ورود و ثبت‌نام Android مطابق UI مرجع Archive با زمینه مشکی، Glass Card تیره و Accent نارنجی.
* تب‌های پیامک و ایمیل و حالت ورود/ثبت‌نام ایمیلی در یک رابط یکپارچه.
* ورود پیامکی با +98، OTP شش‌رقمی در شش باکس مستقل و Success متحرک.
* Override امن در Build پس از prepare_android.py تا UI جدید با سورس قدیمی جایگزین نشود.

= 4.0.21 =
* بازطراحی صفحه پلن‌ها برای موبایل و دسکتاپ بدون جدول چندستونه و اسکرول شکسته.
* نمایش هر پلن به‌صورت کارت مستقل با خلاصه قیمت، اعتبار، حجم، دستگاه و Providerها.
* انتقال Routing هر پلن به بخش بازشونده و حذف min-width ثابت 620px.
* گروه‌بندی فرم ساخت پلن به مشخصات اصلی، مسیر سرویس و تنظیمات پیشرفته.
* جمع‌شدن Group ID / Service ID در بخش پیشرفته برای کاهش شلوغی صفحه.
* بهبود عمومی خوانایی جدول‌ها و فاصله‌گذاری موبایل در Control Center.

= 4.0.20 =
* بازطراحی واقعی پنل مدیریت بر پایه ساختار admin.zip به‌صورت Control Center مستقل.
* مخفی‌کردن کامل نوار بالا و منوی wp-admin فقط روی صفحات BlueVPN؛ رفع تداخل و منوی کشویی خراب در موبایل.
* سایدبار اختصاصی Responsive با لینک بازگشت به وردپرس و ناوبری کامل همه بخش‌ها.
* بازگرداندن تبلیغات و اتصال رایگان به سایدبار BlueVPN.
* CRUD کامل تبلیغات: افزودن، ویرایش، حذف، فعال/غیرفعال، تعویض تصویر، URL مقصد و زمان‌بندی.
* نمایش تبلیغات موجود به‌صورت کارت‌های Responsive و افزودن دکمه واضح «افزودن تبلیغ».
* حفظ کامل Runtime ربات/GitHub و اصلاحات v4.0.19 بدون تغییر در PAT.

= 4.0.19 =
* رفع رگرسیون GitHub Build: repository_dispatch دوباره روش اصلی است و workflow_dispatch فقط fallback است.
* سازگاری با همان PAT قدیمی Railway که Contents:write داشت و بدون نیاز اجباری به Actions:write Build را شروع می‌کرد.
* نمایش Trigger واقعی در پیام ربات و گزارش خطای جداگانه برای هر دو روش.
* بازگردانی کامل بخش مدیریت تبلیغات و ذخیره تصویر بنر در MySQL.
* بازگردانی Endpoint باینری /api/v1/ad-assets/{id} و بازیابی خودکار لینک‌های قدیمی /media/ads و URLهای Railway.
* اصلاح قرارداد Android تبلیغات از interval_seconds به interval_ms.
* بازگردانی BlueAI runtime: /ai/events، /ai/recommendations، /ai/dashboard و /feedback.
* ثبت Heartbeat و اتصال زنده BlueAI، Route Aggregate، scoring، circuit-breaker و داشبورد مدیریتی.
* بازگردانی خرید BluePay، Poll، checkout lifecycle و Webhook امضاشده با فعال‌سازی idempotent.
* بازگردانی اتصال شماره موبایل به حساب و account/sync واقعی با Providerها.
* تکمیل GuardCore API provisioning/sync و Routing پلن‌ها برای PasarGuard/Marzban/GuardCore.
* بازگردانی مدیریت اتصال رایگان و Tapsell و Endpointهای free subscription.
* تست اتصال WordPress اکنون Advertising contract، Asset MySQL و جداول BlueAI را هم بررسی می‌کند.

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

== Changelog ==

= 4.0.33 =
- اصلاح چرخه واقعی Start/Stop هسته Xray روی v2rayNG 2.2.6 و جلوگیری از Race تعویض سرور.
- ارسال GUID دقیق سرویس در RUNNING/START_SUCCESS و عدم اعتماد به selected GUID متغیر MMKV.
- قفل متقابل Import اشتراک و Connect/Connected برای جلوگیری از تغییر Pool وسط اتصال.
- Last-Known-Good اختصاصی همان حساب Premium با حذف کامل سرورهای Free از fallback.
- حذف Forced Sync خودکار هنگام بازشدن Locations و از مسیر تعمیر BlueAI.

= 4.0.31 =
* GitHub Updater اکنون از GITHUB_TOKEN مهاجرت‌شده ربات برای Release API استفاده می‌کند؛ مناسب مخزن خصوصی و جلوگیری از Rate Limit ناشناس.
* دانلود Release Asset خصوصی از API رسمی GitHub با Authorization و application/octet-stream انجام می‌شود.
* /health وضعیت امن Updater شامل authenticated/status/target را بدون افشای Token گزارش می‌کند.
* WordPress Release Barrier خطای واقعی Timeout/Updater را گزارش می‌کند و خطای موقت git push دیگر به‌عنوان علت اصلی نمایش داده نمی‌شود.
* اگر سایت هنوز روی Manager قدیمی باشد، Workflow پیام WORDPRESS_BOOTSTRAP_REQUIRED می‌دهد تا فقط یک‌بار افزونه جدید دستی نصب شود؛ پس از آن آپدیت‌ها خودکار می‌شوند.

= 4.0.29 =
* WordPress Release Barrier: بسته BlueVPN Manager اکنون در همان Workflow ساخت APK منتشر می‌شود.
* Build اصلی تا وقتی tag مستقل bluevpn-manager-vX.Y.Z و asset استاندارد bluevpn-manager.zip در GitHub تأیید نشود ادامه پیدا نمی‌کند.
* نسخه APK و Backend دیگر نمی‌توانند به‌صورت موفق با چند نسخه اختلاف منتشر شوند.
* Workflow مستقل Release BlueVPN Manager به‌عنوان fallback و مسیر دستی حفظ شده است.

= 4.0.28 =
* بازیابی Workflowهای .github که در بسته 4.0.27 حذف شده بودند.
* انتشار خودکار BlueVPN Manager در هر Push مرتبط به main، مستقل از موفق یا ناموفق بودن Gradle Android.
* ساخت قرارداد ثابت GitHub Release با tag bluevpn-manager-vX.Y.Z و asset bluevpn-manager.zip.
* اعتبارسنجی نسخه Header/Constant/readme، PHP lint، ساخت ZIP، SHA256 و دانلود مجدد Asset بعد از Release.
* اجرای مجدد Release پس از پایان Build APK برای پوشش نسخه‌ای که Workflow اصلی در GitHub همگام می‌کند.


= 4.0.26 =
* Production Completion برای WordPress/MySQL.
* Backup خصوصی زمان‌بندی‌شده با نگهداری ۷ نسخه و Snapshot قبل از Restore.
* Restore واقعی با Checksum، Transaction و Rollback.
* داشبورد Health برای DB، SMS، پرداخت، Backup، Cron، Cutover و Providerها.
* ویرایش کامل، حذف نرم و بازیابی پلن‌ها.
* صفحه جزئیات کاربر با مدیریت دستگاه‌ها، Sessionها، خروج اجباری و تغییر/تمدید پلن.
* Rate Limit برای Login/Register ایمیلی.
* تفکیک وضعیت صف SMS از پذیرش Provider و نمایش پوشش Runtime هر Template.
* ابزار Final Cutover برای قطع Cron/Token مهاجرت Railway پس از Backup.

= 4.0.25 =
* ادغام طراحی جدید ورود Android با Guardهای پایداری و جلوگیری از Render قدیمی/همزمان.
* همگام‌سازی Snapshot و Generator اندروید و اضافه‌شدن Release Regression Gate.
* تکمیل صف SMS با زمان شروع ارسال، بازیابی امن‌تر و reconciliation رویدادهای سفارش/اشتراک.
* اصلاح قرارداد mobile config و entitlement حساب کاربری.


= 4.0.24 =
* تکمیل موتور اعلان پیامکی WordPress/MySQL با ۳۸ پترن، صف ارسال، Retry و بازیابی پیام‌های گیرکرده.
* رفع ارسال‌نشدن پترن‌های بدون متغیر با ارسال attributes به شکل Object خالی.
* پردازش صف در پایان درخواست علاوه بر WP-Cron برای جلوگیری از وابستگی کامل به ترافیک سایت.
* افزودن تست پترن، ارسال عمومی، Retry دستی، گزارش صف و بازیابی اعلان‌های سفارش/اشتراک.
* اتصال رویدادهای پرداخت، فعال‌سازی دستی، ورود دستگاه جدید، تغییر شماره و قفل امنیتی به سیستم پیام.
* حفظ Fix کامپایل Android برای setLineSpacing با مقدار Float.
