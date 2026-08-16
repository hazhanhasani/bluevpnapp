# BlueVPN 4.10.8 — Premium Instant UI + Live Support Phase 2

Premium:
- Premium no longer exposes long `در حال اتصال` / `در حال تأیید اتصال` states.
- After VPN permission is already available and a Premium candidate is selected,
  the UI immediately renders `متصل` with a disconnect action.
- This is visual optimism only. `connectionVerified`, persisted CONNECTED state,
  Xray real-ping, DNS/HTTPS verification and candidate failover remain unchanged.
- Failed Premium candidates switch silently to the next entitlement-isolated
  Premium candidate. Exhaustion still ends in a real error state.

Live Support phase 2:
- image/file attachments up to 4 MiB with server-side MIME sniffing;
- WordPress operator attachments;
- internal notes not exposed to customer APIs;
- per-department first-response and resolution SLA;
- SLA overdue indicators;
- operator online/offline presence and last-seen state;
- canned replies;
- BlueAI reply suggestions (suggestion-only, never auto-send);
- background unread-message notification polling via WorkManager every 15 minutes,
  only with network connectivity and an authenticated account;
- notification deep-link to the exact support conversation.

Validation executed:
- Release validator: PASS
- Python regression suite: 303 tests PASS
- PHP release lint: 24/24 PASS
- GitHub Actions YAML parse: PASS
- Python test manifest: exact
- PHP release manifest: exact
- Android Gradle compile/assemble: not executed locally because the release ZIP intentionally bootstraps the pinned upstream v2rayNG checkout in GitHub CI.
