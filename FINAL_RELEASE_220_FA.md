# نسخه نهایی BlueVPN Android 2.2.0

BlueVPN Android 2.2.0 — GuardCore Final

این ZIP را مستقیم برای ربات Deploy ارسال کن.

امکانات افزوده‌شده:
- GuardCore به‌عنوان Provider سوم کنار PasarGuard و Marzban
- احراز هویت با X-API-Key یا نام کاربری و رمز
- تست اتصال به /api/admins/current
- دریافت خودکار Serviceها از /api/services
- تعیین Service IDهای GuardCore برای هر پلن
- ساخت اشتراک با POST /api/subscriptions
- تمدید و ویرایش با PUT /api/subscriptions/{username}
- فعال‌کردن کاربر غیرفعال با POST /api/subscriptions/enable
- دریافت لینک از SubscriptionResponse.link
- پشتیبانی از لینک‌های کامل و نسبی GuardCore
- تجمیع کانفیگ‌های PasarGuard، Marzban و GuardCore
- تقسیم مساوی حجم یا اعمال حجم کامل روی همه Providerها
- نمایش کاربر و خطای GuardCore در پنل مدیریت
- انتقال کاربران فعلی پلن به Providerهای جدید
- رمزگذاری کلیدها فقط در Backend؛ هیچ کلیدی وارد APK نمی‌شود
- Migration خودکار دیتابیس از Schema 6 به Schema 7
- حفظ طراحی Premium و رفع حلقه تکراری نصب نسخه 2.1.1
- تمرکز خروجی فقط Android

نسخه:
- اولین Build این بسته: 2.2.0 / Version Code 22000
- Build بعدی: 2.2.1 / Version Code 22001
- سپس 2.2.2، 2.2.3 و ...

راه‌اندازی پس از Deploy:
1. وارد پنل مدیریت شو.
2. بخش GuardCore را باز کن.
3. Base URL و X-API-Key را وارد کن.
4. واحد limit_usage و نوع limit_expire را مطابق پنلت انتخاب کن.
5. «تست و دریافت Serviceها» را بزن.
6. در پلن فروش، GuardCore و Service IDها را انتخاب کن.
7. برای کاربران قدیمی «همگام‌سازی کاربران فعلی» را بزن.

زمان بسته‌بندی: 2026-08-05T09:05:57.678207+00:00
