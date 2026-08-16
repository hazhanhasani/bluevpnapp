<?php
if (!defined('ABSPATH')) exit;

final class BlueVPN_Ads {
    private const MAX_IMAGE_BYTES = 6291456;
    private const MAX_STORY_VIDEO_BYTES = 12582912;

    public static function init(): void {
        add_filter('rest_pre_serve_request', [self::class, 'serve_raw_response'], 10, 4);
        foreach ([
            'bluevpn_ads_save' => 'save_settings',
            'bluevpn_ads_create' => 'create_ad',
            'bluevpn_ads_update' => 'update_ad',
            'bluevpn_ads_toggle' => 'toggle_ad',
            'bluevpn_ads_delete' => 'delete_ad',
            'bluevpn_story_save' => 'save_story_settings',
            'bluevpn_story_create' => 'create_story_ad',
            'bluevpn_story_update' => 'update_story_ad',
            'bluevpn_story_toggle' => 'toggle_story_ad',
            'bluevpn_story_delete' => 'delete_story_ad',
            'bluevpn_free_save' => 'save_free_settings',
            'bluevpn_free_add' => 'add_free_source',
            'bluevpn_free_toggle' => 'toggle_free_source',
            'bluevpn_free_delete' => 'delete_free_source',
        ] as $action => $method) {
            add_action('admin_post_' . $action, [self::class, $method]);
        }
    }

    private static function guard(string $nonce): void {
        if (!current_user_can('manage_options')) wp_die('دسترسی ندارید.');
        check_admin_referer($nonce);
    }

