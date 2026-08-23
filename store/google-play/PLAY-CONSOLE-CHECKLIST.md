# BlueVPN 5.2.4 — Google Play publishing checklist

Checked against Google Play / Android guidance on 2026-08-23.

## Binary and Android requirements

- Upload `BlueVPN-5.2.4-GooglePlay.aab` produced by `Build Signed BlueVPN Android`.
- BlueVPN uses the upstream v2rayNG 2.2.6 Android project, whose `targetSdk` is 37; the release workflow fails if the target falls below API 36.
- The workflow builds `bundlePlaystoreRelease`, signs the AAB with the permanent Android upload key, and verifies the JAR signature.
- Native libraries are gated for 16 KB ELF LOAD alignment and signed APKs are checked with `zipalign -P 16`.
- Play flavor intentionally removes `REQUEST_INSTALL_PACKAGES` and `AD_ID` from the merged manifest.
- Play flavor does not ship the Tapsell SDK; it uses a no-SDK flavor stub. First-party BlueVPN notices/ads can still be delivered by the Manager feed.

Official references:
- https://support.google.com/googleplay/android-developer/answer/11926878
- https://developer.android.com/guide/app-bundle
- https://developer.android.com/guide/practices/page-sizes

## VpnService declaration

Complete the VpnService declaration in Play Console.

Recommended answers for this codebase:
- Is VPN the core functionality? **Yes**.
- BlueVPN creates a device-level VPN tunnel using the Android VPN service and Xray-based encrypted transports.
- The Store listing must state that a VPN tunnel is the core product function.
- Do not claim traffic inspection/monetization. BlueVPN's Play build does not initialize a third-party ad SDK and must not use tunneled user traffic for ad targeting.

Official reference:
- https://support.google.com/googleplay/android-developer/answer/12564964

See `VPN-SERVICE-DECLARATION.md` for prepared wording.

## Payments and accounts

The Google Play flavor is intentionally **consumption-only**:
- Existing users can sign in and consume an already-active entitlement.
- Account creation is disabled in the Play flavor.
- SMS OTP is login-only and cannot create a new customer from the Play build.
- External BlueVPN checkout links are not offered in the Play build.
- The Play build does not self-install APK updates; it routes users to Google Play for updates.

This avoids shipping a non-Play digital subscription checkout in the Play-distributed binary. If you later want subscription purchases inside the Android app, integrate Google Play Billing and corresponding server-side purchase verification before enabling purchase UI.

Official payment policy:
- https://support.google.com/googleplay/android-developer/answer/17190352

## App content / Data safety

Use `DATA-SAFETY.md` as the engineering inventory, then answer the Play Console questionnaire based on the final production configuration and your real server retention practices.

Public URLs prepared by the WordPress theme:
- Privacy policy: `https://bot.blluepanel.ir/privacy/`
- Terms: `https://bot.blluepanel.ir/terms/`
- Support: `https://bot.blluepanel.ir/support/`

Before submission, open each URL in an incognito browser and confirm it is publicly reachable without login.

## App access for review

Because subscription/account screens can require authentication, provide a dedicated reviewer account in Play Console → App access. Do not use a personal administrator account.

Fill these values yourself:
- Reviewer email/phone: `________________`
- Password/OTP review method: `________________`
- Premium entitlement expires after review: `________________`
- Additional navigation instruction: `Open BlueVPN → Account/Subscription → sign in → Connect.`

## Store listing

- App name: BlueVPN
- Category suggestion: Tools
- Short description and full description: see `LISTING-FA.md`.
- Explain VPN use clearly and accurately.
- Do not promise absolute anonymity, guaranteed access, or impossible speed/security outcomes.
- Prepare current screenshots on real Android devices for phone form factor.
- Feature graphic/icon/screenshots should be uploaded through Play Console using the dimensions shown there at submission time.

## Pre-submit smoke test

On at least one Android 15/16 device and, if available, a 16 KB page-size device/emulator:
1. Fresh install from Play internal testing.
2. Login with an existing account.
3. Confirm no account-creation or external checkout path is visible.
4. Grant VPN consent and connect.
5. Verify real tunneled HTTP connectivity.
6. Switch Wi-Fi ↔ mobile data; confirm reconnect/failover.
7. Force-stop/relaunch; confirm no stale Connected state.
8. Confirm update button opens Google Play and never requests package-install permission.
9. Open privacy/terms/support links.
10. Review Android vitals/pre-launch report before production rollout.
