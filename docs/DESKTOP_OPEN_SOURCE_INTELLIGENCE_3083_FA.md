# BlueVPN — Desktop/Open‑Source Connection Intelligence (3.0.83)

این مرحله برای نزدیک‌کردن رفتار مدیریت سرور و Subscription در BlueVPN به کلاینت‌های بالغ دسکتاپ و چندسکویی انجام شده است؛ هدف کپی UI یا کد پروژه‌های دیگر نیست، بلکه جداکردن ایده‌های معماری قابل‌اعتماد و پیاده‌سازی مستقل آن‌ها در BlueVPN است.

## پروژه‌های مرجع

- **v2rayN**: تفکیک TCPing از Real Delay، اجرای تست‌ها با کنترل هم‌زمانی/لغو، مدیریت چند Core و رفتارهای عملیاتی لیست سرورها.
- **Throne (ادامه Nekoray)**: معماری چندپروتکلی مبتنی بر sing-box، Custom Xray/sing-box، SSH، subscriptionهای چندفرمتی و سازگاری بیشتر ورودی‌ها.
- **sing-box**: الگوی `urltest` با tolerance/hysteresis برای جلوگیری از تعویض بی‌دلیل سرور وقتی اختلاف دو مسیر ناچیز است.
- **Xray-core Observatory / BurstObservatory**: health observation به‌عنوان داده‌ی جانبی، sampling، HTTP probe و تفکیک سلامت مسیر از یک ping منفرد.
- **NekoBox for Android**: parser چندفرمتی و نگهداری پروفایل‌های ناهمگون زیر یک مدل واحد.
- **Hiddify**: انتخاب خودکار node، remote profile، اطلاعات subscription و پشتیبانی از مجموعه پروتکل‌های مختلف.
- **Karing**: چند منبع subscription، route/ruleset مشترک و مدیریت providerها به‌جای یک لیست تخت و شکننده.
- **Mihomo**: lifecycle جدا برای provider و health-check؛ refresh منبع و health-check مسیر نباید یک عملیات مخرب واحد باشند.

## تغییرات اعمال‌شده

### 1. Route Intelligence شبکه‌محور

`BlueVpnRouteIntelligence.kt` وضعیت هر مسیر را با کلید زیر ذخیره می‌کند:

`semantic-profile-fingerprint + physical-network`

بنابراین یک سرور روی Wi‑Fi، ایرانسل، همراه اول یا شبکه دیگر سابقه مستقل دارد و خراب‌شدن آن روی یک شبکه به‌صورت دائمی به همه شبکه‌ها تعمیم داده نمی‌شود.

برای هر مسیر این داده‌ها نگهداری می‌شوند:

- success/failure و consecutive failure
- EWMA latency
- EWMA jitter
- آخرین موفقیت و شکست
- cooldown/quarantine تا زمان مشخص
- علت آخرین شکست
- Exit IP
- Exit country
- Exit colo (در صورت وجود در trace)

برای جلوگیری از «حافظه ابدی»، بعد از 64 نمونه شمارنده‌ها decay می‌شوند؛ یعنی یک سرور که در گذشته بد بوده ولی بعداً تعمیر شده می‌تواند دوباره رتبه مناسب بگیرد.

### 2. URLTest-style hysteresis / stickiness

به‌جای اینکه با اختلاف چند میلی‌ثانیه سرور فعال عوض شود، BlueVPN یک sticky candidate نگه می‌دارد. اگر سرور فعلی در محدوده قابل‌قبول امتیاز یا latency باشد، همان مسیر حفظ می‌شود. این کار churn، قطع‌و‌وصل و تغییر بی‌دلیل IP را کاهش می‌دهد.

پیش‌فرض فعلی BlueVPN:

- score tolerance: 7
- latency tolerance: 60ms
- sticky max age: 6h

این مقادیر مستقل از پیش‌فرض sing-box هستند و برای رفتار فعلی BlueVPN تنظیم شده‌اند.

### 3. تست لایه‌ای، نه «ping = سالم»

DNS/TCP خام فقط evidence/preflight است. نتیجه نهایی اتصال همچنان باید از داخل proxy/TUN واقعی اثبات شود.

