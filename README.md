# BlueVPN v0.1.0

این ZIP برای ارسال مستقیم به ربات Deploy آماده شده است.

پس از ارسال:

1. فایل‌ها داخل همان مخزن GitHub نصب می‌شوند.
2. Railway پنل مدیریت و API را Deploy می‌کند.
3. GitHub Actions سورس رسمی v2rayNG نسخه 2.2.6 را دریافت می‌کند.
4. نام، Package ID، Deep Link و آیکون BlueVPN اعمال می‌شود.
5. APK آزمایشیِ قابل نصب در Actions → Artifacts ساخته می‌شود.

## قابلیت‌های این نسخه

- موتور واقعی VPN مبتنی بر v2rayNG/Xray
- برند و آیکون BlueVPN
- Package ID: `ir.blluepanel.bluevpn`
- Deep Link: `bluevpn://install-sub?url=SUBSCRIPTION_URL`
- پنل تحت وب و API
- اطلاعیه، حالت تعمیرات و پیام آپدیت
- اشتراک عمومی اختیاری
- تولید لینک اشتراک اختصاصی مشتری
- ساخت خودکار APK

## متغیرهای Railway بعد از نصب ZIP

متغیرهای قبلی ربات Deploy دیگر استفاده نمی‌شوند. این موارد را اضافه کنید:

```env
ADMIN_USERNAME=admin
ADMIN_PASSWORD=یک_رمز_قوی
SESSION_SECRET=یک_رشته_تصادفی_طولانی
DATABASE_URL=${{Postgres.DATABASE_URL}}
```

اختیاری:

```env
DEFAULT_SUBSCRIPTION_URL=
SUPPORT_URL=
RENEW_URL=
APK_URL=
```

در Railway یک PostgreSQL اضافه کنید تا تنظیمات پنل بعد از Deploy باقی بمانند.

## پنل و API

```text
https://YOUR-RAILWAY-DOMAIN/admin
https://YOUR-RAILWAY-DOMAIN/api/v1/mobile/config
```

## دریافت APK

```text
GitHub → Actions → Build BlueVPN APK → آخرین اجرای موفق → Artifacts
```

برای گوشی‌های جدید معمولاً APK نوع `arm64-v8a` مناسب است.

## افزودن اشتراک مشتری

در پنل، لینک Subscription اختصاصی مشتری را وارد کنید. خروجی:

```text
bluevpn://install-sub?url=...
```

نسخه مرورگری:

```text
https://YOUR-RAILWAY-DOMAIN/open-sub?url=SUBSCRIPTION_URL
```

## وضعیت نسخه

این نسخه اولین Build واقعی و آزمایشی است. رابط اصلی هنوز بر پایه v2rayNG است.
بازطراحی کامل صفحه اصلی و اتصال یک‌دکمه‌ای در مرحله بعد انجام می‌شود.

## امنیت

- توکن مشتری را داخل سورس یا `branding/app.json` نگذارید.
- مخزن را Private نگه دارید.
- رمز پنل و SESSION_SECRET فقط در Railway Variables باشند.
- نسخه انتشار نهایی باید با Keystore اختصاصی امضا شود.

## مجوز

بخش اندروید بر پایه v2rayNG و تحت GNU GPL v3 ساخته می‌شود.
فایل LICENSE و اسکریپت تغییرات در همین مخزن قرار دارند.
