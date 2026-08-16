# BlueVPN 4.10.4

Version 4.10.4 introduces a two-mode GitHub Actions pipeline. Manual `workflow_dispatch` builds default to **fast** mode, while production `repository_dispatch` builds remain **full** by default. Fast mode still performs the real Android compile, assemble, signing and regression gates, but uploads the signed APK immediately after signing and skips the production WordPress convergence / GitHub Release publication barriers.

The Android pipeline now caches the pinned Aether native binaries, `libhevtun`, and the resolved `libv2ray.aar`. Gradle compile + assemble are executed in one Gradle invocation with build cache and parallel execution enabled, avoiding a second project configuration pass. Cache keys remain tied to pinned runtime inputs, not the BlueVPN release version, so a normal app version bump does not force a needless Aether rebuild.

Version 4.7.9 added permissionless Android SMS OTP autofill with one-time SMS User Consent and no `READ_SMS` / `RECEIVE_SMS` permission. Version 4.7.6 hardened the free WARP path around a single pinned Aether process, persistent identity, native scan/quick reconnect and adaptive transport history.

Local verification for 4.10.4: **203 Python regression tests passed**, all BlueVPN Manager PHP files passed syntax lint, the release validator passed, all three GitHub workflow YAML files parsed successfully, and Python CI scripts compiled. A complete Android Gradle build cannot be materialized in this artifact sandbox because the pinned upstream v2rayNG checkout requires outbound GitHub access; GitHub Actions remains the authoritative Android build environment.

### CI modes

- `workflow_dispatch → build_mode=fast` — signed test APK as quickly as possible; no production WordPress wait or GitHub Release publication.
- `workflow_dispatch → build_mode=full` — complete production release path.
- `repository_dispatch` — defaults to `full` so the existing WordPress/build automation keeps its production safety barriers unless the caller explicitly chooses fast mode.

## v4.5.9 — Fast Authentication + Background Bootstrap

- Email/password and OTP verification finish as soon as a valid BlueVPN session is issued.
- Subscription import, Provider reconciliation, Free/Premium pool preparation and BlueAI bootstrap are deferred to a background executor and no longer block the login screen.
- Normal account login returns to the caller immediately; purchase/plans/renew deep-links remain in-place and render from local session state.
- The soft keyboard is dismissed after successful authentication.
- Free/Premium isolation, hidden-route location architecture, exhaustive scanners, IRCF intelligence and connection verification from previous releases remain intact.

## v4.5.7 — Full Free Scanner + Visible Connection Status

- Tests every allowed Free/Premium route with the official v2rayNG TestService on the current network before AUTO ranking.
- Never promotes Core RUNNING to BlueVPN CONNECTED until an HTTP proof traverses the selected Xray tunnel.
- Connecting orb remains animated on low-end devices and removes the stray outer arc.
- Download/upload telemetry samples locally at 250–400 ms and uses EWMA/zero-hold to avoid 0/value flicker.


## v4.5.3 — Free subscription import repair

- Free subscription rows with zero imported profiles are always refreshed through stock v2rayNG `AngConfigManager.updateConfigViaSub`, even inside the normal refresh TTL.
- Empty/new imports receive one bounded retry; BlueVPN never substitutes its own parser.
- A Free pool is marked installed only after the subscription row actually owns decodable server GUIDs.
- Fixes the case where the same URL imports ~200 profiles in v2rayNG but BlueVPN shows 0 Free locations.

## 4.4.6 Beta Channel Auth + Fresh Release Check

- بررسی بروزرسانی Beta دیگر HTTP جداگانه ندارد و از مسیر احراز هویت اصلی حساب استفاده می‌کند.
- اگر Access Token منقضی/خالی شده ولی Refresh Token معتبر باشد، قبل از انتخاب کانال نسخه به‌صورت خودکار نشست تازه می‌شود.
- بررسی دستی نسخه بعد از trigger کردن Sync پس‌زمینه WordPress حداکثر دو بار با فاصله کوتاه metadata را دوباره می‌خواند تا Beta تازه منتشرشده اشتباهاً «آخرین نسخه» گزارش نشود.
- Cache تنظیمات موبایل هنگام Login/Logout/Invalid Session پاک می‌شود تا Stable قبلی روی حساب Beta باقی نماند.
- WordPress وضعیت احراز هویت release را برای عیب‌یابی برمی‌گرداند، اما حق دریافت Beta همچنان فقط با `beta_tester=1` و نشست معتبر تعیین می‌شود.

## 4.4.5 Live Connection Telemetry + Real BlueAI RTT

