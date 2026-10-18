<?php
declare(strict_types=1);

namespace BlueVPN\Standalone;

use RuntimeException;
use Throwable;

final class Application
{
    private Database $database;
    private Admin $admin;

    public function __construct(private array $config)
    {
        $this->database = new Database($config['database']);
        $this->admin = new Admin($this->database);
    }

    public function run(): void
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $app = $this->config['app'];
        if (!empty($app['enforce_https']) && !$https) {
            $this->respondJson(['detail' => ['code' => 'HTTPS_REQUIRED', 'message' => 'HTTPS is required']], 403);
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Content-Security-Policy: default-src \'none\'; style-src \'self\'; form-action \'self\'; frame-ancestors \'none\'; base-uri \'none\'');
        header('Cache-Control: no-store, max-age=0');
        header('X-BlueVPN-Timezone: Asia/Tehran');
        header('X-BlueVPN-Calendar: jalali');

        if (in_array($path, ['/health', '/wp-json/bluevpn-system/v1/health'], true) && $method === 'GET') {
            try {
                $ok = $this->database->connection()->query('SELECT 1')->fetchColumn() !== false;
            } catch (Throwable $e) {
                $ok = false;
            }
            $this->respondJson([
                'success' => $ok, 'service' => 'bluevpn-standalone', 'version' => '0.1.0',
                'wordpress_required' => false, 'database' => $ok ? 'up' : 'down',
                'cutover_ready' => false,
            ], $ok ? 200 : 503);
            return;
        }
        Admin::startSession($https);

