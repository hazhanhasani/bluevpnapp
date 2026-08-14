## BlueVPN 4.4.5

Live telemetry is measured, not synthesized: Android computes download/upload throughput from real UID traffic while connected, Free shows the actual remaining session time, Premium shows exact connected duration, and BlueAI v2.1 samples real HTTP RTT through the active Xray local proxy. Zero ping is treated as unavailable rather than as a valid measurement.

## 4.4.2 smart SMS pattern-assignment boundary

BlueVPN may automatically map IranPayamak patterns to first-party notification contracts only when the Provider attribute set is compatible. The matcher uses deterministic local metadata/text scoring; it does not send message content to an external AI service. Valid manual selections are preserved unless an administrator explicitly confirms a full remap.

## 4.4.0 FarazSMS / IranPayamak API boundary

BlueVPN uses the official IranPayamak REST base `https://api.iranpayamak.com/ws/v1` and authenticates with the `Api-Key` header. Pattern discovery uses `GET /patterns` with no body or optional filters so WordPress/PHP 8.4 never serializes a GET JSON body and private patterns are not excluded by a `share=1` filter. Active-state enforcement is local. If the configured pattern is absent from the list response, BlueVPN may validate that exact code with `GET /patterns/{code}`. OTP delivery remains `POST /sms/pattern` with a JSON body.

## 4.3.8 advertising navigation boundary

Advertising navigation is first-party and allow-listed. WordPress selects a semantic action and optional plan id; Android maps that action to BlueVPN account/plans/settings screens locally. The control plane cannot provide an arbitrary Android component or custom intent. External fallbacks are limited to valid HTTP(S) URLs. A mandatory Free Story CTA stops the pending Free connection before navigation and does not count as a completed ad view. This boundary does not modify the protected v2rayNG/Xray runtime.

## 4.3.5 Beta update parity boundary

Beta and Stable now use the same authenticated Android update pipeline: periodic/manual checks, automatic APK download, SHA-256 validation, force-update blocking and PackageInstaller handoff. WordPress selects the eligible release before returning metadata, and automatic delivery can be controlled independently for Stable and Beta. Android platform security may still require final user confirmation before package installation. The protected v2rayNG/Xray runtime is unchanged.

## 4.3.4 Free-policy control-plane boundary

The WordPress mobile-config endpoint is the authoritative source for Free access settings. Android refreshes and persists this policy independently of local v2rayNG subscription readiness. A server-side reduction of the Free session duration may shorten an active Free session, while an increase starts from the next Free connection. This control-plane fix does not modify the protected v2rayNG/Xray runtime.

## 4.3.3 update availability / Live compatibility boundary

Android update availability must not depend on a synchronous GitHub request from shared cPanel hosting. The mobile config endpoint serves verified MySQL release metadata first and refreshes GitHub state asynchronously. BlueAI Live remains proof-based; older AI Schema v1 clients are identified as legacy instead of being falsely counted as live.

# BlueVPN — upstream/runtime notice

BlueVPN Android 4.3.2 is built directly on the official **v2rayNG 2.2.6** Android source (GNU GPL v3).

Runtime ownership is intentionally simple:

- v2rayNG owns profile import/parsing, MMKV profile storage, `CoreConfigManager`, `CoreServiceManager`, `CoreVpnService`, Android `VpnService`, TUN and Xray startup/stop lifecycle.
- BlueVPN uses the AndroidLibXrayLite release resolved by the exact v2rayNG 2.2.6 submodule, currently **v26.7.5** (MPL 2.0).
- The official v2rayNG 2.2.6 release notes label the bundled Xray-core as **v26.6.27**. AndroidLibXrayLite and Xray-core use separate version namespaces and must not be compared as if they were the same tag.
- BlueVPN owns branding, its custom Home/Locations/Account/Settings UI, Free/Premium entitlement, WordPress API integration, updater, advertising and location grouping.
- **sing-box and the previous Dual Engine runtime have been removed from the Android production path.**
- BlueVPN no longer patches v2rayNG CoreServiceManager/CoreVpnService/MainViewModel or protocol parsers.

BlueVPN is an independent modified distribution and is not endorsed by the upstream v2rayNG/Xray maintainers.


## 4.3.7 advertising API contract boundary

The WordPress control plane again exposes the canonical `advertising` and `tapsell` objects expected by Android. The temporary `ads` key is preserved as a compatibility alias and Android accepts either advertising key during staggered rollout. This change does not modify the protected v2rayNG/Xray runtime.

## 4.3.0 advertising delivery boundary