- فضای زیر کنترل اتصال اکنون سه کارت زنده دارد: دانلود، زمان اتصال/باقی‌مانده Free و آپلود.
- Free timer از Header حذف و به همان پنل زنده منتقل شده است؛ Premium زمان دقیق اتصال را به HH:MM:SS نمایش می‌دهد.
- سرعت دانلود/آپلود از TrafficStats واقعی UID سرویس VPN و اختلاف بایت در بازه زمانی زنده محاسبه می‌شود.
- BlueAI Engine 2.1 / AI Schema v3 در هر Heartbeat چند RTT واقعی را از داخل Xray HTTP proxy می‌سنجد؛ میانگین، min، max، jitter و packet loss گزارش می‌شوند.
- Dashboard دیگر 0 ms را به‌عنوان Ping معتبر نشان نمی‌دهد؛ هر ردیف Live فقط نمونه واقعی تونل را نمایش می‌دهد و آمار نسخه‌ها نیز از Heartbeatهای واقعی تغذیه می‌شود.
- v2rayNG 2.2.6 / Xray lifecycle و config builder دست‌نخورده‌اند.

## 4.4.4 Missing Provider Subscription Repair

- بخش کاربران اکنون گزینه «همگام‌سازی اشتراک‌های گمشده Provider» دارد.
- فقط کاربران فعال با اشتراک فعال و پلن دارای PasarGuard/Marzban اسکن می‌شوند.
- وجود کاربر روی Provider از API واقعی بررسی می‌شود؛ اگر وجود نداشته باشد ساخته و به همان حساب BlueVPN متصل می‌شود.
- Repair از اعتبار فعلی WordPress استفاده می‌کند و هرگز روز جدید به اشتراک اضافه نمی‌کند یا مصرف را Reset نمی‌کند.
- اگر کاربر روی Provider وجود داشته باشد ولی نگاشت محلی/Subscription URL گم شده باشد، اتصال محلی بازیابی می‌شود.
- عملیات Bulk به صورت Batch/AJAX اجرا می‌شود تا تعداد زیاد کاربران باعث Timeout یک درخواست طولانی نشود.
- امکان «ترمیم Provider» برای یک کاربر نیز در لیست و جزئیات کاربر اضافه شده است.

## 4.4.2 Smart SMS pattern assignment

- Pattern sync now automatically fills empty BlueVPN message-template selections using a deterministic compatibility score.
- Variable contracts are a hard safety gate; a Provider pattern with incompatible attributes is never auto-wired.
- Matching uses title/body text overlap and confidence/ambiguity checks, while preserving every valid manual selection.
- Admin can run safe fill-only mapping after sync or explicitly confirm a full remap.
- The last smart-map report shows assigned, ambiguous and unmatched counts plus per-row confidence.
- Multi-page IranPayamak discovery and Manager-from-bot deployment from 4.4.0 remain intact.

## 4.4.0 FarazSMS / IranPayamak official API alignment

- Pattern discovery now follows the official `GET /ws/v1/patterns` contract without a request body and without optional provider filters. The provider documents those filters as nullable, so BlueVPN fetches the account list and performs the `active` filter locally.
- The previous `share=1` restriction was removed because it could hide private/account-owned patterns and produce a false `0 active patterns` result.
- If the list endpoint still returns no usable rows while an administrator already configured a pattern code, BlueVPN performs the official `GET /ws/v1/patterns/{code}` detail lookup and recovers that exact active pattern.
- Provider payloads with string status values such as `error`/`failed` are now treated as failures even when the HTTP status is 2xx.
- OTP sending remains `POST /ws/v1/sms/pattern` with `Api-Key`, JSON `code`, `attributes`, `recipient`, `line_number`, and `number_format`.
- Advertising/deep-link, Beta/Stable, BlueAI and v2rayNG/Xray behavior are unchanged.

## 4.3.8 in-app advertising destinations

- Banner and Free Story campaigns can route to an allow-listed in-app destination: auth/register, plans, purchase, account, renew/upgrade, settings, or an external HTTPS/HTTP URL.
- A campaign may target a specific active plan id. Guests authenticate first; after login the selected plan is sorted to the top and highlighted instead of silently creating a payment order.
- WordPress emits a canonical `target_action`, optional `target_plan_id`, compatibility `target_url`, and a human-readable `bluevpn://...` deep-link contract.
- Android never launches arbitrary classes or custom schemes received from the control plane. Internal actions are mapped locally and web fallbacks are restricted to HTTP(S).
- Following a CTA from a mandatory Free Story stops the pending Free VPN before navigation, so ad interaction cannot bypass the completion gate.
- The protected v2rayNG 2.2.6 / Xray runtime is unchanged.

## 4.3.5 Beta update parity

