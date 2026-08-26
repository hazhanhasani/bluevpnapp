# WordPress Control Plane

`bluevpn-manager/` is the BlueVPN WordPress control-plane plugin. `bluevpn-site/` contains the site/theme integration.

## Responsibilities

The Manager owns server-side configuration used by clients, including account/entitlement state, mobile configuration, release metadata, policy controls and operational endpoints.

## Reliability

Client-facing read paths should tolerate temporary failure of one control-plane base where a secondary base is configured. For state-changing requests, failover must be paired with a stable request identifier and server-side deduplication so retrying transport cannot duplicate the logical action.

## Release metadata

Manager/Theme publication is part of the synchronized release fan-out. A GitHub release existing for a plugin or theme does not necessarily mean a WordPress installation has already auto-updated; external health reporting should distinguish published version from active installed version.

## Security

Do not expose WordPress secrets, API tokens, subscription URLs or private control-plane credentials in diagnostics, Sentinel messages or repository fixtures. Public health reports should contain version/state information only.
