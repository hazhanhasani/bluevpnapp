# BlueVPN 4.11.4 — Panel / Site / BluPal / Smart Free Pool

This release is a panel/WordPress release with a small Android reporting hook required for crowd-tested free-pool ranking.

## Payment
- BluePay removed from visible BlueVPN panel/navigation and replaced by BluPal.
- BluPal Base URL: `https://blupal.net/api`.
- Invoice create: `POST /v1/invoices/create`, amount converted from local Toman to documented Rial.
- Invoice status: `GET /v1/invoices/{invoice_id}`.
- Webhook: `/api/v1/webhooks/blupal`.
- Because the published BluPal documentation does not specify a webhook signature, BlueVPN never activates from webhook JSON alone: it re-fetches the invoice with `X-API-Key`, verifies PAID and amount, then provisions idempotently.
- Payment events and provisioning attempts are first-class MySQL tables.
- Admin can retry paid/partial provisioning without creating or charging another invoice.

## Free pool
- Public Telegram preview source `https://t.me/s/persianvpnhub` is seeded as a managed source.
- WordPress periodically extracts public V2Ray-compatible config URIs, deduplicates by SHA-256, and keeps recent candidates.
- Android full-pool background tests report anonymized network-specific latency/jitter/loss/bucket evidence.
- Server aggregates reports and ranks the curated feed; configs with multi-user evidence, higher score/success, lower latency/jitter/loss are preferred.
- The curated feed is exposed at `/api/v1/free/curated` and automatically participates in the legacy/free fallback pool when available.

## UI
- Payment navigation is now "پرداخت / بلوپال".
- BlueVPN-owned frontend OTP/login palette moved from orange/black to the BlueVPN blue/cyan control-center visual language.
- Control-center cards/code blocks no longer force WordPress-white surfaces over the unified dark shell.

## Validation executed
- Release validator: PASS
- Python regression suite: 384/384 PASS
- PHP release lint: 25/25 PASS
- GitHub Actions YAML parse: PASS
- Test manifest: exact
- PHP manifest: exact
- Android Gradle compile/assemble: not executed locally; the repository materializes the pinned upstream v2rayNG runtime in GitHub CI.