- Beta Testerها همان چرخه بررسی خودکار، دانلود خودکار، SHA verification و نصب داخلی Stable را دریافت می‌کنند.
- سیاست Auto Update برای `Stable` و `Beta` از پنل مستقل است؛ هر دو به‌صورت پیش‌فرض فعال‌اند.
- Force Update Beta همچنان per-release است و فقط روی حساب‌های `beta_tester=1` اثر می‌گذارد.
- Android کانال انتخاب‌شده را ذخیره می‌کند و در دیالوگ بروزرسانی Beta، برچسب آزمایشی را واضح نمایش می‌دهد.
- محدودیت امنیتی Android پابرجاست: APK می‌تواند خودکار دانلود و آماده شود، اما تأیید نهایی نصب بسته به نسخه Android/سیاست دستگاه ممکن است از کاربر خواسته شود.


## 4.3.7 advertising contract regression fix

- Restores the canonical `advertising` object in `/api/v1/mobile/config`; `ads` is retained only as a compatibility alias.
- Restores the `tapsell` object that was accidentally dropped while the release-channel response was expanded.
- Android accepts both `advertising` and `ads`, so a Manager-first or APK-first rollout cannot silently collapse the banner slot.
- Existing MySQL campaigns, static `wp-content/uploads/bluevpn-ads/` delivery, image cache/prefetch and Free/Premium behavior are preserved.
- The protected v2rayNG 2.2.6 / Xray runtime is unchanged.

## 4.3.4 Free policy live-sync + mobile config fatal fix

- Fixes the WordPress `/api/v1/mobile/config` fatal caused by calling non-existent `BlueVPN_Ads::public_config()` / `free_public_config()` helpers.
- The API now calls the canonical advertising and Free-access payload builders and retains compatibility aliases.
- Android applies `free_access.session_minutes` from every successful mobile-config response.
- Free policy refresh no longer depends on whether the local Free pool is already installed.
- Reducing the server-side limit (for example 60 -> 30 minutes) clamps an active Free session; increasing it applies on the next connection.
- Manual update checks persist the same Free policy so Settings and Home cannot disagree.

## 4.3.3 update API + Live compatibility hotfix

- `/api/v1/mobile/config?refresh=true` is cache-first and no longer performs a blocking GitHub Release request.
- Manual update checks queue release refresh in the background and immediately return the last verified MySQL Stable/Beta selection.
- Release-channel lookup failures fall back to the last Stable values instead of breaking the Android update checker.
- BlueAI admin distinguishes legacy AI Schema v1 clients from Android 4.3.2+ real Live Heartbeat clients.

# BlueVPN 4.3.2

BlueVPN is now rebased as a **custom product/UI on top of official v2rayNG**, instead of treating v2rayNG as a replaceable compatibility bridge.

## Runtime baseline

- v2rayNG: `2.2.6` (current stable release selected for production)
- v2rayNG release commit: `15b4fff`
- AndroidLibXrayLite (resolved from v2rayNG 2.2.6 submodule): `v26.7.5`
- Xray-core label in the official v2rayNG 2.2.6 release notes: `v26.6.27`
- Android target from upstream: SDK 37
- Production VPN engines: **v2rayNG/Xray only**
- sing-box: **removed**

The newer v2rayNG `2.3.3` is a pre-release and includes the Jetpack Compose migration, so it is not used as the production base for this rebase.

## Architecture

The GitHub workflow first checks out the official v2rayNG tag and then overlays BlueVPN product code. BlueVPN does not fork the core lifecycle.

`v2rayNG source -> BlueVPN branding/UI/account/location overlay -> official v2rayNG CoreServiceManager/CoreVpnService -> Xray`

BlueVPN continues to show only locations to users. Hidden route GUIDs are selected by BlueVPN, but once a GUID is chosen it is committed to `MmkvManager` and started through the stock `CoreServiceManager.startVService(context, guid)` path. No alternate engine, custom TUN owner, custom config compiler or authoritative BlueVPN network pre-check sits between the selected v2rayNG profile and Xray.

## What BlueVPN still owns

- Custom Home and location-only UI
- Hidden routes behind each location
- Free/Premium entitlement isolation
- WordPress/MySQL account and plan control plane
- Update manager
- Advertising
- Local route history used only for ranking; it is not a second VPN engine

## What v2rayNG owns again

- Subscription/profile parsing semantics
- Profile/MMKV representation
- Runtime config generation
- Protocol and transport handling
- Core start/stop lifecycle
- Android VPN service
- TUN
- Xray runtime
- Connection service broadcasts consumed by BlueVPN UI

## Repository layout

