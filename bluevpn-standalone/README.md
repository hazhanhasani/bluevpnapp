# BlueVPN Standalone — Native PHP + MySQL for cPanel

**No WordPress, Elementor, WP-Cron, or WordPress database required.** The current deployment is a fresh installation: no customer data needs to be migrated.

## Implemented in this branch

- PHP 8.2+ standalone backend and MySQL 8-compatible install schema (15 clean BlueVPN tables).
- One-time secure installer, private configuration, standalone administrator accounts.
- Mobile-first, right-to-left public website (home, plans, downloads, account, support).
- Real multi-page administrator interface (customers, plans, locations, orders, releases, support, settings).
- New customer email registration/login, hashed passwords, hashed access/refresh tokens and session revocation.
- Android compatibility prefix: \`/api/v1/*\`; Windows compatibility prefix: \`/wp-json/bluevpn/v1/*\`.
- Native mobile config, Windows update metadata, plan/account endpoints.
- cPanel scheduled maintenance task, PHP lint, native schema + API integration tests and GitHub ZIP artifact.

## Explicitly not live yet

**Do not sell subscriptions or claim that the VPN network is ready based only on these files.** BluePal payment and signed callback verification, passwordless SMS OTP, provider provisioning (PasarGuard/Marzban/Hiddify/3x-ui/GuardCore), active VPN subscriptions, customer support messaging and AI routing still need standalone implementations and provider configuration. API methods requiring these features return a clear non-successful response. New plans are created **inactive**, so accidental paid orders cannot be accepted.

## Fresh cPanel installation

1. Create an empty database and MySQL user in **cPanel → Manage My Databases / MySQL Database Wizard**. Grant the new database user privileges for this database only.
2. Get the CI ZIP artifact \`bluevpn-cpanel-standalone.zip\`. Extract the \`bluevpn-standalone\` folder into \`/home/CPANEL_USER/bluevpn-standalone\`.
3. Create a subdomain or domain in cPanel that serves **only** \`/home/CPANEL_USER/bluevpn-standalone/public\` as its document root. Do **not** serve the application root.
4. Set PHP to **8.2+** and enable \`pdo_mysql\`, \`mbstring\`, \`openssl\`, \`json\`; enable Apache \`mod_rewrite\`. Issue a valid TLS certificate and force HTTPS.
5. Copy \`config.example.php\` to \`config.php\` in the **private** directory \`bluevpn-standalone/\`, **outside** \`public/\`. Set MySQL \`host/name/username/password\`, public HTTPS \`base_url\`, and a random installation key:
   \`\`\`sh
   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
   \`\`\`
   Keep \`database.prefix\` blank on a new installation unless you specifically need a custom safe prefix. **No \`wp_\` prefix or export is required.**
6. Open \`https://YOUR_DOMAIN/install\`. Enter the exact installation key, administrator email and strong (14+ character) password.
7. Log in at \`/login\` and open \`/admin\`. Check \`/health\`, \`/api/v1/mobile/config\`, and the native management pages.
8. Add this cPanel Cron Job (once an hour or more often if warranted by actual volume), replacing \`CPANEL_USER\` and confirming your actual PHP CLI path:
   \`\`\`cron
   0 * * * * /usr/local/bin/php /home/CPANEL_USER/bluevpn-standalone/cron/maintenance.php >/dev/null 2>&1
   \`\`\`
   It removes expired auth sessions and rate-limit counters with a database lock. **Do not run WP-Cron.**

## Important URLs

| URL | Purpose |
| --- | --- |
| \`/\` | Public website |
| \`/admin\` | Administrator dashboard |
| \`/admin/plans\` | Plans |
| \`/admin/locations\` | Locations |
| \`/admin/users\` | Customer list |
| \`/admin/orders\` | Orders |
| \`/admin/releases\` | Release metadata |
| \`/health\` | Health / incomplete-feature status |
| \`/api/v1/auth/register\` | Email registration |
| \`/api/v1/auth/login\` | Email login |
| \`/api/v1/auth/refresh\` | Session refresh |
| \`/api/v1/auth/logout\` | Logout |
| \`/api/v1/account\` | Current customer |
| \`/api/v1/plans\` | Active plan list |
| \`/api/v1/mobile/config\` | Android bootstrap |

The health status intentionally stays \`degraded\` until critical VPN/payment integration is complete. This is not an HTTP server failure.

## Security and operations

- Never commit \`config.php\`, SQL dumps, signing keys or any credentials. Private directory must not be within web document root.
- Use HTTPS and configure IP-based brute-force protection using your hosting WAF/ModSecurity.
- Do not let the installer auto-detect old WordPress files. The schema is fresh.
- Payment endpoints currently refuse orders rather than marking them paid.
- New release metadata is recorded inactive; distribution must not be enabled without release integrity checks.
- Set up regular encrypted backups of **both** MySQL and the private configuration, and test restoration.
- cPanel shared hosting hosts **the control panel and API**, not the actual VPN/Xray processes. External VPN nodes are still required.

## Continuous deployment

The GitHub Action \`Standalone cPanel PHP Quality\` builds and tests a ZIP. Optional deploy via **verified SSH/rsync** requires cPanel SSH access and repository secrets; see workflow documentation. No deployment is assumed until the cPanel target has been connected.

### Automatic cPanel deployment (optional)

For automatic push-to-cPanel deployment, connect the host over **SSH with verified host keys**, create GitHub environment \`bluevpn-cpanel\`, then configure these six **environment secrets**:

| Secret | Value |
| --- | --- |
| \`CPANEL_SSH_HOST\` | Hostname of your cPanel SSH server |
| \`CPANEL_SSH_USER\` | cPanel SSH username |
| \`CPANEL_SSH_PORT\` | SSH port, often 22 |
| \`CPANEL_SSH_PRIVATE_KEY\` | Dedicated deployment SSH private key |
| \`CPANEL_SSH_KNOWN_HOSTS\` | Verified host public key line, obtained through a trusted channel |
| \`CPANEL_APP_DIR\` | \`/home/CPANEL_USER/bluevpn-standalone\` |

Then set repository variable \`CPANEL_DEPLOY_ENABLED=true\`. Only successful builds on \`main\` can then deploy, using \`rsync\` without overwriting \`config.php\` or private storage. The remote app directory and configuration must exist before enabling this. The deployment job does **not** create databases, import secrets or provision SSH for you.

## CI verification

A successful workflow must pass PHP lint, 14 static security/contract checks and a MySQL 8 clean installation/API smoke test.

Sources: [cPanel Database Guide](https://docs.cpanel.net/cpanel/databases/mysql-databases/) · [cPanel Cron Jobs](https://docs.cpanel.net/cpanel/advanced/cron-jobs/) · [PHP PDO](https://www.php.net/manual/en/book.pdo.php)