Probeهای اتصال از local Xray proxy اجرا می‌شوند و چند endpoint مستقل در اختیار دارند. Trace موفق می‌تواند IP/country/colo خروجی را ثبت کند. یک failure خام به‌تنهایی کانفیگ را برای همیشه حذف نمی‌کند.

### 4. Subscription Intelligence

`BlueVpnSubscriptionIntelligence.kt` اضافه شده است. این لایه refresh را فقط روی subscriptionهایی که واقعاً متعلق به entitlement فعلی BlueVPN هستند انجام می‌دهد؛ دیگر `updateConfigViaSubAll()` برای free/premium swap استفاده نمی‌شود.

قابلیت‌ها:

- Last-known-good pool preservation
- bounded retry؛ حالت عادی حداکثر 2 UA و حالت repair/empty pool حداکثر 4 UA
- یادگیری User-Agent موفق برای هر URL
- UA compatibility ladder:
  - UA موفق قبلی
  - UA تنظیم‌شده روی subscription
  - `v2rayNG`
  - `sing-box`
  - `Clash.Meta`
  - `BlueVPN/<version>`
- failure streak و last successful refresh
- last-good config count
- حفظ انتخاب کاربر با semantic fingerprint بعد از refresh و GUID churn
- عدم پاک‌کردن pool سالم فقط به‌خاطر timeout/HTTP failure/فرمت موقتاً نامعتبر

### 5. حفظ metadata ساب

هنگام فعال/غیرفعال کردن Subscription، آبجکت قبلی با `copy(...)` به‌روزرسانی می‌شود؛ در نتیجه UA، filter و metadataهای موجود بی‌دلیل از بین نمی‌روند.

### 6. شواهد قابل مشاهده در صفحه سرورها

لیست سرورها اکنون در کنار delay می‌تواند خلاصه‌ی route evidence و exit summary را نمایش دهد؛ بنابراین کاربر فقط یک عدد ping نمی‌بیند و می‌تواند تفاوت «latency پایین ولی مسیر ناپایدار» با «مسیر واقعاً سالم» را تشخیص دهد.

## اصولی که از این مرحله به بعد باید ثابت بمانند

1. **هیچ سروری به‌خاطر یک تست خام برای همیشه حذف نشود.** Quarantine باید session/cooldown based باشد.
2. **Subscription refresh نباید last-known-good را نابود کند.**
3. **Ping، TCP connect و Real Tunnel Proof سه مفهوم متفاوت‌اند.**
4. **انتخاب خودکار باید hysteresis داشته باشد.** سریع‌ترین نمونه لحظه‌ای همیشه بهترین سرور نیست.
5. **سلامت سرور باید per-network باشد.**
6. **Refresh provider و Health check lifecycle جدا باشند.**
7. **GUID هویت واقعی کانفیگ نیست.** semantic fingerprint مبنای continuity است.
8. **IP خروجی فقط بعد از عبور واقعی از tunnel معتبر است.**
9. **تست‌های حجیم نباید startup یا UI thread را قفل کنند.**
10. **پشتیبانی از Core باید capability-based باشد، نه if/elseهای پراکنده در UI.**

## موارد عمداً هنوز «کامل» اعلام نشده‌اند

- Android TUN هنوز به‌طور کامل بین Xray و sing-box hand-off نمی‌شود؛ بنابراین SSH/sing-box full-device runtime تا تکمیل مالکیت TUN نباید Connected واقعی اعلام شود.
- اجرای bulk real-speed benchmark برای صدها node هنوز نباید در startup انجام شود؛ باید به worker محدود، قابل لغو و on-demand منتقل شود.
- Build کامل Gradle/NDK/Go در محیط فعلی آفلاین انجام نشده و GitHub Actions مرجع build نهایی APK است.

## تست‌های regression این مرحله

- route intelligence / sticky selection
- session quarantine isolation
- subscription UA fallback
- scoped entitlement refresh
- preservation of subscription metadata
- semantic selection restoration
- route evidence / exit summary UI wiring
- generated Android source validation
- dual-engine validation