- `android-source/` — BlueVPN UI/product overlay copied into the official v2rayNG checkout
- `bluevpn-manager/` — WordPress/MySQL control plane
- `branding/` — application identity and release pin
- `scripts/prepare_android.py` — branding/UI overlay only; **no core lifecycle or protocol parser patches**
- `.github/workflows/build-apk.yml` — checks out official v2rayNG and builds/signs BlueVPN
- `tests/` — current release contracts

## Versioning

Patch numbers remain short: `x.y.0 ... x.y.10`, then the next minor version.

## 4.1.8 rebase baseline

- Removed `BlueVpnEngineManager`.
- Removed sing-box native build/runtime/profile compiler.
- Removed Dual Engine mode/state.
- Restored direct BlueVPN Home -> v2rayNG `CoreServiceManager` start/stop calls.
- Removed read-only MainViewModel runtime patch too; MainViewModel remains upstream.
- Removed the Shadowsocks/parser compatibility patch so profile semantics are exactly those of the pinned v2rayNG release.
- Removed the authoritative BlueVPN DNS/TCP/config-hydration gate before starting a route. Imported profiles are accepted/rejected by the official v2rayNG runtime.

See `LICENSE` and `NOTICE.md` for licensing and attribution.

## Overlay-safe repository cleanup

Some deployments copy a release bundle over an existing GitHub checkout instead of replacing the tree. Deleted files from older releases can therefore remain tracked in the repository. The Android workflow now runs `scripts/cleanup_repository.py` before applying the BlueVPN overlay. It removes retired dual-engine/sing-box files, the removed AI activity, and historical generated Android snapshots from the build workspace before the regression gate runs.



## 4.3.2 — BlueAI Live Intelligence

- BlueAI برای هر دو پلن **Free** و **Premium** به‌صورت همزمان فعال است، اما یادگیری Routeها با `plan_tier` جدا نگه داشته می‌شود تا داده‌های رایگان و اشتراکی با هم قاطی نشوند.
- اتصال‌های مهمان/Free هم Heartbeat تأییدشده ارسال می‌کنند؛ شرط قدیمی Login برای Live Reporter حذف شده است.
- پنل WordPress نمای **Live** با تعداد Free/Premium، Route، اپراتور، Ping، ترافیک فنی، نسخه اپ و سن Heartbeat دارد.
- سلامت هر نسخه در ۲۴ ساعت اخیر جداگانه رصد می‌شود تا Beta/Stable و رگرسیون‌های اتصال قابل تشخیص باشند.
- `AI_SCHEMA_VERSION=2` و capability metadata اضافه شده تا هر آپدیت بتواند قابلیت‌های هوشمند جدید اضافه کند، بدون پاک‌کردن دانش جمعی قبلی.
- داده‌های قدیمی `unknown` حذف نمی‌شوند و فقط با وزن کمتر برای cold-start نسخه جدید استفاده می‌شوند.
- حریم خصوصی همان قرارداد قبلی است: محتوای ترافیک و IP مقصد جمع‌آوری نمی‌شود؛ فقط شاخص‌های فنی اتصال ثبت می‌شوند.

## 4.3.1 — Release Channels (Beta / Stable)

- Every new GitHub Android release is imported into WordPress as **Beta** by default.
- Only customers marked `beta_tester=1` can receive the newest active Beta release.
- Normal users always receive the latest **Stable** release until an administrator promotes a tested Beta.
- Promotion changes only MySQL release state; it reuses the exact APK, SHA-256, Build and commit and does not rebuild Android.
- Beta can be stopped/resumed and Force Update is stored per release.
- Android 4.3.1+ authenticates `/api/v1/mobile/config` with the current Bearer session and `X-Device-ID`.
- Schema 1.8.0 adds `app_releases` and `customers.beta_tester`. Existing configured release metadata is seeded as Stable during upgrade.
- Bootstrap note: pre-4.3.1 APKs do not authenticate update checks, so the first 4.3.1 Beta must be installed manually on tester devices once.


## 4.3.0 — Advertising blank-card / cPanel binary fix

