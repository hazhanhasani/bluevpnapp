=== BlueVPN Manager ===
Version: 4.1.3
Stable tag: 4.1.3
Requires PHP: 8.0

کنترل‌پلین اصلی BlueVPN روی WordPress/MySQL.

== قابلیت‌های جاری ==
- احراز هویت، حساب، پلن، سفارش، دستگاه و Session روی MySQL.
- Providerهای PasarGuard، Marzban و GuardCore و Subscription Bridge بومی WordPress.
- BlueAI، تبلیغات، اتصال رایگان، BluePay، OTP و اعلان‌های پیامکی.
- ربات/صف Build و GitHub Updater با Release Barrier و Health diagnostics.
- Backup/Restore، Health dashboard و ابزارهای مدیریتی BlueVPN.
- Migration Bridge فقط برای بازیابی/انتقال داده‌های قدیمی و نه به‌عنوان Runtime اصلی.

== قرارداد نسخه 4.1.3 ==
- Free و Premium با Pool Identity مستقل می‌شوند؛ تغییر پلن/Provider حتی با URL یکسان دقیقاً یک Refresh واقعی ایجاد می‌کند.
- Account Sync عادی فقط خواندنی است و Force Sync فقط در عملیات صریح کاربر انجام می‌شود.
- Subscriptionهای مدیریت‌شده auto-update ندارند و تغییر Pool وسط Connect ممنوع است.
- Schema 1.6.0 با ایندکس‌های ترکیبی برای entitlement، session، device، order، webhook و BlueAI.
- انتشار WordPress فقط پس از Build و Sign موفق APK در Workflow اصلی انجام می‌شود.
- نسخه‌گذاری BlueVPN از الگوی x.y.0 تا x.y.10 پیروی می‌کند؛ بعد از x.y.10 نسخه بعدی x.(y+1).0 است.

== Changelog ==
= 4.1.3 =
- قبل از شروع VPN، کانفیگ واقعی هر Route مخفی با سازنده رسمی v2rayNG تولید/اعتبارسنجی می‌شود؛ لوکیشن بدون کانفیگ قابل اجرا وارد اتصال نمی‌شود.
- GUID انتخاب‌شده مستقیماً تا پروسه CoreVpnService/CoreServiceManager منتقل می‌شود تا daemon سرور دیگری را از MMKV انتخاب نکند.
- Profile ناقص یا Import نیمه‌کاره قبل از ساخت TUN رد می‌شود و Failover سراغ Route بعدی همان لوکیشن می‌رود.

= 4.1.2 =
- نمایش فقط لوکیشن‌ها؛ Route/GUID و تعداد مسیرهای داخلی دیگر در رابط کاربر نمایش داده نمی‌شود.
- انتخاب یک لوکیشن، موتور اتصال را به همان کشور محدود می‌کند و بهترین اتصال داخلی به‌صورت خودکار انتخاب می‌شود.
- انتخاب‌های MANUAL_SERVER باقی‌مانده از نسخه‌های قبلی به MANUAL_LOCATION مهاجرت می‌شوند.
- Failover، امتیازدهی، پینگ و سلامت مسیرها همچنان داخلی باقی می‌مانند و بدون نمایش جزئیات به کاربر اجرا می‌شوند.

= 4.1.1 =
- رفع Race پایان Failover که پس از شکست آخرین مسیر، UI را دوباره وارد «در حال اتصال/شناسایی» می‌کرد.
- جلوگیری از قبول Ping دیررس پس از خطای نهایی و جلوگیری از زنده‌شدن دوباره Verification.
- نگه‌داشتن Runtime Gate تا دریافت توقف واقعی CoreVpnService و جلوگیری از تغییر Subscription در زمان آزادسازی Xray.
- نمایش علت واقعی شکست آخرین Candidate به‌جای خطای عمومی و مبهم.
- بازگردانی AndroidLibXrayLite/Xray به v26.6.27 برای جفت‌شدن دقیق با v2rayNG 2.2.6 و حذف backport آزمایشی 26.7.28.

= 4.1.0 =
- سخت‌سازی جداسازی Free/Premium و حذف Refreshهای ناخواسته.
- رفع انتظار نامحدود Connection Gate و سخت‌سازی چرخه Connect/Disconnect/Switch.
- جداسازی BlueAI از Repair/Import اشتراک و اجرای Local-first scoring.
- ارتقای دیتابیس به Schema 1.6.0 و بهینه‌سازی Queryهای پرتکرار.
- پاک‌سازی CI، حذف snapshotهای تولیدی/تاریخی و یکپارچه‌سازی Release Gate.
