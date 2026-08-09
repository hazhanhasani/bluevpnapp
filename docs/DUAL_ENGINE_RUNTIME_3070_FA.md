# BlueVPN 3.0.70 — مرز Runtime و هسته دوم sing-box

این نسخه مرحله اول مهاجرت واقعی از v2rayNG است.

## تغییرات اجراشده

- تمام Start/Stopهای صفحه اصلی و مدیریت حساب از `CoreServiceManager` جدا شدند.
- `BlueVpnEngineManager` تنها ورودی Runtime برنامه شد.
- State Machine مرکزی برای IDLE/PREPARING/STARTING/VERIFYING/CONNECTED/SWITCHING/STOPPING/FAILED اضافه شد.
- sing-box نسخه `v1.13.16` در GitHub Actions برای arm64-v8a و armeabi-v7a به‌صورت PIE Native ساخته می‌شود.
- به‌جای قرار دادن `libbox.aar` کنار `libv2ray.aar`، sing-box در یک Process ایزوله اجرا می‌شود؛ چون هر دو AAR با gomobile ساخته شده‌اند و در یک APK باعث تداخل `go.Seq` و `libgojni` می‌شوند.
- `BlueVpnSingBoxProcess` نصب، اعتبارسنجی، اجرا، توقف و گزارش نسخه sing-box را مدیریت می‌کند.
- فایل‌های قابل‌خواندن `android-source` اکنون منبع اصلی تزریق هستند و payloadهای قدیمی base64 را Override می‌کنند.

## وضعیت مسیر ترافیک

در 3.0.70، Xray هنوز مالک Android TUN است تا اتصال کاربران فعلی خراب نشود. sing-box به‌صورت Native Runtime آماده و دارای اعتبارسنجی مستقل اضافه شده، اما انتخاب مستقیم sing-box برای تمام ترافیک تا اضافه‌شدن `BlueVpnSingBoxVpnService` و تبدیل پروفایل‌های موجود، به fallback ایمن Xray برمی‌گردد.

این رفتار عمدی است: برنامه نباید صرفاً به‌دلیل Running بودن sing-box وضعیت Connected نمایش دهد.

## مرحله بعد

- افزودن مدل مستقل `BlueServerProfile`
- تبدیل VLESS/VMess/Trojan/Shadowsocks به JSON هر دو هسته
- ساخت `BlueVpnSingBoxVpnService`
- انتقال TUN از v2rayNG به سرویس BlueVPN
- حذف `MainViewModel` و `MmkvManager` از UI
- حذف Checkout کامل v2rayNG پس از Migration داده‌ها
