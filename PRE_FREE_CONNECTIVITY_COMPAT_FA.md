# BlueVPN — بازیابی مسیر اتصال قبل از Free Tier

این اصلاح برای رگرسیونی است که پس از جداسازی Pool رایگان و Premium ایجاد شد: پروفایل‌های سالم v2rayNG ممکن بود به‌خاطر refresh یا تعویض entitlement از MMKV حذف شوند، و semantic dedupe سراسری نیز می‌توانست نسخه Free یک endpoint را نگه دارد و نسخه Premium همان endpoint را قبل از فیلتر entitlement حذف کند.

## تغییرات

- پاک‌سازی Pool دیگر هیچ `removeServerViaSubid` انجام نمی‌دهد؛ ردیف‌های غیرفعال فقط disable می‌شوند و پروفایل‌های v2rayNG به‌عنوان Last Known Good باقی می‌مانند.
- Premium ابتدا Pool دقیق فعلی را استفاده می‌کند؛ اگر موقتاً خالی باشد، Poolهای Premium حفظ‌شده و در نهایت پروفایل‌های usable سراسری v2rayNG (به‌جز تمام GUIDهای Free شناخته‌شده) را به‌عنوان fallback می‌پذیرد.
- Free mode همچنان کاملاً strict است و هیچ fallback به Premium/global ندارد.
- انتخاب قبلی Premium در refresh موقتاً خالی بی‌دلیل پاک نمی‌شود؛ در logout/free mode انتخاب Premium پاک/غیرمجاز می‌شود.
- در `BlueVpnLocationUtil` ابتدا entitlement انتخاب می‌شود و سپس semantic dedupe انجام می‌شود؛ بنابراین duplicate مشترک Free/Premium دیگر باعث ناپدیدشدن نسخه Premium نمی‌شود.
- مسیر Xray همچنان GUID دقیق را به `CoreServiceManager.startVService(app, targetGuid)` می‌دهد و v2rayNG/Xray مرجع اجرای Core باقی می‌ماند.
- quarantine همان تلاش اتصال حفظ شده است؛ سرور Fail شده در همان cycle دوباره وارد صف نمی‌شود ولی در connect جدید امکان تست مجدد دارد.

## اعتبارسنجی

- pytest: 373 passed
- Generated Android validation: passed
- Dual Engine validation: 35/35 passed
- Full Gradle/NDK/Go build: باید در GitHub Actions اجرا شود.