- تصاویر تبلیغات داخلی دیگر برای مسیر اصلی از PHP REST stream خوانده نمی‌شوند؛ Assetهای MySQL به‌صورت lazy به `wp-content/uploads/bluevpn-ads` منتقل و با URL استاتیک سرو می‌شوند.
- مسیر قدیمی `/api/v1/ad-assets/{id}` برای سازگاری حفظ شده، اما `Content-Length` دستی حذف و خروجی خام در برابر فشرده‌سازی zlib/output-buffer سخت‌سازی شده است.
- Android در Cold Start تا Decode واقعی اولین Bitmap کارت تبلیغ خالی نشان نمی‌دهد؛ در تغییر اسلاید نیز تصویر قبلی تا آماده‌شدن بعدی باقی می‌ماند.
- درخواست تصویر با `Accept-Encoding: identity` انجام می‌شود و فقط یک Retry کوتاه دارد تا بنر خراب چندین ثانیه فضای خالی اشغال نکند.
- Health Check وردپرس علاوه بر HTTP 200، بایت تصویر را با `getimagesizefromstring()` واقعاً Decode می‌کند.
- Cache/Prefetch نسخه 4.2.4، جداسازی Free/Premium و Runtime رسمی v2rayNG 2.2.6/Xray دست‌نخورده‌اند.

## 4.2.10 — Free plan entitlement correctness

- کاربر لاگین‌شده‌ای که اشتراک Premium فعال ندارد، از این نسخه **پلن رایگان** محسوب می‌شود؛ داشتن Session حساب دیگر باعث رد شدن Bootstrap رایگان نمی‌شود.
- مدل Entitlement از «آماده بودن Pool» جدا شد؛ پلن Free قبل از دانلود اولین Pool هم درست در UI نمایش داده می‌شود و دکمه اتصال همان مسیر `prepareFreeAccess()` را برای آماده‌سازی اجرا می‌کند.
- Startup و بازگشت از صفحه حساب برای Guest و حساب لاگین‌شده بدون Premium یک مسیر Free مشترک دارند.
- متن‌های خانه/تنظیمات به‌جای «بدون اشتراک فعال» برای این حالت، `پلن رایگان` را نشان می‌دهند.
- اگر سرور WordPress صراحتاً Free Access را غیرفعال کند، وضعیت `UNAVAILABLE` همچنان محترم شمرده می‌شود.
- مرز Free/Premium، GUID ownership و Runtime رسمی v2rayNG 2.2.6/Xray دست نخورده‌اند.

## 4.2.9 — Explicit APK build trigger / single-dispatch hardening

- Push معمولی روی `main` دیگر Workflow ساخت APK را اجرا نمی‌کند.
- ساخت Android فقط از `repository_dispatch` ربات یا `workflow_dispatch` دستی شروع می‌شود.
- Upload ZIP یک Commit می‌سازد و سپس فقط همان Commit را برای Build Dispatch می‌کند.
- Job ربات قبل از هر Side Effect به‌صورت اتمیک Claim می‌شود تا اجرای همزمان wp-cron نتواند یک Build را دوبار Dispatch کند.
- متن اعلان موفق Telegram دیگر `%0A` چاپ نمی‌کند و از Line Break واقعی استفاده می‌کند.
- Versioning منبع‌محور 4.2.8، جداسازی Free/Premium و Cache بنرها حفظ شده‌اند.
- Runtime رسمی v2rayNG 2.2.6/Xray دست‌نخورده باقی مانده است.

## 4.2.8 — Source-controlled versioning / GitHub push verification

- `branding/app.json` و `release.json` منبع قطعی نسخه هستند؛ GitHub Actions دیگر صرفاً به دلیل وجود Release قبلی شماره نسخه را در Workspace بالا نمی‌برد.
- Build مجدد همان نسخه مجاز است و Release همان Tag را بازسازی می‌کند؛ تغییر واقعی سورس باید همراه با افزایش صریح نسخه باشد.
- سری نسخه همچنان کوتاه است: `x.y.0 ... x.y.10` و بعد از `.10` نسخه بعدی باید `x.(y+1).0` باشد.
- تأیید Push ربات ابتدا پاسخ خود `PATCH ref` را بررسی می‌کند؛ اگر HEAD جلو رفته باشد، ancestry Commit بررسی می‌شود و برای تأخیر کوتاه GitHub Retry وجود دارد.
- خطای کاذب `SHA شاخه پس از Push تأیید نشد` در حالتی که Commit واقعاً روی شاخه ثبت شده دیگر نباید رخ دهد.
- اصلاحات 4.2.4 بنر و 4.2.5 جداسازی Logout/Free/Premium بدون تغییر باقی مانده‌اند.

## 4.2.1 Release Gate / Versioning Fix

- Release validation no longer hard-codes a specific app version.
- `version_name` is validated as short SemVer and `version_code` is derived from it.
- `branding/app.json`, `release.json`, WordPress plugin header/constant and `Stable tag` must match dynamically.
- The GitHub regression gate now runs the current-release unittest suite after version synchronization.
- This prevents the automatic build bump (for example `4.2.0 -> 4.2.1`) from failing against stale validator literals.

## 4.2.0 Stability/Performance Freeze

