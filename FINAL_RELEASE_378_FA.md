# BlueVPN 3.0.78 — BluePay Payment Runtime Recovery

این نسخه کل چرخه پرداخت BluePay را بازسازی می‌کند و فقط یک اصلاح موردی برای دکمه پاک‌سازی نیست.

## تغییرات

- حذف Hard Delete برای فاکتورهای واقعی و حفظ سابقه مالی
- رفع خطای PostgreSQL Foreign Key هنگام وجود پیامک مرتبط با سفارش
- حفظ سفارش برای Callback دیرهنگام و فعال‌سازی خودکار پس از تأیید
- پاک‌سازی مقاوم و ردیف‌به‌ردیف با استعلام و لغو Remote
- عدم شکست کل عملیات در صورت خراب‌بودن یک فاکتور
- تست اتصال رسمی با Sandbox BluePay
- جلوگیری از ذخیره Placeholder ماسک‌شده به‌جای API Key یا Callback Secret
- الزام Callback Secret برای فعال‌سازی درگاه
- تبدیل خطاهای پنل به پیام قابل‌فهم به‌جای Internal Server Error
- ثبت Request ID و خطای Provider در گزارش BluePay

## اعتبارسنجی

- تست‌های Python و Regression
- تست Foreign Key واقعی در SQLite با `PRAGMA foreign_keys=ON`
- تست بازیابی پرداخت دیرهنگام
- تست حفظ Secret ماسک‌شده
- اعتبارسنجی Generated Android و Dual Engine
