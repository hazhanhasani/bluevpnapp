# Architecture

BlueVPN is organized as a multi-platform client system around a WordPress control plane and synchronized release pipeline.

## High-level flow

```text
                   +----------------------+
                   |   WordPress Manager  |
                   | control plane/config |
                   +----------+-----------+
                              |
             account/config/release policy
                              |
          +-------------------+-------------------+
          |                   |                   |
     +----v----+         +----v----+         +----v----+
     | Android |         | Windows |         |   iOS   |
     +----+----+         +----+----+         +----+----+
          |                   |                   |
          +--------- VPN/runtime engines --------+
                              |
                        managed gateways
```

## Android

The canonical BlueVPN Android implementation lives in `android-source/`. CI checks out the pinned upstream v2rayNG source, applies the BlueVPN overlay and build hardening, then compiles the release APKs. Android uses ABI-specific packages rather than forcing every device to download every native architecture.

Connection behavior is split into explicit phases such as preparing, connecting, verifying, connected, recovering and failed. Runtime verification is intentionally separate from merely starting a core process.

## Windows

`bluevpn-windows/` contains the .NET/WPF application and runtime integration. Windows release automation publishes architecture-specific builds and installer metadata. Web surfaces use WebView2 where required; external ad/web failures are expected to fail open rather than block the client.

## iOS

`bluevpn-ios/` contains the Swift application source and project metadata. iOS participates in synchronized version fan-out from Project Health.

## Control plane

`bluevpn-manager/` is the WordPress control plane. It provides mobile configuration, account/entitlement data, release metadata, policy and operational controls used by the clients.

Clients use multiple control-plane bases where supported. Read and retry behavior should preserve idempotency for operations that can be repeated during transport failover.

## Gateway

`bluevpn-gateway/` contains managed gateway installation and agent logic. The Manager also carries distributable gateway assets where required by the deployment flow.

## Release orchestration

`.github/workflows/project-health.yml` is the central gate. It performs repository validation and only then dispatches synchronized platform/component release workflows. BlueVPN Sentinel observes workflow failures separately from the release logic.
