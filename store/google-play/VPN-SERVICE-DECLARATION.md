# Google Play VpnService declaration — prepared wording

## Is providing a VPN the core functionality of the application?

**Yes.**

## Explanation

BlueVPN is a VPN client. Its primary user-facing function is to establish and manage a device-level encrypted network tunnel after the user explicitly requests a connection and grants Android VPN permission. The Android client uses the platform VPN service to route device traffic through the selected VPN configuration and provides connection state, reconnect/failover, server selection, subscription synchronization, and connection diagnostics.

BlueVPN does not use the VPN service to monetize, sell, or redirect user traffic to advertisers. The Google Play flavor does not initialize or package the third-party Tapsell advertising SDK. The VPN service is used only while VPN functionality requires it, with the required foreground behavior/notification managed by the Android runtime.

## Listing disclosure

Suggested sentence for the Play listing:

> BlueVPN uses Android's VPN service as its core functionality to create and manage an encrypted VPN connection selected by the user.

## Reviewer note

A reviewer can verify the VPN behavior by signing in with the review account, selecting an available connection, tapping Connect, accepting the Android VPN consent dialog, and then checking the live connection status.
