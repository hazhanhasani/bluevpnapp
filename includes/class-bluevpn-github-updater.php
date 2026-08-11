<?php
if (!defined('ABSPATH')) exit;

/**
 * GitHub Releases updater for BlueVPN Manager.
 *
 * Normal release contract:
 *   tag:   bluevpn-manager-v1.2.4
 *   asset: bluevpn-manager.zip
 *
 * Compatibility recovery:
 * If an asset is replaced under the same semantic tag (for example 1.2.3),
 * the updater can still detect the changed asset by its GitHub fingerprint and
 * offer a one-time repair update. Future releases should still bump SemVer.
 */
final class BlueVPN_GitHub_Updater {
    private const OPTION = 'bluevpn_github_updater_settings';
    private const CACHE_KEY = 'bluevpn_github_release_cache_v2';
    private const INSTALLED_RELEASE_KEY = 'bluevpn_github_installed_release_v2';
    private const DEFAULT_OWNER = 'hazhanhasani';
    private const DEFAULT_REPO = 'bluevpnapp';
    private const DEFAULT_PREFIX = 'bluevpn-manager-v';
    private const DEFAULT_ASSET = 'bluevpn-manager.zip';
    private const CACHE_TTL = 5 * MINUTE_IN_SECONDS;
    private const CRON_HOOK = 'bluevpn_manager_github_update_check';
    private const LAST_CHECK_OPTION = 'bluevpn_github_updater_last_background_check';

