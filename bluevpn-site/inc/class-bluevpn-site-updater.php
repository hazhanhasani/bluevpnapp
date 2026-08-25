<?php
if (!defined('ABSPATH')) exit;

/**
 * Resilient self-updater for the BlueVPN Site theme.
 *
 * Release contract:
 * - tag:   bluevpn-site-v<semver>
 * - asset: bluevpn-site-theme-v<semver>.zip
 * - root:  bluevpn-site/
 *
 * The updater deliberately follows the newest BlueVPN Site release even when
 * GitHub marks it as prerelease. The BlueVPN installation may itself be on the
 * beta channel and the site theme must not silently stop updating in that case.
 */
final class BlueVPN_Site_Updater {
    private const CANONICAL_SLUG = 'bluevpn-site';
    private const DEFAULT_OWNER = 'hazhanhasani';
    private const DEFAULT_REPO = 'bluevpnapp';
    private const TAG_PREFIX = 'bluevpn-site-v';
    private const ASSET_PATTERN = '/^bluevpn-site-theme-v(\d+\.\d+\.\d+)\.zip$/i';
    private const CACHE_KEY = 'bluevpn_site_release_cache_v2';
    private const CACHE_TTL = 5 * MINUTE_IN_SECONDS;
    private const CRON_HOOK = 'bluevpn_site_github_update_check';
    private const LAST_CHECK_OPTION = 'bluevpn_site_updater_last_check_v2';
    private const STATUS_OPTION = 'bluevpn_site_updater_status_v2';
    private const KICK_LOCK = 'bluevpn_site_updater_kick_lock_v2';
    private const CHECK_LOCK = 'bluevpn_site_updater_check_lock_v2';
    private const INSTALL_LOCK = 'bluevpn_site_updater_install_lock_v2';

    public static function init(): void {
        add_filter('pre_set_site_transient_update_themes', [self::class, 'inject_update']);
        // Also repair stale/missing transients when WordPress reads them. This
        // covers hosts where the normal core update write cycle is infrequent.
        add_filter('site_transient_update_themes', [self::class, 'inject_update']);
        add_filter('auto_update_theme', [self::class, 'enable_auto_update'], 20, 2);
        add_filter('http_request_args', [self::class, 'authenticate_github_http'], 25, 2);
        add_filter('cron_schedules', [self::class, 'cron_schedules']);

        add_action(self::CRON_HOOK, [self::class, 'background_update_check']);
        add_action('admin_init', [self::class, 'ensure_schedule']);
        add_action('admin_init', [self::class, 'maybe_force_refresh']);
        add_action('init', [self::class, 'maybe_kick_background_check'], 30);
        add_action('upgrader_process_complete', [self::class, 'after_upgrade'], 10, 2);
        add_action('switch_theme', [self::class, 'unschedule']);

        add_action('admin_menu', [self::class, 'admin_menu']);
        add_action('admin_post_bluevpn_site_check_update', [self::class, 'manual_check']);

        self::ensure_schedule();
    }

    private static function auto_enabled(): bool {
        if (defined('BLUEVPN_SITE_AUTO_UPDATE')) return (bool) BLUEVPN_SITE_AUTO_UPDATE;
        return true;
    }

    /**
     * Use the real active stylesheet directory instead of assuming the folder
     * name is always bluevpn-site. Older manual installs may have another
     * directory name while still being the BlueVPN Site theme.
     */
    private static function stylesheet(): string {
        $active = function_exists('get_stylesheet') ? trim((string)get_stylesheet()) : '';
        if ($active !== '') {
            $theme = wp_get_theme($active);
            $name = strtolower(trim((string)$theme->get('Name')));
            $textDomain = strtolower(trim((string)$theme->get('TextDomain')));
            if ($name === 'bluevpn site' || $textDomain === 'bluevpn-site' || $active === self::CANONICAL_SLUG) {
                return $active;
            }
        }
        return self::CANONICAL_SLUG;
    }

