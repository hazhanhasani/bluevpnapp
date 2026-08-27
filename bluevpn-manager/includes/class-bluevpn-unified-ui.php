<?php
if (!defined('ABSPATH')) exit;

final class BlueVPN_Unified_UI {
    public static function init(): void {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets'], 100);
        add_action('admin_head', [self::class, 'enforce_mobile_viewport'], 999);
        add_filter('admin_body_class', [self::class, 'body_class']);
    }

    public static function enforce_mobile_viewport(): void {
        if (!self::is_bluevpn_page()) return;
        echo '<script>(function(){var h=document.head,m=h.querySelector("meta[name=viewport]");if(!m){m=document.createElement("meta");m.name="viewport";h.appendChild(m)}m.content="width=device-width,initial-scale=1,maximum-scale=5,viewport-fit=cover"}());</script>';
    }

    private static function page_slug(): string {
        return sanitize_key((string)($_GET['page'] ?? ''));
    }

    public static function is_bluevpn_page(): bool {
        return str_starts_with(self::page_slug(), 'bluevpn');
    }

    public static function enqueue_admin_assets(): void {
        if (!self::is_bluevpn_page()) return;
        wp_enqueue_style('bluevpn-unified-admin', BLUEVPN_MANAGER_URL . 'assets/admin-unified.css', [], BLUEVPN_MANAGER_VERSION);
        wp_enqueue_script('bluevpn-unified-admin', BLUEVPN_MANAGER_URL . 'assets/admin-unified.js', [], BLUEVPN_MANAGER_VERSION, true);
        wp_localize_script('bluevpn-unified-admin','BlueVPNAdmin',['ajaxUrl'=>admin_url('admin-ajax.php'),'providerCatalogNonce'=>wp_create_nonce('bluevpn_provider_access_catalog'),'monitorEndpoint'=>rest_url('bluevpn-system/v1/monitor/client-error'),'monitorToken'=>class_exists('BlueVPN_Error_Monitor')?BlueVPN_Error_Monitor::client_token():'']);
    }

    public static function body_class(string $classes): string {
        return self::is_bluevpn_page() ? $classes . ' bluevpn-admin-unified bluevpn-standalone-admin' : $classes;
    }

    private static function nav(): array {
        return [
            'داشبورد' => [
                ['bluevpn-manager', 'نمای کلی', 'dashboard'],
            ],
            'کاربران و فروش' => [
                ['bluevpn-customers', 'کاربران', 'users'],
                ['bluevpn-manual-customers', 'مشتریان دستی', 'manual'],
                ['bluevpn-plans', 'پلن‌ها', 'plans'],
                ['bluevpn-orders', 'سفارش‌ها و پرداخت‌ها', 'orders'],
                ['bluevpn-payments', 'درگاه و بلوپال', 'wallet'],
                ['bluevpn-manual', 'فعال‌سازی دستی', 'manual'],
                ['bluevpn-sms', 'SMS / OTP', 'sms'],
                ['bluevpn-support', 'پشتیبانی آنلاین', 'support'],
                ['bluevpn-telegram-bot', 'ربات تلگرام', 'bot'],
            ],
            'شبکه و سرویس' => [
                ['bluevpn-free-access', 'اتصال رایگان / WARP', 'free'],
                ['bluevpn-subscription-sources', 'Sourceهای اشتراک', 'link'],
                ['bluevpn-pasarguard', 'PasarGuard', 'server'],
                ['bluevpn-marzban', 'Marzban', 'server'],
                ['bluevpn-guardcore', 'GuardCore', 'shield'],
                ['bluevpn-guardcore-queue', 'صف GuardCore', 'queue'],
                ['bluevpn-gateway', 'Gateway Metering', 'shield'],
            ],
            'محصول و هوشمندی' => [
                ['bluevpn-blueai', 'BlueAI', 'ai'],
                ['bluevpn-ads', 'تبلیغات', 'ads'],
                ['bluevpn-app-update', 'اپ و انتشار', 'app'],
                ['bluevpn-app-connection', 'اتصال اپلیکیشن', 'link'],
            ],
            'سیستم و عملیات' => [
                ['bluevpn-production', 'سلامت و Backup', 'shield'],
                ['bluevpn-error-monitor', 'خطاها و مانیتورینگ', 'shield'],
                ['bluevpn-database', 'دیتابیس', 'db'],
                ['bluevpn-settings', 'تنظیمات عمومی', 'settings'],
                ['bluevpn-github-updater', 'آپدیت Manager', 'update'],
                ['bluevpn-migration', 'Migration', 'migration'],
            ],
        ];
    }

    private static function current_group(string $slug): string {
        foreach (self::nav() as $group => $items) {
            foreach ($items as $item) if (($item[0] ?? '') === $slug) return $group;
        }
        return 'BlueVPN';
    }

