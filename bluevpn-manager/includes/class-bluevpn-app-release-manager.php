<?php
if (!defined('ABSPATH')) exit;

/**
 * Keeps Android release metadata in WordPress/MySQL.
 *
 * GitHub remains the binary build/release host, but WordPress is the source of
 * truth served to Android through /api/v1/mobile/config.
 */
final class BlueVPN_App_Release_Manager {
    private const OPTION = 'bluevpn_app_release_manager_settings_v1';
    private const STATUS_OPTION = 'bluevpn_app_release_manager_status_v1';
    private const FINGERPRINT_OPTION = 'bluevpn_app_release_fingerprint_v1';
    private const LAST_SYNC_OPTION = 'bluevpn_app_release_last_sync_v1';
    private const CRON_HOOK = 'bluevpn_app_release_fallback_sync';
    private const KICK_LOCK = 'bluevpn_app_release_kick_lock_v1';
    private const SYNC_LOCK = 'bluevpn_app_release_sync_lock_v1';
    private const DEFAULT_OWNER = 'hazhanhasani';
    private const DEFAULT_REPO = 'bluevpnapp';

    public static function init(): void {
        add_action(self::CRON_HOOK, [self::class, 'background_sync']);
        add_action('admin_init', [self::class, 'ensure_schedule']);
        add_action('init', [self::class, 'maybe_kick'], 21);
        self::ensure_schedule();
    }

    public static function defaults(): array {
        return [
            'owner' => self::DEFAULT_OWNER,
            'repo' => self::DEFAULT_REPO,
            'auto_sync' => true,
            'title_override' => '',
            'message_override' => '',
        ];
    }

    public static function settings(): array {
        $saved = get_option(self::OPTION, []);
        return wp_parse_args(is_array($saved) ? $saved : [], self::defaults());
    }

    public static function save_settings(array $settings): void {
        $clean = self::defaults();
        $clean['owner'] = self::clean_slug((string)($settings['owner'] ?? self::DEFAULT_OWNER));
        $clean['repo'] = self::clean_slug((string)($settings['repo'] ?? self::DEFAULT_REPO));
        $clean['auto_sync'] = !empty($settings['auto_sync']);
        $clean['title_override'] = sanitize_text_field((string)($settings['title_override'] ?? ''));
        $clean['message_override'] = sanitize_textarea_field((string)($settings['message_override'] ?? ''));
        if ($clean['owner'] === '') $clean['owner'] = self::DEFAULT_OWNER;
        if ($clean['repo'] === '') $clean['repo'] = self::DEFAULT_REPO;
        update_option(self::OPTION, $clean, false);
    }

    private static function clean_slug(string $value): string {
        return preg_replace('/[^A-Za-z0-9_.-]/', '', $value) ?: '';
    }

    public static function repository(): string {
        $s = self::settings();
        return $s['owner'] . '/' . $s['repo'];
    }

    public static function repository_url(): string {
        $s = self::settings();
        return 'https://github.com/' . rawurlencode($s['owner']) . '/' . rawurlencode($s['repo']);
    }

    public static function status(): array {
        $value = get_option(self::STATUS_OPTION, []);
        return is_array($value) ? wp_parse_args($value, [
            'status' => 'never', 'message' => '', 'version' => '', 'version_code' => 0,
            'release_url' => '', 'source' => '', 'at' => 0,
        ]) : [
            'status' => 'never', 'message' => '', 'version' => '', 'version_code' => 0,
            'release_url' => '', 'source' => '', 'at' => 0,
        ];
    }

    private static function save_status(string $status, string $message, array $extra = []): void {
        $payload = array_merge([
            'status' => sanitize_key($status),
            'message' => sanitize_text_field($message),
            'version' => '',
            'version_code' => 0,
            'release_url' => '',
            'source' => '',
            'at' => time(),
        ], $extra);
        update_option(self::STATUS_OPTION, $payload, false);
    }

    public static function last_sync(): int {
        return (int)get_option(self::LAST_SYNC_OPTION, 0);
    }

