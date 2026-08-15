# BlueVPN 4.7.3 changed files

- `android-source/BlueVpnWarpEngine.kt` — reconnect order fixed so per-network LKG/direct endpoint is probed before expensive strategy scan backoff; failed cached endpoint is invalidated immediately.
- `android-source/BlueVpnWarpKeepAliveService.kt` — new sticky foreground owner for the Free/WARP Aether child process, independent of Home Activity lifecycle.
- `android-source/BlueVpnHomeActivity.kt` — starts keep-alive only after successful Aether preparation and stops it only on explicit disconnect.
- `scripts/prepare_android.py` — injects keep-alive source, foreground-service permissions, service declaration, `stopWithTask=false`, and Android 14+ special-use subtype.
- `scripts/validate_release.py` — validates the background-WARP lifecycle contract.
- `tests/test_warp_background_reconnect_473.py` — regression coverage for background persistence, LKG/backoff ordering and failed-cache invalidation.
- `tests/release_test_manifest.json` — adds the 4.7.3 regression module.
- Version metadata updated to 4.7.3 / 40703.
