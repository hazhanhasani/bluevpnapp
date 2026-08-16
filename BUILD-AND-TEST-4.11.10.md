# BlueVPN 4.11.10 — Iran Time Fix

The support-chat UI was displaying the raw `created_at` clock from MySQL. BlueVPN stores MySQL timestamps in UTC, so a message sent around 00:49 in Iran appeared around 21:19 in the Android chat.

Fixes:
- Added UTC MySQL datetime parsing (`yyyy-MM-dd HH:mm:ss[.SSS]`) to the shared Android Persian/Tehran date parser.
- Replaced SupportActivity's regex-only clock extraction with the shared parser so message timestamps are converted to `Asia/Tehran` before display.
- ISO timestamps with `Z` or explicit offsets continue to be honored, and all database timestamps without offsets remain UTC by contract.
- 4.11.9 Kotlin compilation fixes remain intact.

Expected example:
- Stored UTC: `2026-08-16 21:19:00`
- Display in Iran: `۰۰:۴۹` on 2026-08-17 (Asia/Tehran, UTC+03:30).