    public static function ensure_schedule(): void {
        add_filter('cron_schedules', [self::class, 'cron_schedules']);
        $event = function_exists('wp_get_scheduled_event') ? wp_get_scheduled_event(self::CRON_HOOK) : null;
        if ($event && (string)($event->schedule ?? '') !== 'bluevpn_ten_minutes') self::unschedule();
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 90, 'bluevpn_ten_minutes', self::CRON_HOOK);
        }
    }

    public static function cron_schedules(array $schedules): array {
        if (!isset($schedules['bluevpn_ten_minutes'])) {
            $schedules['bluevpn_ten_minutes'] = [
                'interval' => 10 * MINUTE_IN_SECONDS,
                'display' => 'BlueVPN every 10 minutes',
            ];
        }
        return $schedules;
    }

    public static function unschedule(): void {
        while ($timestamp = wp_next_scheduled(self::CRON_HOOK)) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    /**
     * Normal app traffic also keeps release metadata fresh on hosts with weak
     * WP-Cron. It never blocks the current request.
     */
    public static function maybe_kick(): void {
        if (empty(self::settings()['auto_sync'])) return;
        $last = self::last_sync();
        if ($last > 0 && (time() - $last) < 10 * MINUTE_IN_SECONDS) return;
        if (get_transient(self::KICK_LOCK)) return;
        set_transient(self::KICK_LOCK, '1', 60);
        wp_schedule_single_event(time() + 1, self::CRON_HOOK, ['traffic-kick']);
        $cron_url = site_url('/wp-cron.php?doing_wp_cron=' . rawurlencode(sprintf('%.22F', microtime(true))));
        wp_remote_post($cron_url, [
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ]);
    }

    public static function background_sync($reason = null): void {
        try {
            if (empty(self::settings()['auto_sync'])) return;
            self::sync_now(false, 'wordpress_background');
        } finally {
            delete_transient(self::KICK_LOCK);
        }
    }

    /**
     * Force/retry release synchronization. When the app and plugin use the same
     * repository, reuse the plugin updater's GitHub request so we do not double
     * the unauthenticated API rate usage.
     */
    public static function sync_now(bool $force = true, string $source = 'manual'): array {
        if (get_transient(self::SYNC_LOCK)) {
            return ['ok' => true, 'message' => 'همگام‌سازی دیگری در حال اجرا است.', 'status' => self::status()];
        }
        set_transient(self::SYNC_LOCK, '1', 90);
        try {
            // Manual/forced recovery uses one direct GitHub request. Normal
            // automatic checks are usually fed by BlueVPN_GitHub_Updater's
            // shared release response and therefore create no duplicate API call.
            $response = self::fetch_releases();
            if (is_wp_error($response)) {
                self::save_status('error', $response->get_error_message(), ['source' => $source]);
                return ['ok' => false, 'message' => $response->get_error_message(), 'status' => self::status()];
            }
            return self::ingest_releases($response, $source, $force);
        } finally {
            delete_transient(self::SYNC_LOCK);
        }
    }

    private static function fetch_releases() {
        $s = self::settings();
        $url = 'https://api.github.com/repos/' . rawurlencode($s['owner']) . '/' . rawurlencode($s['repo']) . '/releases?per_page=30';
        $response = wp_remote_get($url, [
            'timeout' => 12,
            'redirection' => 3,
            'headers' => self::request_headers(),
        ]);
        if (is_wp_error($response)) return $response;
        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code !== 200) return new WP_Error('bluevpn_app_release_http', 'GitHub API HTTP ' . $code);
        $releases = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($releases) ? $releases : new WP_Error('bluevpn_app_release_json', 'پاسخ Releaseهای GitHub معتبر نیست.');
    }

    private static function request_headers(): array {
        return [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'BlueVPN-App-Release-Manager/' . BLUEVPN_MANAGER_VERSION . '; ' . home_url('/'),
        ];
    }

    /**
     * Receives the raw GitHub releases list. This is intentionally public so
     * BlueVPN_GitHub_Updater can share its existing API response.
     */
    public static function ingest_releases(array $releases, string $source = 'shared_github_poll', bool $force = false): array {
        if (!$force && empty(self::settings()['auto_sync'])) {
            return ['ok' => true, 'message' => 'همگام‌سازی خودکار اپ غیرفعال است.', 'status' => self::status()];
        }
        update_option(self::LAST_SYNC_OPTION, time(), false);

        $candidates = [];
        foreach ($releases as $release) {
            if (!is_array($release) || !empty($release['draft']) || !empty($release['prerelease'])) continue;
            $tag = trim((string)($release['tag_name'] ?? ''));
            if (!preg_match('/^v(\d+\.\d+\.\d+)$/', $tag, $m)) continue;
            $release['_bluevpn_version'] = $m[1];
            $candidates[] = $release;
        }
        if (!$candidates) {
            self::save_status('no_release', 'Release اپلیکیشن با Tag استاندارد vX.Y.Z پیدا نشد.', ['source' => $source]);
            return ['ok' => false, 'message' => 'Release اپلیکیشن پیدا نشد.', 'status' => self::status()];
        }
        usort($candidates, static fn($a, $b) => version_compare((string)$b['_bluevpn_version'], (string)$a['_bluevpn_version']));
        $release = $candidates[0];
        $version = (string)$release['_bluevpn_version'];
        $fingerprint = self::release_fingerprint($release);
        $old_fingerprint = (string)get_option(self::FINGERPRINT_OPTION, '');
        if (!$force && $fingerprint !== '' && hash_equals($old_fingerprint, $fingerprint)) {
            $settings = BlueVPN_DB::settings();
            self::save_status('up_to_date', 'اطلاعات نسخه اپ به‌روز است.', [
                'version' => (string)($settings['latest_version'] ?? $version),
                'version_code' => (int)($settings['latest_version_code'] ?? 0),
                'release_url' => (string)($settings['release_url'] ?? ($release['html_url'] ?? '')),
                'source' => $source,
            ]);
            return ['ok' => true, 'message' => 'اطلاعات Release تغییری نکرده است.', 'status' => self::status()];
        }

        $assets = [];
        foreach ((array)($release['assets'] ?? []) as $asset) {
            if (!is_array($asset) || empty($asset['name']) || empty($asset['browser_download_url'])) continue;
            $assets[(string)$asset['name']] = $asset;
        }

        $manifest = [];
        if (isset($assets['release-manifest.json'])) {
            $json = self::download_text((string)$assets['release-manifest.json']['browser_download_url'], 1024 * 1024);
            if (!is_wp_error($json)) {
                $decoded = json_decode($json, true);
                if (is_array($decoded)) $manifest = $decoded;
            }
        }

        $hashes = [];
        if (isset($assets['SHA256SUMS.txt'])) {
            $text = self::download_text((string)$assets['SHA256SUMS.txt']['browser_download_url'], 512 * 1024);
            if (!is_wp_error($text)) {
                foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
                    if (preg_match('/^([a-fA-F0-9]{64})\s+\*?(.+)$/', trim($line), $m)) {
                        $hashes[trim($m[2])] = strtolower($m[1]);
                    }
                }
            }
        }

        $apk_assets = [];
        $apk_meta = [];
        foreach ($assets as $name => $asset) {
            if (!str_ends_with(strtolower($name), '.apk')) continue;
            $key = self::abi_key($name);
            if ($key === '') continue;
            $url = esc_url_raw((string)$asset['browser_download_url']);
            if ($url === '') continue;
            $apk_assets[$key] = $url;
            $digest = (string)($asset['digest'] ?? '');
            $sha = '';
            if (preg_match('/^sha256:([a-fA-F0-9]{64})$/', $digest, $m)) $sha = strtolower($m[1]);
            if ($sha === '' && isset($hashes[$name])) $sha = $hashes[$name];
            $apk_meta[$key] = [
                'filename' => sanitize_file_name($name),
                'sha256' => $sha,
                'size' => max(0, (int)($asset['size'] ?? 0)),
            ];
        }

        if (!$apk_assets) {
            self::save_status('error', 'Release پیدا شد اما APK قابل استفاده داخل Assets وجود ندارد.', [
                'version' => $version,
                'release_url' => esc_url_raw((string)($release['html_url'] ?? '')),
                'source' => $source,
            ]);
            return ['ok' => false, 'message' => 'APK داخل Release پیدا نشد.', 'status' => self::status()];
        }

        $version_code = max(0, (int)($manifest['version_code'] ?? 0));
        if ($version_code <= 0) $version_code = self::version_code($version);
        $default_apk = $apk_assets['arm64-v8a'] ?? $apk_assets['universal'] ?? reset($apk_assets) ?: '';
        $manager_cfg = self::settings();
        $title = trim((string)($manager_cfg['title_override'] ?? ''));
        if ($title === '') $title = trim((string)($manifest['update_title'] ?? ''));
        if ($title === '') $title = trim((string)($release['name'] ?? ''));
        if ($title === '') $title = 'BlueVPN ' . $version;
        $title = sanitize_text_field($title);
        $message = trim((string)($manager_cfg['message_override'] ?? ''));
        if ($message === '') $message = trim((string)($manifest['update_message'] ?? ''));
        if ($message === '') $message = trim(wp_strip_all_tags((string)($release['body'] ?? '')));
        $message = sanitize_textarea_field($message !== '' ? $message : 'نسخه جدید BlueVPN آماده است.');
        $published = trim((string)($manifest['published_at'] ?? ''));
        if ($published === '') $published = (string)($release['published_at'] ?? '');
        $published = sanitize_text_field($published);
        $commit = sanitize_text_field((string)($manifest['commit'] ?? ''));
        $build_number = max(0, (int)($manifest['build_number'] ?? 0));
        $release_url = esc_url_raw((string)($release['html_url'] ?? self::repository_url()));

        $settings = BlueVPN_DB::settings();
        $settings['latest_version'] = $version;
        $settings['latest_version_code'] = $version_code;
        $settings['apk_url'] = $default_apk;
        $settings['apk_assets'] = $apk_assets;
        $settings['apk_asset_meta'] = $apk_meta;
        $settings['update_title'] = $title;
        $settings['update_message'] = $message;
        $settings['release_url'] = $release_url;
        $settings['release_published_at'] = $published;
        $settings['release_build_number'] = $build_number;
        $settings['release_commit'] = $commit;
        $settings['update_source'] = 'wordpress_github_release_sync';
        $settings['github_repository'] = self::repository();
        $settings['github_error'] = '';
        $settings['release_cache_seconds'] = 15;
        BlueVPN_DB::save_settings($settings);

        update_option(self::FINGERPRINT_OPTION, $fingerprint, false);
        self::save_status('synced', 'نسخه ' . $version . ' و لینک APKها به WordPress همگام شد.', [
            'version' => $version,
            'version_code' => $version_code,
            'release_url' => $release_url,
            'source' => $source,
        ]);
        return ['ok' => true, 'message' => 'نسخه ' . $version . ' همگام شد.', 'status' => self::status()];
    }

    private static function release_fingerprint(array $release): string {
        $parts = [
            (string)($release['id'] ?? ''),
            (string)($release['tag_name'] ?? ''),
            (string)($release['updated_at'] ?? ''),
        ];
        foreach ((array)($release['assets'] ?? []) as $asset) {
            if (!is_array($asset)) continue;
            $parts[] = implode(':', [
                (string)($asset['id'] ?? ''),
                (string)($asset['name'] ?? ''),
                (string)($asset['updated_at'] ?? ''),
                (string)($asset['size'] ?? ''),
                (string)($asset['digest'] ?? ''),
            ]);
        }
        return hash('sha256', implode('|', $parts));
    }

    private static function abi_key(string $filename): string {
        $name = strtolower($filename);
        if (str_contains($name, 'arm64-v8a') || str_contains($name, 'arm64')) return 'arm64-v8a';
        if (str_contains($name, 'armeabi-v7a') || str_contains($name, 'armeabi') || str_contains($name, 'v7a')) return 'armeabi-v7a';
        if (str_contains($name, 'universal')) return 'universal';
        return 'other';
    }

    private static function version_code(string $version): int {
        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $version, $m)) return 0;
        return ((int)$m[1] * 10000) + ((int)$m[2] * 100) + (int)$m[3];
    }

    private static function download_text(string $url, int $max_bytes) {
        $response = wp_remote_get($url, [
            'timeout' => 12,
            'redirection' => 5,
            'headers' => ['User-Agent' => 'BlueVPN-Manager/' . BLUEVPN_MANAGER_VERSION],
        ]);
        if (is_wp_error($response)) return $response;
        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) return new WP_Error('bluevpn_asset_http', 'GitHub asset HTTP ' . $code);
        $body = (string)wp_remote_retrieve_body($response);
        if (strlen($body) > $max_bytes) return new WP_Error('bluevpn_asset_large', 'GitHub asset بیش از حد بزرگ است.');
        return $body;
    }
}
