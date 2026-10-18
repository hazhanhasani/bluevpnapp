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
                'success' => $ok, 'status' => ($ok && $this->database->installed()) ? 'degraded' : 'unavailable',
                'service' => 'bluevpn-standalone', 'version' => '0.1.0',
                'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
                'wordpress_required' => false, 'database' => $ok ? 'up' : 'down',
                'cutover_ready' => false,
            ], ($ok && $this->database->installed()) ? 200 : 503);
            return;
        }
        // API requests must not start an unrelated browser admin session.
        if ($path==='/install'||$path==='/login'||$path==='/logout'
            ||$path==='/admin'||str_starts_with($path,'/admin/')
            ||$path==='/api/v1/system/status') {
            Admin::startSession($https);
        }

        try {
            if ($path === '/install') {
                $this->install($method);
                return;
            }
            if (!$this->database->installed()) {
                if (str_starts_with($path,'/api/')||str_starts_with($path,'/wp-json/')) {
                    $this->respondJson(['detail'=>['code'=>'NOT_INSTALLED']],503);
                } else $this->redirect('/install');
                return;
            }
            if ($path === '/login') {
                $this->login($method);
                return;
            }
            if ($path === '/logout') {
                if ($method!=='POST') {
                    $this->respondJson(['detail'=>['code'=>'METHOD_NOT_ALLOWED']],405);
                    return;
                }
                Admin::requireCsrf();
                Admin::logout();
                $this->redirect('/login');
                return;
            }
            if ($path==='/admin'||str_starts_with($path,'/admin/')) {
                (new AdminPanel($this->database))->handle($path,$method);
                return;
            }
            if ($path==='/api/v1/system/status') {
                if ($method!=='GET') {
                    $this->respondJson(['detail'=>['code'=>'METHOD_NOT_ALLOWED']],405);return;
                }
                if (!Admin::authorized()) {
                    $this->respondJson(['detail'=>['code'=>'UNAUTHORIZED']],401);return;
                }
                $this->respondJson(['success'=>true,'inventory'=>$this->database->snapshot()]);
                return;
            }
            if ($method==='GET' && in_array($path,['/','/home','/plans','/download','/account','/support'],true)) {
                (new Site($this->database,$this->config))->handle($path);
                return;
            }
            if (str_starts_with($path,'/api/v1/')
                ||str_starts_with($path,'/wp-json/bluevpn/v1/')
                ||$path==='/wp-json/bluevpn-system/v1/app-connection') {
                (new Api($this->database,$this->config))->handle($path,$method);
                return;
            }
            if (str_starts_with($path,'/webhooks/')
                ||str_starts_with($path,'/sub/')
                ||str_starts_with($path,'/bluevpn/payment/')) {
                $this->respondJson(['detail'=>['code'=>'FEATURE_NOT_CONFIGURED',
                    'message'=>'Payment and subscription handlers are not yet enabled']],503);
                return;
            }
            $this->respondJson(['detail'=>['code'=>'NOT_FOUND']],404);
        } catch (RuntimeException $e) {
            error_log('BlueVPN request rejected: '.get_class($e));
            $this->respondJson(['detail'=>[
                'code'=>$e->getCode()===403?'FORBIDDEN':'INVALID_REQUEST',
                'message'=>'درخواست پذیرفته نشد.',
            ]],$e->getCode()===403?403:400);
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
                $this->screen('نصب BlueVPN', '<p>کلید نصب، ایمیل یا گذرواژه معتبر نیست (حداقل ۱۴ نویسه).</p>' . $this->installForm(), false);
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
        $this->screen('نصب BlueVPN', '<p>پنل مستقل PHP / MySQL روی cPanel</p>' . $this->installForm(), false);
    }

    private function installForm(): string
    {
        return '<form method="post" action="/install">' . Admin::csrfField() .
            '<label>کلید نصب<input type="password" name="installation_key" required autocomplete="off"></label>' .
            '<label>ایمیل مدیر<input type="email" name="email" required autocomplete="username"></label>' .
            '<label>گذرواژه قوی<input type="password" name="password" minlength="14" required autocomplete="new-password"></label>' .
            '<button type="submit">نصب ایمن</button></form>';
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
            $message = '<p class="error">اطلاعات ورود نادرست است یا ورود موقتاً محدود شده است.</p>';
        } elseif ($method !== 'GET') {
            $this->respondJson(['detail' => ['code' => 'METHOD_NOT_ALLOWED']], 405);
            return;
        }
        $form = '<form method="post" action="/login">' . Admin::csrfField() .
            '<label>ایمیل<input type="email" name="email" required autocomplete="username"></label>' .
            '<label>گذرواژه<input type="password" name="password" required autocomplete="current-password"></label>' .
            '<button type="submit">ورود</button></form>';
        $this->screen('ورود مدیر', $message . $form, false);
    }

    private function dashboard(): void
    {
        $inventory = $this->database->snapshot();
        $counts = $inventory['sample_counts'];
        $metrics = [
            ['کاربران', $counts['customers'] ?? '—'],
            ['پلن‌ها', $counts['plans'] ?? '—'],
            ['سفارش‌ها', $counts['orders'] ?? '—'],
            ['جداول موجود', $inventory['legacy_coverage']],
        ];
        $cards = '';
        foreach ($metrics as [$label, $value]) {
            $cards .= '<article class="metric"><span>' . self::e((string) $label) .
                '</span><strong>' . self::e((string) $value) . '</strong></article>';
        }
        $missing = implode(', ', $inventory['missing_tables']);
        $body = '<header class="topbar"><div><span class="eyebrow">CONTROL PLANE / CPANEL</span><h1>نمای کلی BlueVPN</h1>' .
            '<p>نمای زنده داده‌های MySQL؛ این نسخه صرفاً پیش‌نمایش مهاجرت است.</p></div>' .
            '<form action="/logout" method="post">' . Admin::csrfField() . '<button class="outline" type="submit">خروج</button></form></header>' .
            '<section class="metrics">' . $cards . '</section>' .
            '<section class="panels"><article class="panel"><h2>وضعیت مهاجرت</h2><div class="notice">' .
            '<strong>هنوز آماده استفاده اصلی نیست</strong><p>این داشبورد بدون وردپرس اجرا می‌شود؛ اما ورود مشتری، پرداخت، پیامک و سازگاری API هنوز باید تکمیل شوند.</p></div>' .
            '<p><b>جداول منتقل‌شده:</b> ' . self::e($inventory['legacy_coverage']) . '</p>' .
            '<p><b>جداول یافت‌نشده:</b> ' . self::e($missing === '' ? 'موردی در فهرست اولیه نیست' : $missing) . '</p></article>' .
            '<article class="panel"><h2>عملیات و زیرساخت</h2><p>PHP و PDO، نشست امن مدیریت، حفاظت CSRF و بررسی سلامت سرویس.</p>' .
            '<p>قبل از اتصال اپلیکیشن‌های اصلی، تست‌های GitHub Actions و زیردامنه آزمایشی را بررسی کنید.</p>' .
            '<a href="/api/v1/migration/status">مشاهده گزارش مهاجرت (JSON)</a></article></section>';
        $this->screen('مرکز مدیریت BlueVPN', $body, true);
    }

    private function screen(string $title, string $content, bool $dashboard): void
    {
        header('Content-Type: text/html; charset=UTF-8');
        $main = $dashboard ? '<div class="layout"><aside class="sidebar"><div class="logo"><b>◈</b> BlueVPN</div>' .
            '<div class="section-label">CONTROL CENTER</div><nav><a class="active" href="/admin">داشبورد</a>' .
            '<span>کاربران · در دست توسعه</span><span>اشتراک‌ها · در دست توسعه</span>' .
            '<span>سرورها · در دست توسعه</span><span>پرداخت‌ها · در دست توسعه</span>' .
            '<span>پشتیبانی · در دست توسعه</span></nav><div class="env">STAGING / cPanel</div></aside><main class="content">' .
            $content . '</main></div>' : '<main class="auth"><div class="authbox"><div class="logo"><b>◈</b> BlueVPN</div>' .
            '<h1>' . self::e($title) . '</h1>' . $content . '</div></main>';
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' .
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
