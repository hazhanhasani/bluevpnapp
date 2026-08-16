# BlueVPN 4.11.3 — Live Support Chat UI

Android support UI was rebuilt without changing the live-support backend contract.

Changes:
- compact BlueVPN-branded chat header with operator/department presence;
- horizontal conversation chips instead of a raw form;
- designed empty state with a single clear CTA;
- department chooser rendered as native cards instead of a Spinner;
- real message bubbles for customer/support with timestamp, seen state and attachment metadata;
- compact messenger composer: attachment button + message field + circular send button;
- keyboard resize handling and bottom auto-scroll;
- bounded incremental polling preserved;
- signed-out state hides the unusable composer;
- existing REST, attachment and notification contracts remain unchanged.

Validation executed:
- Release validator: PASS
- Python regression suite: 314/314 PASS
- PHP release lint: 24/24 PASS
- GitHub Actions YAML parse: PASS
- Python test manifest: exact
- PHP release manifest: exact
- Android Gradle build: not executed locally because the ZIP bootstraps pinned upstream v2rayNG in GitHub CI.
