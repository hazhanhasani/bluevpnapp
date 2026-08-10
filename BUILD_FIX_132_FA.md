# رفع Build #132 — BlueVPN 3.0.83

خطاهای Kotlin گزارش‌شده در Build #132 اصلاح شدند:

- `BlueVpnAccountManager.install` اکنون `Context` معتبر دریافت می‌کند.
- `scheduleInstall` فقط `applicationContext` را به worker پس‌زمینه منتقل می‌کند تا Activity leak ایجاد نشود.
- فراخوانی `recommendedUserAgent` با نام پارامتر صحیح `context` انجام می‌شود.
- import کلاس `BlueVpnRouteIntelligence` به `BlueVpnHomeActivity` اضافه شد.
- payloadهای Base64 مولد Android با سورس canonical دوباره همگام شدند.
- regression test مخصوص Build #132 اضافه شد.

نسخه اپ عمداً روی 3.0.83 باقی مانده است؛ این بسته hotfix همان release است.
