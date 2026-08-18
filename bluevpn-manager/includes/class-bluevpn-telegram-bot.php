<?php
if (!defined('ABSPATH')) exit;

/**
 * Native Telegram deploy/build bot for BlueVPN.
 *
 * Replaces the Railway polling runtime with a Telegram webhook hosted by the
 * same WordPress/MySQL backend. ZIP deployment first uses the same authenticated Git-over-HTTPS transport as
 * the former Railway bot, with GitHub Git Data API as a fallback. No Docker,
 * Python or long-running worker process is required.
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
    private const MANAGER_WORKFLOW = 'bluevpn-manager-release.yml';
    private const MANAGER_REPOSITORY_EVENT = 'bluevpn_manager_release';

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

    /**
     * Internal read-only accessor used by the GitHub updater.
     * The token is never exposed through REST/admin output; it only leaves the
     * process in an Authorization header sent to api.github.com.
     */
    public static function github_token_for_internal_requests(): string {
        return self::github_token();
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
            BlueVPN_Error_Monitor::legacy_error_log('BlueVPN Telegram webhook: ' . self::redact($e->getMessage(), $s));
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
        if (
            $text !== '' &&
            class_exists('BlueVPN_Support') &&
            BlueVPN_Support::telegram_reply_command($text, (int)$userId)
        ) {
            self::send_message($chatId, '✅ پاسخ پشتیبانی داخل گفتگوی کاربر ثبت شد.', [], $s);
            return;
        }
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
        } elseif (in_array($text, ['/manager', '🧩 بروزرسانی Manager'], true)) {
            self::queue_manager_update($chatId, $userId, $s);
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
            [['text' => '🧩 بروزرسانی Manager'], ['text' => '🔐 بررسی امضا']],
            [['text' => '🔓 آزادسازی عملیات']],
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

    public static function support_notify(string $text): void {
        $s = self::settings();
        if (empty($s['enabled'])) return;
        foreach (self::admin_ids($s) as $chatId) {
            self::send_message($chatId, $text, [], $s);
        }
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

    private static function queue_manager_update($chatId, $userId, array $s): void {
        if (self::chat_has_active_job((string)$chatId)) {
            self::send_message($chatId, '⏳ یک عملیات از قبل در حال اجراست.', self::keyboard(), $s);
            return;
        }
        global $wpdb;
        $jobId = wp_generate_uuid4();
        $wpdb->insert(self::jobs_table(), [
            'id' => $jobId, 'chat_id' => (string)$chatId, 'user_id' => (string)$userId,
            'kind' => 'manager_update', 'status' => 'queued', 'telegram_file_id' => '', 'telegram_file_name' => '',
            'source_message_id' => 0, 'progress_message_id' => 0, 'commit_sha' => '', 'run_id' => 0, 'run_url' => '',
            'attempts' => 0, 'last_error' => '', 'created_at' => BlueVPN_Utils::now_mysql(), 'updated_at' => BlueVPN_Utils::now_mysql(),
        ]);
        self::send_message($chatId, '🧩 انتشار و نصب BlueVPN Manager در صف قرار گرفت.', self::keyboard(), $s);
        self::schedule_process($jobId);
    }

    private static function schedule_process(string $jobId): void {
        wp_schedule_single_event(time() + 1, self::PROCESS_HOOK, [$jobId]);
        self::spawn_cron();
    }

    private static function spawn_cron(): void {
        BlueVPN_Utils::kick_wp_cron();
    }

    private static function chat_has_active_job(string $chatId): bool {
        global $wpdb;
        $t = self::jobs_table();
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE chat_id=%s AND status IN ('queued','downloading','deploying','dispatching','waiting_manager','building_manager','updating_manager','waiting_build','building')", $chatId)) > 0;
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

        // Claim the job atomically before any GitHub side effect. wp-cron can
        // execute the same scheduled hook concurrently; without this guard two
        // workers could dispatch the same commit twice.
        $claimedStatus = $job['kind'] === 'deploy_zip' ? 'downloading' : 'dispatching';
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE {$t} SET status=%s, updated_at=%s WHERE id=%s AND status IN ('queued','retry')",
            $claimedStatus, BlueVPN_Utils::now_mysql(), $jobId
        ));
        if ((int)$claimed !== 1) return;
        $job['status'] = $claimedStatus;

        try {
            if ($job['kind'] === 'deploy_zip') {
                self::send_message($job['chat_id'], '📥 در حال دریافت ZIP از Telegram...', [], $s);
                $zip = self::download_telegram_zip((string)$job['telegram_file_id'], (string)$job['telegram_file_name'], $s);
                self::update_job($jobId, ['status' => 'deploying']);
                self::send_message($job['chat_id'], '📤 در حال ثبت فایل‌ها روی GitHub...', [], $s);
                $deploy = self::deploy_zip_to_github($zip, $s);
                @unlink($zip);
                $commit = (string)$deploy['commit'];
                if ((string)($deploy['package_type'] ?? '') === 'manager_only') {
                    $job['kind'] = 'manager_update';
                    self::update_job($jobId, ['kind' => 'manager_update']);
                }
                if(empty($deploy['changed'])){
                    throw new RuntimeException(
                        'DEPLOY_COMMIT_NOT_APPLIED: Commit جدیدی روی GitHub ثبت نشده؛ repository_dispatch مسدود شد.'
                    );
                }
                self::update_job($jobId, ['status' => 'dispatching', 'commit_sha' => $commit]);
                self::send_message(
                    $job['chat_id'],
                    "✅ فایل‌ها روی GitHub ثبت و روی SHA مقصد تأیید شد.\n" .
                    "نسخه: <code>" . esc_html((string)($deploy['expected_version']??'')) . "</code>\n" .
                    "Commit: <code>" . esc_html(substr($commit, 0, 12)) . "</code>\n" .
                    "فایل‌های تغییرکرده: <b>" . (int)$deploy['files'] . "</b>",
                    [],
                    $s
                );
            } else {
                $commit = self::branch_head_sha($s);
                self::update_job($jobId, ['status' => 'dispatching', 'commit_sha' => $commit]);
            }
            $needsManager = $job['kind'] === 'manager_update' || ($job['kind'] === 'deploy_zip' && !empty($deploy['manager_included']));
            if ($needsManager) {
                self::dispatch_manager_release($commit, $jobId, $s);
                self::update_job($jobId, ['status' => 'waiting_manager', 'run_id' => 0, 'run_url' => '']);
                self::send_message($job['chat_id'], "🧩 انتشار BlueVPN Manager شروع شد.
Commit: <code>" . esc_html(substr($commit, 0, 12)) . "</code>
بعد از انتشار، ربات همان نسخه را روی WordPress نصب می‌کند.", self::keyboard(), $s);
                wp_schedule_single_event(time() + 15, self::POLL_HOOK);
                self::spawn_cron();
                return;
            }

            self::start_android_build_for_job($job, $commit, $s);
        } catch (Throwable $e) {
            self::fail_job($job, $e->getMessage(), $s);
        }
    }

    private static function download_telegram_zip(string $fileId, string $name, array $s): string {
        if (!class_exists('ZipArchive')) throw new RuntimeException('PHP ZipArchive روی سرور فعال نیست.');
        if (!function_exists('wp_tempnam')) require_once ABSPATH . 'wp-admin/includes/file.php';

        $lastError = '';
        $maxAttempts = 5;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            // Resolve the Telegram object again only for transport failures. When the
            // received byte count exactly matches getFile(file_size), a structural ZIP
            // failure belongs to the uploaded source object and retrying the same bytes
            // five times cannot repair it.
            $file = self::api('getFile', ['file_id' => $fileId], $s);
            if (is_wp_error($file)) {
                $lastError = $file->get_error_message();
                if ($attempt < $maxAttempts) { sleep(min(8, $attempt * 2)); continue; }
                throw new RuntimeException($lastError);
            }

            $path = (string)($file['file_path'] ?? '');
            $expectedSize = (int)($file['file_size'] ?? 0);
            if ($path === '') throw new RuntimeException('Telegram file_path را برنگرداند.');

            if ($expectedSize > 0 && $expectedSize > (int)$s['max_zip_mb'] * 1024 * 1024) {
                throw new RuntimeException('ZIP از محدودیت حجم بیشتر است.');
            }

            $token = self::bot_token($s);
            $url = 'https://api.telegram.org/file/bot' . $token . '/' .
                implode('/', array_map('rawurlencode', explode('/', $path)));

            $tmp = wp_tempnam($name !== '' ? $name : 'bluevpn-telegram.zip');
            if (!$tmp) throw new RuntimeException('ساخت فایل موقت برای دریافت ZIP ناموفق بود.');

            try {
                self::download_telegram_file_streaming($url, $tmp);
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                @unlink($tmp);
                if ($attempt < $maxAttempts) { sleep(min(10, $attempt * 2)); continue; }
                break;
            }

            clearstatcache(true, $tmp);
            $size = (int)(@filesize($tmp) ?: 0);
            $transportComplete = $expectedSize > 0 && $size === $expectedSize;

            if ($size > (int)$s['max_zip_mb'] * 1024 * 1024) {
                @unlink($tmp);
                throw new RuntimeException('ZIP از محدودیت حجم بیشتر است.');
            }

            if ($expectedSize > 0 && $size !== $expectedSize) {
                $lastError = 'دانلود Telegram ناقص بود: expected=' . $expectedSize .
                    ', received=' . $size . ' bytes.';
                @unlink($tmp);
                if ($attempt < $maxAttempts) { sleep(min(10, $attempt * 2)); continue; }
                break;
            }

            if ($size < 22) {
                $lastError = 'فایل دریافتی از Telegram ناقص است (' . $size . ' bytes).';
                @unlink($tmp);
                if (!$transportComplete && $attempt < $maxAttempts) { sleep(min(10, $attempt * 2)); continue; }
                break;
            }

            $head = (string)@file_get_contents($tmp, false, null, 0, 8);
            if (!self::telegram_zip_has_local_signature($head)) {
                $lastError = self::telegram_zip_source_error(
                    $tmp,
                    'امضای ابتدایی ZIP معتبر نیست',
                    $expectedSize,
                    $size
                );
                @unlink($tmp);
                // Exact Telegram byte parity proves this is not a truncated transfer.
                if ($transportComplete) break;
                if ($attempt < $maxAttempts) { sleep(min(10, $attempt * 2)); continue; }
                break;
            }

            if (!self::telegram_zip_has_end_record($tmp)) {
                $lastError = self::telegram_zip_source_error(
                    $tmp,
                    'رکورد انتهایی/Central Directory آرشیو پیدا نشد',
                    $expectedSize,
                    $size
                );
                @unlink($tmp);
                if ($transportComplete) break;
                if ($attempt < $maxAttempts) { sleep(min(10, $attempt * 2)); continue; }
                break;
            }

            $zip = new ZipArchive();
            $flags = ZipArchive::RDONLY;
            if (defined('ZipArchive::CHECKCONS')) $flags |= ZipArchive::CHECKCONS;
            $open = $zip->open($tmp, $flags);
            if ($open === true) {
                // Force libzip to inspect the central directory and every entry before
                // the deploy path is allowed to touch GitHub.
                $valid = $zip->numFiles > 0;
                for ($i = 0; $valid && $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    if ($stat === false) { $valid = false; break; }
                    $entryName = str_replace('\\', '/', (string)($stat['name'] ?? ''));
                    if ($entryName === '' || str_starts_with($entryName, '/') || preg_match('#(^|/)\.\.(/|$)#', $entryName)) {
                        $valid = false; break;
                    }
                }
                $zip->close();
                if ($valid) return $tmp;
                $lastError = self::telegram_zip_source_error(
                    $tmp,
                    'Central Directory قابل خواندن است اما فهرست فایل‌ها ناسالم/ناامن است',
                    $expectedSize,
                    $size
                );
            } else {
                $lastError = self::telegram_zip_source_error(
                    $tmp,
                    'ZipArchive باز کردن آرشیو را رد کرد: code=' . (string)$open . '/' . self::ziparchive_error_name((int)$open),
                    $expectedSize,
                    $size
                );
            }

            @unlink($tmp);
            if ($transportComplete) {
                // Telegram returned every byte it advertises. Retrying the identical
                // object is noise and previously turned a source-archive problem into a
                // misleading "download incomplete" report.
                break;
            }
            if ($attempt < $maxAttempts) sleep(min(10, $attempt * 2));
        }

        throw new RuntimeException(
            'ZIP قابل Deploy نیست. ' . $lastError .
            ' اگر expected و received برابرند، فایلِ آپلودشده در Telegram خودش خراب/اشتباه است؛ ' .
            'ZIP کامل پروژه را دوباره از منبع اصلی دانلود و بدون Extract/Repack ناقص ارسال کن.'
        );
    }

    private static function telegram_zip_has_local_signature(string $head): bool {
        if (strlen($head) < 4) return false;
        $sig = substr($head, 0, 4);
        return in_array($sig, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true);
    }

    private static function telegram_zip_has_end_record(string $path): bool {
        $size = (int)(@filesize($path) ?: 0);
        if ($size < 22) return false;
        // EOCD can be followed by a comment of up to 65535 bytes. Read a larger tail
        // to also cover ZIP64 locator/EOCD records without loading the archive in RAM.
        $read = min($size, 131072);
        $fh = @fopen($path, 'rb');
        if (!$fh) return false;
        if ($size > $read) @fseek($fh, -$read, SEEK_END);
        $tail = (string)@fread($fh, $read);
        @fclose($fh);
        return str_contains($tail, "PK\x05\x06") ||
            str_contains($tail, "PK\x06\x06") ||
            str_contains($tail, "PK\x06\x07");
    }

    private static function telegram_zip_source_error(string $path, string $reason, int $expected, int $received): string {
        $sha = is_file($path) ? (string)(@hash_file('sha256', $path) ?: '') : '';
        $tail = self::telegram_zip_has_end_record($path) ? 'yes' : 'no';
        return 'TELEGRAM_SOURCE_ZIP_INVALID: ' . $reason .
            '; expected=' . $expected .
            '; received=' . $received .
            '; eocd=' . $tail .
            ($sha !== '' ? '; sha256=' . $sha : '') . '.';
    }

    private static function ziparchive_error_name(int $code): string {
        $map = [
            0 => 'ER_OK', 1 => 'ER_MULTIDISK', 2 => 'ER_RENAME', 3 => 'ER_CLOSE',
            4 => 'ER_SEEK', 5 => 'ER_READ', 6 => 'ER_WRITE', 7 => 'ER_CRC',
            8 => 'ER_ZIPCLOSED', 9 => 'ER_NOENT', 10 => 'ER_EXISTS', 11 => 'ER_OPEN',
            12 => 'ER_TMPOPEN', 13 => 'ER_ZLIB', 14 => 'ER_MEMORY', 15 => 'ER_CHANGED',
            16 => 'ER_COMPNOTSUPP', 17 => 'ER_EOF', 18 => 'ER_INVAL', 19 => 'ER_NOZIP',
            20 => 'ER_INTERNAL', 21 => 'ER_INCONS', 22 => 'ER_REMOVE', 23 => 'ER_DELETED',
            24 => 'ER_ENCRNOTSUPP', 25 => 'ER_RDONLY', 26 => 'ER_NOPASSWD',
            27 => 'ER_WRONGPASSWD', 28 => 'ER_OPNOTSUPP', 29 => 'ER_INUSE',
            30 => 'ER_TELL', 31 => 'ER_COMPRESSED_DATA', 32 => 'ER_CANCELLED',
        ];
        return $map[$code] ?? 'ER_UNKNOWN';
    }

    private static function download_telegram_file_streaming(string $url, string $target): void {
        // Prefer native cURL on cPanel/PHP. WordPress download_url() can pass through
        // hosting/proxy layers that have produced repeatable ~2 MB truncation for larger
        // Telegram documents. cURL writes directly to disk and lets us verify HTTP status.
        if (function_exists('curl_init')) {
            $fh = @fopen($target, 'wb');
            if (!$fh) throw new RuntimeException('فایل موقت برای نوشتن باز نشد.');

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fh,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_TIMEOUT => 600,
                CURLOPT_LOW_SPEED_LIMIT => 128,
                CURLOPT_LOW_SPEED_TIME => 30,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'BlueVPN-Manager/' . BLUEVPN_MANAGER_VERSION,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/octet-stream',
                    'Accept-Encoding: identity',
                    'Connection: close',
                ],
            ]);

            $ok = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            fclose($fh);

            if ($ok !== true || $errno !== 0) {
                throw new RuntimeException(
                    'خطای دریافت مستقیم Telegram/cURL' .
                    ($errno ? ' #' . $errno : '') .
                    ($error !== '' ? ': ' . $error : '')
                );
            }
            if ($status < 200 || $status >= 300) {
                throw new RuntimeException('Telegram file HTTP ' . $status);
            }
            return;
        }

        // Portable fallback when cURL is unavailable.
        $args = [
            'timeout' => 600,
            'redirection' => 5,
            'stream' => true,
            'filename' => $target,
            'headers' => [
                'Accept' => 'application/octet-stream',
                'Accept-Encoding' => 'identity',
                'Connection' => 'close',
            ],
        ];
        $res = wp_safe_remote_get($url, $args);
        if (is_wp_error($res)) throw new RuntimeException($res->get_error_message());
        $status = (int)wp_remote_retrieve_response_code($res);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Telegram file HTTP ' . $status);
        }
    }
    private static function resolve_project_root(string $extractedRoot): string {
        $extractedRoot = rtrim($extractedRoot, '/\\');
        $fullRequired = ['branding/app.json', 'release.json', 'bluevpn-manager/bluevpn-manager.php'];

        $matchesFull = static function (string $base) use ($fullRequired): bool {
            foreach ($fullRequired as $rel) {
                if (!is_file($base . '/' . $rel)) return false;
            }
            return true;
        };

        // Backward-compatible alias kept intentionally: the regression gate and older
        // flat-ZIP deploy contract expect this exact $matches() fast path.
        $matches = $matchesFull;

        $matchesManagerParent = static function (string $base): bool {
            return is_file($base . '/bluevpn-manager/bluevpn-manager.php');
        };

        // extract_zip_safely() collapses a ZIP that contains exactly one top-level
        // directory. For a manager-only ZIP that directory is commonly
        // "bluevpn-manager", so $extractedRoot itself becomes the plugin directory.
        // In that case the deploy root must be its parent, otherwise files would be
        // copied into repository root and the manager sentinel would not be found.
        $isManagerDir = static function (string $base): bool {
            return basename(str_replace('\\', '/', $base)) === 'bluevpn-manager'
                && is_file($base . '/bluevpn-manager.php');
        };

        if($matches($extractedRoot))return $extractedRoot;
        if ($matchesManagerParent($extractedRoot)) return $extractedRoot;
        if ($isManagerDir($extractedRoot)) return dirname($extractedRoot);

        // Do not depend on directory nesting depth. Search for the actual sentinel
        // files and derive candidate roots from them. This supports Telegram ZIPs
        // wrapped in one or more folders as well as manager-only packages.
        $fullCandidates = [];
        $managerCandidates = [];

        $scan = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($extractedRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($scan as $item) {
            if (!$item->isFile()) continue;

            $name = str_replace('\\', '/', $item->getPathname());
            if (basename($name) !== 'bluevpn-manager.php') continue;

            $pluginDir = dirname($name);
            if (basename(str_replace('\\', '/', $pluginDir)) !== 'bluevpn-manager') continue;

            $candidate = dirname($pluginDir);
            if ($matchesFull($candidate)) {
                $fullCandidates[$candidate] = true;
            } elseif ($matchesManagerParent($candidate)) {
                $managerCandidates[$candidate] = true;
            }
        }

        $fullCandidates = array_keys($fullCandidates);
        $managerCandidates = array_keys($managerCandidates);

        if ($fullCandidates) {
            $candidates = $fullCandidates;
        } elseif ($managerCandidates) {
            $candidates = $managerCandidates;
        } else {
            throw new RuntimeException(
                'DEPLOY_PROJECT_ROOT_NOT_FOUND: ریشه معتبر BlueVPN داخل ZIP پیدا نشد. ' .
                'پروژه کامل باید branding/app.json + release.json + bluevpn-manager/bluevpn-manager.php داشته باشد؛ ' .
                'برای بروزرسانی فقط Manager وجود bluevpn-manager/bluevpn-manager.php کافی است.'
            );
        }

        usort($candidates, static function (string $a, string $b) use ($extractedRoot): int {
            $ra = ltrim(str_replace('\\', '/', substr($a, strlen($extractedRoot))), '/');
            $rb = ltrim(str_replace('\\', '/', substr($b, strlen($extractedRoot))), '/');
            $da = $ra === '' ? 0 : substr_count($ra, '/') + 1;
            $db = $rb === '' ? 0 : substr_count($rb, '/') + 1;
            if ($da !== $db) return $da <=> $db;
            return strlen($a) <=> strlen($b);
        });

        $best = (string)$candidates[0];
        if (count($candidates) > 1) {
            $firstRel = ltrim(str_replace('\\', '/', substr($best, strlen($extractedRoot))), '/');
            $firstDepth = $firstRel === '' ? 0 : substr_count($firstRel, '/') + 1;
            foreach (array_slice($candidates, 1) as $candidate) {
                $rel = ltrim(str_replace('\\', '/', substr($candidate, strlen($extractedRoot))), '/');
                $depth = $rel === '' ? 0 : substr_count($rel, '/') + 1;
                if ($depth === $firstDepth) {
                    throw new RuntimeException(
                        'DEPLOY_PROJECT_ROOT_AMBIGUOUS: بیش از یک ریشه معتبر BlueVPN داخل ZIP پیدا شد.'
                    );
                }
                break;
            }
        }

        return $best;
    }
    private static function expected_release_from_tree(string $root): array {
        $brandingPath=$root . '/branding/app.json';
        $releasePath=$root . '/release.json';
        $managerPath=$root . '/bluevpn-manager/bluevpn-manager.php';
        $siteStylePath=$root . '/bluevpn-site/style.css';
        $siteFunctionsPath=$root . '/bluevpn-site/functions.php';
        $windowsSettingsPath=$root . '/bluevpn-windows/appsettings.json';
        $windowsProjectPath=$root . '/bluevpn-windows/BlueVPN.Windows.csproj';

        if(!is_file($brandingPath) && is_file($managerPath)){
            $manager=(string)file_get_contents($managerPath);
            if(!preg_match('/define\(\s*[\'\"]BLUEVPN_MANAGER_VERSION[\'\"]\s*,\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/',$manager,$m)){
                throw new RuntimeException('DEPLOY_MANAGER_VERSION_MISSING: نسخه BlueVPN Manager داخل ZIP قابل تشخیص نیست.');
            }
            $version=trim((string)$m[1]);
            if($version==='')throw new RuntimeException('DEPLOY_MANAGER_VERSION_INVALID: نسخه BlueVPN Manager معتبر نیست.');
            return ['version'=>$version,'version_code'=>0,'mode'=>'manager_only'];
        }
        if(!is_file($brandingPath))throw new RuntimeException('DEPLOY_VERSION_METADATA_MISSING: branding/app.json داخل ZIP وجود ندارد.');
        $branding=json_decode((string)file_get_contents($brandingPath),true);
        if(!is_array($branding))throw new RuntimeException('DEPLOY_VERSION_METADATA_INVALID: branding/app.json معتبر نیست.');
        $version=trim((string)($branding['version_name']??''));$code=(int)($branding['version_code']??0);
        if($version===''||$code<=0)throw new RuntimeException('DEPLOY_VERSION_METADATA_INVALID: version_name/version_code معتبر نیست.');
        if(!is_file($releasePath))throw new RuntimeException('DEPLOY_VERSION_METADATA_MISSING: release.json برای پروژه کامل وجود ندارد.');
        $release=json_decode((string)file_get_contents($releasePath),true);
        if(!is_array($release))throw new RuntimeException('DEPLOY_VERSION_METADATA_INVALID: release.json معتبر نیست.');
        $releaseVersion=trim((string)($release['version']??''));$releaseCode=(int)($release['version_code']??0);
        if($releaseVersion!==$version||$releaseCode!==$code){
            throw new RuntimeException('DEPLOY_VERSION_MISMATCH: branding/app.json و release.json هم‌نسخه نیستند. branding=' . $version . '/' . $code . ' release=' . $releaseVersion . '/' . $releaseCode);
        }
        if(is_file($managerPath)){
            $manager=(string)file_get_contents($managerPath);
            if(!preg_match('/define\(\s*[\'\"]BLUEVPN_MANAGER_VERSION[\'\"]\s*,\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/',$manager,$m)||trim((string)$m[1])!==$version){
                throw new RuntimeException('DEPLOY_VERSION_MISMATCH: نسخه BlueVPN Manager با نسخه Android/Release یکسان نیست.');
            }
        }
        if(is_file($siteStylePath) || is_file($siteFunctionsPath)){
            if(!is_file($siteStylePath) || !is_file($siteFunctionsPath)){
                throw new RuntimeException('DEPLOY_SITE_VERSION_MISSING: فایل‌های نسخه پوسته BlueVPN ناقص هستند.');
            }
            $style=(string)file_get_contents($siteStylePath);
            $functions=(string)file_get_contents($siteFunctionsPath);
            if(!preg_match('/^Version:\s*(\d+\.\d+\.\d+)\s*$/mi',$style,$sm) || trim((string)$sm[1])!==$version){
                throw new RuntimeException('DEPLOY_VERSION_MISMATCH: نسخه style.css پوسته با نسخه Android/Manager یکسان نیست.');
            }
            if(!preg_match('/define\(\s*[\'\"]BLUEVPN_SITE_VERSION[\'\"]\s*,\s*[\'\"](\d+\.\d+\.\d+)[\'\"]\s*\)/',$functions,$sf) || trim((string)$sf[1])!==$version){
                throw new RuntimeException('DEPLOY_VERSION_MISMATCH: BLUEVPN_SITE_VERSION با نسخه Android/Manager یکسان نیست.');
            }
        }
        if(is_file($windowsSettingsPath) || is_file($windowsProjectPath)){
            if(!is_file($windowsSettingsPath) || !is_file($windowsProjectPath)){
                throw new RuntimeException('DEPLOY_WINDOWS_VERSION_MISSING: فایل‌های نسخه کلاینت Windows ناقص هستند.');
            }
            $windowsSettings=json_decode((string)file_get_contents($windowsSettingsPath),true);
            $windowsProject=(string)file_get_contents($windowsProjectPath);
            if(!is_array($windowsSettings) || trim((string)($windowsSettings['version']??''))!==$version){
                throw new RuntimeException('DEPLOY_VERSION_MISMATCH: نسخه appsettings کلاینت Windows با نسخه اصلی یکسان نیست.');
            }
            if(!preg_match('/<Version>\s*(\d+\.\d+\.\d+)\s*<\/Version>/',$windowsProject,$wv) || trim((string)$wv[1])!==$version){
                throw new RuntimeException('DEPLOY_VERSION_MISMATCH: نسخه پروژه Windows با نسخه اصلی یکسان نیست.');
            }
        }
        foreach(['manager_version','site_version','theme_version','windows_version'] as $componentVersionKey){
            if(isset($release[$componentVersionKey]) && trim((string)$release[$componentVersionKey])!==$version){
                throw new RuntimeException('DEPLOY_VERSION_MISMATCH: release.json/' . $componentVersionKey . ' با نسخه اصلی یکسان نیست.');
            }
        }
        return ['version'=>$version,'version_code'=>$code,'mode'=>'full_project'];
    }

    private static function github_file_at_commit(string $path,string $commitSha,array $s): string {
        $payload=self::gh(
            'GET',
            self::repo_path($s) . '/contents/' . str_replace('%2F','/',rawurlencode($path)) .
            '?ref=' . rawurlencode($commitSha),
            null,
            $s
        );
        $encoding=strtolower((string)($payload['encoding']??''));
        $content=(string)($payload['content']??'');
        if($encoding!=='base64'||$content===''){
            throw new RuntimeException('DEPLOY_REMOTE_VERIFY_FAILED: فایل ' . $path . ' روی Commit مقصد قابل خواندن نیست.');
        }
        $decoded=base64_decode(str_replace(["\r","\n"],'',$content),true);
        if($decoded===false){
            throw new RuntimeException('DEPLOY_REMOTE_VERIFY_FAILED: محتوای ' . $path . ' روی GitHub قابل Decode نیست.');
        }
        return $decoded;
    }

    private static function verify_deployed_release(string $commitSha,array $expected,array $deploy,array $s): void {
        if((string)($expected['mode']??'full_project')==='manager_only'){
            if(empty($deploy['changed']))throw new RuntimeException('DEPLOY_COMMIT_NOT_APPLIED: ZIP Manager هیچ Diff واقعی روی GitHub ایجاد نکرد.');
            self::verify_commit_on_branch($commitSha,$s);
            $managerRaw=self::github_file_at_commit('bluevpn-manager/bluevpn-manager.php',$commitSha,$s);
            if(!preg_match('/define\(\s*[\'\"]BLUEVPN_MANAGER_VERSION[\'\"]\s*,\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/',$managerRaw,$m)||trim((string)$m[1])!==(string)$expected['version']){
                throw new RuntimeException('DEPLOY_MANAGER_VERSION_NOT_APPLIED: نسخه Manager روی SHA مقصد با ZIP یکسان نیست.');
            }
            return;
        }
        if(empty($deploy['changed'])){
            throw new RuntimeException(
                'DEPLOY_COMMIT_NOT_APPLIED: ZIP هیچ Diff واقعی روی GitHub ایجاد نکرد؛ Build شروع نشد.'
            );
        }
        if((int)($deploy['files']??0)+(int)($deploy['deleted']??0)<=0){
            throw new RuntimeException(
                'DEPLOY_COMMIT_NOT_APPLIED: تعداد فایل‌های تغییرکرده صفر است؛ Build شروع نشد.'
            );
        }

        self::verify_commit_on_branch($commitSha,$s);

        $commitPayload=self::gh(
            'GET',
            self::repo_path($s) . '/commits/' . rawurlencode($commitSha),
            null,
            $s
        );
        if(count((array)($commitPayload['files']??[]))===0){
            throw new RuntimeException(
                'DEPLOY_COMMIT_NOT_APPLIED: Commit مقصد در GitHub هیچ فایل تغییرکرده‌ای ندارد.'
            );
        }

        $branding=json_decode(self::github_file_at_commit('branding/app.json',$commitSha,$s),true);
        $remoteVersion=is_array($branding)?trim((string)($branding['version_name']??'')):'';
        $remoteCode=is_array($branding)?(int)($branding['version_code']??0):0;
        if($remoteVersion!==(string)$expected['version']||$remoteCode!==(int)$expected['version_code']){
            throw new RuntimeException(
                'DEPLOY_VERSION_NOT_APPLIED: نسخه روی SHA مقصد با ZIP یکسان نیست.' .
                ' expected=' . (string)$expected['version'] . '/' . (int)$expected['version_code'] .
                ' remote=' . $remoteVersion . '/' . $remoteCode .
                ' sha=' . substr($commitSha,0,12)
            );
        }

        $release=json_decode(self::github_file_at_commit('release.json',$commitSha,$s),true);
        $releaseVersion=is_array($release)?trim((string)($release['version']??'')):'';
        $releaseCode=is_array($release)?(int)($release['version_code']??0):0;
        if($releaseVersion!==(string)$expected['version']||$releaseCode!==(int)$expected['version_code']){
            throw new RuntimeException(
                'DEPLOY_RELEASE_METADATA_NOT_APPLIED: release.json روی SHA مقصد هنوز نسخه مورد انتظار را ندارد.'
            );
        }

        $managerRaw=self::github_file_at_commit('bluevpn-manager/bluevpn-manager.php',$commitSha,$s);
        if(
            !preg_match('/define\(\s*[\'"]BLUEVPN_MANAGER_VERSION[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/',$managerRaw,$m) ||
            trim((string)$m[1])!==(string)$expected['version']
        ){
            throw new RuntimeException(
                'DEPLOY_MANAGER_VERSION_NOT_APPLIED: نسخه Manager روی SHA مقصد با ZIP یکسان نیست.'
            );
        }

        $siteStyle=self::github_file_at_commit('bluevpn-site/style.css',$commitSha,$s);
        $siteFunctions=self::github_file_at_commit('bluevpn-site/functions.php',$commitSha,$s);
        if(
            !preg_match('/^Version:\s*(\d+\.\d+\.\d+)\s*$/mi',$siteStyle,$siteStyleMatch) ||
            trim((string)$siteStyleMatch[1])!==(string)$expected['version']
        ){
            throw new RuntimeException(
                'DEPLOY_SITE_VERSION_NOT_APPLIED: نسخه style.css پوسته روی SHA مقصد با ZIP یکسان نیست.'
            );
        }
        if(
            !preg_match('/define\(\s*[\'"]BLUEVPN_SITE_VERSION[\'"]\s*,\s*[\'"](\d+\.\d+\.\d+)[\'"]\s*\)/',$siteFunctions,$siteFnMatch) ||
            trim((string)$siteFnMatch[1])!==(string)$expected['version']
        ){
            throw new RuntimeException(
                'DEPLOY_SITE_VERSION_NOT_APPLIED: BLUEVPN_SITE_VERSION روی SHA مقصد با ZIP یکسان نیست.'
            );
        }

        $windowsSettings=json_decode(self::github_file_at_commit('bluevpn-windows/appsettings.json',$commitSha,$s),true);
        $windowsProject=self::github_file_at_commit('bluevpn-windows/BlueVPN.Windows.csproj',$commitSha,$s);
        if(!is_array($windowsSettings) || trim((string)($windowsSettings['version']??''))!==(string)$expected['version']){
            throw new RuntimeException('DEPLOY_WINDOWS_VERSION_NOT_APPLIED: نسخه appsettings کلاینت Windows روی SHA مقصد یکسان نیست.');
        }
        if(!preg_match('/<Version>\s*(\d+\.\d+\.\d+)\s*<\/Version>/',$windowsProject,$windowsVersionMatch) || trim((string)$windowsVersionMatch[1])!==(string)$expected['version']){
            throw new RuntimeException('DEPLOY_WINDOWS_VERSION_NOT_APPLIED: نسخه پروژه Windows روی SHA مقصد یکسان نیست.');
        }
    }

    private static function deploy_zip_to_github(string $zipPath, array $s): array {
        $extractRoot = self::extract_zip_safely($zipPath, $s);
        $root = self::resolve_project_root($extractRoot);
        self::stamp_tapsell_build_config($root);
        $managerIncluded = is_file($root . '/bluevpn-manager/bluevpn-manager.php');
        $expectedRelease = self::expected_release_from_tree($root);
        try {
            $gitError = '';
            if (self::git_cli_available()) {
                try {
                    $result = self::deploy_extracted_via_git($root, $s);
                    $result['transport'] = 'git_https';
                    $result['manager_included'] = $managerIncluded;
                    $result['expected_version'] = (string)$expectedRelease['version'];
                    $result['expected_version_code'] = (int)$expectedRelease['version_code'];
                    $result['package_type'] = (string)($expectedRelease['mode'] ?? 'full_project');
                    self::verify_deployed_release((string)$result['commit'],$expectedRelease,$result,$s);
                    return $result;
                } catch (Throwable $e) {
                    $gitError = self::redact($e->getMessage(), $s);
                }
            }

            try {
                $result = self::deploy_extracted_via_rest($root, $s);
                $result['transport'] = 'github_git_data_api';
                $result['manager_included'] = $managerIncluded;
                $result['expected_version'] = (string)$expectedRelease['version'];
                $result['expected_version_code'] = (int)$expectedRelease['version_code'];
                    $result['package_type'] = (string)($expectedRelease['mode'] ?? 'full_project');
                if ($gitError !== '') $result['git_cli_fallback_error'] = $gitError;
                self::verify_deployed_release((string)$result['commit'],$expectedRelease,$result,$s);
                return $result;
            } catch (Throwable $e) {
                $restError = self::redact($e->getMessage(), $s);
                if ($gitError !== '') {
                    throw new RuntimeException(
                        "ثبت ZIP روی GitHub با هر دو روش ناموفق بود.\n" .
                        "Git HTTPS: " . $gitError . "\n" .
                        "GitHub REST: " . $restError
                    );
                }
                throw new RuntimeException($restError);
            }
        } finally {
            $cleanupRoot = isset($extractRoot) ? $extractRoot : $root;
            if (str_starts_with(basename($cleanupRoot), 'bluevpn-bot-')) self::rrmdir($cleanupRoot);
            elseif (str_starts_with(basename(dirname($cleanupRoot)), 'bluevpn-bot-')) self::rrmdir(dirname($cleanupRoot));
        }
    }

    /**
     * The Railway deploy bot used real `git clone` + `git push` over HTTPS.
     * Keep that transport as the primary path because existing PATs that were
     * already proven against the old bot continue to work without depending on
     * GitHub's Git Data REST endpoints.
     */
    private static function deploy_extracted_via_git(string $root, array $s): array {
        $repoName = trim((string)$s['github_repository']);
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repoName)) {
            throw new RuntimeException('GITHUB_REPOSITORY باید OWNER/REPOSITORY باشد.');
        }
        $branch = (string)$s['git_branch'];
        $token = self::github_token($s);
        if ($token === '') throw new RuntimeException('GITHUB_TOKEN تنظیم نشده است.');

        $tmp = trailingslashit(get_temp_dir()) . 'bluevpn-git-' . wp_generate_password(12, false, false);
        $repo = $tmp . '/repo';
        wp_mkdir_p($tmp);
        $askpass = $tmp . '/askpass.sh';
        $askpassScript = <<<'BLUEVPN_ASKPASS'
