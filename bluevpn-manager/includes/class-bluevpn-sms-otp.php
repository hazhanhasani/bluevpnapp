<?php
if (!defined('ABSPATH')) exit;

final class BlueVPN_SMS_OTP {
    private const OTP_LENGTH = 6;
    private const PURPOSE_AUTH = 'auth';
    private const PURPOSE_BIND = 'bind_phone';
    private const MAX_ATTEMPTS = 5;
    private const PATTERN_CACHE_OPTION = 'bluevpn_sms_pattern_cache_v2';
    private const PATTERN_CACHE_TTL = 15 * MINUTE_IN_SECONDS;

    public static function settings(): array {
        global $wpdb;
        $table = BlueVPN_DB::table('sms_settings');
        $row = $wpdb->get_row("SELECT * FROM {$table} WHERE id=1", ARRAY_A);
        if (is_array($row) && (int)($row['otp_length'] ?? 0) !== self::OTP_LENGTH) {
            $wpdb->update($table, ['otp_length' => self::OTP_LENGTH, 'updated_at' => BlueVPN_Utils::now_mysql()], ['id' => 1]);
            $row['otp_length'] = self::OTP_LENGTH;
        }
        return is_array($row) ? $row : [];
    }

    private static function provider_context(): array {
        $s = self::settings();
        $apiKey = BlueVPN_Utils::decrypt_secret((string)($s['api_key_enc'] ?? ''));
        $base = untrailingslashit((string)($s['base_url'] ?? 'https://api.iranpayamak.com/ws/v1'));
        if ($base === '' || stripos($base, 'edge.ippanel.com') !== false) $base = 'https://api.iranpayamak.com/ws/v1';
        return [$s, $apiKey, $base];
    }

    private static function pattern_cache_key_hash(string $apiKey, string $base): string {
        return substr(hash('sha256', $apiKey . '|' . strtolower($base)), 0, 24);
    }

    public static function clear_pattern_cache(): void {
        delete_option(self::PATTERN_CACHE_OPTION);
    }

    public static function pattern_cache(): array {
        [$s, $apiKey, $base] = self::provider_context();
        $raw = get_option(self::PATTERN_CACHE_OPTION, []);
        if (!is_array($raw) || $apiKey === '') return ['patterns'=>[], 'fetched_at'=>'', 'fresh'=>false, 'message'=>''];
        $expected = self::pattern_cache_key_hash($apiKey, $base);
        if (!hash_equals($expected, (string)($raw['key_hash'] ?? ''))) return ['patterns'=>[], 'fetched_at'=>'', 'fresh'=>false, 'message'=>''];
        $patterns = is_array($raw['patterns'] ?? null) ? array_values($raw['patterns']) : [];
        $ts = (int)($raw['fetched_ts'] ?? 0);
        return [
            'patterns' => $patterns,
            'fetched_at' => (string)($raw['fetched_at'] ?? ''),
            'fresh' => $ts > 0 && (time() - $ts) < self::PATTERN_CACHE_TTL,
            'message' => (string)($raw['message'] ?? ''),
        ];
    }

