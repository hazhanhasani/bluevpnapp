<?php
if (!defined('ABSPATH')) exit;

/**
 * Central observability/error sentinel for the complete BlueVPN WordPress control plane.
 *
 * Goals:
 * - capture BlueVPN PHP/runtime failures without changing normal WordPress error handling;
 * - persist every distinct event in MySQL with occurrence counters;
 * - immediately notify Telegram for first occurrence and controlled repeats;
 * - continuously scan MySQL/queues/providers/cron/update health;
 * - keep secrets and user-sensitive values out of notifications.
 */
final class BlueVPN_Error_Monitor {
    private const OPTION = 'bluevpn_error_monitor_settings';
    private const CRON_HOOK = 'bluevpn_error_monitor_tick';
    private const TABLE = 'error_events';
    private const MENU_SLUG = 'bluevpn-error-monitor';

    private static bool $bootstrapped = false;
    private static bool $handling = false;
    private static bool $notifying = false;
    private static $previousErrorHandler = null;
    private static ?array $lastQuery = null;
    private static array $expectedHttpStatuses = [];

    public static function bootstrap(): void {
        if (self::$bootstrapped) return;
        self::$bootstrapped = true;
        add_filter('cron_schedules', [self::class, 'cron_schedules']);
        add_filter('query', [self::class, 'remember_query'], PHP_INT_MAX);
        self::$previousErrorHandler = set_error_handler([self::class, 'php_error_handler']);
        register_shutdown_function([self::class, 'shutdown_handler']);
    }

    public static function init(): void {
        add_action(self::CRON_HOOK, [self::class, 'health_scan']);
        add_action('init', [self::class, 'ensure_schedule'], 30);
        add_action('http_api_debug', [self::class, 'http_api_debug'], 10, 5);
        add_filter('rest_request_after_callbacks', [self::class, 'rest_after_callbacks'], PHP_INT_MAX, 3);
        add_action('wp_mail_failed', [self::class, 'mail_failed']);
        add_action('automatic_updates_complete', [self::class, 'automatic_updates_complete']);
        add_action('upgrader_process_complete', [self::class, 'upgrader_complete'], 10, 2);
        add_action('rest_api_init', [self::class, 'register_client_route']);
        add_action('admin_post_bluevpn_monitor_action', [self::class, 'admin_action']);
    }