#!/bin/sh
case "$1" in
  *Username*) printf '%s\n' 'x-access-token' ;;
  *Password*) printf '%s\n' "$BLUEVPN_GH_TOKEN" ;;
  *) printf '\n' ;;
esac
BLUEVPN_ASKPASS;
        file_put_contents($askpass, $askpassScript . "\n");
        @chmod($askpass, 0700);

        $env = self::git_env($token, $askpass);
        try {
            $remote = 'https://github.com/' . $repoName . '.git';
            self::run_git(['clone', '--depth', '50', '--branch', $branch, $remote, $repo], null, $env, 420, true, $s, 'clone');
            self::run_git(['config', 'user.name', 'BlueVPN Deploy Bot'], $repo, $env, 30, true, $s, 'config user.name');
            self::run_git(['config', 'user.email', 'bluevpn-bot@users.noreply.github.com'], $repo, $env, 30, true, $s, 'config user.email');

            $copied = 0;
            $deleted = 0;

            if (self::is_full_platform_root($root)) {
                $authoritative = self::repository_authoritative_files($root);
                $repoIt = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($repo, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($repoIt as $existing) {
                    if ($existing->isDir()) continue;
                    $path = $existing->getPathname();
                    $rel = str_replace('\\', '/', substr($path, strlen($repo) + 1));
                    if ($rel === '.git' || str_starts_with($rel, '.git/')) continue;
                    if (!self::safe_repo_path($rel) || self::protected_path($rel)) continue;
                    if (self::repository_junk_path($rel) || !isset($authoritative[$rel])) {
                        @unlink($path);
                        $deleted++;
                    }
                }
            }
            $deleteFile = $root . '/.bluevpn-delete';
            if (is_file($deleteFile)) {
                foreach (preg_split('/\r?\n/', (string)file_get_contents($deleteFile)) ?: [] as $line) {
                    $line = ltrim(trim(str_replace('\\', '/', $line)), '/');
                    if ($line === '' || str_starts_with($line, '#') || !self::safe_repo_path($line) || self::protected_path($line)) continue;
                    $dest = $repo . '/' . $line;
                    $repoReal = realpath($repo) ?: $repo;
                    $parentReal = realpath(dirname($dest));
                    if ($parentReal !== false && !str_starts_with($parentReal . '/', rtrim($repoReal, '/') . '/')) continue;
                    if (is_dir($dest) && !is_link($dest)) { self::rrmdir($dest); $deleted++; }
                    elseif (file_exists($dest) || is_link($dest)) { @unlink($dest); $deleted++; }
                }
            }

            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!$file->isFile()) continue;
                $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                if ($rel === '.bluevpn-delete' || str_starts_with($rel, '__MACOSX/') || str_starts_with($rel, '.git/')) continue;
                if (self::repository_junk_path($rel)) continue;
                if (!self::safe_repo_path($rel) || self::protected_path($rel)) continue;
                $dest = $repo . '/' . $rel;
                wp_mkdir_p(dirname($dest));
                if (!@copy($file->getPathname(), $dest)) throw new RuntimeException('کپی فایل داخل Clone ناموفق بود: ' . $rel);
                $copied++;
            }
            if ($copied === 0 && $deleted === 0) throw new RuntimeException('ZIP فایل قابل اعمال ندارد.');

            self::run_git(['add', '-A'], $repo, $env, 120, true, $s, 'git add');
            $diff = self::run_git(['diff', '--cached', '--quiet'], $repo, $env, 60, false, $s, 'git diff');
            if ((int)$diff['code'] === 0) {
                $head = trim((string)self::run_git(['rev-parse', 'HEAD'], $repo, $env, 30, true, $s, 'rev-parse')['stdout']);
                $remoteSha = self::remote_branch_sha_git($repo, $branch, $env, $s);
                if ($head === '' || $remoteSha === '' || !hash_equals($head, $remoteSha)) {
                    throw new RuntimeException('Clone محلی با شاخه GitHub همگام نیست؛ عملیات برای جلوگیری از Build روی سورس اشتباه متوقف شد.');
                }
                return ['commit' => $head, 'files' => $copied, 'deleted' => $deleted, 'changed' => false];
            }
            if ((int)$diff['code'] !== 1) throw new RuntimeException('بررسی تغییرات Git ناموفق بود: ' . trim((string)$diff['stderr']));

            $changedRaw = (string)self::run_git(['diff', '--cached', '--name-only'], $repo, $env, 60, true, $s, 'changed files')['stdout'];
            $changedFiles = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $changedRaw) ?: []), 'strlen'));
            self::run_git(['commit', '-m', 'deploy: persist BlueVPN project files from Telegram'], $repo, $env, 120, true, $s, 'git commit');

            $push = self::run_git(['push', '--porcelain', 'origin', 'HEAD:' . $branch], $repo, $env, 420, false, $s, 'git push');
            if ((int)$push['code'] !== 0) {
                $combined = strtolower((string)$push['stderr'] . "\n" . (string)$push['stdout']);
                $race = str_contains($combined, 'non-fast-forward') || str_contains($combined, 'fetch first') || str_contains($combined, 'failed to push some refs') || str_contains($combined, 'stale info');
                if ($race) {
                    self::run_git(['fetch', '--prune', 'origin', $branch], $repo, $env, 180, true, $s, 'git fetch');
                    $rebase = self::run_git(['rebase', 'origin/' . $branch], $repo, $env, 180, false, $s, 'git rebase');
                    if ((int)$rebase['code'] !== 0) {
                        self::run_git(['rebase', '--abort'], $repo, $env, 30, false, $s, 'rebase abort');
                        throw new RuntimeException('هم‌زمان تغییر دیگری روی GitHub ثبت شد و Rebase خودکار به تعارض خورد. ZIP را دوباره ارسال کن.');
                    }
                    $push = self::run_git(['push', '--porcelain', 'origin', 'HEAD:' . $branch], $repo, $env, 420, false, $s, 'git push retry');
                }
                if ((int)$push['code'] !== 0) {
                    throw new RuntimeException('Push فایل‌ها به GitHub ناموفق بود: ' . trim((string)($push['stderr'] ?: $push['stdout'])));
                }
            }

            $commit = trim((string)self::run_git(['rev-parse', 'HEAD'], $repo, $env, 30, true, $s, 'rev-parse after push')['stdout']);
            $remoteSha = '';
            for ($i = 0; $i < 8; $i++) {
                $remoteSha = self::remote_branch_sha_git($repo, $branch, $env, $s);
                if ($commit !== '' && $remoteSha !== '' && hash_equals($commit, $remoteSha)) break;
                usleep(750000);
            }
            if ($commit === '' || $remoteSha === '' || !hash_equals($commit, $remoteSha)) {
                throw new RuntimeException('تأیید Push ناموفق بود؛ SHA محلی و GitHub یکسان نیستند.');
            }
            return ['commit' => $commit, 'files' => count($changedFiles), 'deleted' => $deleted, 'changed' => true, 'changed_files' => $changedFiles];
        } finally {
            @unlink($askpass);
            self::rrmdir($tmp);
        }
    }

    private static function git_cli_available(): bool {
        if (!function_exists('proc_open')) return false;
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (in_array('proc_open', $disabled, true)) return false;
        try {
            $r = self::run_process(['git', '--version'], null, self::base_process_env(), 10);
            return (int)$r['code'] === 0 && str_contains(strtolower((string)$r['stdout']), 'git version');
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function base_process_env(): array {
        $env = [];
        foreach (['PATH','HOME','TMPDIR','LANG','LC_ALL','SSL_CERT_FILE','SSL_CERT_DIR'] as $key) {
            $v = getenv($key);
            if ($v !== false && $v !== '') $env[$key] = $v;
        }
        if (!isset($env['PATH'])) $env['PATH'] = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
        if (!isset($env['HOME'])) $env['HOME'] = get_temp_dir();
        return $env;
    }

    private static function git_env(string $token, string $askpass): array {
        $env = self::base_process_env();
        $env['GIT_TERMINAL_PROMPT'] = '0';
        $env['GIT_ASKPASS'] = $askpass;
        $env['GIT_ASKPASS_REQUIRE'] = 'force';
        $env['BLUEVPN_GH_TOKEN'] = $token;
        return $env;
    }

    private static function run_git(array $args, ?string $cwd, array $env, int $timeout, bool $check, array $s, string $stage): array {
        $result = self::run_process(array_merge(['git'], $args), $cwd, $env, $timeout);
        if ($check && (int)$result['code'] !== 0) {
            $msg = trim((string)($result['stderr'] ?: $result['stdout']));
            throw new RuntimeException('Git stage [' . $stage . '] failed (exit ' . (int)$result['code'] . '): ' . self::redact(mb_substr($msg, -2500), $s));
        }
        return $result;
    }

    private static function run_process(array $command, ?string $cwd, array $env, int $timeout): array {
        $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($command, $spec, $pipes, $cwd ?: null, $env);
        if (!is_resource($proc)) throw new RuntimeException('امکان اجرای Git CLI روی این سرور وجود ندارد.');
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $started = microtime(true);
        $timedOut = false;
        $exitCode = null;
        while (true) {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) { $exitCode = (int)$status['exitcode']; break; }
            if ((microtime(true) - $started) > $timeout) {
                $timedOut = true;
                @proc_terminate($proc, 15);
                usleep(250000);
                $status = proc_get_status($proc);
                if ($status['running']) @proc_terminate($proc, 9);
                break;
            }
            usleep(100000);
        }
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $closedCode = proc_close($proc);
        $code = ($closedCode >= 0) ? $closedCode : (($exitCode !== null && $exitCode >= 0) ? $exitCode : $closedCode);
        if ($timedOut) throw new RuntimeException('فرمان Git بعد از ' . $timeout . ' ثانیه Timeout شد.');
        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private static function remote_branch_sha_git(string $repo, string $branch, array $env, array $s): string {
        $r = self::run_git(['ls-remote', 'origin', 'refs/heads/' . $branch], $repo, $env, 60, true, $s, 'ls-remote');
        $line = trim((string)$r['stdout']);
        if ($line === '') return '';
        $parts = preg_split('/\s+/', $line) ?: [];
        return isset($parts[0]) && preg_match('/^[a-f0-9]{40}$/i', $parts[0]) ? strtolower($parts[0]) : '';
    }

    private static function git_remote_head(array $s): string {
        $repoName = trim((string)$s['github_repository']);
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repoName)) throw new RuntimeException('GITHUB_REPOSITORY نامعتبر است.');
        $token = self::github_token($s);
        if ($token === '') throw new RuntimeException('GITHUB_TOKEN تنظیم نشده است.');
        $tmp = trailingslashit(get_temp_dir()) . 'bluevpn-git-check-' . wp_generate_password(10, false, false);
        wp_mkdir_p($tmp);
        $askpass = $tmp . '/askpass.sh';
        $script = <<<'BLUEVPN_ASKPASS_CHECK'
#!/bin/sh
case "$1" in
  *Username*) printf '%s\n' 'x-access-token' ;;
  *Password*) printf '%s\n' "$BLUEVPN_GH_TOKEN" ;;
  *) printf '\n' ;;
