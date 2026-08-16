# BlueVPN 4.11.9 — Kotlin Release Build Fix

The uploaded GitHub Actions log reached `:app:compilePlaystoreReleaseKotlin` and failed on exactly three compiler errors:

1. `BlueVpnHomeActivity.kt`: missing import for `BlueVpnBackgroundOptimizer` (two references).
2. `BlueVpnSupportActivity.kt`: `ScrollView.LayoutParams` is not resolvable in this Kotlin/Android compile surface.

Fixes:
- imported `com.v2ray.ang.bluevpn.BlueVpnBackgroundOptimizer`;
- replaced `ScrollView.LayoutParams(-1, -2)` with `FrameLayout.LayoutParams(-1, -2)`;
- added a dedicated regression gate proving the optimizer is overlaid by `prepare_android.py`, the Home import exists, and the invalid LayoutParams reference cannot return.

Validation:
- Python regression suite: 426/426 PASS
- Release validator: PASS
- PHP release lint: 25/25 PASS
- GitHub Actions YAML parse: PASS
- Test manifest: exact (48 files)
- PHP manifest: exact (25 files)

A full local Android assemble is not possible from the platform ZIP alone because the official v2rayNG upstream is fetched during GitHub Actions. The three compiler errors from the supplied CI log are directly fixed in the reviewed overlay sources.
