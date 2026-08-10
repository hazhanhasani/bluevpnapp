# BlueVPN 3.0.83 — مدیریت یکپارچه کانفیگ و Subscription

این نسخه لایه مدیریت کانفیگ را از GUID و نام نمایشی v2rayNG جدا می‌کند و برای V2Ray share link، Xray JSON، sing-box JSON و SSH URI یک هویت معنایی پایدار می‌سازد.

## تغییرات اصلی

- `BlueVpnProfileManager.kt`
  - تشخیص نوع منبع: Share Link / Xray JSON / sing-box JSON / SSH URI
  - تشخیص پروتکل و مسیر موتور
  - Fingerprint معنایی بر اساس فیلدهای واقعی اتصال، بدون remarks/subscriptionId/GUID
  - Canonical JSON و URI برای جلوگیری از Duplicate کاذب
  - حفظ انتخاب کاربر پس از Refresh ساب حتی اگر GUIDهای MMKV عوض شوند

- `BlueVpnLocationUtil.kt`
  - Duplicateهای معنایی فقط در کاتالوگ انتخاب یکی می‌شوند
  - نسخه انتخاب‌شده اولویت دارد
  - هیچ پروفایلی برای de-duplication از MMKV حذف نمی‌شود
  - قرنطینه مسیر ناموفق در اتصال جاری همچنان موقتی است و در تلاش بعدی قابل برگشت است

- `BlueVpnAccountManager.kt`
  - قبل از Refresh ساب Free/Premium اثر انگشت سرور منتخب ذخیره می‌شود
  - پس از import مجدد، اگر همان سرور با GUID جدید وجود داشته باشد انتخاب به آن منتقل می‌شود

- `BlueVpnSingBoxProfileCompiler.kt` و `BlueVpnSingBoxProcess.kt`
  - پشتیبانی از parse/compile برای `ssh://`
  - پشتیبانی از sing-box JSON کامل یا یک outbound منفرد
  - ساخت local mixed proxy روی `127.0.0.1:21080`
  - اعتبارسنجی واقعی با `sing-box check` قبل از commit پروفایل
  - پشتیبانی SSH از password/private_key/private_key_passphrase/host_key/client_version

- `scripts/prepare_android.py`
  - Backport محدود برای Shadowsocks SIP002 در v2rayNG 2.2.6
  - پارامترهای transport/TLS مانند `type`, `host`, `path`, `serviceName`, `security` دیگر هنگام parse لینک `ss://` نادیده گرفته نمی‌شوند
  - export لینک Shadowsocks نیز queryهای transport را حفظ می‌کند

## محدودیت فعلی SSH

در این مرحله Android TUN هنوز تحت مالکیت Xray است. SSH و sing-box اکنون parse، compile و با باینری native اعتبارسنجی می‌شوند، اما برای اینکه SSH کل ترافیک دستگاه را به‌صورت VPN حمل کند باید مرحله بعدی TUN hand-off/bridge اجرا شود. عمداً قبل از تکمیل این بخش، وضعیت «اتصال کامل SSH» گزارش نمی‌شود.

## اعتبارسنجی

- pytest: 341 passed
- Generated Android validation: passed
- Dual engine validation: 35 passed / 0 failed
- Build کامل Gradle/NDK/Go در محیط آفلاین اجرا نشده و توسط GitHub Actions انجام می‌شود.
