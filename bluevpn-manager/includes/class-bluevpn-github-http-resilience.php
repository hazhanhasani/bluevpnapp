<?php
if (!defined('ABSPATH')) exit;

/**
 * Bounded resilience for BlueVPN GitHub Release-list polling.
 *
 * Several BlueVPN components share the same GitHub Releases collection. On
 * slower hosts a single cURL timeout used to become a Sentinel runtime incident
 * even though the next request commonly succeeded. This wrapper owns only the
 * canonical BlueVPN repository Release-list request, retries transient failures
 * with short bounded budgets, and marks the internal attempts as expected
 * transient traffic so Sentinel does not emit duplicate false-positive alerts.
 */
final class BlueVPN_GitHub_HTTP_Resilience {
    private const INTERNAL_HEADER = 'X-BlueVPN-GitHub-Resilience';
    private const SENTINEL_HEADER = 'X-BlueVPN-Sentinel-Ignore';
    private const OWNER = 'hazhanhasani';
    private const REPO = 'bluevpnapp';

    public static function init(): void {
        add_filter('pre_http_request', [self::class, 'pre_http_request'], 15, 3);
    }

    public static function pre_http_request($preempt, array $args, string $url) {
        if ($preempt !== false || !self::is_release_list_url($url) || self::is_internal_attempt($args)) {
            return $preempt;
        }

        $last = null;
        $timeouts = [8, 15, 24];
        foreach ($timeouts as $index => $timeout) {
            $retryArgs = $args;
            $retryArgs['timeout'] = max(1, $timeout);
            $retryArgs['redirection'] = min(5, max(0, (int)($args['redirection'] ?? 3)));
            $retryArgs['headers'] = self::headers_array($args['headers'] ?? []);
            $retryArgs['headers'][self::INTERNAL_HEADER] = '1';
            $retryArgs['headers'][self::SENTINEL_HEADER] = '1';

            $response = wp_remote_get($url, $retryArgs);
            $last = $response;
            if (is_wp_error($response)) {
                if ($index < count($timeouts) - 1) usleep(250000 * (2 ** $index));
                continue;
            }

            $status = (int)wp_remote_retrieve_response_code($response);
            if ($status >= 200 && $status < 300) return $response;
            if (!in_array($status, [408, 425, 429, 500, 502, 503, 504], true)) return $response;
            if ($index < count($timeouts) - 1) usleep(250000 * (2 ** $index));
        }

        return $last !== null
            ? $last
            : new WP_Error('bluevpn_github_release_unavailable', 'GitHub Releases temporarily unavailable.');
    }

    private static function is_release_list_url(string $url): bool {
        $parts = wp_parse_url($url);
        if (!is_array($parts)) return false;
        if (strtolower((string)($parts['scheme'] ?? '')) !== 'https') return false;
        if (strtolower((string)($parts['host'] ?? '')) !== 'api.github.com') return false;
        $path = rtrim((string)($parts['path'] ?? ''), '/');
        return $path === '/repos/' . self::OWNER . '/' . self::REPO . '/releases';
    }

    private static function is_internal_attempt(array $args): bool {
        $headers = self::headers_array($args['headers'] ?? []);
        foreach ($headers as $name => $value) {
            if (strcasecmp((string)$name, self::INTERNAL_HEADER) === 0 && (string)$value === '1') return true;
        }
        return false;
    }

    private static function headers_array($headers): array {
        if (is_array($headers)) return $headers;
        if ($headers instanceof Requests_Utility_CaseInsensitiveDictionary) return $headers->getAll();
        if (is_object($headers) && method_exists($headers, 'getAll')) return (array)$headers->getAll();
        return [];
    }
}
