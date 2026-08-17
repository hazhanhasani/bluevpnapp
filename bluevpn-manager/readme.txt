=== BlueVPN Manager ===
Version: 4.13.8
Stable tag: 4.13.8
Requires PHP: 8.0


== 4.13.8 ==
- Full cross-component release audit for Android, BlueVPN Manager and BlueVPN Site.
- Adds aggregate guards for Android overlay completeness, RuntimeAudit enum references and network-recovery API wiring.
- Adds release metadata synchronization and site version consistency gates.

== 4.13.7 ==
- Fixes BlueVpnNetworkRecoveryManager enum/API compile errors.
- Uses the existing NETWORK_CHANGE audit event with lost/available details.
- Adds debounced requestNetworkRecovery() to the WARP keepalive service.

== 4.13.6 ==
- Fixes Android compile failure: BlueVpnNetworkRecoveryManager is now imported by BlueVpnHomeActivity.
- prepare_android.py now copies BlueVpnNetworkRecoveryManager.kt into the pinned v2rayNG source tree.

== 4.13.5 ==
- Removes all remaining hardcoded 4.12.8/41208 release pins from shipped regression tests.
- Historical feature tests now validate current release metadata instead of freezing an old app version.
- Prevents repeated one-by-one CI failures after future version bumps.

== 4.13.4 ==
- Removes stale hardcoded 4.12.8 Fast CI release expectations.
- Fast CI now validates current branding/release synchronization dynamically.

== 4.13.3 ==
- Removes stale hardcoded 4.12.8 expectations from the Elementor regression test.
- Regression now checks release/branding synchronization and deterministic version_code.

== 4.13.2 ==
- Restores the exact flat-ZIP resolver compatibility path expected by the regression gate.
- Keeps the 4.13.1 manager-only and wrapped-ZIP root resolver fixes intact.

== 4.13.1 ==
- Fix manager-only ZIP root detection after single-directory extraction collapse.
- Resolve BlueVPN roots from sentinel files instead of a fixed nesting-depth scan.
- Preserve bluevpn-manager/ path when deploying manager-only packages.

== 4.13.0 ==
- Telegram ZIP downloads now use direct streaming with byte-length verification.
- Detects truncated Telegram/CDN responses before ZipArchive parsing.
- Five bounded retries and cURL-first transport for cPanel/PHP hosts.

== 4.12.10 ==
- GitHub delta-only deployment to reduce Git Data API traffic.
- Shared transient retry handling for GitHub blobs/trees/commits/refs.
- Readme and plugin release metadata synchronized for GitHub Actions.

== 4.12.8 ==
* ویدئوی استوری اتصال رایگان در اندروید دیگر صرفاً با آماده‌شدن صدا Ready محسوب نمی‌شود؛ اولین فریم واقعی ویدئو باید Render شود.
* ویدئوی کوتاه تبلیغ قبل از پخش در Cache محلی دریافت می‌شود تا مشکلات Range/MIME/streaming هاست باعث صفحه سیاه نشود.
* Player از Surface صریح TextureView استفاده می‌کند و اگر فریم ویدئو نیاید، تبلیغ Fail-open می‌شود تا کاربر روی صفحه سیاه گیر نکند.
* پنل تبلیغات برای آپلود جدید، MP4 با H.264/AVC + AAC را به‌عنوان فرمت سازگار با Android الزام/راهنمایی می‌کند.
* پوسته همراه پروژه به BlueVPN Site 1.3.14 همگام شد.

== 4.12.4 ==
* زمان پیام‌های پشتیبانی در اندروید از UTC دیتابیس به Asia/Tehran تبدیل می‌شود و دیگر ساعت خام سرور نمایش داده نمی‌شود.
* Parser مشترک اندروید اکنون timestampهای MySQL با قالب yyyy-MM-dd HH:mm:ss را نیز به‌عنوان UTC می‌خواند.
* اصلاحات Kotlin نسخه 4.11.9 بدون تغییر حفظ شده‌اند.

== 4.11.8 ==
* Support: انتخاب درخواست سه‌مرحله‌ای «بخش → موضوع → پیام» و رفع باگ ازبین‌رفتن انتخاب موضوع.
* Support: Retry امن و idempotent برای ساخت گفتگو و ارسال پیام؛ جلوگیری از ایجاد گفتگو/پیام تکراری هنگام timeout.
* Support: انتقال گفتگو بین بخش/موضوع/اپراتور با اعتبارسنجی سمت سرور و فیلتر UI.
* Support: Topic schema 1.2.0، موضوع‌های پیش‌فرض، اولویت موضوع و chooser اسکرول‌پذیر.
* GuardCore: اسکن اشتراک‌های گمشده اکنون API GuardCore را مانند PasarGuard و Marzban بررسی می‌کند.
* GuardCore: Mapping گمشده با تطبیق سخت‌گیرانه بازیابی و Serviceهای پلن بدون تغییر حجم/انقضا همگام می‌شوند.
* Release hardening: PHP/Regression/manifest gates برای انتشار نهایی سخت‌گیرانه‌تر شدند.

