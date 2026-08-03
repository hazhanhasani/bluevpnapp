# اصلاح Build نسخه 0.4.8

## علت خطا
در نسخه 0.4.7 این دسترسی استفاده شده بود:

`MmkvManager.settingsStorage.encode(...)`

اما `settingsStorage` در v2rayNG 2.2.6 خصوصی است و از کلاس رابط کاربری
قابل دسترسی نیست.

## اصلاح
هر دو فراخوانی با API عمومی زیر جایگزین شدند:

`MmkvManager.encodeSettings(...)`

همچنین Workflow حالا:
- خروجی کامل Gradle را در `android-build.log` ذخیره می‌کند.
- خطاهای مهم کامپایل را در Summary نمایش می‌دهد.
- در صورت شکست، فایل Build Log را به‌عنوان Artifact نگه می‌دارد.
