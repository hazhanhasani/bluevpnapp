=== BlueVPN Manager ===
Version: 4.3.4
Stable tag: 4.3.4
Requires PHP: 8.0

کنترل‌پلین اصلی BlueVPN روی WordPress/MySQL.

== قابلیت‌های جاری ==
- احراز هویت، حساب، پلن، سفارش، دستگاه و Session روی MySQL.
- Providerهای PasarGuard، Marzban و GuardCore و Subscription Bridge بومی WordPress.
- BlueAI، تبلیغات، اتصال رایگان، BluePay، OTP و اعلان‌های پیامکی.
- بروزرسانی خودکار افزونه با کنترل انتشار و پایش سلامت.
- Backup/Restore، Health dashboard و ابزارهای مدیریتی BlueVPN.
- Migration Bridge فقط برای بازیابی/انتقال داده‌های قدیمی و نه به‌عنوان Runtime اصلی.

== قرارداد نسخه 4.1.6 ==
- Free و Premium با Pool Identity مستقل می‌شوند؛ تغییر پلن/Provider حتی با URL یکسان دقیقاً یک Refresh واقعی ایجاد می‌کند.
- Account Sync عادی فقط خواندنی است و Force Sync فقط در عملیات صریح کاربر انجام می‌شود.
- Subscriptionهای مدیریت‌شده auto-update ندارند و تغییر Pool وسط Connect ممنوع است.
- Schema 1.6.0 با ایندکس‌های ترکیبی برای entitlement، session، device، order، webhook و BlueAI.
- انتشار WordPress فقط پس از Build و Sign موفق APK در Workflow اصلی انجام می‌شود.
- نسخه‌گذاری BlueVPN از الگوی x.y.0 تا x.y.10 پیروی می‌کند؛ بعد از x.y.10 نسخه بعدی x.(y+1).0 است.

== Changelog ==

= 4.3.4 =
* Fixed /mobile/config HTTP 500 caused by stale BlueVPN_Ads helper method names.
* Free session duration and Free policy now refresh independently from local pool readiness.
* A server-side duration reduction clamps an already-active Free session; duration increases apply on the next connection.
* Manual update checks also persist the Free policy returned by WordPress.
= 4.3.3 =
* Update checker is cache-first: manual refresh no longer blocks on GitHub API.
* Release sync is queued in the background and mobile config falls back to the last Stable metadata if release-channel lookup fails.
* BlueAI dashboard labels AI Schema v1 clients as legacy instead of implying they support real Live Heartbeat.
= 4.3.2 =
* BlueAI Engine v2 با یادگیری مستقل Free/Premium و پایش Live همزمان.
* Heartbeat برای کاربران مهمان/رایگان نیز فعال شد؛ بدون جمع‌آوری محتوای ترافیک یا IP مقصد.
* داشبورد Live، سلامت نسخه‌ها، Schema versioning و Capability negotiation به پنل افزوده شد.
* دانش نسخه‌های قبلی حفظ می‌شود و به‌عنوان cold-start fallback کم‌وزن استفاده می‌شود.
= 4.3.1 =
* Added server-controlled Beta/Stable release channels.
* New GitHub APK releases default to Beta and are hidden from normal users.
* Added per-customer Beta Tester flag and per-release force/stop/promote controls.
* Stable promotion reuses the exact APK/SHA without rebuilding.
* Android update checks now authenticate with the current app session so WordPress can select the correct release channel.

= 4.3.0 =
- رفع بنر خالی: تصویر تا Decode موفق در Android نمایش داده نمی‌شود و در تعویض اسلاید تصویر قبلی تا آماده‌شدن بعدی حفظ می‌شود.
- تصاویر تبلیغات MySQL به‌صورت lazy در wp-content/uploads/bluevpn-ads به فایل استاتیک تبدیل می‌شوند؛ REST باینری فقط fallback است.
- Content-Length باینری PHP حذف و خروجی خام در برابر zlib/output-buffer سخت‌سازی شد.
- تست سلامت تبلیغات حالا خود بایت تصویر را Decode می‌کند، نه فقط HTTP 200/Content-Type.

= 4.1.6 =
- تمام Routeهای مخفی لوکیشن انتخاب‌شده تا آخرین گزینه در Failover قابل استفاده‌اند؛ AUTO به‌صورت Batch پیش‌رونده عمل می‌کند و دیگر رتبه‌های بعد از 5/10/18 حذف نمی‌شوند.
- پنجره Start هسته Xray برای Cold Start به 24 ثانیه افزایش یافت و آماده‌شدن Local Proxy تا 5 ثانیه فرصت دارد.
- تأیید اینترنت با چند endpoint و معیار پاسخ واقعی 2xx/3xx/4xx انجام می‌شود تا تغییر پاسخ Cloudflare/Google یا Health endpoint یک VPN سالم را خراب اعلام نکند.
- HTTP local proxy با احراز هویت Basic و SOCKS fallback پشتیبانی می‌شود؛ Dynamic SOCKS در BlueVPN خاموش است تا پروسه UI و CoreVpnService روی یک پورت قطعی باشند.
- Fingerprint کانفیگ، Browser Dialer، Proxy Chain و Policy Group را هم لحاظ می‌کند تا کانفیگ‌های واقعاً متفاوت اشتباهی Deduplicate نشوند.

= 4.1.5 =
- کارت انتخاب لوکیشن و خلاصه وضعیت اتصال در یک کارت واحد ادغام شدند و کارت جداگانه پایین آن حذف شد.
- هیچ کارت/دکمه/Activity با عنوان BlueAI در رابط عمومی Android نمایش داده نمی‌شود؛ موتور هوشمند فقط در پس‌زمینه برای رتبه‌بندی، Failover و پایش کیفیت کار می‌کند.
- وضعیت قابل‌نمایش کاربر فقط شامل لوکیشن، آمادگی/اتصال و نتیجه کلی مسیر است؛ جزئیات داخلی Route و AI همچنان مخفی هستند.

= 4.1.4 =
- خلاصه BlueAI در صفحه اصلی با GUID/لوکیشن انتخاب‌شده همگام شد تا نتیجه قدیمی یک کشور دیگر نمایش داده نشود.
- اطلاعات داخلی Route مانند امتیاز، پینگ نامشخص و تعداد خطاهای پیاپی از UI عمومی حذف شد و فقط در موتور رتبه‌بندی باقی می‌ماند.
- نمایش نوع شبکه برای کاربر خواناتر شد و مقدار خام mobile/cellular به «دیتای موبایل» تبدیل می‌شود.

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
- همگام‌سازی Runtime با v2rayNG 2.2.6؛ AndroidLibXrayLite از ساب‌ماژول رسمی resolve می‌شود و برچسب آن با نسخه Xray-core اشتباه گرفته نمی‌شود.

= 4.1.0 =
- سخت‌سازی جداسازی Free/Premium و حذف Refreshهای ناخواسته.
- رفع انتظار نامحدود Connection Gate و سخت‌سازی چرخه Connect/Disconnect/Switch.
- جداسازی BlueAI از Repair/Import اشتراک و اجرای Local-first scoring.
- ارتقای دیتابیس به Schema 1.6.0 و بهینه‌سازی Queryهای پرتکرار.
- پاک‌سازی CI، حذف snapshotهای تولیدی/تاریخی و یکپارچه‌سازی Release Gate.