== 4.11.7 ==
* Build pipeline: manual Android builds now default to Fast CI while production repository-dispatch builds remain Full.
* Build cache: pinned Aether, libhevtun and libv2ray artifacts are reused across compatible Android builds.
* Android build: compile and assemble run in one Gradle invocation; signed Fast artifacts are uploaded before production WordPress convergence.
* Existing provider group/inbound selection and paid provisioning behavior from 4.7.5 remains unchanged.

== 4.7.4 ==
* Fix: PasarGuard/Marzban/GuardCore panels can be deleted safely from Control Center.
* Fix: legacy plans without provider IDs automatically use active PasarGuard/Marzban during paid/manual provisioning.
* Fix: manual GuardCore Global Subscription is automatically attached to paid users when no explicit GuardCore route exists.
* Fix: Provider repair scans legacy active subscriptions even when plan provider IDs are empty.

== 4.7.3 ==
* Cloudflare Endpoint Racing روی رنج‌ها و پورت‌های معتبر WARP اضافه شد.
* آخرین Edge سالم برای هر نوع شبکه ذخیره می‌شود و Endpoint خراب cooldown می‌گیرد.
* Turbo scan و WireGuard fallback به‌صورت پیش‌فرض فعال شدند.
* WARP exit guard نسخه 4.6.8 همچنان فعال است و خروجی IR را رد می‌کند.

== 4.6.8 ==
= WARP exit guard: rejects blocked egress countries (IR by default) and fails over to the configured Free pool. =

== 4.6.7 ==
* Free WARP entitlement is independent from legacy free-subscription availability.
* Adds WARP/Pool engine policy controls to WordPress and schema 1.11.0.

== 4.6.4 ==
= Pool sync single-owner / nonblocking locations =

== 4.6.0 ==
* رفع خطای Kotlin در BlueVpnHomeActivity هنگام ارسال Live heartbeat داخل withContext؛ Activity Context به‌صورت صریح به BlueVpnLiveReporter داده می‌شود.
* تمام قابلیت‌های BlueAI Subscription Pool Orchestrator، تفکیک دائمی Free/Premium و Live Telemetry نسخه‌های قبل حفظ شده‌اند.
* این Hotfix تغییری در API، Schema یا مسیر v2rayNG/Xray ایجاد نمی‌کند.


== 4.4.6 ==
* دریافت Beta به نشست واقعی حساب متصل شد؛ Access Token در صورت نیاز با Refresh Token بازسازی می‌شود.
* بررسی دستی Beta پس از Sync پس‌زمینه، metadata را دوباره می‌خواند تا Release تازه از دست نرود.
* Cache کانال بروزرسانی در مرزهای Login/Logout/Invalid Session پاک می‌شود.
* diagnostic احراز هویت release در mobile/config اضافه شد.

== تغییرات 4.4.1 ==
* جایگذاری هوشمند پترن‌ها اضافه شد؛ BlueVPN بر اساس متن، عنوان و قرارداد متغیرهای هر پیام بهترین پترن IranPayamak را پیشنهاد و روی خانه‌های خالی تنظیم می‌کند.
* انتخاب‌های دستی معتبر حفظ می‌شوند و فقط دکمه «بازچینی کامل» با تأیید مدیر اجازه جایگزینی آن‌ها را دارد.
* پترن دارای متغیر ناسازگار هرگز خودکار متصل نمی‌شود تا attributes اشتباه به Provider ارسال نشود.
* بعد از «تازه‌سازی پترن‌ها» جایگذاری هوشمند خالی‌ها خودکار اجرا می‌شود و گزارش اطمینان/موارد مبهم در پنل نمایش داده می‌شود.


کنترل‌پلین اصلی BlueVPN روی WordPress/MySQL.

== تغییرات 4.3.10 ==
* اتصال SMS/OTP با مستندات رسمی FarazSMS / IranPayamak همگام شد.
* فهرست پترن‌ها از `GET /ws/v1/patterns` بدون body و بدون فیلتر `share=1` دریافت می‌شود تا پترن‌های خصوصی حساب اشتباهاً حذف نشوند.
* وضعیت Active در BlueVPN فیلتر می‌شود و اگر فهرست خالی باشد، پترن تنظیم‌شده با `GET /patterns/{code}` به‌صورت دقیق بازیابی/اعتبارسنجی می‌شود.
* پاسخ‌های Provider با `status=error/failed` حتی در HTTP 2xx خطا محسوب می‌شوند.

