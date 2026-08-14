# BlueVPN 4.2.9

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