Campaign image delivery is hardened for WordPress/cPanel hosting. DB-backed ad images are lazily materialized to static WordPress upload files, while the legacy REST binary endpoint remains as a compatibility fallback with compression/output-buffer safeguards. Android does not reveal an empty campaign container before a bitmap has decoded and keeps the previous bitmap during slide transitions. This change does not modify the protected v2rayNG/Xray runtime.

## 4.2.10 Free-plan entitlement boundary

Authenticated accounts without an active Premium entitlement use the same Free entitlement and Free subscription pool as guest users. Free plan presentation is no longer coupled to whether the local Free pool has already finished downloading. This change does not patch the v2rayNG/Xray runtime.

## 4.2.9 build-trigger boundary

This release removes the normal `push` event from the Android APK workflow. APK compilation is explicit: the WordPress bot uses `repository_dispatch`, while `workflow_dispatch` remains available for intentional manual builds. The Telegram deployment job is atomically claimed before GitHub side effects so duplicate wp-cron execution cannot dispatch the same job twice. Telegram success notifications now use real newline characters. The protected v2rayNG 2.2.6 runtime remains unchanged.

## 4.2.8 release-control boundary

This release changes release-control and WordPress bot GitHub deployment logic only. The project-declared version is authoritative; build jobs do not silently consume a new patch number. GitHub ref updates are accepted from the PATCH response and, when needed, verified by retrying branch HEAD and checking commit ancestry. The protected v2rayNG 2.2.6 runtime remains unchanged.

## 4.2.1 Release validation

The release gate uses the resolved build version dynamically and verifies cross-file consistency instead of embedding a fixed release number.

## 4.2.0 Stability/Performance Freeze

- Runtime اتصال موفق 4.1.8 فریز شده است: فایل‌های رسمی CoreServiceManager/CoreConfigManager/CoreVpnService/MainViewModel/AngConfigManager تغییر نمی‌کنند.
- اولین onResume دیگر Refresh تکراری Startup را اجرا نمی‌کند.
- کاربر لاگین‌شده/Premium دیگر هنگام Startup برای Free Pool اسکن MMKV انجام نمی‌دهد.
- رندر صفحه اصلی برای نمایش سرور انتخاب‌شده از ownership check سبک استفاده می‌کند و لیست کامل سرورها را روی Main Thread باز نمی‌کند.
- فیلدهای Compatibility/AI که در UI مخفی هستند دیگر در هر Refresh محاسبه نمی‌شوند.
- پاک‌کردن SharedPreferences قدیمی subscription-info از مسیر رندر حذف شد؛ فقط تغییر واقعی حساب آن Cache را invalidate می‌کند.
## 4.2.0 SMS boundary

The SMS/OTP hardening changes only BlueVPN Android account networking and the WordPress control plane. The protected upstream v2rayNG runtime/parser files remain immutable.


## 4.2.0 SMS pattern discovery

BlueVPN Manager retrieves the authenticated account's active SMS patterns from the IranPayamak/FarazSMS REST API and presents them as controlled selections in WordPress. No SMS API key is written to the pattern cache. This change is limited to the WordPress control plane and does not alter the frozen v2rayNG/Xray runtime.



## 4.2.4

- Android campaign banner pipeline is now cache-first and starts near the first frame.
- Banner config and image bytes persist locally with stale-while-revalidate behavior.
- First-load placeholder and next-image prefetch reduce visible banner latency.
- Third-party ad SDK warm-up remains outside the critical startup path.

## 4.2.3
- اصلاح نمایش اشتراک واقعی حساب وب با قرارداد canonical `subscription`.
- نمایش صحیح شماره محلی و اطلاعات پلن جاری.
- داشبورد حساب یکپارچه و حذف محتوای معرفی پس از ورود.
- Runtime رسمی v2rayNG/Xray بدون تغییر.


## 4.2.5 Logout entitlement boundary

This release changes only BlueVPN account/entitlement control-plane code. Premium ownership now requires a live authenticated app session, stale authenticated responses are rejected across logout/login boundaries, and the cached Free pool is re-enabled after Premium logout. The 4.2.4 banner cache/prefetch changes remain included. Protected v2rayNG 2.2.6 runtime/parser files remain unchanged.

### 4.3.7 build barrier hotfix
The Android workflow now treats the WordPress Manager and schema versions as minimum compatibility barriers rather than requiring exact equality. If WordPress already runs a newer Manager (for example 4.3.6 while an older 4.3.5 APK source is being built), the build no longer times out with `WORDPRESS_AUTOUPDATE_TIMEOUT` solely because the control plane is newer.


4.4.0: Telegram Deploy Bot can publish/install BlueVPN Manager automatically; SMS pattern synchronization is multi-page and deduplicated.
