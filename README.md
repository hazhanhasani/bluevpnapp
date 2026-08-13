# BlueVPN 4.1.0

BlueVPN is an Android VPN client with a WordPress/MySQL control plane. The repository intentionally keeps only current production source, build automation, and release validation; historical release reports and generated Android snapshots are not source of truth.

## Current production baseline

- BlueVPN: `4.1.0` (`versionCode 40100`)
- WordPress Manager schema: `1.6.0`
- v2rayNG production pin: `2.2.6` (reviewed stable base)
- Xray / AndroidLibXrayLite: `v26.7.28` (safe core backport from the 2.3.x line)
- sing-box source pin: `v1.13.16` (staged validator/runtime; Xray remains Android TUN owner)
- Android ABI: `arm64-v8a`, `armeabi-v7a`

v2rayNG `2.3.3` was reviewed but is currently a pre-release and includes a major Jetpack Compose/runtime lifecycle migration. BlueVPN therefore does not blindly replace its stable upstream checkout with 2.3.3. The newer Xray core is pinned separately, while the BlueVPN exact-start/stop bridge remains on the reviewed stable v2rayNG lifecycle.

## Runtime rules

- Free and Premium pools have separate ownership. WordPress emits a stable `pool_identity`; Android uses it as an entitlement boundary.
- Managed v2rayNG subscriptions have `autoUpdate = false`. Routine account reads never rebuild subscriptions.
- `GET /api/v1/account` is the routine/cache-first account path. `POST /api/v1/account/sync` is reserved for an explicit refresh/payment return and is coalesced.
- A real entitlement/pool identity change causes exactly one authoritative subscription refresh, even when the subscription URL itself did not change.
- Subscription mutation is blocked while a connection owns the MMKV/Xray profile pool.
- Connection-gate waiting is bounded; the UI cannot remain indefinitely in `CONNECTING` because a subscription mutation is still active.
- BlueAI selects locally first and does not import/repair subscriptions on an AI tap. Cloud enrichment is background-only and is not run on app startup.

## Repository

- `android-source/` — canonical BlueVPN Android Kotlin/resources injected into official upstream.
- `bluevpn-manager/` — WordPress/MySQL control plane and REST API.
- `branding/` — app metadata and icon.
- `scripts/prepare_android.py` — applies BlueVPN to the pinned upstream checkout.
- `scripts/validate_release.py` — release contract/regression gate.
- `.github/workflows/` — Android and WordPress release workflows.
- `tests/test_current_release.py` — focused current-release regression tests.
- `release.json` — release metadata consumed by the app/control plane.

Generated source, APK/AAB files, Gradle output, logs, local caches, historical validation reports, and legacy Railway backend source are intentionally excluded from the repository.

## Versioning

Patch numbers are short by policy:

`x.y.0` → `x.y.1` → … → `x.y.10` → `x.(y+1).0`

Historical versions such as `4.0.36` are rolled forward rather than renumbered backward, so the stabilization line starts at `4.1.0`. This preserves monotonic Android/WordPress update comparison.

## Validation

Before release:

```bash
python -m py_compile scripts/prepare_android.py scripts/validate_release.py
python scripts/validate_release.py
pytest -q
find bluevpn-manager -type f -name '*.php' -print0 | xargs -0 -n1 php -l
```

The GitHub Android workflow builds/signs APKs first, publishes the synchronized WordPress control-plane release only after a successful Android build, then publishes the APK release. It does not commit generated source or version metadata back to the source branch.

## License

See `LICENSE` and `NOTICE.md`. Upstream components retain their own licenses and attribution requirements.
