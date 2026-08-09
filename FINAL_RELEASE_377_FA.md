# BlueVPN 3.0.77 — Stable Location Snapshot

این نسخه Race Condition صفحه مکان‌ها را برطرف می‌کند. فهرست سرورها دیگر در فاصله پاک‌شدن و Import مجدد MMKV ناپدید نمی‌شود و Subscription سالم هنگام ورود به صفحه دوباره دریافت نمی‌شود.

## نکات اصلی

- Snapshot اتمی و غیرخالی مکان‌ها
- Cache مبتنی بر هویت پایدار Entitlement
- جلوگیری از Import تکراری اشتراک سالم
- قفل تک‌مسیره برای Reconcile و Subscription Import
- حذف Refreshهای هم‌زمان و متداخل
- جلوگیری از نمایش نتیجه متعلق به پلن قبلی
