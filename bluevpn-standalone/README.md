# BlueVPN Standalone Control Plane (cPanel)

This directory is the **WordPress-free migration foundation** for the BlueVPN control plane. It is **staging-only** and intentionally does **not** claim feature parity with the production WordPress plugin. Existing Android/Windows/iOS clients, subscriptions, payments, callbacks, SMS and bots **must stay on the current production backend** until migration contract tests pass.

## Architecture

- PHP 8.2+ with PDO MySQL; Apache with mod_rewrite; MySQL/MariaDB.
- Public web document root: `bluevpn-standalone/public`.
- Private PHP code and `config.php` remain **outside** the document root.
- Reuses an SQL copy of existing tables (`{legacy_prefix}bluevpn_*`); no WordPress runtime or WordPress functions.
- Native admin account table `{legacy_prefix}bluevpn_standalone_admins`; private session cookies, CSRF protection and strict HTTPS.
- Public status: `GET /health`; WordPress health alias: `GET /wp-json/bluevpn-system/v1/health`.
- Authenticated migration snapshot: `GET /api/v1/migration/status`.
- Unsupported app/API endpoints return HTTP 503 with `ENDPOINT_NOT_PORTED` rather than fake successful responses.

## Install on a **staging** cPanel domain

1. Create a staging subdomain with **Document Root** pointing to the `public` directory. Enable an SSL certificate and select PHP 8.2+ with `pdo_mysql` and `openssl`.
2. In cPanel **Manage My Databases**, create a new database and a dedicated DB user. Assign that user only the privileges necessary for your test. Do not reuse live credentials.
3. Back up production WordPress SQL. Import a **copy** into the staging database using phpMyAdmin. The existing BlueVPN tables must retain their exact prefix (usually `wp_bluevpn_`). Existing WP user tables do not need to be executed by this new application.
4. Copy `config.example.php` to `config.php` (outside `public`), edit the DB settings, set `legacy_prefix` to the imported table prefix, and generate a long random installation key:
   ```sh
   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
   ```
5. Keep `app.environment=staging`, set your HTTPS staging hostname in `base_url`, and browse `https://your-staging-domain/install`. Enter the installation key, admin email and strong admin password. Setup can only create the first admin; subsequent visits redirect to login.
6. Open `/admin`. Review the live table inventory and counts. Validate `/health` and migration JSON.
7. **Do not redirect live app domains/DNS or remove WordPress** yet. WordPress-free readiness requires porting all API, payment, webhook, OTP, cron and provider operations and completing cutover rehearsals.

## Security

- Never commit `config.php`, production secrets, export SQL or signing keys.
- Only `public/` is web-accessible; verify visiting `/config.php` returns 404.
- Require TLS, keep PHP `display_errors=Off`, configure IP-level rate limiting in the hosting WAF, and make database backups before any import.
- Installation key is deliberately separate from the admin password and may be replaced with a new random value after setup.
- Data migration is **copy-first**; no destructive SQL or automatic production cutover is performed.
- cPanel cron jobs will replace WP-Cron only after each task has a tested standalone handler. Do not schedule the old WP handlers against the staging database.

## Cutover blockers (must pass before production)

- [ ] Port customer email and OTP login/register, refresh/logout and session semantics.
- [ ] Match Android, Windows and iOS mobile config and subscription API JSON contracts.
- [ ] Port provider sync, plans, orders, BluePal signature verification, idempotent callbacks and entitlement ledger.
- [ ] Port SMS, bot, support, dashboard write operations, free pools, analytics and AI services.
- [ ] Recreate native MySQL migrations for every table and enable safe cPanel Cron Jobs.
- [ ] Add webhook replay, rollback, backup/restore, load tests and security scans.
- [ ] Verify client compatibility, switch staged app endpoints, observe metrics, then perform controlled DNS cutover.

See [official cPanel database documentation](https://docs.cpanel.net/cpanel/databases/mysql-databases/) and [Cron Jobs documentation](https://docs.cpanel.net/cpanel/advanced/cron-jobs/).
