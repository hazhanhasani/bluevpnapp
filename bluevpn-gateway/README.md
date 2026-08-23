# BlueVPN Gateway Metering — 5.1.9 Autopilot

این پوشه دیتاپلین لینوکسی پلن‌های `gateway_metered` را اجرا می‌کند.

## مسیر ترافیک

`Windows / Android -> BlueVPN Gateway (VLESS/TLS + Xray metering) -> Xray native upstreams / local sing-box Hysteria2+TUIC bridge -> Internet`

کلاینت فقط credential خود BlueVPN Gateway را دریافت می‌کند؛ credential و URL اصلی Provider/Manual Source سمت سرور باقی می‌ماند.

## Phase 3 (baseline 5.1.6، حفظ‌شده در 5.1.9)

- HA چند Node با `priority`، `region`، `max_sessions`، Primary/Standby و Drain.
- Reconcile یک‌دقیقه‌ای و region diversity برای جایگزینی Node خراب بدون حذف upstream اصلی از کنترل‌پلین.
- Circuit breaker دارای hysteresis: بعد از 3 مشاهده ناسالم Node از placement خارج می‌شود؛ بعد از 180 ثانیه و 2 heartbeat سالم دوباره بسته می‌شود.
- Feature flag `bluevpn_gateway_phase3_circuit_enabled` امکان rollback فوری circuit breaker را بدون migration دیتابیس می‌دهد.
- pending usage بعد از Xray `reset=true` قبل از شبکه fsync می‌شود.
- `agent_epoch` + seq مونو‌تونیک + event_id مانع replay و دوباره‌شماری می‌شود.
- row lock مرکزی سهمیه را در چند Gateway هم‌زمان serialize می‌کند.
- quota lease محلی fail-closed است: اگر Manager موقتاً قطع شود، Agent بعد از مصرف lease کاربر را از Xray حذف می‌کند.
- Hysteria2/TUIC با sing-box sidecar محلی اجرا می‌شوند؛ Xray همچنان VLESS/TLS ingress و مرجع per-user metering است.
- heartbeat سلامت Xray، CPU/RAM، uptime، pending usage و وضعیت runtime را گزارش می‌کند.


## Phase 4 — Safe Gateway Rollout (5.1.9)

- هر config ساختاری یک `config_generation` دارد و Agent فقط بعد از apply موفق ACK می‌دهد.
- انتشار با Canary و مراحل 10%، 25%، 50% و 100% انجام می‌شود.
- config-hash mismatch، runtime error یا timeout ACK باعث rollback خودکار به نسل پایدار قبلی می‌شود.
- quota/revoke و policy از snapshot جدا هستند و همیشه live از Manager rehydrate می‌شوند.
- rollout تا وقتی همه Agentهای فعال حداقل 5.1.9 نباشند شروع نمی‌شود.
- وضعیت Stable/Active generation و درصد مرحله در پنل Gateway نمایش داده می‌شود.

جزئیات: `PHASE4.md`.

## Runtime

1. Xray رسمی در `/usr/local/bin/xray`.
2. sing-box رسمی در `/usr/local/bin/sing-box` برای Hysteria2/TUIC؛ بدون آن VLESS/VMess/Trojan/Shadowsocks همچنان کار می‌کنند.
3. Python 3.10+، DNS و TLS معتبر.

## نصب

1. در BlueVPN Manager یک Gateway بساز و Node ID/Secret را بردار.
2. `sudo ./install.sh` را اجرا کن.
3. `/etc/bluevpn-gateway/agent.json` را تکمیل کن.
4. `sudo systemctl enable --now bluevpn-gateway`.
5. قبل از assignment صبر کن Node Healthy شود. برای HA حداقل دو Node در Region متفاوت داشته باش.

## Drain / نگهداری

Drain را فعال و Reconcile را اجرا کن. Node جدید session نمی‌گیرد و placement به Node سالم منتقل می‌شود.

## امنیت حسابداری

- Node Secret با HMAC درخواست‌ها را امضا می‌کند و در WordPress encrypted-at-rest است.
- `agent.json` باید 0600 باشد.
- Xray API فقط localhost.
- سهمیه نهایی در MySQL/BlueVPN authoritative است؛ lease فقط fail-closed محلی برای محدودکردن overrun هنگام outage است.


## Phase 5 Autopilot

- ظرفیت Node از CPU/RAM Agent خودکار محاسبه می‌شود.
- Auto-Drain/Auto-Recover نیاز به تنظیم روزانه ندارد.
- Drain دیگر connection موجود را فوراً قطع نمی‌کند؛ Session جایگزین ACK می‌شود و پس از overlap، source retire می‌شود.
- جزئیات: `PHASE5.md`.