- Runtime اتصال موفق 4.1.8 فریز شده است: فایل‌های رسمی CoreServiceManager/CoreConfigManager/CoreVpnService/MainViewModel/AngConfigManager تغییر نمی‌کنند.
- اولین onResume دیگر Refresh تکراری Startup را اجرا نمی‌کند.
- کاربر لاگین‌شده/Premium دیگر هنگام Startup برای Free Pool اسکن MMKV انجام نمی‌دهد.
- رندر صفحه اصلی برای نمایش سرور انتخاب‌شده از ownership check سبک استفاده می‌کند و لیست کامل سرورها را روی Main Thread باز نمی‌کند.
- فیلدهای Compatibility/AI که در UI مخفی هستند دیگر در هر Refresh محاسبه نمی‌شوند.
- پاک‌کردن SharedPreferences قدیمی subscription-info از مسیر رندر حذف شد؛ فقط تغییر واقعی حساب آن Cache را invalidate می‌کند.
## 4.2.0 SMS / OTP Transport Hardening

- Runtime VPN remains frozen on stock v2rayNG 2.2.6 / Xray path; no core file is changed.
- Android OTP requests now have a 30-second read budget while WordPress gives IranPayamak at most 10 seconds. The backend therefore returns the real provider error before the app can time out first.
- IranPayamak transport failures are classified as timeout, DNS, TLS, or generic network failures.
- OTP REST endpoints catch unexpected PHP failures and always return JSON with a trace id instead of leaking an HTML 500 page to Android.
- SMS provider health is persisted in `sms_settings.last_test_*` and shown in the WordPress SMS control center.
- Pattern requests still use the official `POST /ws/v1/sms/pattern` contract with `Api-Key`, `code`, `attributes`, `recipient`, `line_number`, and `number_format`.


## 4.2.0 IranPayamak Pattern Sync

The WordPress SMS / OTP control center now discovers active IranPayamak/FarazSMS patterns from the provider API instead of requiring manual Pattern UID entry. The provider API key remains encrypted in MySQL; only a one-way hash is stored beside the local pattern cache.

- `GET /ws/v1/patterns` is authenticated with the exact `Api-Key` header.
- Active patterns are cached for 15 minutes and can be refreshed explicitly from the SMS / OTP page.
- OTP and notification templates use provider-backed dropdowns.
- OTP parameter names are aligned with discovered provider variables when possible.
- A successful refresh reconciles stale pattern selections so removed/inactive provider patterns cannot silently remain enabled.
- The stable v2rayNG/Xray runtime boundary is unchanged by this release.

## BlueVPN Site theme

The repository also includes `bluevpn-site/`, a dedicated WordPress theme for the public BlueVPN website. It is intentionally separate from the Android runtime and does not modify v2rayNG/Xray.

Theme pages created on activation:

- `/` — landing page
- `/plans/` — authenticated plan list and BluePay purchase flow
- `/download/` — current Android release from BlueVPN Manager settings
- `/account/` — phone OTP or email/password login, account snapshot, plans and purchase
- `/support/` — support entry point

The theme consumes the existing `bluevpn/v1` and `bluevpn-system/v1` APIs from BlueVPN Manager. Set the exact BlueVPN logo as the WordPress Custom Logo; if none is configured the theme falls back to the BlueVPN wordmark.

## BlueVPN Site professional UI

Theme `1.0.3` replaces the previous generic card layout with a product-focused RTL interface: a stronger connection hero, BlueVPN product mockup, bento feature grid, hidden-route network visual, premium CTA, FAQ, and redesigned plans/download/account/support pages. The website remains self-contained and does not modify Android or the frozen v2rayNG/Xray runtime.

## BlueVPN Site automatic updates

`bluevpn-site/` has its own SemVer lifecycle independent from the Android app and BlueVPN Manager. The theme ships with a GitHub Releases updater and is auto-updated by WordPress without manual ZIP installation after the one-time bootstrap install.

- Current theme version: `1.0.9`
- Release tag: `bluevpn-site-v<theme-version>`
- Release asset: `bluevpn-site-theme-v<theme-version>.zip`
- Update source: the same GitHub repository configured in BlueVPN Manager; if the manager is unavailable, the theme falls back to `hazhanhasani/bluevpnapp`.
- Private GitHub authentication: when BlueVPN Manager has the migrated GitHub token, the theme reuses the same internal token source for API and release-asset downloads.
- Background checks: every 10 minutes through WP-Cron, plus an immediate non-blocking cron kick when a stale frontend/admin request is seen.
- Installation: `Theme_Upgrader` replaces only `bluevpn-site/`; Android/v2rayNG and BlueVPN Manager are untouched.
- Manual diagnostics: WordPress → Appearance → `آپدیت BlueVPN Site`.
- GitHub publication is isolated in `.github/workflows/bluevpn-site-theme-release.yml`, so changing website styling no longer needs an Android APK build.



