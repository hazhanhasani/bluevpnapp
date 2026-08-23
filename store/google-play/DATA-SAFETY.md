# Google Play Data safety — engineering inventory for BlueVPN 5.2.3

This is an engineering inventory, not a substitute for the legal/Play Console declaration. The publisher must reconcile it with actual production retention, logging, analytics, payment, and support practices before submission.

## Data observed in the current product flows

### Account and authentication
- Email address and/or phone number when an existing user signs in.
- Session/authentication tokens stored for authenticated use.
- Device/session identifiers used for account/session management.

### Subscription/account state
- Entitlement/subscription state and expiration information.
- Provider/source configuration is controlled through the BlueVPN Manager; secrets must not be exposed in client diagnostics.

### Diagnostics and connection quality
- Runtime status may include connection success/failure, latency, jitter, selected route/server, version, and operational error diagnostics.
- The prepared privacy page states that BlueVPN does not need to store browsing content, messages, or user file contents for the VPN client to function.

### Support
- If the user submits a support request, the text/details deliberately provided by the user are sent to the support service.

## Google Play flavor-specific privacy controls

- No Tapsell SDK is compiled into the Play flavor.
- `AD_ID` permission is removed from the Play merged manifest.
- External APK installation permission is removed from the Play merged manifest.
- Account creation is disabled in the Play build.
- External digital subscription checkout is disabled in the Play build.

## Console review items

Before answering Data safety, confirm with the production backend owner:
- exact server log retention period;
- whether IP addresses are retained, and for how long;
- whether crash/analytics services outside this repository are enabled in production;
- payment provider data flows outside the Play build;
- support-ticket retention/deletion practices;
- account deletion workflow and retention exceptions required by law/security.

Public privacy policy URL prepared by the project:
`https://bot.blluepanel.ir/privacy/`