    public static function init(): void {
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'inject_update']);
        add_filter('plugins_api', [self::class, 'plugin_info'], 20, 3);
        add_filter('auto_update_plugin', [self::class, 'auto_update'], 20, 2);
        add_action('upgrader_process_complete', [self::class, 'after_upgrade'], 10, 2);
        add_action(self::CRON_HOOK, [self::class, 'background_update_check']);
        add_action('admin_init', [self::class, 'ensure_schedule']);
        add_action('admin_init', [self::class, 'maybe_force_refresh']);
        self::ensure_schedule();
    }

    public static function ensure_schedule(): void {
        add_filter('cron_schedules', [self::class, 'cron_schedules']);
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'bluevpn_five_minutes', self::CRON_HOOK);
        }
    }

    public static function cron_schedules(array $schedules): array {
        if (!isset($schedules['bluevpn_five_minutes'])) {
            $schedules['bluevpn_five_minutes'] = [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display' => 'BlueVPN every 5 minutes',
            ];
        }
        return $schedules;
    }

    public static function unschedule(): void {
        while ($timestamp = wp_next_scheduled(self::CRON_HOOK)) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    public static function background_update_check(): void {
        if (get_transient('bluevpn_github_update_check_lock')) return;
        set_transient('bluevpn_github_update_check_lock', '1', 4 * MINUTE_IN_SECONDS);
        try {
            self::clear_cache();
            delete_site_transient('update_plugins');
            update_option(self::LAST_CHECK_OPTION, time(), false);
            if (!function_exists('wp_update_plugins')) require_once ABSPATH . 'wp-includes/update.php';
            wp_update_plugins();
            if (!empty(self::settings()['auto_update']) && function_exists('wp_maybe_auto_update')) {
                wp_maybe_auto_update();
            }
        } finally {
            delete_transient('bluevpn_github_update_check_lock');
        }
    }

    public static function last_background_check(): int {
        return (int)get_option(self::LAST_CHECK_OPTION, 0);
    }

    public static function defaults(): array {
        return [
            'owner' => self::DEFAULT_OWNER,
            'repo' => self::DEFAULT_REPO,
            'tag_prefix' => self::DEFAULT_PREFIX,
            'asset_name' => self::DEFAULT_ASSET,
            'auto_update' => true,
        ];
    }

    public static function settings(): array {
        $saved = get_option(self::OPTION, []);
        return wp_parse_args(is_array($saved) ? $saved : [], self::defaults());
    }

    public static function save_settings(array $settings): void {
        $clean = self::defaults();
        $clean['owner'] = self::clean_slug($settings['owner'] ?? self::DEFAULT_OWNER);
        $clean['repo'] = self::clean_slug($settings['repo'] ?? self::DEFAULT_REPO);
        $clean['tag_prefix'] = sanitize_text_field((string)($settings['tag_prefix'] ?? self::DEFAULT_PREFIX));
        $clean['asset_name'] = sanitize_file_name((string)($settings['asset_name'] ?? self::DEFAULT_ASSET));
        $clean['auto_update'] = !empty($settings['auto_update']);
        if ($clean['owner'] === '') $clean['owner'] = self::DEFAULT_OWNER;
        if ($clean['repo'] === '') $clean['repo'] = self::DEFAULT_REPO;
        if ($clean['tag_prefix'] === '') $clean['tag_prefix'] = self::DEFAULT_PREFIX;
        if ($clean['asset_name'] === '') $clean['asset_name'] = self::DEFAULT_ASSET;
        update_option(self::OPTION, $clean, false);
        self::clear_cache();
    }

    private static function clean_slug(string $value): string {
        return preg_replace('/[^A-Za-z0-9_.-]/', '', $value) ?: '';
    }

    public static function plugin_basename(): string {
        return plugin_basename(BLUEVPN_MANAGER_FILE);
    }

    public static function repository_url(): string {
        $s = self::settings();
        return 'https://github.com/' . rawurlencode($s['owner']) . '/' . rawurlencode($s['repo']);
    }

    private static function api_url(): string {
        $s = self::settings();
        return 'https://api.github.com/repos/' . rawurlencode($s['owner']) . '/' . rawurlencode($s['repo']) . '/releases?per_page=30';
    }

    private static function request_headers(): array {
        return [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'BlueVPN-Manager/' . BLUEVPN_MANAGER_VERSION . '; ' . home_url('/'),
        ];
    }

    public static function clear_cache(): void {
        delete_site_transient(self::CACHE_KEY);
    }

    /**
     * When WordPress itself asks for a forced plugin refresh, bypass our own
     * GitHub cache too. This prevents the custom updater from staying stale.
     */
    public static function maybe_force_refresh(): void {
        if (!is_admin() || !current_user_can('update_plugins')) return;
        $force = isset($_GET['force-check']) || isset($_GET['bluevpn_force_check']);
        if (!$force) return;
        self::clear_cache();
        delete_site_transient('update_plugins');
    }

    private static function release_fingerprint(array $release): string {
        return hash('sha256', implode('|', [
            (string)($release['tag'] ?? ''),
            (string)($release['release_id'] ?? ''),
            (string)($release['release_updated_at'] ?? ''),
            (string)($release['asset_id'] ?? ''),
            (string)($release['asset_updated_at'] ?? ''),
            (string)($release['asset_size'] ?? ''),
            (string)($release['package'] ?? ''),
        ]));
    }

    private static function base_version(array $release): string {
        return (string)($release['base_version'] ?? $release['version'] ?? '0.0.0');
    }

    private static function same_version_asset_changed(array $release): bool {
        $base = self::base_version($release);
        if (version_compare($base, BLUEVPN_MANAGER_VERSION, '!=')) return false;

        $installed = self::installed_release();
        $installed_fp = (string)($installed['fingerprint'] ?? '');
        $remote_fp = (string)($release['fingerprint'] ?? '');
        if ($installed_fp !== '' && $remote_fp !== '' && !hash_equals($installed_fp, $remote_fp)) return true;

        return $installed_fp === '' && self::remote_asset_is_newer_than_local_files($release);
    }

    private static function effective_version(array $release): string {
        $base = self::base_version($release);
        if (!self::same_version_asset_changed($release)) return $base;
        $stamp = strtotime((string)($release['asset_updated_at'] ?? $release['release_updated_at'] ?? ''));
        if ($stamp <= 0) $stamp = time();
        return $base . '.' . gmdate('YmdHis', $stamp);
    }

    /**
     * @return array|WP_Error|null Normalized release, error, or null when no plugin release exists.
     */
    public static function latest_release(bool $force = false) {
        if (!$force) {
            $cached = get_site_transient(self::CACHE_KEY);
            if (is_array($cached)) return $cached;
            if ($cached === 'none') return null;
        }

        $response = wp_remote_get(self::api_url(), [
            'timeout' => 12,
            'redirection' => 3,
            'headers' => self::request_headers(),
        ]);
        if (is_wp_error($response)) return $response;

        $status = (int)wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            return new WP_Error('bluevpn_github_http', 'GitHub API HTTP ' . $status);
        }

        $releases = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($releases)) {
            return new WP_Error('bluevpn_github_json', 'پاسخ GitHub معتبر نیست.');
        }

        $s = self::settings();
        $candidates = [];
        foreach ($releases as $release) {
            if (!is_array($release) || !empty($release['draft']) || !empty($release['prerelease'])) continue;
            $tag = (string)($release['tag_name'] ?? '');
            if ($tag === '' || strpos($tag, $s['tag_prefix']) !== 0) continue;
            $version = substr($tag, strlen($s['tag_prefix']));
            if (!preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version)) continue;

            $asset_data = null;
            foreach ((array)($release['assets'] ?? []) as $asset) {
                if (($asset['name'] ?? '') === $s['asset_name'] && !empty($asset['browser_download_url'])) {
                    $asset_data = $asset;
                    break;
                }
            }
            if (!is_array($asset_data)) continue;

            $candidate = [
                'base_version' => $version,
                'version' => $version,
                'tag' => $tag,
                'package' => esc_url_raw((string)$asset_data['browser_download_url']),
                'url' => esc_url_raw((string)($release['html_url'] ?? self::repository_url())),
                'published_at' => sanitize_text_field((string)($release['published_at'] ?? '')),
                'release_updated_at' => sanitize_text_field((string)($release['updated_at'] ?? '')),
                'release_id' => (string)($release['id'] ?? ''),
                'asset_id' => (string)($asset_data['id'] ?? ''),
                'asset_updated_at' => sanitize_text_field((string)($asset_data['updated_at'] ?? '')),
                'asset_size' => (string)($asset_data['size'] ?? ''),
                'body' => wp_kses_post((string)($release['body'] ?? '')),
            ];
            $candidate['fingerprint'] = self::release_fingerprint($candidate);
            $candidates[] = $candidate;
        }

        if (!$candidates) {
            set_site_transient(self::CACHE_KEY, 'none', self::CACHE_TTL);
            return null;
        }

        usort($candidates, static fn($a, $b) => version_compare($b['version'], $a['version']));
        $latest = $candidates[0];
        $latest['version'] = self::effective_version($latest);
        set_site_transient(self::CACHE_KEY, $latest, self::CACHE_TTL);
        return $latest;
    }

    private static function installed_release(): array {
        $value = get_site_option(self::INSTALLED_RELEASE_KEY, []);
        return is_array($value) ? $value : [];
    }

    private static function remote_asset_is_newer_than_local_files(array $release): bool {
        $remote = strtotime((string)($release['asset_updated_at'] ?? $release['release_updated_at'] ?? ''));
        $local = defined('BLUEVPN_MANAGER_FILE') && is_file(BLUEVPN_MANAGER_FILE)
            ? (int)@filemtime(BLUEVPN_MANAGER_FILE)
            : 0;
        return $remote > 0 && $local > 0 && $remote > ($local + 60);
    }

    /**
     * Returns true for a normal semantic-version update, or for a replaced
     * GitHub release asset that has the same semantic version.
     */
    public static function update_available(?array $release = null): bool {
        if ($release === null) {
            $release = self::latest_release(false);
            if (!is_array($release)) return false;
        }

        $base = self::base_version($release);
        if (version_compare($base, BLUEVPN_MANAGER_VERSION, '>')) return true;
        if (version_compare($base, BLUEVPN_MANAGER_VERSION, '<')) return false;
        return self::same_version_asset_changed($release);
    }

    /**
     * WordPress wants a version string in its update response. For same-version
     * recovery updates, synthesize a sortable build suffix from GitHub time.
     */
    public static function advertised_version(array $release): string {
        return (string)($release['version'] ?? self::base_version($release));
    }

    public static function inject_update($transient) {
        if (!is_object($transient)) return $transient;
        if (empty($transient->checked) || !isset($transient->checked[self::plugin_basename()])) return $transient;

        $release = self::latest_release(false);
        if (is_wp_error($release) || !is_array($release)) return $transient;

        $item = (object)[
            'id' => self::repository_url(),
            'slug' => 'bluevpn-manager',
            'plugin' => self::plugin_basename(),
            'new_version' => self::advertised_version($release),
            'url' => $release['url'],
            'package' => $release['package'],
            'requires' => '6.2',
            'requires_php' => '8.0',
            'tested' => get_bloginfo('version'),
            'icons' => [],
            'banners' => [],
        ];

        if (self::update_available($release)) {
            $transient->response[self::plugin_basename()] = $item;
            unset($transient->no_update[self::plugin_basename()]);
        } else {
            // In the no-update response WordPress should see the real plugin
            // version, not the synthetic recovery suffix.
            $item->new_version = self::base_version($release);
            $transient->no_update[self::plugin_basename()] = $item;
            unset($transient->response[self::plugin_basename()]);
        }
        return $transient;
    }

    public static function plugin_info($result, string $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'bluevpn-manager') return $result;
        $release = self::latest_release(false);
        if (is_wp_error($release) || !is_array($release)) return $result;

        return (object)[
            'name' => 'BlueVPN Manager',
            'slug' => 'bluevpn-manager',
            'version' => self::advertised_version($release),
            'author' => '<a href="' . esc_url(self::repository_url()) . '">BlueVPN</a>',
            'homepage' => $release['url'],
            'requires' => '6.2',
            'requires_php' => '8.0',
            'download_link' => $release['package'],
            'sections' => [
                'description' => 'BlueVPN backend and management layer for WordPress/MySQL.',
                'changelog' => $release['body'] !== '' ? $release['body'] : 'جزئیات این نسخه در GitHub Release ثبت نشده است.',
            ],
        ];
    }

    public static function auto_update($update, $item) {
        if (!is_object($item)) return $update;
        $plugin = (string)($item->plugin ?? '');
        $slug = (string)($item->slug ?? '');
        if ($plugin !== self::plugin_basename() && $slug !== 'bluevpn-manager') return $update;
        return !empty(self::settings()['auto_update']);
    }

    public static function after_upgrade($upgrader, array $hook_extra): void {
        if (($hook_extra['type'] ?? '') !== 'plugin' || ($hook_extra['action'] ?? '') !== 'update') return;
        $plugins = (array)($hook_extra['plugins'] ?? []);
        if (!$plugins && !empty($hook_extra['plugin'])) $plugins = [(string)$hook_extra['plugin']];
        if (!in_array(self::plugin_basename(), $plugins, true)) return;

        self::clear_cache();
        delete_site_transient('update_plugins');

        $release = self::latest_release(true);
        if (is_array($release) && !empty($release['fingerprint'])) {
            update_site_option(self::INSTALLED_RELEASE_KEY, [
                'fingerprint' => (string)$release['fingerprint'],
                'tag' => (string)($release['tag'] ?? ''),
                'version' => self::base_version($release),
                'asset_id' => (string)($release['asset_id'] ?? ''),
                'asset_updated_at' => (string)($release['asset_updated_at'] ?? ''),
                'installed_at' => gmdate('c'),
            ]);
        }
    }
}
