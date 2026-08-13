# BlueVPN 4.2.0

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
