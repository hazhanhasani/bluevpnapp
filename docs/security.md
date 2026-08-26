# Security

## Never commit

- production API tokens or OAuth credentials;
- Android/iOS/Windows signing private keys;
- WordPress/database passwords;
- enrollment or gateway tokens;
- subscription URLs containing credentials;
- `.env` files or private production configuration;
- raw user access tokens in diagnostics or tests.

## Diagnostics and telemetry

BlueVPN diagnostics should use normalized event/error classes and redact sensitive values. Logs intended for Sentinel, GitHub Actions artifacts or user copy/paste must not expose tokens, secret URLs or private credentials.

## Update artifacts

Release clients should verify the expected artifact identity before installation. Build pipelines publish checksums/metadata and perform signing/runtime validation where supported. Do not weaken these checks merely to make an update succeed.

## Transport retries

Retrying read operations is generally safe when bounded. Retrying state-changing requests can duplicate effects unless the same logical request identifier is retained and the server performs idempotent replay/deduplication.

## Vulnerability handling

Do not publish exploitable secrets or credential material in a public issue. Use a private maintainer channel for sensitive reports and publish a sanitized fix summary after remediation.
