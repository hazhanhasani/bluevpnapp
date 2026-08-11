<?php
if (!defined('ABSPATH')) exit;

/**
 * Native Telegram deploy/build bot for BlueVPN.
 *
 * Replaces the Railway polling runtime with a Telegram webhook hosted by the
 * same WordPress/MySQL backend. ZIP deployment uses the GitHub Git Data API,
 * so the WordPress host does not need git, Docker, Python or a long-running
 * worker process.
 */
final class BlueVPN_Telegram_Bot {
    private const PROCESS_HOOK = 'bluevpn_bot_process_job';
    private const POLL_HOOK = 'bluevpn_bot_poll_builds';
    private const SETTINGS_TABLE = 'bot_settings';
    private const JOBS_TABLE = 'bot_jobs';
    private const MAX_FILES_DEFAULT = 25000;
    private const MAX_EXTRACTED_MB_DEFAULT = 900;
    private const MAX_ZIP_MB_DEFAULT = 50;
    private const GITHUB_API = 'https://api.github.com';
    private const GITHUB_API_VERSION = '2022-11-28';

    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('admin_menu', [self::class, 'register_menu'], 20);
        add_action('admin_post_bluevpn_bot_save', [self::class, 'admin_save']);
        add_action('admin_post_bluevpn_bot_set_webhook', [self::class, 'admin_set_webhook']);
        add_action('admin_post_bluevpn_bot_delete_webhook', [self::class, 'admin_delete_webhook']);
        add_action('admin_post_bluevpn_bot_test', [self::class, 'admin_test']);
        add_action(self::PROCESS_HOOK, [self::class, 'process_job'], 10, 1);
        add_action(self::POLL_HOOK, [self::class, 'poll_builds']);
        add_action('init', [self::class, 'ensure_poll_schedule'], 25);
    }

    public static function activate(): void {
        self::ensure_defaults();
        self::ensure_poll_schedule();
    }

    public static function deactivate(): void {
        $next = wp_next_scheduled(self::POLL_HOOK);
        while ($next) {
            wp_unschedule_event($next, self::POLL_HOOK);
            $next = wp_next_scheduled(self::POLL_HOOK);
        }
    }

    public static function ensure_poll_schedule(): void {
        if (!wp_next_scheduled(self::POLL_HOOK)) {
            wp_schedule_event(time() + 60, 'bluevpn_one_minute', self::POLL_HOOK);
        }
    }

    private static function settings_table(): string { return BlueVPN_DB::table(self::SETTINGS_TABLE); }
    private static function jobs_table(): string { return BlueVPN_DB::table(self::JOBS_TABLE); }

    public static function defaults(): array {
        return [
            'id' => 1,
            'enabled' => 0,
            'bot_token_enc' => '',
            'admin_ids' => '',
            'github_token_enc' => '',
            'github_repository' => 'hazhanhasani/bluevpnapp',
            'git_branch' => 'main',
            'github_workflow' => 'build-apk.yml',
            'repository_dispatch_event' => 'bluevpn_build',
            'max_zip_mb' => self::MAX_ZIP_MB_DEFAULT,
            'max_extracted_mb' => self::MAX_EXTRACTED_MB_DEFAULT,
            'max_files' => self::MAX_FILES_DEFAULT,
            'webhook_secret' => '',
            'webhook_secret_token_enc' => '',
            'webhook_status' => 'not_configured',
            'webhook_last_error' => '',
            'last_update_at' => null,
            'updated_at' => BlueVPN_Utils::now_mysql(),
        ];
    }

    public static function ensure_defaults(): void {
        global $wpdb;
        $t = self::settings_table();
        $exists = $wpdb->get_var("SELECT id FROM {$t} WHERE id=1");
        if ($exists) return;
        $d = self::defaults();
        $d['webhook_secret'] = self::random_secret(32);
        $d['webhook_secret_token_enc'] = BlueVPN_Utils::encrypt_secret(self::random_secret(32));
        $wpdb->insert($t, $d);
    }

    public static function settings(): array {
        self::ensure_defaults();
        global $wpdb;
        $row = $wpdb->get_row('SELECT * FROM ' . self::settings_table() . ' WHERE id=1', ARRAY_A) ?: [];
        return array_replace(self::defaults(), $row);
    }

    public static function runtime_ready(): bool {
        $s = self::settings();
        return !empty($s['enabled']) && self::bot_token($s) !== '' && self::github_token($s) !== '' && self::admin_ids($s);
    }

    public static function import_legacy_runtime(array $runtime): array {
        self::ensure_defaults();
        global $wpdb;
        $t = self::settings_table();
        $old = self::settings();
        $bot = trim((string)($runtime['BOT_TOKEN'] ?? ''));
        $gh = trim((string)($runtime['GITHUB_TOKEN'] ?? ''));
        $repo = trim((string)($runtime['GITHUB_REPOSITORY'] ?? $old['github_repository']));
        $admins = trim((string)($runtime['ADMIN_IDS'] ?? $old['admin_ids']));
        $branch = trim((string)($runtime['GIT_BRANCH'] ?? $old['git_branch']));
        $workflow = trim((string)($runtime['GITHUB_WORKFLOW'] ?? $old['github_workflow']));
        $event = trim((string)($runtime['GITHUB_REPOSITORY_DISPATCH_EVENT'] ?? $old['repository_dispatch_event']));
        $data = [
            'enabled' => ($bot !== '' && $gh !== '' && $admins !== '') ? 1 : (int)$old['enabled'],
            'bot_token_enc' => $bot !== '' ? BlueVPN_Utils::encrypt_secret($bot) : (string)$old['bot_token_enc'],
            'github_token_enc' => $gh !== '' ? BlueVPN_Utils::encrypt_secret($gh) : (string)$old['github_token_enc'],
            'github_repository' => preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) ? $repo : (string)$old['github_repository'],
            'admin_ids' => self::sanitize_admin_ids($admins),
            'git_branch' => self::sanitize_branch($branch),
            'github_workflow' => sanitize_file_name($workflow ?: 'build-apk.yml'),
            'repository_dispatch_event' => sanitize_key($event ?: 'bluevpn_build'),
            'max_zip_mb' => self::bounded_int($runtime['MAX_ZIP_MB'] ?? $old['max_zip_mb'], 1, 200, self::MAX_ZIP_MB_DEFAULT),
            'max_extracted_mb' => self::bounded_int($runtime['MAX_EXTRACTED_MB'] ?? $old['max_extracted_mb'], 50, 4096, self::MAX_EXTRACTED_MB_DEFAULT),
            'max_files' => self::bounded_int($runtime['MAX_FILES'] ?? $old['max_files'], 100, 100000, self::MAX_FILES_DEFAULT),
            'updated_at' => BlueVPN_Utils::now_mysql(),
        ];
        $wpdb->update($t, $data, ['id' => 1]);
        update_option('bluevpn_bot_runtime_migrated_at', BlueVPN_Utils::iso_now(), false);
        $result = ['success' => self::runtime_ready(), 'message' => 'تنظیمات Runtime ربات از Railway به WordPress منتقل شد.'];
        if (self::runtime_ready()) {
            $webhook = self::set_webhook();
            if (is_wp_error($webhook)) {
                $result['webhook_error'] = $webhook->get_error_message();
                $result['message'] .= ' تنظیم Webhook نیاز به بررسی دارد.';
            }
        }
        return $result;
    }

    private static function bounded_int($v, int $min, int $max, int $fallback): int {
        $n = is_numeric($v) ? (int)$v : $fallback;
        return max($min, min($max, $n));
    }

    private static function sanitize_admin_ids(string $raw): string {
        $ids = [];
        foreach (preg_split('/[,\s]+/', $raw) ?: [] as $part) {
            $part = trim($part);
            if ($part !== '' && preg_match('/^-?\d{4,20}$/', $part)) $ids[] = $part;
        }
        return implode(',', array_values(array_unique($ids)));
    }

    private static function sanitize_branch(string $branch): string {
        $branch = trim($branch);
        if ($branch === '' || preg_match('#(^[./]|\.\.|[~^:?*\[\\\s]|\.lock$|/$)#', $branch)) return 'main';
        return substr($branch, 0, 180);
    }

    private static function random_secret(int $bytes = 32): string {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private static function bot_token(?array $s = null): string {
        $s = $s ?: self::settings();
        return BlueVPN_Utils::decrypt_secret((string)$s['bot_token_enc']);
    }
    private static function github_token(?array $s = null): string {
        $s = $s ?: self::settings();
        return BlueVPN_Utils::decrypt_secret((string)$s['github_token_enc']);
    }
    private static function webhook_secret_token(?array $s = null): string {
        $s = $s ?: self::settings();
        return BlueVPN_Utils::decrypt_secret((string)$s['webhook_secret_token_enc']);
    }
    private static function admin_ids(?array $s = null): array {
        $s = $s ?: self::settings();
        return array_map('strval', array_filter(explode(',', (string)$s['admin_ids']), 'strlen'));
    }
    private static function is_admin($userId, ?array $s = null): bool {
        if ($userId === null || $userId === '') return false;
        return in_array((string)$userId, self::admin_ids($s), true);
    }

    public static function register_routes(): void {
        register_rest_route('bluevpn-bot/v1', '/webhook/(?P<secret>[A-Za-z0-9_-]{20,120})', [
            'methods' => 'POST',
            'callback' => [self::class, 'webhook'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('bluevpn-bot/v1', '/health', [
            'methods' => 'GET',
            'callback' => static function () {
                $s = self::settings();
                return new WP_REST_Response([
                    'status' => 'ok',
                    'service' => 'bluevpn-wordpress-telegram-bot',
                    'version' => BLUEVPN_MANAGER_VERSION,
                    'enabled' => (bool)$s['enabled'],
                    'runtime_ready' => self::runtime_ready(),
                    'webhook_status' => (string)$s['webhook_status'],
                ]);
            },
            'permission_callback' => '__return_true',
        ]);
    }

    public static function webhook(WP_REST_Request $request): WP_REST_Response {
        $s = self::settings();
        if (empty($s['enabled'])) return new WP_REST_Response(['ok' => true, 'ignored' => 'disabled']);
        if (!hash_equals((string)$s['webhook_secret'], (string)$request['secret'])) {
            return new WP_REST_Response(['ok' => false], 404);
        }
        $expected = self::webhook_secret_token($s);
        $provided = trim((string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''));
        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            return new WP_REST_Response(['ok' => false], 401);
        }
        $body = $request->get_json_params();
        if (!is_array($body)) return new WP_REST_Response(['ok' => true]);
        global $wpdb;
        $wpdb->update(self::settings_table(), ['last_update_at' => BlueVPN_Utils::now_mysql()], ['id' => 1]);
        try {
            self::handle_update($body, $s);
        } catch (Throwable $e) {
            error_log('BlueVPN Telegram webhook: ' . self::redact($e->getMessage(), $s));
        }
        return new WP_REST_Response(['ok' => true]);
    }

    private static function handle_update(array $u, array $s): void {
        if (!empty($u['callback_query']) && is_array($u['callback_query'])) {
            self::handle_callback($u['callback_query'], $s);
            return;
        }
        $m = $u['message'] ?? null;
        if (!is_array($m)) return;
        $userId = $m['from']['id'] ?? null;
        $chatId = $m['chat']['id'] ?? null;
        if (!self::is_admin($userId, $s)) {
            if ($chatId !== null) self::send_message($chatId, '⛔️ دسترسی ندارید.', [], $s);
            return;
        }
        if ($chatId === null) return;
        $text = trim((string)($m['text'] ?? ''));
        if ($text !== '' && self::capture_guardcore_link((string)$chatId, (string)$userId, $text, $s)) return;
        if (!empty($m['document']) && is_array($m['document'])) {
            $doc = $m['document'];
            $name = (string)($doc['file_name'] ?? '');
            if (str_ends_with(strtolower($name), '.zip')) {
                self::queue_zip($chatId, $userId, $m, $doc, $s);
                return;
            }
        }
        if ($text === '/start' || $text === '📦 نصب و ساخت خودکار') {
            self::send_message($chatId,
                "✅ <b>ربات BlueVPN روی WordPress آماده است</b>\n" .
                'نسخه: ' . esc_html(BLUEVPN_MANAGER_VERSION) . "\n" .
                'Backend: WordPress/MySQL + Telegram Webhook' . "\n" .
                'مخزن: <code>' . esc_html((string)$s['github_repository']) . "</code>\n\n" .
                "ZIP پروژه یا آپدیت را بفرست؛ فایل‌ها روی GitHub Commit می‌شوند و Build از همان Branch اجرا می‌شود.",
                self::keyboard(), $s);
        } elseif (in_array($text, ['/build', '🚀 ساخت فوری', '🛠 ساخت دوباره'], true)) {
            self::queue_rebuild($chatId, $userId, $s);
        } elseif (in_array($text, ['/status', '📊 وضعیت'], true)) {
            self::send_status($chatId, $s);
        } elseif (in_array($text, ['/unlock', '🔓 آزادسازی عملیات'], true)) {
            self::unlock_chat($chatId, $s);
        } elseif (in_array($text, ['/latest', '⬇️ دریافت آخرین APK'], true)) {
            self::send_latest($chatId, $s);
        } elseif (in_array($text, ['/signing', '🔐 بررسی امضا'], true)) {
            self::send_signing_status($chatId, $s);
        } elseif (in_array($text, ['/guardcore', '🟡 صف GuardCore'], true)) {
            self::send_guardcore_queue($chatId, $s);
        } else {
            self::send_message($chatId, 'ZIP را بفرست یا از دکمه‌های منو استفاده کن.', self::keyboard(), $s);
        }
    }

    private static function keyboard(): array {
        return ['keyboard' => [
            [['text' => '📦 نصب و ساخت خودکار']],
            [['text' => '🟡 صف GuardCore'], ['text' => '📊 وضعیت']],
            [['text' => '🚀 ساخت فوری'], ['text' => '⬇️ دریافت آخرین APK']],
            [['text' => '🔓 آزادسازی عملیات'], ['text' => '🔐 بررسی امضا']],
        ], 'resize_keyboard' => true];
    }

    private static function api(string $method, array $params = [], ?array $s = null) {
        $s = $s ?: self::settings();
        $token = self::bot_token($s);
        if ($token === '') return new WP_Error('bluevpn_bot_token', 'BOT_TOKEN تنظیم نشده است.');
        foreach (['reply_markup'] as $key) {
            if (isset($params[$key]) && is_array($params[$key])) $params[$key] = wp_json_encode($params[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $res = wp_remote_post('https://api.telegram.org/bot' . rawurlencode($token) . '/' . rawurlencode($method), [
            'timeout' => 30,
            'redirection' => 2,
            'body' => $params,
            'user-agent' => 'BlueVPN-WordPress-Bot/' . BLUEVPN_MANAGER_VERSION,
        ]);
        if (is_wp_error($res)) return $res;
        $code = (int)wp_remote_retrieve_response_code($res);
        $json = json_decode(wp_remote_retrieve_body($res), true);
        if ($code < 200 || $code >= 300 || !is_array($json) || empty($json['ok'])) {
            return new WP_Error('bluevpn_bot_http', 'Telegram API: ' . sanitize_text_field((string)($json['description'] ?? ('HTTP ' . $code))));
        }
        return $json['result'] ?? true;
    }

    private static function send_message($chatId, string $text, array $replyMarkup = [], ?array $s = null) {
        $params = ['chat_id' => (string)$chatId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true'];
        if ($replyMarkup) $params['reply_markup'] = $replyMarkup;
        return self::api('sendMessage', $params, $s);
    }

    private static function answer_callback(string $id, string $text = 'ثبت شد', bool $alert = false, ?array $s = null): void {
        self::api('answerCallbackQuery', ['callback_query_id' => $id, 'text' => $text, 'show_alert' => $alert ? 'true' : 'false'], $s);
    }

    private static function queue_zip($chatId, $userId, array $message, array $doc, array $s): void {
        $size = (int)($doc['file_size'] ?? 0);
        $limit = (int)$s['max_zip_mb'] * 1024 * 1024;
        if ($size > 0 && $size > $limit) {
            self::send_message($chatId, '❌ حداکثر حجم ZIP برابر ' . (int)$s['max_zip_mb'] . ' مگابایت است.', self::keyboard(), $s);
            return;
        }
        if (self::chat_has_active_job((string)$chatId)) {
            self::send_message($chatId, "⏳ یک نصب یا Build از قبل در حال اجراست.\nبرای توقف، «🔓 آزادسازی عملیات» را بزن.", self::keyboard(), $s);
            return;
        }
        global $wpdb;
        $jobId = wp_generate_uuid4();
        $wpdb->insert(self::jobs_table(), [
            'id' => $jobId,
            'chat_id' => (string)$chatId,
            'user_id' => (string)$userId,
            'kind' => 'deploy_zip',
            'status' => 'queued',
            'telegram_file_id' => sanitize_text_field((string)($doc['file_id'] ?? '')),
            'telegram_file_name' => sanitize_file_name((string)($doc['file_name'] ?? 'project.zip')),
            'source_message_id' => (int)($message['message_id'] ?? 0),
            'progress_message_id' => 0,
            'commit_sha' => '',
            'run_id' => 0,
            'run_url' => '',
            'attempts' => 0,
            'last_error' => '',
            'created_at' => BlueVPN_Utils::now_mysql(),
            'updated_at' => BlueVPN_Utils::now_mysql(),
        ]);
        self::send_message($chatId, "✅ ZIP دریافت شد و در صف نصب قرار گرفت.\nBackend Railway برای این عملیات دیگر استفاده نمی‌شود.", self::keyboard(), $s);
        self::schedule_process($jobId);
    }

    private static function queue_rebuild($chatId, $userId, array $s): void {
        if (self::chat_has_active_job((string)$chatId)) {
            self::send_message($chatId, '⏳ یک عملیات از قبل در حال اجراست.', self::keyboard(), $s);
            return;
        }
        global $wpdb;
        $jobId = wp_generate_uuid4();
        $wpdb->insert(self::jobs_table(), [
            'id' => $jobId, 'chat_id' => (string)$chatId, 'user_id' => (string)$userId,
            'kind' => 'rebuild', 'status' => 'queued', 'telegram_file_id' => '', 'telegram_file_name' => '',
            'source_message_id' => 0, 'progress_message_id' => 0, 'commit_sha' => '', 'run_id' => 0, 'run_url' => '',
            'attempts' => 0, 'last_error' => '', 'created_at' => BlueVPN_Utils::now_mysql(), 'updated_at' => BlueVPN_Utils::now_mysql(),
        ]);
        self::send_message($chatId, '🚀 Build فوری در صف قرار گرفت.', self::keyboard(), $s);
        self::schedule_process($jobId);
    }

    private static function schedule_process(string $jobId): void {
        wp_schedule_single_event(time() + 1, self::PROCESS_HOOK, [$jobId]);
        self::spawn_cron();
    }

    private static function spawn_cron(): void {
        $cronUrl = site_url('/wp-cron.php?doing_wp_cron=' . rawurlencode(sprintf('%.22F', microtime(true))));
        wp_remote_post($cronUrl, ['timeout' => 0.01, 'blocking' => false, 'sslverify' => apply_filters('https_local_ssl_verify', false)]);
    }

    private static function chat_has_active_job(string $chatId): bool {
        global $wpdb;
        $t = self::jobs_table();
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE chat_id=%s AND status IN ('queued','downloading','deploying','dispatching','waiting_build','building')", $chatId)) > 0;
    }

    public static function process_job(string $jobId): void {
        @set_time_limit(600);
        global $wpdb;
        $t = self::jobs_table();
        $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%s", $jobId), ARRAY_A);
        if (!$job || !in_array((string)$job['status'], ['queued','retry'], true)) return;
        $s = self::settings();
        if (!self::runtime_ready()) {
            self::fail_job($job, 'Runtime ربات کامل تنظیم نشده است.', $s); return;
        }
        try {
            if ($job['kind'] === 'deploy_zip') {
                self::update_job($jobId, ['status' => 'downloading']);
                self::send_message($job['chat_id'], '📥 در حال دریافت ZIP از Telegram...', [], $s);
                $zip = self::download_telegram_zip((string)$job['telegram_file_id'], (string)$job['telegram_file_name'], $s);
                self::update_job($jobId, ['status' => 'deploying']);
                self::send_message($job['chat_id'], '📤 در حال ثبت فایل‌ها روی GitHub...', [], $s);
                $deploy = self::deploy_zip_to_github($zip, $s);
                @unlink($zip);
                $commit = (string)$deploy['commit'];
                self::update_job($jobId, ['status' => 'dispatching', 'commit_sha' => $commit]);
                self::send_message($job['chat_id'], "✅ فایل‌ها روی GitHub ثبت شد.\nCommit: <code>" . esc_html(substr($commit, 0, 12)) . "</code>\nفایل‌های اعمال‌شده: <b>" . (int)$deploy['files'] . '</b>', [], $s);
            } else {
                $commit = self::branch_head_sha($s);
                self::update_job($jobId, ['status' => 'dispatching', 'commit_sha' => $commit]);
            }
            self::dispatch_workflow($s);
            self::update_job($jobId, ['status' => 'waiting_build']);
            self::send_message($job['chat_id'], "🛠 Build از GitHub Actions شروع شد.\nCommit: <code>" . esc_html(substr($commit, 0, 12)) . '</code>', self::keyboard(), $s);
            wp_schedule_single_event(time() + 20, self::POLL_HOOK);
            self::spawn_cron();
        } catch (Throwable $e) {
            self::fail_job($job, $e->getMessage(), $s);
        }
    }

    private static function download_telegram_zip(string $fileId, string $name, array $s): string {
        $file = self::api('getFile', ['file_id' => $fileId], $s);
        if (is_wp_error($file)) throw new RuntimeException($file->get_error_message());
        $path = (string)($file['file_path'] ?? '');
        if ($path === '') throw new RuntimeException('Telegram file_path را برنگرداند.');
        $token = self::bot_token($s);
        $url = 'https://api.telegram.org/file/bot' . rawurlencode($token) . '/' . str_replace('%2F', '/', rawurlencode($path));
        if (!function_exists('download_url')) require_once ABSPATH . 'wp-admin/includes/file.php';
        $tmp = download_url($url, 300);
        if (is_wp_error($tmp)) throw new RuntimeException($tmp->get_error_message());
        $size = @filesize($tmp) ?: 0;
        if ($size > (int)$s['max_zip_mb'] * 1024 * 1024) { @unlink($tmp); throw new RuntimeException('ZIP از محدودیت حجم بیشتر است.'); }
        if (!class_exists('ZipArchive')) { @unlink($tmp); throw new RuntimeException('PHP ZipArchive روی سرور فعال نیست.'); }
        return $tmp;
    }

    private static function deploy_zip_to_github(string $zipPath, array $s): array {
        $root = self::extract_zip_safely($zipPath, $s);
        try {
            $entries = [];
            $deletions = [];
            $deleteFile = $root . '/.bluevpn-delete';
            if (is_file($deleteFile)) {
                foreach (preg_split('/\r?\n/', (string)file_get_contents($deleteFile)) ?: [] as $line) {
                    $line = trim(str_replace('\\', '/', $line));
                    if ($line === '' || str_starts_with($line, '#')) continue;
                    if (self::safe_repo_path($line) && !self::protected_path($line)) $deletions[] = $line;
                }
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!$file->isFile()) continue;
                $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                if ($rel === '.bluevpn-delete' || str_starts_with($rel, '__MACOSX/') || str_starts_with($rel, '.git/')) continue;
                if (!self::safe_repo_path($rel) || self::protected_path($rel)) continue;
                $entries[] = ['path' => $rel, 'file' => $file->getPathname()];
            }
            if (!$entries && !$deletions) throw new RuntimeException('ZIP فایل قابل اعمال ندارد.');
            $head = self::gh('GET', self::repo_path($s) . '/git/ref/heads/' . rawurlencode((string)$s['git_branch']), null, $s);
            $parent = (string)($head['object']['sha'] ?? '');
            if ($parent === '') throw new RuntimeException('SHA شاخه GitHub دریافت نشد.');
            $commit = self::gh('GET', self::repo_path($s) . '/git/commits/' . rawurlencode($parent), null, $s);
            $baseTree = (string)($commit['tree']['sha'] ?? '');
            if ($baseTree === '') throw new RuntimeException('Tree پایه GitHub دریافت نشد.');

            $treeSha = $baseTree;
            $batch = [];
            $flush = static function () use (&$batch, &$treeSha, $s): void {
                if (!$batch) return;
                $created = self::gh('POST', self::repo_path($s) . '/git/trees', ['base_tree' => $treeSha, 'tree' => $batch], $s);
                $treeSha = (string)($created['sha'] ?? '');
                if ($treeSha === '') throw new RuntimeException('GitHub Tree ساخته نشد.');
                $batch = [];
            };
            foreach ($entries as $entry) {
                $bytes = (string)file_get_contents($entry['file']);
                $item = ['path' => $entry['path'], 'mode' => '100644', 'type' => 'blob'];
                if (strlen($bytes) <= 300000 && !str_contains($bytes, "\0") && preg_match('//u', $bytes)) {
                    $item['content'] = $bytes;
                } else {
                    $blob = self::gh('POST', self::repo_path($s) . '/git/blobs', ['content' => base64_encode($bytes), 'encoding' => 'base64'], $s);
                    $sha = (string)($blob['sha'] ?? '');
                    if ($sha === '') throw new RuntimeException('آپلود Blob برای ' . $entry['path'] . ' ناموفق بود.');
                    $item['sha'] = $sha;
                }
                $batch[] = $item;
                if (count($batch) >= 70) $flush();
            }
            foreach (array_values(array_unique($deletions)) as $path) {
                $batch[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => null];
                if (count($batch) >= 70) $flush();
            }
            $flush();
            $newCommit = self::gh('POST', self::repo_path($s) . '/git/commits', [
                'message' => 'BlueVPN bot update ' . gmdate('Y-m-d H:i:s') . ' UTC',
                'tree' => $treeSha,
                'parents' => [$parent],
            ], $s);
            $newSha = (string)($newCommit['sha'] ?? '');
            if ($newSha === '') throw new RuntimeException('Commit GitHub ساخته نشد.');
            self::gh('PATCH', self::repo_path($s) . '/git/refs/heads/' . rawurlencode((string)$s['git_branch']), ['sha' => $newSha, 'force' => false], $s);
            $verified = self::branch_head_sha($s);
            if (!hash_equals($newSha, $verified)) throw new RuntimeException('SHA شاخه پس از Push تأیید نشد.');
            return ['commit' => $newSha, 'files' => count($entries), 'deleted' => count($deletions)];
        } finally {
            $cleanupRoot = str_starts_with(basename($root), 'bluevpn-bot-') ? $root : dirname($root);
            if (str_starts_with(basename($cleanupRoot), 'bluevpn-bot-')) self::rrmdir($cleanupRoot);
        }
    }

    private static function extract_zip_safely(string $zipPath, array $s): string {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) throw new RuntimeException('ZIP معتبر نیست.');
        $count = $zip->numFiles;
        if ($count > (int)$s['max_files']) { $zip->close(); throw new RuntimeException('تعداد فایل‌های ZIP بیش از حد مجاز است.'); }
        $total = 0;
        for ($i = 0; $i < $count; $i++) {
            $st = $zip->statIndex($i);
            $name = str_replace('\\', '/', (string)($st['name'] ?? ''));
            if ($name === '' || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name) || preg_match('#^[A-Za-z]:/#', $name)) {
                $zip->close(); throw new RuntimeException('مسیر ناامن داخل ZIP: ' . $name);
            }
            $total += max(0, (int)($st['size'] ?? 0));
            if ($total > (int)$s['max_extracted_mb'] * 1024 * 1024) { $zip->close(); throw new RuntimeException('حجم Extract شده بیش از حد مجاز است.'); }
        }
        $base = trailingslashit(get_temp_dir()) . 'bluevpn-bot-' . wp_generate_password(10, false, false);
        wp_mkdir_p($base);
        if (!$zip->extractTo($base)) { $zip->close(); self::rrmdir($base); throw new RuntimeException('Extract فایل ZIP ناموفق بود.'); }
        $zip->close();
        $items = array_values(array_filter(scandir($base) ?: [], static fn($x) => !in_array($x, ['.','..','__MACOSX'], true)));
        $root = $base;
        if (count($items) === 1 && is_dir($base . '/' . $items[0])) $root = $base . '/' . $items[0];
        return $root;
    }

    private static function safe_repo_path(string $path): bool {
        $path = str_replace('\\', '/', trim($path));
        return $path !== '' && !str_starts_with($path, '/') && !preg_match('#(^|/)\.\.(/|$)#', $path) && !preg_match('#^[A-Za-z]:/#', $path);
    }

    private static function protected_path(string $path): bool {
        $base = basename($path);
        if ($base === '.env' || $base === 'BlueVPN-release.jks') return true;
        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        return in_array('.' . $ext, ['.jks','.keystore','.p12','.pfx'], true);
    }

    private static function rrmdir(string $dir): void {
        if (!is_dir($dir)) return;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { if ($f->isDir()) @rmdir($f->getPathname()); else @unlink($f->getPathname()); }
        @rmdir($dir);
    }

    private static function repo_path(array $s): string {
        [$owner, $repo] = array_pad(explode('/', (string)$s['github_repository'], 2), 2, '');
        if ($owner === '' || $repo === '') throw new RuntimeException('GITHUB_REPOSITORY باید OWNER/REPOSITORY باشد.');
        return '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo);
    }

    private static function gh(string $method, string $path, ?array $body, array $s) {
        $token = self::github_token($s);
        if ($token === '') throw new RuntimeException('GITHUB_TOKEN تنظیم نشده است.');
        $args = [
            'method' => $method,
            'timeout' => 45,
            'redirection' => 3,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'Authorization' => 'Bearer ' . $token,
                'X-GitHub-Api-Version' => self::GITHUB_API_VERSION,
                'User-Agent' => 'BlueVPN-WordPress-Bot/' . BLUEVPN_MANAGER_VERSION,
            ],
        ];
        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $res = wp_remote_request(self::GITHUB_API . $path, $args);
        if (is_wp_error($res)) throw new RuntimeException($res->get_error_message());
        $code = (int)wp_remote_retrieve_response_code($res);
        $raw = wp_remote_retrieve_body($res);
        $json = $raw !== '' ? json_decode($raw, true) : [];
        if ($code < 200 || $code >= 300) {
            $message = is_array($json) ? (string)($json['message'] ?? '') : '';
            throw new RuntimeException('GitHub API HTTP ' . $code . ($message !== '' ? ': ' . $message : ''));
        }
        return is_array($json) ? $json : [];
    }

    private static function branch_head_sha(array $s): string {
        $ref = self::gh('GET', self::repo_path($s) . '/git/ref/heads/' . rawurlencode((string)$s['git_branch']), null, $s);
        $sha = (string)($ref['object']['sha'] ?? '');
        if ($sha === '') throw new RuntimeException('SHA شاخه GitHub پیدا نشد.');
        return $sha;
    }

    private static function dispatch_workflow(array $s): void {
        self::gh('POST', self::repo_path($s) . '/actions/workflows/' . rawurlencode((string)$s['github_workflow']) . '/dispatches', ['ref' => (string)$s['git_branch']], $s);
    }

    public static function poll_builds(): void {
        global $wpdb;
        $t = self::jobs_table();
        $jobs = $wpdb->get_results("SELECT * FROM {$t} WHERE status IN ('waiting_build','building') ORDER BY created_at ASC LIMIT 10", ARRAY_A);
        if (!$jobs) return;
        $s = self::settings();
        foreach ($jobs as $job) {
            try {
                $runs = self::gh('GET', self::repo_path($s) . '/actions/workflows/' . rawurlencode((string)$s['github_workflow']) . '/runs?branch=' . rawurlencode((string)$s['git_branch']) . '&per_page=30', null, $s);
                $match = null;
                foreach ((array)($runs['workflow_runs'] ?? []) as $run) {
                    if (!is_array($run)) continue;
                    if ((string)($run['head_sha'] ?? '') === (string)$job['commit_sha']) { $match = $run; break; }
                }
                if (!$match) continue;
                $status = (string)($match['status'] ?? '');
                $runId = (int)($match['id'] ?? 0);
                $runUrl = esc_url_raw((string)($match['html_url'] ?? ''));
                if ($status !== 'completed') {
                    self::update_job((string)$job['id'], ['status' => 'building', 'run_id' => $runId, 'run_url' => $runUrl]);
                    continue;
                }
                $conclusion = (string)($match['conclusion'] ?? 'unknown');
                if ($conclusion === 'success') {
                    self::update_job((string)$job['id'], ['status' => 'success', 'run_id' => $runId, 'run_url' => $runUrl, 'finished_at' => BlueVPN_Utils::now_mysql()]);
                    if (class_exists('BlueVPN_App_Release_Manager')) BlueVPN_App_Release_Manager::sync_now(true, 'telegram_bot_build');
                    self::send_message($job['chat_id'], "🎉 <b>Build موفق شد</b>\n" . ($runUrl ? '<a href="' . esc_url($runUrl) . '">مشاهده GitHub Actions</a>\n' : '') . "نسخه جدید آماده دریافت است.", self::keyboard(), $s);
                    self::send_latest($job['chat_id'], $s);
                } else {
                    self::update_job((string)$job['id'], ['status' => 'failed', 'run_id' => $runId, 'run_url' => $runUrl, 'last_error' => 'Build: ' . $conclusion, 'finished_at' => BlueVPN_Utils::now_mysql()]);
                    self::send_message($job['chat_id'], "❌ Build ناموفق بود.\nنتیجه: <code>" . esc_html($conclusion) . '</code>' . ($runUrl ? "\n<a href=\"" . esc_url($runUrl) . '\">مشاهده Log</a>' : ''), self::keyboard(), $s);
                }
            } catch (Throwable $e) {
                self::update_job((string)$job['id'], ['attempts' => (int)$job['attempts'] + 1, 'last_error' => self::redact($e->getMessage(), $s)]);
            }
        }
    }

    private static function update_job(string $id, array $data): void {
        global $wpdb;
        $data['updated_at'] = BlueVPN_Utils::now_mysql();
        $wpdb->update(self::jobs_table(), $data, ['id' => $id]);
    }

    private static function fail_job(array $job, string $error, array $s): void {
        $error = self::redact($error, $s);
        self::update_job((string)$job['id'], ['status' => 'failed', 'last_error' => mb_substr($error, 0, 4000), 'finished_at' => BlueVPN_Utils::now_mysql()]);
        self::send_message($job['chat_id'], "❌ عملیات ناموفق بود.\n<code>" . esc_html(mb_substr($error, -3000)) . '</code>', self::keyboard(), $s);
    }

    private static function redact(string $text, array $s): string {
        foreach ([self::bot_token($s), self::github_token($s)] as $secret) if ($secret !== '') $text = str_replace($secret, '***', $text);
        return $text;
    }

    private static function send_status($chatId, array $s): void {
        global $wpdb;
        $t = self::jobs_table();
        $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE chat_id=%s ORDER BY created_at DESC LIMIT 1", (string)$chatId), ARRAY_A);
        $lines = ["📊 <b>وضعیت BlueVPN Bot</b>", 'Runtime: ' . (self::runtime_ready() ? '✅ آماده' : '❌ ناقص'), 'Webhook: <code>' . esc_html((string)$s['webhook_status']) . '</code>', 'Repository: <code>' . esc_html((string)$s['github_repository']) . '</code>'];
        if ($job) {
            $lines[] = '';
            $lines[] = 'آخرین عملیات: <code>' . esc_html((string)$job['status']) . '</code>';
            if (!empty($job['commit_sha'])) $lines[] = 'Commit: <code>' . esc_html(substr((string)$job['commit_sha'], 0, 12)) . '</code>';
            if (!empty($job['run_url'])) $lines[] = '<a href="' . esc_url((string)$job['run_url']) . '">GitHub Actions</a>';
            if (!empty($job['last_error'])) $lines[] = 'خطا: <code>' . esc_html(mb_substr((string)$job['last_error'], 0, 800)) . '</code>';
        }
        self::send_message($chatId, implode("\n", $lines), self::keyboard(), $s);
    }

    private static function unlock_chat($chatId, array $s): void {
        global $wpdb;
        $t = self::jobs_table();
        $jobs = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE chat_id=%s AND status IN ('queued','downloading','deploying','dispatching','waiting_build','building')", (string)$chatId), ARRAY_A);
        foreach ($jobs as $job) {
            if ((int)$job['run_id'] > 0) {
                try { self::gh('POST', self::repo_path($s) . '/actions/runs/' . (int)$job['run_id'] . '/cancel', [], $s); } catch (Throwable $e) {}
            }
            self::update_job((string)$job['id'], ['status' => 'cancelled', 'finished_at' => BlueVPN_Utils::now_mysql()]);
        }
        self::send_message($chatId, '🔓 قفل عملیات آزاد شد و Build فعال در صورت امکان لغو شد.', self::keyboard(), $s);
    }

    private static function latest_release(array $s): ?array {
        $releases = self::gh('GET', self::repo_path($s) . '/releases?per_page=30', null, $s);
        foreach ((array)$releases as $r) {
            if (!is_array($r) || !empty($r['draft']) || !empty($r['prerelease'])) continue;
            if (preg_match('/^v\d+\.\d+\.\d+$/', (string)($r['tag_name'] ?? ''))) return $r;
        }
        return null;
    }

    private static function send_latest($chatId, array $s): void {
        try {
            $r = self::latest_release($s);
            if (!$r) { self::send_message($chatId, 'APK Release پیدا نشد.', self::keyboard(), $s); return; }
            $buttons = [];
            foreach ((array)($r['assets'] ?? []) as $asset) {
                $name = (string)($asset['name'] ?? '');
                $url = esc_url_raw((string)($asset['browser_download_url'] ?? ''));
                if ($url !== '' && str_ends_with(strtolower($name), '.apk')) $buttons[] = [['text' => '⬇️ ' . mb_substr($name, 0, 48), 'url' => $url]];
            }
            if (!$buttons && !empty($r['html_url'])) $buttons[] = [['text' => '⬇️ باز کردن Release', 'url' => esc_url_raw((string)$r['html_url'])]];
            self::send_message($chatId, "⬇️ <b>آخرین APK BlueVPN</b>\nنسخه: <code>" . esc_html((string)($r['tag_name'] ?? '')) . '</code>', $buttons ? ['inline_keyboard' => $buttons] : self::keyboard(), $s);
        } catch (Throwable $e) {
            self::send_message($chatId, '❌ دریافت آخرین APK ناموفق بود: <code>' . esc_html(self::redact($e->getMessage(), $s)) . '</code>', self::keyboard(), $s);
        }
    }

    private static function send_signing_status($chatId, array $s): void {
        try {
            $data = self::gh('GET', self::repo_path($s) . '/actions/secrets?per_page=100', null, $s);
            $names = [];
            foreach ((array)($data['secrets'] ?? []) as $x) if (is_array($x)) $names[] = (string)($x['name'] ?? '');
            $required = ['ANDROID_KEYSTORE_BASE64','ANDROID_KEYSTORE_PASSWORD','ANDROID_KEY_ALIAS','ANDROID_KEY_PASSWORD'];
            $lines = ["🔐 <b>وضعیت امضای Android</b>"];
            foreach ($required as $name) $lines[] = (in_array($name, $names, true) ? '✅ ' : '❌ ') . '<code>' . $name . '</code>';
            self::send_message($chatId, implode("\n", $lines), self::keyboard(), $s);
        } catch (Throwable $e) {
            self::send_message($chatId, '❌ بررسی Secretهای امضا ناموفق بود: <code>' . esc_html(self::redact($e->getMessage(), $s)) . '</code>', self::keyboard(), $s);
        }
    }

    private static function manual_guardcore_rows(int $limit = 20): array {
        global $wpdb;
        $orders = BlueVPN_DB::table('orders');
        $plans = BlueVPN_DB::table('plans');
        $customers = BlueVPN_DB::table('customers');
        $rows = $wpdb->get_results("SELECT o.id,o.order_code,o.gateway_json,o.customer_id,o.plan_id,p.title AS plan_title,p.duration_days,p.data_limit_gb,c.email,c.phone,c.guardcore_username FROM {$orders} o LEFT JOIN {$plans} p ON p.id=o.plan_id LEFT JOIN {$customers} c ON c.id=o.customer_id WHERE o.status='activated' ORDER BY o.created_at DESC LIMIT 200", ARRAY_A);
        $out = [];
        foreach ($rows as $row) {
            $meta = BlueVPN_Utils::json_decode_array((string)$row['gateway_json'], []);
            $req = is_array($meta['guardcore_manual'] ?? null) ? $meta['guardcore_manual'] : [];
            $state = (string)($req['state'] ?? '');
            if (!in_array($state, ['awaiting_decision','awaiting_link'], true)) continue;
            $row['_request'] = $req;
            $out[] = $row;
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    private static function send_guardcore_queue($chatId, array $s): void {
        $rows = self::manual_guardcore_rows(20);
        if (!$rows) { self::send_message($chatId, '✅ درخواست منتظر GuardCore وجود ندارد.', self::keyboard(), $s); return; }
        $lines = ['🟡 <b>صف GuardCore — ' . count($rows) . ' درخواست</b>'];
        $buttons = [];
        foreach (array_slice($rows, 0, 10) as $row) {
            $r = $row['_request'];
            $username = (string)($r['username'] ?? $row['guardcore_username'] ?? '');
            $plan = (string)($r['plan_title'] ?? $row['plan_title'] ?? '');
            $lines[] = '• <code>' . esc_html($username) . '</code> — ' . esc_html($plan) . ' — ' . (((string)($r['state'] ?? '')) === 'awaiting_decision' ? 'منتظر تصمیم' : 'منتظر لینک');
            $buttons[] = [['text' => '✅ ' . mb_substr($username, 0, 22), 'callback_data' => 'gc:y:' . $row['id']], ['text' => '⏭ رد', 'callback_data' => 'gc:n:' . $row['id']]];
        }
        self::send_message($chatId, implode("\n", $lines), ['inline_keyboard' => $buttons], $s);
    }

    private static function handle_callback(array $q, array $s): void {
        $userId = $q['from']['id'] ?? null;
        $chatId = $q['message']['chat']['id'] ?? null;
        $qid = (string)($q['id'] ?? '');
        if (!self::is_admin($userId, $s)) { self::answer_callback($qid, 'دسترسی ندارید', true, $s); return; }
        $data = (string)($q['data'] ?? '');
        if (!preg_match('/^gc:([yn]):([0-9a-fA-F-]{36})$/', $data, $m)) { self::answer_callback($qid, 'درخواست نامعتبر است', true, $s); return; }
        global $wpdb;
        $orders = BlueVPN_DB::table('orders');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$orders} WHERE id=%s", $m[2]), ARRAY_A);
        if (!$row) { self::answer_callback($qid, 'سفارش پیدا نشد', true, $s); return; }
        $meta = BlueVPN_Utils::json_decode_array((string)$row['gateway_json'], []);
        $req = is_array($meta['guardcore_manual'] ?? null) ? $meta['guardcore_manual'] : [];
        if (!$req) { self::answer_callback($qid, 'درخواست GuardCore پیدا نشد', true, $s); return; }
        $now = BlueVPN_Utils::iso_now();
        if ($m[1] === 'n') {
            $req['state'] = 'skipped'; $req['skipped_at'] = $now; $req['admin_id'] = (string)$userId;
            $meta['guardcore_manual'] = $req;
            $wpdb->update($orders, ['gateway_json' => BlueVPN_Utils::json_encode($meta)], ['id' => $m[2]]);
            self::answer_callback($qid, 'رد شد', false, $s);
            if ($chatId !== null) self::send_message($chatId, '⏭ GuardCore برای این سفارش رد شد.', self::keyboard(), $s);
            return;
        }
        $req['state'] = 'awaiting_link'; $req['decision_at'] = $now; $req['admin_id'] = (string)$userId;
        $meta['guardcore_manual'] = $req;
        $wpdb->update($orders, ['gateway_json' => BlueVPN_Utils::json_encode($meta)], ['id' => $m[2]]);
        if ($chatId !== null) update_option('bluevpn_bot_gc_pending_' . md5((string)$chatId), $m[2], false);
        self::answer_callback($qid, 'ثبت شد', false, $s);
        if ($chatId !== null) {
            $duration = ((int)($req['duration_days'] ?? 0) === 0) ? 'نامحدود' : (int)$req['duration_days'] . ' روز';
            $volume = ((int)($req['data_limit_gb'] ?? 0) === 0) ? 'نامحدود' : (int)$req['data_limit_gb'] . ' گیگ';
            $text = "🛠 <b>کاربر را در پنل GuardCore بساز</b>\n\nنام کاربری: <code>" . esc_html((string)($req['username'] ?? '')) . "</code>\nزمان: <b>{$duration}</b>\nحجم: <b>{$volume}</b>\n\nبعد از ساخت، فقط لینک Subscription را در پیام بعدی بفرست.";
            $markup = [];
            $panel = esc_url_raw((string)($req['panel_url'] ?? ''));
            if ($panel !== '') $markup = ['inline_keyboard' => [[['text' => '🌐 باز کردن پنل', 'url' => $panel]]]];
            self::send_message($chatId, $text, $markup ?: self::keyboard(), $s);
        }
    }

    private static function capture_guardcore_link(string $chatId, string $userId, string $text, array $s): bool {
        if (!preg_match('#^https?://#i', $text)) return false;
        $key = 'bluevpn_bot_gc_pending_' . md5($chatId);
        $orderId = (string)get_option($key, '');
        if ($orderId === '') return false;
        global $wpdb;
        $orders = BlueVPN_DB::table('orders');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$orders} WHERE id=%s", $orderId), ARRAY_A);
        if (!$row) { delete_option($key); return false; }
        $result = BlueVPN_Providers::attach_guardcore((int)$row['customer_id'], esc_url_raw($text));
        if (empty($result['ok'])) {
            self::send_message($chatId, '❌ ثبت لینک ناموفق بود: <code>' . esc_html((string)($result['message'] ?? 'خطا')) . '</code>', self::keyboard(), $s);
            return true;
        }
        $meta = BlueVPN_Utils::json_decode_array((string)$row['gateway_json'], []);
        $req = is_array($meta['guardcore_manual'] ?? null) ? $meta['guardcore_manual'] : [];
        $req['state'] = 'attached'; $req['attached_at'] = BlueVPN_Utils::iso_now(); $req['admin_id'] = $userId; $req['subscription_url'] = esc_url_raw($text);
        $meta['guardcore_manual'] = $req;
        $wpdb->update($orders, ['gateway_json' => BlueVPN_Utils::json_encode($meta)], ['id' => $orderId]);
        delete_option($key);
        self::send_message($chatId, '✅ <b>ساب GuardCore ثبت شد</b> و به اشتراک تجمیعی کاربر اضافه شد.', self::keyboard(), $s);
        return true;
    }

    public static function webhook_url(?array $s = null): string {
        $s = $s ?: self::settings();
        return rest_url('bluevpn-bot/v1/webhook/' . rawurlencode((string)$s['webhook_secret']));
    }

    public static function set_webhook() {
        $s = self::settings();
        if (self::bot_token($s) === '') return new WP_Error('bluevpn_bot_token', 'BOT_TOKEN تنظیم نشده است.');
        $secret = self::webhook_secret_token($s);
        if ($secret === '') {
            $secret = self::random_secret(32);
            global $wpdb;
            $wpdb->update(self::settings_table(), ['webhook_secret_token_enc' => BlueVPN_Utils::encrypt_secret($secret)], ['id' => 1]);
            $s = self::settings();
        }
        $result = self::api('setWebhook', [
            'url' => self::webhook_url($s),
            'secret_token' => $secret,
            'allowed_updates' => wp_json_encode(['message','callback_query']),
            'drop_pending_updates' => 'false',
        ], $s);
        global $wpdb;
        if (is_wp_error($result)) {
            $wpdb->update(self::settings_table(), ['webhook_status' => 'error', 'webhook_last_error' => $result->get_error_message(), 'updated_at' => BlueVPN_Utils::now_mysql()], ['id' => 1]);
            return $result;
        }
        $wpdb->update(self::settings_table(), ['webhook_status' => 'active', 'webhook_last_error' => '', 'updated_at' => BlueVPN_Utils::now_mysql()], ['id' => 1]);
        return $result;
    }

    public static function delete_webhook() {
        $s = self::settings();
        $result = self::api('deleteWebhook', ['drop_pending_updates' => 'false'], $s);
        global $wpdb;
        $wpdb->update(self::settings_table(), ['webhook_status' => is_wp_error($result) ? 'error' : 'disabled', 'webhook_last_error' => is_wp_error($result) ? $result->get_error_message() : '', 'updated_at' => BlueVPN_Utils::now_mysql()], ['id' => 1]);
        return $result;
    }

    public static function register_menu(): void {
        add_submenu_page('bluevpn-manager', 'ربات تلگرام', 'ربات تلگرام', 'manage_options', 'bluevpn-telegram-bot', [self::class, 'admin_page']);
    }

    private static function admin_guard(): void { if (!current_user_can('manage_options')) wp_die('دسترسی ندارید.'); }
    private static function admin_redirect(string $msg, bool $error = false): void {
        wp_safe_redirect(add_query_arg(['page' => 'bluevpn-telegram-bot', $error ? 'bot_error' : 'bot_msg' => $msg], admin_url('admin.php'))); exit;
    }

    public static function admin_save(): void {
        self::admin_guard(); check_admin_referer('bluevpn_bot_save');
        self::ensure_defaults(); global $wpdb; $t = self::settings_table(); $old = self::settings();
        $bot = trim((string)wp_unslash($_POST['bot_token'] ?? ''));
        $gh = trim((string)wp_unslash($_POST['github_token'] ?? ''));
        $repo = trim((string)wp_unslash($_POST['github_repository'] ?? ''));
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) self::admin_redirect('Repository باید OWNER/REPOSITORY باشد.', true);
        $wpdb->update($t, [
            'enabled' => isset($_POST['enabled']) ? 1 : 0,
            'bot_token_enc' => $bot !== '' ? BlueVPN_Utils::encrypt_secret($bot) : (string)$old['bot_token_enc'],
            'admin_ids' => self::sanitize_admin_ids((string)wp_unslash($_POST['admin_ids'] ?? '')),
            'github_token_enc' => $gh !== '' ? BlueVPN_Utils::encrypt_secret($gh) : (string)$old['github_token_enc'],
            'github_repository' => $repo,
            'git_branch' => self::sanitize_branch((string)wp_unslash($_POST['git_branch'] ?? 'main')),
            'github_workflow' => sanitize_file_name((string)wp_unslash($_POST['github_workflow'] ?? 'build-apk.yml')),
            'repository_dispatch_event' => sanitize_key((string)wp_unslash($_POST['repository_dispatch_event'] ?? 'bluevpn_build')),
            'max_zip_mb' => self::bounded_int($_POST['max_zip_mb'] ?? 50, 1, 200, 50),
            'max_extracted_mb' => self::bounded_int($_POST['max_extracted_mb'] ?? 900, 50, 4096, 900),
            'max_files' => self::bounded_int($_POST['max_files'] ?? 25000, 100, 100000, 25000),
            'updated_at' => BlueVPN_Utils::now_mysql(),
        ], ['id' => 1]);
        self::admin_redirect('تنظیمات ربات ذخیره شد.');
    }

    public static function admin_set_webhook(): void { self::admin_guard(); check_admin_referer('bluevpn_bot_set_webhook'); $r = self::set_webhook(); self::admin_redirect(is_wp_error($r) ? $r->get_error_message() : 'Webhook تلگرام روی WordPress فعال شد.', is_wp_error($r)); }
    public static function admin_delete_webhook(): void { self::admin_guard(); check_admin_referer('bluevpn_bot_delete_webhook'); $r = self::delete_webhook(); self::admin_redirect(is_wp_error($r) ? $r->get_error_message() : 'Webhook غیرفعال شد.', is_wp_error($r)); }
    public static function admin_test(): void {
        self::admin_guard(); check_admin_referer('bluevpn_bot_test'); $s = self::settings();
        $me = self::api('getMe', [], $s);
        if (is_wp_error($me)) self::admin_redirect($me->get_error_message(), true);
        try { self::branch_head_sha($s); } catch (Throwable $e) { self::admin_redirect('Telegram سالم است اما GitHub خطا داد: ' . self::redact($e->getMessage(), $s), true); }
        self::admin_redirect('Telegram و GitHub هر دو سالم هستند. @' . sanitize_text_field((string)($me['username'] ?? 'bot')));
    }

    public static function admin_page(): void {
        self::admin_guard(); $s = self::settings();
        echo '<div class="wrap" dir="rtl"><h1>ربات تلگرام BlueVPN</h1><style>.bvb-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:14px;max-width:1200px}.bvb-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:16px}.bvb-ok{color:#087c2c;font-weight:700}.bvb-bad{color:#b32d2e;font-weight:700}.bvb-code{direction:ltr;text-align:left;background:#f6f7f7;padding:9px;border-radius:7px;word-break:break-all}</style>';
        if (isset($_GET['bot_msg'])) echo '<div class="notice notice-success"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['bot_msg']))) . '</p></div>';
        if (isset($_GET['bot_error'])) echo '<div class="notice notice-error"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['bot_error']))) . '</p></div>';
        echo '<div class="bvb-grid"><div class="bvb-card"><h3>Runtime</h3><p class="' . (self::runtime_ready() ? 'bvb-ok' : 'bvb-bad') . '">' . (self::runtime_ready() ? '✅ آماده روی WordPress' : '❌ تنظیمات ناقص') . '</p></div>';
        echo '<div class="bvb-card"><h3>Webhook</h3><p><strong>' . esc_html((string)$s['webhook_status']) . '</strong></p><div class="bvb-code">' . esc_html(self::webhook_url($s)) . '</div></div>';
        echo '<div class="bvb-card"><h3>Railway</h3><p class="bvb-ok">برای اجرای ربات لازم نیست.</p><small>Telegram مستقیماً به REST API وردپرس ارسال می‌کند.</small></div></div>';
        echo '<div class="bvb-card" style="max-width:1000px;margin-top:16px"><h2>تنظیمات</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'; wp_nonce_field('bluevpn_bot_save');
        echo '<input type="hidden" name="action" value="bluevpn_bot_save"><table class="form-table">';
        echo '<tr><th>فعال</th><td><label><input type="checkbox" name="enabled" value="1" ' . checked((int)$s['enabled'], 1, false) . '> ربات WordPress فعال باشد</label></td></tr>';
        echo '<tr><th>BOT_TOKEN</th><td><input class="regular-text" dir="ltr" type="password" name="bot_token" value="" placeholder="خالی = حفظ مقدار فعلی"><p class="description">ذخیره‌شده به‌صورت رمز‌شده.</p></td></tr>';
        echo '<tr><th>Admin IDs</th><td><input class="regular-text" dir="ltr" name="admin_ids" value="' . esc_attr((string)$s['admin_ids']) . '"></td></tr>';
        echo '<tr><th>GITHUB_TOKEN</th><td><input class="regular-text" dir="ltr" type="password" name="github_token" value="" placeholder="خالی = حفظ مقدار فعلی"></td></tr>';
        echo '<tr><th>Repository</th><td><input class="regular-text" dir="ltr" name="github_repository" value="' . esc_attr((string)$s['github_repository']) . '"></td></tr>';
        echo '<tr><th>Branch</th><td><input class="regular-text" dir="ltr" name="git_branch" value="' . esc_attr((string)$s['git_branch']) . '"></td></tr>';
        echo '<tr><th>Workflow</th><td><input class="regular-text" dir="ltr" name="github_workflow" value="' . esc_attr((string)$s['github_workflow']) . '"></td></tr>';
        echo '<tr><th>Repository Dispatch Event</th><td><input class="regular-text" dir="ltr" name="repository_dispatch_event" value="' . esc_attr((string)$s['repository_dispatch_event']) . '"></td></tr>';
        echo '<tr><th>ZIP max MB</th><td><input type="number" name="max_zip_mb" value="' . (int)$s['max_zip_mb'] . '"></td></tr>';
        echo '<tr><th>Extract max MB</th><td><input type="number" name="max_extracted_mb" value="' . (int)$s['max_extracted_mb'] . '"></td></tr>';
        echo '<tr><th>Max files</th><td><input type="number" name="max_files" value="' . (int)$s['max_files'] . '"></td></tr></table>'; submit_button('ذخیره تنظیمات'); echo '</form>';
        echo '<hr><div style="display:flex;gap:8px;flex-wrap:wrap">';
        foreach ([['bluevpn_bot_test','bluevpn_bot_test','تست Telegram + GitHub','secondary'],['bluevpn_bot_set_webhook','bluevpn_bot_set_webhook','فعال‌سازی Webhook','primary'],['bluevpn_bot_delete_webhook','bluevpn_bot_delete_webhook','غیرفعال‌سازی Webhook','secondary']] as $a) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'; wp_nonce_field($a[1]); echo '<input type="hidden" name="action" value="' . esc_attr($a[0]) . '">'; submit_button($a[2], $a[3], 'submit', false); echo '</form>';
        }
        echo '</div>';
        if (!empty($s['webhook_last_error'])) echo '<p class="bvb-bad">آخرین خطا: ' . esc_html((string)$s['webhook_last_error']) . '</p>';
        echo '</div>';
        global $wpdb; $jobs = $wpdb->get_results('SELECT * FROM ' . self::jobs_table() . ' ORDER BY created_at DESC LIMIT 30', ARRAY_A);
        echo '<h2>آخرین عملیات‌ها</h2><table class="widefat striped"><tr><th>نوع</th><th>وضعیت</th><th>Chat</th><th>Commit</th><th>Run</th><th>خطا</th><th>زمان</th></tr>';
        foreach ($jobs as $j) echo '<tr><td>' . esc_html((string)$j['kind']) . '</td><td>' . esc_html((string)$j['status']) . '</td><td>' . esc_html((string)$j['chat_id']) . '</td><td><code>' . esc_html(substr((string)$j['commit_sha'],0,12)) . '</code></td><td>' . (!empty($j['run_url']) ? '<a href="' . esc_url((string)$j['run_url']) . '" target="_blank" rel="noopener">GitHub</a>' : '') . '</td><td>' . esc_html(mb_substr((string)$j['last_error'],0,160)) . '</td><td>' . esc_html((string)$j['created_at']) . '</td></tr>';
        echo '</table></div>';
    }
}