    private static function redirect(string $tab, string $message = '', string $error = ''): void {
        $args = ['page' => $tab === 'free' ? 'bluevpn-free-access' : 'bluevpn-ads'];
        if ($message !== '') $args['bluevpn_notice'] = $message;
        if ($error !== '') $args['bluevpn_error'] = $error;
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    private static function clean_time(string $value): string {
        $value = trim($value);
        if ($value === '') return '';
        try {
            // datetime-local has no timezone; interpret it in Tehran to match admin UX.
            if (!preg_match('/(?:Z|[+-]\d\d:\d\d)$/', $value)) {
                $dt = new DateTimeImmutable($value, new DateTimeZone('Asia/Tehran'));
            } else {
                $dt = new DateTimeImmutable($value);
            }
            return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable $e) {
            return '';
        }
    }

    private static function is_current(array $item): bool {
        if (array_key_exists('active', $item) && !BlueVPN_Utils::boolish($item['active'])) return false;
        $now = time();
        foreach (['start_at' => 'start', 'end_at' => 'end'] as $field => $kind) {
            $raw = trim((string)($item[$field] ?? ''));
            if ($raw === '') continue;
            $ts = strtotime($raw);
            if (!$ts) continue;
            if ($kind === 'start' && $now < $ts) return false;
            if ($kind === 'end' && $now >= $ts) return false;
        }
        return true;
    }

    private const TARGET_ACTIONS = ['none', 'auth', 'plans', 'purchase', 'account', 'renew', 'settings', 'external'];

    private static function normalize_target_action(string $value, string $legacyUrl = ''): string {
        $action = strtolower(trim($value));
        if (in_array($action, self::TARGET_ACTIONS, true)) return $action;
        return trim($legacyUrl) !== '' ? 'external' : 'none';
    }

    private static function clean_target_url(string $value): string {
        $value = trim($value);
        if ($value === '') return '';
        if (!wp_http_validate_url($value)) throw new RuntimeException('لینک خارجی/فال‌بک باید یک آدرس معتبر http یا https باشد.');
        return esc_url_raw($value);
    }

    private static function validate_target_plan_id(string $action, int $planId): int {
        if ($action !== 'purchase' || $planId <= 0) return 0;
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . BlueVPN_DB::table('plans') . ' WHERE id=%d AND active=1 AND deleted=0 LIMIT 1',
            $planId
        ));
        if (!$exists) throw new RuntimeException('پلن انتخاب‌شده برای تبلیغ فعال نیست یا پیدا نشد.');
        return (int)$exists;
    }

    private static function target_from_post(): array {
        $url = self::clean_target_url((string)wp_unslash($_POST['target_url'] ?? ''));
        $action = self::normalize_target_action((string)wp_unslash($_POST['target_action'] ?? ''), $url);
        $planId = self::validate_target_plan_id($action, max(0, (int)($_POST['target_plan_id'] ?? 0)));
        return ['action' => $action, 'plan_id' => $planId, 'url' => $url];
    }

    private static function deep_link(string $action, int $planId = 0): string {
        if (!in_array($action, ['auth', 'plans', 'purchase', 'account', 'renew', 'settings'], true)) return '';
        $link = 'bluevpn://' . $action;
        if ($action === 'purchase' && $planId > 0) $link .= '?plan_id=' . $planId;
        return $link;
    }

    private static function target_label(string $action, int $planId = 0): string {
        return match ($action) {
            'auth' => 'ورود / ثبت‌نام',
            'plans' => 'مشاهده پلن‌ها',
            'purchase' => $planId > 0 ? 'خرید پلن #' . $planId : 'خرید اشتراک',
            'account' => 'حساب کاربری',
            'renew' => 'تمدید / ارتقا',
            'settings' => 'تنظیمات',
            'external' => 'لینک خارجی',
            default => 'بدون عملکرد',
        };
    }

    public static function items(array $settings): array {
        $rows = is_array($settings['ads_items'] ?? null) ? $settings['ads_items'] : [];
        $out = [];
        foreach (array_slice($rows, 0, 100) as $raw) {
            if (!is_array($raw)) continue;
            $id = preg_replace('/[^A-Za-z0-9_-]+/', '', (string)($raw['id'] ?? '')) ?: '';
            if (strlen($id) < 6 || strlen($id) > 64) continue;
            $out[] = [
                'id' => $id,
                'title' => mb_substr(trim((string)($raw['title'] ?? '')), 0, 120),
                'subtitle' => mb_substr(trim((string)($raw['subtitle'] ?? '')), 0, 240),
                'image_url' => mb_substr(trim((string)($raw['image_url'] ?? $raw['image_path'] ?? '')), 0, 1200),
                'target_action' => self::normalize_target_action((string)($raw['target_action'] ?? ''), (string)($raw['target_url'] ?? '')),
                'target_plan_id' => max(0, (int)($raw['target_plan_id'] ?? 0)),
                'target_url' => mb_substr(trim((string)($raw['target_url'] ?? '')), 0, 1200),
                'button_text' => mb_substr(trim((string)($raw['button_text'] ?? '')), 0, 40),
                'active' => !array_key_exists('active', $raw) || BlueVPN_Utils::boolish($raw['active']),
                'sort_order' => max(-10000, min(10000, (int)($raw['sort_order'] ?? 0))),
                'start_at' => mb_substr(trim((string)($raw['start_at'] ?? '')), 0, 40),
                'end_at' => mb_substr(trim((string)($raw['end_at'] ?? '')), 0, 40),
            ];
        }
        usort($out, static fn($a, $b) => [$a['sort_order'], $a['id']] <=> [$b['sort_order'], $b['id']]);
        return $out;
    }

    private static function asset_extension(string $mime): string {
        return match (strtolower(trim($mime))) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            default => 'webp',
        };
    }

    /**
     * Materialize a DB-backed ad image into wp-content/uploads so Android can
     * download it as a normal static file. This avoids PHP/REST output
     * buffering, compression and Content-Length edge cases on cPanel.
     * Existing DB assets are migrated lazily and remain available through the
     * legacy /api/v1/ad-assets/{id} route as a fallback.
     */
    private static function static_asset_url_for_row(array $row): string {
        $payload = array_key_exists('payload', $row) ? (string)$row['payload'] : '';
        $sha = strtolower(trim((string)($row['sha256'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $sha)) {
            if ($payload === '') return '';
            $sha = hash('sha256', $payload);
        }
        $mime = strtolower(trim((string)($row['content_type'] ?? 'image/webp')));
        if (!in_array($mime, ['image/webp', 'image/jpeg', 'image/png', 'image/gif', 'video/mp4', 'video/webm'], true)) $mime = 'image/webp';
        $uploads = wp_upload_dir(null, false);
        if (!is_array($uploads) || !empty($uploads['error'])) return '';
        $baseDir = rtrim((string)($uploads['basedir'] ?? ''), '/\\');
        $baseUrl = rtrim((string)($uploads['baseurl'] ?? ''), '/');
        if ($baseDir === '' || $baseUrl === '') return '';
        $dir = $baseDir . '/bluevpn-ads';
        if (!wp_mkdir_p($dir)) return '';

        // Defense in depth: uploaded assets are images only; prevent accidental
        // script execution if the directory is ever reused incorrectly.
        $guard = $dir . '/.htaccess';
        if (!is_file($guard)) {
            @file_put_contents($guard, "<FilesMatch \"\.(php|phtml|phar|cgi|pl|py|sh)$\">\nRequire all denied\n</FilesMatch>\n");
        }
        $index = $dir . '/index.html';
        if (!is_file($index)) @file_put_contents($index, '');

        $ext = self::asset_extension($mime);
        $filename = $sha . '.' . $ext;
        $path = $dir . '/' . $filename;
        $expectedSize = max(0, (int)($row['byte_size'] ?? 0));
        $valid = is_file($path) && ($expectedSize <= 0 || (int)@filesize($path) === $expectedSize);
        if ($valid) return esc_url_raw($baseUrl . '/bluevpn-ads/' . rawurlencode($filename));
        if ($payload === '') return '';
        if (!$valid) {
            $tmp = $path . '.tmp-' . wp_generate_password(8, false, false);
            $written = @file_put_contents($tmp, $payload, LOCK_EX);
            if ($written !== strlen($payload)) { @unlink($tmp); return ''; }
            @chmod($tmp, 0644);
            if (!@rename($tmp, $path)) {
                $copied = @copy($tmp, $path);
                @unlink($tmp);
                if (!$copied) return '';
            }
            @chmod($path, 0644);
        }
        return esc_url_raw($baseUrl . '/bluevpn-ads/' . rawurlencode($filename));
    }

    private static function static_asset_url_by_id(string $id): string {
        global $wpdb;
        $id = preg_replace('/[^A-Za-z0-9_-]+/', '', $id) ?: '';
        if ($id === '') return '';
        $table = BlueVPN_DB::table('ad_assets');
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id, content_type, sha256, byte_size FROM ' . $table . ' WHERE id=%s LIMIT 1',
            $id
        ), ARRAY_A);
        if (!is_array($row)) return '';
        $fast = self::static_asset_url_for_row($row);
        if ($fast !== '') return $fast;
        $full = $wpdb->get_row($wpdb->prepare(
            'SELECT id, content_type, payload, sha256, byte_size FROM ' . $table . ' WHERE id=%s LIMIT 1',
            $id
        ), ARRAY_A);
        return is_array($full) ? self::static_asset_url_for_row($full) : '';
    }

    private static function resolve_asset_path(string $value): string {
        global $wpdb;
        $value = trim($value);
        if ($value === '') return '';

        // During the Railway -> WordPress migration some ad settings may still
        // contain an absolute Railway URL. Treat our historical local routes as
        // local even when a host is present, otherwise shutting Railway down
        // would silently break the banner image.
        $candidatePath = $value;
        if (wp_http_validate_url($value)) {
            $parsed = wp_parse_url($value);
            $candidatePath = is_array($parsed) ? (string)($parsed['path'] ?? '') : '';
        }

        if (preg_match('#^/api/v1/ad-assets/([A-Za-z0-9_-]{6,64})/?$#', $candidatePath, $m)) {
            $static = self::static_asset_url_by_id((string)$m[1]);
            if ($static !== '') return $static;
            $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . BlueVPN_DB::table('ad_assets') . ' WHERE id=%s LIMIT 1', $m[1]));
            return $exists ? '/api/v1/ad-assets/' . rawurlencode((string)$m[1]) : '';
        }
        // Legacy Railway media paths can be recovered by matching the migrated filename.
        if (str_starts_with($candidatePath, '/media/ads/')) {
            $name = sanitize_file_name(basename($candidatePath));
            $id = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . BlueVPN_DB::table('ad_assets') . ' WHERE filename=%s ORDER BY created_at DESC LIMIT 1', $name));
            if (!$id) return '';
            $static = self::static_asset_url_by_id((string)$id);
            return $static !== '' ? $static : '/api/v1/ad-assets/' . rawurlencode((string)$id);
        }
        if (wp_http_validate_url($value)) return esc_url_raw($value);
        return '';
    }

    private static function client_version(?WP_REST_Request $request): string {
        $ua = $request ? (string)$request->get_header('user-agent') : (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        return preg_match('/(?:^|\s)BlueVPN\/([0-9]+(?:\.[0-9]+){1,3})/i', $ua, $m) ? $m[1] : '';
    }

    private static function version_key(string $value): array {
        preg_match_all('/\d+/', $value, $m);
        $parts = array_map('intval', array_slice($m[0] ?? [], 0, 4));
        return array_pad($parts, 4, 0);
    }

    public static function advertising_payload(array $settings, ?WP_REST_Request $request = null): array {
        $rows = [];
        foreach (self::items($settings) as $item) {
            if (!self::is_current($item)) continue;
            $image = self::resolve_asset_path((string)$item['image_url']);
            if ($image === '') continue;
            $target = wp_http_validate_url((string)$item['target_url']) ? esc_url_raw((string)$item['target_url']) : '';
            $targetAction = self::normalize_target_action((string)($item['target_action'] ?? ''), $target);
            $targetPlanId = $targetAction === 'purchase' ? max(0, (int)($item['target_plan_id'] ?? 0)) : 0;
            $rows[] = [
                'id' => $item['id'],
                'title' => $item['title'],
                'subtitle' => $item['subtitle'],
                // Keep local assets relative: Android resolves them against apiBaseUrl.
                'image_url' => $image,
                'image_path' => str_starts_with($image, '/api/v1/ad-assets/') ? $image : '',
                'target_action' => $targetAction,
                'target_plan_id' => $targetPlanId,
                'deep_link' => self::deep_link($targetAction, $targetPlanId),
                'target_url' => $target,
                'button_text' => $item['button_text'],
            ];
        }
        $configured = !empty($settings['ads_enabled']) && !empty($rows);
        $version = self::client_version($request);
        $supported = $version === '' || self::version_key($version) >= self::version_key('3.0.48');
        $enabled = $configured && $supported;
        if (class_exists('BlueVPN_Free_Sources')) {
            $curatedCount=count(BlueVPN_Free_Sources::curated(300));
            if($curatedCount>0){
                $public[]=[
                    'id'=>'telegram-curated','name'=>'Pool هوشمند رایگان','subscription_url'=>$base.'/api/v1/free/curated','priority'=>5,
                ];
                $legacyPoolEnabled = $mode !== 'warp_only' && !empty($settings['free_access_enabled']);
                $fallbackEnabled = $mode === 'warp_fallback_pool' && $legacyPoolEnabled && (!array_key_exists('free_warp_fallback_enabled', $settings) || !empty($settings['free_warp_fallback_enabled']));
                $enabled = $warpEnabled || $legacyPoolEnabled;
            }
        }
        return [
            'enabled' => $enabled,
            'autoplay' => !empty($settings['ads_autoplay']),
            'loop' => !empty($settings['ads_loop']),
            'interval_ms' => max(3000, min(30000, (int)($settings['ads_interval_seconds'] ?? 6) * 1000)),
            'height_dp' => max(116, min(160, (int)($settings['ads_height_dp'] ?? 146))),
            'aspect_ratio' => '20:9',
            'required_client_version' => '3.0.48',
            'disabled_reason' => $configured && !$supported ? 'old_client_layout' : '',
            'items' => $enabled ? $rows : [],
        ];
    }

    /** Backward-compatible aliases for older call sites. */
    public static function public_config(?WP_REST_Request $request = null): array {
        return self::advertising_payload(BlueVPN_DB::settings(), $request);
    }

    public static function free_public_config(): array {
        return self::free_access_payload(BlueVPN_DB::settings());
    }

    public static function story_items(array $settings): array {
        $rows = is_array($settings['free_story_ads_items'] ?? null) ? $settings['free_story_ads_items'] : [];
        $out = [];
        foreach (array_slice($rows, 0, 100) as $raw) {
            if (!is_array($raw)) continue;
            $id = preg_replace('/[^A-Za-z0-9_-]+/', '', (string)($raw['id'] ?? '')) ?: '';
            if (strlen($id) < 6 || strlen($id) > 64) continue;
            $type = strtolower(trim((string)($raw['media_type'] ?? 'image')));
            if (!in_array($type, ['image', 'video'], true)) $type = 'image';
            $out[] = [
                'id' => $id,
                'title' => mb_substr(trim((string)($raw['title'] ?? '')), 0, 120),
                'subtitle' => mb_substr(trim((string)($raw['subtitle'] ?? '')), 0, 240),
                'media_type' => $type,
                'media_url' => mb_substr(trim((string)($raw['media_url'] ?? '')), 0, 1200),
                'target_action' => self::normalize_target_action((string)($raw['target_action'] ?? ''), (string)($raw['target_url'] ?? '')),
                'target_plan_id' => max(0, (int)($raw['target_plan_id'] ?? 0)),
                'target_url' => mb_substr(trim((string)($raw['target_url'] ?? '')), 0, 1200),
                'button_text' => mb_substr(trim((string)($raw['button_text'] ?? '')), 0, 40),
                'active' => !array_key_exists('active', $raw) || BlueVPN_Utils::boolish($raw['active']),
                'weight' => max(1, min(100, (int)($raw['weight'] ?? 1))),
                'image_duration_seconds' => max(3, min(30, (int)($raw['image_duration_seconds'] ?? ($settings['free_story_ads_image_seconds'] ?? 6)))),
                'start_at' => mb_substr(trim((string)($raw['start_at'] ?? '')), 0, 40),
                'end_at' => mb_substr(trim((string)($raw['end_at'] ?? '')), 0, 40),
            ];
        }
        return $out;
    }

    public static function free_story_payload(array $settings): array {
        $rows = [];
        foreach (self::story_items($settings) as $item) {
            if (!self::is_current($item)) continue;
            $media = self::resolve_asset_path((string)$item['media_url']);
            if ($media === '') continue;
            $target = wp_http_validate_url((string)$item['target_url']) ? esc_url_raw((string)$item['target_url']) : '';
            $targetAction = self::normalize_target_action((string)($item['target_action'] ?? ''), $target);
            $targetPlanId = $targetAction === 'purchase' ? max(0, (int)($item['target_plan_id'] ?? 0)) : 0;
            $rows[] = [
                'id' => $item['id'],
                'title' => $item['title'],
                'subtitle' => $item['subtitle'],
                'media_type' => $item['media_type'],
                'media_url' => $media,
                'target_action' => $targetAction,
                'target_plan_id' => $targetPlanId,
                'deep_link' => self::deep_link($targetAction, $targetPlanId),
                'target_url' => $target,
                'button_text' => $item['button_text'],
                'weight' => $item['weight'],
                'image_duration_seconds' => $item['image_duration_seconds'],
            ];
        }
        $enabled = !empty($settings['free_story_ads_enabled']) && !empty($rows);
        return [
            'enabled' => $enabled,
            'required' => $enabled && (!array_key_exists('free_story_ads_required', $settings) || !empty($settings['free_story_ads_required'])),
            'free_only' => true,
            'random' => true,
            'every_connection' => true,
            'image_duration_seconds' => max(3, min(30, (int)($settings['free_story_ads_image_seconds'] ?? 6))),
            'load_timeout_ms' => max(3000, min(15000, (int)($settings['free_story_ads_load_timeout_seconds'] ?? 8) * 1000)),
            'max_video_seconds' => max(5, min(60, (int)($settings['free_story_ads_max_video_seconds'] ?? 30))),
            'items' => $enabled ? $rows : [],
        ];
    }

    public static function tapsell_payload(array $settings): array {
        $appKey = trim((string)($settings['tapsell_app_key'] ?? ''));
        $zone = trim((string)($settings['tapsell_interstitial_zone_id'] ?? ''));
        $requested = !empty($settings['tapsell_enabled']);
        $enabled = $requested && $appKey !== '' && $zone !== '';
        return [
            'enabled' => $enabled,
            'app_key' => $enabled ? $appKey : '',
            'interstitial_zone_id' => $enabled ? $zone : '',
            'show_after_connect' => !array_key_exists('tapsell_show_after_connect', $settings) || !empty($settings['tapsell_show_after_connect']),
            'free_only' => true,
            'min_interval_seconds' => max(0, min(86400, (int)($settings['tapsell_min_interval_seconds'] ?? 0))),
            'daily_cap' => max(0, min(1000, (int)($settings['tapsell_daily_cap'] ?? 0))),
            'disabled_reason' => $enabled ? '' : ($requested ? 'missing_credentials' : 'disabled'),
        ];
    }

    public static function free_sources(array $settings, bool $activeOnly = false): array {
        $rows = is_array($settings['free_subscription_items'] ?? null) ? $settings['free_subscription_items'] : [];
        if (!$rows && trim((string)($settings['free_subscription_url'] ?? '')) !== '') {
            $rows[] = ['id' => 'legacy-default', 'name' => 'ساب رایگان اصلی', 'url' => (string)$settings['free_subscription_url'], 'active' => true, 'priority' => 0];
        }
        $seen = [];
        $out = [];
        foreach (array_slice($rows, 0, 100) as $i => $raw) {
            if (!is_array($raw)) continue;
            $id = preg_replace('/[^A-Za-z0-9_-]+/', '', (string)($raw['id'] ?? '')) ?: substr(hash('sha256', wp_json_encode($raw) . ':' . $i), 0, 24);
            $id = substr($id, 0, 64);
            if (isset($seen[$id])) $id = substr(hash('sha256', $id . ':' . $i), 0, 24);
            $seen[$id] = true;
            $url = trim((string)($raw['url'] ?? ''));
            $active = !array_key_exists('active', $raw) || BlueVPN_Utils::boolish($raw['active']);
            if ($activeOnly && (!$active || !wp_http_validate_url($url))) continue;
            $out[] = [
                'id' => $id,
                'name' => mb_substr(trim((string)($raw['name'] ?? 'ساب رایگان ' . ($i + 1))), 0, 120),
                'url' => mb_substr($url, 0, 1200),
                'active' => $active,
                'priority' => max(0, min(9999, (int)($raw['priority'] ?? $i))),
            ];
        }
        usort($out, static fn($a, $b) => [$a['priority'], $a['name'], $a['id']] <=> [$b['priority'], $b['name'], $b['id']]);
        return $out;
    }

    public static function free_access_payload(array $settings): array {
        $items = self::free_sources($settings, true);
        $mode = sanitize_key((string)($settings['free_warp_mode'] ?? 'warp_fallback_pool'));
        if (!in_array($mode, ['warp_only', 'warp_fallback_pool', 'pool_only'], true)) $mode = 'warp_fallback_pool';

        $warpEnabled =
            $mode !== 'pool_only' &&
            (!array_key_exists('free_warp_enabled', $settings) || !empty($settings['free_warp_enabled']));

        // Migration-safe intent inference: older 4.11.7 settings may have WARP
        // disabled while `free_access_enabled` remained false because the UI
        // called it "legacy pool". Treat that state as pool-only automatically.
        $poolRequested =
            !empty($settings['free_access_enabled']) ||
            (!$warpEnabled && $mode !== 'warp_only');

        $smartPoolAvailable =
            $poolRequested &&
            class_exists('BlueVPN_Free_Sources') &&
            BlueVPN_Free_Sources::has_enabled_sources();

        if ($smartPoolAvailable) {
            // First-class local subscription generated from Telegram/public
            // collectors and continuously re-ranked by real user-network probes.
            $items[] = [
                'id' => 'smart-curated',
                'name' => 'BlueVPN Smart Free Pool',
                'url' => 'bluevpn://smart-curated',
                'active' => true,
                'priority' => -100,
            ];
        }
        $items = array_values(array_reduce($items, static function(array $carry,array $item): array {
            $carry[(string)$item['id']] = $item;
            return $carry;
        }, []));
        usort($items, static fn($a,$b) => [(int)$a['priority'],(string)$a['name']] <=> [(int)$b['priority'],(string)$b['name']]);

        $legacyPoolEnabled = $mode !== 'warp_only' && $poolRequested && !empty($items);
        $fallbackEnabled = $mode === 'warp_fallback_pool' && $legacyPoolEnabled && (!array_key_exists('free_warp_fallback_enabled', $settings) || !empty($settings['free_warp_fallback_enabled']));
        $enabled = $warpEnabled || $legacyPoolEnabled;
        $base = untrailingslashit(home_url('/'));
        $public = [];
        foreach ($items as $item) {
            $public[] = [
                'id' => $item['id'],
                'name' => $item['name'],
                'subscription_url' => $legacyPoolEnabled ? $base . '/api/v1/free/subscriptions/' . rawurlencode($item['id']) : '',
                'priority' => $item['priority'],
            ];
        }
        return [
            'enabled' => $enabled,
            'session_minutes' => max(15, min(180, (int)($settings['free_session_minutes'] ?? 60))),
            'auto_only' => true,
            'guest_allowed' => !array_key_exists('free_warp_guest_allowed', $settings) || !empty($settings['free_warp_guest_allowed']),
            'account_required_for_free' => false,
            'manual_selection_requires_subscription' => true,
            'engine_mode' => $mode,
            'warp' => [
                'enabled' => $warpEnabled,
                'mode' => $mode,
                'fallback_pool_enabled' => $fallbackEnabled,
                'schema' => 2,
                'start_timeout_seconds' => max(3, min(40, (int)($settings['free_warp_start_timeout_seconds'] ?? 30))),
                'adaptive_strategy_enabled' => !array_key_exists('free_warp_adaptive_enabled',$settings) || !empty($settings['free_warp_adaptive_enabled']),
                'endpoint_racing_enabled' => !array_key_exists('free_warp_endpoint_racing_enabled',$settings) || !empty($settings['free_warp_endpoint_racing_enabled']),
                'endpoint_race_breadth' => max(2, min(16, (int)($settings['free_warp_endpoint_race_breadth'] ?? 8))),
                'endpoint_probe_seconds' => max(3, min(8, (int)($settings['free_warp_endpoint_probe_seconds'] ?? 5))),
                'quick_reconnect' => !array_key_exists('free_warp_quick_reconnect',$settings) || !empty($settings['free_warp_quick_reconnect']),
                'allowed_transports' => array_values(array_intersect((array)($settings['free_warp_allowed_transports'] ?? ['h3','h2','h2_fragment','wireguard']), ['h3','h2','h2_fragment','wireguard','gool'])),
                'scan_mode' => in_array(($settings['free_warp_scan_mode'] ?? 'turbo'), ['turbo','balanced','thorough','stealth','ironclad'], true) ? $settings['free_warp_scan_mode'] : 'turbo',
                'ip_mode' => in_array(($settings['free_warp_ip_mode'] ?? 'auto'), ['auto','v4','dual'], true) ? $settings['free_warp_ip_mode'] : 'auto',
                'h2_enabled' => !array_key_exists('free_warp_h2_enabled',$settings) || !empty($settings['free_warp_h2_enabled']),
                'fragment_enabled' => !array_key_exists('free_warp_fragment_enabled',$settings) || !empty($settings['free_warp_fragment_enabled']),
                'fragment_size' => preg_match('/^\d{1,3}(?:-\d{1,3})?$/', (string)($settings['free_warp_fragment_size'] ?? '8-24')) ? (string)$settings['free_warp_fragment_size'] : '8-24',
                'fragment_delay' => preg_match('/^\d{1,3}(?:-\d{1,3})?$/', (string)($settings['free_warp_fragment_delay'] ?? '5-15')) ? (string)$settings['free_warp_fragment_delay'] : '5-15',
                'wireguard_enabled' => !array_key_exists('free_warp_wireguard_enabled',$settings) || !empty($settings['free_warp_wireguard_enabled']),
                'warp_in_warp_enabled' => !empty($settings['free_warp_gool_enabled']),
                'warm_timeout_seconds' => max(4, min(12, (int)($settings['free_warp_warm_timeout_seconds'] ?? 8))),
                'cold_timeout_seconds' => max(15, min(40, (int)($settings['free_warp_cold_timeout_seconds'] ?? 30))),
                'total_timeout_seconds' => max(30, min(90, (int)($settings['free_warp_total_timeout_seconds'] ?? 75))),
                'noize_profile' => in_array(($settings['free_warp_noize_profile'] ?? 'firewall'), ['off','light','balanced','aggressive','firewall','gfw'], true) ? $settings['free_warp_noize_profile'] : 'firewall',
                'require_exit_trace' => !array_key_exists('free_warp_require_exit_trace', $settings) || !empty($settings['free_warp_require_exit_trace']),
                'blocked_exit_countries' => array_values(array_unique(array_filter(array_map(static function($code){ $code = strtoupper(trim((string)$code)); return preg_match('/^[A-Z]{2}$/', $code) ? $code : ''; }, (array)($settings['free_warp_blocked_exit_countries'] ?? []))))),
                'provider' => 'Cloudflare WARP',
                'runtime' => 'Aether',
                'guest_allowed' => !array_key_exists('free_warp_guest_allowed', $settings) || !empty($settings['free_warp_guest_allowed']),
            ],
            'legacy_pool_enabled' => $legacyPoolEnabled,
            'subscription_url' => $public[0]['subscription_url'] ?? '',
            'subscriptions' => $public,
            'label' => $warpEnabled ? 'اتصال رایگان WARP' : 'اتصال رایگان',
        ];
    }

    public static function asset_response(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $id = preg_replace('/[^A-Za-z0-9_-]+/', '', (string)$request['asset_id']) ?: '';
        if (strlen($id) < 6 || strlen($id) > 64) return new WP_REST_Response(['detail' => ['code' => 'AD_ASSET_NOT_FOUND', 'message' => 'تصویر تبلیغ پیدا نشد']], 404);
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . BlueVPN_DB::table('ad_assets') . ' WHERE id=%s LIMIT 1', $id), ARRAY_A);
        if (!$row || empty($row['payload'])) return new WP_REST_Response(['detail' => ['code' => 'AD_ASSET_NOT_FOUND', 'message' => 'تصویر تبلیغ پیدا نشد']], 404);
        $etag = '"' . ((string)($row['sha256'] ?: hash('sha256', (string)$row['payload']))) . '"';
        if (trim((string)$request->get_header('if-none-match')) === $etag) {
            $res = new WP_REST_Response('', 304);
        } else {
            $res = new WP_REST_Response((string)$row['payload'], 200);
        }
        $mime = strtolower((string)($row['content_type'] ?? 'image/webp'));
        if (!in_array($mime, ['image/webp', 'image/jpeg', 'image/png', 'image/gif', 'video/mp4', 'video/webm'], true)) $mime = 'image/webp';
        $res->header('Content-Type', $mime);
        // Do not send Content-Length from PHP. cPanel/Apache/PHP output
        // compression can otherwise advertise the uncompressed byte count and
        // truncate/corrupt the image on Android. Static upload URLs are the
        // preferred path; this REST route remains a compatibility fallback.
        $res->header('ETag', $etag);
        $res->header('Cache-Control', 'public, max-age=86400, immutable');
        $res->header('X-Content-Type-Options', 'nosniff');
        $res->header('X-BlueVPN-Raw', '1');
        return $res;
    }

    private static function free_response_for_source(array $source): WP_REST_Response {
        $cacheKey = 'bluevpn_free_' . hash('sha256', $source['url']);
        $cached = get_transient($cacheKey);
        if (is_array($cached) && isset($cached['body'], $cached['type'])) {
            $body = base64_decode((string)$cached['body'], true);
            if ($body !== false && $body !== '') return self::raw_text_response($body, (string)$cached['type'], (string)$source['id']);
        }
        $remote = wp_remote_get((string)$source['url'], [
            'timeout' => 18,
            'redirection' => 5,
            'headers' => ['Accept' => 'text/plain, application/octet-stream;q=0.9, */*;q=0.1', 'User-Agent' => 'BlueVPN-Free-Relay/' . BLUEVPN_MANAGER_VERSION],
        ]);
        if (is_wp_error($remote) || (int)wp_remote_retrieve_response_code($remote) >= 400) {
            return new WP_REST_Response(['detail' => ['code' => 'FREE_SOURCE_UNAVAILABLE', 'message' => 'سرورهای رایگان موقتاً در دسترس نیستند']], 503);
        }
        $body = (string)wp_remote_retrieve_body($remote);
        if ($body === '' || strlen($body) > 4 * 1024 * 1024) return new WP_REST_Response(['detail' => ['code' => 'FREE_SOURCE_INVALID', 'message' => 'پاسخ اشتراک رایگان معتبر نیست']], 503);
        $type = strtolower(trim(explode(';', (string)wp_remote_retrieve_header($remote, 'content-type'))[0] ?? 'text/plain'));
        if (!in_array($type, ['text/plain', 'application/octet-stream', 'application/json'], true)) $type = 'text/plain';
        // Avoid storing multi-megabyte transients inside wp_options.
        if (strlen($body) <= 512 * 1024) set_transient($cacheKey, ['body' => base64_encode($body), 'type' => $type], MINUTE_IN_SECONDS);
        return self::raw_text_response($body, $type, (string)$source['id']);
    }

    private static function raw_text_response(string $body, string $type, string $id): WP_REST_Response {
        $res = new WP_REST_Response($body, 200);
        $res->header('Content-Type', $type . ($type === 'text/plain' ? '; charset=utf-8' : ''));
        $res->header('Cache-Control', 'public, max-age=60, stale-if-error=300');
        $res->header('X-BlueVPN-Access', 'free-auto-only');
        $res->header('X-BlueVPN-Free-Source', $id);
        $res->header('X-BlueVPN-Raw', '1');
        return $res;
    }

    public static function free_subscription(WP_REST_Request $request): WP_REST_Response {
        $settings = BlueVPN_DB::settings();
        $mode = sanitize_key((string)($settings['free_warp_mode'] ?? 'warp_fallback_pool'));
        if (!in_array($mode, ['warp_only','warp_fallback_pool','pool_only'], true)) $mode = 'warp_fallback_pool';
        $warpEnabled = $mode !== 'pool_only' && (!array_key_exists('free_warp_enabled',$settings) || !empty($settings['free_warp_enabled']));
        $poolEnabled = !empty($settings['free_access_enabled']) || (!$warpEnabled && $mode !== 'warp_only');
        if (!$poolEnabled) {
            return new WP_REST_Response(['detail' => ['code' => 'FREE_ACCESS_DISABLED', 'message' => 'اتصال رایگان فعال نیست']], 404);
        }

        $id = (string)$request->get_param('item_id');
        if ($id === 'smart-curated' && class_exists('BlueVPN_Free_Sources')) {
            BlueVPN_Free_Sources::ensure_seeded_pool();
            $body = BlueVPN_Free_Sources::subscription_text(160);
            // Empty is still a valid temporary pool state; returning 200 lets
            // Android keep the Free entitlement and retry without degrading the
            // account to UNAVAILABLE.
            return self::raw_text_response($body, 'text/plain', 'smart-curated');
        }

        $sources = self::free_sources($settings, true);
        $source = $id === '' ? ($sources[0] ?? null) : (array_values(array_filter($sources, static fn($x) => $x['id'] === $id))[0] ?? null);
        if (!$source) return new WP_REST_Response(['detail' => ['code' => 'FREE_SUBSCRIPTION_NOT_FOUND', 'message' => 'ساب رایگان پیدا نشد']], 404);
        return self::free_response_for_source($source);
    }

    public static function serve_raw_response($served, $result, $request, $server) {
        if ($served || !($result instanceof WP_REST_Response)) return $served;
        $headers = $result->get_headers();
        if ((string)($headers['X-BlueVPN-Raw'] ?? '') !== '1') return $served;
        // Avoid stray buffered HTML/notices and PHP zlib output compression
        // corrupting binary images/subscriptions on shared cPanel hosting.
        if (function_exists('ini_set')) @ini_set('zlib.output_compression', '0');
        while (ob_get_level() > 0) { if (!@ob_end_clean()) break; }
        status_header($result->get_status());
        foreach ($result->get_headers() as $key => $value) {
            if (strcasecmp((string)$key, 'X-BlueVPN-Raw') === 0) continue;
            header((string)$key . ': ' . (string)$value, true);
        }
        $data = $result->get_data();
        if (is_string($data)) echo $data;
        return true;
    }

    private static function store_upload(array $file): string {
        global $wpdb;
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) throw new RuntimeException('تصویر تبلیغ انتخاب نشده است.');
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_IMAGE_BYTES) throw new RuntimeException('حجم تصویر باید کمتر از ۶ مگابایت باشد.');
        $payload = file_get_contents((string)$file['tmp_name']);
        if (!is_string($payload) || $payload === '') throw new RuntimeException('خواندن تصویر ناموفق بود.');
        $info = @getimagesizefromstring($payload);
        if (!is_array($info) || empty($info[0]) || empty($info[1])) throw new RuntimeException('فایل انتخاب‌شده تصویر معتبر نیست.');
        if ((int)$info[0] < 240 || (int)$info[1] < 120 || (int)$info[0] > 8000 || (int)$info[1] > 8000) throw new RuntimeException('ابعاد تصویر باید بین 240×120 و 8000×8000 باشد.');
        $mime = strtolower((string)($info['mime'] ?? ''));
        if (!in_array($mime, ['image/webp', 'image/jpeg', 'image/png', 'image/gif'], true)) throw new RuntimeException('فرمت تصویر پشتیبانی نمی‌شود؛ WebP/JPG/PNG استفاده کنید.');
        $id = bin2hex(random_bytes(16));
        $ok = $wpdb->insert(BlueVPN_DB::table('ad_assets'), [
            'id' => $id,
            'filename' => mb_substr(sanitize_file_name((string)($file['name'] ?? 'ad-image')), 0, 180),
            'content_type' => $mime,
            'payload' => $payload,
            'sha256' => hash('sha256', $payload),
            'byte_size' => strlen($payload),
            'created_at' => BlueVPN_Utils::now_mysql(),
        ]);
        if ($ok === false) throw new RuntimeException('ذخیره تصویر در MySQL ناموفق بود.');
        return '/api/v1/ad-assets/' . $id;
    }

    private static function store_story_upload(array $file, string $mediaType): string {
        global $wpdb;
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) throw new RuntimeException('فایل استوری انتخاب نشده است.');
        $size = (int)($file['size'] ?? 0);
        $max = $mediaType === 'video' ? self::MAX_STORY_VIDEO_BYTES : self::MAX_IMAGE_BYTES;
        if ($size <= 0 || $size > $max) {
            throw new RuntimeException($mediaType === 'video' ? 'حجم ویدئو باید کمتر از ۱۲ مگابایت باشد.' : 'حجم تصویر باید کمتر از ۶ مگابایت باشد.');
        }
        $payload = file_get_contents((string)$file['tmp_name']);
        if (!is_string($payload) || $payload === '') throw new RuntimeException('خواندن فایل استوری ناموفق بود.');
        $mime = '';
        if ($mediaType === 'image') {
            $info = @getimagesizefromstring($payload);
            if (!is_array($info) || empty($info[0]) || empty($info[1])) throw new RuntimeException('فایل انتخاب‌شده تصویر معتبر نیست.');
            if ((int)$info[0] < 240 || (int)$info[1] < 240 || (int)$info[0] > 8000 || (int)$info[1] > 8000) throw new RuntimeException('ابعاد تصویر استوری معتبر نیست.');
            $mime = strtolower((string)($info['mime'] ?? ''));
            if (!in_array($mime, ['image/webp', 'image/jpeg', 'image/png'], true)) throw new RuntimeException('فرمت تصویر استوری باید WebP/JPG/PNG باشد.');
        } else {
            $finfo = function_exists('finfo_open') ? @finfo_open(FILEINFO_MIME_TYPE) : false;
            $mime = $finfo ? strtolower((string)@finfo_file($finfo, (string)$file['tmp_name'])) : '';
            if ($finfo) @finfo_close($finfo);
            if (!in_array($mime, ['video/mp4', 'video/webm'], true)) throw new RuntimeException('فرمت ویدئو استوری باید MP4 یا WebM باشد.');
        }
        $id = bin2hex(random_bytes(16));
        $ok = $wpdb->insert(BlueVPN_DB::table('ad_assets'), [
            'id' => $id,
            'filename' => mb_substr(sanitize_file_name((string)($file['name'] ?? 'story-media')), 0, 180),
            'content_type' => $mime,
            'payload' => $payload,
            'sha256' => hash('sha256', $payload),
            'byte_size' => strlen($payload),
            'created_at' => BlueVPN_Utils::now_mysql(),
        ]);
        if ($ok === false) throw new RuntimeException('ذخیره فایل استوری در MySQL ناموفق بود؛ محدودیت max_allowed_packet هاست را بررسی کنید.');
        return '/api/v1/ad-assets/' . $id;
    }

    public static function save_settings(): void {
        self::guard('bluevpn_ads_save');
        $s = BlueVPN_DB::settings();
        $s['ads_enabled'] = isset($_POST['ads_enabled']);
        $s['ads_autoplay'] = isset($_POST['ads_autoplay']);
        $s['ads_loop'] = isset($_POST['ads_loop']);
        $s['ads_interval_seconds'] = max(3, min(30, (int)($_POST['ads_interval_seconds'] ?? 6)));
        $s['ads_height_dp'] = max(116, min(160, (int)($_POST['ads_height_dp'] ?? 146)));
        $s['tapsell_enabled'] = isset($_POST['tapsell_enabled']);
        $s['tapsell_app_key'] = mb_substr(trim((string)wp_unslash($_POST['tapsell_app_key'] ?? '')), 0, 500);
        $s['tapsell_interstitial_zone_id'] = mb_substr(trim((string)wp_unslash($_POST['tapsell_interstitial_zone_id'] ?? '')), 0, 300);
        $s['tapsell_show_after_connect'] = isset($_POST['tapsell_show_after_connect']);
        $s['tapsell_min_interval_seconds'] = max(0, min(86400, (int)($_POST['tapsell_min_interval_seconds'] ?? 0)));
        $s['tapsell_daily_cap'] = max(0, min(1000, (int)($_POST['tapsell_daily_cap'] ?? 0)));
        BlueVPN_DB::save_settings($s);
        self::redirect('ads', 'تنظیمات تبلیغات ذخیره شد.');
    }

    public static function create_ad(): void {
        self::guard('bluevpn_ads_create');
        try {
            $s = BlueVPN_DB::settings();
            $image = '';
            if (!empty($_FILES['image']) && is_array($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) $image = self::store_upload($_FILES['image']);
            if ($image === '') {
                $external = trim((string)wp_unslash($_POST['image_url'] ?? ''));
                if ($external !== '' && !wp_http_validate_url($external)) throw new RuntimeException('لینک تصویر معتبر نیست.');
                $image = esc_url_raw($external);
            }
            if ($image === '') throw new RuntimeException('تصویر یا لینک تصویر لازم است.');
            $target = self::target_from_post();
            $items = self::items($s);
            $items[] = [
                'id' => substr(bin2hex(random_bytes(16)), 0, 32),
                'title' => mb_substr(sanitize_text_field(wp_unslash($_POST['title'] ?? '')), 0, 120),
                'subtitle' => mb_substr(sanitize_text_field(wp_unslash($_POST['subtitle'] ?? '')), 0, 240),
                'image_url' => $image,
                'target_action' => $target['action'],
                'target_plan_id' => $target['plan_id'],
                'target_url' => $target['url'],
                'button_text' => mb_substr(sanitize_text_field(wp_unslash($_POST['button_text'] ?? '')), 0, 40),
                'active' => isset($_POST['active']),
                'sort_order' => max(-10000, min(10000, (int)($_POST['sort_order'] ?? 0))),
                'start_at' => self::clean_time((string)wp_unslash($_POST['start_at'] ?? '')),
                'end_at' => self::clean_time((string)wp_unslash($_POST['end_at'] ?? '')),
            ];
            $s['ads_items'] = $items;
            BlueVPN_DB::save_settings($s);
            self::redirect('ads', 'تبلیغ اضافه شد.');
        } catch (Throwable $e) {
            self::redirect('ads', '', $e->getMessage());
        }
    }

    public static function update_ad(): void {
        self::guard('bluevpn_ads_update');
        try {
            global $wpdb;
            $id = preg_replace('/[^A-Za-z0-9_-]+/', '', (string)($_POST['id'] ?? '')) ?: '';
            if ($id === '') throw new RuntimeException('شناسه تبلیغ معتبر نیست.');
            $s = BlueVPN_DB::settings();
            $items = self::items($s);
            $found = false;
            foreach ($items as &$item) {
                if ($item['id'] !== $id) continue;
                $found = true;
                $oldImage = (string)$item['image_url'];
                $image = $oldImage;
                if (!empty($_FILES['image']) && is_array($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $image = self::store_upload($_FILES['image']);
                    if (preg_match('#^/api/v1/ad-assets/([A-Za-z0-9_-]{6,64})$#', $oldImage, $m)) {
                        $wpdb->delete(BlueVPN_DB::table('ad_assets'), ['id' => $m[1]]);
                    }
                } else {
                    $external = trim((string)wp_unslash($_POST['image_url'] ?? ''));
                    if ($external !== '') {
                        if (!wp_http_validate_url($external) && !str_starts_with($external, '/api/v1/ad-assets/')) throw new RuntimeException('لینک تصویر معتبر نیست.');
                        $image = str_starts_with($external, '/api/v1/ad-assets/') ? $external : esc_url_raw($external);
                    }
                }
                if ($image === '') throw new RuntimeException('تصویر تبلیغ لازم است.');
                $target = self::target_from_post();
                $item['title'] = mb_substr(sanitize_text_field(wp_unslash($_POST['title'] ?? '')), 0, 120);
                $item['subtitle'] = mb_substr(sanitize_text_field(wp_unslash($_POST['subtitle'] ?? '')), 0, 240);
                $item['image_url'] = $image;
                $item['target_action'] = $target['action'];
                $item['target_plan_id'] = $target['plan_id'];
                $item['target_url'] = $target['url'];
                $item['button_text'] = mb_substr(sanitize_text_field(wp_unslash($_POST['button_text'] ?? '')), 0, 40);
                $item['active'] = isset($_POST['active']);
                $item['sort_order'] = max(-10000, min(10000, (int)($_POST['sort_order'] ?? 0)));
                $item['start_at'] = self::clean_time((string)wp_unslash($_POST['start_at'] ?? ''));
                $item['end_at'] = self::clean_time((string)wp_unslash($_POST['end_at'] ?? ''));
                break;
            }
            unset($item);
            if (!$found) throw new RuntimeException('تبلیغ پیدا نشد.');
            $s['ads_items'] = $items;
            BlueVPN_DB::save_settings($s);
            self::redirect('ads', 'تبلیغ ویرایش شد.');
        } catch (Throwable $e) {
            self::redirect('ads', '', $e->getMessage());
        }
    }

    public static function toggle_ad(): void {
        self::guard('bluevpn_ads_toggle');
        $id = preg_replace('/[^A-Za-z0-9_-]+/', '', (string)($_POST['id'] ?? '')) ?: '';
        $s = BlueVPN_DB::settings(); $items = self::items($s);
        foreach ($items as &$item) if ($item['id'] === $id) $item['active'] = !$item['active'];
        unset($item); $s['ads_items'] = $items; BlueVPN_DB::save_settings($s);
        self::redirect('ads', 'وضعیت تبلیغ تغییر کرد.');
    }

    public static function delete_ad(): void {
        self::guard('bluevpn_ads_delete');
        global $wpdb;
        $id = preg_replace('/[^A-Za-z0-9_-]+/', '', (string)($_POST['id'] ?? '')) ?: '';
        $s = BlueVPN_DB::settings(); $items = self::items($s); $removed = null;
        $keep = [];
        foreach ($items as $item) { if ($item['id'] === $id) $removed = $item; else $keep[] = $item; }
        if ($removed && preg_match('#^/api/v1/ad-assets/([A-Za-z0-9_-]{6,64})$#', (string)$removed['image_url'], $m)) $wpdb->delete(BlueVPN_DB::table('ad_assets'), ['id' => $m[1]]);
        $s['ads_items'] = $keep; BlueVPN_DB::save_settings($s);
        self::redirect('ads', 'تبلیغ حذف شد.');
    }

    public static function save_story_settings(): void {
        self::guard('bluevpn_story_save');
        $s = BlueVPN_DB::settings();
        $s['free_story_ads_enabled'] = isset($_POST['free_story_ads_enabled']);
        $s['free_story_ads_required'] = isset($_POST['free_story_ads_required']);
        $s['free_story_ads_image_seconds'] = max(3, min(30, (int)($_POST['free_story_ads_image_seconds'] ?? 6)));
        $s['free_story_ads_load_timeout_seconds'] = max(3, min(15, (int)($_POST['free_story_ads_load_timeout_seconds'] ?? 8)));
        $s['free_story_ads_max_video_seconds'] = max(5, min(60, (int)($_POST['free_story_ads_max_video_seconds'] ?? 30)));
        BlueVPN_DB::save_settings($s);
        self::redirect('ads', 'تنظیمات استوری تبلیغاتی اتصال رایگان ذخیره شد.');
    }

    public static function create_story_ad(): void {
        self::guard('bluevpn_story_create');
        try {
            $s = BlueVPN_DB::settings();
            $type = strtolower(trim((string)wp_unslash($_POST['media_type'] ?? 'image')));
            if (!in_array($type, ['image', 'video'], true)) $type = 'image';
            $media = '';
            if (!empty($_FILES['media']) && is_array($_FILES['media']) && ($_FILES['media']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $media = self::store_story_upload($_FILES['media'], $type);
            }
            if ($media === '') {
                $external = trim((string)wp_unslash($_POST['media_url'] ?? ''));
                if ($external !== '' && !wp_http_validate_url($external)) throw new RuntimeException('لینک رسانه استوری معتبر نیست.');
                $media = esc_url_raw($external);
            }
            if ($media === '') throw new RuntimeException('تصویر/ویدئو یا لینک رسانه لازم است.');
            $target = self::target_from_post();
            $items = self::story_items($s);
            $items[] = [
                'id' => substr(bin2hex(random_bytes(16)), 0, 32),
                'title' => mb_substr(sanitize_text_field(wp_unslash($_POST['title'] ?? '')), 0, 120),
                'subtitle' => mb_substr(sanitize_text_field(wp_unslash($_POST['subtitle'] ?? '')), 0, 240),
                'media_type' => $type,
                'media_url' => $media,
                'target_action' => $target['action'],
                'target_plan_id' => $target['plan_id'],
                'target_url' => $target['url'],
                'button_text' => mb_substr(sanitize_text_field(wp_unslash($_POST['button_text'] ?? '')), 0, 40),
                'active' => isset($_POST['active']),
                'weight' => max(1, min(100, (int)($_POST['weight'] ?? 1))),
                'image_duration_seconds' => max(3, min(30, (int)($_POST['image_duration_seconds'] ?? ($s['free_story_ads_image_seconds'] ?? 6)))),
                'start_at' => self::clean_time((string)wp_unslash($_POST['start_at'] ?? '')),
                'end_at' => self::clean_time((string)wp_unslash($_POST['end_at'] ?? '')),
            ];
            $s['free_story_ads_items'] = $items;
            BlueVPN_DB::save_settings($s);
            self::redirect('ads', 'استوری تبلیغاتی اضافه شد.');
        } catch (Throwable $e) {
            self::redirect('ads', '', $e->getMessage());
        }
    }

    public static function update_story_ad(): void {
        self::guard('bluevpn_story_update');
        try {
            global $wpdb;
            $id = preg_replace('/[^A-Za-z0-9_-]+/', '', (string)($_POST['id'] ?? '')) ?: '';
            if ($id === '') throw new RuntimeException('شناسه استوری معتبر نیست.');
            $s = BlueVPN_DB::settings();
            $items = self::story_items($s);
            $found = false;
            foreach ($items as &$item) {
                if ($item['id'] !== $id) continue;
                $found = true;
                $type = strtolower(trim((string)wp_unslash($_POST['media_type'] ?? $item['media_type'])));
                if (!in_array($type, ['image', 'video'], true)) $type = 'image';
                $oldMedia = (string)$item['media_url'];
                $media = $oldMedia;
                if (!empty($_FILES['media']) && is_array($_FILES['media']) && ($_FILES['media']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $media = self::store_story_upload($_FILES['media'], $type);
                    if (preg_match('#^/api/v1/ad-assets/([A-Za-z0-9_-]{6,64})$#', $oldMedia, $m)) $wpdb->delete(BlueVPN_DB::table('ad_assets'), ['id' => $m[1]]);
                } else {
                    $external = trim((string)wp_unslash($_POST['media_url'] ?? ''));
                    if ($external !== '') {
                        if (!wp_http_validate_url($external) && !str_starts_with($external, '/api/v1/ad-assets/')) throw new RuntimeException('لینک رسانه معتبر نیست.');
                        $media = str_starts_with($external, '/api/v1/ad-assets/') ? $external : esc_url_raw($external);
                    }
                }
                if ($media === '') throw new RuntimeException('رسانه استوری لازم است.');
                $target = self::target_from_post();
                $item['title'] = mb_substr(sanitize_text_field(wp_unslash($_POST['title'] ?? '')), 0, 120);
                $item['subtitle'] = mb_substr(sanitize_text_field(wp_unslash($_POST['subtitle'] ?? '')), 0, 240);
                $item['media_type'] = $type;
                $item['media_url'] = $media;
                $item['target_action'] = $target['action'];
                $item['target_plan_id'] = $target['plan_id'];
                $item['target_url'] = $target['url'];
                $item['button_text'] = mb_substr(sanitize_text_field(wp_unslash($_POST['button_text'] ?? '')), 0, 40);
                $item['active'] = isset($_POST['active']);
                $item['weight'] = max(1, min(100, (int)($_POST['weight'] ?? 1)));
                $item['image_duration_seconds'] = max(3, min(30, (int)($_POST['image_duration_seconds'] ?? ($s['free_story_ads_image_seconds'] ?? 6))));
                $item['start_at'] = self::clean_time((string)wp_unslash($_POST['start_at'] ?? ''));
                $item['end_at'] = self::clean_time((string)wp_unslash($_POST['end_at'] ?? ''));
                break;
            }
            unset($item);
            if (!$found) throw new RuntimeException('استوری پیدا نشد.');
            $s['free_story_ads_items'] = $items;
            BlueVPN_DB::save_settings($s);
            self::redirect('ads', 'استوری تبلیغاتی ویرایش شد.');
        } catch (Throwable $e) {
            self::redirect('ads', '', $e->getMessage());
        }
    }

    public static function toggle_story_ad(): void {
        self::guard('bluevpn_story_toggle');
        $id = preg_replace('/[^A-Za-z0-9_-]+/', '', (string)($_POST['id'] ?? '')) ?: '';
        $s = BlueVPN_DB::settings(); $items = self::story_items($s);
        foreach ($items as &$item) if ($item['id'] === $id) $item['active'] = !$item['active'];
        unset($item); $s['free_story_ads_items'] = $items; BlueVPN_DB::save_settings($s);
        self::redirect('ads', 'وضعیت استوری تغییر کرد.');
    }

    public static function delete_story_ad(): void {
        self::guard('bluevpn_story_delete');
        global $wpdb;
        $id = preg_replace('/[^A-Za-z0-9_-]+/', '', (string)($_POST['id'] ?? '')) ?: '';
        $s = BlueVPN_DB::settings(); $items = self::story_items($s); $removed = null; $keep = [];
        foreach ($items as $item) { if ($item['id'] === $id) $removed = $item; else $keep[] = $item; }
        if ($removed && preg_match('#^/api/v1/ad-assets/([A-Za-z0-9_-]{6,64})$#', (string)$removed['media_url'], $m)) $wpdb->delete(BlueVPN_DB::table('ad_assets'), ['id' => $m[1]]);
        $s['free_story_ads_items'] = $keep; BlueVPN_DB::save_settings($s);
        self::redirect('ads', 'استوری تبلیغاتی حذف شد.');
    }

    public static function save_free_settings(): void {
        self::guard('bluevpn_free_save');
        $s = BlueVPN_DB::settings();
        $warpRequested = isset($_POST['free_warp_enabled']);
        $s['free_warp_enabled'] = $warpRequested;
        $mode = sanitize_key((string)($_POST['free_warp_mode'] ?? 'warp_fallback_pool'));
        $mode = in_array($mode, ['warp_only','warp_fallback_pool','pool_only'], true) ? $mode : 'warp_fallback_pool';

        // Admin intent: disabling WARP means "use the Free Pool", not "disable
        // free access entirely". Keep an explicit pool-only mode so the Android
        // entitlement remains FREE and can fetch/test curated configs.
        if (!$warpRequested && $mode !== 'warp_only') $mode = 'pool_only';
        $s['free_warp_mode'] = $mode;
        $s['free_access_enabled'] =
            isset($_POST['free_access_enabled']) ||
            (!$warpRequested && $mode === 'pool_only');
        $s['free_warp_fallback_enabled'] = isset($_POST['free_warp_fallback_enabled']);
        $s['free_warp_guest_allowed'] = isset($_POST['free_warp_guest_allowed']);
        $s['free_warp_start_timeout_seconds'] = max(3, min(40, (int)($_POST['free_warp_start_timeout_seconds'] ?? 30)));
        $s['free_warp_adaptive_enabled'] = isset($_POST['free_warp_adaptive_enabled']);
        $s['free_warp_endpoint_racing_enabled'] = isset($_POST['free_warp_endpoint_racing_enabled']);
        $s['free_warp_endpoint_race_breadth'] = max(2, min(16, (int)($_POST['free_warp_endpoint_race_breadth'] ?? 8)));
        $s['free_warp_endpoint_probe_seconds'] = max(3, min(8, (int)($_POST['free_warp_endpoint_probe_seconds'] ?? 5)));
        $s['free_warp_quick_reconnect'] = isset($_POST['free_warp_quick_reconnect']);
        $s['free_warp_h2_enabled'] = isset($_POST['free_warp_h2_enabled']);
        $s['free_warp_fragment_enabled'] = isset($_POST['free_warp_fragment_enabled']);
        $s['free_warp_wireguard_enabled'] = isset($_POST['free_warp_wireguard_enabled']);
        $s['free_warp_gool_enabled'] = isset($_POST['free_warp_gool_enabled']);
        $s['free_warp_require_exit_trace'] = isset($_POST['free_warp_require_exit_trace']);
        $blockedRaw = trim((string)wp_unslash($_POST['free_warp_blocked_exit_countries'] ?? ''));
        $blocked = $blockedRaw === '' ? [] : (preg_split('/[\s,;]+/', strtoupper($blockedRaw)) ?: []);
        $blocked = array_values(array_unique(array_filter(array_map('trim', $blocked), static fn($code) => (bool)preg_match('/^[A-Z]{2}$/', $code))));
        // Empty is authoritative: admins may intentionally allow every WARP exit country, including IR.
        $s['free_warp_blocked_exit_countries'] = $blocked;
        $s['free_warp_scan_mode'] = in_array(sanitize_key((string)($_POST['free_warp_scan_mode'] ?? 'turbo')), ['turbo','balanced','thorough','stealth','ironclad'], true) ? sanitize_key((string)$_POST['free_warp_scan_mode']) : 'turbo';
        $s['free_warp_ip_mode'] = in_array(sanitize_key((string)($_POST['free_warp_ip_mode'] ?? 'auto')), ['auto','v4','dual'], true) ? sanitize_key((string)$_POST['free_warp_ip_mode']) : 'auto';
        $s['free_warp_warm_timeout_seconds'] = max(4,min(12,(int)($_POST['free_warp_warm_timeout_seconds'] ?? 8)));
        $s['free_warp_cold_timeout_seconds'] = max(15,min(40,(int)($_POST['free_warp_cold_timeout_seconds'] ?? 30)));
        $s['free_warp_total_timeout_seconds'] = max(30,min(90,(int)($_POST['free_warp_total_timeout_seconds'] ?? 75)));
        $s['free_warp_allowed_transports'] = array_values(array_intersect((array)($_POST['free_warp_allowed_transports'] ?? []), ['h3','h2','h2_fragment','wireguard','gool']));
        $s['free_session_minutes'] = max(15, min(180, (int)($_POST['free_session_minutes'] ?? 60)));
        BlueVPN_DB::save_settings($s);
        self::redirect('free', 'تنظیمات اتصال رایگان ذخیره شد.');
    }

    public static function add_free_source(): void {
        self::guard('bluevpn_free_add');
        $url = trim((string)wp_unslash($_POST['url'] ?? ''));
        if (!wp_http_validate_url($url)) self::redirect('free', '', 'لینک ساب رایگان معتبر نیست.');
        $s = BlueVPN_DB::settings(); $items = self::free_sources($s);
        $items[] = ['id' => substr(bin2hex(random_bytes(16)), 0, 24), 'name' => mb_substr(sanitize_text_field(wp_unslash($_POST['name'] ?? 'ساب رایگان')), 0, 120), 'url' => esc_url_raw($url), 'active' => isset($_POST['active']), 'priority' => max(0, min(9999, (int)($_POST['priority'] ?? count($items))))];
        $s['free_subscription_items'] = $items; BlueVPN_DB::save_settings($s);
        self::redirect('free', 'منبع رایگان اضافه شد.');
    }

    public static function toggle_free_source(): void {
        self::guard('bluevpn_free_toggle');
        $id = preg_replace('/[^A-Za-z0-9_-]+/', '', (string)($_POST['id'] ?? '')) ?: '';
        $s = BlueVPN_DB::settings(); $items = self::free_sources($s);
        foreach ($items as &$item) if ($item['id'] === $id) $item['active'] = !$item['active']; unset($item);
        $s['free_subscription_items'] = $items; BlueVPN_DB::save_settings($s); self::redirect('free', 'وضعیت منبع تغییر کرد.');
    }

    public static function delete_free_source(): void {
        self::guard('bluevpn_free_delete');
        $id = preg_replace('/[^A-Za-z0-9_-]+/', '', (string)($_POST['id'] ?? '')) ?: '';
        $s = BlueVPN_DB::settings(); $s['free_subscription_items'] = array_values(array_filter(self::free_sources($s), static fn($x) => $x['id'] !== $id)); BlueVPN_DB::save_settings($s); self::redirect('free', 'منبع حذف شد.');
    }

    private static function notice(): void {
        if (!empty($_GET['bluevpn_notice'])) echo '<div class="notice notice-success"><p>' . esc_html(wp_unslash($_GET['bluevpn_notice'])) . '</p></div>';
        if (!empty($_GET['bluevpn_error'])) echo '<div class="notice notice-error"><p>' . esc_html(wp_unslash($_GET['bluevpn_error'])) . '</p></div>';
    }

    public static function render_admin(): void {
        $s = BlueVPN_DB::settings(); self::notice();
        $payload = self::advertising_payload($s, null);
        $items = self::items($s);
        $activeCount = count(array_filter($items, static fn($x) => !empty($x['active'])));

        echo '<div class="bluevpn-ad-toolbar"><div><h2>مدیریت تبلیغات BlueVPN</h2><p style="margin:4px 0 0;color:#64748b">بنرهای داخل اپ، استوری اتصال رایگان، مقصدهای درون‌برنامه‌ای، زمان‌بندی نمایش و Tapsell را از همین صفحه کنترل کنید.</p></div><a class="button button-primary bluevpn-ad-add-button" href="#bluevpn-add-ad">＋ افزودن تبلیغ</a></div>';
        echo '<div class="bvc-grid"><div class="bvc-card bvc-kpi"><span>کل تبلیغات</span><strong>' . count($items) . '</strong></div><div class="bvc-card bvc-kpi"><span>تبلیغات فعال</span><strong>' . $activeCount . '</strong></div><div class="bvc-card bvc-kpi"><span>قابل نمایش در اپ</span><strong>' . count($payload['items']) . '</strong></div><div class="bvc-card bvc-kpi"><span>Assetهای MySQL</span><strong>' . self::asset_count() . '</strong></div></div>';

        echo '<div class="bvc-card"><h2>تنظیمات نمایش و Tapsell</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'; wp_nonce_field('bluevpn_ads_save'); echo '<input type="hidden" name="action" value="bluevpn_ads_save"><div class="bvc-form-grid">';
        self::checkbox('ads_enabled', 'نمایش بنرهای داخل اپ', !empty($s['ads_enabled'])); self::checkbox('ads_autoplay', 'تعویض خودکار', !empty($s['ads_autoplay'])); self::checkbox('ads_loop', 'تکرار اسلایدها', !empty($s['ads_loop']));
        self::number('ads_interval_seconds', 'فاصله اسلاید (ثانیه)', (int)($s['ads_interval_seconds'] ?? 6), 3, 30); self::number('ads_height_dp', 'ارتفاع بنر (dp)', (int)($s['ads_height_dp'] ?? 146), 116, 160);
        self::checkbox('tapsell_enabled', 'فعال‌سازی Tapsell', !empty($s['tapsell_enabled'])); self::text('tapsell_app_key', 'Tapsell App Key', (string)($s['tapsell_app_key'] ?? '')); self::text('tapsell_interstitial_zone_id', 'Interstitial Zone ID', (string)($s['tapsell_interstitial_zone_id'] ?? '')); self::checkbox('tapsell_show_after_connect', 'نمایش بعد از اتصال موفق', !array_key_exists('tapsell_show_after_connect', $s) || !empty($s['tapsell_show_after_connect'])); self::number('tapsell_min_interval_seconds', 'حداقل فاصله Tapsell', (int)($s['tapsell_min_interval_seconds'] ?? 0), 0, 86400); self::number('tapsell_daily_cap', 'سقف روزانه (۰=نامحدود)', (int)($s['tapsell_daily_cap'] ?? 0), 0, 1000);
        echo '</div><div style="margin-top:14px">'; submit_button('ذخیره تنظیمات تبلیغات', 'primary', 'submit', false); echo '</div></form></div>';

        $storyItems = self::story_items($s);
        $storyPayload = self::free_story_payload($s);
        $storyActive = count(array_filter($storyItems, static fn($x) => !empty($x['active'])));
        echo '<div class="bvc-card" id="bluevpn-free-story-ads"><h2>استوری تبلیغاتی اتصال رایگان</h2><div class="bvc-note">پس از آماده‌شدن اتصال رایگان، یک عکس یا ویدئو به‌صورت تصادفی تمام‌صفحه نمایش داده می‌شود. تا پایان رسانه، Session رایگان نهایی و تایمر آن شروع نمی‌شود. می‌توانید برای هر استوری CTA داخلی مثل ثبت‌نام، خرید اشتراک یا تمدید تعیین کنید؛ لمس CTA اتصال رایگان درحال آماده‌سازی را متوقف کرده و کاربر را امن به مقصد می‌برد.</div>';
        echo '<div class="bvc-grid" style="margin:14px 0"><div class="bvc-card bvc-kpi"><span>استوری‌ها</span><strong>' . count($storyItems) . '</strong></div><div class="bvc-card bvc-kpi"><span>فعال</span><strong>' . $storyActive . '</strong></div><div class="bvc-card bvc-kpi"><span>قابل انتخاب</span><strong>' . count($storyPayload['items']) . '</strong></div></div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'; wp_nonce_field('bluevpn_story_save'); echo '<input type="hidden" name="action" value="bluevpn_story_save"><div class="bvc-form-grid">';
        self::checkbox('free_story_ads_enabled', 'فعال‌سازی استوری برای پلن رایگان', !empty($s['free_story_ads_enabled']));
        self::checkbox('free_story_ads_required', 'تماشای کامل برای نهایی‌شدن اتصال الزامی باشد', !array_key_exists('free_story_ads_required', $s) || !empty($s['free_story_ads_required']));
        self::number('free_story_ads_image_seconds', 'مدت پیش‌فرض عکس (ثانیه)', (int)($s['free_story_ads_image_seconds'] ?? 6), 3, 30);
        self::number('free_story_ads_load_timeout_seconds', 'مهلت بارگذاری رسانه (ثانیه)', (int)($s['free_story_ads_load_timeout_seconds'] ?? 8), 3, 15);
        self::number('free_story_ads_max_video_seconds', 'حداکثر زمان ویدئو در اپ (ثانیه)', (int)($s['free_story_ads_max_video_seconds'] ?? 30), 5, 60);
        echo '</div><div style="margin-top:14px">'; submit_button('ذخیره تنظیمات استوری', 'primary', 'submit', false); echo '</div></form>';

        echo '<hr style="margin:22px 0;border:0;border-top:1px solid rgba(148,163,184,.18)"><h3>افزودن استوری جدید</h3><form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">'; wp_nonce_field('bluevpn_story_create'); echo '<input type="hidden" name="action" value="bluevpn_story_create"><div class="bvc-form-grid">';
        echo '<label>نوع رسانه<select name="media_type"><option value="image">عکس</option><option value="video">ویدئو</option></select></label>';
        self::text('title', 'عنوان', ''); self::text('subtitle', 'زیرعنوان', ''); self::target_editor(); self::text('button_text', 'متن دکمه', 'مشاهده');
        self::number('weight', 'وزن انتخاب تصادفی', 1, 1, 100); self::number('image_duration_seconds', 'مدت عکس (ثانیه)', (int)($s['free_story_ads_image_seconds'] ?? 6), 3, 30);
        echo '<label>شروع نمایش (اختیاری)<input type="datetime-local" name="start_at"></label><label>پایان نمایش (اختیاری)<input type="datetime-local" name="end_at"></label>';
        echo '<label class="bluevpn-file-input">آپلود رسانه<input type="file" name="media" accept="image/webp,image/jpeg,image/png,video/mp4,video/webm"><span data-file-name>عکس تا ۶MB / ویدئو تا ۱۲MB</span></label>';
        echo '<label>یا URL مستقیم رسانه<input type="url" name="media_url" placeholder="https://..."></label><label style="display:flex;align-items:center;align-self:end;padding-bottom:8px"><input type="checkbox" name="active" value="1" checked> فعال</label>';
        echo '</div><div style="margin-top:14px">'; submit_button('افزودن استوری', 'primary', 'submit', false); echo '</div></form>';

        if ($storyItems) {
            echo '<div class="bluevpn-ad-cards" style="margin-top:20px">';
            foreach ($storyItems as $story) {
                $src = self::resolve_asset_path((string)$story['media_url']); if (str_starts_with($src, '/')) $src = home_url($src);
                $on = !empty($story['active']);
                echo '<article class="bvc-card bluevpn-ad-card"><div class="bluevpn-ad-preview">';
                if ($story['media_type'] === 'video') echo '<div style="min-height:150px;display:grid;place-items:center;background:#050914;color:#67e8f9;font-size:44px">▶</div>';
                elseif ($src) echo '<img src="' . esc_url($src) . '" alt="">';
                echo '<span class="bluevpn-ad-state ' . ($on ? 'is-on' : 'is-off') . '">' . ($on ? 'فعال' : 'خاموش') . '</span></div><div class="bluevpn-ad-body">';
                echo '<h3>' . esc_html($story['title'] ?: ($story['media_type'] === 'video' ? 'ویدئوی استوری' : 'تصویر استوری')) . '</h3><p>' . esc_html($story['subtitle'] ?: 'نمایش تصادفی پس از اتصال رایگان') . '</p><div class="bluevpn-ad-meta"><span>' . esc_html($story['media_type'] === 'video' ? 'ویدئو' : 'عکس') . '</span><span>وزن ' . (int)$story['weight'] . '</span><span>مقصد: ' . esc_html(self::target_label((string)$story['target_action'], (int)$story['target_plan_id'])) . '</span><span>' . (int)$story['image_duration_seconds'] . ' ثانیه برای عکس</span></div><div class="bluevpn-ad-actions">';
                self::mini_form('bluevpn_story_toggle', 'bluevpn_story_toggle', $story['id'], $on ? 'خاموش کردن' : 'فعال کردن'); self::mini_form('bluevpn_story_delete', 'bluevpn_story_delete', $story['id'], 'حذف', true); echo '</div>';
                echo '<details class="bluevpn-inline-edit"><summary>ویرایش استوری</summary><form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">'; wp_nonce_field('bluevpn_story_update'); echo '<input type="hidden" name="action" value="bluevpn_story_update"><input type="hidden" name="id" value="' . esc_attr($story['id']) . '"><div class="bvc-form-grid">';
                echo '<label>نوع رسانه<select name="media_type"><option value="image" ' . selected($story['media_type'], 'image', false) . '>عکس</option><option value="video" ' . selected($story['media_type'], 'video', false) . '>ویدئو</option></select></label>';
                echo '<label>عنوان<input type="text" name="title" value="' . esc_attr($story['title']) . '"></label><label>زیرعنوان<input type="text" name="subtitle" value="' . esc_attr($story['subtitle']) . '"></label>'; self::target_editor($story); echo '<label>متن دکمه<input type="text" name="button_text" value="' . esc_attr($story['button_text']) . '"></label>';
                echo '<label>وزن<input type="number" name="weight" min="1" max="100" value="' . (int)$story['weight'] . '"></label><label>مدت عکس<input type="number" name="image_duration_seconds" min="3" max="30" value="' . (int)$story['image_duration_seconds'] . '"></label><label>شروع<input type="datetime-local" name="start_at"></label><label>پایان<input type="datetime-local" name="end_at"></label>';
                echo '<label class="bluevpn-file-input">تعویض رسانه<input type="file" name="media" accept="image/webp,image/jpeg,image/png,video/mp4,video/webm"><span data-file-name>برای حفظ رسانه فعلی خالی بگذارید</span></label><label>URL رسانه<input type="text" name="media_url" value="' . esc_attr($story['media_url']) . '"></label><label style="display:flex;align-items:center;align-self:end;padding-bottom:8px"><input type="checkbox" name="active" value="1" ' . checked($on, true, false) . '> فعال</label>';
                echo '</div><div style="margin-top:12px">'; submit_button('ذخیره استوری', 'primary', 'submit', false); echo '</div></form></details></div></article>';
            }
            echo '</div>';
        }
        echo '</div>';

        echo '<div class="bvc-card bluevpn-ad-editor" id="bluevpn-add-ad"><h2>افزودن تبلیغ جدید</h2><div class="bvc-note">اندازه پیشنهادی BlueVPN: 1200×540 پیکسل، نسبت 20:9، WebP یا JPG. تصویر آپلودشده مستقیماً داخل MySQL ذخیره می‌شود.</div><form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">'; wp_nonce_field('bluevpn_ads_create'); echo '<input type="hidden" name="action" value="bluevpn_ads_create"><div class="bvc-form-grid">';
        self::text('title', 'عنوان تبلیغ', ''); self::text('subtitle', 'زیرعنوان', ''); self::target_editor(); self::text('button_text', 'متن دکمه', 'مشاهده'); self::number('sort_order', 'ترتیب نمایش', 0, -10000, 10000);
        echo '<label>شروع نمایش (اختیاری)<input type="datetime-local" name="start_at"></label><label>پایان نمایش (اختیاری)<input type="datetime-local" name="end_at"></label>';
        echo '<label class="bluevpn-file-input">آپلود تصویر<input type="file" name="image" accept="image/webp,image/jpeg,image/png"><span data-file-name>هیچ فایلی انتخاب نشده</span></label>';
        echo '<label>یا URL تصویر<input type="url" name="image_url" placeholder="https://..."></label><label style="display:flex;align-items:center;align-self:end;padding-bottom:8px"><input type="checkbox" name="active" value="1" checked> فعال و قابل نمایش</label>';
        echo '</div><div style="margin-top:14px">'; submit_button('افزودن تبلیغ', 'primary', 'submit', false); echo '</div></form></div>';

        echo '<div class="bluevpn-ad-toolbar" style="margin-top:22px"><div><h2>تبلیغات موجود</h2><p style="margin:4px 0 0;color:#64748b">برای ویرایش، بخش «ویرایش تبلیغ» هر کارت را باز کنید.</p></div></div>';
        if (!$items) {
            echo '<div class="bvc-card"><div style="text-align:center;padding:30px 10px;color:#64748b"><strong style="display:block;color:#cbd5e1;font-size:15px;margin-bottom:8px">هنوز تبلیغی ثبت نشده</strong>از دکمه «افزودن تبلیغ» بالا اولین بنر را بسازید.</div></div>';
        } else {
            echo '<div class="bluevpn-ad-cards">';
            foreach ($items as $item) {
                $src = self::resolve_asset_path((string)$item['image_url']); if (str_starts_with($src, '/')) $src = home_url($src);
                $on = !empty($item['active']);
                echo '<article class="bvc-card bluevpn-ad-card"><div class="bluevpn-ad-preview">' . ($src ? '<img src="' . esc_url($src) . '" alt="">' : '') . '<span class="bluevpn-ad-state ' . ($on ? 'is-on' : 'is-off') . '">' . ($on ? 'فعال' : 'خاموش') . '</span></div><div class="bluevpn-ad-body">';
                echo '<h3>' . esc_html($item['title'] ?: 'بدون عنوان') . '</h3><p>' . esc_html($item['subtitle'] ?: 'بدون زیرعنوان') . '</p><div class="bluevpn-ad-meta"><span>ترتیب ' . (int)$item['sort_order'] . '</span><span>مقصد: ' . esc_html(self::target_label((string)$item['target_action'], (int)$item['target_plan_id'])) . '</span>' . ($item['start_at'] ? '<span>شروع ' . esc_html($item['start_at']) . '</span>' : '') . ($item['end_at'] ? '<span>پایان ' . esc_html($item['end_at']) . '</span>' : '') . '</div><div class="bluevpn-ad-actions">';
                self::mini_form('bluevpn_ads_toggle', 'bluevpn_ads_toggle', $item['id'], $on ? 'خاموش کردن' : 'فعال کردن'); self::mini_form('bluevpn_ads_delete', 'bluevpn_ads_delete', $item['id'], 'حذف', true); echo '</div>';
                echo '<details class="bluevpn-inline-edit"><summary>ویرایش تبلیغ</summary><form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">'; wp_nonce_field('bluevpn_ads_update'); echo '<input type="hidden" name="action" value="bluevpn_ads_update"><input type="hidden" name="id" value="' . esc_attr($item['id']) . '"><div class="bvc-form-grid">';
                echo '<label>عنوان<input type="text" name="title" value="' . esc_attr($item['title']) . '"></label><label>زیرعنوان<input type="text" name="subtitle" value="' . esc_attr($item['subtitle']) . '"></label>'; self::target_editor($item); echo '<label>متن دکمه<input type="text" name="button_text" value="' . esc_attr($item['button_text']) . '"></label><label>ترتیب<input type="number" name="sort_order" min="-10000" max="10000" value="' . (int)$item['sort_order'] . '"></label>';
                echo '<label>شروع<input type="datetime-local" name="start_at"></label><label>پایان<input type="datetime-local" name="end_at"></label><label class="bluevpn-file-input">تعویض تصویر<input type="file" name="image" accept="image/webp,image/jpeg,image/png"><span data-file-name>برای حفظ تصویر فعلی خالی بگذارید</span></label><label>URL تصویر<input type="text" name="image_url" value="' . esc_attr($item['image_url']) . '"></label><label style="display:flex;align-items:center;align-self:end;padding-bottom:8px"><input type="checkbox" name="active" value="1" ' . checked($on, true, false) . '> فعال</label>';
                echo '</div><div style="margin-top:12px">'; submit_button('ذخیره ویرایش', 'primary', 'submit', false); echo '</div></form></details></div></article>';
            }
            echo '</div>';
        }
    }

    public static function render_free_admin(): void {
        $s = BlueVPN_DB::settings(); self::notice();
        echo '<div class="bvc-card"><h2>اتصال رایگان</h2><p>اتصال رایگان می‌تواند با WARP یا Smart Free Pool کار کند. Smart Pool از منابع عمومی جمع‌آوری و با اینترنت واقعی کاربران رتبه‌بندی می‌شود.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'; wp_nonce_field('bluevpn_free_save'); echo '<input type="hidden" name="action" value="bluevpn_free_save"><div class="bvc-form-grid">';
        self::checkbox('free_warp_enabled', 'WARP / Aether فعال', !array_key_exists('free_warp_enabled',$s)||!empty($s['free_warp_enabled']));
        echo '<label>حالت موتور رایگان<select name="free_warp_mode">';
        $mode=(string)($s['free_warp_mode']??'warp_fallback_pool');
        foreach (['warp_fallback_pool'=>'WARP اصلی + Smart Pool پشتیبان','warp_only'=>'فقط WARP','pool_only'=>'فقط Smart Free Pool'] as $v=>$l) echo '<option value="'.esc_attr($v).'" '.selected($mode,$v,false).'>'.esc_html($l).'</option>';
        echo '</select></label>';
        self::checkbox('free_warp_fallback_enabled', 'Fallback به Smart Free Pool', !array_key_exists('free_warp_fallback_enabled',$s)||!empty($s['free_warp_fallback_enabled']));
        self::checkbox('free_warp_guest_allowed', 'اتصال مهمان بدون ورود', !array_key_exists('free_warp_guest_allowed',$s)||!empty($s['free_warp_guest_allowed']));
        self::number('free_warp_start_timeout_seconds', 'مهلت شروع WARP (ثانیه)', (int)($s['free_warp_start_timeout_seconds'] ?? 30), 3, 40);
        self::checkbox('free_warp_adaptive_enabled', 'Strategy تطبیقی', !array_key_exists('free_warp_adaptive_enabled',$s)||!empty($s['free_warp_adaptive_enabled']));
        self::checkbox('free_warp_endpoint_racing_enabled', 'Cloudflare Endpoint Racing', !array_key_exists('free_warp_endpoint_racing_enabled',$s)||!empty($s['free_warp_endpoint_racing_enabled']));
        self::number('free_warp_endpoint_race_breadth', 'تعداد Candidate در هر اتصال', (int)($s['free_warp_endpoint_race_breadth'] ?? 8), 2, 16);
        self::number('free_warp_endpoint_probe_seconds', 'مهلت هر Candidate (ثانیه)', (int)($s['free_warp_endpoint_probe_seconds'] ?? 5), 3, 8);
        self::checkbox('free_warp_quick_reconnect', 'Quick reconnect', !array_key_exists('free_warp_quick_reconnect',$s)||!empty($s['free_warp_quick_reconnect']));
        self::checkbox('free_warp_h2_enabled', 'HTTP/2 fallback', !array_key_exists('free_warp_h2_enabled',$s)||!empty($s['free_warp_h2_enabled']));
        self::checkbox('free_warp_fragment_enabled', 'TLS Fragment fallback', !array_key_exists('free_warp_fragment_enabled',$s)||!empty($s['free_warp_fragment_enabled']));
        self::checkbox('free_warp_wireguard_enabled', 'WireGuard fallback', !array_key_exists('free_warp_wireguard_enabled',$s)||!empty($s['free_warp_wireguard_enabled']));
        self::checkbox('free_warp_gool_enabled', 'WARP-in-WARP / gool', !empty($s['free_warp_gool_enabled']));
        self::checkbox('free_warp_require_exit_trace', 'تأیید اجباری کشور خروجی WARP', !array_key_exists('free_warp_require_exit_trace',$s)||!empty($s['free_warp_require_exit_trace']));
        self::text('free_warp_blocked_exit_countries', 'کشورهای خروجی مسدود (ISO، جدا با کاما — خالی = همه مجاز)', implode(',', (array)($s['free_warp_blocked_exit_countries'] ?? [])));
        self::number('free_warp_warm_timeout_seconds', 'Warm timeout', (int)($s['free_warp_warm_timeout_seconds'] ?? 8), 4, 12);
        self::number('free_warp_cold_timeout_seconds', 'Cold timeout', (int)($s['free_warp_cold_timeout_seconds'] ?? 30), 15, 40);
        self::number('free_warp_total_timeout_seconds', 'Total timeout', (int)($s['free_warp_total_timeout_seconds'] ?? 75), 30, 90);
        self::checkbox('free_access_enabled', 'Smart Free Pool فعال', !empty($s['free_access_enabled']));
        self::number('free_session_minutes', 'مدت هر Session (دقیقه)', (int)($s['free_session_minutes'] ?? 60), 15, 180);
        echo '</div>'; submit_button('ذخیره موتور رایگان', 'primary', 'submit', false); echo '</form></div>';
        echo '<div class="bvc-card"><h2>افزودن ساب رایگان</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'; wp_nonce_field('bluevpn_free_add'); echo '<input type="hidden" name="action" value="bluevpn_free_add"><div class="bvc-form-grid">'; self::text('name', 'نام', 'سرور رایگان'); self::text('url', 'URL ساب', ''); self::number('priority', 'اولویت', 0, 0, 9999); echo '<label><input type="checkbox" name="active" value="1" checked> فعال</label></div>'; submit_button('افزودن', 'primary', 'submit', false); echo '</form></div>';
        echo '<div class="bvc-card"><h2>منابع</h2><table class="widefat striped bvc-table"><tr><th>نام</th><th>URL مبدا</th><th>Endpoint اپ</th><th>وضعیت</th><th>عملیات</th></tr>';
        foreach (self::free_sources($s) as $item) { $public = home_url('/api/v1/free/subscriptions/' . rawurlencode($item['id'])); echo '<tr><td>' . esc_html($item['name']) . '</td><td><code>' . esc_html($item['url']) . '</code></td><td><code>' . esc_html($public) . '</code></td><td>' . ($item['active'] ? 'فعال' : 'خاموش') . '</td><td>'; self::mini_form('bluevpn_free_toggle', 'bluevpn_free_toggle', $item['id'], $item['active'] ? 'خاموش' : 'روشن'); self::mini_form('bluevpn_free_delete', 'bluevpn_free_delete', $item['id'], 'حذف', true); echo '</td></tr>'; }
        echo '</table></div>';
        if(class_exists('BlueVPN_Free_Sources')){
            BlueVPN_Free_Sources::seed();global $wpdb;$st=BlueVPN_DB::table('free_config_sources');$ct=BlueVPN_DB::table('free_configs');
            $sources=$wpdb->get_results("SELECT * FROM {$st} ORDER BY priority,id",ARRAY_A)?:[];
            $total=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$ct} WHERE active=1");$tested=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$ct} WHERE active=1 AND reports_count>0");$strong=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$ct} WHERE active=1 AND reports_count>=2 AND score>=65");
            echo '<div class="bvc-grid"><div class="bvc-card bvc-kpi"><span>کانفیگ فعال جمع‌آوری‌شده</span><strong>'.number_format($total).'</strong></div><div class="bvc-card bvc-kpi"><span>تست‌شده با اینترنت کاربران</span><strong>'.number_format($tested).'</strong></div><div class="bvc-card bvc-kpi"><span>Pool منتخب</span><strong>'.number_format($strong).'</strong></div></div>';
            echo '<div class="bvc-card"><h2>منابع خودکار عمومی</h2><p>سرور فقط کانفیگ‌های عمومی را جمع می‌کند؛ تست واقعی روی اینترنت کاربران انجام و نتیجه ناشناس برای رتبه‌بندی Pool برگردانده می‌شود.</p><table class="widefat striped bvc-table"><tr><th>منبع</th><th>آخرین دریافت</th><th>وضعیت</th><th>عملیات</th></tr>';
            foreach($sources as $src){echo '<tr><td><strong>'.esc_html($src['title']).'</strong><br><code>'.esc_html($src['url']).'</code></td><td>'.esc_html($src['last_fetch_at']?:'—').'</td><td>'.esc_html($src['last_status']?:($src['enabled']?'آماده':'خاموش')).($src['last_error']?'<br><small class="bvc-bad">'.esc_html(mb_substr($src['last_error'],0,160)).'</small>':'').'</td><td><div class="bvc-actions"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_free_source_refresh_'.(int)$src['id']);echo '<input type="hidden" name="action" value="bluevpn_free_source_refresh"><input type="hidden" name="source_id" value="'.(int)$src['id'].'"><button class="button">دریافت الآن</button></form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_free_source_toggle_'.(int)$src['id']);echo '<input type="hidden" name="action" value="bluevpn_free_source_toggle"><input type="hidden" name="source_id" value="'.(int)$src['id'].'"><button class="button">'.($src['enabled']?'خاموش':'روشن').'</button></form></div></td></tr>';}
            echo '</table><h3 style="margin-top:18px">افزودن کانال عمومی Telegram</h3><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_free_source_save');echo '<input type="hidden" name="action" value="bluevpn_free_source_save"><div class="bvc-form-grid"><label>عنوان<input name="title" value="VPNhub | کانفیگ رایگان"></label><label>Preview URL<input name="url" value="https://t.me/s/persianvpnhub" required></label><label>فاصله دریافت (ثانیه)<input type="number" name="fetch_interval_seconds" min="60" value="300"></label><label>حداکثر آیتم<input type="number" name="max_items" min="10" max="1000" value="400"></label><label>اولویت<input type="number" name="priority" value="10"></label></div><p><button class="button button-primary">ذخیره منبع</button></p></form></div>';
        }
    }

    private static function target_editor(array $item = []): void {
        $action = self::normalize_target_action((string)($item['target_action'] ?? ''), (string)($item['target_url'] ?? ''));
        $planId = max(0, (int)($item['target_plan_id'] ?? 0));
        $url = (string)($item['target_url'] ?? '');
        $options = [
            'none' => 'بدون عملکرد',
            'auth' => 'ورود / ثبت‌نام',
            'plans' => 'مشاهده پلن‌ها',
            'purchase' => 'خرید اشتراک',
            'account' => 'حساب کاربری',
            'renew' => 'تمدید / ارتقا',
            'settings' => 'تنظیمات',
            'external' => 'لینک خارجی',
        ];
        echo '<label>عملکرد هنگام لمس<select name="target_action">';
        foreach ($options as $value => $label) echo '<option value="' . esc_attr($value) . '" ' . selected($action, $value, false) . '>' . esc_html($label) . '</option>';
        echo '</select></label>';

        global $wpdb;
        $plans = $wpdb->get_results('SELECT id,title FROM ' . BlueVPN_DB::table('plans') . ' WHERE active=1 AND deleted=0 ORDER BY id DESC LIMIT 200', ARRAY_A);
        echo '<label>پلن مشخص (اختیاری)<select name="target_plan_id"><option value="0">انتخاب خود کاربر</option>';
        foreach ((array)$plans as $plan) {
            $id = (int)($plan['id'] ?? 0);
            if ($id <= 0) continue;
            echo '<option value="' . $id . '" ' . selected($planId, $id, false) . '>' . esc_html('#' . $id . ' — ' . (string)($plan['title'] ?? 'پلن')) . '</option>';
        }
        echo '</select><small style="display:block;margin-top:5px;color:#64748b">فقط برای «خرید اشتراک» استفاده می‌شود؛ پس از ورود همان پلن در ابتدای فهرست برجسته می‌شود.</small></label>';
        echo '<label>لینک وب / فال‌بک (اختیاری)<input type="url" name="target_url" value="' . esc_attr($url) . '" placeholder="https://..."><small style="display:block;margin-top:5px;color:#64748b">در نسخه‌های جدید مقصد داخلی اولویت دارد؛ این URL برای لینک خارجی یا fallback نسخه‌های قدیمی است.</small></label>';
        if ($action !== 'none' && $action !== 'external') {
            echo '<label>Deep Link داخلی<input type="text" readonly value="' . esc_attr(self::deep_link($action, $planId)) . '"></label>';
        }
    }

    private static function asset_count(): int { global $wpdb; return (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . BlueVPN_DB::table('ad_assets')); }
    private static function text(string $name, string $label, string $value): void { echo '<label>' . esc_html($label) . '<input type="text" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"></label>'; }
    private static function number(string $name, string $label, int $value, int $min, int $max): void { echo '<label>' . esc_html($label) . '<input type="number" name="' . esc_attr($name) . '" min="' . $min . '" max="' . $max . '" value="' . $value . '"></label>'; }
    private static function checkbox(string $name, string $label, bool $checked): void { echo '<label><input type="checkbox" name="' . esc_attr($name) . '" value="1" ' . checked($checked, true, false) . '> ' . esc_html($label) . '</label>'; }
    private static function mini_form(string $action, string $nonce, string $id, string $label, bool $danger = false): void { echo '<form style="display:inline-block;margin:2px" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'; wp_nonce_field($nonce); echo '<input type="hidden" name="action" value="' . esc_attr($action) . '"><input type="hidden" name="id" value="' . esc_attr($id) . '"><button class="button' . ($danger ? ' button-link-delete' : '') . '">' . esc_html($label) . '</button></form>'; }
}