        try {
            if ($path === '/install') {
                $this->install($method);
                return;
            }
            if (!$this->database->installed()) {
                $this->redirect('/install');
                return;
            }
            if ($path === '/login') {
                $this->login($method);
                return;
            }
            if ($path === '/logout') {
                if ($method !== 'POST') {
                    $this->respondJson(['detail' => ['code' => 'METHOD_NOT_ALLOWED']], 405);
                    return;
                }
                Admin::requireCsrf();
                Admin::logout();
                $this->redirect('/login');
                return;
            }
            if ($path === '/' || $path === '/admin') {
                if (!Admin::authorized()) {
                    $this->redirect('/login');
                    return;
                }
                $this->dashboard();
                return;
            }
            if ($path === '/api/v1/migration/status') {
                if (!Admin::authorized()) {
                    $this->respondJson(['detail' => ['code' => 'UNAUTHORIZED']], 401);
                    return;
                }
                $this->respondJson(['success' => true, 'inventory' => $this->database->snapshot()]);
                return;
            }
            if (str_starts_with($path, '/api/v1/')
                || str_starts_with($path, '/wp-json/bluevpn/v1/')
                || str_starts_with($path, '/webhooks/')
                || str_starts_with($path, '/sub/')) {
                // This endpoint must never falsely succeed until its full payment,
                // session, provider and data invariants have been ported and tested.
                $this->respondJson(['detail' => [
                    'code' => 'ENDPOINT_NOT_PORTED',
                    'message' => 'Staging endpoint is not yet available. Do not switch clients.',
                ]], 503);
                return;
            }
            $this->respondJson(['detail' => ['code' => 'NOT_FOUND']], 404);
        } catch (RuntimeException $e) {
            $this->respondJson(['detail' => [
                'code' => $e->getCode() === 403 ? 'FORBIDDEN' : 'INVALID_REQUEST',
                'message' => 'Request rejected',
            ]], $e->getCode() === 403 ? 403 : 400);
        }
    }

    private function install(string $method): void
    {
        if ($this->database->installed()) {
            $this->redirect('/login');
            return;
        }
        $key = (string) ($this->config['app']['installation_key'] ?? '');
        if (strlen($key) < 32 || str_contains($key, 'REPLACE_')) {
            $this->respondJson(['detail' => ['code' => 'CONFIGURE_INSTALLATION_KEY']], 503);
            return;
        }
        if ($method === 'POST') {
            Admin::requireCsrf();
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 14
                || !hash_equals($key, (string) ($_POST['installation_key'] ?? ''))) {
                $this->screen('Install BlueVPN', '<p>Invalid setup key, email or password (minimum 14 characters).</p>' . $this->installForm(), false);
                return;
            }
            $this->database->installAdmin($email, $password);
            $this->redirect('/login');
            return;
        }
        if ($method !== 'GET') {
            $this->respondJson(['detail' => ['code' => 'METHOD_NOT_ALLOWED']], 405);
            return;
        }
        $this->screen('Install BlueVPN', '<p>Standalone control plane • staging mode</p>' . $this->installForm(), false);
    }

    private function installForm(): string
    {
        return '<form method="post" action="/install">' . Admin::csrfField() .
            '<label>Installation key<input type="password" name="installation_key" required autocomplete="off"></label>' .
            '<label>Administrator email<input type="email" name="email" required autocomplete="username"></label>' .
            '<label>Strong password<input type="password" name="password" minlength="14" required autocomplete="new-password"></label>' .
            '<button type="submit">Secure installation</button></form>';
    }

    private function login(string $method): void
    {
        if (Admin::authorized()) {
            $this->redirect('/admin');
            return;
        }
        $message = '';
        if ($method === 'POST') {
            Admin::requireCsrf();
            if ($this->admin->authenticate((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
                $this->redirect('/admin');
                return;
            }
            $message = '<p class="error">Invalid credentials or temporarily rate limited.</p>';
        } elseif ($method !== 'GET') {
            $this->respondJson(['detail' => ['code' => 'METHOD_NOT_ALLOWED']], 405);
            return;
        }
        $form = '<form method="post" action="/login">' . Admin::csrfField() .
            '<label>Email<input type="email" name="email" required autocomplete="username"></label>' .
            '<label>Password<input type="password" name="password" required autocomplete="current-password"></label>' .
            '<button type="submit">Sign in</button></form>';
        $this->screen('Admin sign in', $message . $form, false);
    }

    private function dashboard(): void
    {
        $inventory = $this->database->snapshot();
        $counts = $inventory['sample_counts'];
        $metrics = [
            ['Users', $counts['customers'] ?? '—'],
            ['Plans', $counts['plans'] ?? '—'],
            ['Orders', $counts['orders'] ?? '—'],
            ['Legacy tables', $inventory['legacy_coverage']],
        ];
        $cards = '';
        foreach ($metrics as [$label, $value]) {
            $cards .= '<article class="metric"><span>' . self::e((string) $label) .
                '</span><strong>' . self::e((string) $value) . '</strong></article>';
        }
        $missing = implode(', ', $inventory['missing_tables']);
        $body = '<header class="topbar"><div><span class="eyebrow">CONTROL PLANE / CPANEL</span><h1>BlueVPN Overview</h1>' .
            '<p>Live MySQL inventory. This is a migration preview, not a production cutover.</p></div>' .
            '<form action="/logout" method="post">' . Admin::csrfField() . '<button class="outline" type="submit">Log out</button></form></header>' .
            '<section class="metrics">' . $cards . '</section>' .
            '<section class="panels"><article class="panel"><h2>Migration readiness</h2><div class="notice">' .
            '<strong>Not ready for production</strong><p>WordPress is not loaded by this dashboard, but customer login, payments, OTP, provider sync and mobile API parity are pending.</p></div>' .
            '<p><b>Imported legacy schema:</b> ' . self::e($inventory['legacy_coverage']) . '</p>' .
            '<p><b>Missing audited tables:</b> ' . self::e($missing === '' ? 'None in current sample' : $missing) . '</p></article>' .
            '<article class="panel"><h2>Operations</h2><p>PHP + PDO, session-protected admin, fixed-origin forms and JSON health checks.</p>' .
            '<p>Use GitHub Actions and a staging subdomain before routing actual app traffic.</p>' .
            '<a href="/api/v1/migration/status">View migration inventory (JSON)</a></article></section>';
        $this->screen('BlueVPN Control Center', $body, true);
    }

    private function screen(string $title, string $content, bool $dashboard): void
    {
        header('Content-Type: text/html; charset=UTF-8');
        $main = $dashboard ? '<div class="layout"><aside class="sidebar"><div class="logo"><b>◈</b> BlueVPN</div>' .
            '<div class="section-label">CONTROL CENTER</div><nav><a class="active" href="/admin">Overview</a>' .
            '<span>Users · coming next</span><span>Subscriptions · coming next</span>' .
            '<span>Servers · coming next</span><span>Payments · coming next</span>' .
            '<span>Support · coming next</span></nav><div class="env">STAGING / cPanel</div></aside><main class="content">' .
            $content . '</main></div>' : '<main class="auth"><div class="authbox"><div class="logo"><b>◈</b> BlueVPN</div>' .
            '<h1>' . self::e($title) . '</h1>' . $content . '</div></main>';
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' .
            '<meta name="robots" content="noindex,nofollow"><title>' . self::e($title) .
            '</title><link rel="stylesheet" href="/assets/app.css"></head><body>' . $main . '</body></html>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function redirect(string $path): void
    {
        header('Location: ' . $path, true, 303);
    }

    private function respondJson(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Language: fa-IR');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