    private static function provider_pattern_candidates($node, array &$out, int $depth = 0): void {
        if ($depth > 7) return;
        if (is_string($node)) {
            $trimmed = trim($node);
            if ($trimmed !== '' && (($trimmed[0] ?? '') === '{' || ($trimmed[0] ?? '') === '[')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) self::provider_pattern_candidates($decoded, $out, $depth + 1);
            }
            return;
        }
        if (!is_array($node)) return;
        $hasCode = isset($node['code']) && is_scalar($node['code']) && trim((string)$node['code']) !== '';
        if ($hasCode) {
            $out[] = $node;
            return;
        }
        foreach ($node as $value) {
            if (is_array($value)) self::provider_pattern_candidates($value, $out, $depth + 1);
        }
    }


    private static function provider_pattern_page_stats(array $payload): array {
        $rows = [];
        self::provider_pattern_candidates($payload, $rows);
        $codes = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $code = trim((string)($row['code'] ?? ''));
            if ($code !== '') $codes[$code] = true;
        }

        $info = ['current_page'=>0, 'last_page'=>0, 'total'=>0, 'per_page'=>0, 'has_next'=>null];
        $walk = static function($node, int $depth = 0) use (&$walk, &$info): void {
            if ($depth > 6 || !is_array($node)) return;
            $aliases = [
                'current_page' => ['current_page','currentPage','page'],
                'last_page' => ['last_page','lastPage','total_pages','totalPages','pages'],
                'total' => ['total','total_count','totalCount'],
                'per_page' => ['per_page','perPage','limit','page_size','pageSize'],
            ];
            foreach ($aliases as $target => $keys) {
                if ($info[$target] > 0) continue;
                foreach ($keys as $key) {
                    if (isset($node[$key]) && is_numeric($node[$key])) {
                        $value = (int)$node[$key];
                        if ($value > 0) { $info[$target] = $value; break; }
                    }
                }
            }
            foreach (['next_page_url','nextPageUrl','next_url','nextUrl','next'] as $key) {
                if (!array_key_exists($key, $node)) continue;
                $next = $node[$key];
                if ($next === null || $next === '' || $next === false) $info['has_next'] = false;
                elseif (is_scalar($next)) $info['has_next'] = true;
            }
            foreach ($node as $value) {
                if (is_array($value)) $walk($value, $depth + 1);
            }
        };
        $walk($payload);
        $info['row_count'] = count($rows);
        $info['codes'] = array_keys($codes);
        return $info;
    }

    private static function provider_pattern_page_url(string $base, int $page, int $limit): string {
        // The public docs currently describe the patterns endpoint/filter body,
        // while production accounts paginate the list. Query pagination keeps
        // GET body-free on WordPress/PHP 8.4 and works with the provider's
        // Laravel-style paginator. Duplicate-page detection below safely stops
        // if a provider deployment ignores these query parameters.
        return $base . '/patterns?page=' . max(1, $page) . '&limit=' . max(1, min(200, $limit));
    }

    private static function pattern_vars_from_row(array $row): array {
        $names = [];
        foreach (['vars','variables','attributes'] as $key) {
            $raw = $row[$key] ?? null;
            if (!is_array($raw)) continue;
            foreach ($raw as $item) {
                if (is_string($item)) $name = $item;
                elseif (is_array($item)) $name = (string)($item['var'] ?? $item['name'] ?? $item['key'] ?? $item['attribute'] ?? '');
                else $name = '';
                $name = sanitize_key(trim($name));
                if ($name !== '' && !in_array($name, $names, true)) $names[] = $name;
            }
        }
        $text = (string)($row['text'] ?? $row['pattern'] ?? $row['body'] ?? '');
        if ($text !== '' && preg_match_all('/%([A-Za-z0-9_\-]+)%/u', $text, $m)) {
            foreach ($m[1] as $name) {
                $name = sanitize_key((string)$name);
                if ($name !== '' && !in_array($name, $names, true)) $names[] = $name;
            }
        }
        return array_slice($names, 0, 30);
    }

    private static function normalize_provider_patterns(array $payload): array {
        $rows = [];
        self::provider_pattern_candidates($payload, $rows);
        $patterns = [];
        foreach ($rows as $row) {
            $code = trim((string)($row['code'] ?? ''));
            if ($code === '') continue;
            $status = strtolower(trim((string)($row['status'] ?? $row['state'] ?? $row['pattern_status'] ?? 'active')));
            if ($status !== '' && !in_array($status, ['active','approved','accept','accepted','1','true'], true)) continue;
            $text = trim(wp_strip_all_tags((string)($row['text'] ?? $row['pattern'] ?? $row['body'] ?? $row['title'] ?? $row['description'] ?? '')));
            $description = trim(wp_strip_all_tags((string)($row['description'] ?? $row['title'] ?? '')));
            $vars = self::pattern_vars_from_row($row);
            $patterns[$code] = [
                'code' => mb_substr($code, 0, 160),
                'text' => mb_substr($text, 0, 500),
                'description' => mb_substr($description, 0, 220),
                'status' => $status !== '' ? $status : 'active',
                'variables' => $vars,
            ];
        }
        uasort($patterns, static function(array $a, array $b): int {
            return strnatcasecmp(($a['description'] ?: $a['text'] ?: $a['code']), ($b['description'] ?: $b['text'] ?: $b['code']));
        });
        return array_values($patterns);
    }

    public static function refresh_patterns(bool $force = true): array {
        [$s, $apiKey, $base] = self::provider_context();
        if ($apiKey === '') throw new RuntimeException('ابتدا API Key ایران‌پیامک را ذخیره کنید.');
        $cached = self::pattern_cache();
        if (!$force && !empty($cached['fresh'])) return $cached + ['count'=>count($cached['patterns'])];

        /*
         * FarazSMS / IranPayamak documents GET /ws/v1/patterns. Production
         * responses are paged (commonly 15 rows per page), so BlueVPN walks all
         * pages instead of silently caching page 1. Requests stay body-free to
         * avoid the WordPress/PHP 8.4 http_build_query() GET-body failure.
         */
        $headers = [
            'Api-Key' => $apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'BlueVPN-WordPress-SMS/' . BLUEVPN_MANAGER_VERSION,
        ];
        $all = [];
        $seenProviderCodes = [];
        $page = 1;
        $limit = 100;
        $maxPages = 50;
        $pagesFetched = 0;
        $lastDecoded = [];

        while ($page <= $maxPages) {
            $url = self::provider_pattern_page_url($base, $page, $limit);
            $res = wp_remote_request($url, [
                'method' => 'GET',
                'timeout' => 10,
                'redirection' => 2,
                'sslverify' => !isset($s['verify_tls']) || (bool)$s['verify_tls'],
                'headers' => $headers,
            ]);
            if (is_wp_error($res)) throw self::provider_transport_failure($res);
            $status = (int)wp_remote_retrieve_response_code($res);
            $body = trim((string)wp_remote_retrieve_body($res));
            $decoded = $body !== '' ? json_decode($body, true) : null;
            if ($status < 200 || $status >= 300) {
                $data = is_array($decoded) ? $decoded : [];
                $fallback = in_array($status, [401,403], true)
                    ? 'API Key ایران‌پیامک معتبر نیست یا اجازه مشاهده پترن‌ها را ندارد.'
                    : 'دریافت فهرست پترن‌های ایران‌پیامک ناموفق بود.';
                $message = self::provider_error_message($data, $fallback);
                self::record_provider_health(false, 'PATTERN_SYNC HTTP ' . $status . ' page=' . $page . ': ' . $message);
                throw new RuntimeException($message);
            }
            if (!is_array($decoded)) {
                self::record_provider_health(false, 'PATTERN_SYNC_INVALID_RESPONSE: HTTP ' . $status . ' page=' . $page . ' بدون JSON معتبر.');
                throw new RuntimeException('ایران‌پیامک برای فهرست پترن‌ها پاسخ JSON معتبر برنگرداند.');
            }
            $providerStatus = strtolower(trim((string)($decoded['status'] ?? '')));
            if (in_array($providerStatus, ['error','failed','fail','rejected'], true)) {
                $message = self::provider_error_message($decoded, 'ایران‌پیامک دریافت فهرست پترن‌ها را رد کرد.');
                self::record_provider_health(false, 'PATTERN_SYNC_PROVIDER_REJECTED page=' . $page . ': ' . $message);
                throw new RuntimeException($message);
            }

            $pagesFetched++;
            $lastDecoded = $decoded;
            $stats = self::provider_pattern_page_stats($decoded);
            $newProviderCodes = 0;
            foreach ((array)$stats['codes'] as $code) {
                if (!isset($seenProviderCodes[$code])) {
                    $seenProviderCodes[$code] = true;
                    $newProviderCodes++;
                }
            }
            foreach (self::normalize_provider_patterns($decoded) as $row) {
                $code = trim((string)($row['code'] ?? ''));
                if ($code !== '') $all[$code] = $row;
            }

            $rowCount = (int)($stats['row_count'] ?? 0);
            $current = (int)($stats['current_page'] ?? 0);
            $last = (int)($stats['last_page'] ?? 0);
            $hasNext = $stats['has_next'] ?? null;

            if ($rowCount === 0) break;
            if ($last > 0 && ($current > 0 ? $current : $page) >= $last) break;
            if ($hasNext === false) break;
            // Provider ignored page/limit and returned the same first page.
            if ($page > 1 && $newProviderCodes === 0) break;
            $page++;
        }

        $patterns = array_values($all);
        usort($patterns, static function(array $a, array $b): int {
            return strnatcasecmp(($a['description'] ?: $a['text'] ?: $a['code']), ($b['description'] ?: $b['text'] ?: $b['code']));
        });

        // Defensive recovery for an empty list although a concrete Pattern UID
        // is already configured. Fetch that exact pattern from the documented
        // details endpoint and still validate its status locally.
        $configuredCode = trim((string)($s['pattern_code'] ?? ''));
        if (empty($patterns) && $configuredCode !== '') {
            $detail = wp_remote_request($base . '/patterns/' . rawurlencode($configuredCode), [
                'method' => 'GET',
                'timeout' => 8,
                'redirection' => 2,
                'sslverify' => !isset($s['verify_tls']) || (bool)$s['verify_tls'],
                'headers' => $headers,
            ]);
            if (!is_wp_error($detail)) {
                $detailStatus = (int)wp_remote_retrieve_response_code($detail);
                $detailBody = trim((string)wp_remote_retrieve_body($detail));
                $detailDecoded = $detailBody !== '' ? json_decode($detailBody, true) : null;
                if ($detailStatus >= 200 && $detailStatus < 300 && is_array($detailDecoded)) {
                    $recovered = self::normalize_provider_patterns($detailDecoded);
                    if (!empty($recovered)) $patterns = $recovered;
                }
            }
        }

        $message = count($patterns) . ' پترن فعال از ایران‌پیامک در ' . $pagesFetched . ' صفحه دریافت شد.';
        if (empty($patterns)) {
            $message .= ' اگر در پنل ایران‌پیامک پترن فعال دارید، API Key و وضعیت همان پترن را بررسی کنید.';
        }
        update_option(self::PATTERN_CACHE_OPTION, [
            'key_hash' => self::pattern_cache_key_hash($apiKey, $base),
            'fetched_ts' => time(),
            'fetched_at' => BlueVPN_Utils::now_mysql(),
            'patterns' => $patterns,
            'message' => $message,
            'pages_fetched' => $pagesFetched,
            'provider_rows_seen' => count($seenProviderCodes),
        ], false);
        self::record_provider_health(true, 'PATTERN_SYNC_OK: ' . $message . ' provider_rows=' . count($seenProviderCodes));
        return ['patterns'=>$patterns, 'fetched_at'=>BlueVPN_Utils::now_mysql(), 'fresh'=>true, 'message'=>$message, 'count'=>count($patterns), 'pages_fetched'=>$pagesFetched];
    }

    public static function active_pattern_codes(): array {
        $cache = self::pattern_cache();
        $codes = [];
        foreach ($cache['patterns'] as $row) {
            $code = trim((string)($row['code'] ?? ''));
            if ($code !== '') $codes[] = $code;
        }
        return array_values(array_unique($codes));
    }

    public static function pattern_variables(string $code): array {
        $code = trim($code);
        if ($code === '') return [];
        foreach (self::pattern_cache()['patterns'] as $row) {
            if (hash_equals($code, (string)($row['code'] ?? ''))) {
                return is_array($row['variables'] ?? null) ? array_values($row['variables']) : [];
            }
        }
        return [];
    }

    public static function preferred_otp_parameter(string $patternCode, string $fallback = 'code'): string {
        $vars = self::pattern_variables($patternCode);
        $fallback = sanitize_key($fallback) ?: 'code';
        if (!$vars) return $fallback;
        if (in_array($fallback, $vars, true)) return $fallback;
        if (in_array('code', $vars, true)) return 'code';
        if (in_array('otp', $vars, true)) return 'otp';
        return sanitize_key((string)$vars[0]) ?: $fallback;
    }

    public static function is_ready(): bool {
        $s = self::settings();
        if (!$s || empty($s['active'])) return false;
        if (strtolower(trim((string)($s['provider'] ?? ''))) !== 'iranpayamak') return false;
        if (BlueVPN_Utils::decrypt_secret((string)($s['api_key_enc'] ?? '')) === '') return false;
        if (trim((string)($s['pattern_code'] ?? '')) === '') return false;
        if (trim((string)($s['from_number'] ?? '')) === '') return false;
        return true;
    }

    public static function public_config(): array {
        $s = self::settings();
        return [
            'provider' => 'iranpayamak',
            'ready' => self::is_ready(),
            'otp_length' => self::OTP_LENGTH,
            'otp_ttl_seconds' => max(60, min(600, (int)($s['otp_ttl_seconds'] ?? 120))),
            'resend_seconds' => max(30, min(600, (int)($s['resend_seconds'] ?? 60))),
        ];
    }

    private static function normalize_phone(string $raw): string {
        $phone = BlueVPN_Utils::sanitize_phone($raw);
        if (!preg_match('/^\+989\d{9}$/', $phone)) {
            throw new BlueVPN_Auth_Exception(422, 'PHONE_INVALID', 'شماره موبایل معتبر نیست.');
        }
        return $phone;
    }

    private static function client_ip(): string {
        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return preg_match('/^[0-9a-f:.]+$/i', $ip) ? $ip : 'unknown';
    }

    private static function rate_limit(string $phone, string $deviceId = ''): void {
        $window = 10 * MINUTE_IN_SECONDS;
        $phoneKey = 'bluevpn_otp_phone_' . substr(hash('sha256', $phone), 0, 24);
        $ipKey = 'bluevpn_otp_ip_' . substr(hash('sha256', self::client_ip()), 0, 24);
        $deviceKey = 'bluevpn_otp_device_' . substr(hash('sha256', trim($deviceId)), 0, 24);
        $limits = [[$phoneKey, 5], [$ipKey, 30]];
        if (trim($deviceId) !== '') $limits[] = [$deviceKey, 8];
        foreach ($limits as [$key, $limit]) {
            $count = (int)get_transient($key);
            if ($count >= $limit) {
                throw new BlueVPN_Auth_Exception(429, 'OTP_RATE_LIMITED', 'تعداد درخواست کد زیاد است؛ چند دقیقه دیگر دوباره تلاش کنید.', ['retry_after_seconds' => 600]);
            }
            set_transient($key, $count + 1, $window);
        }
    }

    private static function otp_hash(string $challengeId, string $phone, string $code): string {
        $secret = hash('sha256', wp_salt('auth') . '|' . wp_salt('secure_auth') . '|bluevpn-otp-v1', true);
        return 'otp_hmac_sha256$' . hash_hmac('sha256', $challengeId . ':' . $phone . ':' . $code, $secret);
    }

    private static function generate_code(): string {
        return (string)random_int(100000, 999999);
    }

    private static function clean_code(string $raw): string {
        $raw = strtr(trim($raw), '۰۱۲۳۴۵۶۷۸۹', '0123456789');
        $digits = preg_replace('/\D+/', '', $raw) ?: '';
        return substr($digits, 0, self::OTP_LENGTH);
    }

    private static function log_delivery(string $challengeId, string $phone, string $status, string $error = '', array $response = [], string $purpose = self::PURPOSE_AUTH, ?int $customerId = null): void {
        global $wpdb;
        $table = BlueVPN_DB::table('sms_deliveries');
        $wpdb->replace($table, [
            'id' => BlueVPN_Utils::random_uuid4(),
            'event_key' => $purpose === self::PURPOSE_BIND ? 'bind_phone_otp' : 'auth_otp',
            'customer_id' => $customerId,
            'order_id' => null,
            'phone' => $phone,
            'params_json' => BlueVPN_Utils::json_encode(['purpose' => $purpose, 'challenge_id' => $challengeId]),
            'dedupe_key' => $purpose . ':' . $challengeId,
            'status' => $status,
            'attempts' => 1,
            'max_attempts' => 1,
            'provider_message_id' => self::provider_message_id($response),
            'response_json' => $response ? BlueVPN_Utils::json_encode($response) : '',
            'last_error' => mb_substr($error, 0, 1000),
            'next_attempt_at' => null,
            'sent_at' => $status === 'sent' ? BlueVPN_Utils::now_mysql() : null,
            'created_at' => BlueVPN_Utils::now_mysql(),
        ]);
    }

    private static function provider_message_id(array $response): string {
        foreach (['message_id', 'messageId', 'id', 'uid'] as $key) {
            if (isset($response[$key]) && is_scalar($response[$key])) return mb_substr((string)$response[$key], 0, 180);
        }
        if (isset($response['data']) && is_array($response['data'])) {
            foreach (['message_id', 'messageId', 'id', 'uid'] as $key) {
                if (isset($response['data'][$key]) && is_scalar($response['data'][$key])) return mb_substr((string)$response['data'][$key], 0, 180);
            }
        }
        return '';
    }

    private static function provider_error_message(array $payload, string $fallback): string {
        if (isset($payload['meta']) && is_array($payload['meta']) && !empty($payload['meta']['message'])) return mb_substr(wp_strip_all_tags((string)$payload['meta']['message']), 0, 300);
        foreach (['message', 'error', 'messages'] as $key) {
            if (!empty($payload[$key]) && is_scalar($payload[$key])) return mb_substr(wp_strip_all_tags((string)$payload[$key]), 0, 300);
        }
        return $fallback;
    }

    private static function record_provider_health(bool $ok, string $message): void {
        global $wpdb;
        $wpdb->update(
            BlueVPN_DB::table('sms_settings'),
            [
                'last_test_ok' => $ok ? 1 : 0,
                'last_test_message' => mb_substr(wp_strip_all_tags($message), 0, 1000),
                'last_test_at' => BlueVPN_Utils::now_mysql(),
                'updated_at' => BlueVPN_Utils::now_mysql(),
            ],
            ['id' => 1]
        );
    }

    private static function provider_transport_failure(WP_Error $error): BlueVPN_Auth_Exception {
        $providerCode = sanitize_key((string)$error->get_error_code()) ?: 'http_request_failed';
        $raw = trim(wp_strip_all_tags((string)$error->get_error_message()));
        $lower = strtolower($raw);
        if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout') || str_contains($lower, 'curl error 28')) {
            $code = 'SMS_PROVIDER_TIMEOUT';
            $message = 'ایران‌پیامک در مهلت مقرر پاسخ نداد؛ ارتباط خروجی سرور WordPress با سرویس پیامک را بررسی کنید.';
        } elseif (str_contains($lower, 'resolve host') || str_contains($lower, 'could not resolve') || str_contains($lower, 'curl error 6')) {
            $code = 'SMS_PROVIDER_DNS_FAILED';
            $message = 'DNS سرور WordPress نتوانست آدرس ایران‌پیامک را پیدا کند.';
        } elseif (str_contains($lower, 'ssl') || str_contains($lower, 'certificate') || str_contains($lower, 'curl error 60')) {
            $code = 'SMS_PROVIDER_TLS_FAILED';
            $message = 'بررسی TLS هنگام اتصال WordPress به ایران‌پیامک ناموفق بود.';
        } else {
            $code = 'SMS_PROVIDER_NETWORK_FAILED';
            $message = 'ارتباط سرور WordPress با ایران‌پیامک برقرار نشد.';
        }
        self::record_provider_health(false, $code . ': ' . ($raw !== '' ? $raw : $providerCode));
        return new BlueVPN_Auth_Exception(503, $code, $message, [
            'retryable' => true,
            'provider_error_code' => $providerCode,
        ]);
    }

    private static function send_code(string $phone, string $code): array {
        $s = self::settings();
        if (!self::is_ready()) {
            self::record_provider_health(false, 'SMS_NOT_CONFIGURED: تنظیمات OTP کامل یا فعال نیست.');
            throw new BlueVPN_Auth_Exception(503, 'SMS_NOT_CONFIGURED', 'سامانه ایران‌پیامک هنوز در پنل مدیریت تنظیم یا فعال نشده است.');
        }
        $apiKey = BlueVPN_Utils::decrypt_secret((string)($s['api_key_enc'] ?? ''));
        $base = untrailingslashit((string)($s['base_url'] ?? 'https://api.iranpayamak.com/ws/v1'));
        if ($base === '' || stripos($base, 'edge.ippanel.com') !== false) $base = 'https://api.iranpayamak.com/ws/v1';
        $line = preg_replace('/\s+/', '', strtr((string)($s['from_number'] ?? ''), '۰۱۲۳۴۵۶۷۸۹', '0123456789')) ?: '';
        if (!preg_match('/^[+0-9A-Za-z_-]{3,32}$/', $line)) {
            self::record_provider_health(false, 'SMS_LINE_REQUIRED: شماره خط ارسال معتبر نیست.');
            throw new BlueVPN_Auth_Exception(503, 'SMS_LINE_REQUIRED', 'شماره خط ارسال ایران‌پیامک معتبر نیست.');
        }
        $patternCode = trim((string)($s['pattern_code'] ?? ''));
        $cache = self::pattern_cache();
        if (!empty($cache['patterns'])) {
            $activeCodes = self::active_pattern_codes();
            if ($patternCode === '' || !in_array($patternCode, $activeCodes, true)) {
                self::record_provider_health(false, 'SMS_PATTERN_INACTIVE: پترن OTP در کش فعال ایران‌پیامک پیدا نشد.');
                throw new BlueVPN_Auth_Exception(503, 'SMS_PATTERN_INACTIVE', 'پترن OTP انتخاب‌شده دیگر فعال نیست؛ در پنل SMS فهرست پترن‌ها را تازه‌سازی و دوباره انتخاب کنید.');
            }
        }
        $param = self::preferred_otp_parameter($patternCode, (string)($s['parameter_name'] ?? 'code'));
        $payload = [
            'code' => $patternCode,
            'attributes' => [$param => $code],
            'recipient' => BlueVPN_Utils::local_phone($phone),
            'number_format' => 'english',
            'line_number' => $line,
        ];
        $res = wp_remote_post($base . '/sms/pattern', [
            // Keep the provider timeout below Android's 30s OTP budget. This
            // guarantees that the API can return a structured provider error
            // instead of Android timing out first with a generic message.
            'timeout' => 8,
            'redirection' => 2,
            'sslverify' => !isset($s['verify_tls']) || (bool)$s['verify_tls'],
            'headers' => [
                'Api-Key' => $apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'BlueVPN-WordPress-SMS/' . BLUEVPN_MANAGER_VERSION,
            ],
            'body' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        if (is_wp_error($res)) {
            throw self::provider_transport_failure($res);
        }
        $status = (int)wp_remote_retrieve_response_code($res);
        $body = trim((string)wp_remote_retrieve_body($res));
        $decoded = $body !== '' ? json_decode($body, true) : null;
        $data = is_array($decoded) ? $decoded : [];
        if ($status < 200 || $status >= 300) {
            $fallback = in_array($status, [401,403], true)
                ? 'کلید API ایران‌پیامک معتبر نیست یا مجوز ارسال پترن ندارد.'
                : 'ارسال کد ورود توسط ایران‌پیامک انجام نشد.';
            $message = self::provider_error_message($data, $fallback);
            self::record_provider_health(false, 'HTTP ' . $status . ': ' . $message);
            throw new BlueVPN_Auth_Exception(in_array($status,[429,500,502,503,504],true) ? 503 : 502, 'SMS_SEND_FAILED', $message, ['provider_status' => $status]);
        }
        if ($body === '' || !is_array($decoded)) {
            self::record_provider_health(false, 'SMS_PROVIDER_INVALID_RESPONSE: HTTP ' . $status . ' بدون JSON معتبر.');
            throw new BlueVPN_Auth_Exception(502, 'SMS_PROVIDER_INVALID_RESPONSE', 'ایران‌پیامک پاسخ معتبر JSON برنگرداند؛ تنظیمات وب‌سرویس را بررسی کنید.', ['provider_status' => $status]);
        }
        $providerStatus = strtolower(trim((string)($data['status'] ?? '')));
        $metaStatus = isset($data['meta']['status']) ? strtolower(trim((string)$data['meta']['status'])) : '';
        if ((isset($data['success']) && $data['success'] === false)
            || (isset($data['status']) && $data['status'] === false)
            || in_array($providerStatus, ['error','failed','fail','rejected'], true)
            || (isset($data['meta']['status']) && $data['meta']['status'] === false)
            || in_array($metaStatus, ['error','failed','fail','rejected'], true)) {
            $message = self::provider_error_message($data, 'ایران‌پیامک ارسال کد را رد کرد.');
            self::record_provider_health(false, 'PROVIDER_REJECTED: ' . $message);
            throw new BlueVPN_Auth_Exception(502, 'SMS_SEND_FAILED', $message);
        }
        self::record_provider_health(true, 'Provider accepted OTP pattern request (HTTP ' . $status . ').');
        return $data;
    }

    public static function request(string $phoneRaw, string $deviceId): array {
        global $wpdb;
        $phone = self::normalize_phone($phoneRaw);
        $deviceId = mb_substr(trim($deviceId), 0, 180);
        if ($deviceId === '') throw new BlueVPN_Auth_Exception(422, 'DEVICE_ID_REQUIRED', 'شناسه دستگاه لازم است.');
        self::rate_limit($phone, $deviceId);
        $s = self::settings();
        if (!self::is_ready()) throw new BlueVPN_Auth_Exception(503, 'SMS_NOT_CONFIGURED', 'سامانه ایران‌پیامک هنوز در پنل مدیریت تنظیم یا فعال نشده است.');

        $table = BlueVPN_DB::table('otp_challenges');
        $resend = max(30, min(600, (int)($s['resend_seconds'] ?? 60)));
        $latest = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE phone=%s AND purpose=%s AND consumed_at IS NULL ORDER BY created_at DESC LIMIT 1",
            $phone, self::PURPOSE_AUTH
        ), ARRAY_A);
        if ($latest && !empty($latest['created_at'])) {
            $age = max(0, time() - (int)strtotime($latest['created_at'] . ' UTC'));
            if ($age < $resend) {
                $wait = $resend - $age;
                throw new BlueVPN_Auth_Exception(429, 'OTP_RESEND_WAIT', "{$wait} ثانیه تا ارسال دوباره کد صبر کنید.", ['retry_after_seconds' => $wait]);
            }
        }
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET consumed_at=%s WHERE phone=%s AND purpose=%s AND consumed_at IS NULL",
            BlueVPN_Utils::now_mysql(), $phone, self::PURPOSE_AUTH
        ));

        $challengeId = BlueVPN_Utils::random_uuid4();
        $code = self::generate_code();
        $ttl = max(60, min(600, (int)($s['otp_ttl_seconds'] ?? 120)));
        $inserted = $wpdb->insert($table, [
            'id' => $challengeId,
            'phone' => $phone,
            'purpose' => self::PURPOSE_AUTH,
            'customer_id' => null,
            'device_id' => $deviceId,
            'code_hash' => self::otp_hash($challengeId, $phone, $code),
            'attempts' => 0,
            'max_attempts' => self::MAX_ATTEMPTS,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttl),
            'consumed_at' => null,
            'created_at' => BlueVPN_Utils::now_mysql(),
        ]);
        if ($inserted === false) throw new BlueVPN_Auth_Exception(500, 'OTP_CREATE_FAILED', 'ساخت درخواست کد ورود انجام نشد.');

        try {
            $provider = self::send_code($phone, $code);
            self::log_delivery($challengeId, $phone, 'sent', '', $provider);
        } catch (BlueVPN_Auth_Exception $e) {
            $wpdb->delete($table, ['id' => $challengeId]);
            self::log_delivery($challengeId, $phone, 'failed', $e->getMessage(), ['code' => $e->error_code]);
            throw $e;
        }
        return [
            'success' => true,
            'challenge_id' => $challengeId,
            'phone' => BlueVPN_Utils::local_phone($phone),
            'otp_length' => self::OTP_LENGTH,
            'expires_in_seconds' => $ttl,
            'resend_after_seconds' => $resend,
            'message' => 'کد تأیید ۶ رقمی برای شماره شما ارسال شد.',
        ];
    }

    public static function verify(string $phoneRaw, string $challengeId, string $codeRaw, string $deviceId, string $deviceName = ''): array {
        global $wpdb;
        $phone = self::normalize_phone($phoneRaw);
        $challengeId = trim($challengeId);
        $deviceId = mb_substr(trim($deviceId), 0, 180);
        if ($deviceId === '') throw new BlueVPN_Auth_Exception(422, 'DEVICE_ID_REQUIRED', 'شناسه دستگاه لازم است.');
        $code = self::clean_code($codeRaw);
        if (strlen($code) !== self::OTP_LENGTH) throw new BlueVPN_Auth_Exception(422, 'OTP_INVALID_FORMAT', 'کد ورود باید ۶ رقمی باشد.');
        $table = BlueVPN_DB::table('otp_challenges');
        $challenge = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id=%s AND phone=%s AND purpose=%s LIMIT 1",
            $challengeId, $phone, self::PURPOSE_AUTH
        ), ARRAY_A);
        if (!$challenge) throw new BlueVPN_Auth_Exception(404, 'OTP_NOT_FOUND', 'درخواست کد تأیید پیدا نشد.');
        if (!hash_equals((string)$challenge['device_id'], $deviceId)) throw new BlueVPN_Auth_Exception(401, 'OTP_DEVICE_MISMATCH', 'کد باید روی همان دستگاه درخواست‌کننده تأیید شود.');
        if (!empty($challenge['consumed_at'])) throw new BlueVPN_Auth_Exception(410, 'OTP_ALREADY_USED', 'این کد قبلاً استفاده شده است.');
        if (empty($challenge['expires_at']) || strtotime($challenge['expires_at'] . ' UTC') <= time()) {
            $wpdb->update($table, ['consumed_at' => BlueVPN_Utils::now_mysql()], ['id' => $challengeId]);
            throw new BlueVPN_Auth_Exception(410, 'OTP_EXPIRED', 'مهلت کد تأیید پایان یافته است؛ کد جدید بگیرید.');
        }
        $attempts = (int)$challenge['attempts'];
        $maxAttempts = max(1, (int)$challenge['max_attempts']);
        if ($attempts >= $maxAttempts) {
            $wpdb->update($table, ['consumed_at' => BlueVPN_Utils::now_mysql()], ['id' => $challengeId]);
            throw new BlueVPN_Auth_Exception(429, 'OTP_LOCKED', 'تعداد تلاش‌های ناموفق زیاد بود؛ کد جدید بگیرید.');
        }
        $attempts++;
        $expected = self::otp_hash($challengeId, $phone, $code);
        if (!hash_equals((string)$challenge['code_hash'], $expected)) {
            $update = ['attempts' => $attempts];
            if ($attempts >= $maxAttempts) $update['consumed_at'] = BlueVPN_Utils::now_mysql();
            $wpdb->update($table, $update, ['id' => $challengeId]);
            if ($attempts >= $maxAttempts && class_exists('BlueVPN_SMS_Notifications')) {
                try { $owner=$wpdb->get_row($wpdb->prepare('SELECT id,phone FROM '.BlueVPN_DB::table('customers').' WHERE phone=%s LIMIT 1',$phone),ARRAY_A);if($owner)BlueVPN_SMS_Notifications::queue('suspicious_login',$phone,[],(int)$owner['id'],null,'otp-lock:'.$challengeId); } catch(Throwable $e) { BlueVPN_Error_Monitor::legacy_error_log('BlueVPN suspicious login SMS: '.$e->getMessage()); }
            }
            throw new BlueVPN_Auth_Exception(401, 'INVALID_OTP', 'کد تأیید نادرست است.', ['remaining_attempts' => max(0, $maxAttempts - $attempts)]);
        }
        $wpdb->update($table, ['attempts' => $attempts, 'consumed_at' => BlueVPN_Utils::now_mysql()], ['id' => $challengeId]);

        $customers = BlueVPN_DB::table('customers');
        $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$customers} WHERE phone=%s LIMIT 1", $phone), ARRAY_A);
        $isNew = false;
        if (!$customer) {
            $isNew = true;
            $subscriptionToken = BlueVPN_Utils::random_token(32);
            $ok = $wpdb->insert($customers, [
                'email' => null,
                'password_hash' => 'phone_otp_only$' . BlueVPN_Utils::random_token(32),
                'phone' => $phone,
                'phone_verified_at' => BlueVPN_Utils::now_mysql(),
                'auth_method' => 'phone_otp',
                'active' => 1,
                'subscription_token' => $subscriptionToken,
                'subscription_url' => '',
                'subscription_status' => 'inactive',
                'data_limit_bytes' => 0,
                'used_traffic_bytes' => 0,
                'device_limit' => 1,
                'last_sync_error' => '',
                'created_at' => BlueVPN_Utils::now_mysql(),
            ]);
            if ($ok === false) throw new BlueVPN_Auth_Exception(500, 'ACCOUNT_CREATE_FAILED', 'ساخت حساب انجام نشد.');
            $customer = BlueVPN_Auth::get_customer((int)$wpdb->insert_id);
        } else {
            if (!(int)$customer['active']) throw new BlueVPN_Auth_Exception(401, 'ACCOUNT_DISABLED', 'این حساب غیرفعال شده است.');
            $wpdb->update($customers, [
                'phone_verified_at' => !empty($customer['phone_verified_at']) ? $customer['phone_verified_at'] : BlueVPN_Utils::now_mysql(),
                'auth_method' => 'phone_otp',
            ], ['id' => (int)$customer['id']]);
            $customer = BlueVPN_Auth::get_customer((int)$customer['id']);
        }
        $tokens = BlueVPN_Auth::issue_session($customer, $deviceId, $deviceName);
        if ($isNew && !empty($customer['phone']) && class_exists('BlueVPN_SMS_Notifications')) {
            BlueVPN_SMS_Notifications::queue('welcome', (string)$customer['phone'], ['name'=>BlueVPN_Utils::local_phone((string)$customer['phone'])], (int)$customer['id'], null, 'welcome:'.(int)$customer['id']);
        }
        return [
            'success' => true,
            'is_new_account' => $isNew,
            'token' => $tokens['token'],
            'refresh_token' => $tokens['refresh_token'],
            'account' => BlueVPN_Auth::account_payload($customer),
        ];
    }

    public static function request_bind(array $customer, string $phoneRaw, string $deviceId): array {
        global $wpdb;
        $phone = self::normalize_phone($phoneRaw);
        $customerId = (int)($customer['id'] ?? 0);
        if ($customerId <= 0) throw new BlueVPN_Auth_Exception(401, 'AUTH_REQUIRED', 'ورود لازم است.');
        $deviceId = mb_substr(trim($deviceId), 0, 180);
        if ($deviceId === '') throw new BlueVPN_Auth_Exception(422, 'DEVICE_ID_REQUIRED', 'شناسه دستگاه لازم است.');
        $customers = BlueVPN_DB::table('customers');
        $owner = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$customers} WHERE phone=%s AND id<>%d LIMIT 1", $phone, $customerId));
        if ($owner) throw new BlueVPN_Auth_Exception(409, 'PHONE_ALREADY_USED', 'این شماره قبلاً به حساب دیگری متصل شده است.');
        self::rate_limit($phone, $deviceId);
        $s = self::settings();
        if (!self::is_ready()) throw new BlueVPN_Auth_Exception(503, 'SMS_NOT_CONFIGURED', 'سامانه ایران‌پیامک هنوز در پنل مدیریت تنظیم یا فعال نشده است.');
        $table = BlueVPN_DB::table('otp_challenges');
        $resend = max(30, min(600, (int)($s['resend_seconds'] ?? 60)));
        $latest = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE phone=%s AND purpose=%s AND customer_id=%d AND consumed_at IS NULL ORDER BY created_at DESC LIMIT 1", $phone, self::PURPOSE_BIND, $customerId), ARRAY_A);
        if ($latest && !empty($latest['created_at'])) {
            $age = max(0, time() - (int)strtotime($latest['created_at'] . ' UTC'));
            if ($age < $resend) {
                $wait = $resend - $age;
                throw new BlueVPN_Auth_Exception(429, 'OTP_RESEND_WAIT', "{$wait} ثانیه تا ارسال دوباره کد صبر کنید.", ['retry_after_seconds' => $wait]);
            }
        }
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET consumed_at=%s WHERE phone=%s AND purpose=%s AND customer_id=%d AND consumed_at IS NULL", BlueVPN_Utils::now_mysql(), $phone, self::PURPOSE_BIND, $customerId));
        $challengeId = BlueVPN_Utils::random_uuid4();
        $code = self::generate_code();
        $ttl = max(60, min(600, (int)($s['otp_ttl_seconds'] ?? 120)));
        $ok = $wpdb->insert($table, [
            'id'=>$challengeId, 'phone'=>$phone, 'purpose'=>self::PURPOSE_BIND, 'customer_id'=>$customerId,
            'device_id'=>$deviceId, 'code_hash'=>self::otp_hash($challengeId,$phone,$code), 'attempts'=>0,
            'max_attempts'=>self::MAX_ATTEMPTS, 'expires_at'=>gmdate('Y-m-d H:i:s', time()+$ttl),
            'consumed_at'=>null, 'created_at'=>BlueVPN_Utils::now_mysql(),
        ]);
        if ($ok === false) throw new BlueVPN_Auth_Exception(500, 'OTP_CREATE_FAILED', 'ساخت درخواست کد تأیید انجام نشد.');
        try {
            $provider = self::send_code($phone, $code);
            self::log_delivery($challengeId, $phone, 'sent', '', $provider, self::PURPOSE_BIND, $customerId);
        } catch (BlueVPN_Auth_Exception $e) {
            $wpdb->delete($table, ['id'=>$challengeId]);
            self::log_delivery($challengeId, $phone, 'failed', $e->getMessage(), ['code'=>$e->error_code], self::PURPOSE_BIND, $customerId);
            throw $e;
        }
        return ['success'=>true,'challenge_id'=>$challengeId,'phone'=>BlueVPN_Utils::local_phone($phone),'otp_length'=>self::OTP_LENGTH,'expires_in_seconds'=>$ttl,'resend_after_seconds'=>$resend,'message'=>'کد تأیید ۶ رقمی برای شماره شما ارسال شد.'];
    }

    public static function verify_bind(array $customer, string $phoneRaw, string $challengeId, string $codeRaw, string $deviceId): array {
        global $wpdb;
        $phone=self::normalize_phone($phoneRaw); $customerId=(int)($customer['id']??0); $challengeId=trim($challengeId); $deviceId=mb_substr(trim($deviceId),0,180);
        if($customerId<=0)throw new BlueVPN_Auth_Exception(401,'AUTH_REQUIRED','ورود لازم است.');
        if($deviceId==='')throw new BlueVPN_Auth_Exception(422,'DEVICE_ID_REQUIRED','شناسه دستگاه لازم است.');
        $code=self::clean_code($codeRaw);if(strlen($code)!==self::OTP_LENGTH)throw new BlueVPN_Auth_Exception(422,'OTP_INVALID_FORMAT','کد ورود باید ۶ رقمی باشد.');
        $table=BlueVPN_DB::table('otp_challenges');
        $ch=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%s AND phone=%s AND purpose=%s AND customer_id=%d LIMIT 1",$challengeId,$phone,self::PURPOSE_BIND,$customerId),ARRAY_A);
        if(!$ch)throw new BlueVPN_Auth_Exception(404,'OTP_NOT_FOUND','درخواست کد تأیید پیدا نشد.');
        if(!hash_equals((string)$ch['device_id'],$deviceId))throw new BlueVPN_Auth_Exception(401,'OTP_DEVICE_MISMATCH','کد باید روی همان دستگاه درخواست‌کننده تأیید شود.');
        if(!empty($ch['consumed_at']))throw new BlueVPN_Auth_Exception(410,'OTP_ALREADY_USED','این کد قبلاً استفاده شده است.');
        if(empty($ch['expires_at'])||strtotime($ch['expires_at'].' UTC')<=time()){$wpdb->update($table,['consumed_at'=>BlueVPN_Utils::now_mysql()],['id'=>$challengeId]);throw new BlueVPN_Auth_Exception(410,'OTP_EXPIRED','مهلت کد تأیید پایان یافته است؛ کد جدید بگیرید.');}
        $attempts=(int)$ch['attempts'];$max=max(1,(int)$ch['max_attempts']);if($attempts>=$max){$wpdb->update($table,['consumed_at'=>BlueVPN_Utils::now_mysql()],['id'=>$challengeId]);throw new BlueVPN_Auth_Exception(429,'OTP_LOCKED','تعداد تلاش‌های ناموفق زیاد بود؛ کد جدید بگیرید.');}
        $attempts++;if(!hash_equals((string)$ch['code_hash'],self::otp_hash($challengeId,$phone,$code))){$up=['attempts'=>$attempts];if($attempts>=$max)$up['consumed_at']=BlueVPN_Utils::now_mysql();$wpdb->update($table,$up,['id'=>$challengeId]);throw new BlueVPN_Auth_Exception(401,'INVALID_OTP','کد تأیید نادرست است.',['remaining_attempts'=>max(0,$max-$attempts)]);}
        $customers=BlueVPN_DB::table('customers');$owner=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$customers} WHERE phone=%s AND id<>%d LIMIT 1",$phone,$customerId));if($owner)throw new BlueVPN_Auth_Exception(409,'PHONE_ALREADY_USED','این شماره قبلاً به حساب دیگری متصل شده است.');
        $wpdb->update($table,['attempts'=>$attempts,'consumed_at'=>BlueVPN_Utils::now_mysql()],['id'=>$challengeId]);
        $wpdb->update($customers,['phone'=>$phone,'phone_verified_at'=>BlueVPN_Utils::now_mysql(),'auth_method'=>'phone_otp'],['id'=>$customerId]);
        if (class_exists('BlueVPN_SMS_Notifications')) BlueVPN_SMS_Notifications::queue('phone_changed',$phone,[],$customerId,null,'phone-changed:'.$customerId.':'.$phone);
        return ['success'=>true,'account'=>BlueVPN_Auth::account_payload(BlueVPN_Auth::get_customer($customerId))];
    }

}
