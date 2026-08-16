# BlueVPN 4.11.1 — Background Reliability + GuardCore Assignment Visibility

Android background reliability:
- the lightweight application foreground owner now covers verified Premium as
  well as Free/WARP connections;
- recovered verified sessions reacquire the foreground owner;
- Premium started from Quick Settings/system controls also acquires it;
- Settings now exposes Background Reliability and detects Battery Optimization
  plus Android Data Saver/background-data restrictions;
- direct system settings shortcuts are provided because Android does not allow
  an app to silently whitelist itself;
- a bounded one-time reminder is shown after a verified connection when the OS
  is still restricting BlueVPN.

GuardCore visibility:
- per-provider subscription snapshots now keep safe metadata (count/hash/status)
  without storing extra raw config copies;
- GuardCore admin shows which users are assigned to each GuardCore panel/sub,
  username, subscription id/status, GuardCore config count, total aggregated
  count and last snapshot time;
- a Refresh GuardCore Stats action queues background snapshots for assigned users
  instead of blocking the WordPress admin request.

Validation executed:
- Release validator: PASS
- Python regression suite: 345/345 PASS
- PHP release lint: 24/24 PASS
- GitHub Actions YAML parse: PASS
- Test manifest: exact
- PHP release manifest: exact
- Android Gradle compile/assemble: not re-run locally because pinned upstream v2rayNG is bootstrapped in GitHub CI.
