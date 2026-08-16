# BlueVPN 4.11.3 — Support Admin Sidebar Fix

The live-support backend and WordPress submenu existed in 4.10.4, but BlueVPN's
custom standalone admin sidebar is not generated from WordPress's `$submenu`.
It uses its own navigation list in `BlueVPN_Unified_UI::nav()`.

Fixes:
- adds `bluevpn-support` to the real BlueVPN custom sidebar under Services;
- adds a dedicated chat/support icon;
- renders the support Inbox inside the same BlueVPN unified shell;
- adds regression tests that require the WordPress submenu slug, custom sidebar
  slug and unified-shell integration to stay synchronized.

No support database migration is required for this release.

Validation executed:
- Release validator: PASS
- Python regression suite: 307/307 PASS
- PHP release lint: 24/24 PASS
- GitHub Actions YAML parse: PASS
- Python test manifest: exact
- PHP release manifest: exact
- Android Gradle build: not executed locally; no Android source changed in this release.