    private static function icon(string $name): string {
        $paths = [
            'dashboard' => '<path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z"/>',
            'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
            'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
            'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
            'plans' => '<path d="M20 13V6a2 2 0 0 0-2-2H5L2 7l9 9 3-3M7 8h.01"/>',
            'orders' => '<path d="M6 2l1 4h10l1-4M5 6h14l-1 16H6L5 6zM9 10h6"/>',
            'ai' => '<path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/><circle cx="12" cy="12" r="4"/>',
            'ads' => '<path d="M3 11l18-5v12L3 13v-2zM7 14l1.5 6h4L11 15"/>',
            'free' => '<path d="M12 3v18M5 12h14M7 7l10 10M17 7L7 17"/>',
            'app' => '<rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/>',
            'link' => '<path d="M10 13a5 5 0 0 0 7.07.07l2-2a5 5 0 0 0-7.07-7.07l-1.15 1.15M14 11a5 5 0 0 0-7.07-.07l-2 2A5 5 0 0 0 12 20l1.15-1.15"/>',
            'server' => '<rect x="3" y="4" width="18" height="6" rx="2"/><rect x="3" y="14" width="18" height="6" rx="2"/><path d="M7 7h.01M7 17h.01"/>',
            'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            'queue' => '<path d="M4 6h16M4 12h12M4 18h8"/>',
            'db' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
            'sms' => '<path d="M21 15a4 4 0 0 1-4 4H8l-5 3 1.5-5A7 7 0 0 1 3 13V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4v8z"/>',
            'wallet' => '<path d="M3 6h16a2 2 0 0 1 2 2v10H5a2 2 0 0 1-2-2V6zM3 8V5a2 2 0 0 1 2-2h12M16 12h5"/>',
            'bot' => '<rect x="4" y="7" width="16" height="12" rx="3"/><path d="M12 3v4M8 12h.01M16 12h.01M8 16h8"/>',
            'support' => '<path d="M21 15a4 4 0 0 1-4 4H9l-5 3 1.5-5A7 7 0 0 1 3 13V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4v8z"/><path d="M8 9h8M8 13h5"/>',
            'manual' => '<path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4z"/>',
            'migration' => '<path d="M5 12h14M15 8l4 4-4 4M9 4L5 8l4 4"/>',
            'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21H9.6v-.1A1.7 1.7 0 0 0 8.6 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.2 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H2.4V9.6h.1A1.7 1.7 0 0 0 4.2 8.6a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 8.6 4.2a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V2.4h4v.1a1.7 1.7 0 0 0 1 1.7 1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 8.6a1.7 1.7 0 0 0 .6 1 1.7 1.7 0 0 0 1.1.4h.1v4h-.1a1.7 1.7 0 0 0-1.7 1z"/>',
            'update' => '<path d="M21 12a9 9 0 1 1-2.64-6.36L21 8M21 3v5h-5"/>',
        ];
        return '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' . ($paths[$name] ?? $paths['dashboard']) . '</svg>';
    }

    public static function shell_open(string $title, string $subtitle = 'BlueVPN • WordPress / MySQL'): void {
        $current = self::page_slug();
        $user = wp_get_current_user();
        echo '<div class="bluevpn-admin-app" id="bluevpnAdminApp" dir="rtl">';
        echo '<div class="bluevpn-bg" aria-hidden="true"><span></span><span></span><span></span></div>';
        echo '<div class="bluevpn-sidebar-overlay" id="bluevpnSidebarOverlay"></div>';
        echo '<aside class="bluevpn-sidebar" id="bluevpnSidebar">';
        echo '<div class="bluevpn-brand"><div class="bluevpn-brand-mark">B<span></span></div><div><strong>BlueVPN</strong><small>پنل مدیریت هوشمند</small></div><button type="button" class="bluevpn-sidebar-close" id="bluevpnSidebarClose" aria-label="بستن">×</button></div>';
        echo '<div class="bluevpn-nav-search"><span>'.self::icon('search').'</span><input id="bluevpnNavSearch" type="search" autocomplete="off" placeholder="جستجو در پنل…"></div>';
        echo '<nav class="bluevpn-nav" id="bluevpnNav">';
        foreach (self::nav() as $group => $items) {
            echo '<div class="bluevpn-nav-group"><span class="bluevpn-nav-label">'.esc_html($group).'</span>';
            foreach ($items as [$slug, $label, $icon]) {
                $active = $current === $slug || ($current === '' && $slug === 'bluevpn-manager');
                echo '<a class="bluevpn-nav-item'.($active?' is-active':'').'" href="'.esc_url(admin_url('admin.php?page='.$slug)).'">'.self::icon($icon).'<span>'.esc_html($label).'</span></a>';
            }
            echo '</div>';
        }
        echo '</nav>';
        echo '<div class="bluevpn-sidebar-bottom"><div class="bluevpn-sidebar-status"><span class="bluevpn-live-dot"></span><div><strong>WordPress Backend</strong><small>MySQL • v'.esc_html(BLUEVPN_MANAGER_VERSION).'</small></div></div>';
        echo '<a class="bluevpn-back-wp" href="'.esc_url(admin_url()).'">'.self::icon('dashboard').'<span>بازگشت به وردپرس</span></a></div>';
        echo '</aside>';
        echo '<section class="bluevpn-main">';
        $group = self::current_group($current);
        echo '<header class="bluevpn-topbar"><div class="bluevpn-top-title"><button type="button" class="bluevpn-mobile-menu" id="bluevpnMenuToggle" aria-label="باز کردن منو">'.self::icon('menu').'</button><div><span class="bluevpn-kicker">'.esc_html($group).' • BLUEVPN CONTROL CENTER</span><h1>'.esc_html($title).'</h1><p><a class="bluevpn-breadcrumb-home" href="'.esc_url(admin_url('admin.php?page=bluevpn-manager')).'">داشبورد</a><span> / </span>'.esc_html($group).'<span> / </span>'.esc_html($title).'</p></div></div>';
        echo '<div class="bluevpn-top-meta"><span id="bluevpnLiveClock">'.esc_html(BlueVPN_Utils::tehran_datetime_fa()).'</span><span class="bluevpn-user-chip"><span class="bluevpn-avatar">'.esc_html(mb_substr($user->display_name ?: $user->user_login, 0, 1)).'</span>'.esc_html($user->display_name ?: $user->user_login).'</span></div></header>';
        echo '<main class="bluevpn-content">';
    }

    public static function shell_close(): void {
        echo '</main></section></div>';
    }
}
