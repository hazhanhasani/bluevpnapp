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
    private const AUTO_STATUS_OPTION = 'bluevpn_github_updater_auto_status_v3';
    private const KICK_LOCK = 'bluevpn_github_update_kick_lock_v3';
    private const UPDATE_LOCK = 'bluevpn_github_update_install_lock_v3';

    public static function init(): void {
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'inject_update']);
        add_filter('plugins_api', [self::class, 'plugin_info'], 20, 3);
        add_filter('auto_update_plugin', [self::class, 'auto_update'], 20, 2);
        add_filter('http_request_args', [self::class, 'authenticate_github_http'], 20, 2);
        add_action('upgrader_process_complete', [self::class, 'after_upgrade'], 10, 2);
        add_action(self::CRON_HOOK, [self::class, 'background_update_check']);
        add_action('admin_init', [self::class, 'ensure_schedule']);
        add_action('admin_init', [self::class, 'maybe_force_refresh']);
        add_action('init', [self::class, 'maybe_kick_background_check'], 20);
        self::ensure_schedule();
    }

    public static function ensure_schedule(): void {
        add_filter('cron_schedules', [self::class, 'cron_schedules']);
        $event = function_exists('wp_get_scheduled_event') ? wp_get_scheduled_event(self::CRON_HOOK) : null;
        if ($event && (string)($event->schedule ?? '') !== 'bluevpn_two_minutes') self::unschedule();
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 30, 'bluevpn_two_minutes', self::CRON_HOOK);
        }
    }

    public static function cron_schedules(array $schedules): array {
        if (!isset($schedules['bluevpn_two_minutes'])) {
            $schedules['bluevpn_two_minutes'] = [
                'interval' => 2 * MINUTE_IN_SECONDS,
                'display' => 'BlueVPN every 2 minutes',
            ];
        }
        return $schedules;
    }

    public static function unschedule(): void {
        while ($timestamp = wp_next_scheduled(self::CRON_HOOK)) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    public static function maybe_kick_background_check(): void {
        $cfg = self::settings();
        if (empty($cfg['auto_update'])) return;
        $last = self::last_background_check();
        if ($last > 0 && (time() - $last) < 2 * MINUTE_IN_SECONDS) return;
        if (get_transient(self::KICK_LOCK)) return;
        set_transient(self::KICK_LOCK, '1', 45);
        // Schedule an immediate single run in addition to the recurring event.
        // A direct non-blocking wp-cron request makes this work even when normal
        // page-triggered cron spawning on the host is unreliable.
        wp_schedule_single_event(time() + 1, self::CRON_HOOK, ['auto-kick']);
        $cronUrl = site_url('/wp-cron.php?doing_wp_cron=' . rawurlencode(sprintf('%.22F', microtime(true))));
        wp_remote_post($cronUrl, [
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ]);
    }

    private static function save_auto_status(string $status, string $message, string $target = ''): void {
        update_option(self::AUTO_STATUS_OPTION, [
            'status' => $status,
            'message' => sanitize_text_field($message),
            'target' => sanitize_text_field($target),
            'at' => time(),
        ], false);
    }

    public static function auto_update_status(): array {
        $value = get_option(self::AUTO_STATUS_OPTION, []);
        return is_array($value) ? wp_parse_args($value, ['status'=>'never','message'=>'','target'=>'','at'=>0]) : ['status'=>'never','message'=>'','target'=>'','at'=>0];
    }

    private static function install_available_release(array $release): array {
        if (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS) {
            return ['success'=>false,'message'=>'وردپرس اجازه تغییر فایل افزونه‌ها را نمی‌دهد (DISALLOW_FILE_MODS).'];
        }
        if (!self::update_available($release)) return ['success'=>true,'message'=>'نسخه جدیدی برای نصب وجود ندارد.'];
        if (!function_exists('wp_update_plugins')) require_once ABSPATH . 'wp-includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        delete_site_transient('update_plugins');
        self::clear_cache();
        // Re-cache the exact release being installed, then let our transient
        // filter expose it to Plugin_Upgrader.
        set_site_transient(self::CACHE_KEY, $release, self::CACHE_TTL);
        wp_update_plugins();
        $updates = get_site_transient('update_plugins');
        $plugin = self::plugin_basename();
        if (!is_object($updates) || empty($updates->response[$plugin])) {
            if (!is_object($updates)) $updates = new stdClass();
            if (empty($updates->checked) || !is_array($updates->checked)) $updates->checked = [];
            $updates->checked[$plugin] = BLUEVPN_MANAGER_VERSION;
            $updates = self::inject_update($updates);
            set_site_transient('update_plugins', $updates);
        }
        $updates = get_site_transient('update_plugins');
        if (!is_object($updates) || empty($updates->response[$plugin])) {
            return ['success'=>false,'message'=>'نسخه جدید در GitHub پیدا شد اما WordPress Update Transient آن را برای نصب آماده نکرد.'];
        }

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        $result = $upgrader->upgrade($plugin, ['clear_update_cache' => true]);
        if (is_wp_error($result)) return ['success'=>false,'message'=>$result->get_error_message()];
        if ($result === false) {
            $errors = method_exists($skin, 'get_errors') ? $skin->get_errors() : null;
            $message = is_wp_error($errors) ? $errors->get_error_message() : 'Plugin_Upgrader نتیجه ناموفق برگرداند.';
            return ['success'=>false,'message'=>$message];
        }
        return ['success'=>true,'message'=>'BlueVPN Manager به‌صورت خودکار به '.self::base_version($release).' بروزرسانی شد.'];
    }

    public static function background_update_check($kick = null): void {
        if (get_transient('bluevpn_github_update_check_lock')) return;
        set_transient('bluevpn_github_update_check_lock', '1', 4 * MINUTE_IN_SECONDS);
        try {
            self::clear_cache();
            delete_site_transient('update_plugins');
            update_option(self::LAST_CHECK_OPTION, time(), false);
            $release = self::latest_release(true);
            if (is_wp_error($release)) {
                self::save_auto_status('check_error', $release->get_error_message());
                return;
            }
            if (!is_array($release)) {
                self::save_auto_status('no_release', 'Release مخصوص BlueVPN Manager پیدا نشد.');
                return;
            }
            $target = self::base_version($release);
            if (!self::update_available($release)) {
                self::save_auto_status('up_to_date', 'BlueVPN Manager به‌روز است.', $target);
                return;
            }
            if (empty(self::settings()['auto_update'])) {
                self::save_auto_status('available', 'نسخه '.$target.' موجود است ولی نصب خودکار غیرفعال است.', $target);
                return;
            }
            if (get_transient(self::UPDATE_LOCK)) return;
            set_transient(self::UPDATE_LOCK, '1', 10 * MINUTE_IN_SECONDS);
            try {
                $result = self::install_available_release($release);
                self::save_auto_status(!empty($result['success']) ? 'installed' : 'install_error', (string)($result['message'] ?? ''), $target);
            } finally {
                delete_transient(self::UPDATE_LOCK);
            }
        } finally {
            delete_transient('bluevpn_github_update_check_lock');
            delete_transient(self::KICK_LOCK);
        }
    }

    private static function installed_version_from_disk(): string {
        if (defined('BLUEVPN_MANAGER_FILE') && is_file(BLUEVPN_MANAGER_FILE)) {
            $head = (string)@file_get_contents(BLUEVPN_MANAGER_FILE, false, null, 0, 8192);
            if ($head !== '' && preg_match('/(?mi)^\s*\*?\s*Version:\s*(\d+\.\d+\.\d+)\s*$/', $head, $m)) {
                return (string)$m[1];
            }
        }
        return BLUEVPN_MANAGER_VERSION;
    }

    /**
     * Immediate, authenticated self-update entry point used by the Telegram
     * deploy bot after the manager release workflow has completed.
     */
    public static function install_latest_now(string $targetVersion = ''): array {
        self::clear_cache();
        delete_site_transient('update_plugins');
        update_option(self::LAST_CHECK_OPTION, time(), false);
        $targetVersion = trim($targetVersion);
        $release = $targetVersion !== '' ? self::release_by_version($targetVersion, 5) : self::latest_release(true);
        if (is_wp_error($release)) {
            return ['success'=>false, 'message'=>$release->get_error_message(), 'target'=>'', 'installed_version'=>self::installed_version_from_disk()];
        }
        if (!is_array($release)) {
            return ['success'=>false, 'message'=>'Release مخصوص BlueVPN Manager پیدا نشد.' . ($targetVersion !== '' ? ' target=' . $targetVersion : ''), 'target'=>$targetVersion, 'installed_version'=>self::installed_version_from_disk()];
        }
        $target = self::base_version($release);
        $before = self::installed_version_from_disk();
        if (!self::update_available($release)) {
            return ['success'=>true, 'message'=>'BlueVPN Manager از قبل به‌روز است.', 'target'=>$target, 'before'=>$before, 'installed_version'=>$before, 'changed'=>false];
        }
        if (get_transient(self::UPDATE_LOCK)) {
            return ['success'=>false, 'message'=>'یک نصب خودکار Manager از قبل در حال اجراست.', 'target'=>$target, 'before'=>$before, 'installed_version'=>$before];
        }
        set_transient(self::UPDATE_LOCK, '1', 10 * MINUTE_IN_SECONDS);
        try {
            $result = self::install_available_release($release);
        } finally {
            delete_transient(self::UPDATE_LOCK);
        }
        $after = self::installed_version_from_disk();
        $ok = !empty($result['success']) && ($target === '' || version_compare($after, $target, '>='));
        $message = (string)($result['message'] ?? '');
        if (!$ok && $message === '') $message = 'نصب Manager کامل تأیید نشد.';
        self::save_auto_status($ok ? 'installed' : 'install_error', $message, $target);
        return ['success'=>$ok, 'message'=>$message, 'target'=>$target, 'before'=>$before, 'installed_version'=>$after, 'changed'=>version_compare($after, $before, '!=')];
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

    private static function github_token(): string {
        if (!class_exists('BlueVPN_Telegram_Bot') || !method_exists('BlueVPN_Telegram_Bot', 'github_token_for_internal_requests')) return '';
        try {
            return trim((string)BlueVPN_Telegram_Bot::github_token_for_internal_requests());
        } catch (Throwable $e) {
            return '';
        }
    }

    private static function request_headers(bool $binary = false): array {
        $headers = [
            'Accept' => $binary ? 'application/octet-stream' : 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'BlueVPN-Manager/' . BLUEVPN_MANAGER_VERSION . '; ' . home_url('/'),
        ];
        $token = self::github_token();
        if ($token !== '') $headers['Authorization'] = 'Bearer ' . $token;
        return $headers;
    }

    /**
     * WordPress core performs the package download itself during Plugin_Upgrader.
     * For a private repository the release asset API requires the same token as
     * the release-list request, so attach it only to the configured repository's
     * GitHub API/download URLs.
     */
    public static function authenticate_github_http(array $args, string $url): array {
        $token = self::github_token();
        if ($token === '') return $args;

        $s = self::settings();
        $owner = preg_quote((string)$s['owner'], '#');
        $repo = preg_quote((string)$s['repo'], '#');
        $isApiAsset = (bool)preg_match('#^https://api\.github\.com/repos/' . $owner . '/' . $repo . '/releases/assets/\d+(?:\?.*)?$#i', $url);
        $isBrowserAsset = (bool)preg_match('#^https://github\.com/' . $owner . '/' . $repo . '/releases/download/#i', $url);
        if (!$isApiAsset && !$isBrowserAsset) return $args;

        if (empty($args['headers']) || !is_array($args['headers'])) $args['headers'] = [];
        $args['headers']['Authorization'] = 'Bearer ' . $token;
        $args['headers']['User-Agent'] = 'BlueVPN-Manager/' . BLUEVPN_MANAGER_VERSION . '; ' . home_url('/');
        if ($isApiAsset) {
            $args['headers']['Accept'] = 'application/octet-stream';
            $args['headers']['X-GitHub-Api-Version'] = '2022-11-28';
        }
        return $args;
    }

    public static function diagnostics(): array {
        return [
            'repository' => self::settings()['owner'] . '/' . self::settings()['repo'],
            'authenticated' => self::github_token() !== '',
            'last_check' => self::last_background_check(),
            'auto_update' => !empty(self::settings()['auto_update']),
            'status' => self::auto_update_status(),
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
            'headers' => self::request_headers(false),
        ]);
        if (is_wp_error($response)) return $response;

        $status = (int)wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            $remaining = (string)wp_remote_retrieve_header($response, 'x-ratelimit-remaining');
            $reset = (string)wp_remote_retrieve_header($response, 'x-ratelimit-reset');
            $suffix = '';
            if ($remaining !== '') $suffix .= ' rate_remaining=' . $remaining;
            if ($reset !== '') $suffix .= ' rate_reset=' . $reset;
            $suffix .= self::github_token() !== '' ? ' auth=token' : ' auth=none';
            return new WP_Error('bluevpn_github_http', 'GitHub API HTTP ' . $status . $suffix);
        }

        $releases = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($releases)) {
            return new WP_Error('bluevpn_github_json', 'پاسخ GitHub معتبر نیست.');
        }

        $s = self::settings();
        // The Android release manager uses the same raw response when both
        // managers point at the same repository. This avoids duplicate GitHub
        // API traffic and keeps both release channels synchronized.
        if (class_exists('BlueVPN_App_Release_Manager')) {
            $app_cfg = BlueVPN_App_Release_Manager::settings();
            if (($app_cfg['owner'] ?? '') === $s['owner'] && ($app_cfg['repo'] ?? '') === $s['repo']) {
                BlueVPN_App_Release_Manager::ingest_releases($releases, 'shared_plugin_updater_poll', false);
            }
        }
        if (class_exists('BlueVPN_Windows_Release_Manager')) {
            $windows_cfg = BlueVPN_Windows_Release_Manager::settings();
            if (($windows_cfg['owner'] ?? '') === $s['owner'] && ($windows_cfg['repo'] ?? '') === $s['repo']) {
                BlueVPN_Windows_Release_Manager::ingest_releases($releases, 'shared_plugin_updater_poll', false);
            }
        }

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

            $assetApiUrl = esc_url_raw((string)($asset_data['url'] ?? ''));
            $browserDownloadUrl = esc_url_raw((string)$asset_data['browser_download_url']);
            $authenticatedAsset = self::github_token() !== '' && $assetApiUrl !== '';
            $candidate = [
                'base_version' => $version,
                'version' => $version,
                'tag' => $tag,
                'package' => $authenticatedAsset ? $assetApiUrl : $browserDownloadUrl,
                'browser_download_url' => $browserDownloadUrl,
                'asset_api_url' => $assetApiUrl,
                'authenticated_asset' => $authenticatedAsset,
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

    private static function normalize_exact_release(array $release, string $version): ?array {
        $s = self::settings();
        if (!empty($release['draft'])) return null;
        $tag = (string)($release['tag_name'] ?? '');
        $expectedTag = (string)$s['tag_prefix'] . $version;
        if ($tag === '' || !hash_equals($expectedTag, $tag)) return null;

        $assetData = null;
        foreach ((array)($release['assets'] ?? []) as $asset) {
            if (($asset['name'] ?? '') === $s['asset_name'] && !empty($asset['browser_download_url'])) {
                $assetData = $asset;
                break;
            }
        }
        if (!is_array($assetData)) return null;

        $assetApiUrl = esc_url_raw((string)($assetData['url'] ?? ''));
        $browserDownloadUrl = esc_url_raw((string)$assetData['browser_download_url']);
        $authenticatedAsset = self::github_token() !== '' && $assetApiUrl !== '';
        $candidate = [
            'base_version' => $version,
            'version' => $version,
            'tag' => $tag,
            'package' => $authenticatedAsset ? $assetApiUrl : $browserDownloadUrl,
            'browser_download_url' => $browserDownloadUrl,
            'asset_api_url' => $assetApiUrl,
            'authenticated_asset' => $authenticatedAsset,
            'url' => esc_url_raw((string)($release['html_url'] ?? self::repository_url())),
            'published_at' => sanitize_text_field((string)($release['published_at'] ?? '')),
            'release_updated_at' => sanitize_text_field((string)($release['updated_at'] ?? '')),
            'release_id' => (string)($release['id'] ?? ''),
            'asset_id' => (string)($assetData['id'] ?? ''),
            'asset_updated_at' => sanitize_text_field((string)($assetData['updated_at'] ?? '')),
            'asset_size' => (string)($assetData['size'] ?? ''),
            'body' => wp_kses_post((string)($release['body'] ?? '')),
        ];
        $candidate['fingerprint'] = self::release_fingerprint($candidate);
        $candidate['version'] = self::effective_version($candidate);
        return $candidate;
    }

    /**
     * Resolve one exact Manager release tag. The list endpoint can lag briefly
     * behind a just-completed release workflow, so the Deploy Bot uses this
     * endpoint as a bounded eventual-consistency fallback.
     *
     * @return array|WP_Error|null
     */
    public static function release_by_version(string $version, int $attempts = 4) {
        $version = trim($version);
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            return new WP_Error('bluevpn_manager_version_invalid', 'نسخه Manager برای Exact Release معتبر نیست.');
        }
        $s = self::settings();
        $tag = (string)$s['tag_prefix'] . $version;
        $url = 'https://api.github.com/repos/' . rawurlencode((string)$s['owner']) . '/' . rawurlencode((string)$s['repo']) . '/releases/tags/' . rawurlencode($tag);
        $attempts = max(1, min(8, $attempts));
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if (class_exists('BlueVPN_Error_Monitor')) {
                BlueVPN_Error_Monitor::expect_http_status_once($url, [404]);
            }
            $response = wp_remote_get($url, [
                'timeout' => 12,
                'redirection' => 3,
                'headers' => self::request_headers(false),
            ]);
            if (is_wp_error($response)) {
                if ($attempt < $attempts) { usleep(350000 * $attempt); continue; }
                return $response;
            }
            $status = (int)wp_remote_retrieve_response_code($response);
            if ($status === 404) {
                if ($attempt < $attempts) { usleep(500000 * $attempt); continue; }
                return null;
            }
            if ($status !== 200) {
                return new WP_Error('bluevpn_github_exact_http', 'GitHub Exact Release HTTP ' . $status . ' tag=' . $tag);
            }
            $payload = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($payload)) return new WP_Error('bluevpn_github_exact_json', 'پاسخ Exact Release گیت‌هاب معتبر نیست.');
            $normalized = self::normalize_exact_release($payload, $version);
            if (is_array($normalized)) return $normalized;
            if ($attempt < $attempts) { usleep(350000 * $attempt); continue; }
            return null;
        }
        return null;
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