== تغییرات 4.3.8 ==
- برای بنرها و استوری‌های تبلیغاتی «عملکرد هنگام لمس» اضافه شد: ورود/ثبت‌نام، مشاهده پلن‌ها، خرید اشتراک، حساب کاربری، تمدید/ارتقا، تنظیمات یا لینک خارجی.
- مسیرهای داخلی به‌صورت allow-list و با قرارداد `bluevpn://...` ساخته می‌شوند؛ پنل نمی‌تواند Intent یا Activity دلخواه به Android تزریق کند.
- برای «خرید اشتراک» می‌توان یک پلن مشخص تعیین کرد؛ کاربر مهمان ابتدا احراز هویت می‌شود و پس از ورود همان پلن در ابتدای فهرست با Highlight نمایش داده می‌شود.
- URL وب اختیاری به‌عنوان fallback نگه داشته می‌شود تا نسخه‌های قدیمی اپ همچنان بتوانند مقصد امن http/https را باز کنند.
- لمس CTA در استوری اجباری، اتصال Free درحال آماده‌سازی را قبل از ورود به مسیر خرید متوقف می‌کند و به‌عنوان مشاهده کامل تبلیغ حساب نمی‌شود.

== تغییرات 4.3.7 ==
- بخش «استوری تبلیغاتی اتصال رایگان» به پنل تبلیغات اضافه شد؛ عکس و ویدئو با انتخاب تصادفی پشتیبانی می‌شوند.
- اتصال Free پس از RUNNING شدن Xray تا پایان استوری در حالت Pending می‌ماند؛ تایمر Session و وضعیت Connected فقط پس از پایان تبلیغ ثبت می‌شوند.
- خروج از استوری اجباری با Home/Recent Apps اتصال در حال آماده‌سازی را متوقف می‌کند تا تبلیغ قابل دورزدن نباشد.
- اگر رسانه یا تنظیمات تبلیغ به‌دلیل خطای شبکه/هاست قابل دریافت نباشد، مسیر Fail-open است تا خرابی تبلیغ سرویس رایگان را برای همه قطع نکند.
- اگر استوری First-party نمایش داده شود، Tapsell روی همان اتصال دوباره نمایش داده نمی‌شود.

== تغییرات 4.3.6 ==
- قرارداد تبلیغات `/api/v1/mobile/config` اصلاح شد: کلید canonical `advertising` دوباره برای Android برمی‌گردد و `ads` فقط alias سازگاری است.
- تنظیمات Tapsell دوباره در کلید `tapsell` به اپ ارسال می‌شود؛ Regression ایجادشده هنگام بازنویسی Release Channels حذف شد.
- Android هم `advertising` و هم `ads` را می‌پذیرد تا هنگام بروزرسانی جداگانه Manager و APK، بنرها ناپدید نشوند.
- این اصلاح فقط Control Plane/تبلیغات است و Runtime رسمی v2rayNG/Xray تغییر نکرده است.

== قابلیت‌های جاری ==
- احراز هویت، حساب، پلن، سفارش، دستگاه و Session روی MySQL.
- Providerهای PasarGuard، Marzban و GuardCore و Subscription Bridge بومی WordPress.
- BlueAI، تبلیغات، اتصال رایگان، BluPal، OTP و اعلان‌های پیامکی.
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

== 4.4.5 ==
* Home: نمایش گرافیکی سرعت دانلود/آپلود واقعی در فضای زیر دکمه اتصال.
* Home: تایمر Free از Header به پنل Telemetry منتقل شد؛ Premium مدت دقیق اتصال را نمایش می‌دهد.
* BlueAI 2.1 / Schema v3: اندازه‌گیری RTT واقعی چندنمونه‌ای از داخل تونل Xray، همراه min/max/jitter/loss.
* Live dashboard: Ping صفر/تخمینی نمایش داده نمی‌شود و Routeهای یادگرفته‌شده از Heartbeat واقعی latency می‌گیرند.



= 4.3.5 =
* Beta Testerها اکنون تمام مسیر بروزرسانی خودکار را مانند Stable دریافت می‌کنند.
* سیاست دانلود خودکار برای Stable و Beta در پنل به‌صورت جداگانه قابل کنترل است.
* Force Update نسخه Beta همچنان per-release است و فقط روی Beta Testerهای مجاز اثر می‌گذارد.
* Android کانال release را ذخیره می‌کند و دیالوگ بروزرسانی Beta را با برچسب آزمایشی نمایش می‌دهد.
* دانلود، SHA validation و PackageInstaller برای Beta و Stable یکسان است.

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

== 4.4.4 ==
* PasarGuard: گروه‌های فعال به‌صورت Live نمایش داده می‌شوند؛ انتخاب per-plan اعمال می‌شود و انتخاب خالی یعنی همه گروه‌های فعال.
* Marzban: Inboundهای فعال به‌صورت Live نمایش داده می‌شوند؛ انتخاب per-plan ذخیره و در Provision/Repair اعمال می‌شود و انتخاب خالی یعنی همه Inboundهای فعال.
* Repair: کاربران موجود نیز access map خود را دوباره دریافت می‌کنند، بدون تمدید یا Reset مصرف.
* Hardening: proxy_settings خالی/لیستی دیگر باعث HTTP 422 در PasarGuard/Marzban نمی‌شود.
