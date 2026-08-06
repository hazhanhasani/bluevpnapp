# چرخه فاکتور BluePay — BlueVPN 3.0.18

## قانون اعتبار

1. فاکتور هنگام بازشدن صفحه پرداخت حداکثر ۳۰ دقیقه اعتبار دارد.
2. Android هنگام بازگشت کاربر از مرورگر، endpoint خروج را فراخوانی می‌کند.
3. Backend از زمان خروج پنج دقیقه فرصت بازگشت در نظر می‌گیرد.
4. پس از پنج دقیقه فاکتور به `abandoned` تغییر می‌کند و دیگر در خرید بعدی reuse نمی‌شود.
5. Webhook پرداخت واقعی حتی برای سفارش `abandoned` قابل بازیابی است.

## Endpointها

- `POST /api/v1/orders/{id}/checkout/open`
- `POST /api/v1/orders/{id}/checkout/heartbeat`
- `POST /api/v1/orders/{id}/checkout/close`

## متغیرهای محیطی

- `BLUEPAY_INVOICE_TTL_MINUTES=30`
- `BLUEPAY_ABANDON_GRACE_SECONDS=300`
- `BLUEPAY_CLEANUP_INTERVAL_SECONDS=300`
