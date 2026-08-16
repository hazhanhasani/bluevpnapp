# BlueVPN 4.11.7 — BluPal Callback + Friendly Webhook Routes

- Defines the exact public Webhook URL `/api/v1/webhooks/blupal`.
- Defines the required BluPal Callback page `/bluevpn/payment/callback/`.
- Callback never trusts browser status/amount; it resolves the local order by invoice id and re-verifies the invoice with BluPal server-to-server before activation.
- Supports common callback invoice parameter names (`invoice_id`, `payment_id`, `invoice`, `id`) because BluPal public API docs currently document Callback as a dashboard field but do not document the callback query-string contract.
- Callback remains useful without an invoice id: it shows a safe return page and relies on Webhook/app polling for authoritative activation.
- Pending paid/provisioning states auto-refresh; success/failure are rendered as dedicated BlueVPN pages.
- Control Center shows both exact copy/paste URLs.

Validation executed:
- Release validator: PASS
- Python regression suite: 390/390 PASS
- PHP release lint: 25/25 PASS
- GitHub Actions YAML parse: PASS
- Test manifest: exact
- PHP release manifest: exact