    private static function installed_version(): string {
        if (function_exists('wp_clean_themes_cache')) wp_clean_themes_cache();
        $theme = wp_get_theme(self::stylesheet());
        $version = trim((string)$theme->get('Version'));
        return $version !== '' ? $version : (defined('BLUEVPN_SITE_VERSION') ? BLUEVPN_SITE_VERSION : '0.0.0');
    }

    private static function repository(): array {
        if (class_exists('BlueVPN_GitHub_Updater') && method_exists('BlueVPN_GitHub_Updater', 'settings')) {
            try {
                $settings = BlueVPN_GitHub_Updater::settings();
                $owner = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)($settings['owner'] ?? '')) ?: '';
                $repo = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)($settings['repo'] ?? '')) ?: '';
                if ($owner !== '' && $repo !== '') return [$owner, $repo];
            } catch (Throwable $e) {
                // Fall through to the canonical repository.
            }
        }
        return [self::DEFAULT_OWNER, self::DEFAULT_REPO];
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
            'User-Agent' => 'BlueVPN-Site/' . self::installed_version() . '; ' . home_url('/'),
            'Cache-Control' => 'no-cache',
        ];
        $token = self::github_token();
        if ($token !== '') $headers['Authorization'] = 'Bearer ' . $token;
        return $headers;
    }

    public static function authenticate_github_http(array $args, string $url): array {
        [$owner, $repo] = self::repository();
        $ownerRx = preg_quote($owner, '#');
        $repoRx = preg_quote($repo, '#');
        $apiAsset = (bool)preg_match('#^https://api\.github\.com/repos/' . $ownerRx . '/' . $repoRx . '/releases/assets/\d+(?:\?.*)?$#i', $url);
        $browserAsset = (bool)preg_match('#^https://github\.com/' . $ownerRx . '/' . $repoRx . '/releases/download/#i', $url);
        if (!$apiAsset && !$browserAsset) return $args;

        if (empty($args['headers']) || !is_array($args['headers'])) $args['headers'] = [];
        $token = self::github_token();
        if ($token !== '') $args['headers']['Authorization'] = 'Bearer ' . $token;
        $args['headers']['User-Agent'] = 'BlueVPN-Site/' . self::installed_version() . '; ' . home_url('/');
        if ($apiAsset) {
            $args['headers']['Accept'] = 'application/octet-stream';
            $args['headers']['X-GitHub-Api-Version'] = '2022-11-28';
        }
        return $args;
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

    public static function ensure_schedule(): void {
        if (!self::auto_enabled()) {
            self::unschedule();
            return;
        }
        $event = function_exists('wp_get_scheduled_event') ? wp_get_scheduled_event(self::CRON_HOOK) : null;
        if ($event && (string)($event->schedule ?? '') !== 'bluevpn_two_minutes') self::unschedule();
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 30, 'bluevpn_two_minutes', self::CRON_HOOK);
        }
    }

    public static function unschedule(): void {
        while ($timestamp = wp_next_scheduled(self::CRON_HOOK)) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    public static function maybe_kick_background_check(): void {
        if (!self::auto_enabled()) return;
        $last = (int)get_option(self::LAST_CHECK_OPTION, 0);
        if ($last > 0 && (time() - $last) < 2 * MINUTE_IN_SECONDS) return;
        if (get_transient(self::KICK_LOCK)) return;

        set_transient(self::KICK_LOCK, '1', 60);
        if (!wp_next_scheduled(self::CRON_HOOK, ['auto-kick'])) {
            wp_schedule_single_event(time() + 1, self::CRON_HOOK, ['auto-kick']);
        }
        $cronUrl = site_url('/wp-cron.php?doing_wp_cron=' . rawurlencode(sprintf('%.22F', microtime(true))));
        // 0.01s was too aggressive on some cPanel hosts and could abort before
        // the local HTTP connection was established. Keep it non-blocking but
        // give WordPress enough time to hand the request to the web server.
        wp_remote_post($cronUrl, [
            'timeout' => 1,
            'blocking' => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ]);
    }

    public static function maybe_force_refresh(): void {
        if (!is_admin() || !current_user_can('update_themes')) return;
        if (!isset($_GET['force-check']) && !isset($_GET['bluevpn_force_check'])) return;
        self::clear_cache();
        delete_site_transient('update_themes');
    }

    private static function set_status(string $status, string $message, string $target = ''): void {
        update_option(self::STATUS_OPTION, [
            'status' => sanitize_key($status),
            'message' => sanitize_text_field($message),
            'target' => sanitize_text_field($target),
            'at' => time(),
        ], false);
    }

    public static function status(): array {
        $status = get_option(self::STATUS_OPTION, []);
        return is_array($status)
            ? wp_parse_args($status, ['status'=>'never','message'=>'','target'=>'','at'=>0])
            : ['status'=>'never','message'=>'','target'=>'','at'=>0];
    }

    public static function clear_cache(): void {
        delete_site_transient(self::CACHE_KEY);
    }

    private static function parse_release(array $release): ?array {
        if (!empty($release['draft'])) return null;
        $assets = isset($release['assets']) && is_array($release['assets']) ? $release['assets'] : [];
        foreach ($assets as $asset) {
            if (!is_array($asset)) continue;
            $name = (string)($asset['name'] ?? '');
            if (!preg_match(self::ASSET_PATTERN, $name, $m)) continue;
            $version = (string)$m[1];
            $apiAssetUrl = (string)($asset['url'] ?? '');
            $browserUrl = (string)($asset['browser_download_url'] ?? '');
            // API assets are reliable for private repositories when a token is
            // present. Public repositories can use the direct browser URL.
            $package = self::github_token() !== '' && $apiAssetUrl !== '' ? $apiAssetUrl : $browserUrl;
            if ($package === '') $package = $apiAssetUrl;
            if ($package === '') continue;
            return [
                'version' => $version,
                'package' => $package,
                'asset_name' => $name,
                'asset_id' => (string)($asset['id'] ?? ''),
                'asset_updated_at' => (string)($asset['updated_at'] ?? ''),
                'release_url' => (string)($release['html_url'] ?? ''),
                'release_name' => (string)($release['name'] ?? $release['tag_name'] ?? ''),
                'release_notes' => (string)($release['body'] ?? ''),
                'tag' => (string)($release['tag_name'] ?? ''),
                'prerelease' => !empty($release['prerelease']),
            ];
        }
        return null;
    }

    private static function fetch_json(string $url) {
        $timeouts = [6, 10, 15];
        $retryable = [408, 425, 429, 500, 502, 503, 504];
        $lastError = null;

        foreach ($timeouts as $attempt => $timeout) {
            $headers = self::request_headers(false);
            // Sentinel must report an outage only after the bounded retry budget
            // is exhausted, rather than paging once for every transient attempt.
            if ($attempt < count($timeouts) - 1) $headers['X-BlueVPN-Sentinel-Transient'] = '1';
            $response = wp_remote_get($url, [
                'timeout' => $timeout,
                'redirection' => 5,
                'headers' => $headers,
            ]);
            if (is_wp_error($response)) {
                $lastError = $response;
            } else {
                $code = (int)wp_remote_retrieve_response_code($response);
                if ($code >= 200 && $code < 300) {
                    $decoded = json_decode((string)wp_remote_retrieve_body($response), true);
                    return is_array($decoded) ? $decoded : new WP_Error('bluevpn_theme_github_json', 'پاسخ سرویس بروزرسانی پوسته معتبر نیست.');
                }
                $lastError = new WP_Error('bluevpn_theme_github_http', 'خطای سرویس بروزرسانی: HTTP ' . $code);
                if (!in_array($code, $retryable, true)) return $lastError;
            }
            if ($attempt < count($timeouts) - 1) sleep($attempt + 1);
        }

        return $lastError ?: new WP_Error('bluevpn_theme_github_unavailable', 'سرویس بروزرسانی GitHub در دسترس نیست.');
    }

    /**
     * Read the source-declared site version. This is a fallback when the GitHub
     * release list is stale or reordered. It then resolves the exact theme tag.
     */
    private static function source_declared_version(): string {
        [$owner, $repo] = self::repository();
        $url = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/contents/release.json?ref=main';
        $payload = self::fetch_json($url);
        if (is_wp_error($payload) || !is_array($payload)) return '';
        $content = (string)($payload['content'] ?? '');
        if ($content === '') return '';
        $decoded = base64_decode(str_replace(["\r", "\n"], '', $content), true);
        if (!is_string($decoded) || $decoded === '') return '';
        $release = json_decode($decoded, true);
        if (!is_array($release)) return '';
        $version = trim((string)($release['site_version'] ?? $release['theme_version'] ?? $release['version'] ?? ''));
        return preg_match('/^\d+\.\d+\.\d+$/', $version) ? $version : '';
    }

    private static function exact_release(string $version) {
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) return null;
        [$owner, $repo] = self::repository();
        $tag = self::TAG_PREFIX . $version;
        $url = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/releases/tags/' . rawurlencode($tag);
        // A source-declared version can land on main a few seconds before the
        // dedicated theme-release workflow creates its GitHub Release. A 404
        // here is therefore an expected capability/consistency miss, not a
        // runtime outage. Sentinel suppresses only this one 404; transport,
        // rate-limit and 5xx failures remain fully observable.
        if (class_exists('BlueVPN_Error_Monitor') && method_exists('BlueVPN_Error_Monitor', 'expect_http_status_once')) {
            BlueVPN_Error_Monitor::expect_http_status_once($url, [404]);
        }
        $release = self::fetch_json($url);
        if (is_wp_error($release) || !is_array($release)) return null;
        return self::parse_release($release);
    }

    public static function latest_release(bool $force = false) {
        if (!$force) {
            $cached = get_site_transient(self::CACHE_KEY);
            if (is_array($cached) && !empty($cached['version']) && !empty($cached['package'])) return $cached;
        }

        [$owner, $repo] = self::repository();
        $url = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/releases?per_page=100';
        $releases = self::fetch_json($url);
        $best = null;
        if (is_array($releases)) {
            foreach ($releases as $release) {
                if (!is_array($release)) continue;
                // Do not skip prereleases: BlueVPN itself may be on beta and the
                // site theme must still receive its matching update.
                $candidate = self::parse_release($release);
                if ($candidate === null) continue;
                if ($best === null || version_compare((string)$candidate['version'], (string)$best['version'], '>')) {
                    $best = $candidate;
                }
            }
        }

        $declared = self::source_declared_version();
        if ($declared !== '' && ($best === null || version_compare($declared, (string)$best['version'], '>'))) {
            $exact = self::exact_release($declared);
            if (is_array($exact)) $best = $exact;
        }

        if ($best === null) {
            if (is_wp_error($releases)) return $releases;
            return new WP_Error('bluevpn_theme_release_missing', 'هنوز بسته بروزرسانی پوسته در GitHub پیدا نشده است.');
        }

        set_site_transient(self::CACHE_KEY, $best, self::CACHE_TTL);
        return $best;
    }

    public static function inject_update($transient) {
        if (!is_object($transient)) $transient = new stdClass();
        if (empty($transient->checked) || !is_array($transient->checked)) $transient->checked = [];
        $stylesheet = self::stylesheet();
        $installed = self::installed_version();
        $transient->checked[$stylesheet] = $installed;

        $release = self::latest_release(false);
        if (is_wp_error($release) || !is_array($release)) return $transient;
        $remote = (string)($release['version'] ?? '0.0.0');
        if (version_compare($remote, $installed, '<=')) {
            if (!empty($transient->response[$stylesheet])) unset($transient->response[$stylesheet]);
            return $transient;
        }

        if (empty($transient->response) || !is_array($transient->response)) $transient->response = [];
        $transient->response[$stylesheet] = [
            'theme' => $stylesheet,
            'new_version' => $remote,
            'url' => (string)($release['release_url'] ?? ''),
            'package' => (string)($release['package'] ?? ''),
            'requires' => '6.4',
            'requires_php' => '8.1',
        ];
        return $transient;
    }

    public static function enable_auto_update($update, $item) {
        if (!self::auto_enabled()) return $update;
        $theme = '';
        if (is_object($item)) $theme = (string)($item->theme ?? $item->stylesheet ?? '');
        elseif (is_array($item)) $theme = (string)($item['theme'] ?? $item['stylesheet'] ?? '');
        return in_array($theme, [self::stylesheet(), self::CANONICAL_SLUG], true) ? true : $update;
    }

    private static function verify_package(array $release): array {
        if (!class_exists('ZipArchive')) return ['success'=>true, 'message'=>'ZipArchive unavailable; WordPress upgrader will validate package.'];
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $package = (string)($release['package'] ?? '');
        if ($package === '') return ['success'=>false, 'message'=>'آدرس بسته بروزرسانی خالی است.'];
        $tmp = download_url($package, 60);
        if (is_wp_error($tmp)) return ['success'=>false, 'message'=>'دانلود بسته پوسته ناموفق بود: ' . $tmp->get_error_message()];
        try {
            $zip = new ZipArchive();
            $opened = $zip->open($tmp, ZipArchive::CHECKCONS);
            if ($opened !== true) return ['success'=>false, 'message'=>'فایل بروزرسانی پوسته ZIP معتبر نیست (code=' . (int)$opened . ').'];
            $style = $zip->getFromName('bluevpn-site/style.css');
            $zip->close();
            if (!is_string($style) || $style === '') return ['success'=>false, 'message'=>'ساختار بسته پوسته معتبر نیست؛ bluevpn-site/style.css پیدا نشد.'];
            if (!preg_match('/(?mi)^Version:\s*(\d+\.\d+\.\d+)\s*$/', $style, $m)) return ['success'=>false, 'message'=>'نسخه داخل بسته پوسته قابل تشخیص نیست.'];
            $target = (string)($release['version'] ?? '');
            if ($target !== '' && $m[1] !== $target) return ['success'=>false, 'message'=>'نسخه بسته پوسته با Release تطابق ندارد.'];
            return ['success'=>true, 'message'=>'Package verified.'];
        } finally {
            @unlink($tmp);
        }
    }

    private static function install_release(array $release): array {
        if (!self::auto_enabled()) return ['success'=>true, 'message'=>'بروزرسانی خودکار پوسته غیرفعال است.'];
        if (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS) return ['success'=>false, 'message'=>'وردپرس اجازه تغییر فایل پوسته را نمی‌دهد (DISALLOW_FILE_MODS).'];
        $remote = (string)($release['version'] ?? '0.0.0');
        $installed = self::installed_version();
        if (version_compare($remote, $installed, '<=')) return ['success'=>true, 'message'=>'پوسته BlueVPN Site بروز است.'];
        if (get_transient(self::INSTALL_LOCK)) return ['success'=>true, 'message'=>'نصب نسخه جدید پوسته هم‌اکنون در حال اجراست.'];
        set_transient(self::INSTALL_LOCK, '1', 10 * MINUTE_IN_SECONDS);

        try {
            $verified = self::verify_package($release);
            if (empty($verified['success'])) return $verified;

            if (!function_exists('wp_update_themes')) require_once ABSPATH . 'wp-includes/update.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

            $stylesheet = self::stylesheet();
            delete_site_transient('update_themes');
            set_site_transient(self::CACHE_KEY, $release, self::CACHE_TTL);
            wp_update_themes();

            $updates = get_site_transient('update_themes');
            if (!is_object($updates) || empty($updates->response[$stylesheet])) {
                if (!is_object($updates)) $updates = new stdClass();
                $updates = self::inject_update($updates);
                set_site_transient('update_themes', $updates);
            }

            $skin = new Automatic_Upgrader_Skin();
            $upgrader = new Theme_Upgrader($skin);
            $result = $upgrader->upgrade($stylesheet, ['clear_update_cache' => true]);
            if (is_wp_error($result) || $result === false) {
                $firstMessage = is_wp_error($result) ? $result->get_error_message() : 'Theme_Upgrader مسیر استاندارد را کامل نکرد.';
                // Recovery path for legacy/suffixed theme directories or stale
                // WordPress update transients. Overwrite the installed package
                // directly using the exact verified GitHub theme ZIP.
                $fallbackSkin = new Automatic_Upgrader_Skin();
                $fallback = new Theme_Upgrader($fallbackSkin);
                $fallbackResult = $fallback->install((string)$release['package'], [
                    'overwrite_package' => true,
                    'clear_update_cache' => true,
                ]);
                if (is_wp_error($fallbackResult)) return ['success'=>false, 'message'=>$firstMessage . ' | fallback: ' . $fallbackResult->get_error_message()];
                if ($fallbackResult === false) {
                    $errors = method_exists($fallbackSkin, 'get_errors') ? $fallbackSkin->get_errors() : null;
                    $message = is_wp_error($errors) ? $errors->get_error_message() : 'نصب جایگزین پوسته نیز ناموفق بود.';
                    return ['success'=>false, 'message'=>$firstMessage . ' | fallback: ' . $message];
                }
            }

            self::clear_cache();
            delete_site_transient('update_themes');
            if (function_exists('wp_clean_themes_cache')) wp_clean_themes_cache();
            $after = self::installed_version();
            if (version_compare($after, $remote, '<')) {
                return ['success'=>false, 'message'=>'نصب اجرا شد اما نسخه فعال پوسته هنوز ' . $after . ' است؛ هدف ' . $remote . ' بود.'];
            }
            return ['success'=>true, 'message'=>'پوسته BlueVPN Site به‌صورت خودکار به نسخه ' . $remote . ' بروزرسانی شد.'];
        } finally {
            delete_transient(self::INSTALL_LOCK);
        }
    }

    public static function background_update_check($kick = null): void {
        if (!self::auto_enabled()) return;
        if (get_transient(self::CHECK_LOCK)) return;
        set_transient(self::CHECK_LOCK, '1', 4 * MINUTE_IN_SECONDS);
        delete_transient(self::KICK_LOCK);
        update_option(self::LAST_CHECK_OPTION, time(), false);
        try {
            self::clear_cache();
            delete_site_transient('update_themes');
            $release = self::latest_release(true);
            if (is_wp_error($release)) {
                self::set_status('error', $release->get_error_message());
                return;
            }
            $remote = (string)($release['version'] ?? '');
            if ($remote === '' || version_compare($remote, self::installed_version(), '<=')) {
                self::set_status('current', 'پوسته BlueVPN Site بروز است.', $remote);
                return;
            }
            $result = self::install_release($release);
            self::set_status(!empty($result['success']) ? 'updated' : 'error', (string)($result['message'] ?? ''), $remote);
        } finally {
            delete_transient(self::CHECK_LOCK);
        }
    }

    public static function after_upgrade($upgrader, array $hookExtra): void {
        if (($hookExtra['type'] ?? '') !== 'theme') return;
        $themes = $hookExtra['themes'] ?? [];
        $stylesheet = self::stylesheet();
        if (($hookExtra['theme'] ?? '') !== $stylesheet && (!is_array($themes) || !in_array($stylesheet, $themes, true))) return;
        self::clear_cache();
        delete_site_transient('update_themes');
        if (function_exists('wp_clean_themes_cache')) wp_clean_themes_cache();
    }

    public static function admin_menu(): void {
        add_theme_page('بروزرسانی پوسته BlueVPN', 'آپدیت BlueVPN Site', 'update_themes', 'bluevpn-site-updater', [self::class, 'admin_page']);
    }

    public static function admin_page(): void {
        if (!current_user_can('update_themes')) wp_die('دسترسی کافی ندارید.');
        $release = self::latest_release(false);
        $status = self::status();
        [$owner, $repo] = self::repository();
        $installed = self::installed_version();
        $remote = is_array($release) ? (string)($release['version'] ?? 'نامشخص') : 'نامشخص';
        $last = (int)get_option(self::LAST_CHECK_OPTION, 0);
        $next = wp_next_scheduled(self::CRON_HOOK);

        echo '<div class="wrap"><h1>بروزرسانی BlueVPN Site</h1>';
        if (isset($_GET['bluevpn_site_checked'])) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html((string)wp_unslash($_GET['bluevpn_site_checked'])) . '</p></div>';
        echo '<table class="widefat striped" style="max-width:850px"><tbody>';
        echo '<tr><td><strong>نسخه نصب‌شده</strong></td><td>' . esc_html($installed) . '</td></tr>';
        echo '<tr><td><strong>آخرین نسخه آنلاین</strong></td><td>' . esc_html($remote) . '</td></tr>';
        echo '<tr><td><strong>پوشه فعال قالب</strong></td><td><code>' . esc_html(self::stylesheet()) . '</code></td></tr>';
        echo '<tr><td><strong>مخزن بروزرسانی</strong></td><td><code>' . esc_html($owner . '/' . $repo) . '</code></td></tr>';
        echo '<tr><td><strong>آپدیت خودکار</strong></td><td>' . (self::auto_enabled() ? 'فعال — هر ۲ دقیقه بررسی می‌شود' : 'غیرفعال') . '</td></tr>';
        echo '<tr><td><strong>اجرای بعدی</strong></td><td>' . ($next ? esc_html(wp_date('Y-m-d H:i:s', $next)) : 'زمان‌بندی نشده') . '</td></tr>';
        echo '<tr><td><strong>آخرین بررسی</strong></td><td>' . ($last ? esc_html(wp_date('Y-m-d H:i:s', $last)) : 'هنوز اجرا نشده') . '</td></tr>';
        echo '<tr><td><strong>آخرین وضعیت</strong></td><td>' . esc_html((string)($status['message'] ?? '')) . '</td></tr>';
        echo '</tbody></table>';
        if (is_wp_error($release)) echo '<div class="notice notice-warning inline"><p>' . esc_html($release->get_error_message()) . '</p></div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:16px">';
        wp_nonce_field('bluevpn_site_check_update');
        echo '<input type="hidden" name="action" value="bluevpn_site_check_update">';
        submit_button('بررسی و نصب بروزرسانی', 'primary', 'submit', false);
        echo '</form></div>';
    }

    public static function manual_check(): void {
        if (!current_user_can('update_themes')) wp_die('دسترسی کافی ندارید.');
        check_admin_referer('bluevpn_site_check_update');
        self::clear_cache();
        delete_site_transient('update_themes');
        $release = self::latest_release(true);
        if (is_wp_error($release)) {
            $message = $release->get_error_message();
        } else {
            $remote = (string)($release['version'] ?? '');
            if ($remote !== '' && version_compare($remote, self::installed_version(), '>')) {
                $result = self::install_release($release);
                $message = (string)($result['message'] ?? 'بررسی انجام شد.');
            } else {
                $message = 'پوسته BlueVPN Site بروز است.';
            }
        }
        wp_safe_redirect(add_query_arg(['page'=>'bluevpn-site-updater','bluevpn_site_checked'=>$message], admin_url('themes.php')));
        exit;
    }
}
