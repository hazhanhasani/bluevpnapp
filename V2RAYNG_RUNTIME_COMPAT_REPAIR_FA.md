# تعمیر مسیر اتصال Xray / v2rayNG در BlueVPN 3.0.83

این Hotfix معماری اتصال Xray را دوباره با lifecycle رسمی v2rayNG همسو می‌کند و لایه‌های BlueVPN را از دخالت مخرب در شروع/توقف Core جدا می‌کند.

## مشکلاتی که اصلاح شدند

- انتخاب GUID جدید قبل از توقف کامل Core قبلی حذف شد.
- Restart ثابت 90ms حذف شد؛ شروع مسیر بعدی فقط بعد از دریافت وضعیت توقف Core انجام می‌شود.
- GUID دقیق کانفیگ از `BlueVpnEngineManager` به `CoreServiceManager.startVService(context, guid)` داده می‌شود.
- BlueVPN دیگر یک کانفیگ سالم را صرفاً به‌خاطر شکست یک HTTP/DNS probe متوقف نمی‌کند.
- تست native خود v2rayNG (`testCurrentServerRealPing`) در کنار تست end-to-end BlueVPN به‌عنوان proof مستقل استفاده می‌شود.
- Health verification برای اتصال جدید سه دور دارد؛ quarantine فقط پس از شکست تکرارشونده انجام می‌شود.
- برای Core از قبل در حال اجرا، شکست موقت health check باعث Stop شدن Xray نمی‌شود؛ وضعیت در حالت «در حال تأیید اینترنت» می‌ماند و دوباره تست می‌شود.
- خطای lifecycle در توقف Core قبلی، کانفیگ بعدی را به اشتباه خراب/قرنطینه نمی‌کند.
- timeout شروع Core به 12 ثانیه افزایش داده شد تا transportهای کندتر فرصت واقعی برای startup داشته باشند.
- snapshot تولیدشده در `prepare_android.py` با سورس جدید همگام شد.

## مرز معماری

برای VLESS/VMess/Trojan/Shadowsocks و Xray-compatible profiles، v2rayNG/Xray همچنان compatibility runtime و مالک TUN است. BlueVPN مسئول UI، حساب، entitlement، ranking، failover و telemetry است و نباید کانفیگ Xray را قبل از Core به شکل ناسازگار بازسازی کند.

sing-box همچنان به‌صورت مستقل نگه داشته شده است و تا زمانی که Android TUN handoff واقعی تکمیل نشود، نباید Connected جعلی برای مسیر native sing-box/SSH نمایش داده شود.

## اعتبارسنجی محلی

- pytest: 361 passed
- Dual Engine validation: 35 passed / 0 failed
- Generated Android validation: passed
- Full Gradle/NDK/Go build: در GitHub Actions انجام می‌شود.
