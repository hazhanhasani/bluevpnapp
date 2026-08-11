# BlueVPN 4.0.29 — WordPress Release Barrier

مشکل مشاهده‌شده در 4.0.28 این بود که APK با موفقیت ساخته می‌شد اما انتشار افزونه WordPress به Workflow دوم `workflow_run` وابسته بود. در نتیجه ممکن بود APK جدید به کاربر تحویل داده شود در حالی که BlueVPN Manager روی WordPress هنوز نسخه قدیمی باشد. این عدم تطابق باعث می‌شد قابلیت‌هایی مثل Premium Pool، Snapshot ساب و مسیر اتصال جدید Android با Backend قدیمی هماهنگ نباشند.

در 4.0.29 انتشار افزونه داخل همان Workflow اصلی ساخت APK انجام می‌شود و قبل از Checkout/Build اندروید یک Release Barrier اجباری اجرا می‌شود:

- نسخه BlueVPN Manager با نسخه APK همگام می‌شود.
- ZIP استاندارد `bluevpn-manager.zip` ساخته و PHP lint می‌شود.
- Release با tag `bluevpn-manager-vX.Y.Z` ساخته یا به‌روز می‌شود.
- Asset منتشرشده دوباره از GitHub دانلود و نسخه داخل ZIP بررسی می‌شود.
- اگر انتشار یا اعتبارسنجی افزونه شکست بخورد، Build اصلی APK متوقف می‌شود و APK جدید تحویل داده نمی‌شود.
- Workflow مستقل `Release BlueVPN Manager` فقط به‌عنوان fallback/manual باقی می‌ماند.

نتیجه: از این نسخه به بعد نباید APK موفقی داشته باشیم که Backend WordPress هم‌نسخه آن هنوز در GitHub Release منتشر نشده باشد.

## قفل همگرایی WordPress

پس از ساخت Release افزونه، Workflow اصلی تا ۴ دقیقه `/health` سایت WordPress را بررسی می‌کند و در هر چرخه WP-Cron را نیز تحریک می‌کند. Build اندروید فقط وقتی ادامه پیدا می‌کند که:

- نسخه نصب‌شده WordPress دقیقاً با نسخه APK برابر باشد؛
- دیتابیس آماده باشد؛
- Schema روی `1.5.0` قرار گرفته باشد.

اگر WordPress نتواند خودکار به نسخه جدید برسد، Build همان‌جا Fail می‌شود؛ بنابراین دیگر APK جدیدی با Backend قدیمی به تلگرام تحویل داده نمی‌شود.
