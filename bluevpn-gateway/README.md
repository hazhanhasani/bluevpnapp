# BlueVPN Gateway Metering — 5.1.5 Phase 2

این پوشه دیتاپلین لینوکسی پلن‌های `gateway_metered` را اجرا می‌کند.

## مسیر ترافیک

`Windows / Android -> BlueVPN Gateway (VLESS/TLS) -> upstream provider/manual configs -> Internet`

کلاینت فقط credential خود BlueVPN Gateway را دریافت می‌کند. URLها و credentialهای اصلی Marzban/PasarGuard/GuardCore و Sourceهای دستی داخل Manager/Gateway باقی می‌مانند.

## تغییرات Phase 2

- **Quota lease محلی و fail-closed:** Manager سهم باقی‌مانده هر کاربر را بین replicaهای سالم تقسیم می‌کند. Agent مصرف reset-counter را قبل از ارسال روی دیسک ثبت می‌کند و اگر lease محلی تمام شود، همان لحظه کاربر را از Xray حذف می‌کند؛ بنابراین قطع Manager باعث مصرف نامحدود نمی‌شود.
- **Exactly-once بهتر:** هر session شماره `seq` مونو‌تونیک دارد. Manager هم `event_id` و هم `(session_id, seq)` را کنترل می‌کند و آخرین seq را در config به Agent برمی‌گرداند تا بعد از پاک‌شدن state یا restart دوباره شماری رخ ندهد.
- **Revoke فوری:** وقتی سهمیه مرکزی تمام شود، endpoint usage شناسه sessionهای revoke شده را همان پاسخ برمی‌گرداند و Agent قبل از poll بعدی آن‌ها را از دیتاپلین حذف می‌کند.
- **Failover سلامت‌محور:** Manager به‌طور پیش‌فرض تا دو replica سالم برای هر کاربر نگه می‌دارد. Node آفلاین یا Drain شده در لینک اشتراک تحویل داده نمی‌شود. `priority` و `max_sessions` برای هر Node قابل تنظیم است.
- **Telemetry:** heartbeat تعداد session فعال، pending usage و load یک‌دقیقه‌ای را به Manager می‌فرستد.
- **Hysteria2 / TUIC:** Xray همچنان inbound و meter اصلی است. برای این دو upstream، Agent یک sing-box sidecar محلی می‌سازد؛ Xray ترافیک همان کاربر را به SOCKS لوکال می‌دهد و sing-box با `urltest` بهترین Hysteria2/TUIC را انتخاب می‌کند. این یعنی accounting همچنان در Xray و BlueVPN باقی می‌ماند.

## Runtime موردنیاز

1. Xray رسمی در `/usr/local/bin/xray`.
2. sing-box رسمی در `/usr/local/bin/sing-box` برای Hysteria2/TUIC. اگر نصب نباشد، VLESS/VMess/Trojan/Shadowsocks همچنان کار می‌کنند ولی Hysteria2/TUIC برای آن Node استفاده نمی‌شوند.
3. DNS و TLS معتبر برای Gateway.
4. Python 3.10+ (Agent فقط stdlib استفاده می‌کند).

## نصب

1. در WordPress: **BlueVPN -> Gateway Metering** یک Node بساز و `NODE_ID` / `NODE_SECRET` یک‌بارمصرف را کپی کن.
2. روی VPS: `sudo ./install.sh`.
3. `/etc/bluevpn-gateway/agent.json` را با URL Manager، Secret، مسیر certificate و runtimeها پر کن.
4. `sudo systemctl enable --now bluevpn-gateway`.
5. `systemctl status bluevpn-gateway` و صفحه Gateway در Manager را بررسی کن.

## Drain و ظرفیت

- `Priority`: عدد کمتر یعنی Node ترجیح داده می‌شود.
- `Max Sessions`: صفر یعنی بدون سقف؛ مقدار مثبت از assignment جدید بعد از پر شدن جلوگیری می‌کند.
- `Drain`: Node فعال می‌ماند و heartbeat می‌دهد، اما session جدید نمی‌گیرد و config دیتاپلین آن خالی می‌شود. برای تعمیر/آپدیت بدون حذف Node استفاده کن.

## امنیت و صحت حسابداری

- Secret هر Node کلید HMAC است، encrypted at rest در WordPress و فقط هنگام ساخت/rotate یک‌بار نمایش داده می‌شود.
- `agent.json` باید mode `0600` داشته باشد.
- Xray API فقط روی `127.0.0.1` گوش می‌دهد.
- pending usage قبل از HTTP روی دیسک fsync می‌شود.
- quota در MySQL/BlueVPN authority است؛ شمارنده Providerها در `gateway_metered` سهمیه را overwrite نمی‌کنند.
- با چند Gateway، quota lease میزان overrun احتمالی هنگام قطع Manager را محدود می‌کند؛ برای quotaهای پولی این رفتار از client-reported accounting قابل اعتمادتر است.