## 4.2.4

- بنرهای داخلی BlueVPN دیگر 5.5 تا 9 ثانیه منتظر شروع نمی‌مانند؛ carousel نزدیک به اولین فریم اجرا می‌شود.
- آخرین تنظیمات معتبر تبلیغات و فایل تصویر بنر روی دستگاه cache می‌شوند و در اجرای بعدی بلافاصله نمایش داده می‌شوند.
- هنگام اولین دریافت شبکه، placeholder بنر فوراً دیده می‌شود و تصاویر بعدی carousel از قبل prefetch می‌شوند.
- warm-up تبلیغات ثالث همچنان با تأخیر قبلی اجرا می‌شود تا با شروع VPN و بارگیری بنر داخلی رقابت نکند.

## 4.2.3
- اصلاح نمایش اشتراک واقعی حساب وب با قرارداد canonical `subscription`.
- نمایش صحیح شماره محلی و اطلاعات پلن جاری.
- داشبورد حساب یکپارچه و حذف محتوای معرفی پس از ورود.
- Runtime رسمی v2rayNG/Xray بدون تغییر.


## BlueVPN Site 1.0.9 — blank-page fail-safe

- Elementor page output is pre-rendered and accepted only when it contains meaningful visible content; otherwise the original PHP template is used automatically.
- Elementor/Theme Builder Header and Footer output is buffered and validated so empty wrappers cannot suppress the built-in BlueVPN header/footer.
- Reveal animations are progressive enhancement: content is visible by default, and JavaScript may temporarily enable motion. A fail-safe removes the motion gate automatically even if an observer or another frontend initializer fails.
- Android/release metadata stays on 4.2.3 because this is a WordPress theme rendering hotfix and must not trigger an APK update.

## Elementor-native website theme

BlueVPN Site Theme v1.0.9 is Elementor-native while preserving the existing BlueVPN Manager/API behavior.

- Install and activate the free Elementor plugin.
- Activate/update the BlueVPN Site theme.
- On the first administrator request, the theme seeds Elementor documents for Home, Plans, Download, Account, and Support.
- Go to **Appearance → BlueVPN Elementor** to open each page, Header, and Footer in Elementor or to rebuild the starter layouts.
- Header/Footer are stored as Elementor Library templates and rendered by the theme. When Elementor Pro Theme Builder is present, standard Elementor theme locations are registered and can override the fallback templates.
- Dynamic account, OTP, plans, payment, and download behavior remains backed by BlueVPN Manager; Elementor controls presentation only.
- Subsequent theme auto-updates do not overwrite Elementor customizations unless the administrator explicitly selects rebuild.

## SEO hardening for the site theme

BlueVPN Site Theme v1.0.9 adds a WordPress/Elementor-aware SEO layer without touching the Android runtime.

- Public Home, Plans, Download, and Support pages receive consistent title/description defaults.
- The Account page is `noindex` and excluded from supported sitemaps so private account state is not indexed.
- Yoast SEO is respected when active; the theme only fills empty defaults and supplies a fallback social image.
- Without an SEO plugin, the theme emits canonical, description, Open Graph, and Twitter metadata itself.
- JSON-LD includes `SoftwareApplication` and `FAQPage`; fallback `Organization`, `WebSite`, and `BreadcrumbList` nodes are emitted when another SEO graph provider is absent.
- `robots.txt` includes the active sitemap and prevents crawling of the account area.
- `/llms.txt` exposes only the important public product pages and explicitly marks account pages as private.
- Default WordPress `Hello world!` / `Sample Page` content is removed on initial theme activation when it is still untouched sample content.
- Appearance → **BlueVPN SEO** shows the SEO status and can reseed only empty default metadata without rebuilding Elementor content.


## 4.2.5 — Logout / Free-Premium Entitlement Isolation

- خروج از حساب اکنون یک مرز صریح احراز هویت ایجاد می‌کند؛ پاسخ‌های قدیمی `/account` یا `/auth/refresh` که قبل از Logout شروع شده‌اند اجازه ندارند نشست یا Premium را دوباره زنده کنند.
- Premium فقط وقتی معتبر است که هم اشتراک فعال باشد و هم نشست واقعی اپ (`token`/`refresh_token`) وجود داشته باشد.
- Pool رایگانِ Cacheشده اگر در حالت Premium غیرفعال شده باشد دیگر «آماده» حساب نمی‌شود و بعد از Logout همان ردیف‌های Free دوباره فعال می‌شوند.
- صف آماده‌سازی اتصال Premium در حال اجرا هنگام Logout با generation جدید باطل می‌شود و نمی‌تواند بعداً GUIDهای قبلی را دوباره منتشر کند.
- بهینه‌سازی Cache/Prefetch بنرهای 4.2.4 حفظ شده است.
- Runtime رسمی v2rayNG 2.2.6 / Xray دست‌نخورده باقی مانده است.


