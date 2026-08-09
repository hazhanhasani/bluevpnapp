# BlueVPN 3.0.55 — مهاجرت کامل پیامک به ایران‌پیامک

## تغییرات اصلی
- حذف مسیر فعال قدیمی IPPanel از ارسال پیامک
- Base URL ثابت: `https://api.iranpayamak.com/ws/v1`
- ارسال پترن از مسیر `POST /sms/pattern`
- ارسال API Key در Header با نام `Api-Key`
- ساختار جدید payload: `code`، `attributes`، `recipient` و `number_format`
- پشتیبانی از پاسخ موفق HTTP 200 و 201 و همه پاسخ‌های 2xx
- حالت خط اشتراکی بدون تزریق شماره فرضی
- ارسال `line_number` فقط در حالت خط اختصاصی
- حفظ API Key و کدهای پترن هنگام مهاجرت خودکار از تنظیمات قدیمی
- تبدیل خودکار provider به `iranpayamak` و Base URL جدید در Startup
- پاک‌سازی شماره پیش‌فرض قدیمی فقط برای رکوردهای واقعاً قدیمی IPPanel
- نگهداری پترن ورود فقط در بخش «پترن‌های پیامکی»
- پیام خطای فارسی و امن برای خطاهای موقت، API Key، پترن یا خط ارسال

## دیتابیس
- Schema: 18
- ستون جدیدی لازم نیست؛ داده‌های موجود به‌شکل ایمن Migration می‌شوند.

## نسخه
- Version: 3.0.55
- Version Code: 30055

## اعتبارسنجی
- Python compile: موفق
- Generated Android validation: موفق
- Tests: 183/183 موفق
