# BlueVPN Legacy Contract Audit — Archived Reference

**Update:** WordPress has already been removed from the host and there are no existing customers. The implementation is now a **greenfield cPanel installation**. No database import, password migration or live customer cutover is needed. This document preserves the historical client/API contracts for future parity work.

Audit target: `hazhanhasani/bluevpnapp`, default branch `main`, application release `6.3.5`.

## Confirmed architectural dependencies

| Existing source | Observed dependency | Standalone replacement |
| --- | --- | --- |
| `bluevpn-manager/bluevpn-manager.php` | WordPress plugin lifecycle, hooks, rewrite rules, cron registration | Native PHP bootstrap, request router, cPanel Cron Jobs |
| `bluevpn-manager/includes/class-bluevpn-db.php` | `$wpdb`, `dbDelta()`, WP option/transient storage | PDO with versioned SQL migrations, cache/settings adapter |
| `bluevpn-manager/includes/class-bluevpn-auth.php` | WP REST request and sanitizers; PBKDF2-SHA256 legacy customer hashes; hashed access/refresh sessions | Native HTTP request DTOs, compatible hash verifier, same session/device invariants |
| `bluevpn-manager/includes/class-bluevpn-api.php` | **43 registered BlueVPN v1 GET/POST route entries**, plus system routes | Route-for-route native API contract test suite |
| `bluevpn-manager/includes/class-bluevpn-compat.php` | WP rewrite of `/api/v1/*`, `/health`, subscription links and payment callback | Apache rewrite and native PHP handlers |
| `bluevpn-site/` | WP theme helpers, WordPress media, Elementor, download/account/plans pages | Standalone responsive frontend and media management |
| `bluevpn-windows/Services/AppSettings.cs` and `bluevpn-windows/appsettings.json` | Hard-coded `/wp-json/bluevpn/v1/*` defaults | Preserve aliases during transition; change client defaults only after backend parity |
| `android-source/BlueVpnAccountManager.kt` | Uses `/api/v1/*` and a direct `/wp-json/bluevpn/v1/server-locations/resolve` call | Support both URL namespaces before Android cutover |
| `bluevpn-ios/BlueVPNApp/APIClient.swift` | Current production host URLs | Keep host stable through reverse-proxy/DNS cutover |
| `.github/workflows/external-health.yml` | Expects `/health` and `/wp-json/bluevpn-system/v1/health` to return `status: ok` | Keep `status: degraded` on staging until parity is proven |

There are **46 core CREATE TABLE statements** in the currently committed WordPress database class, with further auxiliary/support tables created by other modules. A full export/import must preserve all BlueVPN tables, collations, relevant indices, data and foreign identifiers; importing only the 20 audited tables from the staging dashboard is insufficient for feature parity.

## Critical compatibility contracts

1. **Authentication:** legacy PBKDF2-SHA256 customer passwords, bearer token SHA256 hashes, per-device refresh rotation, session expiry and revocation; do not sign users out or invalidate subscriptions on cutover.
2. **Entitlements and billing:** provider-owned subscriptions, paid/free pool isolation, orders and ledger invariants, BluePal signed callbacks and replay/idempotency handling.
3. **Network routing:** stable public country/location identifiers and hidden internal server selection; protect sensitive raw configs from user-facing APIs.
4. **Client updates:** Windows `windows/update`, Android `mobile/config`, APK relay, iOS settings, the existing public API domain aliases.
5. **Background operations:** native cron for SMS jobs, provider sync, billing reconciliation, source refresh, support notifications, releases and health checks.
6. **Site UX:** native account, pricing, download, contact/support, SEO, responsive RTL screens and admin role controls.

## Implementation state of this branch

**Implemented:** WordPress-free PHP/PDO bootstrap, private configuration, staging-only installer and admin authentication, secure cookies/CSRF, MySQL table inventory, native health endpoints, responsive RTL admin overview, fail-closed API fallback, README and CI packaging/lint checks.

**NOT implemented:** customer auth and refresh, subscription provisioning, every payment/webhook path, 43 API route parity, OTP/SMS, bots, admin CRUD, website feature parity, scheduled jobs, automatic updater, real deployment, restore verification.

The public health field intentionally reports `degraded` on an otherwise reachable staging instance. `cutover_ready=false` is intentional and must not be changed merely to satisfy a health probe.

## Production cutover gates

- [ ] Export production database and securely import into a separate, tested cPanel MySQL instance. Reconcile row counts, IDs, tokens and hashed secrets without logging sensitive values.
- [ ] Implement and regression-test every API route with golden JSON fixtures from current production responses (redacted).
- [ ] Exercise real customer login/refresh/logout, OTP, plan purchase/payment callback, expired/limited subscription, per-device session limit and multi-provider failover.
- [ ] Verify Android, Windows and iOS against staging with stable certificate, persistent sessions and no WordPress runtime.
- [ ] Run scheduled cron and webhook replay under load with idempotency guarantees and security testing (SQLi, XSS, CSRF, SSRF, rate limit, access control).
- [ ] Rehearse full backups, rollback and DNS cutover. Only then disable the WordPress install and flip the production domain.
- [ ] Update/replace WordPress-specific release and health workflows; ensure any authoritative full-platform deployment preserves `bluevpn-standalone/`.

## Referenced source contracts

- [BlueVPN API](https://github.com/hazhanhasani/bluevpnapp/blob/main/bluevpn-manager/includes/class-bluevpn-api.php)
- [BlueVPN legacy auth](https://github.com/hazhanhasani/bluevpnapp/blob/main/bluevpn-manager/includes/class-bluevpn-auth.php)
- [BlueVPN legacy schema](https://github.com/hazhanhasani/bluevpnapp/blob/main/bluevpn-manager/includes/class-bluevpn-db.php)
- [cPanel database documentation](https://docs.cpanel.net/cpanel/databases/mysql-databases/)
- [cPanel cron documentation](https://docs.cpanel.net/cpanel/advanced/cron-jobs/)
- [PHP PDO documentation](https://www.php.net/manual/en/book.pdo.php)
