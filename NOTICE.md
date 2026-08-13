# BlueVPN — upstream/runtime notice

BlueVPN Android 4.1.9 is built directly on the official **v2rayNG 2.2.6** Android source (GNU GPL v3).

Runtime ownership is intentionally simple:

- v2rayNG owns profile import/parsing, MMKV profile storage, `CoreConfigManager`, `CoreServiceManager`, `CoreVpnService`, Android `VpnService`, TUN and Xray startup/stop lifecycle.
- BlueVPN uses the AndroidLibXrayLite release resolved by the exact v2rayNG 2.2.6 submodule, currently **v26.7.5** (MPL 2.0).
- The official v2rayNG 2.2.6 release notes label the bundled Xray-core as **v26.6.27**. AndroidLibXrayLite and Xray-core use separate version namespaces and must not be compared as if they were the same tag.
- BlueVPN owns branding, its custom Home/Locations/Account/Settings UI, Free/Premium entitlement, WordPress API integration, updater, advertising and location grouping.
- **sing-box and the previous Dual Engine runtime have been removed from the Android production path.**
- BlueVPN no longer patches v2rayNG CoreServiceManager/CoreVpnService/MainViewModel or protocol parsers.

BlueVPN is an independent modified distribution and is not endorsed by the upstream v2rayNG/Xray maintainers.


## 4.1.9 Stability/Performance Freeze

- Runtime اتصال موفق 4.1.8 فریز شده است: فایل‌های رسمی CoreServiceManager/CoreConfigManager/CoreVpnService/MainViewModel/AngConfigManager تغییر نمی‌کنند.
- اولین onResume دیگر Refresh تکراری Startup را اجرا نمی‌کند.
- کاربر لاگین‌شده/Premium دیگر هنگام Startup برای Free Pool اسکن MMKV انجام نمی‌دهد.
- رندر صفحه اصلی برای نمایش سرور انتخاب‌شده از ownership check سبک استفاده می‌کند و لیست کامل سرورها را روی Main Thread باز نمی‌کند.
- فیلدهای Compatibility/AI که در UI مخفی هستند دیگر در هر Refresh محاسبه نمی‌شوند.
- پاک‌کردن SharedPreferences قدیمی subscription-info از مسیر رندر حذف شد؛ فقط تغییر واقعی حساب آن Cache را invalidate می‌کند.
