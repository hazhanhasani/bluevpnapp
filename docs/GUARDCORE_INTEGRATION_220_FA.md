# BlueVPN 2.2.0 — GuardCore و Multi Provider

این نسخه GuardCore را به‌عنوان Provider سوم Backend اضافه می‌کند.

## امکانات

- ثبت چند اتصال GuardCore با X-API-Key یا OAuth Password
- تست اتصال با `/api/admins/current` و دریافت `/api/services`
- تنظیم واحد `limit_usage` به Byte یا GB
- تنظیم `limit_expire` به روز، ثانیه یا Unix Timestamp
- تعیین Service IDهای GuardCore برای هر پلن
- ساخت و ویرایش اشتراک با `/api/subscriptions`
- فعال‌کردن اشتراک غیرفعال با `/api/subscriptions/enable`
- دریافت لینک از `SubscriptionResponse.link`
- ادغام کانفیگ‌های GuardCore با PasarGuard و Marzban در یک Subscription
- تقسیم حجم بین همه Providerها یا اعمال حجم کامل روی همه
- Migration خودکار دیتابیس به Schema 7

کلید GuardCore فقط به‌صورت رمزگذاری‌شده در Backend ذخیره می‌شود و وارد APK نمی‌شود.

## نسخه Android

نسخه پایه پروژه `2.2.0` است. اولین Build موفق سری جدید `2.2.0` و Buildهای بعدی `2.2.1`، `2.2.2` و ... خواهند بود.
