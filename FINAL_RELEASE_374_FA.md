# BlueVPN 3.0.74 — اصلاح قرارداد رسمی BluePay

این نسخه مسیر ساخت فاکتور را با قرارداد عمومی BluePay 1.2.5 یکسان می‌کند:

- فقط `POST /api/v1/invoices`
- فقط هدر `X-API-Key`
- یک `Idempotency-Key` ثابت برای یک بدنه ثابت
- حذف Retryهای قدیمی با هدر و بدنه متفاوت
- حذف فیلد مستندنشده `webhook_url`
- ثبت `X-Request-ID` و پیام واقعی خطای BluePay
- جلوگیری از فعال‌کردن درگاه بدون API Key فروشگاه

آدرس `https://bluepay-production.up.railway.app/developers` فقط مستندات است. Base URL به دامنه اصلی تبدیل می‌شود، ولی API Key باید از ربات BluePay و بخش فروشگاه‌ها و APIها دریافت شود.
