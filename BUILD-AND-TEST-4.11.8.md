# BlueVPN 4.11.8 — Release Hardening

## Live Support
- Support schema upgraded to 1.2.0.
- Added first-class support topics under departments.
- Android flow is now Department → Topic → Message.
- Selecting a topic no longer immediately tries to create a conversation before
  the user has written the first message.
- Topic/draft/create-request state survives Activity recreation.
- Department/topic loading has an explicit loading/retry state.
- Topic chooser is scrollable.
- Conversation create and message send are idempotent with client request IDs.
- Concurrent retry races return the already-created conversation/message.
- Admin transfer validates department, topic, and operator eligibility server-side.
- Stale operators are excluded from automatic assignment after 10 minutes.
- Urgent/high-priority topics are ordered ahead of normal/low requests.
- Conversation-list metadata uses one joined query instead of N+1 lookups.
- Support tables are included in canonical BlueVPN backup/restore inventory.

## GuardCore Missing Subscription Repair
- GuardCore API panels now participate in the same customer repair scan as
  PasarGuard and Marzban.
- Lost GuardCore mappings are recovered by strict identity matching:
  expected deterministic BlueVPN username, exact email/phone/username, or a
  BlueVPN ownership note containing the WordPress customer id.
- If the remote subscription does not exist, it can be recreated from the
  plan's GuardCore Service IDs without extending the WordPress entitlement.
- Existing GuardCore subscriptions synchronize only `service_ids`; quota,
  usage and expiry are not reset or extended by the repair operation.
- Customer search and repair UI now explicitly include GuardCore.

## Database
- BlueVPN Manager schema inventory: 1.19.0.
- Support schema: 1.2.0.
- Canonical backup now includes:
  support_departments, support_topics, support_operators,
  support_conversations, support_messages, support_events,
  support_attachments, support_notes, support_canned_replies.

## Validation
- Release validator: PASS.
- Python regression suite: 422/422 PASS.
- PHP 8.4 release lint: 25/25 PASS.
- GitHub Actions YAML parse: PASS.
- Test manifest: exact.
- PHP release manifest: exact.
- Kotlin standalone syntax scan for BlueVpnSupportActivity: no parser/syntax
  errors. Android framework symbols are expected to be unresolved outside the
  Gradle runtime.
- Full local Gradle assemble was not run because the official v2rayNG upstream
  is intentionally fetched by GitHub Actions and is not bundled in the platform
  source ZIP.
