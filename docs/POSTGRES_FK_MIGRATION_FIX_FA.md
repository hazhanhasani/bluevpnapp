# اصلاح Migration PostgreSQL برای Foreign Keyهای nullable

## خطای رفع‌شده

Startup می‌توانست هنگام اجرای Migration با خطایی مشابه زیر متوقف شود:

`customers_plan_id_fkey: Key (plan_id)=(0) is not present in table plans`

علت این بود که Migration عمومی برای ستون‌های عددی دارای مقدار NULL، مقدار `0` می‌نوشت. این رفتار برای Foreign Keyها معتبر نیست و برای ستون‌های nullable نیز NULL می‌تواند معنای واقعی دامنه داشته باشد.

## اصلاح

- برای هیچ ستون Foreign Key مقدار ساختگی ایجاد نمی‌شود.
- NULL ستون‌های nullable موجود در هر Startup بازنویسی نمی‌شود.
- ستون nullable تازه‌اضافه‌شده فقط در صورت داشتن default صریح مدل backfill می‌شود.
- ستون‌های primitive غیرnullable همچنان در صورت نیاز قابل repair هستند.
- Regression test اضافه شد تا `customers.plan_id`، `panel_id`، `marzban_panel_id` و `guardcore_panel_id` بدون والد معتبر NULL باقی بمانند.

## نتیجه اعتبارسنجی

کل تست‌های پروژه: `219 passed`.
