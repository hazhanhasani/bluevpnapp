# BlueVPN Gateway — Phase 5 / 5.1.9 Autopilot + Session Handoff

5.1.9 عملیات روزمره Gateway را از حالت تنظیم دستی خارج می‌کند. Manager با Heartbeat واقعی Agent ظرفیت و سلامت Node را می‌سنجد، Node پرریسک را خودکار از placement جدید خارج می‌کند و پس از recovery دوباره وارد مدار می‌کند.

## Autopilot (default ON)

- `bluevpn_gateway_autopilot_enabled` به‌صورت پیش‌فرض روشن است.
- `Priority` و `Max Sessions` دیگر برای کار عادی لازم نیستند؛ ظرفیت مؤثر از CPU core و RAM واقعی Agent محاسبه می‌شود.
- دو Heartbeat بد/فشار شدید CPU یا RAM => Auto-Drain.
- سه Heartbeat سالم => Auto-Recover.
- Manual Drain همچنان Override اضطراری است.
- Region اختیاری است؛ HA حتی بدون تنظیم دستی Region روی Nodeهای جداگانه کار می‌کند.

## Zero-downtime handoff

1. Node در Drain/Auto-Drain دیگر placement جدید نمی‌گیرد.
2. Manager ابتدا Session جایگزین را روی Gateway سالم می‌سازد.
3. Source Session حذف نمی‌شود و connection موجود را نگه می‌دارد.
4. Target باید Heartbeat سالم و Config ACK بعد از شروع migration داشته باشد.
5. پس از ACK، یک overlap امن 60 ثانیه‌ای برقرار می‌ماند.
6. سپس Source Session retire می‌شود.
7. اگر Target طی 240 ثانیه آماده نشود، migration fail-safe می‌شود، Target موقت retire و Source فعال باقی می‌ماند.

Central quota row-lock، `agent_epoch` و sequence replay guard در طول overlap همچنان authoritative هستند؛ بنابراین traffic واقعی هر replica حساب می‌شود ولی replay/duplicate event دوباره شمرده نمی‌شود.

## Operator experience

برای استفاده عادی فقط Public Host لازم است. نام Node و TLS Server Name در صورت خالی بودن از Host ساخته می‌شوند. Priority/Capacity/Drain در حالت Autopilot نیازی به تنظیم ندارند و Advanced Override محسوب می‌شوند.
