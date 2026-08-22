# BlueVPN Gateway — Phase 4 / 5.1.8 Safe Rollout

5.1.8 جلوی پخش هم‌زمان config ساختاری جدید روی همه Gatewayها را می‌گیرد. Manager یک نسل پیکربندی پایدار نگه می‌دارد، نسل کاندید می‌سازد و آن را مرحله‌ای منتشر می‌کند.

## Rollout policy

- `config_generation` برای هر پاسخ ساختاری Gateway.
- Agent فقط بعد از validate + apply موفق Xray/sing-box، generation/hash را ACK می‌کند.
- ترتیب rollout: `10% -> 25% -> 50% -> 100%`.
- Canary به‌صورت deterministic از Node سالم و کم‌بار انتخاب می‌شود.
- هر مرحله فقط بعد از ACK همه Nodeهای همان مرحله و 45 ثانیه سلامت پایدار جلو می‌رود.
- نبود ACK بعد از 150 ثانیه، خطای runtime یا config-hash mismatch باعث rollback خودکار می‌شود.
- rollout بعد از rollback برای همان fingerprint به مدت 15 دقیقه cooldown می‌گیرد.
- Nodeهای با Agent قدیمی‌تر از 5.1.8 rollout جدید را متوقف می‌کنند تا ACK واقعی قابل اتکا باشد.

## Safety boundaries

- Drain و Circuit Breaker کنترل اضطراری هستند و منتظر staged rollout نمی‌مانند.
- quota، revoke، seq و entitlement در هر poll از دیتابیس زنده rehydrate می‌شوند؛ snapshot پایدار فقط upstream/config ساختاری را pin می‌کند.
- نسل‌ها در `bluevpn_gateway_config_generations` ذخیره و تاریخچه به شش نسل اخیر محدود می‌شود.
- آخرین generation/hash اعمال‌شده از heartbeat Agent در `gateway_nodes` ثبت می‌شود.

## Rollback flags

- `bluevpn_gateway_safe_rollout_enabled` — خاموش‌کردن rollout مرحله‌ای و برگشت به رفتار live برای عیب‌یابی.
- `bluevpn_gateway_phase3_circuit_enabled` — rollback مستقل Circuit Breaker فاز ۳.
