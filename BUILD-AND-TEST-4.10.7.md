# BlueVPN 4.11.4 — Support Admin UI Rebuild

The WordPress support Inbox was rebuilt in code. The previous implementation used
raw white WordPress-style cards and inline forms that ignored BlueVPN's dark
control-center design.

4.11.4 adds:
- dark BlueVPN design tokens;
- four support summary cards;
- real Inbox conversation list;
- dedicated chat panel with customer/operator bubbles;
- BlueAI suggestion inside the conversation;
- grouped conversation controls;
- separate SLA, attachment and internal-note cards;
- structured department/operator management;
- responsive tablet/mobile layouts at 1100px and 760px;
- automatic chat scroll to the newest message.

The support backend, REST API, Android chat, Telegram bridge and database schema are unchanged.

Validation executed:
- Release validator: PASS
- Python regression suite: PASS (35 modules)
- PHP release lint: PASS (24/24)
- GitHub Actions YAML parse: PASS
- Test manifest: exact
- PHP manifest: exact
