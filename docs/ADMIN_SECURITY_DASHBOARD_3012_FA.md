# BlueVPN 3.0.12 — داشبورد مدیریت و امنیت

## رابط مدیریت

- ظاهر Dark Glass با فونت Vazirmatn، Grid واکنش‌گرا و آیکون‌های SVG داخلی
- کارت‌های آماری جدید برای کاربران، اشتراک‌ها، پرداخت‌ها، Providerها و دیتابیس
- نمودار دایره‌ای زنده نرخ موفقیت اپراتورها از داده واقعی BlueAI
- بروزرسانی نمودارها و آمار از `/admin/api/live` هر ۸ ثانیه

## امنیت ورود

- Rate Limit مبتنی بر IP برای `/api/v1/auth/login`، `/api/v1/auth/register` و `/admin/login`
- هدر `Retry-After` برای پاسخ‌های HTTP 429
- اعتبارسنجی IP پراکسی و امکان کنترل با `TRUST_PROXY_HEADERS`
- پاک‌سازی و چرخش Session پس از ورود موفق مدیر
- Cookie مدیر با `SameSite=Strict` و HTTPS-only قابل تنظیم

## بکاپ دیتابیس

- Endpoint مدیر: `POST /admin/database/backup`
- محافظت با Session مدیر و CSRF Token
- PostgreSQL با `pg_dump --format=custom`
- SQLite با Backup API داخلی SQLite برای Snapshot سازگار
- خروجی ZIP شامل Dump، فایل `manifest.json`، راهنمای Restore و SHA-256
- رمز، URL کامل دیتابیس و Secretها داخل ZIP نوشته نمی‌شوند
- دانلود با `Cache-Control: no-store`

## متغیرهای قابل تنظیم

```env
SESSION_HTTPS_ONLY=true
ADMIN_SESSION_MAX_AGE=43200
TRUST_PROXY_HEADERS=true
AUTH_LOGIN_RATE_LIMIT=12
AUTH_LOGIN_IP_RATE_LIMIT=120
AUTH_LOGIN_WINDOW_SECONDS=600
AUTH_REGISTER_RATE_LIMIT=20
AUTH_REGISTER_WINDOW_SECONDS=3600
ADMIN_LOGIN_RATE_LIMIT=8
ADMIN_LOGIN_WINDOW_SECONDS=900
```
