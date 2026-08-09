# اصلاح ساخت فاکتور BluePay در BlueVPN 3.0.74

در پیاده‌سازی قبلی برای سازگاری با نسخه‌های فرضی، چند Endpoint، چند هدر احراز هویت و دو بدنه متفاوت با یک Idempotency-Key امتحان می‌شد. قرارداد BluePay استفاده مجدد از همان کلید با بدنه متفاوت را با HTTP 409 رد می‌کند.

نسخه جدید فقط درخواست رسمی زیر را می‌فرستد:

- `POST /api/v1/invoices`
- `X-API-Key`
- `Idempotency-Key`
- `amount_toman`
- `order_id`
- `description`
- `fee_mode`
- `callback_url`
- `ttl_minutes`

برای فعال‌شدن درگاه، واردکردن Base URL کافی نیست و API Key اختصاصی فروشگاه الزامی است.
