# BlueVPN 4.1.8

BlueVPN is now rebased as a **custom product/UI on top of official v2rayNG**, instead of treating v2rayNG as a replaceable compatibility bridge.

## Runtime baseline

- v2rayNG: `2.2.6` (current stable release selected for production)
- v2rayNG release commit: `15b4fff`
- Xray / AndroidLibXrayLite: `v26.6.27`
- Android target from upstream: SDK 37
- Production VPN engines: **v2rayNG/Xray only**
- sing-box: **removed**

The newer v2rayNG `2.3.3` is a pre-release and includes the Jetpack Compose migration, so it is not used as the production base for this rebase.

## Architecture

The GitHub workflow first checks out the official v2rayNG tag and then overlays BlueVPN product code. BlueVPN does not fork the core lifecycle.

`v2rayNG source -> BlueVPN branding/UI/account/location overlay -> official v2rayNG CoreServiceManager/CoreVpnService -> Xray`

BlueVPN continues to show only locations to users. Hidden route GUIDs are selected by BlueVPN, but once a GUID is chosen it is committed to `MmkvManager` and started through the stock `CoreServiceManager.startVService(context, guid)` path. No alternate engine, custom TUN owner, custom config compiler or authoritative BlueVPN network pre-check sits between the selected v2rayNG profile and Xray.

## What BlueVPN still owns

- Custom Home and location-only UI
- Hidden routes behind each location
- Free/Premium entitlement isolation
- WordPress/MySQL account and plan control plane
- Update manager
- Advertising
- Local route history used only for ranking; it is not a second VPN engine

## What v2rayNG owns again

- Subscription/profile parsing semantics
- Profile/MMKV representation
- Runtime config generation
- Protocol and transport handling
- Core start/stop lifecycle
- Android VPN service
- TUN
- Xray runtime
- Connection service broadcasts consumed by BlueVPN UI

## Repository layout

- `android-source/` — BlueVPN UI/product overlay copied into the official v2rayNG checkout
- `bluevpn-manager/` — WordPress/MySQL control plane
- `branding/` — application identity and release pin
- `scripts/prepare_android.py` — branding/UI overlay only; **no core lifecycle or protocol parser patches**
- `.github/workflows/build-apk.yml` — checks out official v2rayNG and builds/signs BlueVPN
- `tests/` — current release contracts

## Versioning

Patch numbers remain short: `x.y.0 ... x.y.10`, then the next minor version.

## 4.1.8 rebase

- Removed `BlueVpnEngineManager`.
- Removed sing-box native build/runtime/profile compiler.
- Removed Dual Engine mode/state.
- Restored direct BlueVPN Home -> v2rayNG `CoreServiceManager` start/stop calls.
- Removed read-only MainViewModel runtime patch too; MainViewModel remains upstream.
- Removed the Shadowsocks/parser compatibility patch so profile semantics are exactly those of the pinned v2rayNG release.
- Removed the authoritative BlueVPN DNS/TCP/config-hydration gate before starting a route. Imported profiles are accepted/rejected by the official v2rayNG runtime.

See `LICENSE` and `NOTICE.md` for licensing and attribution.
