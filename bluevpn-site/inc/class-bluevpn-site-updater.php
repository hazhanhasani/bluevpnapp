<?php
if (!defined('ABSPATH')) exit;

/**
 * Self-updater for the BlueVPN Site theme.
 *
 * Release contract:
 * - Repository: inherited from BlueVPN Manager when available, otherwise
 *   hazhanhasani/bluevpnapp.
 * - Asset: bluevpn-site-theme-v<semver>.zip
 * - ZIP root: bluevpn-site/
 *
 * Version contract:
 * - BlueVPN Site uses the exact same semver as Android and BlueVPN Manager.
 * - The theme is still published as its own installable WordPress ZIP, but a
 *   full BlueVPN release is invalid if the three component versions differ.
 */
final class BlueVPN_Site_Updater {
    private const SLUG = 'bluevpn-site';
    private const DEFAULT_OWNER = 'hazhanhasani';
    private const DEFAULT_REPO = 'bluevpnapp';
    private const ASSET_PATTERN = '/^bluevpn-site-theme-v(\d+\.\d+\.\d+)\.zip$/i';
    private const CACHE_KEY = 'bluevpn_site_release_cache_v1';
    private const CACHE_TTL = 10 * MINUTE_IN_SECONDS;
    private const CRON_HOOK = 'bluevpn_site_github_update_check';
    private const LAST_CHECK_OPTION = 'bluevpn_site_updater_last_check_v1';
    private const STATUS_OPTION = 'bluevpn_site_updater_status_v1';
    private const KICK_LOCK = 'bluevpn_site_updater_kick_lock_v1';
    private const CHECK_LOCK = 'bluevpn_site_updater_check_lock_v1';
    private const INSTALL_LOCK = 'bluevpn_site_updater_install_lock_v1';

    public static function init(): void {
        add_filter('pre_set_site_transient_update_themes', [self::class, 'inject_update']);
        add_filter('auto_update_theme', [self::class, 'enable_auto_update'], 20, 2);
        add_filter('http_request_args', [self::class, 'authenticate_github_http'], 25, 2);
        add_filter('cron_schedules', [self::class, 'cron_schedules']);

        add_action(self::CRON_HOOK, [self::class, 'background_update_check']);
        add_action('admin_init', [self::class, 'ensure_schedule']);
        add_action('init', [self::class, 'maybe_kick_background_check'], 30);
        add_action('upgrader_process_complete', [self::class, 'after_upgrade'], 10, 2);
        add_action('switch_theme', [self::class, 'unschedule']);

        add_action('admin_menu', [self::class, 'admin_menu']);
        add_action('admin_post_bluevpn_site_check_update', [self::class, 'manual_check']);

        self::ensure_schedule();
    }

    private static function auto_enabled(): bool {
        if (defined('BLUEVPN_SITE_AUTO_UPDATE')) {
            return (bool) BLUEVPN_SITE_AUTO_UPDATE;
        }
        return true;
    }

