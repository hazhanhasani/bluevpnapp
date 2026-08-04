# BlueVPN 1.0.12

- Android: 1.0.12
- Version Code: 26
- Build ID: 20260804110832

## اصلاح Build
`import kotlinx.coroutines.delay` اضافه شد و خطای `Unresolved reference 'delay'` رفع شد.

## حذف پلن
- دکمه حذف کنار هر پلن
- تأیید قبل از حذف
- مخفی‌شدن از فروش، فعال‌سازی دستی و فهرست پلن‌ها
- حفظ سفارش‌ها و سوابق قبلی با Soft Delete
- Migration خودکار ستون‌های `deleted` و `deleted_at`
