# BlueVPN WordPress Migration Bridge v1.2.0

این Bridge فقط برای انتقال کنترل‌شده اطلاعات از Backend فعلی Railway/PostgreSQL به BlueVPN Manager روی WordPress/MySQL است.

## فایل جدید Backend

`server/wordpress_migration_bridge.py`

آن را در همین مسیر Repository قرار بده.

## دو خط تغییر در `server/main.py`

فایل `server/main.py.patch` محل دقیق دو تغییر را نشان می‌دهد:

1. Import کردن `register_wordpress_migration_bridge`
2. اجرای `register_wordpress_migration_bridge(app)` بلافاصله بعد از ساخته‌شدن FastAPI app

فایل کامل `main.py` عمداً جایگزین نشده تا تغییرات جدید فعلی Repository از بین نروند.

## Railway Variable

در Variables سرویس Railway این متغیر را بساز:

`WORDPRESS_MIGRATION_TOKEN`

مقدار آن باید یک Token تصادفی حداقل 32 کاراکتری باشد. همان مقدار را داخل:

`WordPress → BlueVPN → ابزار مهاجرت`

وارد کن.

نمونه ساخت Token روی Linux/macOS:

`openssl rand -hex 32`

## مسیرهای Bridge

- `/internal/migration/v1/health`
- `/internal/migration/v1/manifest`
- `/internal/migration/v1/export/{table}`

همه مسیرها بدون Header زیر 401 می‌دهند:

`X-BlueVPN-Migration-Token`

## امنیت

- Bridge فقط 21 جدول موردنیاز BlueVPN را export می‌کند.
- Secretهای رمزنگاری‌شده Railway فقط هنگام درخواست معتبر decrypt می‌شوند، روی HTTPS منتقل می‌شوند و WordPress دوباره آن‌ها را با کلید محلی خودش رمز می‌کند.
- Token در GitHub قرار نده.
- Railway را تا پایان Resync و Cutover خاموش نکن.