    private static function installed_version(): string {
        if (function_exists('wp_clean_themes_cache')) wp_clean_themes_cache();
        $theme = wp_get_theme(self::SLUG);
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
                // Fall through to the stable default repository.
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
        if (!isset($schedules['bluevpn_ten_minutes'])) {
            $schedules['bluevpn_ten_minutes'] = [
                'interval' => 10 * MINUTE_IN_SECONDS,
                'display' => 'BlueVPN every 10 minutes',
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
        if ($event && (string)($event->schedule ?? '') !== 'bluevpn_ten_minutes') self::unschedule();
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'bluevpn_ten_minutes', self::CRON_HOOK);
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
        if ($last > 0 && (time() - $last) < 10 * MINUTE_IN_SECONDS) return;
        if (get_transient(self::KICK_LOCK)) return;

        set_transient(self::KICK_LOCK, '1', 90);
        wp_schedule_single_event(time() + 1, self::CRON_HOOK, ['auto-kick']);
        $cronUrl = site_url('/wp-cron.php?doing_wp_cron=' . rawurlencode(sprintf('%.22F', microtime(true))));
        wp_remote_post($cronUrl, [
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ]);
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

    public static function latest_release(bool $force = false) {
        if (!$force) {
            $cached = get_site_transient(self::CACHE_KEY);
            if (is_array($cached) && !empty($cached['version']) && !empty($cached['package'])) return $cached;
        }

        [$owner, $repo] = self::repository();
        $url = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/releases?per_page=100';
        $response = wp_remote_get($url, [
            'timeout' => 15,
            'redirection' => 5,
            'headers' => self::request_headers(false),
        ]);
        if (is_wp_error($response)) return $response;

        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('bluevpn_theme_github_http', 'خطای سرویس بروزرسانی: HTTP ' . $code);
        }
        $releases = json_decode((string)wp_remote_retrieve_body($response), true);
        if (!is_array($releases)) return new WP_Error('bluevpn_theme_github_json', 'پاسخ سرویس بروزرسانی پوسته معتبر نیست.');

        $best = null;
        foreach ($releases as $release) {
            if (!is_array($release) || !empty($release['draft']) || !empty($release['prerelease'])) continue;
            $assets = isset($release['assets']) && is_array($release['assets']) ? $release['assets'] : [];
            foreach ($assets as $asset) {
                if (!is_array($asset)) continue;
                $name = (string)($asset['name'] ?? '');
                if (!preg_match(self::ASSET_PATTERN, $name, $m)) continue;
                $version = $m[1];
                if ($best !== null && version_compare($version, (string)$best['version'], '<=')) continue;

                $apiAssetUrl = (string)($asset['url'] ?? '');
                $browserUrl = (string)($asset['browser_download_url'] ?? '');
                $package = $apiAssetUrl !== '' ? $apiAssetUrl : $browserUrl;
                if ($package === '') continue;

                $best = [
                    'version' => $version,
                    'package' => $package,
                    'asset_name' => $name,
                    'asset_id' => (string)($asset['id'] ?? ''),
                    'asset_updated_at' => (string)($asset['updated_at'] ?? ''),
                    'release_url' => (string)($release['html_url'] ?? ''),
                    'release_name' => (string)($release['name'] ?? $release['tag_name'] ?? ''),
                    'release_notes' => (string)($release['body'] ?? ''),
                ];
            }
        }

        if ($best === null) {
            return new WP_Error('bluevpn_theme_release_missing', 'هنوز بسته بروزرسانی جدیدی برای پوسته پیدا نشده است.');
        }

        set_site_transient(self::CACHE_KEY, $best, self::CACHE_TTL);
        return $best;
    }

    public static function inject_update($transient) {
        if (!is_object($transient)) $transient = new stdClass();
        if (empty($transient->checked) || !is_array($transient->checked)) $transient->checked = [];
        $installed = self::installed_version();
        $transient->checked[self::SLUG] = $installed;

        $release = self::latest_release(false);
        if (is_wp_error($release) || !is_array($release)) return $transient;
        $remote = (string)($release['version'] ?? '0.0.0');
        if (version_compare($remote, $installed, '<=')) return $transient;

        if (empty($transient->response) || !is_array($transient->response)) $transient->response = [];
        $transient->response[self::SLUG] = [
            'theme' => self::SLUG,
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
        if (is_object($item)) $theme = (string)($item->theme ?? '');
        elseif (is_array($item)) $theme = (string)($item['theme'] ?? '');
        return $theme === self::SLUG ? true : $update;
    }

    private static function install_release(array $release): array {
        if (!self::auto_enabled()) return ['success'=>true, 'message'=>'بروزرسانی خودکار پوسته غیرفعال است.'];
        if (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS) {
            return ['success'=>false, 'message'=>'وردپرس اجازه تغییر فایل پوسته را نمی‌دهد (DISALLOW_FILE_MODS).'];
        }
        $remote = (string)($release['version'] ?? '0.0.0');
        $installed = self::installed_version();
        if (version_compare($remote, $installed, '<=')) {
            return ['success'=>true, 'message'=>'پوسته BlueVPN بروز است.'];
        }
        if (get_transient(self::INSTALL_LOCK)) {
            return ['success'=>true, 'message'=>'نصب نسخه جدید پوسته هم‌اکنون در حال اجراست.'];
        }
        set_transient(self::INSTALL_LOCK, '1', 5 * MINUTE_IN_SECONDS);

        try {
            if (!function_exists('wp_update_themes')) require_once ABSPATH . 'wp-includes/update.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

            delete_site_transient('update_themes');
            set_site_transient(self::CACHE_KEY, $release, self::CACHE_TTL);
            wp_update_themes();

            $updates = get_site_transient('update_themes');
            if (!is_object($updates) || empty($updates->response[self::SLUG])) {
                if (!is_object($updates)) $updates = new stdClass();
                $updates = self::inject_update($updates);
                set_site_transient('update_themes', $updates);
            }

            $skin = new Automatic_Upgrader_Skin();
            $upgrader = new Theme_Upgrader($skin);
            $result = $upgrader->upgrade(self::SLUG, ['clear_update_cache' => true]);
            if (is_wp_error($result)) return ['success'=>false, 'message'=>$result->get_error_message()];
            if ($result === false) {
                $errors = method_exists($skin, 'get_errors') ? $skin->get_errors() : null;
                $message = is_wp_error($errors) ? $errors->get_error_message() : 'Theme_Upgrader نتیجه ناموفق برگرداند.';
                return ['success'=>false, 'message'=>$message];
            }

            self::clear_cache();
            delete_site_transient('update_themes');
            if (function_exists('wp_clean_themes_cache')) wp_clean_themes_cache();
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
        if (($hookExtra['theme'] ?? '') !== self::SLUG && (!is_array($themes) || !in_array(self::SLUG, $themes, true))) return;
        self::clear_cache();
        delete_site_transient('update_themes');
        if (function_exists('wp_clean_themes_cache')) wp_clean_themes_cache();
    }

    public static function admin_menu(): void {
        add_theme_page(
            'بروزرسانی پوسته BlueVPN',
            'آپدیت BlueVPN Site',
            'update_themes',
            'bluevpn-site-updater',
            [self::class, 'admin_page']
        );
    }

    public static function admin_page(): void {
        if (!current_user_can('update_themes')) wp_die('دسترسی کافی ندارید.');
        $release = self::latest_release(false);
        $status = self::status();
        [$owner, $repo] = self::repository();
        $installed = self::installed_version();
        $remote = is_array($release) ? (string)($release['version'] ?? 'نامشخص') : 'نامشخص';
        $last = (int)get_option(self::LAST_CHECK_OPTION, 0);

        echo '<div class="wrap"><h1>بروزرسانی BlueVPN Site</h1>';
        if (isset($_GET['bluevpn_site_checked'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html((string)wp_unslash($_GET['bluevpn_site_checked'])) . '</p></div>';
        }
        echo '<table class="widefat striped" style="max-width:850px"><tbody>';
        echo '<tr><td><strong>نسخه نصب‌شده</strong></td><td>' . esc_html($installed) . '</td></tr>';
        echo '<tr><td><strong>آخرین نسخه آنلاین</strong></td><td>' . esc_html($remote) . '</td></tr>';
        echo '<tr><td><strong>آپدیت خودکار</strong></td><td>' . (self::auto_enabled() ? 'فعال — هر ۱۰ دقیقه بررسی می‌شود' : 'غیرفعال') . '</td></tr>';
        echo '<tr><td><strong>آخرین بررسی</strong></td><td>' . ($last ? esc_html(wp_date('Y-m-d H:i:s', $last)) : 'هنوز اجرا نشده') . '</td></tr>';
        echo '<tr><td><strong>آخرین وضعیت</strong></td><td>' . esc_html((string)($status['message'] ?? '')) . '</td></tr>';
        echo '</tbody></table>';
        if (is_wp_error($release)) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html($release->get_error_message()) . '</p></div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:16px">';
        wp_nonce_field('bluevpn_site_check_update');
        echo '<input type="hidden" name="action" value="bluevpn_site_check_update">';
        submit_button('بررسی بروزرسانی', 'primary', 'submit', false);
        echo '</form></div>';
    }

    public static function manual_check(): void {
        if (!current_user_can('update_themes')) wp_die('دسترسی کافی ندارید.');
        check_admin_referer('bluevpn_site_check_update');
        self::clear_cache();
        delete_site_transient('update_themes');
        $release = self::latest_release(true);
        $message = '';
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
        wp_safe_redirect(add_query_arg([
            'page' => 'bluevpn-site-updater',
            'bluevpn_site_checked' => $message,
        ], admin_url('themes.php')));
        exit;
    }
}
