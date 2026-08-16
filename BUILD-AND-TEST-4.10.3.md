# BlueVPN 4.10.10 — Live Support Foundation

Implemented as one shared support system:
- Android private chat screen inside BlueVPN.
- Customer-authenticated REST conversations/messages.
- Department definitions with seeded Technical, Account, Billing, Sales and Reseller queues.
- Operator definitions with department membership, online state and active-chat capacity.
- Least-loaded automatic assignment.
- WordPress support Inbox with reply, transfer/assignment and status controls.
- Telegram admin bridge: new messages are delivered to existing BlueVPN bot admins.
- Telegram replies using `/support_reply <conversation_id> <message>` are stored in the same conversation and become visible in Android.
- Per-customer ownership checks, message limits and rate limits.
- Conversation/message/event audit tables in MySQL.
- Adaptive 4.5-second polling only while the support screen is visible.

This release intentionally starts with text chat. Attachments, internal notes, push delivery while the app is fully closed, SLA/escalation and AI-assisted reply suggestions are reserved for subsequent support iterations on this same schema.

Validation executed locally:
- Release validator: PASS
- Python regression suite: 295 tests PASS
- PHP release lint: 24/24 PASS
- GitHub Actions YAML parse: PASS
- Test manifest: exact
- PHP release manifest: exact
- Android Gradle build: not executed locally because the release ZIP intentionally does not include the pinned upstream v2rayNG checkout; GitHub CI remains the authoritative Android compile/assemble gate.
