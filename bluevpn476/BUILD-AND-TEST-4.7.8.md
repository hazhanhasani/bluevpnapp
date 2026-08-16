# BlueVPN 4.7.8 Build & Test

Implemented Android system integration matching the useful v2rayNG behavior while preserving BlueVPN Free/WARP lifecycle ownership.

## Added

- Quick Settings tile for BlueVPN.
- Persistent VPN notification keeps live proxy/direct traffic stats enabled.
- Notification actions: Stop and Restart.
- Notification tap opens BlueVPN Home instead of the hidden upstream MainActivity.
- Stop cleans Xray, Aether, WARP keep-alive, connected state, and Free session.
- Restart rebuilds Free/WARP through Aether when the account is in Free mode, and restarts the selected v2rayNG profile for Premium.
- First-time VPN permission remains Android-controlled; system actions open BlueVPN when consent is still required.

## Verification performed locally

- Python regression suite: 196/196 PASS.
- Release validator: PASS.
- WordPress PHP syntax lint: PASS.
- GitHub Actions YAML parse: PASS.
- `scripts/prepare_android.py` Python compile: PASS.

A full Gradle APK compile is still performed in GitHub Actions after the pinned v2rayNG checkout is bootstrapped.