    public static function activate(): void {
        self::ensure_schedule();
        if (get_option(self::OPTION, null) === null) {
            add_option(self::OPTION, self::defaults(), '', false);
        }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function defaults(): array {
        return [
            'enabled' => true,
            'telegram_enabled' => true,
            'capture_php_notices' => true,
            'capture_rest_4xx' => true,
            'capture_http_4xx' => true,
            'health_scan_enabled' => true,
            'dedup_seconds' => 180,
            'repeat_every' => 10,
            'max_context_chars' => 1800,
            'retention_days' => 30,
        ];
    }

    public static function settings(): array {
        $saved = get_option(self::OPTION, []);
        return array_replace(self::defaults(), is_array($saved) ? $saved : []);
    }

    public static function cron_schedules(array $schedules): array {
        $schedules['bluevpn_monitor_minute'] = ['interval' => 60, 'display' => 'BlueVPN Sentinel every minute'];
        return $schedules;
    }

    public static function ensure_schedule(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 45, 'bluevpn_monitor_minute', self::CRON_HOOK);
        }
    }

    private static function table(): string {
        return BlueVPN_DB::table(self::TABLE);
    }

    public static function remember_query(string $query): string {
        if (stripos($query, 'bluevpn_') !== false) {
            self::$lastQuery = ['sql' => self::truncate($query, 1200), 'at' => microtime(true)];
        }
        return $query;
    }

    public static function php_error_handler(int $severity, string $message, string $file = '', int $line = 0): bool {
        $s = self::settings();
        if (empty($s['enabled'])) return false;
        // Respect PHP's @ operator and the active error_reporting mask. Without
        // this guard Sentinel sees intentionally suppressed best-effort cleanup
        // warnings (for example race-safe @unlink calls) and turns them into
        // false runtime incidents even though PHP itself would suppress them.
        if ((error_reporting() & $severity) === 0) return false;
        if (!self::belongs_to_bluevpn($file)) return false;
        $level = self::php_severity($severity);
        if ($level === 'notice' && empty($s['capture_php_notices'])) return false;
        self::report('php', self::component_from_path($file), $level, self::php_error_name($severity), $message, [
            'file' => self::relative_path($file), 'line' => $line,
        ]);
        if (is_callable(self::$previousErrorHandler) && self::$previousErrorHandler !== [self::class, 'php_error_handler']) {
            try { return (bool)call_user_func(self::$previousErrorHandler, $severity, $message, $file, $line); }
            catch (Throwable $ignored) { return false; }
        }
        return false; // Preserve PHP's native handler behavior when no previous custom handler exists.
    }

    public static function shutdown_handler(): void {
        $error = error_get_last();
        if (is_array($error) && in_array((int)($error['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
            $file = (string)($error['file'] ?? '');
            if (self::belongs_to_bluevpn($file)) {
                self::report('php_fatal', self::component_from_path($file), 'critical', self::php_error_name((int)$error['type']), (string)$error['message'], [
                    'file' => self::relative_path($file), 'line' => (int)($error['line'] ?? 0),
                ], true);
            }
        }
        self::report_wpdb_last_error('shutdown');
    }

    /**
     * Mark one HTTP response status as an expected capability-probe fallback.
     * The request is still executed normally; Sentinel only suppresses the
     * explicitly allowed status for this exact URL once. Transport failures,
     * 5xx responses and the final fallback failure remain observable.
     */
    public static function expect_http_status_once(string $url, array $statuses): void {
        $key = hash('sha256', $url);
        $clean = [];
        foreach ($statuses as $status) {
            $status = (int)$status;
            if ($status >= 400 && $status < 500) $clean[$status] = true;
        }
        if ($clean) self::$expectedHttpStatuses[$key] = ['statuses'=>$clean, 'expires'=>microtime(true)+15.0];
    }

    private static function consume_expected_http_status(string $url, int $status): bool {
        $now = microtime(true);
        foreach (self::$expectedHttpStatuses as $key => $row) {
            if (($row['expires'] ?? 0) < $now) unset(self::$expectedHttpStatuses[$key]);
        }
        $key = hash('sha256', $url);
        $row = self::$expectedHttpStatuses[$key] ?? null;
        if (!is_array($row)) return false;
        unset(self::$expectedHttpStatuses[$key]);
        return !empty($row['statuses'][$status]);
    }

    public static function http_api_debug($response, string $context, $class, array $parsedArgs, string $url): void {
        if ($context !== 'response' || self::$notifying) return;
        $host = strtolower((string)wp_parse_url($url, PHP_URL_HOST));
        $headers = $parsedArgs['headers'] ?? [];
        $transientRetryAttempt = false;
        if (is_array($headers)) {
            foreach ($headers as $headerName => $headerValue) {
                $hn = strtolower((string)$headerName);
                if ($hn === 'x-bluevpn-internal-cron' && (string)$headerValue === '1') return;
                // Used only for non-authoritative fallback probes where failure is
                // expected to degrade to cached/DB metadata instead of becoming a
                // runtime incident. Authoritative/provider calls must not set it.
                if ($hn === 'x-bluevpn-sentinel-ignore' && (string)$headerValue === '1') return;
                // Retry-capable callers mark all non-final attempts. Sentinel keeps
                // the final exhausted failure visible while avoiding one alert per
                // transient 5xx/network retry.
                if ($hn === 'x-bluevpn-sentinel-transient' && (string)$headerValue === '1') $transientRetryAttempt = true;
            }
        }
        if ($host === 'api.telegram.org') return; // Prevent alert recursion if Telegram itself is unavailable.
        $s = self::settings();
        if (empty($s['enabled'])) return;
        $component = self::http_component($host, $url);
        if (is_wp_error($response)) {
            if (self::consume_expected_http_status($url, 0) || $transientRetryAttempt) return;
            self::report('http', $component, 'error', (string)$response->get_error_code(), $response->get_error_message(), [
                'host' => $host, 'url' => self::safe_url($url), 'method' => strtoupper((string)($parsedArgs['method'] ?? 'GET')),
            ]);
            return;
        }
        $status = is_array($response) ? (int)wp_remote_retrieve_response_code($response) : 0;
        if (self::consume_expected_http_status($url, $status)) return;
        if ($transientRetryAttempt && in_array($status, [408, 425, 429, 500, 502, 503, 504], true)) return;
        if ($status >= 500 || ($status >= 400 && !empty($s['capture_http_4xx']))) {
            self::report('http', $component, $status >= 500 ? 'error' : 'warning', 'HTTP_' . $status, 'درخواست HTTP با وضعیت ناموفق برگشت.', [
                'host' => $host, 'url' => self::safe_url($url), 'method' => strtoupper((string)($parsedArgs['method'] ?? 'GET')),
            ]);
        }
    }

    public static function rest_after_callbacks($response, array $handler, WP_REST_Request $request) {
        if (self::$handling) return $response;
        $route = (string)$request->get_route();
        if (!str_starts_with($route, '/bluevpn/') && !str_starts_with($route, '/bluevpn-system/') && !str_starts_with($route, '/bluevpn-bot/')) return $response;
        $status = 200;
        $code = '';
        $message = '';
        if (is_wp_error($response)) {
            $code = (string)$response->get_error_code();
            $message = $response->get_error_message();
            $data = $response->get_error_data();
            $status = is_array($data) && isset($data['status']) ? (int)$data['status'] : 500;
        } elseif ($response instanceof WP_REST_Response) {
            $status = $response->get_status();
            $data = $response->get_data();
            if (is_array($data)) {
                $detail = is_array($data['detail'] ?? null) ? $data['detail'] : [];
                $code = sanitize_key((string)($detail['code'] ?? ''));
                $message = sanitize_text_field((string)($detail['message'] ?? ''));
            }
        }
        // Signed CI callers mark non-final retries. Keep the final failure visible
        // while suppressing duplicate transient REST incidents from earlier attempts.
        $transientRetryAttempt = (string)$request->get_header('x-bluevpn-sentinel-transient') === '1';
        if ($transientRetryAttempt && in_array($status, [408,425,429,500,502,503,504], true)) return $response;

        // A verify miss means the location discovery pipeline has not learned this
        // route yet. The client is expected to continue safely; it is not a runtime
        // incident and can otherwise flood Sentinel while the route is being learned.
        if ($route === '/bluevpn/v1/server-locations/verify' && $status === 404 && $code === 'server_location_not_found') return $response;

        // Validation failures caused by user input are normal API outcomes, not
        // infrastructure incidents. Keep real auth/provider/control-plane failures
        // observable while preventing expected 4xx validation (notably EMAIL_INVALID)
        // from repeatedly paging Sentinel.
        if (self::expected_rest_client_outcome($status, $code)) return $response;
        $s = self::settings();
        if ($status >= 500 || ($status >= 400 && !empty($s['capture_rest_4xx']))) {
            self::report('rest', 'api', $status >= 500 ? 'error' : 'warning', $code !== '' ? $code : 'HTTP_' . $status, $message !== '' ? $message : 'REST API پاسخ ناموفق داد.', [
                'route' => $route, 'method' => $request->get_method(), 'status' => $status,
            ]);
        }
        return $response;
    }

    private static function expected_rest_client_outcome(int $status, string $code): bool {
        $code = strtolower(trim($code));
        if (!in_array($status, [400, 413, 422], true) || $code === '') return false;
        return in_array($code, [
            'email_invalid',
            'weak_password',
            'password_too_long',
            'phone_invalid',
            'device_id_required',
            'otp_invalid_format',
            'support_empty_message',
            'support_message_too_long',
            'support_attachment_too_large',
            'support_attachment_invalid',
            'support_attachment_type',
            'server_location_key_invalid',
            'server_country_invalid',
        ], true);
    }

    public static function client_token(?int $bucket = null): string {
        $bucket = $bucket ?? (int)floor(time() / 600);
        return hash_hmac('sha256', $bucket . '|' . home_url('/'), wp_salt('nonce'));
    }

    public static function register_client_route(): void {
        register_rest_route('bluevpn-system/v1', '/monitor/client-error', [
            'methods' => 'POST', 'callback' => [self::class, 'client_error'], 'permission_callback' => '__return_true',
        ]);
    }

    public static function client_error(WP_REST_Request $request): WP_REST_Response {
        $body = $request->get_json_params();
        if (!is_array($body)) return new WP_REST_Response(null, 204);
        $token = (string)($body['token'] ?? '');
        $valid = false;
        $bucket = (int)floor(time() / 600);
        foreach ([$bucket, $bucket - 1] as $b) if ($token !== '' && hash_equals(self::client_token($b), $token)) { $valid = true; break; }
        if (!$valid) return new WP_REST_Response(null, 204);

        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $rateKey = 'bluevpn_clienterr_' . substr(hash_hmac('sha256', $ip ?: 'unknown', wp_salt('auth')), 0, 24);
        $count = (int)get_transient($rateKey);
        if ($count >= 12) return new WP_REST_Response(null, 204);
        set_transient($rateKey, $count + 1, 10 * MINUTE_IN_SECONDS);

        $file = esc_url_raw((string)($body['file'] ?? ''));
        $page = esc_url_raw((string)($body['page'] ?? ''));
        $siteHost = strtolower((string)wp_parse_url(home_url('/'), PHP_URL_HOST));
        if ($file !== '') {
            $fileHost = strtolower((string)wp_parse_url($file, PHP_URL_HOST));
            $path = strtolower((string)wp_parse_url($file, PHP_URL_PATH));
            if ($fileHost !== '' && $fileHost !== $siteHost) return new WP_REST_Response(null, 204);
            if (!str_contains($path, 'bluevpn-site') && !str_contains($path, 'bluevpn-manager')) return new WP_REST_Response(null, 204);
        }
        if ($page !== '' && strtolower((string)wp_parse_url($page, PHP_URL_HOST)) !== $siteHost) return new WP_REST_Response(null, 204);

        $kind = sanitize_key((string)($body['kind'] ?? 'js_error'));
        $message = sanitize_text_field((string)($body['message'] ?? 'JavaScript runtime error'));
        $stack = self::truncate(sanitize_textarea_field((string)($body['stack'] ?? '')), 1800);
        // Chromium may reject a superseded Navigation/View Transition with no
        // source location or stack. It is browser lifecycle noise, not a panel
        // runtime failure. Keep this server-side guard for clients with cached JS.
        $normalizedMessage = strtolower(trim($message));
        if ($kind === 'unhandledrejection' && $stack === '' &&
            in_array($normalizedMessage, [
                'transition was aborted because of invalid state',
                'transition aborted because of invalid state',
                'view transition was aborted because of invalid state',
                'navigation transition was aborted because of invalid state',
            ], true)) {
            return new WP_REST_Response(null, 204);
        }
        $component = str_contains(strtolower($file), 'bluevpn-manager') || str_contains(strtolower($page), '/wp-admin/') ? 'admin_ui' : 'site_theme';
        self::report('browser', $component, 'warning', $kind === 'unhandledrejection' ? 'JS_UNHANDLED_REJECTION' : 'JS_RUNTIME_ERROR', $message, [
            'file' => $file, 'line' => (int)($body['line'] ?? 0), 'column' => (int)($body['column'] ?? 0), 'page' => $page, 'stack' => $stack,
        ]);
        return new WP_REST_Response(['ok' => true], 202);
    }

    public static function mail_failed(WP_Error $error): void {
        self::report('wordpress', 'mail', 'warning', (string)$error->get_error_code(), $error->get_error_message(), []);
    }

    public static function automatic_updates_complete(array $results): void {
        self::scan_for_wp_errors($results, 'auto_update', 'updater');
    }

    public static function upgrader_complete($upgrader, array $hookExtra): void {
        $result = null;
        if (is_object($upgrader) && isset($upgrader->skin) && is_object($upgrader->skin) && property_exists($upgrader->skin, 'result')) {
            $result = $upgrader->skin->result;
        }
        if (is_wp_error($result)) {
            self::report('wordpress', 'updater', 'error', (string)$result->get_error_code(), $result->get_error_message(), ['action' => $hookExtra['action'] ?? '', 'type' => $hookExtra['type'] ?? '']);
        }
    }

    private static function scan_for_wp_errors($value, string $source, string $component, string $path = ''): void {
        if (is_wp_error($value)) {
            self::report($source, $component, 'error', (string)$value->get_error_code(), $value->get_error_message(), ['path' => $path]);
            return;
        }
        if (!is_array($value)) return;
        foreach ($value as $key => $item) self::scan_for_wp_errors($item, $source, $component, $path . '/' . (string)$key);
    }

    public static function health_scan(): void {
        $settings = self::settings();
        if (empty($settings['enabled']) || empty($settings['health_scan_enabled'])) return;
        self::report_wpdb_last_error('health_scan_start');

        try {
            $db = BlueVPN_DB::status();
            if (empty($db['ready'])) {
                self::report('database', 'schema', 'critical', 'DB_SCHEMA_DEGRADED', 'جدول‌های موردنیاز BlueVPN کامل نیستند.', ['missing_tables' => $db['missing_tables'] ?? []]);
            }
            if ((string)($db['schema_version'] ?? '') !== BLUEVPN_MANAGER_SCHEMA_VERSION) {
                self::report('database', 'schema', 'error', 'DB_SCHEMA_VERSION_MISMATCH', 'نسخه Schema دیتابیس با Manager هماهنگ نیست.', ['expected' => BLUEVPN_MANAGER_SCHEMA_VERSION, 'actual' => $db['schema_version'] ?? '']);
            }
        } catch (Throwable $e) {
            self::report_exception($e, 'database', 'schema', 'DB_HEALTH_EXCEPTION');
        }

        try {
            if (class_exists('BlueVPN_Production')) {
                // Sentinel itself is already running, so WP-Cron is alive enough
                // to perform a bounded recovery attempt for a stale daily backup.
                BlueVPN_Production::recover_stale_backup_if_needed();
                $health = BlueVPN_Production::health_summary();
                foreach ((array)($health['checks'] ?? []) as $name => $row) {
                    $component = (string)$name;
                    if (!empty($row['ok'])) {
                        self::resolve_health_component($component);
                        continue;
                    }
                    $severity = self::normalize_severity((string)($row['severity'] ?? 'warning'));
                    $code = (string)($row['code'] ?? 'HEALTH_CHECK_FAILED');
                    self::report('health', $component, $severity, $code, (string)($row['message'] ?? 'Health check failed'), $row);
                }
            }
        } catch (Throwable $e) {
            self::report_exception($e, 'health', 'production', 'PRODUCTION_HEALTH_EXCEPTION');
        }

        self::scan_operational_tables();
        self::scan_cron();
        self::scan_error_options();
        self::cleanup();
        self::report_wpdb_last_error('health_scan_end');
    }

    public static function resolve_matching(string $source, string $component, string $code = ''): void {
        global $wpdb;
        $source = sanitize_key($source);
        $component = sanitize_key($component);
        $code = strtoupper(substr(preg_replace('/[^A-Z0-9_.:-]+/i', '_', $code) ?: '', 0, 100));
        if ($source === '' || $component === '') return;
        $now = BlueVPN_Utils::now_mysql();
        if ($code !== '') {
            $wpdb->query($wpdb->prepare(
                "UPDATE " . self::table() . " SET status='resolved', resolved_at=%s, updated_at=%s WHERE source=%s AND component=%s AND code=%s AND status='open'",
                $now, $now, $source, $component, $code
            ));
            return;
        }
        $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET status='resolved', resolved_at=%s, updated_at=%s WHERE source=%s AND component=%s AND status='open'",
            $now, $now, $source, $component
        ));
    }

    private static function resolve_health_component(string $component): void {
        global $wpdb;
        $component = sanitize_key($component);
        if ($component === '') return;
        $now = BlueVPN_Utils::now_mysql();
        $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET status='resolved', resolved_at=%s, updated_at=%s WHERE source='health' AND component=%s AND status='open'",
            $now, $now, $component
        ));
    }

    private static function operational_row_key(string $logical, array $row, string $message = ''): string {
        $id = (string)($row['id'] ?? '');
        $status = (string)($row['status'] ?? '');
        if ($message === '') $message = (string)($row['error_message'] ?? $row['last_error'] ?? '');
        $created = (string)($row['created_at'] ?? '');
        return 'bluevpn_opseen_' . substr(hash('sha256', implode('|', [$logical, $id, $status, self::normalize_for_fingerprint($message), $created])), 0, 36);
    }

    private static function operational_row_should_report(string $logical, array $row, string $message = ''): bool {
        $key = self::operational_row_key($logical, $row, $message);
        if (get_transient($key)) return false;
        set_transient($key, '1', 2 * DAY_IN_SECONDS);
        return true;
    }

    /** Report a failed Deploy Bot job immediately, while keeping the periodic
     * operational scan as recovery only. The same immutable failed row will not
     * increment Sentinel occurrences once per minute anymore. */
    public static function report_bot_job_failure(array $job, string $message): void {
        $job['status'] = 'failed';
        $job['last_error'] = $message;
        self::operational_row_should_report('bot_jobs', $job, $message);
        self::report('runtime', 'deploy_bot', 'error', 'BOT_JOB_FAILED', $message !== '' ? $message : 'عملیات Deploy Bot ناموفق شد.', $job);
    }

    private static function scan_operational_tables(): void {
        global $wpdb;
        $since = gmdate('Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS);
        $oldSending = gmdate('Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS);
        $checks = [
            ['provisioning_attempts', 'provisioning', 'PROVISIONING_FAILED', 'error'],
            ['sms_deliveries', 'sms', 'SMS_DELIVERY_FAILED', 'warning'],
            ['bot_jobs', 'deploy_bot', 'BOT_JOB_FAILED', 'error'],
        ];
        foreach ($checks as $c) {
            [$logical, $component, $code, $severity] = $c;
            $table = BlueVPN_DB::table($logical);
            if ($logical === 'sms_deliveries') {
                $query = $wpdb->prepare("SELECT id,event_key,status,last_error,created_at FROM {$table} WHERE (status='failed' OR (status='sending' AND sending_started_at<%s)) AND created_at>=%s ORDER BY created_at DESC LIMIT 20", $oldSending, $since);
            } elseif ($logical === 'provisioning_attempts') {
                $query = $wpdb->prepare("SELECT id,order_id,status,error_message,created_at FROM {$table} WHERE status IN ('failed','error') AND created_at>=%s ORDER BY id DESC LIMIT 20", $since);
            } else {
                $query = $wpdb->prepare("SELECT id,kind,status,last_error,created_at FROM {$table} WHERE status IN ('failed','error') AND created_at>=%s ORDER BY created_at DESC LIMIT 20", $since);
            }
            $rows = $wpdb->get_results($query, ARRAY_A);
            if ($wpdb->last_error) { self::report_wpdb_last_error('scan_' . $logical); continue; }
            foreach ((array)$rows as $row) {
                $msg = (string)($row['error_message'] ?? $row['last_error'] ?? 'عملیات ناموفق ثبت شده است.');
                // A deploy_zip row with "Build: <conclusion>" is a downstream mirror of
                // the GitHub Actions result. The workflow Sentinel and Telegram bot have
                // already reported that failure with the actionable job/log URL, so a
                // second BOT_JOB_FAILED event only creates duplicate incident noise.
                // Other Deploy Bot failures still flow through Sentinel normally.
                if ($logical === 'bot_jobs'
                    && (string)($row['kind'] ?? '') === 'deploy_zip'
                    && preg_match('/^Build:\s*(?:failure|cancelled|timed_out|action_required|startup_failure|stale)\b/i', trim($msg))) {
                    continue;
                }
                if (!self::operational_row_should_report($logical, $row, $msg)) continue;
                self::report('runtime', $component, $severity, $code, $msg !== '' ? $msg : 'عملیات ناموفق ثبت شده است.', $row);
            }
        }

        foreach (['pasarguard_panels','marzban_panels','guardcore_panels'] as $logical) {
            $table = BlueVPN_DB::table($logical);
            $rows = $wpdb->get_results("SELECT id,name,last_test_message,last_test_at FROM {$table} WHERE active=1 AND last_test_at IS NOT NULL AND last_test_ok=0 ORDER BY last_test_at DESC LIMIT 20", ARRAY_A);
            if ($wpdb->last_error) { self::report_wpdb_last_error('scan_' . $logical); continue; }
            foreach ((array)$rows as $row) self::report('provider', $logical, 'warning', 'PANEL_HEALTH_FAILED', (string)($row['last_test_message'] ?: 'تست پنل ناموفق است.'), $row);
        }

        $sources = BlueVPN_DB::table('free_config_sources');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id,title,last_status,last_error,last_fetch_at FROM {$sources} WHERE enabled=1 AND last_error<>'' AND (last_fetch_at IS NULL OR last_fetch_at>=%s) ORDER BY id DESC LIMIT 20", $since), ARRAY_A);
        foreach ((array)$rows as $row) self::report('runtime', 'free_sources', 'warning', 'FREE_SOURCE_FAILED', (string)$row['last_error'], $row);

        $orders = BlueVPN_DB::table('orders');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id,order_code,status,activation_error,created_at FROM {$orders} WHERE activation_error<>'' AND created_at>=%s ORDER BY created_at DESC LIMIT 20", $since), ARRAY_A);
        foreach ((array)$rows as $row) self::report('runtime', 'payments', 'error', 'ORDER_ACTIVATION_ERROR', (string)$row['activation_error'], $row);
    }

    private static function scan_cron(): void {
        if (!function_exists('_get_cron_array')) return;
        $cron = _get_cron_array();
        if (!is_array($cron)) return;
        $now = time();
        foreach ($cron as $timestamp => $hooks) {
            if ((int)$timestamp >= $now - 900) continue;
            foreach ((array)$hooks as $hook => $instances) {
                if (!str_starts_with((string)$hook, 'bluevpn_')) continue;
                self::report('cron', 'wordpress', 'warning', 'CRON_EVENT_OVERDUE', 'یک Cron مربوط به BlueVPN بیش از پانزده دقیقه عقب افتاده است.', ['hook' => $hook, 'scheduled_at' => gmdate('c', (int)$timestamp), 'delay_seconds' => $now - (int)$timestamp]);
            }
        }
    }

    private static function option_has_explicit_error_signal(string $name, $value): bool {
        $name = sanitize_key($name);
        // Sentinel's own settings contain the word "error" in the option name.
        // They are configuration, not an incident. 5.1.6 used to report this
        // option every health scan and notify again on occurrences 10, 20, ... .
        if ($name === self::OPTION) return false;

        if (is_object($value)) $value = (array)$value;
        if (is_array($value)) {
            foreach (['error','last_error','error_message','exception','fatal'] as $key) {
                if (!array_key_exists($key, $value)) continue;
                $signal = trim(is_scalar($value[$key]) ? (string)$value[$key] : wp_json_encode($value[$key]));
                if ($signal !== '' && !in_array(strtolower($signal), ['0','false','none','null','[]','{}'], true)) return true;
            }
            $status = strtolower(trim((string)($value['status'] ?? '')));
            return in_array($status, ['error','failed','failure','critical','fatal'], true);
        }

        // Scalar options such as bluevpn_*_last_error remain observable.
        $text = trim((string)$value);
        return $text !== '' && !in_array(strtolower($text), ['0','false','none','null','[]','{}'], true);
    }

    private static function scan_error_options(): void {
        global $wpdb;

        // Close the 5.1.6 false-positive incident immediately after upgrade so
        // its occurrence counter cannot keep producing repeat notifications.
        self::resolve_matching('wordpress_option', 'control_plane', 'BLUEVPN_ERROR_MONITOR_SETTINGS');

        $rows = $wpdb->get_results("SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE 'bluevpn\\_%error%' ESCAPE '\\\\' AND option_value NOT IN ('','0','false','[]','{}') LIMIT 50", ARRAY_A);
        foreach ((array)$rows as $row) {
            $name = (string)($row['option_name'] ?? '');
            $value = maybe_unserialize($row['option_value'] ?? '');
            if (!self::option_has_explicit_error_signal($name, $value)) continue;
            if (is_array($value) || is_object($value)) $value = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $text = trim((string)$value);
            if ($text === '') continue;
            self::report('wordpress_option', 'control_plane', 'warning', strtoupper(sanitize_key($name)), $text, ['option' => $name]);
        }
    }

    private static function report_wpdb_last_error(string $scope): void {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) return;
        $error = trim((string)($wpdb->last_error ?? ''));
        if ($error === '') return;
        $query = self::$lastQuery['sql'] ?? self::truncate((string)($wpdb->last_query ?? ''), 1200);
        $query = self::safe_sql($query);
        if ($query !== '' && stripos($query, 'bluevpn_') === false) return;
        self::report('database', 'mysql', 'error', 'WPDB_QUERY_ERROR', $error, ['scope' => $scope, 'query' => $query]);
    }

    public static function legacy_error_log(string $message): void {
        error_log($message);
        self::report('legacy_log', 'plugin', 'error', 'BLUEVPN_ERROR_LOG', $message, []);
    }

    public static function report_exception(Throwable $e, string $source, string $component, string $code = 'UNCAUGHT_EXCEPTION'): void {
        self::report($source, $component, 'error', $code, $e->getMessage(), ['exception' => get_class($e), 'file' => self::relative_path($e->getFile()), 'line' => $e->getLine()]);
    }

    public static function report(string $source, string $component, string $severity, string $code, string $message, array $context = [], bool $forceNotify = false): void {
        if (self::$handling) return;
        self::$handling = true;
        try {
            $settings = self::settings();
            if (empty($settings['enabled'])) return;
            $source = sanitize_key($source ?: 'runtime');
            $component = sanitize_key($component ?: 'unknown');
            $severity = self::normalize_severity($severity);
            $code = strtoupper(substr(preg_replace('/[^A-Z0-9_.:-]+/i', '_', $code ?: 'UNKNOWN') ?: 'UNKNOWN', 0, 100));
            $message = self::sanitize_message($message);
            if ($message === '') $message = 'خطای بدون پیام ثبت شد.';
            $safeContext = self::sanitize_context($context);
            $normalized = self::normalize_for_fingerprint($message);
            $fingerprint = hash('sha256', implode('|', [$source, $component, $severity, $code, $normalized, (string)($safeContext['host'] ?? ''), (string)($safeContext['method'] ?? ''), (string)($safeContext['file'] ?? ''), (string)($safeContext['line'] ?? ''), (string)($safeContext['route'] ?? '')]));
            $now = BlueVPN_Utils::now_mysql();
            $event = self::upsert_event($fingerprint, $source, $component, $severity, $code, $message, $safeContext, $now);
            $should = $forceNotify || self::should_notify($event, $settings);
            if ($should && !empty($settings['telegram_enabled'])) {
                self::notify($event, $safeContext);
                self::mark_notified((int)($event['id'] ?? 0), $now);
            }
        } catch (Throwable $ignored) {
            // Monitoring must never break production. Native error_log is the last fallback.
            error_log('BlueVPN Sentinel internal failure: ' . $ignored->getMessage());
        } finally {
            self::$handling = false;
        }
    }

    private static function upsert_event(string $fingerprint, string $source, string $component, string $severity, string $code, string $message, array $context, string $now): array {
        global $wpdb;
        $table = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE fingerprint=%s AND status='open' LIMIT 1", $fingerprint), ARRAY_A);
        if (is_array($row)) {
            $count = max(1, (int)$row['occurrences']) + 1;
            $wpdb->update($table, ['occurrences' => $count, 'last_seen_at' => $now, 'message' => $message, 'context_json' => BlueVPN_Utils::json_encode($context), 'updated_at' => $now], ['id' => (int)$row['id']]);
            $row['occurrences'] = $count; $row['last_seen_at'] = $now; $row['message'] = $message; $row['context_json'] = BlueVPN_Utils::json_encode($context);
            return $row;
        }
        $wpdb->insert($table, [
            'fingerprint' => $fingerprint, 'source' => $source, 'component' => $component, 'severity' => $severity, 'code' => $code,
            'message' => $message, 'context_json' => BlueVPN_Utils::json_encode($context), 'occurrences' => 1, 'status' => 'open',
            'first_seen_at' => $now, 'last_seen_at' => $now, 'last_notified_at' => null, 'resolved_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return ['id' => (int)$wpdb->insert_id, 'fingerprint' => $fingerprint, 'source' => $source, 'component' => $component, 'severity' => $severity, 'code' => $code, 'message' => $message, 'occurrences' => 1, 'last_notified_at' => null, 'last_seen_at' => $now];
    }

    private static function should_notify(array $event, array $settings): bool {
        if (empty($event['last_notified_at'])) return true;
        $last = strtotime((string)$event['last_notified_at']) ?: 0;
        $elapsed = time() - $last;
        $severity = (string)($event['severity'] ?? 'error');
        $source = (string)($event['source'] ?? 'runtime');

        // Health state is sampled every minute. Do not turn an unchanged advisory into Telegram spam.
        // Runtime errors keep the tighter generic repeat policy below.
        if ($source === 'health') {
            if (in_array($severity, ['notice','info'], true)) return $elapsed >= 12 * HOUR_IN_SECONDS;
            if ($severity === 'warning') return $elapsed >= HOUR_IN_SECONDS;
        }

        if ($elapsed < max(60, (int)$settings['dedup_seconds'])) return false;
        if ($severity === 'critical') return true;
        $repeat = max(2, (int)$settings['repeat_every']);
        return ((int)($event['occurrences'] ?? 1) % $repeat) === 0;
    }

    private static function notify(array $event, array $context): void {
        if (self::$notifying || !class_exists('BlueVPN_Telegram_Bot')) return;
        self::$notifying = true;
        try {
            $severity = (string)($event['severity'] ?? 'error');
            $source = (string)($event['source'] ?? 'runtime');
            $emoji = ['critical' => '🆘', 'error' => '🚨', 'warning' => '⚠️', 'notice' => '🔎', 'info' => 'ℹ️'][$severity] ?? '🚨';
            $isHealth = $source === 'health';
            $kind = $isHealth
                ? (in_array($severity, ['notice','info'], true) ? 'وضعیت سلامت / نیازمند بررسی' : 'هشدار سلامت')
                : (in_array($severity, ['critical','error'], true) ? 'خطای اجرایی' : 'هشدار Runtime');
            $lines = [
                $emoji . ' <b>BlueVPN Sentinel</b>',
                '━━━━━━━━━━━━━━',
                'نوع: <b>' . esc_html($kind) . '</b>',
                'شدت: <b>' . esc_html($severity) . '</b>',
                'منبع: <code>' . esc_html($source) . '</code>',
                'بخش: <code>' . esc_html((string)$event['component']) . '</code>',
                'کد: <code>' . esc_html((string)$event['code']) . '</code>',
                'نسخه: <code>' . esc_html(defined('BLUEVPN_MANAGER_VERSION') ? BLUEVPN_MANAGER_VERSION : 'unknown') . '</code>',
                'تکرار: <b>' . (int)($event['occurrences'] ?? 1) . '</b>',
                'پیام: <code>' . esc_html(self::truncate((string)$event['message'], 1400)) . '</code>',
            ];

            if ($isHealth) {
                foreach (self::health_context_lines((string)$event['component'], $context) as $line) $lines[] = $line;
            } else {
                $compact = self::compact_context($context);
                if ($compact !== '') $lines[] = 'جزئیات فنی: <code>' . esc_html(self::truncate($compact, 1500)) . '</code>';
            }
            $lines[] = 'زمان ایران: <code>' . esc_html(BlueVPN_Utils::tehran_datetime_fa()) . ' (Asia/Tehran)</code>';
            BlueVPN_Telegram_Bot::support_notify(implode("\n", $lines));
        } finally {
            self::$notifying = false;
        }
    }

    private static function health_context_lines(string $component, array $context): array {
        $lines = [];
        if ($component === 'payments') {
            $stuck = (int)($context['stuck'] ?? 0);
            if ($stuck > 0) $lines[] = 'تعداد موارد: <b>' . $stuck . '</b>';
            $items = is_array($context['items'] ?? null) ? $context['items'] : [];
            if ($items) {
                $lines[] = '<b>سفارش‌های درگیر:</b>';
                foreach (array_slice($items, 0, 10) as $item) {
                    if (!is_array($item)) continue;
                    $code = esc_html((string)($item['order_code'] ?? '—'));
                    $status = esc_html((string)($item['status'] ?? '—'));
                    $age = (int)($item['age_minutes'] ?? 0);
                    $created = esc_html((string)($item['created_at_fa'] ?? ''));
                    $reason = esc_html(self::truncate((string)($item['reason'] ?? ''), 420));
                    $line = '• <code>' . $code . '</code> | <code>' . $status . '</code> | ' . $age . ' دقیقه';
                    if ($created !== '') $line .= ' | ' . $created;
                    if ($reason !== '') $line .= "\n  علت: " . $reason;
                    $activation = trim((string)($item['activation_error'] ?? ''));
                    if ($activation !== '') $line .= "\n  خطای فعال‌سازی: <code>" . esc_html(self::truncate($activation, 360)) . '</code>';
                    $lines[] = $line;
                }
                if ($stuck > count($items)) $lines[] = '… و ' . max(0, $stuck - count($items)) . ' مورد دیگر در پنل.';
            }
        } elseif ($component === 'cutover') {
            $lines[] = 'Control Plane: <b>' . esc_html((string)($context['control_plane_mode'] ?? 'wordpress_mysql_native')) . '</b>';
            $lines[] = 'WordPress/MySQL: <b>' . (!empty($context['app_cutover_enabled']) ? 'فعال' : 'نیازمند بررسی') . '</b>';
            $lines[] = 'Legacy Bridge: <b>' . (!empty($context['legacy_bridge_disabled']) ? 'بازنشسته' : 'هنوز فعال') . '</b>';
            $lines[] = 'توضیح: در 4.16.6 به بعد مهاجرت Legacy بخشی از Runtime نیست.';
        } else {
            $compact = self::compact_context($context);
            if ($compact !== '') $lines[] = 'جزئیات سلامت: <code>' . esc_html(self::truncate($compact, 1200)) . '</code>';
        }
        $action = trim((string)($context['action'] ?? ''));
        if ($action !== '') $lines[] = 'اقدام پیشنهادی: <b>' . esc_html(self::truncate($action, 800)) . '</b>';
        return $lines;
    }

    private static function mark_notified(int $id, string $now): void {
        if ($id <= 0) return;
        global $wpdb;
        $wpdb->update(self::table(), ['last_notified_at' => $now, 'updated_at' => $now], ['id' => $id]);
    }

    private static function cleanup(): void {
        global $wpdb;
        $days = max(1, min(365, (int)self::settings()['retention_days']));
        $cut = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . " WHERE status='resolved' AND updated_at<%s", $cut));
    }

    public static function register_menu(): void {
        add_submenu_page('bluevpn-manager', 'خطاها و مانیتورینگ', 'خطاها و مانیتورینگ', 'manage_options', self::MENU_SLUG, [self::class, 'admin_page']);
    }

    public static function admin_action(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('bluevpn_monitor_action');
        $action = sanitize_key((string)($_POST['monitor_action'] ?? 'save'));
        if ($action === 'save') {
            $old = self::settings();
            $new = $old;
            foreach (['enabled','telegram_enabled','capture_php_notices','capture_rest_4xx','capture_http_4xx','health_scan_enabled'] as $key) $new[$key] = !empty($_POST[$key]);
            $new['dedup_seconds'] = max(60, min(3600, (int)($_POST['dedup_seconds'] ?? 180)));
            $new['repeat_every'] = max(2, min(1000, (int)($_POST['repeat_every'] ?? 10)));
            $new['retention_days'] = max(1, min(365, (int)($_POST['retention_days'] ?? 30)));
            update_option(self::OPTION, $new, false);
            $msg = 'تنظیمات ذخیره شد.';
        } elseif ($action === 'test') {
            self::report('sentinel', 'self_test', 'notice', 'SENTINEL_TEST', 'این یک پیام آزمایشی از سامانه مانیتورینگ سراسری BlueVPN است.', ['admin' => get_current_user_id()], true);
            $msg = 'پیام آزمایشی ثبت و ارسال شد.';
        } elseif ($action === 'scan') {
            self::health_scan(); $msg = 'اسکن سلامت کامل اجرا شد.';
        } elseif ($action === 'resolve_all') {
            global $wpdb; $now = BlueVPN_Utils::now_mysql(); $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . " SET status='resolved',resolved_at=%s,updated_at=%s WHERE status='open'", $now, $now)); $msg = 'رویدادهای باز Resolve شدند.';
        } else $msg = 'عملیات ناشناخته.';
        wp_safe_redirect(add_query_arg(['page' => self::MENU_SLUG, 'monitor_msg' => rawurlencode($msg)], admin_url('admin.php'))); exit;
    }

    public static function admin_page(): void {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $s = self::settings();
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table() . ' ORDER BY last_seen_at DESC LIMIT 100', ARRAY_A) ?: [];
        $shell = class_exists('BlueVPN_Unified_UI');
        if ($shell) BlueVPN_Unified_UI::shell_open('خطاها و مانیتورینگ', 'BlueVPN Sentinel • GitHub / WordPress / MySQL / Runtime');
        else echo '<div class="wrap"><h1>BlueVPN Sentinel — خطاها و مانیتورینگ</h1>';

        if (!empty($_GET['monitor_msg'])) echo '<div class="notice notice-success"><p>' . esc_html(rawurldecode((string)$_GET['monitor_msg'])) . '</p></div>';

        $open = 0; $errors = 0; $warnings = 0; $resolved = 0;
        foreach ($rows as $r) {
            $status = (string)($r['status'] ?? 'open');
            if ($status === 'resolved') $resolved++; else $open++;
            $sev = (string)($r['severity'] ?? '');
            if ($status !== 'resolved' && in_array($sev, ['critical','error'], true)) $errors++;
            if ($status !== 'resolved' && $sev === 'warning') $warnings++;
        }

        echo '<div class="bvem-summary bvc-grid">';
        foreach ([
            ['رویداد باز', $open, 'تمام رخدادهای فعال'],
            ['خطای جدی', $errors, 'Error / Critical'],
            ['هشدار', $warnings, 'Warning'],
            ['Resolve شده', $resolved, 'در ۱۰۰ رخداد اخیر'],
        ] as $kpi) {
            echo '<div class="bvc-card bvc-kpi"><span>'.esc_html($kpi[0]).'</span><strong>'.(int)$kpi[1].'</strong><small>'.esc_html($kpi[2]).'</small></div>';
        }
        echo '</div>';

        echo '<section class="bvc-card bvem-settings">';
        echo '<div class="bvem-section-head"><div><h2>تنظیمات Sentinel</h2><p>گزارش‌گیری GitHub، WordPress، MySQL، Runtime و سلامت سرویس‌ها از یک مرکز واحد.</p></div><span class="bvem-live"><i></i> فعال</span></div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="bluevpn_monitor_action"><input type="hidden" name="monitor_action" value="save">'; wp_nonce_field('bluevpn_monitor_action');
        echo '<div class="bvem-toggle-grid">';
        foreach (['enabled'=>'Sentinel سراسری','telegram_enabled'=>'ارسال فوری Telegram','capture_php_notices'=>'Notice / Deprecated PHP','capture_rest_4xx'=>'REST 4xx','capture_http_4xx'=>'HTTP خروجی 4xx','health_scan_enabled'=>'اسکن سلامت دوره‌ای'] as $key=>$label) {
            echo '<label class="bvem-toggle"><span><strong>'.esc_html($label).'</strong><small>'.(!empty($s[$key])?'فعال':'غیرفعال').'</small></span><input type="checkbox" name="'.esc_attr($key).'" value="1" '.checked(!empty($s[$key]),true,false).'><i></i></label>';
        }
        echo '</div>';
        echo '<div class="bvc-form-grid bvem-number-grid">';
        echo '<label>Dedup <small>ثانیه</small><input type="number" min="60" max="3600" name="dedup_seconds" value="'.(int)$s['dedup_seconds'].'"></label>';
        echo '<label>تکرار هشدار <small>هر N رخداد</small><input type="number" min="2" max="1000" name="repeat_every" value="'.(int)$s['repeat_every'].'"></label>';
        echo '<label>نگهداری تاریخچه <small>روز</small><input type="number" min="1" max="365" name="retention_days" value="'.(int)$s['retention_days'].'"></label>';
        echo '</div><div class="bvem-save"><button class="button button-primary">ذخیره تنظیمات</button></div></form>';
        echo '</section>';

        echo '<section class="bvc-card bvem-actions-card"><div class="bvem-section-head"><div><h2>ابزارهای سریع</h2><p>برای تست اعلان، اسکن فوری یا بستن رخدادهای قدیمی.</p></div></div><div class="bvc-actions bvem-actions">';
        foreach (['test'=>'ارسال تست Telegram','scan'=>'اجرای اسکن کامل','resolve_all'=>'Resolve همه'] as $act=>$label) {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="bluevpn_monitor_action"><input type="hidden" name="monitor_action" value="'.esc_attr($act).'">'; wp_nonce_field('bluevpn_monitor_action'); echo '<button class="button">'.esc_html($label).'</button></form>';
        }
        echo '</div></section>';

        echo '<section class="bvem-events"><div class="bvem-section-head"><div><h2>آخرین رویدادها</h2><p>حداکثر ۱۰۰ رخداد اخیر؛ در موبایل هر ردیف به کارت خوانا تبدیل می‌شود.</p></div></div>';
        echo '<div class="bvc-table-scroll"><table class="widefat striped bvc-table bvem-events-table"><thead><tr><th>آخرین زمان</th><th>شدت</th><th>منبع / بخش</th><th>کد</th><th>پیام</th><th>تکرار</th><th>وضعیت</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $kind = ((string)$r['source'] === 'health') ? 'سلامت' : (in_array((string)$r['severity'], ['critical','error'], true) ? 'خطای اجرایی' : 'هشدار');
            $sev = sanitize_key((string)$r['severity']);
            $status = sanitize_key((string)$r['status']);
            echo '<tr class="bvem-row is-'.esc_attr($sev).' is-'.esc_attr($status).'">';
            echo '<td>'.esc_html(BlueVPN_Utils::tehran_datetime_fa((string)$r['last_seen_at'])).'</td>';
            echo '<td><span class="bvem-severity bvem-severity-'.esc_attr($sev).'">'.esc_html((string)$r['severity']).'</span><small>'.esc_html($kind).'</small></td>';
            echo '<td><strong>'.esc_html((string)$r['source']).'</strong><small>'.esc_html((string)$r['component']).'</small></td>';
            echo '<td><code>'.esc_html((string)$r['code']).'</code></td>';
            echo '<td class="bvem-message">'.esc_html(self::truncate((string)$r['message'],320)).'</td>';
            echo '<td>'.(int)$r['occurrences'].'</td>';
            echo '<td><span class="bvem-status bvem-status-'.esc_attr($status).'">'.esc_html((string)$r['status']).'</span></td>';
            echo '</tr>';
        }
        if (!$rows) echo '<tr><td colspan="7">رویدادی ثبت نشده است.</td></tr>';
        echo '</tbody></table></div></section>';
        if ($shell) BlueVPN_Unified_UI::shell_close(); else echo '</div>';
    }

    private static function belongs_to_bluevpn(string $file): bool {
        $f = str_replace('\\', '/', strtolower($file));
        if ($f === '') return false;
        return str_contains($f, '/bluevpn-manager/') || str_contains($f, '/bluevpn-site/') || str_contains($f, '/bluevpn/') || (defined('BLUEVPN_MANAGER_DIR') && str_starts_with($f, str_replace('\\','/',strtolower(BLUEVPN_MANAGER_DIR))));
    }
    private static function component_from_path(string $file): string { $f = strtolower(str_replace('\\','/',$file)); return str_contains($f,'bluevpn-site') ? 'theme' : (str_contains($f,'bluevpn-manager') ? 'plugin' : 'runtime'); }
    private static function relative_path(string $file): string { $root = defined('ABSPATH') ? str_replace('\\','/',ABSPATH) : ''; $f=str_replace('\\','/',$file); return $root!=='' && str_starts_with($f,$root) ? substr($f,strlen($root)) : basename($f); }
    private static function php_severity(int $n): string { return in_array($n,[E_ERROR,E_CORE_ERROR,E_COMPILE_ERROR,E_PARSE,E_USER_ERROR,E_RECOVERABLE_ERROR],true)?'critical':(in_array($n,[E_WARNING,E_CORE_WARNING,E_COMPILE_WARNING,E_USER_WARNING],true)?'warning':'notice'); }
    private static function php_error_name(int $n): string { $m=[E_ERROR=>'E_ERROR',E_WARNING=>'E_WARNING',E_PARSE=>'E_PARSE',E_NOTICE=>'E_NOTICE',E_CORE_ERROR=>'E_CORE_ERROR',E_CORE_WARNING=>'E_CORE_WARNING',E_COMPILE_ERROR=>'E_COMPILE_ERROR',E_COMPILE_WARNING=>'E_COMPILE_WARNING',E_USER_ERROR=>'E_USER_ERROR',E_USER_WARNING=>'E_USER_WARNING',E_USER_NOTICE=>'E_USER_NOTICE',E_STRICT=>'E_STRICT',E_RECOVERABLE_ERROR=>'E_RECOVERABLE_ERROR',E_DEPRECATED=>'E_DEPRECATED',E_USER_DEPRECATED=>'E_USER_DEPRECATED']; return $m[$n]??('PHP_'.$n); }
    private static function normalize_severity(string $s): string { $s=strtolower($s); return in_array($s,['critical','error','warning','notice','info'],true)?$s:'error'; }
    private static function http_component(string $host, string $url): string { if (str_contains($host,'github')) return 'github'; if (str_contains($host,'iranpayamak')) return 'sms'; if (str_contains($host,'blupal')) return 'payment'; if (str_contains($url,'marzban')) return 'marzban'; return 'external_http'; }
    private static function safe_url(string $url): string {
        $parts=wp_parse_url($url);if(!is_array($parts))return '';
        $out=($parts['scheme']??'https').'://'.($parts['host']??'');if(isset($parts['port']))$out.=':'.(int)$parts['port'];
        $path=(string)($parts['path']??'');if($path!==''){
            $segments=explode('/',$path);$safe=[];$previous='';
            foreach($segments as $segment){
                if($segment===''){$safe[]='';continue;}
                $decoded=rawurldecode($segment);$lowerPrev=strtolower($previous);
                $sensitiveParent=in_array($lowerPrev,['sub','subscribe','subscription','token','auth','key'],true);
                $looksSecret=$sensitiveParent||(strlen($decoded)>=32&&preg_match('/^[^\\s\\/]+$/',$decoded));
                $safe[]=$looksSecret?'[REDACTED]':$segment;$previous=$decoded;
            }
            $out.=implode('/',$safe);
        }
        return self::truncate($out,700);
    }
    private static function sanitize_message(string $message): string {
        $message=wp_strip_all_tags($message);
        $message=preg_replace_callback('~https?://[^\s<>\x22\x27]+~i',static fn($m)=>self::safe_url(rtrim((string)$m[0],".,;)]}")),$message)??$message;
        $message=preg_replace('/(authorization|token|password|secret|api[_-]?key|cookie)\\s*[:=]\\s*[^\\s,;]+/i','$1=[REDACTED]',$message)??$message;
        $message=preg_replace('/\\b09\\d{9}\\b/','[PHONE]',$message)??$message;
        $message=preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\\.[A-Z]{2,}/i','[EMAIL]',$message)??$message;
        return self::truncate(trim($message),3000);
    }
    private static function safe_sql(string $sql): string { $sql=preg_replace("/'(?:''|[^'])*'/", "'?'", $sql)??$sql; $sql=preg_replace('/\b\d{4,}\b/','?', $sql)??$sql; return self::truncate($sql,1200); }
    private static function sanitize_context(array $ctx): array { $out=[]; foreach($ctx as $k=>$v){$key=(string)$k;if(preg_match('/token|secret|password|authorization|cookie|api.?key|phone|email/i',$key)){$out[$key]='[REDACTED]';continue;} if(is_array($v)){$out[$key]=self::sanitize_context($v);} elseif(is_object($v)){$out[$key]='['.get_class($v).']';} else {$out[$key]=self::sanitize_message((string)$v);} } return $out; }
    private static function compact_context(array $context): string { if(!$context)return ''; $json=wp_json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); return is_string($json)?$json:''; }
    private static function normalize_for_fingerprint(string $m): string { $m=strtolower($m); $m=preg_replace('/\b[0-9a-f]{8}-[0-9a-f-]{27,}\b/i','{uuid}',$m)??$m; $m=preg_replace('/\b[0-9a-f]{16,}\b/i','{hex}',$m)??$m; $m=preg_replace('/\b\d{4,}\b/','{n}',$m)??$m; return self::truncate($m,1500); }
    private static function truncate(string $s, int $max): string { return function_exists('mb_substr') ? mb_substr($s,0,$max) : substr($s,0,$max); }
}