esac
BLUEVPN_ASKPASS_CHECK;
        file_put_contents($askpass, $script . "\n");
        @chmod($askpass, 0700);
        try {
            $env = self::git_env($token, $askpass);
            $remote = 'https://github.com/' . $repoName . '.git';
            $r = self::run_git(['ls-remote', $remote, 'refs/heads/' . (string)$s['git_branch']], null, $env, 60, true, $s, 'remote head');
            $line = trim((string)$r['stdout']);
            $parts = preg_split('/\s+/', $line) ?: [];
            $sha = isset($parts[0]) && preg_match('/^[a-f0-9]{40}$/i', $parts[0]) ? strtolower($parts[0]) : '';
            if ($sha === '') throw new RuntimeException('Git HTTPS برای شاخه مقصد SHA برنگرداند.');
            return $sha;
        } finally {
            @unlink($askpass);
            self::rrmdir($tmp);
        }
    }

    private static function deploy_extracted_via_rest(string $root, array $s): array {
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
            if (self::repository_junk_path($rel)) continue;
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

        // Build a path -> blob SHA map once. This prevents re-uploading the whole ZIP
        // on every deploy. Git blob IDs are deterministic, so unchanged files can be
        // skipped locally without a POST /git/blobs call.
        $remoteFiles = [];
        try {
            $remoteTree = self::gh(
                'GET',
                self::repo_path($s) . '/git/trees/' . rawurlencode($baseTree) . '?recursive=1',
                null,
                $s
            );
            foreach ((array)($remoteTree['tree'] ?? []) as $node) {
                if (!is_array($node) || (string)($node['type'] ?? '') !== 'blob') continue;
                $path = str_replace('\\', '/', (string)($node['path'] ?? ''));
                $sha = strtolower((string)($node['sha'] ?? ''));
                if ($path !== '' && preg_match('/^[a-f0-9]{40}$/', $sha)) {
                    $remoteFiles[$path] = $sha;
                }
            }
        } catch (Throwable $e) {
            // Safe fallback: deploy still works using the old full-upload behavior.
            $remoteFiles = [];
        }

        if (self::is_full_platform_root($root) && $remoteFiles) {
            $authoritative = [];
            foreach ($entries as $entry) {
                $authoritative[(string)$entry['path']] = true;
            }
            foreach (array_keys($remoteFiles) as $remotePath) {
                if (
                    self::safe_repo_path($remotePath) &&
                    !self::protected_path($remotePath) &&
                    (self::repository_junk_path($remotePath) || !isset($authoritative[$remotePath]))
                ) {
                    $deletions[] = $remotePath;
                }
            }
        }

        $changedEntries = [];
        foreach ($entries as $entry) {
            $bytes = (string)file_get_contents($entry['file']);
            $gitBlobSha = sha1('blob ' . strlen($bytes) . "\0" . $bytes);
            if (isset($remoteFiles[$entry['path']]) && hash_equals($remoteFiles[$entry['path']], $gitBlobSha)) {
                continue;
            }
            $entry['bytes'] = $bytes;
            $entry['git_sha'] = $gitBlobSha;
            $changedEntries[] = $entry;
        }

        $effectiveDeletions = [];
        foreach (array_values(array_unique($deletions)) as $path) {
            // Do not create a tree mutation for a path that is already absent.
            if (!$remoteFiles || isset($remoteFiles[$path])) $effectiveDeletions[] = $path;
        }

        if (!$changedEntries && !$effectiveDeletions) {
            return [
                'commit' => $parent,
                'files' => count($entries),
                'uploaded' => 0,
                'skipped' => count($entries),
                'deleted' => 0,
                'changed' => false,
            ];
        }

        $treeSha = $baseTree;
        $batch = [];
        $flush = static function () use (&$batch, &$treeSha, $s): void {
            if (!$batch) return;
            $created = self::gh(
                'POST',
                self::repo_path($s) . '/git/trees',
                ['base_tree' => $treeSha, 'tree' => $batch],
                $s
            );
            $treeSha = (string)($created['sha'] ?? '');
            if ($treeSha === '') throw new RuntimeException('GitHub Tree ساخته نشد.');
            $batch = [];
        };

        foreach ($changedEntries as $entry) {
            $bytes = (string)$entry['bytes'];
            $item = ['path' => $entry['path'], 'mode' => '100644', 'type' => 'blob'];

            // Inline small UTF-8 text directly in the tree. Large/binary files use blobs.
            if (strlen($bytes) <= 300000 && !str_contains($bytes, "\0") && preg_match('//u', $bytes)) {
                $item['content'] = $bytes;
            } else {
                $blob = self::gh(
                    'POST',
                    self::repo_path($s) . '/git/blobs',
                    ['content' => base64_encode($bytes), 'encoding' => 'base64'],
                    $s
                );
                $sha = (string)($blob['sha'] ?? '');
                if ($sha === '') throw new RuntimeException('آپلود Blob برای ' . $entry['path'] . ' ناموفق بود.');
                $item['sha'] = $sha;
            }

            $batch[] = $item;
            if (count($batch) >= 50) $flush();
        }

        foreach ($effectiveDeletions as $path) {
            $batch[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => null];
            if (count($batch) >= 50) $flush();
        }
        $flush();

        if($treeSha===$baseTree) {
            // Commit خالی ساخته نشد؛ هیچ تغییر واقعی برای ثبت وجود ندارد.
            return [
                'commit' => $parent,
                'files' => count($entries),
                'uploaded' => 0,
                'skipped' => count($entries),
                'deleted' => 0,
                'changed' => false,
            ];
        }

        $newCommit = self::gh('POST', self::repo_path($s) . '/git/commits', [
            'message' => 'BlueVPN bot update ' . gmdate('Y-m-d H:i:s') . ' UTC',
            'tree' => $treeSha,
            'parents' => [$parent],
        ], $s);
        $newSha = (string)($newCommit['sha'] ?? '');
        if ($newSha === '') throw new RuntimeException('Commit GitHub ساخته نشد.');

        $updatedRef = self::gh('PATCH',
            self::repo_path($s) . '/git/refs/heads/' . rawurlencode((string)$s['git_branch']),
            ['sha' => $newSha, 'force' => false],
            $s
        );
        $patchedSha = (string)($updatedRef['object']['sha'] ?? '');
        if ($patchedSha === '' || !hash_equals($newSha, $patchedSha)) {
            self::verify_commit_on_branch($newSha, $s);
        }

        return [
            'commit' => $newSha,
            'files' => count($entries),
            'uploaded' => count($changedEntries),
            'skipped' => count($entries) - count($changedEntries),
            'deleted' => count($effectiveDeletions),
            'changed' => true,
        ];
    }
    private static function extract_zip_safely(string $zipPath, array $s): string {
        $zip = new ZipArchive();
        if (($open = $zip->open($zipPath, ZipArchive::RDONLY)) !== true) throw new RuntimeException('ZIP معتبر نیست (ZipArchive code=' . (string)$open . ', size=' . (string)((int)(@filesize($zipPath) ?: 0)) . ').');
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

    private static function stamp_tapsell_build_config(string $root): void {
        if (!self::is_full_platform_root($root)) return;

        $settings = BlueVPN_DB::settings();
        $appId = trim((string)($settings['tapsell_app_id'] ?? ''));
        if ($appId === '') {
            $legacy = trim((string)($settings['tapsell_app_key'] ?? ''));
            if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $legacy)) {
                $appId = $legacy;
            }
        }
        if ($appId === '') return;

        if (!preg_match('/^[A-Za-z0-9._:-]{8,200}$/', $appId)) {
            throw new RuntimeException('Tapsell Mediation App ID معتبر نیست.');
        }

        $path = $root . '/branding/app.json';
        $raw = json_decode((string)file_get_contents($path), true);
        if (!is_array($raw)) {
            throw new RuntimeException('branding/app.json برای ثبت Tapsell قابل خواندن نیست.');
        }

        $raw['tapsell_app_id'] = $appId;
        $raw['tapsell_mediation_version'] = '1.4.0-alpha03';

        $encoded = wp_json_encode(
            $raw,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if (!is_string($encoded) || file_put_contents($path, $encoded . "\n") === false) {
            throw new RuntimeException('ثبت Tapsell App ID در پروژه ناموفق بود.');
        }
    }

    private static function is_full_platform_root(string $root): bool {
        return is_file($root . '/branding/app.json')
            && is_file($root . '/release.json')
            && is_file($root . '/bluevpn-manager/bluevpn-manager.php');
    }

    private static function repository_junk_path(string $path): bool {
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');
        if ($path === '') return true;
        if (
            $path === '.pytest_cache' ||
            str_starts_with($path, '.pytest_cache/') ||
            str_contains($path, '/__pycache__/') ||
            str_starts_with($path, '__pycache__/') ||
            str_ends_with($path, '.pyc') ||
            str_ends_with($path, '.pyo') ||
            str_ends_with($path, '.log') ||
            str_ends_with($path, '.tmp') ||
            str_ends_with($path, '.temp') ||
            str_starts_with($path, 'reports/')
        ) return true;

        if (!str_contains($path, '/')) {
            if (preg_match('/^BUILD-AND-TEST-.*\.md$/i', $path)) return true;
            if (preg_match('/^CHANGED-FILES-.*\.md$/i', $path)) return true;
            if (preg_match('/^ROOT-CAUSE-.*\.md$/i', $path)) return true;
            if (preg_match('/^BUILD_FIX_.*_FA\.md$/i', $path)) return true;
            if (preg_match('/^FINAL_RELEASE_.*_FA\.md$/i', $path)) return true;
            if (preg_match('/^VALIDATION_.*_FA\.txt$/i', $path)) return true;
            if (preg_match('/^UPDATE-.*\.txt$/i', $path)) return true;
            if ($path === 'NETWORK_RECOVERY_UPDATE.txt') return true;
            if ($path === 'FIX_REPORT_FA.txt') return true;
            if (in_array($path, ['README.txt','README_FA.txt','README_PLATFORM_FA.txt'], true)) return true;
        }
        return false;
    }

    private static function repository_authoritative_files(string $root): array {
        $files = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (
                $rel === '.bluevpn-delete' ||
                str_starts_with($rel, '__MACOSX/') ||
                str_starts_with($rel, '.git/') ||
                self::repository_junk_path($rel) ||
                !self::safe_repo_path($rel) ||
                self::protected_path($rel)
            ) continue;
            $files[$rel] = true;
        }
        return $files;
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

        // GitHub occasionally returns transient 5xx responses from Git Data endpoints
        // (blobs, trees, commits and refs). Retrying only /git/blobs is not enough:
        // a deploy can advance to /git/trees and fail there. Handle transient failures
        // in one place so every GitHub API operation gets the same bounded policy.
        $maxAttempts = 6;
        $fallbackDelays = [2, 5, 10, 20, 35];
        $lastError = '';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $res = wp_remote_request(self::GITHUB_API . $path, $args);

            if (is_wp_error($res)) {
                $lastError = $res->get_error_message();
                if ($attempt < $maxAttempts) {
                    $delay = $fallbackDelays[min($attempt - 1, count($fallbackDelays) - 1)];
                    sleep($delay);
                    continue;
                }
                throw new RuntimeException(
                    'GitHub API ' . strtoupper($method) . ' ' . $path .
                    ' network error after ' . $attempt . ' attempts: ' . $lastError
                );
            }

            $code = (int)wp_remote_retrieve_response_code($res);
            $raw = wp_remote_retrieve_body($res);
            $json = $raw !== '' ? json_decode($raw, true) : [];
            if ($code >= 200 && $code < 300) {
                return is_array($json) ? $json : [];
            }

            $message = is_array($json) ? (string)($json['message'] ?? '') : '';
            $lastError =
                'GitHub API ' . strtoupper($method) . ' ' . $path . ' HTTP ' . $code .
                ($message !== '' ? ': ' . $message : '');

            $headers = wp_remote_retrieve_headers($res);
            $retryAfter = 0;
            if (is_object($headers) && method_exists($headers, 'offsetGet')) {
                $retryAfter = (int)$headers->offsetGet('retry-after');
            } elseif (is_array($headers)) {
                $retryAfter = (int)($headers['retry-after'] ?? 0);
            }

            $messageLower = strtolower($message);
            $isTransient = in_array($code, [408, 425, 429, 500, 502, 503, 504], true);
            if ($code === 403 && (
                $retryAfter > 0 ||
                str_contains($messageLower, 'secondary rate limit') ||
                str_contains($messageLower, 'temporarily unavailable')
            )) {
                $isTransient = true;
            }

            if (!$isTransient || $attempt >= $maxAttempts) {
                throw new RuntimeException(
                    $lastError .
                    ($isTransient ? ' (retry budget exhausted after ' . $attempt . ' attempts)' : '')
                );
            }

            $fallback = $fallbackDelays[min($attempt - 1, count($fallbackDelays) - 1)];
            $delay = $retryAfter > 0 ? min(60, max(1, $retryAfter)) : $fallback;
            // Small jitter avoids immediately colliding with another deploy retry.
            try {
                $delay += random_int(0, 2);
            } catch (Throwable $ignored) {}
            sleep($delay);
        }

        throw new RuntimeException($lastError !== '' ? $lastError : 'GitHub API request failed.');
    }

    private static function verify_commit_on_branch(string $commitSha, array $s): void {
        $lastHead = '';
        $repo = self::repo_path($s);
        $branch = rawurlencode((string)$s['git_branch']);
        for ($attempt = 0; $attempt < 8; $attempt++) {
            try {
                $ref = self::gh('GET', $repo . '/git/ref/heads/' . $branch, null, $s);
                $head = (string)($ref['object']['sha'] ?? '');
                if ($head !== '') {
                    $lastHead = $head;
                    if (hash_equals($commitSha, $head)) return;

                    // Another writer may move HEAD forward immediately after our
                    // successful ref update. In that case our commit is still a
                    // valid deployment if it is an ancestor of current HEAD.
                    $compare = self::gh(
                        'GET',
                        $repo . '/compare/' . rawurlencode($commitSha) . '...' . rawurlencode($head),
                        null,
                        $s
                    );
                    $status = (string)($compare['status'] ?? '');
                    if (in_array($status, ['ahead', 'identical'], true)) return;
                }
            } catch (Throwable $e) {
                // A short GitHub read-after-write/API propagation delay must not
                // turn a successful PATCH into a false deployment failure.
            }
            if ($attempt < 7) usleep(250000 + ($attempt * 100000));
        }

        throw new RuntimeException(
            'Commit روی شاخه GitHub تأیید نشد.' .
            ($lastHead !== '' ? ' HEAD=' . substr($lastHead, 0, 12) : '')
        );
    }

    private static function branch_head_sha(array $s): string {
        $gitError = '';
        if (self::git_cli_available()) {
            try {
                $sha = self::git_remote_head($s);
                if ($sha !== '') return $sha;
            } catch (Throwable $e) {
                $gitError = self::redact($e->getMessage(), $s);
            }
        }
        try {
            $ref = self::gh('GET', self::repo_path($s) . '/git/ref/heads/' . rawurlencode((string)$s['git_branch']), null, $s);
            $sha = (string)($ref['object']['sha'] ?? '');
            if ($sha === '') throw new RuntimeException('SHA شاخه GitHub پیدا نشد.');
            return $sha;
        } catch (Throwable $e) {
            if ($gitError !== '') {
                throw new RuntimeException("خواندن HEAD با هر دو روش ناموفق بود.\nGit HTTPS: " . $gitError . "\nGitHub REST: " . self::redact($e->getMessage(), $s));
            }
            throw $e;
        }
    }

    private static function dispatch_build(string $commitSha, array $s): array {
        $repositoryError = '';
        try {
            $requestId = bin2hex(random_bytes(12));
            self::gh('POST', self::repo_path($s) . '/dispatches', [
                'event_type' => (string)$s['repository_dispatch_event'],
                'client_payload' => [
                    'target_sha' => $commitSha,
                    'ref' => (string)$s['git_branch'],
                    'request_id' => $requestId,
                    'source' => 'bluevpn-wordpress-bot',
                ],
            ], $s);
            return [
                'trigger' => 'repository_dispatch',
                'request_id' => $requestId,
                'target_sha' => $commitSha,
            ];
        } catch (Throwable $e) {
            $repositoryError = self::redact($e->getMessage(), $s);
        }

        try {
            self::dispatch_workflow($s);
            return [
                'trigger' => 'workflow_dispatch',
                'fallback_from' => 'repository_dispatch',
                'repository_dispatch_error' => $repositoryError,
            ];
        } catch (Throwable $e) {
            $workflowError = self::redact($e->getMessage(), $s);
            throw new RuntimeException(
                "هیچ‌یک از دو روش اجرای Build در GitHub پذیرفته نشد.\n" .
                'repository_dispatch: ' . $repositoryError . "\n" .
                'workflow_dispatch: ' . $workflowError
            );
        }
    }

    private static function dispatch_manager_release(string $commitSha, string $requestId, array $s): void {
        // Fine-grained PATs commonly used by the Deploy Bot already need
        // Contents:write to push the project. GitHub requires Actions:write
        // for workflow_dispatch, but repository_dispatch only needs
        // Contents:write. Use the latter as the primary trigger so Manager
        // self-update does not require a second high-privilege permission.
        $repositoryError = '';
        try {
            self::gh('POST', self::repo_path($s) . '/dispatches', [
                'event_type' => self::MANAGER_REPOSITORY_EVENT,
                'client_payload' => [
                    'target_sha' => $commitSha,
                    'ref' => (string)$s['git_branch'],
                    'request_id' => $requestId,
                    'source' => 'bluevpn-wordpress-bot-manager',
                ],
            ], $s);
            return;
        } catch (Throwable $e) {
            $repositoryError = self::redact($e->getMessage(), $s);
        }

        try {
            self::gh('POST', self::repo_path($s) . '/actions/workflows/' . rawurlencode(self::MANAGER_WORKFLOW) . '/dispatches', [
                'ref' => (string)$s['git_branch'],
                'inputs' => ['target_sha' => $commitSha, 'request_id' => $requestId],
            ], $s);
            return;
        } catch (Throwable $e) {
            $workflowError = self::redact($e->getMessage(), $s);
            throw new RuntimeException(
                "انتشار Manager با هر دو Trigger ناموفق بود.\n" .
                'repository_dispatch (نیازمند Contents:write): ' . $repositoryError . "\n" .
                'workflow_dispatch (نیازمند Actions:write): ' . $workflowError
            );
        }
    }

    private static function start_android_build_for_job(array $job, string $commit, array $s): void {
        self::verify_commit_on_branch($commit,$s);
        $commitPayload=self::gh(
            'GET',
            self::repo_path($s).'/commits/'.rawurlencode($commit),
            null,
            $s
        );
        if(count((array)($commitPayload['files']??[]))===0){
            throw new RuntimeException(
                'DEPLOY_COMMIT_NOT_APPLIED: Commit بدون Diff است؛ Build برای جلوگیری از ساخت نسخه قبلی شروع نشد.'
            );
        }
        $trigger = self::dispatch_build($commit, $s);
        self::update_job((string)$job['id'], ['status' => 'waiting_build', 'run_id' => 0, 'run_url' => '']);
        $triggerLabel = (string)($trigger['trigger'] ?? 'github');
        self::send_message($job['chat_id'], "🛠 Build از GitHub Actions شروع شد.
Commit: <code>" . esc_html(substr($commit, 0, 12)) . "</code>
Trigger: <code>" . esc_html($triggerLabel) . '</code>', self::keyboard(), $s);
        wp_schedule_single_event(time() + 20, self::POLL_HOOK);
        self::spawn_cron();
    }

    private static function dispatch_workflow(array $s): void {
        self::gh('POST', self::repo_path($s) . '/actions/workflows/' . rawurlencode((string)$s['github_workflow']) . '/dispatches', ['ref' => (string)$s['git_branch']], $s);
    }

    public static function poll_builds(): void {
        global $wpdb;
        $t = self::jobs_table();
        $s = self::settings();

        $managerJobs = $wpdb->get_results("SELECT * FROM {$t} WHERE status IN ('waiting_manager','building_manager','updating_manager') ORDER BY created_at ASC LIMIT 10", ARRAY_A);
        foreach ($managerJobs as $job) {
            try {
                if ((string)$job['status'] !== 'updating_manager') {
                    $runs = self::gh('GET', self::repo_path($s) . '/actions/workflows/' . rawurlencode(self::MANAGER_WORKFLOW) . '/runs?branch=' . rawurlencode((string)$s['git_branch']) . '&per_page=50', null, $s);
                    $match = null;
                    $requestMarker = (string)$job['id'];
                    foreach ((array)($runs['workflow_runs'] ?? []) as $run) {
                        if (!is_array($run)) continue;
                        $title = (string)($run['display_title'] ?? $run['name'] ?? '');
                        if ($requestMarker !== '' && strpos($title, $requestMarker) !== false) { $match = $run; break; }
                        // Backward-compatible fallback for a workflow run created
                        // before request_id/run-name correlation existed.
                        if ((string)($run['head_sha'] ?? '') === (string)$job['commit_sha']) { $match = $run; }
                    }
                    if (!$match) continue;
                    $status = (string)($match['status'] ?? '');
                    $runId = (int)($match['id'] ?? 0);
                    $runUrl = esc_url_raw((string)($match['html_url'] ?? ''));
                    if ($status !== 'completed') {
                        self::update_job((string)$job['id'], ['status' => 'building_manager', 'run_id' => $runId, 'run_url' => $runUrl]);
                        continue;
                    }
                    $conclusion = (string)($match['conclusion'] ?? 'unknown');
                    if ($conclusion !== 'success') {
                        self::update_job((string)$job['id'], ['status' => 'failed', 'run_id' => $runId, 'run_url' => $runUrl, 'last_error' => 'Manager release: ' . $conclusion, 'finished_at' => BlueVPN_Utils::now_mysql()]);
                        self::send_message($job['chat_id'], "❌ انتشار Manager ناموفق بود.\nنتیجه: <code>" . esc_html($conclusion) . '</code>' . ($runUrl ? "\n<a href=\"" . esc_url($runUrl) . '\">مشاهده Log</a>' : ''), self::keyboard(), $s);
                        continue;
                    }
                    self::update_job((string)$job['id'], ['status' => 'updating_manager', 'run_id' => $runId, 'run_url' => $runUrl]);
                }

                if (!class_exists('BlueVPN_GitHub_Updater') || !method_exists('BlueVPN_GitHub_Updater', 'install_latest_now')) {
                    throw new RuntimeException('Updater داخلی BlueVPN Manager در دسترس نیست.');
                }
                $installed = BlueVPN_GitHub_Updater::install_latest_now();
                if (empty($installed['success'])) throw new RuntimeException((string)($installed['message'] ?? 'نصب Manager ناموفق بود.'));
                $installedVersion = (string)($installed['installed_version'] ?? '');
                $target = (string)($installed['target'] ?? '');
                self::send_message($job['chat_id'], "✅ <b>BlueVPN Manager نصب شد</b>
نسخه نصب‌شده: <code>" . esc_html($installedVersion ?: $target) . "</code>
منبع: GitHub Release تأییدشده", self::keyboard(), $s);

                if ((string)$job['kind'] === 'manager_update') {
                    self::update_job((string)$job['id'], ['status' => 'success', 'finished_at' => BlueVPN_Utils::now_mysql(), 'last_error' => '']);
                    continue;
                }
                $freshJob = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%s", (string)$job['id']), ARRAY_A) ?: $job;
                self::start_android_build_for_job($freshJob, (string)$job['commit_sha'], $s);
            } catch (Throwable $e) {
                $attempts = (int)$job['attempts'] + 1;
                self::update_job((string)$job['id'], ['attempts' => $attempts, 'last_error' => self::redact($e->getMessage(), $s)]);
                if ($attempts >= 5) self::fail_job($job, 'نصب خودکار Manager: ' . $e->getMessage(), $s);
            }
        }

        $jobs = $wpdb->get_results("SELECT * FROM {$t} WHERE status IN ('waiting_build','building') ORDER BY created_at ASC LIMIT 10", ARRAY_A);
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
        self::send_message($job['chat_id'], "❌ عملیات ناموفق بود.\nRuntime: <code>v" . esc_html(BLUEVPN_MANAGER_VERSION) . "</code>\n<code>" . esc_html(mb_substr($error, -3000)) . '</code>', self::keyboard(), $s);
    }

    private static function redact(string $text, array $s): string {
        foreach ([self::bot_token($s), self::github_token($s)] as $secret) if ($secret !== '') $text = str_replace($secret, '***', $text);
        return $text;
    }

    private static function send_status($chatId, array $s): void {
        global $wpdb;
        $t = self::jobs_table();
        $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE chat_id=%s ORDER BY created_at DESC LIMIT 1", (string)$chatId), ARRAY_A);
        $lines = ["📊 <b>وضعیت BlueVPN Bot</b>", 'Runtime: ' . (self::runtime_ready() ? '✅ آماده' : '❌ ناقص'), 'Version: <code>' . esc_html(BLUEVPN_MANAGER_VERSION) . '</code>', 'Upload transport: <code>' . (self::git_cli_available() ? 'git_https' : 'rest_fallback') . '</code>', 'Webhook: <code>' . esc_html((string)$s['webhook_status']) . '</code>', 'Repository: <code>' . esc_html((string)$s['github_repository']) . '</code>'];
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
        $jobs = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE chat_id=%s AND status IN ('queued','downloading','deploying','dispatching','waiting_manager','building_manager','updating_manager','waiting_build','building')", (string)$chatId), ARRAY_A);
        $cancelledRuns = 0;
        $skippedRuns = 0;
        foreach ($jobs as $job) {
            if ((int)$job['run_id'] > 0) {
                $runId = (int)$job['run_id'];
                $runPath = self::repo_path($s) . '/actions/runs/' . $runId;
                try {
                    $run = self::gh('GET', $runPath, null, $s);
                    $runStatus = strtolower((string)($run['status'] ?? ''));
                    if ($runStatus !== 'completed') {
                        $cancelPath = $runPath . '/cancel';
                        $cancelUrl = self::GITHUB_API . $cancelPath;
                        if (class_exists('BlueVPN_Error_Monitor')) {
                            // GitHub can legitimately answer 403/404/409 when a run is no
                            // longer cancellable or the token is read-only. Prevent the
                            // global HTTP hook from misclassifying this best-effort control
                            // request; classify it explicitly below instead.
                            BlueVPN_Error_Monitor::expect_http_status_once($cancelUrl, [403, 404, 409]);
                        }
                        try {
                            self::gh('POST', $cancelPath, [], $s);
                            $cancelledRuns++;
                        } catch (Throwable $cancelError) {
                            $msg = $cancelError->getMessage();
                            if (str_contains($msg, 'HTTP 403')) {
                                $skippedRuns++;
                                if (class_exists('BlueVPN_Error_Monitor')) {
                                    BlueVPN_Error_Monitor::report('github', 'actions_cancel', 'notice', 'GITHUB_CANCEL_NOT_PERMITTED', 'لغو GitHub Actions انجام نشد؛ Token ممکن است Actions: write نداشته باشد یا Run دیگر قابل لغو نباشد.', [
                                        'run_id' => $runId,
                                        'run_status' => $runStatus,
                                    ]);
                                }
                            } elseif (str_contains($msg, 'HTTP 404') || str_contains($msg, 'HTTP 409')) {
                                $skippedRuns++;
                            } else {
                                throw $cancelError;
                            }
                        }
                    } else {
                        $skippedRuns++;
                    }
                } catch (Throwable $e) {
                    if (class_exists('BlueVPN_Error_Monitor')) {
                        BlueVPN_Error_Monitor::report('github', 'actions_cancel', 'warning', 'GITHUB_CANCEL_CHECK_FAILED', 'بررسی/لغو Run گیت‌هاب ناموفق بود.', [
                            'run_id' => $runId,
                            'error' => self::redact($e->getMessage(), $s),
                        ]);
                    }
                }
            }
            self::update_job((string)$job['id'], ['status' => 'cancelled', 'finished_at' => BlueVPN_Utils::now_mysql()]);
        }
        self::send_message($chatId, '🔓 قفل عملیات آزاد شد.' . ($cancelledRuns ? "\nGitHub Run لغوشده: <code>{$cancelledRuns}</code>" : '') . ($skippedRuns ? "\nRunهای غیرقابل/غیرنیازمند لغو: <code>{$skippedRuns}</code>" : ''), self::keyboard(), $s);
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
        try { self::branch_head_sha($s); } catch (Throwable $e) { self::admin_redirect('Telegram سالم است اما GitHub خطا داد [v' . BLUEVPN_MANAGER_VERSION . ']: ' . self::redact($e->getMessage(), $s), true); }
        self::admin_redirect('Telegram و GitHub هر دو سالم هستند. Runtime v' . BLUEVPN_MANAGER_VERSION . ' / transport=' . (self::git_cli_available() ? 'git_https' : 'rest_fallback') . ' / @' . sanitize_text_field((string)($me['username'] ?? 'bot')));
    }

    public static function admin_page(): void {
        self::admin_guard(); $s = self::settings();
        BlueVPN_Unified_UI::shell_open('ربات تلگرام');
        echo '<div class="wrap" dir="rtl"><style>.bvb-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:14px;max-width:1200px}.bvb-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:16px}.bvb-ok{color:#087c2c;font-weight:700}.bvb-bad{color:#b32d2e;font-weight:700}.bvb-code{direction:ltr;text-align:left;background:#f6f7f7;padding:9px;border-radius:7px;word-break:break-all}</style>';
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
        foreach ($jobs as $j) echo '<tr><td>' . esc_html((string)$j['kind']) . '</td><td>' . esc_html((string)$j['status']) . '</td><td>' . esc_html((string)$j['chat_id']) . '</td><td><code>' . esc_html(substr((string)$j['commit_sha'],0,12)) . '</code></td><td>' . (!empty($j['run_url']) ? '<a href="' . esc_url((string)$j['run_url']) . '" target="_blank" rel="noopener">GitHub</a>' : '') . '</td><td>' . esc_html(mb_substr((string)$j['last_error'],0,160)) . '</td><td>' . esc_html(BlueVPN_Utils::tehran_datetime_fa($j['created_at'])) . '</td></tr>';
        echo '</table></div>';
        BlueVPN_Unified_UI::shell_close();
    }
}
