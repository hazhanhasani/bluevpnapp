# BlueVPN Gateway Metering — 5.2.2 Autopilot + One-Click Enrollment

این پوشه دیتاپلین لینوکسی پلن‌های `gateway_metered` را اجرا می‌کند.

## مسیر ترافیک

`Windows / Android -> BlueVPN Gateway (VLESS/TLS + Xray metering) -> Xray native upstreams / local sing-box Hysteria2+TUIC bridge -> Internet`

کلاینت فقط credential خود BlueVPN Gateway را دریافت می‌کند؛ credential و URL اصلی Provider/Manual Source سمت سرور باقی می‌ماند.

## Phase 3 (baseline 5.1.6، حفظ‌شده در 5.2.2)

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


## Phase 4 — Safe Gateway Rollout (introduced in 5.1.8)

- هر config ساختاری یک `config_generation` دارد و Agent فقط بعد از apply موفق ACK می‌دهد.
- انتشار با Canary و مراحل 10%، 25%، 50% و 100% انجام می‌شود.
- config-hash mismatch، runtime error یا timeout ACK باعث rollback خودکار به نسل پایدار قبلی می‌شود.
- quota/revoke و policy از snapshot جدا هستند و همیشه live از Manager rehydrate می‌شوند.
- rollout تا وقتی همه Agentهای فعال حداقل 5.1.8 نباشند شروع نمی‌شود.
- وضعیت Stable/Active generation و درصد مرحله در پنل Gateway نمایش داده می‌شود.

جزئیات: `PHASE4.md`.

## Runtime

1. Xray رسمی در `/usr/local/bin/xray`.
2. sing-box رسمی در `/usr/local/bin/sing-box` برای Hysteria2/TUIC؛ بدون آن VLESS/VMess/Trojan/Shadowsocks همچنان کار می‌کنند.
3. Python 3.10+، DNS و TLS معتبر.

## نصب یک‌مرحله‌ای

1. در BlueVPN Manager فقط **Public Host** را وارد کن.
2. Manager یک دستور نصب یک‌بارمصرف ۳۰ دقیقه‌ای نشان می‌دهد.
3. همان یک دستور را روی VPS با دسترسی root اجرا کن.
4. Node ID، Secret، `agent.json` و systemd خودکار نصب می‌شوند.
5. اگر Xray/TLS روی VPS آماده نباشد، installer به‌صورت fail-safe سرویس را start نمی‌کند و دقیقاً prerequisite مفقود را اعلام می‌کند.

Secret دیگر نیاز به Copy/Paste دستی ندارد. جزئیات امنیتی و rotation خودکار در `PHASE6.md` است.

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


## Phase 6 One-Click Enrollment

- توکن Enrollment یک‌بارمصرف و ۳۰ دقیقه‌ای.
- Agent و service دقیقاً از همان Manager نصب‌شده دریافت می‌شوند.
- Secret هر ۳۰ روز خودکار rotate می‌شود و Agent آن را بدون SSH به‌صورت atomic ذخیره می‌کند.
- Sentinel برای Enrollment بدون Heartbeat watchdog دارد.
- جزئیات: `PHASE6.md`.
