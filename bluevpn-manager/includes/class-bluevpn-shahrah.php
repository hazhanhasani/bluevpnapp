<?php
if (!defined('ABSPATH')) exit;

/**
 * Shahrah VaaS reseller API client.
 *
 * API documentation supplied by the operator:
 *   Base: https://shahrah.top/api/vaas/reseller
 *   Auth: X-API-KEY
 *   GET  /me
 *   GET  /traffic
 *   GET  /plans
 *   GET  /services
 *   POST /services/create
 *   GET  /services/{slug}
 *   POST /services/{slug}/renew
 *   POST /services/{slug}/disable
 *   POST /services/{slug}/enable
 */
final class BlueVPN_Shahrah {
    public const BASE_URL = 'https://shahrah.top/api/vaas/reseller';

    private static function clean_key(string $apiKey): string {
        $apiKey = trim($apiKey);
        if ($apiKey === '' || strlen($apiKey) > 512) {
            throw new RuntimeException('API KEY شاهراه وارد نشده یا معتبر نیست.');
        }
        return $apiKey;
    }

    private static function clean_slug(string $value, string $label): string {
        $value = trim($value);
        if ($value === '' || strlen($value) > 180 || !preg_match('/^[A-Za-z0-9._-]+$/', $value)) {
            throw new RuntimeException($label . ' شاهراه معتبر نیست.');
        }
        return $value;
    }

    private static function error_message(int $code, array $json): string {
        $remote = trim((string)($json['message'] ?? ''));
        if ($code === 400) return 'شاهراه: داده ارسالی ناقص یا نامعتبر است.' . ($remote !== '' ? ' (' . mb_substr($remote, 0, 300) . ')' : '');
        if ($code === 401) return 'شاهراه: API KEY ارسال نشده یا معتبر نیست.';
        if ($code === 404) return 'شاهراه: برند، بسته یا سرویس پیدا نشد.';
        if ($code >= 500) return 'شاهراه: خطای داخلی هنگام پردازش درخواست.';
        if ($remote !== '') return 'شاهراه: ' . mb_substr($remote, 0, 500);
        return 'شاهراه HTTP ' . $code;
    }

    public static function request(string $apiKey, string $method, string $path, ?array $body = null, array $query = []): array {
        $apiKey = self::clean_key($apiKey);
        $path = '/' . ltrim($path, '/');
        $url = self::BASE_URL . $path;
        if ($query) $url = add_query_arg($query, $url);

        $args = [
            'method' => strtoupper($method),
            'timeout' => 15,
            'redirection' => 0,
            'sslverify' => true,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-API-KEY' => $apiKey,
                'User-Agent' => 'BlueVPN-Shahrah/' . BLUEVPN_MANAGER_VERSION,
                'X-BlueVPN-Sentinel-Ignore' => '1',
            ],
        ];
        if ($body !== null) {
            $args['body'] = wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $res = wp_remote_request($url, $args);
        if (is_wp_error($res)) {
            throw new RuntimeException('ارتباط با وب‌سرویس شاهراه برقرار نشد: ' . $res->get_error_message());
        }

        $code = (int)wp_remote_retrieve_response_code($res);
        $raw = (string)wp_remote_retrieve_body($res);
        $json = json_decode($raw, true);
        if (!is_array($json)) $json = ['ok' => false, 'status' => 'Error', 'message' => 'INVALID_JSON'];

        $ok = $code >= 200 && $code < 300 && (($json['ok'] ?? true) !== false);
        if (!$ok) throw new RuntimeException(self::error_message($code, $json));

        return [
            'ok' => true,
            'code' => $code,
            'status' => (string)($json['status'] ?? 'Success'),
            'items' => $json['items'] ?? [],
            'json' => $json,
        ];
    }

    public static function me(string $apiKey): array {
        return self::request($apiKey, 'GET', '/me');
    }

    public static function traffic(string $apiKey): array {
        return self::request($apiKey, 'GET', '/traffic');
    }

    public static function plans(string $apiKey): array {
        return self::request($apiKey, 'GET', '/plans');
    }

    public static function services(string $apiKey, array $query = []): array {
        $allowed = [];
        foreach (['limit','page','status'] as $key) {
            if (isset($query[$key]) && $query[$key] !== '') $allowed[$key] = $query[$key];
        }
        return self::request($apiKey, 'GET', '/services', null, $allowed);
    }

    public static function create_service(string $apiKey, string $planSlug, string $username): array {
        $planSlug = self::clean_slug($planSlug, 'planSlug');
        $username = self::clean_slug($username, 'username');
        return self::request($apiKey, 'POST', '/services/create', [
            'planSlug' => $planSlug,
            'username' => $username,
        ]);
    }

    public static function service(string $apiKey, string $serviceSlug): array {
        $serviceSlug = self::clean_slug($serviceSlug, 'شناسه سرویس');
        return self::request($apiKey, 'GET', '/services/' . rawurlencode($serviceSlug));
    }

    public static function renew_service(string $apiKey, string $serviceSlug, string $planSlug): array {
        $serviceSlug = self::clean_slug($serviceSlug, 'شناسه سرویس');
        $planSlug = self::clean_slug($planSlug, 'planSlug');
        return self::request($apiKey, 'POST', '/services/' . rawurlencode($serviceSlug) . '/renew', [
            'planSlug' => $planSlug,
        ]);
    }