## Free Story Ads (4.3.7)
Free-plan connections can be finalized through a first-party random image/video story gate managed from WordPress. The Free session timer starts only after completion; abandoning the mandatory story stops the pending VPN.

### 4.3.7 build barrier hotfix
The Android workflow now treats the WordPress Manager and schema versions as minimum compatibility barriers rather than requiring exact equality. If WordPress already runs a newer Manager (for example 4.3.6 while an older 4.3.5 APK source is being built), the build no longer times out with `WORDPRESS_AUTOUPDATE_TIMEOUT` solely because the control plane is newer.


## 4.4.0 — Manager Bot Auto-Install + Full Pattern Pagination

- Deploy Bot publishes and installs BlueVPN Manager on WordPress before Android Build when the uploaded ZIP contains manager source.
- Added `🧩 بروزرسانی Manager` for a manager-only publish/install cycle.
- Manager release workflow can publish the exact uploaded commit through `target_sha`.
- IranPayamak/FarazSMS pattern sync now walks all pages, deduplicates Pattern UID values, and stops safely if pagination is ignored.
- Pattern GET requests remain body-free for PHP 8.4 compatibility.

## Free engine (4.6.5)

BlueVPN 4.6.5 introduces an isolated Free-tier WARP path: pinned Aether is built from source in CI, exposes a loopback SOCKS5 endpoint, and is bridged into the existing stock v2rayNG VPN service. Premium subscriptions continue to use the existing v2rayNG/Xray path unchanged. If Aether cannot become ready within the bounded startup window, the legacy isolated Free subscription pool is retained as a fallback. See `third_party/AETHER.md` for source and licensing information.



## 4.10.4 WARP failure diagnostics
WARP failures now retain a privacy-safe structured code, stage, strategy and startup duration. The Home screen surfaces the actual failure class instead of collapsing every failure into a generic unavailable message. Sensitive token/OTP/secret-like values and URLs are redacted from persisted diagnostics.


## 4.10.4 — BlueAI Control Plane v3
BlueAI now includes privacy-safe network fingerprints, unified failure classification, network-aware route scoring and quarantine, predictive failover, shadow decisions, anomaly detection, Provider/payment reconciliation, AI-assisted panel balancing, SMS/runtime incident detection, and an Operations Center. AI recommendations remain bounded by deterministic VPN/payment/provider state machines.


## 4.10.4 — Production Runtime Validation
Signed APKs now pass a post-signing runtime-contract gate in GitHub Actions. The gate verifies APK integrity, permanent signature, BlueVPN foreground service/tile/action receiver declarations, notification permissions, and both packaged Aether ABIs. Android also keeps a bounded privacy-safe lifecycle audit for production diagnostics.


## 4.10.4 — Native Network Adaptation
BlueVPN remains on the stock pinned v2rayNG/Xray runtime. The experimental Mahsa-Core integration was removed completely. Useful network-adaptation ideas are implemented natively: per-network route learning, UDP-blocked awareness, conditional preference for existing Fragment-capable routes, and bounded failure-aware scoring/circuit breaking based on BlueVPN's own verified outcomes.


## 4.10.4 — Live Support
BlueVPN now includes the first production foundation of a private live-support system shared by Android, WordPress/MySQL and the existing Telegram admin bot. Customers choose a support department, open a conversation and exchange messages inside the app. WordPress provides an Inbox with departments, operators, assignment/transfer and statuses. The Telegram admin bot receives new messages and can reply with `/support_reply <conversation_id> <message>`. All customer REST operations are session-authenticated and conversation ownership is enforced server-side.


## 4.10.4 — Premium Instant UI + Live Support Phase 2
Premium connection now uses an optimistic UI: after VPN permission is available and a Premium candidate is selected, the screen immediately shows `متصل` while Xray/data-plane verification continues privately. This does not persist CONNECTED or set `connectionVerified` until the real verification path succeeds. Failed Premium candidates are switched silently inside the same Premium entitlement pool.

Live Support phase 2 adds bounded attachments, internal operator notes, department SLA, operator presence, canned replies, BlueAI reply suggestions, and authenticated background unread notifications. Attachments are MIME-sniffed server-side and capped at 4 MiB. Internal notes are never returned to customer REST endpoints.
