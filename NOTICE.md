# BlueVPN — upstream/runtime notice

BlueVPN Android 4.3.0 is built directly on the official **v2rayNG 2.2.6** Android source (GNU GPL v3).

Runtime ownership is intentionally simple:

- v2rayNG owns profile import/parsing, MMKV profile storage, `CoreConfigManager`, `CoreServiceManager`, `CoreVpnService`, Android `VpnService`, TUN and Xray startup/stop lifecycle.
- BlueVPN uses the AndroidLibXrayLite release resolved by the exact v2rayNG 2.2.6 submodule, currently **v26.7.5** (MPL 2.0).
- The official v2rayNG 2.2.6 release notes label the bundled Xray-core as **v26.6.27**. AndroidLibXrayLite and Xray-core use separate version namespaces and must not be compared as if they were the same tag.
- BlueVPN owns branding, its custom Home/Locations/Account/Settings UI, Free/Premium entitlement, WordPress API integration, updater, advertising and location grouping.
- **sing-box and the previous Dual Engine runtime have been removed from the Android production path.**
- BlueVPN no longer patches v2rayNG CoreServiceManager/CoreVpnService/MainViewModel or protocol parsers.

BlueVPN is an independent modified distribution and is not endorsed by the upstream v2rayNG/Xray maintainers.


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
