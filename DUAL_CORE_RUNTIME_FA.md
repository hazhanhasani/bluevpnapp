# معماری دو هسته‌ای BlueVPN — وضعیت واقعی مرحله اول

BlueVPN اکنون یک مرز Runtime اختصاصی با نام `BlueVpnEngineManager` دارد. Activityها و مدیریت حساب فرمان Start/Stop را از این مرز عبور می‌دهند و دیگر مستقیماً به `CoreServiceManager` وابسته نیستند.

sing-box نسخه پین‌شده `v1.13.16` برای دو ABI اندروید به‌صورت Native PIE ساخته می‌شود. این هسته در مرحله فعلی برای بررسی Native نسخه و اعتبارسنجی پروفایل آماده است. Xray همچنان تنها مالک Android TUN است؛ زیرا اجرای هم‌زمان دو هسته TUN یا قراردادن دو AAR مستقل gomobile می‌تواند اتصال، کلاس‌های Java و Runtime بومی را دچار تداخل کند.

مسیر این مرحله:

```text
BlueVPN UI
  → BlueVpnEngineManager
  → Xray compatibility bridge (مالک TUN فعلی)

BlueVPN Build/Profile Tools
  → isolated sing-box native runtime
  → native config validation
```

مرحله بعد باید `BlueVpnService` مستقل، مدل `BlueServerProfile` و انتقال اتمی مالکیت TUN میان Xray و sing-box را اضافه کند. پس از آن می‌توان `CoreServiceManager`، `MainViewModel` و MMKV متعلق به v2rayNG را به‌تدریج حذف کرد.