    public static function disable_service(string $apiKey, string $serviceSlug): array {
        $serviceSlug = self::clean_slug($serviceSlug, 'شناسه سرویس');
        return self::request($apiKey, 'POST', '/services/' . rawurlencode($serviceSlug) . '/disable');
    }

    public static function enable_service(string $apiKey, string $serviceSlug): array {
        $serviceSlug = self::clean_slug($serviceSlug, 'شناسه سرویس');
        return self::request($apiKey, 'POST', '/services/' . rawurlencode($serviceSlug) . '/enable');
    }

    private static function find_first_key($node, array $keys): string {
        if (!is_array($node)) return '';
        foreach ($keys as $key) {
            if (isset($node[$key]) && is_scalar($node[$key])) {
                $value = trim((string)$node[$key]);
                if ($value !== '') return $value;
            }
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                $found = self::find_first_key($value, $keys);
                if ($found !== '') return $found;
            }
        }
        return '';
    }

    private static function find_service_for_username($node, string $username): array {
        if (!is_array($node)) return [];
        $candidateUser = '';
        foreach (['username','user','name'] as $key) {
            if (isset($node[$key]) && is_scalar($node[$key])) {
                $candidateUser = trim((string)$node[$key]);
                if ($candidateUser === $username) return $node;
            }
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                $found = self::find_service_for_username($value, $username);
                if ($found) return $found;
            }
        }
        return [];
    }

    public static function extract_service_slug(array $response, string $username = ''): string {
        $slug = self::find_first_key($response, ['slug','serviceSlug','service_slug']);
        if ($slug !== '') return $slug;
        if ($username !== '') {
            $candidate = self::find_service_for_username($response, $username);
            return self::find_first_key($candidate, ['slug','serviceSlug','service_slug']);
        }
        return '';
    }

    private static function collect_config_strings($node, array &$out): void {
        if (is_string($node)) {
            $value = trim($node);
            if ($value === '') return;
            if (preg_match('~^(?:vless|vmess|trojan|ss|hysteria2|hysteria|hy2|tuic)://~i', $value)) {
                $out[] = $value;
                return;
            }
            if (preg_match_all('~(?:vless|vmess|trojan|ss|hysteria2|hysteria|hy2|tuic)://[^\s"\'<>]+~i', $value, $m)) {
                foreach ($m[0] as $config) $out[] = trim((string)$config);
            }
            return;
        }
        if (!is_array($node)) return;
        foreach ($node as $value) self::collect_config_strings($value, $out);
    }

    public static function extract_configs(array $response): array {
        $out = [];
        self::collect_config_strings($response, $out);
        return array_values(array_unique(array_filter($out)));
    }

    private static function map_option(int $sourceId, int $customerId): string {
        return 'bluevpn_shahrah_' . $sourceId . '_' . $customerId;
    }

    public static function mapping(int $sourceId, int $customerId): array {
        $value = get_option(self::map_option($sourceId, $customerId), []);
        return is_array($value) ? $value : [];
    }

    private static function save_mapping(int $sourceId, int $customerId, array $mapping): void {
        update_option(self::map_option($sourceId, $customerId), $mapping, false);
    }

    public static function provision(int $sourceId, int $customerId, string $apiKey, string $planSlug, string $username): array {
        $existing = self::mapping($sourceId, $customerId);
        $serviceSlug = trim((string)($existing['service_slug'] ?? ''));
        $response = [];

        if ($serviceSlug !== '') {
            try {
                $response = self::renew_service($apiKey, $serviceSlug, $planSlug);
            } catch (Throwable $renewError) {
                if (!str_contains($renewError->getMessage(), 'پیدا نشد')) throw $renewError;
                $serviceSlug = '';
            }
        }

        if ($serviceSlug === '') {
            $response = self::create_service($apiKey, $planSlug, $username);
            $serviceSlug = self::extract_service_slug((array)$response['json'], $username);
            if ($serviceSlug === '') {
                $listing = self::services($apiKey, ['limit'=>100,'page'=>1]);
                $candidate = self::find_service_for_username((array)$listing['json'], $username);
                $serviceSlug = self::extract_service_slug($candidate, $username);
            }
            if ($serviceSlug === '') {
                throw new RuntimeException('شاهراه سرویس را ساخت اما slug سرویس از پاسخ قابل تشخیص نبود.');
            }
        }

        self::save_mapping($sourceId, $customerId, [
            'service_slug' => $serviceSlug,
            'username' => $username,
            'plan_slug' => $planSlug,
            'updated_at' => BlueVPN_Utils::iso_now(),
        ]);

        return [
            'ok' => true,
            'service_slug' => $serviceSlug,
            'username' => $username,
            'configs' => self::extract_configs((array)$response['json']),
            'response' => (array)$response['json'],
        ];
    }

    public static function configs_for_customer(int $sourceId, int $customerId, string $apiKey): array {
        $mapping = self::mapping($sourceId, $customerId);
        $slug = trim((string)($mapping['service_slug'] ?? ''));
        if ($slug === '') return ['ok'=>false,'lines'=>[],'message'=>'سرویس شاهراه برای این کاربر هنوز provision نشده است.'];
        $response = self::service($apiKey, $slug);
        $lines = self::extract_configs((array)$response['json']);
        return [
            'ok' => !empty($lines),
            'lines' => $lines,
            'message' => $lines ? count($lines) . ' کانفیگ از شاهراه دریافت شد.' : 'سرویس شاهراه پاسخ داد اما کانفیگ قابل استفاده‌ای در پاسخ نبود.',
            'service_slug' => $slug,
        ];
    }
}
