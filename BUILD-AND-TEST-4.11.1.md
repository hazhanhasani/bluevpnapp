# BlueVPN 4.11.3 — GuardCore API 0.13 Integration

Source contract: GuardCore OpenAPI 3.1, API version 0.13.0 supplied from the official
`api.cyberpower.space` Swagger/OpenAPI document.

Implemented:
- API Key and OAuth password auth; optional TOTP query support on token issuance;
- one-time TOTP/password bootstrap that retrieves the current admin API key and
  switches unattended BlueVPN automation to `X-API-Key`;
- `/openapi.json` capability/version detection with fail-soft behavior;
- official Services, Nodes, Node Stats, Subscription Stats, Status Stats,
  Agent Stats, 7-day Usage and Most Usage synchronization;
- Service picker for BlueVPN plans from the live GuardCore service catalog;
- subscription normalization for `is_active`, `is_online`, `online_at`,
  `last_request_at`, `last_client_agent`, service IDs and usage fields;
- official enable/disable/revoke/reset bulk lifecycle actions;
- Node enable/disable controls;
- per-user GuardCore detail and usage-log view;
- reached/limited/expired subscription view;
- expiry reconciliation: an expired BlueVPN entitlement disables its mapped
  GuardCore subscription; renewal/provisioning re-enables it;
- cached capabilities/nodes/stats with 5-minute refresh TTL.

`limit_usage` and `limit_expire` unit modes remain configurable because the
provided OpenAPI schema defines integer fields but does not document their unit
semantics. Existing remote `auto_renewals` are read and preserved by omission on
normal updates; BlueVPN does not invent renewal semantics not specified by the
source contract.

Validation executed:
- Release validator: PASS
- Python regression suite: 355/355 PASS
- PHP release lint: 24/24 PASS
- GitHub Actions YAML parse: PASS
- Test manifest: exact
- PHP release manifest: exact
- Android Gradle compile/assemble: not re-run locally because this release changes WordPress/GuardCore integration and release metadata, not Android runtime source.
