# BlueVPN 4.11.7 — Iran Date/Time Display

- Database and expiry calculations remain UTC.
- All manager-facing date/time presentation uses Asia/Tehran.
- Jalali/Persian calendar display is centralized in BlueVPN_Utils.
- Control Center customers, sessions, payments, releases, SMS, GuardCore details, Support, BlueAI, AI Ops, Telegram Deploy Bot and legacy GitHub/migration status are localized.
- The Control Center live clock is explicitly Asia/Tehran, Persian calendar, and refreshes every second.

Validation executed:
- Release validator: PASS
- Python regression suite: 397/397 PASS
- PHP release lint: 25/25 PASS
- GitHub Actions YAML parse: PASS
- Test manifest: exact
- PHP release manifest: exact

Important architecture rule:
- database timestamps and expiry/OTP/Cron calculations remain UTC;
- manager-facing presentation is localized to Asia/Tehran and Jalali display.
