# BlueVPN 4.0.27 — WordPress Connectivity Hotfix

این نسخه Regressionهای 4.0.25/4.0.26 را در مسیر اتصال اصلاح می‌کند.

- Free و Premium به‌صورت strict از هم جدا شده‌اند؛ هیچ fallback به دیتابیس سراسری v2rayNG وجود ندارد.
- Auto Update داخلی v2rayNG برای Subscriptionهای مدیریت‌شده BlueVPN خاموش است.
- ورود به Home دیگر Provider Sync اجباری و Subscription rebuild انجام نمی‌دهد.
- GET حساب فقط Snapshot سریع MySQL را می‌خواند؛ Provider Sync فقط مسیر صریح و throttled است.
- خطای موقت PasarGuard/Marzban/GuardCore دیگر وضعیت Premium را inactive نمی‌کند.
- /sub از Last-Good Snapshot وردپرس با stale-while-revalidate استفاده می‌کند و اتصال را منتظر پنل‌های Remote نمی‌گذارد.
- Ping/List event دیگر Candidate warm-up سنگین را دائماً تکرار نمی‌کند.
- BlueAI Recommendations و telemetry فنیِ رضایت‌داده‌شده در حالت Free/Guest نیز دوباره فعال است.
- Schema دیتابیس تغییری نکرده و 1.5.0 باقی مانده است.
